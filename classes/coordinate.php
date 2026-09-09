<?php
require_once(ANNCSU_CLASS_PATH.'services.php');

/**
 * ANNCSUCoordinates
 * La classe si occupa di effettuare il conferimento delle coordinate da DB verso civici esistenti su DB ANNCSU 
 */
class ANNCSUCoordinates extends ANNCSUGenericService {

    /**
     * chiavi obbligatorie da definire nel file ini di configurazione
     * @var array<string>
     */
    protected $configuration_keys = array(
        "iss",
        "sub",
        "aud",
        "auth_url",
        "purpose_id",
        "client_id",
        "key_id",
        "modi_key_id",
        "user_location",
        "LoA",
        "user_id",
        "service_url",
        "schema",
        "tabella_accessi",
        "vista_accessi",
        "id_tabella_accessi",
        "id_vista_accessi",
        "allineato_tabella_accessi",
        "progr_vista_accessi",
        "coord_x",
        "coord_y",
        "metodo"
    );

    /**
     * Schema del db a cui connettersi
     * @var string
     */
    protected $schema;

    /**
     * Tabella su cui eseguire l'operazione di update del campo $allineato_tabella_accessi
     * @var string
     */
    protected $tabella_accessi;

    /**
     * Vista contenente le coordinate dei civici da conferire
     * @var string
     */
    protected $vista_accessi;

    /**
     * Nome del campo univoco incrementale della tabella degli accessi
     * @var string
     */
    protected $id_tabella_accessi;

    /**
     * Nome del campo univoco della vista accessi
     * @var string
     */
    protected $id_vista_accessi;

    /**
     * Nome del campo della vista che determina se le coordinate sono già state conferite per il record corrispondente
     * @var string
     */
    protected $allineato_tabella_accessi;

    /**
     * Nome del campo della vista contenente il progressivo accesso per il record corrispondente
     * @var string
     */
    protected $progr_vista_accessi;

    /**
     * Nome del campo della vista contenente il valore x delle coordinate per il record corrispondente
     * @var string
     */
    protected $coord_x;

    /**
     * Nome del campo della vista contenente il valore y delle coordinate per il record corrispondente
     * @var string
     */
    protected $coord_y;

    /**
     * Nome del campo del record accesso che identifica il metodo di ottenimento delle cordinate del civico
     * @var string
     */
    protected $metodo; 

    /**
     * Limite di chiamate massimo per ogni esecuzione
     * @var string
     */
    private $limit = 0;

    /**
     * Dimensione massima per ogni blocco di esecuzione
     * Es: Se il limite è 500, i dati vengono conferiti in 3 blocchi, i primi due da 200 record ciascuno, l'ultimo da 100
     * @var string
     */
    private $chunkSize = 1;

    /**
     * Inizializza il servizio di conferimento coordinate ed esegue i controlli necessari su configurazione, 
     * chiave privata e connettività a DB
     * 
     * @param array         $config         Configurazione del client per il servizio richiesto
     * @param array         $options        Opzioni di lancio aggiuntive
     * @param string        $environment    Ambiente di lancio
     * @param ProcessLog    $logInstance    Istanza globale del processo di log
     * @param boolean       $dryRun         Modalità dry run attiva
     */
    public function __construct($config, $options, $environment, $logInstance, $dryRun) 
    {
        parent::__construct($config,$options, $environment,'coordinate', $dryRun);

        // set delle proprietà della classe
        foreach ($this->config as $confKey => $confValue) {
            if(property_exists($this, $confKey)){
                $this->$confKey = $confValue;
            }
        }

        // set istanza dei log
        $this->setLogInstance($logInstance);
        $this->logInstance->setLogFile("coordinate_$this->environment.log");

        $this->logInstance->printProcessLog(($this->dryRun ? 'SIMULAZIONE - ' : '')."INIZIO PROCESSO DI CONFERIMENTO COORDINATE");

        // controllo configurazione
        $this->initConfiguration($this->configuration_keys);
        
        // controllo esistenza chiave privata (voucher)
        $this->setPrivateKey();

        // controllo esistenza chiave privata ModI (Agid-JWT-Signature/TrackingEvidence)
        $this->setModiPrivateKey();

        // controllo connettività a db
        $this->checkPostgreServiceFile();

        // controllo esistenza campi tabelle
        $this->checkDbTables();
        
        $this->logInstance->newLine();
        if ($this->dryRun) {
            $this->printProcessParameters();
        }

    }

    /**
     * Logica core del servizio, esegue il conferimento delle coordinate
     */
    public function callService()
    {
        // recupero connessione a db per ottenimento record da aggiornare
        $conn = $this->getPgConnection();

        // preparazione query
        $query = $this->prepareAccessQuery();
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE RECORD DA PROCESSARE. LANCIO QUERY $query");

        $accessRecordsResult = pg_query($conn, $query);

        if(!$accessRecordsResult || pg_num_rows($accessRecordsResult) == -1){
            $selectError = $accessRecordsResult ? pg_result_error($accessRecordsResult) : pg_last_error($conn);
            throw new Exception("Errore nella query di estrazioni accessi. Query $query $selectError");
        }

        if(pg_num_rows($accessRecordsResult) == 0){
            $this->logInstance->printProcessLog("NESSUN RECORD DA PROCESSARE");
            return;
        }
        $this->logInstance->printProcessLog("TOTALE RECORD ESTRATTI: ".pg_num_rows($accessRecordsResult));

        // record a db
        $accessRecords = pg_fetch_all($accessRecordsResult);
        
        // check sul processo dry-run
        if ($this->dryRun) {
            return;
        }

        // chunk array
        $chunkedResults = ANNCSUUtilities::chunkArray($accessRecords, $this->chunkSize);
        $chunkCount = 0;

        $accessObjects = [];

        foreach ($chunkedResults as $block){
            $this->logInstance->newLine();
            $startRecord = $chunkCount*$this->chunkSize+1;
            $endRecord = $chunkCount*$this->chunkSize+count($block);
            $this->logInstance->printProcessLog("Process dei record da $startRecord a $endRecord...");
            $this->logInstance->printProcessLog("PROGRESSIVI IN AGGIORNAMENTO");
            $this->logInstance->printProcessLog(ANNCSUUtilities::getItemsOnProcess($block,"$this->progr_vista_accessi"), false);
            $this->logInstance->printProcessLog("Recupero Voucher PDND");

            // recupero voucher PDND
            $voucher = $this->getPDNDDigestVoucher();

            if($voucher && is_array($voucher) && $voucher['token'] && $voucher['audit_encode']){
                $this->logInstance->printProcessLog("Conferimento in corso...");

                $accessObjectsPart = $this->execMultiPDNDDigestRequest(
                    $block, 
                    $voucher, 
                    'C',
                    [$this,'prepareUpdateCoordRequest'],
                    $this->progr_vista_accessi
                );

                $accessObjects = $accessObjects + $accessObjectsPart;
            } else {
                $this->logInstance->printErrorLog("Impossibile processare il blocco di progressivi. Voucher mancante");
            }

            // processamento del blocco successivo
            $chunkCount++;
        }

        $this->logInstance->newLine();

        // recupero eventuali errori
        $errors = $this->getProcessErrors($accessObjects);
        if(count($errors) > 0) {
            $this->logInstance->printErrorLog('ERRORE DI CONFERIMENTO PER I SEGUENTI PROGRESSIVI:');
            foreach ($errors as $er) {
                $this->logInstance->printErrorLog($er, false);
            }
        }

        $errorProgrs = array_filter($accessObjects, function($s){
            return !$s['success'];
        });
        // estrazione dei conferimenti andati a buon fine
        $successProgrs = array_filter($accessObjects, function($s){
            return $s['success'];
        });

        // aggiornamento a DB per i record conferiti con successo
        $insertValues = array();
        foreach($successProgrs as $progr => $results){
            $access = $results['access'];
            $insertValues[] = $access[$this->id_vista_accessi];
        }
        $values = implode(',',$insertValues);

        if ($values) {
            $updateQuery = "
                    UPDATE $this->schema.$this->tabella_accessi  
                    SET $this->allineato_tabella_accessi = true 
                    WHERE $this->id_tabella_accessi IN ($values)";
            
                    $this->logInstance->printProcessLog("QUERY DI UPDATE RECORD A DB: $updateQuery", false);

            $updateResult = pg_query($conn,$updateQuery);
            $dbUpdated = false;
            if(!$updateResult || pg_affected_rows($updateResult) == 0){
                $errorOnUpdate = $updateResult ? pg_result_error($updateResult) : pg_last_error($conn);
                $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                $this->logInstance->printErrorLog("I SEGUENTI PROGRESSIVI SONO STATI CONFERITI CORRETTAMENTE, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($successProgrs)), false);
                $this->logInstance->printProcessLog("QUERY DI UPDATE: ",false);
                $this->logInstance->printProcessLog($updateQuery, false);
            } else {
                $dbUpdated = true;
            }
        }

        // scrittura log di output
        $this->logInstance->newLine();
        
        $this->logInstance->printProcessLog("STATISTICHE DI CONFERIMENTO");
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE PROGRESSIVI PROCESSATI:........".count(array_keys($accessObjects)));
        $this->logInstance->printProcessLog("TOTALE PROGRESSIVI NON CONFERITI:.....".count(array_keys($errorProgrs)));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($errorProgrs)), false);
        $this->logInstance->printProcessLog("TOTALE PROGRESSIVI CONFERITI:.........".count(array_keys($successProgrs)));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($successProgrs)), false);
        
        if($values && !$dbUpdated) {
            $this->logInstance->printProcessLog("ATTENZIONE! I progressivi conferiti non sono stati aggiornati correttamente a DB");
        }

        return;
    }

    /**
     * Restituisce un array di messaggi di errore estratto dalla risposta del blocco di progressivi conferiti
     * 
     * @param array $accessObj risposta servizio di conferimento
     * 
     * @return array  
     */
    private function getProcessErrors($accessObj)
    {
        $errorMessage = array();
        foreach($accessObj as $progr => $result){
            if(!$result['success']){
                $error = $result['error'];
                $errorMessage[] = "PROGRESSIVO $progr $error";
            }
        }

        return $errorMessage;
    }

    /**
     * Prepara il corpo della richiesta per il conferimento delle coordinate
     * 
     * @param array     $access         oggetto con le informazioni del singolo accesso, proveniente da DB
     * 
     * @return array
     */
    protected function prepareUpdateCoordRequest($access)
    {
        $progressivo_accesso = strval($access[$this->progr_vista_accessi]);
        $x = strval($access[$this->coord_x]);
        $y = strval($access[$this->coord_y]);
        $method = strval($access[$this->metodo]);

        $coordinate_body = array(
            "richiesta" => array(
                "accesso" => array(
                    "codcom" => $this->codcom,
                    "progr_civico" => $progressivo_accesso,
                    "coordinate" => array(
                        "x" => $x,
                        "y" => $y,
                        "metodo" => $method
                    )
                )
            )
        );

        return $coordinate_body;
    }

    /**
     * Restituisce la query per l'ottenimento della lista civici da DB
     * 
     * @return string
     */
    private function prepareAccessQuery()
    {

        return "SELECT * FROM $this->schema.$this->vista_accessi WHERE $this->id_vista_accessi NOT IN (SELECT $this->id_tabella_accessi FROM $this->schema.$this->tabella_accessi WHERE $this->allineato_tabella_accessi = TRUE) AND $this->coord_x IS NOT NULL and $this->coord_y IS NOT NULL order by $this->progr_vista_accessi ASC LIMIT $this->limit";

    }

    /**
     * Controlla che i parametri delle tabelle impostati in configurazione corrispondano alla struttura tabellare
     * @throws Exception
     * @return void
     */
    private function checkDBTables()
    {
        $conn = $this->getPgConnection();
        // seleziona campi da tabella accessi
        $tableQuery = "SELECT $this->id_tabella_accessi,$this->allineato_tabella_accessi FROM $this->schema.$this->tabella_accessi LIMIT 1";

        $testTableResults = pg_query($conn, $tableQuery);
        if(!$testTableResults || pg_num_rows($testTableResults) == -1){
            $selectError = $testTableResults ? pg_result_error($testTableResults) : pg_last_error($conn);
            throw new Exception("Errore nella tabella accessi, controllare la definizione dei campi in configurazione. $selectError");
        }
        
        // seleziona campi da vista accessi
        $viewQuery = "SELECT $this->id_vista_accessi,$this->progr_vista_accessi, $this->coord_x, $this->coord_y, $this->metodo FROM $this->schema.$this->vista_accessi LIMIT 1";

        $testViewResults = pg_query($conn, $viewQuery);
        if(!$testViewResults || pg_num_rows($testViewResults) == -1){
            $selectError = $testViewResults ? pg_result_error($testViewResults) : pg_last_error($conn);
            throw new Exception("Errore nella vista accessi, controllare la definizione dei campi in configurazione. $selectError");
        }
    }

}
