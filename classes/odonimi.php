<?php
require_once(ANNCSU_CLASS_PATH.'services.php');

/**
 * ANNCSUOdonimi
 * La classe si occupa di effettuare le operazioni di gestione degli odonimi (vie/aree di circolazione) su DB ANNCSU.
 * Le operazioni previste sono I(Inserimento), R(Aggiornamento), S(Soppressione) - nessuna operazione di lettura (select).
 *
 * A differenza del servizio di aggiornamento accessi, per gli odonimi il "progressivo nazionale" NON � un
 * riferimento a un'entit� preesistente, ma � l'identificativo che ANNCSU stesso assegna al momento
 * dell'inserimento (analogamente a come "progr_civico" viene assegnato dal servizio accessi): per questo,
 * nella richiesta di inserimento (I) il progressivo nazionale non va MAI valorizzato, mentre � obbligatorio
 * per aggiornamento (R) e soppressione (S).
 *
 * ATTENZIONE: la soglia di chiamate giornaliere per questo servizio � significativamente pi� bassa
 * (50 chiamate/giorno per fruitore, contro le 2000/giorno del servizio accessi) - vedi propriet� $limit.
 */
class ANNCSUOdonimi extends ANNCSUGenericService {

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
        "tabella_odonimi",
        "tabella_operazioni",
        "id_tabella_odonimi",
        "id_tabella_operazioni",
        "id_odonimo_operazioni",
        "progr_tabella_operazioni",
        "progr_tabella_odonimi",
        "codice_comunale",
        "dug",
        "denom_delibera",
        "denom_in_lingua_1",
        "denom_in_lingua_2",
        "denom_localita",
        "provv_data",
        "provv_protocollo",
        "provv_flag_delibera",
        "autpref_data",
        "autpref_protocollo",
        "data_valid_amm",
        "tipo_operazione",
        "allineato_tabella_operazioni",
        "allineato_tabella_odonimi",
    );

    /**
     * Schema del db a cui connettersi
     * @var string
     */
    protected $schema;

    /**
     * Tabella principale degli odonimi (analoga a "civici" per il servizio accessi)
     * @var string
     */
    protected $tabella_odonimi;

    /**
     * Tabella accessoria per lettura degli odonimi da processare
     * @var string
     */
    protected $tabella_operazioni;

    /**
     * Nome del campo univoco incrementale della tabella degli odonimi
     * @var string
     */
    protected $id_tabella_odonimi;

    /**
     * Nome del campo univoco incrementale della tabella delle operazioni
     * @var string
     */
    protected $id_tabella_operazioni;

    /**
     * Nome della colonna presente sulla tabella tabella_operazioni che contiene il riferimento
     * all'id (id_tabella_odonimi) della tabella tabella_odonimi
     * @var string
     */
    protected $id_odonimo_operazioni;

    /**
     * Nome della colonna presente sulla tabella tabella_operazioni che contiene il progressivo
     * nazionale dell'odonimo. NULL per le operazioni di inserimento non ancora processate.
     * @var string
     */
    protected $progr_tabella_operazioni;

    /**
     * Nome della colonna presente sulla tabella tabella_odonimi che contiene il progressivo
     * nazionale dell'odonimo
     * @var string
     */
    protected $progr_tabella_odonimi;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la codifica comunale dell'odonimo (opzionale)
     * @var string
     */
    protected $codice_comunale;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente il DUG (es. "VIA", "PIAZZA")
     * @var string
     */
    protected $dug;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la denominazione da delibera
     * @var string
     */
    protected $denom_delibera;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la denominazione in lingua 1 (comuni bi/trilingue)
     * @var string
     */
    protected $denom_in_lingua_1;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la denominazione in lingua 2 (comuni trilingue)
     * @var string
     */
    protected $denom_in_lingua_2;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la denominazione della localit�
     * @var string
     */
    protected $denom_localita;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la data del provvedimento
     * @var string
     */
    protected $provv_data;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente il protocollo del provvedimento
     * @var string
     */
    protected $provv_protocollo;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente il flag_delibera (0-4)
     * @var string
     */
    protected $provv_flag_delibera;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la data dell'autorizzazione prefettura
     * @var string
     */
    protected $autpref_data;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente il protocollo dell'autorizzazione prefettura
     * @var string
     */
    protected $autpref_protocollo;

    /**
     * Nome della colonna nella tabella tabella_operazioni contenente la data di validit� amministrativa
     * @var string
     */
    protected $data_valid_amm;

    /**
     * Nome del campo che identifica l'operazione da eseguire sull'odonimo (I/R/S)
     * @var string
     */
    protected $tipo_operazione;

    /**
     * Nome della colonna booleana presente nella tabella tabella_operazioni che indica se l'odonimo � allineato con DB ANNCSU
     * @var string
     */
    protected $allineato_tabella_operazioni;

    /**
     * Nome della colonna booleana presente nella tabella tabella_odonimi che indica se l'odonimo � allineato con DB ANNCSU
     * @var string
     */
    protected $allineato_tabella_odonimi;

    /**
     * Limite di chiamate massimo per ogni esecuzione.
     * ATTENZIONE: per questo servizio la soglia PDND dichiarata � di 50 chiamate/giorno per fruitore
     * (contro le 2000/giorno del servizio di aggiornamento accessi) - NON aumentare senza aver
     * verificato la soglia attuale sulla scheda tecnica dell'e-service su piattaforma PDND.
     * @var int
     */
    private $limit = 50;

    /**
     * Dimensione massima per ogni blocco di esecuzione concorrente
     * @var int
     */
    private $chunkSize = 50;

    /**
     * Inizializza il servizio di gestione odonimi ed esegue i controlli necessari su configurazione,
     * chiavi private e connettivit� a DB
     *
     * @param array         $config         Configurazione del client per il servizio richiesto
     * @param array         $options        Opzioni di lancio aggiuntive
     * @param string        $environment    Ambiente di lancio
     * @param ProcessLog    $logInstance    Istanza globale del processo di log
     * @param boolean       $dryRun         Modalit� dry run attiva
     */
    public function __construct($config, $options, $environment, $logInstance, $dryRun)
    {
        parent::__construct($config, $options, $environment, 'odonimi', $dryRun);

        // set delle propriet� della classe
        foreach ($this->config as $confKey => $confValue) {
            if(property_exists($this, $confKey)){
                $this->$confKey = $confValue;
            }
        }

        // set istanza dei log
        $this->setLogInstance($logInstance);
        $this->logInstance->setLogFile('odonimi.log');
        $this->logInstance->printProcessLog(($this->dryRun ? 'SIMULAZIONE - ' : '')."INIZIO PROCESSO DI GESTIONE ODONIMI");

        // controllo configurazione
        $this->initConfiguration($this->configuration_keys);

        // controllo esistenza chiave privata (voucher)
        $this->setPrivateKey();

        // controllo esistenza chiave privata ModI (Agid-JWT-Signature/TrackingEvidence)
        $this->setModiPrivateKey();

        // controllo connettivit� a db
        $this->checkPostgreServiceFile();

        $this->checkDBTables();

        $this->logInstance->newLine();
        if ($this->dryRun) {
            $this->printProcessParameters();
        }
    }

    /**
     * Logica core del servizio, esegue la gestione degli odonimi presenti in coda.
     * Ordine di elaborazione: prima le soppressioni, poi gli aggiornamenti, infine gli inserimenti
     * (stesso ordine adottato dal servizio di aggiornamento accessi).
     */
    public function callService()
    {
        // variabili di processo
        $dbDeleted = false;
        $dbInserted = false;
        $dbUpdated = false;
        $valuesDeleted = null;
        $valuesInserted = null;
        $valuesUpdated = null;
        $insertRecords = array();
        $updatedRecords = array();
        $insertResults = array();
        $updateResults = array();

        // process dei record da SOPPRIMERE prima degli altri
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE ODONIMI DA SOPPRIMERE DA ANNCSU");
        $deletedRecords = $this->processRecords('S');

        if(!$this->dryRun) {
            $deleteResults = $this->processResponses($deletedRecords);
            if(count($deleteResults['success']) > 0){
                $rowsToUpdate = array();
                foreach($deleteResults['success'] as $unique_id => $results){
                    $odonimo = $results['access'];
                    $rowsToUpdate[] = $odonimo[$this->id_tabella_operazioni];
                }
                $valuesDeleted = implode(',', $rowsToUpdate);
                if($valuesDeleted) {
                    $updateQuery = "
                        UPDATE $this->schema.$this->tabella_operazioni
                        SET $this->allineato_tabella_operazioni = true
                        WHERE $this->id_tabella_operazioni IN ($valuesDeleted)";

                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER ODONIMI SOPPRESSI DA ANNCSU: $updateQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn, $updateQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ODONIMI SONO STATI SOPPRESSI CORRETTAMENTE DA ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['success'])), false);
                    } else {
                        $dbDeleted = true;
                    }
                }
            }
            $this->updateDBError($deleteResults['error']);
        }

        // process dei record da AGGIORNARE
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE ODONIMI DA AGGIORNARE SU ANNCSU");
        $updatedRecords = $this->processRecords('R');

        if(!$this->dryRun) {
            $updateResults = $this->processResponses($updatedRecords);

            if(count($updateResults['success']) > 0){
                $rowsOperazioniToUpdate = array();
                $rowsOdonimiToUpdate = array();
                foreach($updateResults['success'] as $unique_id => $results){
                    $odonimo = $results['access'];
                    $rowsOperazioniToUpdate[] = $odonimo[$this->id_tabella_operazioni];
                    $rowsOdonimiToUpdate[] = $odonimo[$this->id_odonimo_operazioni];
                }
                $valuesUpdated = implode(',', $rowsOperazioniToUpdate);
                $valuesUpdatedOdonimi = implode(',', $rowsOdonimiToUpdate);
                if($valuesUpdated) {
                    $updateQueryOperazione = "
                        UPDATE $this->schema.$this->tabella_operazioni
                        SET $this->allineato_tabella_operazioni = true
                        WHERE $this->id_tabella_operazioni IN ($valuesUpdated);";

                    $updateQueryTabella = "
                        UPDATE $this->schema.$this->tabella_odonimi
                        SET $this->allineato_tabella_odonimi = true
                        WHERE $this->id_tabella_odonimi IN ($valuesUpdatedOdonimi);";

                    $finalUpdatedQuery = $updateQueryOperazione.$updateQueryTabella;
                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER ODONIMI AGGIORNATI SU ANNCSU: $finalUpdatedQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn, $finalUpdatedQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ODONIMI SONO STATI AGGIORNATI CORRETTAMENTE SU ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['success'])), false);
                    } else {
                        $dbUpdated = true;
                    }
                }
            }
            $this->updateDBError($updateResults['error']);
        }

        // process dei record da INSERIRE
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("ESTRAZIONE ODONIMI DA INSERIRE SU ANNCSU");
        $insertRecords = $this->processRecords('I');

        if(!$this->dryRun) {
            $insertResults = $this->processResponses($insertRecords);

            if(count($insertResults['success']) > 0){
                $rowsOperazioniToUpdate = array();
                $rowsOdonimiToUpdate = array();
                foreach($insertResults['success'] as $unique_id => $results){
                    $odonimo = $results['access'];
                    // per gli odonimi, a differenza degli accessi, il progressivo restituito
                    // dall'inserimento � "progr_nazionale", non "progr_civico"
                    $progr = $results['updateResponse']['dati'][0]['progr_nazionale'];

                    $rowsOperazioniToUpdate[] = $odonimo[$this->id_tabella_operazioni];
                    $rowsOdonimiToUpdate[] = "(".$odonimo[$this->id_odonimo_operazioni].",'".$progr."')";
                }
                $valuesInserted = implode(',', $rowsOperazioniToUpdate);
                $valuesInsertedOdonimi = implode(',', $rowsOdonimiToUpdate);
                if($valuesInserted) {
                    $insertQueryOperazione = "
                        UPDATE $this->schema.$this->tabella_operazioni
                        SET $this->allineato_tabella_operazioni = true
                        WHERE $this->id_tabella_operazioni IN ($valuesInserted);";

                    $insertQueryTabella = "
                        UPDATE $this->schema.$this->tabella_odonimi as t
                        SET $this->progr_tabella_odonimi = v.progressivo_nazionale, $this->allineato_tabella_odonimi = true from (VALUES $valuesInsertedOdonimi) as v(id,progressivo_nazionale) WHERE v.id = t.$this->id_tabella_odonimi;";

                    $finalUpdatedQuery = $insertQueryOperazione.$insertQueryTabella;
                    $this->logInstance->printProcessLog("QUERY DI AGGIORNAMENTO RECORD A DB PER ODONIMI INSERITI SU ANNCSU: $finalUpdatedQuery", false);
                    $conn = $this->getPgConnection();
                    $recs = pg_query($conn, $finalUpdatedQuery);
                    if(!$recs || pg_affected_rows($recs) == 0){
                        $errorOnUpdate = $recs ? pg_result_error($recs) : pg_last_error($conn);
                        $this->logInstance->printErrorLog('ERRORE IN FASE DI AGGIORNAMENTO DB '.$errorOnUpdate);
                        $this->logInstance->printErrorLog("I SEGUENTI ODONIMI SONO STATI INSERITI CORRETTAMENTE SU ANNCSU, MA NON E STATO POSSIBILE AGGIORNARE IL DB");
                        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['success'])), false);
                    } else {
                        $dbInserted = true;
                    }
                }
            }
            $this->updateDBError($insertResults['error']);
        }

        if ($this->dryRun) {
            return;
        }

        $totalProcessed = count(array_keys($deletedRecords)) + count(array_keys($updatedRecords)) + count(array_keys($insertRecords));

        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("STATISTICHE DI CONFERIMENTO");
        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE ODONIMI PROCESSATI:........".$totalProcessed);

        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE ODONIMI ESTRATTI DA SOPPRIMERE:.....".count(array_keys($deletedRecords)));
        $this->logInstance->printProcessLog("TOTALE ODONIMI SOPPRESSI:.....".count(array_keys($deleteResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE ODONIMI NON SOPPRESSI:.....".count(array_keys($deleteResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($deleteResults['error'])), false);
        if($valuesDeleted && !$dbDeleted) {
            $this->logInstance->printProcessLog("ATTENZIONE! Gli odonimi sono stati soppressi correttamente da ANNCSU ma non � stata aggiornata la tabella operazioni a DB");
        }

        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE ODONIMI ESTRATTI DA AGGIORNARE:.....".count(array_keys($updatedRecords)));
        $this->logInstance->printProcessLog("TOTALE ODONIMI AGGIORNATI:.....".count(array_keys($updateResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE ODONIMI NON AGGIORNATI:.....".count(array_keys($updateResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($updateResults['error'])), false);
        if($valuesUpdated && !$dbUpdated) {
            $this->logInstance->printProcessLog("ATTENZIONE! Gli odonimi sono stati aggiornati correttamente su ANNCSU ma non � stato correttamente aggiornato il DB");
        }

        $this->logInstance->newLine();
        $this->logInstance->printProcessLog("TOTALE ODONIMI ESTRATTI DA INSERIRE:.....".count(array_keys($insertRecords)));
        $this->logInstance->printProcessLog("TOTALE ODONIMI INSERITI:.....".count(array_keys($insertResults['success'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['success'])), false);
        $this->logInstance->printProcessLog("TOTALE ODONIMI NON INSERITI:.....".count(array_keys($insertResults['error'])));
        $this->logInstance->printProcessLog(ANNCSUUtilities::chunkProgrsForDisplay(array_keys($insertResults['error'])), false);
        if($valuesInserted && !$dbInserted) {
            $this->logInstance->printProcessLog("ATTENZIONE! Gli odonimi sono stati inseriti correttamente su ANNCSU ma non � stato correttamente aggiornato il DB");
        }

        return;
    }

    /**
     * Restituisce un array di messaggi di errore estratto dalla risposta del blocco di odonimi conferiti
     * @param array $accessObj risposta servizio di conferimento
     * @return array
     */
    private function getProcessErrors($accessObj)
    {
        $errorMessage = array();
        foreach($accessObj as $progr => $result){
            if(!$result['success']){
                $error = $result['error'];
                $errorMessage[] = "ODONIMO ID $progr $error";
            }
        }

        return $errorMessage;
    }

    /**
     * Aggiorna la tabella delle operazioni con un messaggio di errore per ogni odonimo, se presente
     * @param mixed $errors
     * @return void
     */
    private function updateDBError($errors)
    {
        $conn = $this->getPgConnection();
        foreach($errors as $error){
            $access = $error['access'];
            $error = $error['error'];

            $rows[] = "(".$access[$this->id_tabella_operazioni].",'".pg_escape_string($conn, $error)."')";

            $errorToinsert = implode(',', $rows);
            if($errorToinsert) {
                $errorQuery = "
                    UPDATE $this->schema.$this->tabella_operazioni as t
                    SET error = v.error from (VALUES $errorToinsert) as v(id,error) WHERE v.id = t.$this->id_tabella_operazioni;";

                $recs = pg_query($conn, $errorQuery);
            }
        }
    }

    /**
     * Processa i record dell'operazione richiesta ($operation) estraendoli da DB ed eseguendo,
     * se non in dry-run, le chiamate verso l'e-service
     * @throws Exception
     * @return array
     */
    private function processRecords($operation){
        $recordsObject = array();
        $query = $this->prepareOdonimoQuery($operation);
        $this->logInstance->printProcessLog("LANCIO QUERY $query");
        try {
            $odonimiRecordsResult = $this->executeQuery($query);

            if($odonimiRecordsResult['count'] == 0) {
                $this->logInstance->printProcessLog("NESSUN RECORD DA PROCESSARE.");
            } else {
                $this->logInstance->printProcessLog("TOTALE RECORD ESTRATTI: ".$odonimiRecordsResult['count']);
            }

            if ($this->dryRun) {
                return $odonimiRecordsResult['records'];
            }

            $recordsToProcess = $odonimiRecordsResult['records'];
            if($odonimiRecordsResult['count'] > 0) {
                $recordsObject = $this->processChunks($recordsToProcess);
            }

            return $recordsObject;

        } catch (Exception $e){
            throw new Exception("Errore nella query di estrazione odonimi. Query ".$e->getMessage());
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
            $this->logInstance->printErrorLog('ERRORE PER I SEGUENTI ODONIMI:');
            foreach ($errors as $er) {
                $this->logInstance->printErrorLog($er, false);
            }
        }

        $errorProgrs = array_filter($accessObects, function($s){
            return !$s['success'];
        });
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
        $odonimiRecordsResult = pg_query($conn, $query);

        if(!$odonimiRecordsResult || pg_num_rows($odonimiRecordsResult) == -1){
            $selectError = $odonimiRecordsResult ? pg_result_error($odonimiRecordsResult) : pg_last_error($conn);
            throw new Exception("$query $selectError");
        }

        $results['records'] = pg_fetch_all($odonimiRecordsResult);
        $results['count'] = pg_num_rows($odonimiRecordsResult);

        return $results;
    }

    /**
     * Prepara il corpo della richiesta per la gestione dell'odonimo
     *
     * @param array     $odonimo    oggetto con le informazioni del singolo odonimo, proveniente da DB
     *
     * @return array|null
     */
    protected function prepareUpdateAccessRequest($odonimo)
    {
        $body = null;
        if(
            !is_array($odonimo) ||
            !array_key_exists($this->tipo_operazione, $odonimo)
        ) return null;
        $operation = $odonimo[$this->tipo_operazione];

        switch($operation){
            case 'I':
            case 'R':
                // NB: la richiesta di inserimento NON deve contenere il progressivo nazionale,
                // che viene assegnato da ANNCSU e restituito nella risposta
                $richiesta = array(
                    "codcom" => strval($this->codcom),
                    "tipo_operazione" => $operation,
                );
                if ($operation === 'R') {
                    $richiesta["progr_nazionale"] = strval($odonimo[$this->progr_tabella_operazioni]);
                }
                $richiesta["codice_comunale"] = strval($odonimo[$this->codice_comunale]);
                $richiesta["dug"] = strval($odonimo[$this->dug]);
                $richiesta["denom_delibera"] = strval($odonimo[$this->denom_delibera]);
                $richiesta["denom_in_lingua_1"] = strval($odonimo[$this->denom_in_lingua_1]);
                $richiesta["denom_in_lingua_2"] = strval($odonimo[$this->denom_in_lingua_2]);
                $richiesta["denom_localita"] = strval($odonimo[$this->denom_localita]);

                // provvedimento: la specifica tecnica lega la presenza di data/protocollo
                // ESCLUSIVAMENTE al valore di flag_delibera (confermato dalle risposte reali di
                // ANNCSU, sia in inserimento che in aggiornamento):
                // - flag_delibera = '0' o '1' -> data e protocollo OBBLIGATORI
                // - qualunque altro valore (anche vuoto) -> data e protocollo VIETATI,
                //   l'intero oggetto "provvedimento" va omesso dal JSON
                $provvProtocollo = strval($odonimo[$this->provv_protocollo]);
                $provvFlagDelibera = strval($odonimo[$this->provv_flag_delibera]);
                $provvData = ANNCSUUtilities::formatDateForANNCSU($odonimo[$this->provv_data]);

                if (in_array($provvFlagDelibera, ['0','1'], true)) {
                    $provvedimento = array(
                        "protocollo" => $provvProtocollo,
                        "flag_delibera" => $provvFlagDelibera,
                    );
                    if ($provvData !== '') {
                        $provvedimento = array_merge(["data" => $provvData], $provvedimento);
                    }
                    $richiesta["provvedimento"] = $provvedimento;
                }
                // se flag_delibera non � 0/1 ma � comunque valorizzato (2/3/4), lo si invia da solo,
                // senza data/protocollo (l'oggetto "provvedimento" richiede comunque flag_delibera
                // se lo si include; se invece flag_delibera � del tutto assente insieme a data e
                // protocollo, si omette l'intero oggetto)
                elseif ($provvFlagDelibera !== '') {
                    $richiesta["provvedimento"] = array(
                        "protocollo" => '',
                        "flag_delibera" => $provvFlagDelibera,
                    );
                }

                // aut_prefettura: sempre facoltativo (qualunque operazione). Stesso principio:
                // si omette l'INTERO oggetto se entrambi i campi sono vuoti, invece di inviare
                // un oggetto parziale con "protocollo_pref": "" e "data_pref" assente.
                $autPrefProtocollo = strval($odonimo[$this->autpref_protocollo]);
                $autPrefData = ANNCSUUtilities::formatDateForANNCSU($odonimo[$this->autpref_data]);

                if ($autPrefData !== '' || $autPrefProtocollo !== '') {
                    $autPrefettura = array(
                        "protocollo_pref" => $autPrefProtocollo,
                    );
                    if ($autPrefData !== '') {
                        $autPrefettura = array_merge(["data_pref" => $autPrefData], $autPrefettura);
                    }
                    $richiesta["aut_prefettura"] = $autPrefettura;
                }

                // data_valid_amm: stesso principio, omessa se assente (l'API la considera opzionale
                // con default alla data corrente)
                $dataValidAmm = ANNCSUUtilities::formatDateForANNCSU($odonimo[$this->data_valid_amm]);
                if ($dataValidAmm !== '') {
                    $richiesta["data_valid_amm"] = $dataValidAmm;
                }

                $body = array("richiesta" => $richiesta);
                break;
            case 'S':
                // NB: la soppressione ammette SOLO l'identificativo (progr_nazionale), nessun campo descrittivo
                $richiesta = array(
                    "codcom" => strval($this->codcom),
                    "tipo_operazione" => 'S',
                    "progr_nazionale" => strval($odonimo[$this->progr_tabella_operazioni]),
                );
                $dataValidAmm = ANNCSUUtilities::formatDateForANNCSU($odonimo[$this->data_valid_amm]);
                if ($dataValidAmm !== '') {
                    $richiesta["data_valid_amm"] = $dataValidAmm;
                }
                $body = array("richiesta" => $richiesta);
                break;
            default:
                break;
        }

        return $body;
    }

    /**
     * Restituisce la query per l'ottenimento della lista odonimi da DB in base al tipo di operazione $operation
     * @param string $operation
     * @return string
     */
    private function prepareOdonimoQuery($operation)
    {
        return "SELECT * FROM $this->schema.$this->tabella_operazioni WHERE $this->tipo_operazione = '$operation' AND $this->allineato_tabella_operazioni IS NOT TRUE LIMIT $this->limit";
    }

    /**
     * Esegue le operazioni di gestione per i record passati $records
     * @param array $records
     * @return array
     */
    private function processChunks($records){
        $chunkedResults = ANNCSUUtilities::chunkArray($records, $this->chunkSize);
        $chunkCount = 0;
        $accessObjects = [];

        // stringa usata per riconoscere lo specifico errore di interoperabilit� (GovWay) che, per
        // esperienza, tende a presentarsi quando il recupero del voucher ha dovuto lottare (pi�
        // tentativi/timeout) per andare a buon fine - riprovando l'intero blocco da capo (nuovo
        // voucher compreso) il problema si risolve quasi sempre.
        $interopErrorNeedle = 'not conform to the required interoperability profile';
        $maxBlockAttempts = 3; // 1 tentativo iniziale + 2 retry

        foreach ($chunkedResults as $block){
            $this->logInstance->newLine();
            $startRecord = $chunkCount*$this->chunkSize+1;
            $endRecord = $chunkCount*$this->chunkSize+count($block);
            $this->logInstance->printProcessLog("Process dei record da $startRecord a $endRecord...");
            $this->logInstance->printProcessLog("ID ODONIMI IN AGGIORNAMENTO");
            $this->logInstance->printProcessLog(ANNCSUUtilities::getItemsOnProcess($block, $this->id_odonimo_operazioni), false);

            $accessObjectsPart = [];

            for ($blockAttempt = 1; $blockAttempt <= $maxBlockAttempts; $blockAttempt++) {
                $this->logInstance->printProcessLog("Recupero Voucher PDND".($blockAttempt > 1 ? " (tentativo $blockAttempt/$maxBlockAttempts)" : ""));

                $voucher = $this->getPDNDDigestVoucher();

                if(!$voucher || !is_array($voucher) || !$voucher['token'] || !$voucher['audit_encode']){
                    $this->logInstance->printErrorLog("Impossibile processare il blocco di odonimi. Voucher mancante");
                    $accessObjectsPart = [];
                    break;
                }

                $this->logInstance->printProcessLog("Aggiornamento in corso...");

                $accessObjectsPart = $this->execMultiPDNDDigestRequest(
                    $block,
                    $voucher,
                    'O',
                    [$this,'prepareUpdateAccessRequest'],
                    $this->id_odonimo_operazioni
                );

                // controlla se lo specifico errore di interoperabilit� � presente su ALMENO uno dei
                // record del blocco: in tal caso, se restano tentativi disponibili, si riprova
                // l'intero blocco (nuovo voucher compreso) invece di segnarlo definitivamente fallito
                $hasInteropError = false;
                foreach ($accessObjectsPart as $result) {
                    if (!$result['success'] && stripos($result['error'], $interopErrorNeedle) !== false) {
                        $hasInteropError = true;
                        break;
                    }
                }

                if (!$hasInteropError) {
                    break;
                }

                if ($blockAttempt < $maxBlockAttempts) {
                    $this->logInstance->printProcessLog("Rilevato errore di interoperabilit� (probabile instabilit� di rete transitoria), riprovo l'intero blocco tra 3 secondi...");
                    sleep(3);
                } else {
                    $this->logInstance->printErrorLog("Errore di interoperabilit� persistito dopo $maxBlockAttempts tentativi, il blocco viene segnato come fallito");
                }
            }

            $accessObjects = $accessObjects + $accessObjectsPart;

            $chunkCount++;
        }

        return $accessObjects;
    }

    /**
     * Controlla che i parametri delle tabelle impostati in configurazione corrispondano alla struttura tabellare
     * @throws Exception
     * @return void
     */
    private function checkDBTables()
    {
        $conn = $this->getPgConnection();

        $tableQuery = "SELECT $this->id_tabella_odonimi,$this->allineato_tabella_odonimi, $this->progr_tabella_odonimi FROM $this->schema.$this->tabella_odonimi LIMIT 1";

        $testTableResults = pg_query($conn, $tableQuery);
        if(!$testTableResults || pg_num_rows($testTableResults) == -1){
            $selectError = $testTableResults ? pg_result_error($testTableResults) : pg_last_error($conn);
            throw new Exception("Errore nella tabella odonimi, controllare la definizione dei campi in configurazione. $selectError");
        }

        $viewQuery = "SELECT $this->id_tabella_operazioni,$this->progr_tabella_operazioni, $this->codice_comunale, $this->dug, $this->denom_delibera, $this->denom_in_lingua_1, $this->denom_in_lingua_2, $this->denom_localita, $this->provv_data, $this->provv_protocollo, $this->provv_flag_delibera, $this->autpref_data, $this->autpref_protocollo, $this->data_valid_amm, $this->tipo_operazione, $this->allineato_tabella_operazioni, error FROM $this->schema.$this->tabella_operazioni LIMIT 1";

        $testViewResults = pg_query($conn, $viewQuery);
        if(!$testViewResults || pg_num_rows($testViewResults) == -1){
            $selectError = $testViewResults ? pg_result_error($testViewResults) : pg_last_error($conn);
            throw new Exception("Errore nella tabella operazioni odonimi, controllare la definizione dei campi in configurazione. $selectError");
        }
    }
}
