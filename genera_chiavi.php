<?php
/**
 * genera_chiavi.php
 *
 * Entrypoint da riga di comando per la generazione del materiale crittografico
 * (chiave voucher + chiave ModI) di un nuovo servizio/ambiente PDND.
 *
 * Uso:
 *   php genera_chiavi.php --name=aggiornamento_test
 *   php genera_chiavi.php --name=coordinate_prod --key-size=4096
 *   php genera_chiavi.php --name=aggiornamento_test --no-modi-key   (solo chiave voucher)
 */

define('ANNCSU_CLASS_PATH', dirname(__FILE__).'/classes/');
define('ANNCSU_CERTS_PATH', dirname(__FILE__).'/certs/');

require_once(ANNCSU_CLASS_PATH.'key_generator.php');

$options = getopt("", array("name:", "key-size::", "no-modi-key"));

if (!array_key_exists('name', $options) || trim($options['name']) === '') {
    die("Specificare il nome del servizio/ambiente con --name=alias_ambiente (es. --name=aggiornamento_test)\n");
}

$name = $options['name'];
$keySize = array_key_exists('key-size', $options) ? (int) $options['key-size'] : 2048;
$generateModiKey = !array_key_exists('no-modi-key', $options);

echo "Generazione chiavi per '$name' (dimensione: $keySize bit, chiave ModI: " . ($generateModiKey ? "sì" : "no") . ")...\n";

try {
    $generatedFiles = ANNCSUKeyGenerator::generate($name, $keySize, $generateModiKey);

    echo "\nCompletato. File generati:\n";
    foreach ($generatedFiles as $file) {
        echo " - $file\n";
    }

    echo "\nProssimi passi:\n";
    echo " 1. Caricare " . ($generateModiKey ? "key.pub e modi_key.pub" : "key.pub") . " su piattaforma PDND (portachiavi del client e-service corrispondente)\n";
    echo " 2. Riportare i kid restituiti da PDND nei parametri key_id" . ($generateModiKey ? " e modi_key_id" : "") . " della sezione [anncsu_$name] in config/anncsu_client_config.ini\n";

} catch (Exception $e) {
    echo "\nERRORE: " . $e->getMessage() . "\n";
    exit(1);
}
