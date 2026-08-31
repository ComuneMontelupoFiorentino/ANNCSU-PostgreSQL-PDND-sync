<?php
require_once(ANNCSU_CLASS_PATH.'services.php');

/**
 * ANNCSUAggiornamento
 * La classe si occupa di effettuare le operazioni di aggiornamento dei civici su DB ANNCSU
 * Le operazioni prevedono I(Inserimento), R(Aggiornamento), S(Cancellazione)
 */
class ANNCSUAggiornamento extends ANNCSUGenericService {

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
        "tabella_operazioni",
        "id_tabella_accessi",
        "id_tabella_operazioni",
        "id_civico_operazioni",
        "progr_naz",
        "progr_tabella_operazioni",
        "progr_tabella_accessi",
        "civico",
        "esp",
        "sez_cens",
        "coord_x",
        "coord_y",
        "metodo",
        "tipo_operazione",
        "allineato_tabella_operazioni",
        "allineato_tabella_accessi",
    );

    /**
     * Schema del db a cui connettersi
     * @var string
     */
    protected $schema;

    /**
     * Tabella accessi
     * @var string
     */

    protected $tabella_accessi;
    /**
     * Tabella accessoria per lettura civici da processare
     * @var string
     */
    protected $tabella_operazioni;

    /**
     * Nome del campo univoco incrementale della tabella degli accessi
     * @var string
     */
    protected $id_tabella_accessi;

    /**
     * Nome del campo univoco incrementale della tabella delle operazioni
     * @var string
     */
    protected $id_tabella_operazioni;

    /**
     * Nome della colonna presente sulla tabella tabella_operazioni che contiene il riferimento all'id (id_tabella_accessi) della tabella tabella_accessi
     * @var string
     */
    protected $id_civico_operazioni;

    /**
     * Nome della colonna della tabella accessoria contente il Progressivo nazionale dell'odonimo
     * @var string
     */
    protected $progr_naz;

    /**
     * Nome della colonna presente sulla tabella tabella_operazioni che contiene il progressivo accesso del civico
     * @var string
     */
    protected $progr_tabella_operazioni;

    /**
     * Nome della colonna presente sulla tabella tabella_accessi che contiene il progressivo accesso del civico
     * @var string
     */
    protected $progr_tabella_accessi;
    /**
     * Limite di chiamate massimo per ogni esecuzione
     * @var string
     */

    /**
     * Nome della colonna nella tabella tabella_operazioni che contiene il valore numerico del civico     
     * @var string
     */
    protected $civico;

    /**
     * Nome della colonna nella tabella tabella_operazioni che contiene il valore alfanumerico dell'esponente del civico
     * @var string
     */
    protected $esp;

    /**
     * Nome della colonna nella tabella tabella_operazioni che contiene il valore della sezione censimento del civico
     * @var string
     */
    protected $sez_cens;

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
     * Nome del campo del record accesso che identifica l'operazione da eseguire sul civico (solo per funzionalit� di aggiornamento)
     * @var string
     */
    protected $tipo_operazione;

    /**
     * Nome della colonna booleana presente nella tabella tabella_operazioni che indica se il civico � allineato con DB ANNCSU 
     * @var string
     */
    protected $allineato_tabella_operazioni;

    /**
     * Nome della colonna booleana presente nella tabella tabella_accessi che indica se il civico � allineato con DB ANNCSU 
     * @var string
     */
    protected $allineato_tabella_accessi;

    private $limit = 2000;

    /**
     * Dimensione massima per ogni blocco di esecuzione
     * Es: Se il limite � 500, i dati vengono conferiti in 3 blocchi, i primi due da 200 record ciascuno, l'ultimo da 100
     * @var string
     */
    private $chunkSize = 500;

    /**
     * Inizializza il servizio di conferimento coordinate ed esegue i controlli necessari su configurazione, 
     * chiave privata e connettivit� a DB
     * 
     * @param array         $config         Configurazione del client per il servizio richiesto
     * @param array         $options        Opzioni di lancio aggiuntive
     * @param string        $environment    Ambiente di lancio
     * @param ProcessLog    $logInstance    Istanza globale del processo di log
     * @param boolean       $dryRun         Modalit� dry run attiva
     */
    public function __construct($config, $options, $environment, $logInstance, $dryRun) 
    {
        parent::__construct($config,$options, $environment,'aggiornamento', $dryRun);

        // set delle propriet� della classe
        foreach ($this->config as $confKey => $confValue) {
            if(property_exists($this, $confKey)){
                $this->$confKey = $confValue;
            }
        }

        // set istanza dei log
        $this->setLogInstance($logInstance);
        $this->logInstance->setLogFile('aggiornamento.log');
        $this->logInstance->printProcessLog(($this->dryRun ? 'SIMULAZIONE - ' : '')."INIZIO PROCESSO DI AGGIORNAMENTO ACCESSI");

        // controllo configurazione
        $this->initConfiguration($this->configuration_keys);
        
        // controllo esistenza chiave privata (voucher)
        $this->setPrivateKey();

        // controllo esistenza chiave privata ModI (Agid-JWT-Signature/TrackingEvidence)
        $this->setModiPrivateKey();

        // controllo connettivit� a db
        $this->checkPostgreServiceFile();

        $this->checkDbTables();
        
        $this->logInstance->newLine();
        if ($this->dryRun) {
            $this->printProcessParameters();
        }
    }

    /**
     * Logica core del servizio, esegue l'aggiornamento degli accessi presenti
     */
    public function callService()
    {
        // variabili di processo
        $dbDeleted = false;
        $dbInserted = false;
        $dbUpdated = false;
        $valuesDeleted= null;
        $valuesInserted = null;
        $valuesUpdated = null;
        $insertRecords = array();
        $updatedRecords = array();
        $insertResults = array();
        $updateResults = array();

        // process dei record da CANCELLARE prima degli altri
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE RECORD DA RIMUOVERE DA ANNCSU");
        $deletedRecords = $this->processRecords('S');

        if(!$this->dryRun) {
            // gestione errori
            $deleteResults = $this->processResponses($deletedRecords);
            // aggiornamento db con record cancellati
            if(count($deleteResults['success'])>0){
                $rowsToUpdate = array();
                // aggiornare il database spuntando la casella allineato su tabella dei jobs
                foreach($deleteResults['success'] as $unique_id => $results){
                    $access=$results['access'];
                    $rowsToUpdate[] = $access[$this->id_tabella_operazioni];
                }
                $valuesDeleted = implode(',',$rowsToUpdate);
                if($valuesDeleted) {
                    $updateQuery = "
                        UPDATE $this->schema.$this->tabella_operazioni 
                        SET $this->allineato_tabella_operazioni = true 
                        WHERE $this->id_tabella_operazioni IN ($valuesDeleted)";

                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER RECORD RIMOSSI DA ANNCSU: $updateQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn,$updateQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ACCESSI SONO STATI RIMOSSI CORRETTAMENTE DA ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['success'])), false);
                    } else {
                        $dbDeleted = true;
                    }
                }
            }
            // scrittura errori in tabella
            $this->updateDBError($deleteResults['error']);
        }


        // process dei record da AGGIORNARE
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE RECORD DA AGGIORNARE SU ANNCSU");
        // process dei record da aggiornare
        $updatedRecords = $this->processRecords('R');

        if(!$this->dryRun) {
            $updateResults = $this->processResponses($updatedRecords);

            // aggiornamento db con record aggiornati
            if(count($updateResults['success'])>0){
                // aggiornare il database spuntando la casella allineato su tabella dei jobs
                $rowsOperazioniToUpdate = array();
                $rowsAccessiToUpdate = array();
                foreach($updateResults['success'] as $unique_id => $results){
                    $access=$results['access'];
                    $rowsOperazioniToUpdate[] = $access[$this->id_tabella_operazioni];
                    $rowsAccessiToUpdate[] = $access[$this->id_civico_operazioni];
                }
                $valuesUpdated = implode(',',$rowsOperazioniToUpdate);
                $valuesUpdatedAccessi = implode(',',$rowsAccessiToUpdate);
                if($valuesUpdated) {
                    
                    $updateQueryOperazione = "
                        UPDATE $this->schema.$this->tabella_operazioni
                        SET $this->allineato_tabella_operazioni = true 
                        WHERE $this->id_tabella_operazioni IN ($valuesUpdated);";
                    
                    $updateQueryTabella = "
                        UPDATE $this->schema.$this->tabella_accessi
                        SET $this->allineato_tabella_accessi = true 
                        WHERE $this->id_tabella_accessi IN ($valuesUpdatedAccessi);";
    
                    $finalUpdatedQuery = $updateQueryOperazione.$updateQueryTabella;
                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER RECORD AGGIORNATI SU ANNCSU: $finalUpdatedQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn,$finalUpdatedQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ACCESSI SONO STATI AGGIORNATI CORRETTAMENTE SU ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['success'])), false);
                    } else {
                        $dbUpdated = true;
                    }
                }
            }
            // scrittura errori in tabella
            $this->updateDBError($updateResults['error']);
        }

        // process dei record da INSERIRE
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE RECORD DA INSERIRE SU ANNCSU");
        // process dei record da aggiornare
        $insertRecords = $this->processRecords('I');

        if (!$this->dryRun) {
            $insertResults = $this->processResponses($insertRecords);

            // aggiornamento db con record inseriti
            if(count($insertResults['success'])>0){
                // aggiornare il database spuntando la casella allineato su tabella dei jobs
                $rowsOperazioniToUpdate = array();
                $rowsAccessiToUpdate = array();
                foreach($insertResults['success'] as $unique_id => $results){
                    $access=$results['access'];
                    $progr = $results['updateResponse']['dati'][0]['progr_civico'];
    
                    $rowsOperazioniToUpdate[] = $access[$this->id_tabella_operazioni];
                    $rowsAccessiToUpdate[] = "(".$access[$this->id_civico_operazioni].",".$progr.")";
                }
                $valuesInserted = implode(',',$rowsOperazioniToUpdate);
                $valuesInsertedAccessi = implode(',',$rowsAccessiToUpdate);
                if($valuesInserted) {
                    
                    $insertQueryOperazione = "
                        UPDATE $this->schema.$this->tabella_operazioni
                        SET $this->allineato_tabella_operazioni = true 
                        WHERE $this->id_tabella_operazioni IN ($valuesInserted);";
                    
                    $insertQueryTabella = "
                        UPDATE $this->schema.$this->tabella_accessi as t 
                        SET $this->progr_tabella_accessi = v.progressivo_accesso, $this->allineato_tabella_accessi = true from (VALUES $valuesInsertedAccessi) as v(id,progressivo_accesso) WHERE v.id = t.$this->id_tabella_accessi;";
    
                    $finalUpdatedQuery = $insertQueryOperazione.$insertQueryTabella;
                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER RECORD INSERITI SU ANNCSU: $finalUpdatedQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn,$finalUpdatedQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ACCESSI SONO STATI INSERITI CORRETTAMENTE SU ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['success'])), false);
                    } else {
                        $dbInserted = true;
                    }
                }
            }
            // scrittura errori in tabella
            $this->updateDBError($insertResults['error']);
        }

        if ($this->dryRun) {
            return;
        }

        $totalProcessed = count(array_keys($deletedRecords)) + count(array_keys($updatedRecords)) + count(array_keys($insertRecords));
        
        //scrittura log di output
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("STATISTICHE DI CONFERIMENTO");
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE CIVICI PROCESSATI:........".$totalProcessed);

        $this->logInstance->newLine();
        // cancellazione
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI DA CANCELLARE:.....".count(array_keys($deletedRecords)));
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI CANCELLATI:.....".count(array_keys($deleteResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI NON CANCELLATI:.....".count(array_keys($deleteResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['error'])), false);
        if($valuesDeleted && !$dbDeleted) {
            $this->logInstance->printProcessLog("ATTENZIONE! I civici sono stati cancellati correttamente da ANNCSU ma non � stata aggiornata la tabella operazioni a DB");
        }

        $this->logInstance->newLine();
        // aggiornamento
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI DA AGGIORNARE:.....".count(array_keys($updatedRecords)));
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI AGGIORNATI:.....".count(array_keys($updateResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI NON AGGIORNATI:.....".count(array_keys($updateResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['error'])), false);
        if($valuesUpdated && !$dbUpdated) {
            $this->logInstance->printProcessLog("ATTENZIONE! I civici sono stati aggiornati correttamente su ANNCSU ma non � stato correttamente aggiornato il DB");
        }

        $this->logInstance->newLine();
        // inserimento
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI DA INSERIRE:.....".count(array_keys($insertRecords)));
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI INSERITI:.....".count(array_keys($insertResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE CIVICI ESTRATTI NON INSERITI:.....".count(array_keys($insertResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['error'])), false);
        if($valuesInserted && !$dbInserted) {
            $this->logInstance->printProcessLog("ATTENZIONE! I civici sono stati inseriti correttamente su ANNCSU ma non � stato correttamente aggiornato il DB");
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
                $errorMessage[] = "ACCESSO ID $progr $error";
            }
        }

        return $errorMessage;
    }
    /**
     * Aggiorna la tabella delle operazioni con un messaggio di errore er ogni civico, se presente
     * @param mixed $errors
     * @return void
     */
    private function updateDBError($errors)
    {
        $conn = $this->getPgConnection();
        foreach($errors as $error){

            $access = $error['access'];
            $error = $error['error'];

            $rows[] = "(".$access[$this->id_tabella_operazioni].",'".pg_escape_string($conn,$error)."')";

            $errorToinsert = implode(',',$rows);
            if($errorToinsert) {
                $errorQuery = "
                    UPDATE $this->schema.$this->tabella_operazioni as t 
                    SET error = v.error from (VALUES $errorToinsert) as v(id,error) WHERE v.id = t.$this->id_tabella_operazioni;";

                $recs = pg_query($conn,$errorQuery);
            }
        }
    }
    /**
     * Esegue leoperazioni di aggiornamento per i record passati $records
     * @param array $records
     * @return array
     */
    private function processChunks($records){
        $chunkedResults = ANNCSUUtilities::chunkArray($records, $this->chunkSize);
        $chunkCount = 0;
        $accessObjects = [];

        foreach ($chunkedResults as $block){
            $this->logInstance->newLine();
            $startRecord = $chunkCount*$this->chunkSize+1;
            $endRecord = $chunkCount*$this->chunkSize+count($block);
            $this->logInstance->printProcessLog("Process dei record da $startRecord a $endRecord...");
            $this->logInstance->printProcessLog("ID ACCESSI IN AGGIORNAMENTO");
            $this->logInstance->printProcessLog(ANNCSUUtilities::getItemsOnProcess($block, $this->id_civico_operazioni), false);
            $this->logInstance->printProcessLog("Recupero Voucher PDND");

            // recupero voucher PDND
            $voucher = $this->getPDNDDigestVoucher();

            if($voucher && is_array($voucher) && $voucher['token'] && $voucher['audit_encode']){
                $this->logInstance->printProcessLog("Aggiornamento in corso...");

                $accessObjectsPart = $this->execMultiPDNDDigestRequest(
                    $block, 
                    $voucher, 
                    'A',
                    [$this,'prepareUpdateAccessRequest'],
                    $this->id_civico_operazioni
                );

                $accessObjects = $accessObjects + $accessObjectsPart;
            } else {
                $this->logInstance->printErrorLog("Impossibile processare il blocco di progressivi. Voucher mancante");
            }

            // processamento del blocco successivo
            $chunkCount++;
        }

        return $accessObjects;
    }

    /**
     * Processa i record da cancellare
     * @throws Exception
     * @return array
     */
    private function processRecords($operation){
        // estrazione record da db
        $recordsObject = array();
        $query = $this->prepareAccessQuery($operation);
        $this->logInstance->printProcessLog("LANCIO QUERY $query");
        try {
            $accessRecordsResult = $this->executeQuery($query);
            
            if($accessRecordsResult['count'] == 0) {
                $this->logInstance->printProcessLog("NESSUN RECORD DA PROCESSARE.");
            } else {
                $this->logInstance->printProcessLog("TOTALE RECORD ESTRATTI: ".$accessRecordsResult['count']);
            }

            if ($this->dryRun) {
                return $accessRecordsResult['records'];
            }

            $recordsToProcess = $accessRecordsResult['records'];
            if($accessRecordsResult['count'] > 0) {
                $recordsObject = $this->processChunks($recordsToProcess);
            }

            return $recordsObject;

        } catch (Exception $e){
            throw new Exception("Errore nella query di estrazioni accessi. Query ".$e->getMessage());
        }
    }

    /**
     * process delle risposte di aggiornamento
     * @param array $accessObects
     * @return array
     */
    private function processResponses($accessObects){
        $errors = $this->getProcessErrors($accessObects);
        if(count($errors) > 0) {
            $this->logInstance->printErrorLog('ERRORE PER I SEGUENTI PROGRESSIVI:');
            foreach ($errors as $er) {
                $this->logInstance->printErrorLog($er, false);
            }
        }

        $errorProgrs = array_filter($accessObects, function($s){
            return !$s['success'];
        });
        // estrazione dei conferimenti andati a buon fine
        $successProgrs = array_filter($accessObects, function($s){
            return $s['success'];
        });

        return array(
            'error' => $errorProgrs,
            'success' => $successProgrs
        );
    }

    /**
     * Esegue una query di SELECT a DB e ritorna i record estratti o errore
     * @param string $query
     * @return array
     * @throws Exception
     */
    protected function executeQuery($query){
        $results = array(
            'records' => array(),
            'count' => 0
        );
        $conn = $this->getPgConnection();
        $accessRecordsResult = pg_query($conn, $query);

        if(!$accessRecordsResult || pg_num_rows($accessRecordsResult) == -1){
            $selectError = $accessRecordsResult ? pg_result_error($accessRecordsResult) : pg_last_error($conn);
            throw new Exception("$query $selectError");
        }

        $results['records'] = pg_fetch_all($accessRecordsResult);
        $results['count'] = pg_num_rows($accessRecordsResult);

        return $results;
    }

    /**
     * Prepara il corpo della richiesta per l'aggiornamento del civico
     * 
     * @param array     $access         oggetto con le informazioni del singolo accesso, proveniente da DB
     * 
     * @return array|null
     */
    protected function prepareUpdateAccessRequest($access)
    {
        $body = null;
        if(
            !is_array($access) || 
            !array_key_exists($this->tipo_operazione,$access)
        ) return null;
        $operation = $access[$this->tipo_operazione];

        switch($operation){
            case 'I':
                $body = array(
                    "richiesta" => array(
                        "codcom" => strval($this->codcom),
                        "progr_nazionale" => strval($access[$this->progr_naz]),
                        "accesso" => array(
                            "sezione_censimento" => strval($access[$this->sez_cens]),
                            "operazione_civico" => 'I',
                            "numero" => strval($access[$this->civico]),
                            "esponente" => strval($access[$this->esp]),
                            "coordinate" => array(
                                "x" => strval($access[$this->coord_x]),
                                "y" => strval($access[$this->coord_y]),
                                "metodo" => strval($access[$this->metodo])
                            )
                        )
                    )
                );

                break;
            case 'R':
                $body = array(
                    "richiesta" => array(
                        "codcom" => strval($this->codcom),
                        "progr_nazionale" => strval($access[$this->progr_naz]),
                        "accesso" => array(
                            "progr_civico" => strval($access[$this->progr_tabella_operazioni]),
                            "sezione_censimento" => strval($access[$this->sez_cens]),
                            "operazione_civico" => 'R',
                            "numero" => strval($access[$this->civico]),
                            "esponente" => strval($access[$this->esp]),
                            "coordinate" => array(
                                "x" => strval($access[$this->coord_x]),
                                "y" => strval($access[$this->coord_y]),
                                "metodo" => strval($access[$this->metodo])
                            )
                        )
                    )
                );
            break;
            case 'S':
                $body = array(
                    "richiesta" => array(
                        "codcom" => strval($this->codcom),
                        "progr_nazionale" => strval($access[$this->progr_naz]),
                        "accesso" => array(
                            "progr_civico" => strval($access[$this->progr_tabella_operazioni]),
                            "operazione_civico" => 'S',
                        )
                    )
                );
            break;
            default:
            break;
        }

        return $body;
    }

    /**
     * Restituisce la query per l'ottenimento della lista civici da DB in base al tipo di operazione $operation
     * 
     * @param string    $operation  se restituire la query semplice o il prepared statement, default = false
     * 
     * @return string  
     */
    private function prepareAccessQuery($operation)
    {   
        return "SELECT * FROM $this->schema.$this->tabella_operazioni WHERE $this->tipo_operazione = '$operation' AND $this->allineato_tabella_operazioni IS NOT TRUE LIMIT $this->limit";
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
        $tableQuery = "SELECT $this->id_tabella_accessi,$this->allineato_tabella_accessi, $this->progr_tabella_accessi FROM $this->schema.$this->tabella_accessi LIMIT 1";

        $testTableResults = pg_query($conn, $tableQuery);
        if(!$testTableResults || pg_num_rows($testTableResults) == -1){
            $selectError = $testTableResults ? pg_result_error($testTableResults) : pg_last_error($conn);
            throw new Exception("Errore nella tabella accessi, controllare la definizione dei campi in configurazione. $selectError");
        }

        // seleziona campi da vista accessi
        $viewQuery = "SELECT $this->id_tabella_operazioni,$this->progr_naz, $this->progr_tabella_operazioni, $this->civico, $this->esp, $this->sez_cens, $this->coord_x, $this->coord_y, $this->metodo, $this->tipo_operazione, $this->allineato_tabella_operazioni, error FROM $this->schema.$this->tabella_operazioni LIMIT 1";

        $testViewResults = pg_query($conn, $viewQuery);
        if(!$testViewResults || pg_num_rows($testViewResults) == -1){
            $selectError = $testViewResults ? pg_result_error($testViewResults) : pg_last_error($conn);
            throw new Exception("Errore nella tabella operazioni, controllare la definizione dei campi in configurazione. $selectError");
        }
    }
}
