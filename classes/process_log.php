<?php
/**
 * ProcessLog
 * 
 * Classe che gestisce le operazioni di logging delle esecuzioni
 */
class ProcessLog {
    /**
     * Messaggio di log totale dell'intera esecuzione
     * 
     * @var string
     */
    private $logMessage = '';

    /**
     * Percorso assoluto del file di log per l'esecuzione corrente
     * 
     * @var string
     */
    private $logFile;

    /**
     * Messaggio di help dello script
     * 
     * @var string
     */
    private $help = "
    Script multifunzione per richiamare i servizi PDND per aggiornamento dati accessi comunali
    
    Parametri:
        --test:         specifica se i dati devono essere trasmessi su ambiente di collaudo
        --prod:         conferire o aggiornare i dati su ambiente di produzione
        --dry-run       modalità simulazione attiva, mostra i parametri di lancio, le configurazioni impostate e il numero di record potenzialmente da processare
        -c:             utilità di aggiornamento coordinate
        -a:             utilità di aggiornamento accessi
    
    Funzionamento:
        1) è obbligatorio specificare uno solo dei parametri --test o --prod, in assenza di tale parametro lo script non verrà eseguito
    
        2) è obbligatorio specificare un solo parametro tra -c e -a, in assenza di tale parametro lo script non verrà eseguito
    
    
";

    public function __construct()
    {

    }

    /**
     * Print a video del messaggio di help
     * 
     * @return void 
     */
    public function logHelp()
    {
        print($this->help);
        flush();
    }

    /**
     * Scrive in console un messaggio di errore.
     * 
     * @param string    $text Messaggio da stampare
     * @param boolean   $head Includere anche l'header nel messaggio, default = true
     * 
     * @return string  
     */
    public function consoleError($text, $head = true)
    {
        $header = $head ? $this->getLogHeader('error') : $this->indent('error');
        $message =  $header." $text".PHP_EOL;
        print($message);

        return $message;
    }

    /**
     * Scrive in console un messaggio di log.
     * 
     * @param string    $text Messaggio da stampare
     * @param boolean   $head Includere anche l'header nel messaggio, default = true
     * 
     * @return string 
     */
    public function consoleLog($text, $head = true)
    {
        $header = $head ? $this->getLogHeader('log') : $this->indent('log');
        $message =  $header." $text".PHP_EOL;
        print($message);
        
        return $message;
    }

    /**
     * Stampa una riga vuota in console e aggiunge una nuova riga vuota al messaggio di log
     * 
     * @return void
     */
    public function newLine()
    {
        $message = $this->consoleLog(PHP_EOL,false);
        $this->logMessage .= $message;
    }

    /**
     * Scrive in console un messaggio di errore e aggiunge il messaggio al messaggio globale di log
     * 
     * @param string    $text Messaggio da stampare
     * @param boolean   $head Includere anche l'header nel messaggio, default = true
     * 
     * @return void 
     */
    public function printErrorLog($text, $head = true)
    {
        $message = $this->consoleError($text, $head);
        $this->logMessage .= $message;
    }

    /**
     * Scrive in console un messaggio di log e aggiunge il messaggio al messaggio globale di log
     * 
     * @param string    $text Messaggio da stampare
     * @param boolean   $head Includere anche l'header nel messaggio, default = true
     * 
     * @return void 
     */
    public function printProcessLog($text, $head = true)
    {
        $message = $this->consoleLog($text, $head);
        $this->logMessage .= $message;
    }

    /**
     * Restituisce il log generale del processo
     * 
     * @return string 
     */
    public function getLogMessage()
    {
        return $this->logMessage;
    }

    /**
     * Imposta il percorso assoluto al file di log
     * 
     * @param string $logFile Il nome del file di log per il processo corrente
     * 
     * @return void 
     */
    public function setLogFile($logFile)
    {
        $this->logFile = ANNCSU_LOGS_PATH.$this->getDate()." ".$logFile;
    }

    /**
     * Scrive il messaggio di log del processo su file
     * 
     * @return void 
     */
    public function writeMessageToFile()
    {
        if ($this->logMessage) {
            if (!is_dir(dirname($this->logFile))) {
                @mkdir(dirname($this->logFile), 0775, true);
            }
            $h = file_put_contents($this->logFile,$this->logMessage);
            if($h === false) {
                $this->consoleError("Impossibile scrivere il file di log ");
            }
        }
    }

    /**
     * Restituisce l'intestazione della riga di una riga del file di log
     * 
     * @param string    $type       tipo di log, log oppure error
     * @param boolean   $timestamp  se includere o meno un timestamp, default = true
     * 
     * @return string 
     */
    private function getLogHeader($type, $timestamp = true)
    {
        $header = '';
        switch($type){
            case "log":
                $header .= "LOG     ";
            break;
            case "error":
                $header .= "ERROR   ";
                break;
            default:
                break;
        }
        
        return ($timestamp ? $this->getDate() : '')."    ".$header;
    }

    /**
     * Restituisce il timestamp corrente nel formato anno-mese-giorno ore:minuti:secondi
     * 
     * @return string 
     */
    private function getDate()
    {
        return date("Y-m-d H:i:s");
    }

    /**
     * Genera un'indentazione in una riga del file di log
     * 
     * @param string $type Tipo di riga, log oppure error 
     * 
     * @return string 
     */
    private function indent($type)
    {
        $header = $this->getLogHeader($type,true);

        return str_repeat(" ",strlen($header));

    }
}
