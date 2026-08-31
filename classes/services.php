<?php
/**
 * ANNCSUGenericService
 * 
 * Classe generica che si occupa di svolgere le operazioni comuni a tutti i servizi
 */
class ANNCSUGenericService {
    /**
     * Configurazione del client del servizo 
     * @var array 
     */
    protected $config;

    /**
     * Ulteriori opzioni di lancio del servizio
     * @var array 
     */
    protected $options;

    /**
     * Ambiente di lancio
     * @var string 
     */
    protected $environment;

    /**
     * Modalit� simulazione attiva
     * @var boolean 
     */
    protected $dryRun;

    /**
     * Tipo di servizio da lanciare
     * @var string 
     */
    protected $serviceType;

    /**
     * Costante rappresentativa del nome della file contenente la chiave privata per la firma dei jwt
     * @var string 
     */
    protected $privateKeyFileName = 'key.priv';
    
    /**
     * Percorso assoluto del file della chiave privata
     * @var string 
     */
    protected $privateKeyPath;

    /**
     * Costante rappresentativa del nome della file contenente la chiave privata ModI
     * (dedicata alla firma di Agid-JWT-Signature e Agid-JWT-TrackingEvidence, distinta
     * dalla chiave usata per il client_assertion/voucher)
     * @var string 
     */
    protected $modiPrivateKeyFileName = 'modi_key.priv';

    /**
     * Percorso assoluto del file della chiave privata ModI
     * @var string 
     */
    protected $modiKeyPath;

    /**
     * contenuto della chiave privata ModI per la firma di Agid-JWT-Signature/TrackingEvidence
     * @var string 
     */
    protected $modi_pKeyPem;

    /**
     * kid della chiave pubblica ModI depositata su PDND (distinto dal kid del voucher)
     * @var string 
     */
    protected $modi_key_id;

    /**
     * Costante rappresentativa del nome base del servizio presente nel file pg_service.conf per la connessione al db Postgis
     * @var string 
     */ 
    protected $baseServiceName = "pg";

    /**
     * Nome completo del servizio di connessione a db composta dalla concatenzaione $baseServiceName e $environment
     * @var string 
     */ 
    protected $serviceName;

    /**
     * Istanza del logger globale
     * @var {ProcessLog}
     */
    protected $logInstance;

    /**
     * baseURI del servizio di aggiornamento coordinate
     * @var string 
     */
    protected $service_url;

    /**
     * valore di aud per il servzio di autenticazione
     * @var string 
     */
    protected $aud;

    /**
     * identificativo chiave pubblica, si recupera da piattaforma PagoPA-Interoperabilit�
     * @var string 
     */
    protected $key_id;

    /**
     *  identificativo univoco del soggetto che inoltra la richiesta, si recupera da piattaforma PagoPA-Interoperabilit�
     * @var string
     */
    protected $iss;

    /**
     * parametro univoco del soggetto che inoltra la richiesta, si recupera da piattaforma PagoPA-Interoperabilit�
     * @var string
     */
    protected $sub;

    /**
     * @var string identificativo univoco della finalit�, si recupera da piattaforma PagoPA-Interoperabilit�
     */
    protected $purpose_id;

    /**
     * identificativo univoco del client, spesso coincide con issuer, si recupera da piattaforma PagoPA-Interoperabilit�
     * @var string 
     */
    protected $client_id;

    /**
     * identificativo univoco dell'utente interno al dominio del fruitore che ha determinato l'esigenza della request
     * @var string
     */
    protected $user_id;

    /**
     * identificativo univoco della postazione interna al dominio del fruitore da cui � avviata l'esigenza della request
     * @var string
     */
    protected $user_location;

    /**
     * livello di sicurezza o di garanzia adottato nel processo di autenticazione informatica nel dominio del fruitore
     * @var string 
     */
    protected $LoA;

    /**
     * percorso della chiave privata per la firma dei jwt
     * @var string 
     */
    protected $pKeyUrl;

    /**
     * contenuto della chiave privata per la firma dei jwt
     * @var string 
     */
    protected $pKeyPem;

    /**
     * url del il servizio di autenticazione
     * @var string 
     */
    protected $auth_url;

    /**
     * timestamp in secondi
     * @var int 
     */
    protected $issued;

    /**
     * durata in secondi dell'assertion
     * @var int 
     */
    protected $delta;

    /**
     * Codice del comune
     * @var string
     */
    protected $codcom;

    /**
     * Iniizializza la classe generale con i parametri passati dallo script 
     *
     * @param array     $config         Configurazione del client per il servizio richiesto
     * @param array     $options        Opzioni di lancio aggiuntive
     * @param string    $environment    Ambiente di lancio
     * @param string    $serviceType    Tipo di servizio richiesto
     * @param boolean   $dryRun         Modalit� dry run attiva
     */
    public function __construct($config, $options, $environment, $serviceType, $dryRun)
    {
        if(!$environment || ($environment !== 'test' && $environment !== 'prod')) throw new Exception("Impossibile definire l'ambiente di esecuzione dello script");
        if(!$serviceType) throw new Exception("Impossibile definire il tipo di servizio");
        if(!$config || !is_array($config) || count($config) == 0) throw new Exception("Configurazione non valida");
        $this->config = $config;
        $this->options = $options;
        $this->environment = $environment;
        $this->serviceType = $serviceType;
        $this->dryRun = $dryRun;
    }

    /**
     * Controlla la validit� della configurazione per il servizio richiesto. In caso di invalidit� interrompe l'esecuzione
     *
     * @param array     $mandatoryKeys  Array con le chiavi delle propriet� richieste per il servizio specificato
     * 
     * @return void 
     * @throws Exception
     */
    public function initConfiguration($mandatoryKeys){
        $confKeys = array_keys($this->config);
        foreach ($mandatoryKeys as $mKey) {
            if(!in_array($mKey,$confKeys)) throw new Exception("voce $mKey mancante, controllare la configurazione del client");
        }

        // usa l'ora esatta letta dal server PDND (header HTTP Date) invece dell'orologio locale,
        // per non dipendere dalla sincronizzazione NTP del sistema (spesso bloccata da firewall)
        $this->issued = ANNCSUUtilities::getAccurateTimestamp($this->auth_url);
        $this->delta = 300;
    }

    /**
     * Controlla l'esistenza della chiave privata e imposta il percorso al file corrispondente. Se non trovata, interrompe l'esecuzione
     * 
     * @return void 
     * @throws Exception
     */
    public function setPrivateKey()
    {
        $privateKeyPath = ANNCSU_CERTS_PATH.$this->serviceType."_".$this->environment."/".$this->privateKeyFileName;
        if (!file_exists($privateKeyPath)) {
            throw new Exception("Percorso chiave privata non trovato. $privateKeyPath");
        }

        $this->privateKeyPath = $privateKeyPath;

        // impostazione chiave privata
        $key = file_get_contents($this->privateKeyPath);
        if($key){
            $this->pKeyPem = $key;
        } else {
            throw new Exception("Impossibile recuperare la chiave privata per il servizio di autenticazione");
        }
    }

    /**
     * Controlla l'esistenza della chiave privata ModI (dedicata alla firma di Agid-JWT-Signature
     * e Agid-JWT-TrackingEvidence) e imposta il percorso al file corrispondente.
     * Se non trovata, interrompe l'esecuzione.
     * 
     * @return void 
     * @throws Exception
     */
    public function setModiPrivateKey()
    {
        $modiKeyPath = ANNCSU_CERTS_PATH.$this->serviceType."_".$this->environment."/".$this->modiPrivateKeyFileName;
        if (!file_exists($modiKeyPath)) {
            throw new Exception("Percorso chiave privata ModI non trovato. $modiKeyPath");
        }

        $this->modiKeyPath = $modiKeyPath;

        $key = file_get_contents($this->modiKeyPath);
        if($key){
            $this->modi_pKeyPem = $key;
        } else {
            throw new Exception("Impossibile recuperare la chiave privata ModI per la firma delle richieste");
        }
    }

    /**
     * Controlla l'esistenza del file  pg_service.conf nella cartella della configurazione e
     * l'esistenza del servizio per la funzionalit� di esecuzione richiesta.
     * In caso di successo imposta la variabile d'ambiente con il percorso del file, altrimenti interrompe l'esecuzione
     * 
     * @return void 
     * @throws Exception
     */
    public function checkPostgreServiceFile()
    {
        $pathToPgService = ANNCSU_CONFIG_PATH.'pg_service.conf';
        if(!file_exists($pathToPgService)) {
            throw new Exception('Impossibile recuperare i parametri di connessione a DB, file pg_service.conf mancante');
        }

        $handleFile = fopen($pathToPgService,'r');
        if(!$handleFile) throw new Exception("Impossibile leggere il file di servizio per la connessione a DB");
        $serviceName = $this->baseServiceName."_".$this->environment;
        while (($line = fgets($handleFile)) !== false) {
            if(preg_match('/\['.$serviceName.'\]/',$line)) {
                $this->serviceName = $serviceName;
                break;
            }
        }
        fclose($handleFile);
        if(!$this->serviceName) throw new Exception('Impossibile recuperare i parametri di connessione a DB, servizio '.$serviceName.' non specificato');

        // assegna la variabile d'ambiente per l'esecuzione corrente
        putenv("PGSERVICEFILE=$pathToPgService");
    }

    /**
     * Restituisce l'oggetto di connessione al db Postgis. Lancia un errore in caso non sia possibile connettersi
     * 
     * @return PGConn
     * @throws Exception
     */
    public function getPgConnection()
    {
        try {
            $conn = pg_connect("service=".$this->serviceName);
            if(!$conn){
                throw new Exception("errore in connessione");
            }
            return $conn;
        } catch(Exception $e){
            throw new Exception('Impossibile stabilire una connessione a DB:'.$e->getMessage());
        }
    }

    /**
     * Imposta l'istanza del logger a livello di classe
     * 
     * @return void
     */
    public function setLogInstance($instance)
    {
        $this->logInstance = $instance;
    }

    /**
     * Restituisce un voucher PDND assieme all'udit_encode
     * 
     * @return array
     */
    public function getPDNDDigestVoucher()
    {
        $voucher = array(
            "token" => "",
            "audit_encode" => ""
        );

        // Agid-JWT-TrackingEvidence (pattern AUDIT_REST_02): firmato con la chiave ModI,
        // DISTINTA da quella usata per il client_assertion/voucher
        $audit_header = array(
            "kid" => $this->modi_key_id,
            "alg" => "RS256",
            "typ" => "JWT"
        );

        $audit_payload = array(
            "userID" => $this->user_id,
            "userLocation" => $this->user_location,
            "LoA" => $this->LoA,
            "iss" => $this->iss,
            "sub" => $this->sub,
            "aud" => $this->service_url,
            "purposeId" => $this->purpose_id,
            "dnonce" => ANNCSUUtilities::getDNonce(),
            "jti" => ANNCSUUtilities::generateUUIDv4(),
            "iat" => $this->issued,
            "nbf" => $this->issued,
            "exp" => $this->issued + $this->delta
        );

        $audit_encode = ANNCSUUtilities::signJWT($audit_header,$audit_payload, $this->modi_pKeyPem);

        $hashed_assertion = openssl_digest($audit_encode, 'sha256');

        $digest_payload = array(
            "iss" => $this->iss,
            "sub"=> $this->sub,
            "aud" => $this->aud,
            "purposeId" => $this->purpose_id,
            "jti" => ANNCSUUtilities::generateUUIDv4(),
            "iat" => $this->issued,
            "exp" => $this->issued + $this->delta,
            "digest" => array(
                "alg"=>"SHA256",
                "value"=> $hashed_assertion
            )
        );

        $client_assertion = ANNCSUUtilities::signJWT(array(
            "kid" => $this->key_id,
            "alg" => "RS256",
            "typ" => "JWT"
        ),$digest_payload, $this->pKeyPem);


        $payload = array(
            "client_id" => $this->client_id,
            "client_assertion" => $client_assertion,
            "client_assertion_type" => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            "grant_type"=>'client_credentials'
        );

        $ch = curl_init($this->auth_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('content-type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
        }
        
        if(isset($error_msg)){
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->logInstance->printErrorLog($http_status.PHP_EOL.$error_msg.PHP_EOL);
            curl_close($ch);
        } else {
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if($http_status >= 300) {
                // error
                $this->logInstance->printErrorLog("Errore in fase di recupero voucher $http_status");
                curl_close($ch);
            } else {
                try {
                    curl_close($ch);
                    $respJSON = json_decode($response,true);
                    if(
                        !is_array($respJSON) || 
                        !array_key_exists('access_token',$respJSON) ||
                        !$respJSON['access_token']
                    ){
                        throw new Exception('Impossibile recuperare il voucher = '.$response);
                    }
                    $voucher['token'] = $respJSON['access_token'];
                    $voucher['audit_encode'] = $audit_encode;

                } catch(Exception $ex){
                    $this->logInstance->printErrorLog($ex);
                }
            }
        }

        return $voucher;
    }

    /**
     * Esegue chiamate in parallelo per il blocco di progressivi specificato
     * 
     * @param array         $block                      blocco di progressivi da processare
     * @param array         $voucher                    voucer pdnd
     * @param string        $serviceType                tipo di operazione da eseguire, C = coordinate, I = creazione nuovo civico, R = aggiornamento civico esistente, S = cancellazione civico esistente
     * @param string        $body_constructor           funzione che costruisce la richiesta
     * @param string        $access_unique_id_field     campo del record accesso che identifica il valore univoco del record
     * @return array
     */
    public function execMultiPDNDDigestRequest($block, $voucher, $serviceType, $body_contructor, $access_unique_id_field)
    {

        // init multi curl
        $curlHandles = [];
        $multiHandle = curl_multi_init();
        $shareHandle = curl_share_init();

        $token = $voucher['token'];
        $audit_encode = $voucher['audit_encode'];

        // per ragioni di performance, si codivide lo stesso handshake tra tutte le chiamate
        curl_share_setopt($shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        curl_share_setopt($shareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);

        foreach ($block as $access){

            $uniq_id = strval($access[$access_unique_id_field]);
            $accessObjects[$uniq_id] = array(
                'error' => '',
                'access' => $access,
                'updateResponse'=> '',
                'success' => null,
                'operation' => $serviceType
            );

            $request_body = call_user_func($body_contructor,$access);
            if(!$request_body) {
                $this->setAccessProgrResponse($accessObjects[$uniq_id],null, false,"Impossibile definire l'operazione da eseguire sull'accesso");
                continue;
            } 

            $preparedRequest = $this->getPDNDDigestBody(
                    $request_body, 
                    $token, 
                    $audit_encode, 
                );

            $curlHandle = curl_init($this->getServiceUrl($serviceType));
            curl_setopt($curlHandle, CURLOPT_POST, 1);
            curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true );

            curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $preparedRequest['headers']);

            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($preparedRequest['body']));
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curlHandle, CURLINFO_HEADER_OUT, true); 
            curl_setopt($curlHandle, CURLOPT_SHARE, $shareHandle);
            
            curl_multi_add_handle($multiHandle, $curlHandle);

            $curlHandles[$uniq_id] = $curlHandle;

        }

        // esecuzione multi curl
        do {
            $mrc = curl_multi_exec($multiHandle, $pendingRequests);
        } while ($pendingRequests > 0);
        
        // risposte delle varie chiamate
        foreach ($curlHandles as $id => $handle) {
            $http_status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $content = curl_multi_getcontent($handle);

            if (curl_errno($handle)) {
                $error_msg = curl_error($handle);;
            }
            if(isset($error_msg)){
                $accessObjects[$id] = $this->setAccessProgrResponse($accessObjects[$id],$content, false, $error_msg);
            } else {
                if($http_status != 200){
                    // errore
                    try {
                        // parse messaggio di errore
                        $msg = $content;
                        $errorObj = json_decode($content,true);
                        if($errorObj) {
                            if(array_key_exists('messaggio',$errorObj)){
                                $msg = $errorObj['messaggio'];
                            } else if (array_key_exists('detail',$errorObj)){
                                $msg = $errorObj['detail'];
                            }
                        }
                        $accessObjects[$id] = $this->setAccessProgrResponse($accessObjects[$id], $errorObj ? $errorObj : $content, false,$msg);
                    } catch(Exception $e){
                        $accessObjects[$id] = $this->setAccessProgrResponse($accessObjects[$id], $content, false, $content);
                    }
                } else {
                    try {
                        $respJSON = json_decode($content,true);
                        $this->setAccessProgrResponse($accessObjects[$id],$respJSON ? $respJSON : $content, true,'');
                    } catch(Exception $ex){
                        // log errore
                        $accessObjects[$id] = $this->setAccessProgrResponse($accessObjects[$id],$content, true,'');
                    }
                }
            }
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        return $accessObjects;
    }

    /**
     * Ritorna l'URI del servizio PDND da chiamare
     * 
     * @param string    $serviceType tipo di servizio
     * @return string
     */
    private function getServiceUrl($serviceType)
    {
        return match($serviceType){
            'C' => "$this->service_url/gestionecoordinate",
            'A' => "$this->service_url/accessi",
        };
    }

    /**
     * Popola l'oggetto di riposta del servizio PDND per ciascun accesso processato
     * 
     * @param array     $access     oggetto di riposta del servizio PDND per ciascun accesso processato
     * @param mixed     $content    contenuto della rispota PDND
     * @param boolean   $success    servizio andato a buon fine oppure no
     * @param string    $error      eventuale messaggio di errore
     *  
     * @return array  
     */
    private function setAccessProgrResponse(&$access, $content, $success, $error)
    {
        $access['success'] = $success;
        $access['error'] = $error;
        $access['updateResponse'] = $content;

        return $access;
    }
    /**
     * Costruisce gli header e il corpo della chiamata ad un servizio PDND con specifica Digest
     * @param mixed $body                               corpo della richiesta
     * @param mixed $token                              jwt restituito dal servizio di autenticazione
     * @param mixed $audit_encode                       stringa audit_encode
     * @return array{body: mixed, headers: string[]}
     */
    public function getPDNDDigestBody($body, $token, $audit_encode)
    {
        $coordinate_body_digest = base64_encode(hash("sha256", json_encode($body), true));

        $digest = 'SHA-256='. $coordinate_body_digest;

        $payload_update_coord = array(
            "iss" => $this->iss,
            "aud" => $this->service_url,
            "purposeId" => $this->purpose_id,
            "sub" => $this->sub,
            "jti" => ANNCSUUtilities::generateUUIDv4(),
            "iat" => $this-> issued,
            "nbf" => $this->issued,
            "exp" => $this->issued + $this->delta,
            "signed_headers" => array(
                array("digest" => $digest),
                array("content-type" => 'application/json')
            )
        );
        // Agid-JWT-Signature (pattern INTEGRITY_REST_02): firmato con la chiave ModI,
        // DISTINTA da quella usata per il client_assertion/voucher
        $signature = ANNCSUUtilities::signJWT(array(
            "kid" => $this->modi_key_id,
            "alg" => "RS256",
            "typ" => "JWT"
        ),$payload_update_coord, $this->modi_pKeyPem);

        $headers = array(
            'Content-Type: application/json',
            'Content-Encoding: identity',
            'Digest: '.$digest,
            'Authorization: Bearer '.$token,
            'Agid-JWT-TrackingEvidence: '.$audit_encode,
            'Agid-JWT-Signature: '.$signature,
        );

        return array(
            "body" => $body,
            "headers" => $headers
        );
    }

    public function printProcessParameters(){
        $this->logInstance->printProcessLog("MODALIT� SIMULAZIONE ATTIVA:");
        $this->logInstance->printProcessLog("Ambiente di lancio:........$this->environment",false);
        $this->logInstance->printProcessLog("Servizio:..................$this->serviceType",false);
        $this->logInstance->printProcessLog("Parametri configurazione:".PHP_EOL,false);
        foreach($this->config as $kName => $kValue) {
            $this->logInstance->printProcessLog("$kName:................$kValue",false);
        }
        $this->logInstance->printProcessLog(PHP_EOL,false);
        $this->logInstance->printProcessLog("Connessione db:............$this->serviceName",false);
    }
}
