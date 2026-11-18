<?php
/**
 * test_single_record.php
 *
 * Esegue UNA SOLA chiamata reale verso l'e-service (produzione o test, a seconda di --prod/--test),
 * usando UN SOLO record estratto da tabella_operazioni.
 *
 * NON aggiorna il DB al termine (né allineato=true, né errori), così puoi rilanciare lo stesso
 * record quante volte vuoi finché non funziona, e poi lasciare che sia lo script ufficiale
 * anncsu.php a processarlo/marcarlo definitivamente.
 *
 * ATTENZIONE: se il record è valido e la chiamata va a buon fine, il civico verrà
 * davvero inserito/aggiornato/cancellato sull'ambiente ANNCSU puntato dalla configurazione
 * (produzione o collaudo, in base a quale sezione ini viene usata).
 *
 * Uso:
 *   php test_single_record.php --test -a --op=I     (default op=I se omesso)
 *   php test_single_record.php --prod -a --op=R
 *   php test_single_record.php --prod -a --op=S
 */

define('ANNCSU_CLASS_PATH', dirname(__FILE__).'/classes/');
define('ANNCSU_CERTS_PATH', dirname(__FILE__).'/certs/');
define('ANNCSU_CONFIG_PATH', dirname(__FILE__).'/config/');
define('ANNCSU_LOGS_PATH', dirname(__FILE__).'/logs/');

require_once(ANNCSU_CLASS_PATH.'utilities.php');
require_once(ANNCSU_CLASS_PATH.'process_log.php');
require_once(ANNCSU_CLASS_PATH.'coordinate.php');
require_once(ANNCSU_CLASS_PATH.'aggiornamento.php');

$options = getopt("hcsa", array("test","prod","op::"));

$environment = array_key_exists('test',$options) ? 'test' : 'prod';
$operation = $options['op'] ?? 'I';

if (!in_array($operation, ['I','R','S'], true)) {
    die("Operazione non valida, usa --op=I, --op=R o --op=S\n");
}

$log = new ProcessLog();
$client_config = ANNCSUUtilities::readConfiguration('a', $environment);
if(!$client_config){
    die("Impossibile caricare la configurazione\n");
}

// dryRun=true solo per evitare che il costruttore stampi/logghi cose superflue; non incide
// sul fatto che qui chiamiamo l'API a mano, quindi la richiesta HTTP verrà comunque inviata.
$service = new ANNCSUAggiornamento($client_config, $options, $environment, $log, true);

$reflClass = new ReflectionClass($service);

// leggiamo le property protette che ci servono per costruire la query manuale
$get = function($name) use ($reflClass, $service) {
    $p = $reflClass->getProperty($name);
    $p->setAccessible(true);
    return $p->getValue($service);
};

$schema = $get('schema');
$tabella_operazioni = $get('tabella_operazioni');
$tipo_operazione = $get('tipo_operazione');
$allineato_tabella_operazioni = $get('allineato_tabella_operazioni');

// connessione db (metodo pubblico ereditato)
$conn = $service->getPgConnection();

$query = "SELECT * FROM $schema.$tabella_operazioni WHERE $tipo_operazione = '".pg_escape_string($conn,$operation)."' AND $allineato_tabella_operazioni IS NOT TRUE ORDER BY id ASC LIMIT 1";

echo "Query: $query\n";
$result = pg_query($conn, $query);
if (!$result || pg_num_rows($result) === 0) {
    die("Nessun record trovato per operazione '$operation'.\n");
}
$access = pg_fetch_assoc($result);

echo "\n=== RECORD SELEZIONATO ===\n";
print_r($access);

// costruzione del body tramite il metodo protetto della classe reale (stessa identica logica dello script ufficiale)
$prepareMethod = $reflClass->getMethod('prepareUpdateAccessRequest');
$prepareMethod->setAccessible(true);
$body = $prepareMethod->invoke($service, $access);

if (!$body) {
    die("Impossibile costruire il body della richiesta per questo record.\n");
}

echo "\n=== BODY DELLA RICHIESTA ===\n";
echo json_encode($body, JSON_PRETTY_PRINT)."\n";

echo "\n=== RECUPERO VOUCHER ===\n";
$voucher = $service->getPDNDDigestVoucher();
if (!$voucher || !$voucher['token']) {
    die("Impossibile ottenere il voucher.\n");
}
echo "Voucher OK.\n";

$prepared = $service->getPDNDDigestBody($body, $voucher['token'], $voucher['audit_encode']);

// leggiamo service_url per costruire l'endpoint /accessi
$service_url = $get('service_url');
$endpoint = "$service_url/accessi";

echo "\n=== CHIAMATA REALE ALL'E-SERVICE ===\n";
echo "Endpoint: $endpoint\n";

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $prepared['headers']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($prepared['body']));
curl_setopt($ch, CURLINFO_HEADER_OUT, true);

$response = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "\nHTTP STATUS: $httpStatus\n";
if ($err) {
    echo "cURL ERROR: $err\n";
}
echo "RISPOSTA GREZZA:\n$response\n";

echo "\n(Nessuna scrittura è stata fatta sul DB PostgreSQL: se la chiamata è andata a buon fine, ricordati che comunque il civico è stato scritto su ANNCSU, ma il flag 'allineato' NON è stato aggiornato qui — lo farà la run ufficiale, oppure aggiornalo a mano.)\n";