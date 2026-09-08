<?php

/**
 * entrypoint per richiamare le funzionalità di aggiornamento accessi su piataforma PDND
 * 
 */
// global paths

// percorso globale del path della cartella delle classi
define( 'ANNCSU_CLASS_PATH', dirname(__FILE__).'/classes/');

// percorso globale del path della cartella certificati
define( 'ANNCSU_CERTS_PATH', dirname(__FILE__).'/certs/');

// percorso globale del path della cartella contenente il file di configurazione ini
define( 'ANNCSU_CONFIG_PATH', dirname(__FILE__).'/config/');

// percorso globale del path della cartella contenente i file di log
define( 'ANNCSU_LOGS_PATH', dirname(__FILE__).'/logs/');

require_once(ANNCSU_CLASS_PATH.'utilities.php');
require_once(ANNCSU_CLASS_PATH.'process_log.php');
require_once(ANNCSU_CLASS_PATH.'coordinate.php');
require_once(ANNCSU_CLASS_PATH.'aggiornamento.php');
require_once(ANNCSU_CLASS_PATH.'odonimi.php');

// recupero parametri di lancio dello script
$options = getopt(
    "hcsao",
    array("test","prod","dry-run")
);

// istanza del logger
$log = new ProcessLog();

if(array_key_exists('h',$options)){
    $log->logHelp();
    die();
}

// parsing opzioni di ambiente di esecuzione, test o produzione
if(!array_key_exists('test',$options) && !array_key_exists('prod',$options)){
    $log->consoleError("Specificare l'ambiente di esecuzione dello script, --test o --prod");
    die();
} else if (array_key_exists('test',$options) && array_key_exists('prod',$options)) {
    $log->consoleError("Impossibile determinare l'ambiente di esecuzione dello script, specificare un solo parametro tra --test o --prod");
    die();
}

$dryRun = array_key_exists('dry-run', $options);

$environment = array_key_exists('test',$options) ? 'test' : 'prod';

// definizione dell'utilità da lanciare, conferimento o aggiornamento accessi
$utilityMode = ['c','a','o'];
$optionsKey = array_keys($options);
$filteredOptions = array_filter($utilityMode, function($u) use($optionsKey){
    return in_array($u,$optionsKey);
});

if(count($filteredOptions) != 1){
    $log->consoleError("Specificare univocamente una funzione tra -c, -a o -o: -c = conferimento coordinate, -a = aggiornamento civici, -o = gestione odonimi");
    die();
}

$utility = array_values($filteredOptions)[0];

// caricamento della configurazione secondo i parametri richiesti
$client_config = ANNCSUUtilities::readConfiguration($utility,$environment);

if(!$client_config){
    $log->consoleError("Impossibile caricare la configurazione per il servizio richiesto, verificare la configurazione del client");
    die();
}

// chiamata al servizio richiesto
$service = null;
switch($utility){
    case 'c':
        $service='ANNCSUCoordinates';
    break;
    case 'a':
        $service='ANNCSUAggiornamento';
    break;
    case 'o':
        $service='ANNCSUOdonimi';
    break;
    default:
    break;
}

if(!$service || !class_exists($service) || !method_exists($service,'callService')){
    $log->consoleError("Impossibile definire il servizio");
    die();
}
try {
    $service_instance = new $service($client_config, $options, $environment, $log, $dryRun);
    $service_instance->callService();

} catch(Exception $e){
    $log->printErrorLog($e->getMessage());
}

// scrittura del file di log a termine del processo
$log->writeMessageToFile();

die();
