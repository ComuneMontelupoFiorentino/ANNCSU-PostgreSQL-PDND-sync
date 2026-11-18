<?php
/**
 * test_voucher.php
 * Script isolato per testare SOLO il recupero del voucher PDND (nessuna chiamata all'e-service,
 * nessuna scrittura/lettura da ANNCSU). Da lanciare dalla cartella principale:
 *
 *   php test_voucher.php --test -a
 *   php test_voucher.php --test -c
 *
 * Usa la stessa configurazione (ini + certs) del client reale.
 */

define('ANNCSU_CLASS_PATH', dirname(__FILE__).'/classes/');
define('ANNCSU_CERTS_PATH', dirname(__FILE__).'/certs/');
define('ANNCSU_CONFIG_PATH', dirname(__FILE__).'/config/');
define('ANNCSU_LOGS_PATH', dirname(__FILE__).'/logs/');

require_once(ANNCSU_CLASS_PATH.'utilities.php');
require_once(ANNCSU_CLASS_PATH.'process_log.php');
require_once(ANNCSU_CLASS_PATH.'coordinate.php');
require_once(ANNCSU_CLASS_PATH.'aggiornamento.php');

$options = getopt("hcsa", array("test","prod"));

$environment = array_key_exists('test',$options) ? 'test' : 'prod';
$utility = array_key_exists('c',$options) ? 'c' : 'a';

$log = new ProcessLog();

$client_config = ANNCSUUtilities::readConfiguration($utility,$environment);
if(!$client_config){
    die("Impossibile caricare la configurazione\n");
}

$service_class = $utility === 'c' ? 'ANNCSUCoordinates' : 'ANNCSUAggiornamento';

// Istanzia il servizio (fa comunque i controlli su config/certs/db, ma in dry-run non tocca ANNCSU)
$service = new $service_class($client_config, $options, $environment, $log, true);

// Usiamo reflection per invocare getPDNDDigestVoucher(), che è public nella classe genitore
echo "\n=== RECUPERO VOUCHER PDND ($environment) ===\n";
$voucher = $service->getPDNDDigestVoucher();

if ($voucher && $voucher['token']) {
    echo "OK - Voucher ottenuto correttamente.\n\n";

    // decodifica (senza verificare firma) header e payload del token per un check visivo
    $parts = explode('.', $voucher['token']);
    if (count($parts) === 3) {
        $header  = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        echo "Header voucher: ".json_encode($header)."\n";
        echo "Payload voucher (estratto): \n";
        foreach (['iss','aud','client_id','exp','iat','organizationId'] as $k) {
            if (isset($payload[$k])) echo "  $k: ".$payload[$k]."\n";
        }
    }
} else {
    echo "FALLITO - Nessun token ottenuto. Controlla i messaggi di errore sopra (printErrorLog) e il file di log in logs/.\n";
}