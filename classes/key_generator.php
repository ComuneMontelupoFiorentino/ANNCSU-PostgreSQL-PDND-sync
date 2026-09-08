<?php

/**
 * Classe di utilità per la generazione del materiale crittografico (chiavi RSA)
 * necessario per l'autenticazione verso PDND Interoperabilità.
 *
 * Per un dato "nome" di servizio (nella forma convenzionale {alias}_{ambiente},
 * es. "aggiornamento_test", "coordinate_prod"), crea la sottocartella corrispondente
 * dentro certs/ e vi genera:
 *
 * - key.pem / key.priv / key.pub                → chiave "voucher" (firma il client_assertion)
 * - modi_key.pem / modi_key.priv / modi_key.pub  → chiave "ModI" (firma Agid-JWT-Signature
 *                                                   e Agid-JWT-TrackingEvidence)
 *
 * Non sovrascrive MAI una cartella già esistente: se il servizio è già stato configurato
 * in precedenza, la generazione viene rifiutata, per evitare di invalidare involontariamente
 * chiavi già caricate ed in uso su PDND.
 *
 * Le chiavi generate vengono verificate (validità RSA, dimensione minima, corrispondenza
 * pubblica/privata) prima di considerare la generazione conclusa; in caso di errore in
 * qualsiasi fase, la cartella creata viene rimossa interamente (nessun materiale parziale
 * o incoerente viene lasciato sul filesystem).
 */
class ANNCSUKeyGenerator
{
    /**
     * Dimensione minima accettata per le chiavi RSA generate, in bit.
     * Coerente con quanto richiesto dalla documentazione tecnica PDND/ANNCSU.
     * @var int
     */
    const MIN_KEY_SIZE = 2048;

    /**
     * Nome (o percorso) del binario openssl da invocare
     * @var string
     */
    private static $opensslBin = 'openssl';

    /**
     * Genera il materiale crittografico completo per un nuovo servizio/ambiente,
     * dentro certs/{name}/
     *
     * @param string $name              Nome della cartella da creare, forma consigliata
     *                                  {alias}_{ambiente} (es. "aggiornamento_test").
     *                                  Ammessi solo lettere, numeri, underscore e trattini.
     * @param int    $keySize           Dimensione della chiave RSA in bit
     *                                  (default e minimo consentito: 2048)
     * @param bool   $generateModiKey   Se generare anche la seconda chiave ModI, necessaria
     *                                  per i servizi ANNCSU che effettuano scritture
     *                                  (aggiornamento accessi, aggiornamento coordinate).
     *                                  Default true, consigliato lasciarlo tale.
     *
     * @return array Percorsi assoluti di tutti i file di chiave generati
     * @throws Exception se il nome non è valido, se la cartella di destinazione esiste
     *                   già, o se la generazione/verifica di conformità fallisce
     */
    public static function generate($name, $keySize = self::MIN_KEY_SIZE, $generateModiKey = true)
    {
        self::validateName($name);
        self::validateKeySize($keySize);
        self::checkOpensslAvailable();

        if (!defined('ANNCSU_CERTS_PATH')) {
            throw new Exception("Costante ANNCSU_CERTS_PATH non definita.");
        }

        $targetDir = rtrim(ANNCSU_CERTS_PATH, '/').'/'.$name;

        if (file_exists($targetDir)) {
            throw new Exception(
                "La cartella '$targetDir' esiste già: per evitare di sovrascrivere chiavi " .
                "eventualmente già caricate e in uso su PDND, la generazione è stata interrotta. " .
                "Se si desidera davvero rigenerare le chiavi per questo servizio, rimuovere " .
                "manualmente la cartella (dopo essersi assicurati che le chiavi non siano più " .
                "necessarie) e rilanciare la generazione."
            );
        }

        if (!mkdir($targetDir, 0700, true)) {
            throw new Exception("Impossibile creare la cartella '$targetDir'. Verificare i permessi della cartella certs/.");
        }

        $generated = array();

        try {
            $generated = array_merge($generated, self::generateKeyTriplet($targetDir, 'key', $keySize));

            if ($generateModiKey) {
                $generated = array_merge($generated, self::generateKeyTriplet($targetDir, 'modi_key', $keySize));
            }
        } catch (Exception $e) {
            // rollback completo: non si vuole lasciare sul filesystem materiale crittografico
            // parziale o che ha fallito la verifica di conformità
            self::removeDirectoryRecursive($targetDir);
            throw $e;
        }

        return $generated;
    }

    /**
     * Genera una singola tripletta di chiavi (basename.pem / .priv / .pub) e ne verifica
     * la conformità ai requisiti PDND prima di restituirla.
     *
     * @param string $targetDir  Cartella di destinazione, già esistente
     * @param string $basename   Nome base dei 3 file ("key" oppure "modi_key")
     * @param int    $keySize    Dimensione della chiave RSA in bit
     *
     * @return array Percorsi assoluti dei 3 file generati
     * @throws Exception in caso di errore nella generazione o nella verifica di conformità
     */
    private static function generateKeyTriplet($targetDir, $basename, $keySize)
    {
        $pemPath  = "$targetDir/$basename.pem";
        $privPath = "$targetDir/$basename.priv";
        $pubPath  = "$targetDir/$basename.pub";

        // 1. chiave privata RSA in formato tradizionale (PKCS1)
        self::runOpenssl(array('genrsa', '-out', $pemPath, (string)$keySize));

        // 2. chiave pubblica corrispondente, da caricare su PDND
        self::runOpenssl(array('rsa', '-in', $pemPath, '-pubout', '-out', $pubPath));

        // 3. chiave privata in formato PKCS8 non cifrato: è questa che le classi del servizio
        //    leggono effettivamente (vedi ANNCSUGenericService::setPrivateKey/setModiPrivateKey)
        self::runOpenssl(array('pkcs8', '-topk8', '-inform', 'PEM', '-outform', 'PEM', '-nocrypt', '-in', $pemPath, '-out', $privPath));

        // permessi restrittivi sui file di chiave privata; la pubblica può restare leggibile
        chmod($pemPath, 0600);
        chmod($privPath, 0600);
        chmod($pubPath, 0644);

        self::verifyConformance($pemPath, $privPath, $pubPath, $keySize);

        return array($pemPath, $privPath, $pubPath);
    }

    /**
     * Verifica che le chiavi generate siano conformi ai requisiti PDND:
     * - la chiave privata deve essere una chiave RSA strutturalmente valida
     * - la dimensione della chiave deve rispettare il minimo richiesto
     * - la chiave pubblica deve corrispondere esattamente alla chiave privata (stesso modulo)
     *
     * @throws Exception se una qualsiasi delle verifiche fallisce
     */
    private static function verifyConformance($pemPath, $privPath, $pubPath, $expectedKeySize)
    {
        // validità strutturale della chiave privata (funziona anche su chiavi RSA wrappate in PKCS8)
        $exitCode = null;
        $checkOutput = self::runOpenssl(array('rsa', '-in', $privPath, '-check', '-noout'), $exitCode);
        if ($exitCode !== 0 || stripos($checkOutput, 'ok') === false) {
            throw new Exception("La chiave privata generata in '$privPath' non ha superato la verifica di validità RSA (openssl rsa -check).");
        }

        // dimensione effettiva della chiave
        $textOutput = self::runOpenssl(array('rsa', '-in', $privPath, '-noout', '-text'));
        if (!preg_match('/Private-Key:\s*\((\d+)\s*bit/i', $textOutput, $matches)) {
            throw new Exception("Impossibile determinare la dimensione della chiave generata in '$privPath'.");
        }
        $actualKeySize = (int) $matches[1];
        if ($actualKeySize < $expectedKeySize) {
            throw new Exception("La chiave generata in '$privPath' ha dimensione di $actualKeySize bit, inferiore al minimo richiesto di $expectedKeySize bit.");
        }

        // corrispondenza tra chiave privata e chiave pubblica (confronto del modulo RSA)
        $privModulus = trim(self::runOpenssl(array('rsa', '-in', $privPath, '-noout', '-modulus')));
        $pubModulus  = trim(self::runOpenssl(array('rsa', '-pubin', '-in', $pubPath, '-noout', '-modulus')));
        if ($privModulus === '' || $privModulus !== $pubModulus) {
            throw new Exception("La chiave pubblica '$pubPath' non corrisponde alla chiave privata '$privPath' (modulo RSA differente).");
        }
    }

    /**
     * Valida il nome della cartella/servizio: ammessi solo lettere, numeri, underscore e trattini,
     * per escludere completamente ogni rischio di path traversal.
     *
     * @throws Exception se il nome non rispetta il formato consentito
     */
    private static function validateName($name)
    {
        if (!is_string($name) || $name === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            throw new Exception("Nome '$name' non valido: sono ammessi solo lettere, numeri, underscore e trattini (es. 'aggiornamento_test').");
        }
    }

    /**
     * @throws Exception se la dimensione della chiave richiesta non è un intero valido >= MIN_KEY_SIZE
     */
    private static function validateKeySize($keySize)
    {
        if (!is_int($keySize) || $keySize < self::MIN_KEY_SIZE) {
            throw new Exception("Dimensione chiave non valida: richiesto un numero intero di almeno " . self::MIN_KEY_SIZE . " bit.");
        }
    }

    /**
     * @throws Exception se il comando openssl non risulta disponibile sul sistema
     */
    private static function checkOpensslAvailable()
    {
        exec(self::$opensslBin . ' version 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new Exception("Il comando 'openssl' non risulta disponibile sul sistema (verificare l'installazione e il PATH).");
        }
    }

    /**
     * Esegue un comando openssl con argomenti passati come array e correttamente sanificati
     * (mai come stringa concatenata), per escludere qualsiasi rischio di command injection.
     *
     * @param array    $args       Argomenti del comando openssl (senza il nome del binario)
     * @param int|null &$exitCode  Se passato per riferimento, riceve il codice di uscita del
     *                             comando e disattiva il lancio automatico di eccezione in
     *                             caso di errore, lasciando la gestione al chiamante
     *
     * @return string Output combinato di stdout e stderr del comando
     * @throws Exception se il comando termina con un codice di errore e $exitCode non è
     *                    stato esplicitamente passato dal chiamante
     */
    private static function runOpenssl(array $args, &$exitCode = null)
    {
        $callerHandlesExitCode = (func_num_args() > 1);

        $escapedArgs = array_map('escapeshellarg', $args);
        $command = self::$opensslBin . ' ' . implode(' ', $escapedArgs) . ' 2>&1';

        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        if (!$callerHandlesExitCode && $exitCode !== 0) {
            throw new Exception("Comando openssl fallito (exit code $exitCode): openssl " . implode(' ', $args) . "\nOutput: $output");
        }

        return $output;
    }

    /**
     * Rimuove ricorsivamente una cartella e tutto il suo contenuto.
     * Usata per il rollback in caso di errore durante la generazione.
     */
    private static function removeDirectoryRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? self::removeDirectoryRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
