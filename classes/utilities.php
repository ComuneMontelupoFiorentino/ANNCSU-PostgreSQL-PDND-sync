<?php
/**
 * ANNCSUUtilities
 * 
 * Classe che espone metodi statici di utilità trasversale
 */
class ANNCSUUtilities {

    /**
     * Lettura configurazione da file ini sulla base dei parametri di lancio
     * 
     * @param string $utility       Tipo funzionalità da lanciare 
     * @param string $environment   Ambiente di lancio
     * 
     * @return array|null Array chiave valore di campi della configurazione
     */
    public static function readConfiguration($utility, $environment)
    {
        $confType = null;
        switch($utility){
            case 'c':
                $confType = 'coordinate';
            break;
            case 'a':
                $confType = 'aggiornamento';
            break;
            case 'o':
                $confType = 'odonimi';
            break;
            default:
            break;
        }

        if(!$confType || !$environment){
            return null;
        }

        $config_section = "anncsu_".$confType."_$environment";

        $config = parse_ini_file(ANNCSU_CONFIG_PATH.'anncsu_client_config.ini',true);

        if(!$config || !is_array($config) || !array_key_exists($config_section,$config)) return null;

        return $config[$config_section];
    }

    /**
     * Converte una data restituita da PostgreSQL (tipicamente in formato ISO 'YYYY-MM-DD')
     * nel formato richiesto dalle API ANNCSU ('dd/mm/yyyy'). Gestisce in modo tollerante
     * valori vuoti/null e formati già corretti o con orario incluso.
     *
     * @param string|null $value valore data così come restituito da pg_fetch_all
     * @return string data in formato dd/mm/yyyy, oppure stringa vuota se $value è vuoto/non valido
     */
    public static function formatDateForANNCSU($value)
    {
        if ($value === null || trim((string)$value) === '') {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('d/m/Y', $timestamp);
    }

    /**
     * Recupera un timestamp accurato interrogando l'header HTTP "Date" di un server esterno
     * raggiungibile, per compensare eventuali derive dell'orologio locale (es. NTP bloccato
     * da firewall aziendale, sistema senza permessi sudo per risincronizzarlo).
     * In caso di qualsiasi errore, ricade sull'orologio locale (time()).
     *
     * @param string $referenceUrl  URL (anche solo l'host) di un server esterno raggiungibile
     *                              e sincronizzato, usato solo per leggere l'header Date
     * @return int  timestamp UNIX
     */
    public static function getAccurateTimestamp($referenceUrl)
    {
        $host = parse_url($referenceUrl, PHP_URL_HOST);
        if (!$host) {
            $host = $referenceUrl;
        }
        if (!$host) {
            return time();
        }

        try {
            $ch = curl_init("https://$host/");
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $response = curl_exec($ch);
            $curlError = curl_errno($ch);
            curl_close($ch);

            if ($curlError || $response === false) {
                return time();
            }

            if (preg_match('/^Date:\s*(.+)$/mi', $response, $matches)) {
                $remoteTime = strtotime(trim($matches[1]));
                if ($remoteTime !== false) {
                    return $remoteTime;
                }
            }
        } catch (Exception $e) {
            // ricade silenziosamente sull'orologio locale
        }

        return time();
    }

    /**
     * Spezza un array in n parti uguali di dimensioni definite dal parametro $size. 
     * Restituisce poi le parti concatenate in un unico array.
     * 
     * @param array $arr    Un array generico
     * @param integer $size La dimensione dei blocchi
     * 
     * @return array        Array contenente i blocchi estratti dall'array di partenza
     */
    public static function chunkArray($arr, $size)
    {
        $chunckedArray = [];
        $length = count($arr);
        $iterations = ceil($length / $size);
        $it = 0;
        while ($it < $iterations){
            $sliced = array_slice($arr,$it*$size,$size);
            array_push($chunckedArray,$sliced);
            $it++;
        }
    
        return $chunckedArray;
    }
    /**
     * Utilità di firma del json web token 
     * @see https://datatracker.ietf.org/doc/html/rfc7519
     * 
     * @param array     $header     Header del json web token
     * @param array     $payload    Payload del json web token
     * @param string    $key        Bite array della chiave privata  
     * 
     * @return string json web token    
     */
    public static function signJWT($header,$payload,$key){
        $header_64 = trim(strtr(base64_encode(json_encode($header)),'+/','-_'),'=');
        $payload_64 = trim(strtr(base64_encode(json_encode($payload)),'+/','-_'),'=');

        // sign the data
        $pkeyid = openssl_pkey_get_private($key);
        openssl_sign("$header_64.$payload_64", $signature, $pkeyid,'sha256WithRSAEncryption');

        $signature = trim(strtr(base64_encode($signature),'+/','-_'),'=');

        $jwt = "$header_64.$payload_64.$signature";

        return $jwt;

    }
    /**
     * Restituisce un valore casuale tra i valori 1000000000000 e 9999999999999
     * 
     * @return integer 
     */
    public static function getDNonce()
    {
        return rand(1000000000000,9999999999999);
    }

    /**
     * Genera un uuidv4
     * @see https://datatracker.ietf.org/doc/html/rfc4122
     * 
     * @return string  
     */
    public static function generateUUIDv4() 
    {
        // Genera 16 byte (128 bit) di dati casuali o pseudo-casuali
        $data = openssl_random_pseudo_bytes(16);
    
        // Imposta la versione su 4 (0100 in binario)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    
        // Imposta i primi due bit del settimo byte su 10 per rispettare RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
        // Converte i byte in una stringa esadecimale
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Estra il valore dei campi univoci dell'accesso dall'array in input e li organizza per essere stampati a video
     * 
     * @param array     $block array che contiene gli oggetti da cui estrarre il campo univoco 
     * @param string    $field nome del campo da estrarre
     * @return string  
     */
    public static function getItemsOnProcess($block, $field)
    {
        $itemsOnProcess = array_map(function($a) use($field){
            return $a[$field];
        }, $block);
        return self::chunkProgrsForDisplay($itemsOnProcess);

    }

    /**
     * Organizza i valori dell'array in input per essere stampati a video
     * 
     * @param array $ar array di voalori
     * 
     * @return string  
     */
    public static function chunkProgrsForDisplay($ar)
    {
        $chunk = self::chunkArray($ar, 10);
        $progrs = "";
        $totalChunk = count($chunk);
        $it = 0;
        foreach ($chunk as $progList) {
            $it++;
            $progrs .= implode(",",$progList).($it == $totalChunk ? '' :PHP_EOL);
        }

        return $progrs;
    }
}
