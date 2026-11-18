# Guida Pratica alla Creazione di Chiavi e Certificati SSL/TLS con OpenSSL

Version 1.0

Questa guida raccoglie le istruzioni passo-passo per generare chiavi private, richieste di firma del certificato (CSR) e certificati autofirmati (Self-Signed) utilizzando **OpenSSL**.

---

## Prerequisiti

Verifica che OpenSSL sia installato sul tuo sistema aprendo il terminale e digitando:

```bash
openssl version
```

> **Nota per OpenSSL 3.x:** Se vuoi generare chiavi private senza password, nei comandi più recenti si preferisce la flag `-noenc` rispetto alla legacy `-nodes`.

---

## 1. Generazione della sola Chiave Privata

### Generare una chiave RSA (2048 o 4096 bit)

* **Con protezione da Passphrase (consigliata per produzione/CA):**
  ```bash
  openssl genrsa -aes256 -out private.key 2048
  ```

* **Senza Passphrase (ideale per server automatizzati Nginx/Apache):**
  ```bash
  openssl genrsa -out private.key 2048
  ```

### Generare una chiave a Curva Ellittica (ECDSA P-256)
*L'algoritmo ECDSA offre un livello di sicurezza elevato con dimensioni delle chiavi ridotte e migliori prestazioni.*

```bash
openssl ecparam -genkey -name prime256v1 -out private_ec.key
```

---

## 2. Generare una CSR (Certificate Signing Request)

La CSR serve quando si deve richiedere un certificato SSL ufficiale a una **Certificate Authority (CA)** (es. DigiCert, Sectigo, Let's Encrypt).

### Opzione A: Con una chiave privata esistente
```bash
openssl req -new -key private.key -out request.csr
```

### Opzione B: Generare Chiave Privata e CSR in un unico comando
```bash
openssl req -new -newkey rsa:2048 -nodes -keyout private.key -out request.csr
```

### Campi richiesti durante il prompt interattivo:
* **Country Name (2 letter code) [AU]:** IT
* **State or Province Name (full name) []:** Italia
* **Locality Name (eg, city) []:** Roma
* **Organization Name (eg, company) []:** Mia Azienda S.p.A.
* **Organizational Unit Name (eg, section) []:** IT Dept
* **Common Name (e.g. server FQDN or YOUR name) []:** www.tuodominio.it *(Campo fondamentale)*
* **Email Address []:** admin@tuodominio.it
* **A challenge password []:** *(Lasciare vuoto premendo INVIO)*

---

## 3. Generare un Certificato Self-Signed (Autofirmato)

I certificati autofirmati sono adatti per ambienti di sviluppo, testing o uso interno.

### Comando Rapido (Genera Chiave + Certificato in un solo passaggio)
```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout self-signed.key \
  -out self-signed.crt \
  -subj "/C=IT/ST=Italia/L=Roma/O=Sviluppo/CN=localhost"
```

### Dettaglio dei parametri:
* `-x509`: Indica di creare un certificato diretto invece di una CSR.
* `-days 365`: Durata di validità del certificato (in giorni).
* `-nodes` (oppure `-noenc` su OpenSSL 3+): Non cifra la chiave privata con passphrase.
* `-subj`: Permette di passare le informazioni dell'organizzatore direttamente dalla riga di comando senza prompt interattivo.

---

## 4. Verifica e Ispezione dei File

Per verificare la correttezza del contenuto dei file generati:

* **Ispezionare una CSR:**
  ```bash
  openssl req -in request.csr -noout -text
  ```

* **Ispezionare un Certificato CRT:**
  ```bash
  openssl x509 -in self-signed.crt -noout -text
  ```

* **Verificare che Chiave Privata e Certificato corrispondano:**
  ```bash
  openssl x509 -noout -modulus -in self-signed.crt | md5sum
  openssl rsa -noout -modulus -in private.key | md5sum
  ```
  *(Se i due hash MD5 coincidono, la chiave e il certificato sono accoppiati correttamente).*

---

## 5. Permessi e Sicurezza dei File

Assicurati che la chiave privata non sia leggibile da altri utenti del sistema:

```bash
chmod 600 private.key
chmod 644 self-signed.crt
```