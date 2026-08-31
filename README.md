# CONFERIMENTO COORDINATE E AGGIORNAMENTO CIVICI SU DB ANNCSU

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

La presente funzionalità è costituita da un insieme di script in linguaggio PHP in grado di gestire le operazioni di conferimento delle coordinate dei civici o l'aggiornamento dei civici stessi su DB ANNCSU. Lo script estrae da DB PostGIS un elenco di record corrispondenti ai civici che si vogliono aggiornare. Le operazioni svolte sono dettagliate in uno specifico file di log generato al termine del processo di conferimento.

**Keywords**: ANNCSU, API, PDND, PostgreSQL, Riuso, PA

## Requisiti di funzionamento e compatibilità con servizi PDND

- server con `openssl` e PHP versione `8` o superiore
- PHP deve essere compilato con le seguenti estensioni: `php-cli`, `php-curl`, `php-pgsql`, `openssl`
- database `Postgresql` con estensione `PostGIS` in cui sono salvati i dati dei civici
- Servizi richiesti attivi e configurati su piattaforma `Interoperabilità PagoPA`
- Lo script è compatibile con la `versione 1` (v1) del servizio di conferimento coordinate e di aggiornamento civici.
- **Il server deve poter raggiungere in uscita (HTTPS, porta 443) gli host `auth.interop.pagopa.it` (o `auth.uat.interop.pagopa.it` per l'ambiente di collaudo) e `modipa.agenziaentrate.gov.it` (o `modipa-val.agenziaentrate.it` per il collaudo)**. Se il server è dietro un firewall con whitelist, verificare che questi domini siano autorizzati (vedi anche la sezione [Risoluzione problemi](#risoluzione-problemi) più sotto).

## Installazione

Lo script non necessita di alcuna installazione specifica, è sufficiente copiare il contenuto completo della cartella principale in una cartella di destinazione sul server sul quale si vorrà lanciare la funzionalità.

## Funzionamento generale dello script

Per ciascuno dei servizi abilitati, è possibile lanciare lo script in due modalità distinte, una modalità di `test` (corrispondente all'ambiente di collaudo PDND) ed una modalità di `produzione`. Dato che gli endpoint di test e produzione sono molto diversi tra loro, è stata previsto per semplicità di dedicare configurazioni distinte per ciascun ambiente.
Lo script è predisposto per contattare 2 tipi di servizi PDND differenti:

| **SERVIZIO**                      | **ALIAS**     | **ATTIVO** |
|-----------------------------------|---------------|------------|
| ANNCSU - Aggiornamento coordinate | coordinate    |     SI     |
| ANNCSU - Aggiornamento accessi    | aggiornamento |     SI     |


> IMPORTANTE
>
> L'**alias** del servizio servirà per poter impostare la configurazione del client (vedi in seguito).

### Struttura delle cartelle

```bash
|── cartella principale
    |── certs
    |── classes
    |── config
        |── anncsu_client_config.ini
        |── pg_service.conf
    |── logs
    |── anncsu.php
```

1. `certs`, contiene le sottocartelle contenenti il materiale crittografico per ciascun servizio abilitato, distinte tra produzione e test
2. `classes`, contiene gli script php che eseguono le funzionalità
3. `config`, contiene il file `anncsu_client_config.ini`, dove vengono definiti tutti i parametri di connessione al servizio specifico e i parametri di interrogazione del database, e il file `pg_service.conf` dove vengono invece specificati i parametri di connessione al database PostGIS
4. `logs`, cartella di destinazione dei log in formato testuale
5. `anncsu.php`, entrypoint della funzionalità

## Configurazione

### Configurazione della piattaforma Interoperabilità PagoPA

Il primo step per poter utilizzare lo script è assicurarsi di aver correttamente abilitato il servizio che si desidera contattare (es per il conferimento coordinate si tratterà del servizio  `ANNCSU - Aggiornamento coordinate`) su piattaforma Interoperabilità PagoPa. Questo comprende l'inoltro della `richiesta` di accesso al servizio, la creazione della `finalità` necessaria all'utilizzo dello stesso, la registrazione del `client API e-service` e il caricamento del `materiale crittografico` necessario. Per i dettagli inerenti alle procedure relative a questa configurazione si rimanda al [manuale operativo](https://docs.pagopa.it/interoperabilita-1) predisposto da PagoPa.

> IMPORTANTE — due chiavi crittografiche, non una sola
>
> I servizi ANNCSU esposti da Agenzia delle Entrate su PDND adottano il pattern **ModI con informazioni aggiuntive** (`AUDIT_REST_02` + `INTEGRITY_REST_02`), che prevede l'uso di **due chiavi RSA distinte** per lo stesso client e-service:
>
> - una prima chiave (**chiave voucher**) usata per firmare il `client_assertion` con cui si ottiene il voucher da PDND (`auth.interop.pagopa.it`)
> - una **seconda chiave, dedicata** (**chiave ModI**), usata per firmare gli header `Agid-JWT-Signature` e `Agid-JWT-TrackingEvidence` inviati direttamente all'e-service ANNCSU
>
> Le due chiavi vanno entrambe generate e caricate nel portachiavi dello **stesso** client e-service su PDND (Fruizione → I tuoi client e-service → \[client] → Chiavi Pubbliche). GovWay (il gateway usato da Agenzia delle Entrate/SOGEI) impone questa separazione in produzione: usare la stessa chiave per entrambi gli scopi porta tipicamente all'errore `400 InteroperabilityInvalidRequest`, con un messaggio generico che non indica la causa reale.

### Caricamento del materiale crittografico per l'utilizzo dello script

Il materiale crittografico associato al servizio richiesto su piattaforma PDND deve anche essere caricato all'interno di una specifica cartella dentro la cartella principale `certs`.

Occorre quindi:

1. creare una sottocartella specifica denominata `{alias}_{ambiente}` dove `{alias}` corriposnde all'alias del servizio e `{ambiente}` corrisponde all'ambiete di esecuzione.

    > ESEMPIO
    >
    > Ho ablitato il servizio di conferimento coordinate su piattaforma di collaudo PDND, dovrò creare una cartella dentro `certs` denominata `coordinate_test`
2. All'interno della cartella appena creata occorre caricare i 3 file della **chiave voucher** (`.pub`, `.priv` e `.pem`) e rinominarli in `key.priv`, `key.pub` e `key.pem`.
3. Generare una **seconda coppia di chiavi RSA 2048 bit**, dedicata alla firma ModI, con gli stessi comandi (vedi sotto), e caricare i 3 file risultanti nella stessa cartella rinominandoli in `modi_key.priv`, `modi_key.pub` e `modi_key.pem`.

Comandi per generare una coppia di chiavi (da ripetere due volte, una per la chiave voucher e una per la chiave ModI, cambiando il nome del file di destinazione):

```bash
openssl genrsa -out key.pem 2048
openssl rsa -in key.pem -pubout -out key.pub
openssl pkcs8 -topk8 -inform PEM -outform PEM -nocrypt -in key.pem -out key.priv
chmod 600 key.pem key.priv
```

Al termine della configurazione di esempio, la struttura della cartella certs dovrebbe apparire come segue:

```bash
|── certs
    |── coordinate_test
        |── key.pem
        |── key.priv
        |── key.pub
        |── modi_key.pem
        |── modi_key.priv
        |── modi_key.pub
```

Sia `key.pub` che `modi_key.pub` devono essere caricate su piattaforma PDND, nel portachiavi dello stesso client e-service; PDND assegnerà a ciascuna un `kid` distinto, da riportare rispettivamente nei parametri `key_id` e `modi_key_id` del file di configurazione (vedi sezione successiva).

### Configurazione del servizio di collegamento al database PostGIS

Configurare il file `config/pg_service.conf` con i parametri di connessione a db.
Il file può contenere due configurazioni `pg_test` e `pg_prod`. Qualsiasi esecuzione che preveda l'utilizzo di ambiente di test (ovvero esecuzioni su ambiente di collaudo PDND) utilizzeranno la connessione `pg_test`. Ugualmente i servizi richiesti su ambiente di produzione utilizzeranno la connessione `pg_prod`.
Questa distinzione è stata introdotta per garantire una maggiore flessibilità in caso si vogliano avere due database distinti per i due ambienti. Nulla vieta di impostare la stessa configurazione sia per test che per produzione.

> ATTENZIONE
>
> Il flag `--test`/`--prod` dello script determina **solo** quale connessione database (`pg_test`/`pg_prod`) e quale sezione ini (`{alias}_test`/`{alias}_prod`) vengono usate. Se nella sezione `{alias}_test` del file ini vengono impostati per errore gli endpoint PDND/ANNCSU di **produzione**, lo script lancerà comunque `--test` scrivendo dati reali sull'archivio nazionale. Verificare sempre, prima del primo utilizzo, che gli endpoint `auth_url`/`aud`/`service_url` della sezione test puntino realmente all'ambiente di collaudo (`auth.uat.interop.pagopa.it`, `modipa-val.agenziaentrate.it`), e non siano una semplice copia della sezione di produzione.

Per i dettagli sulla struttura e sulla configurazione del file pg_service.conf consultare il [manuale](https://www.postgresql.org/docs/current/libpq-pgservice.html) Postgres dedicato.

### Struttura tabellare necessaria per il funzionamento dello script per i servizi di conferimento ed aggiornamento

> NOTA
>
> Nella cartella `db_example/` sono contenuti file di esempio per la creazione di un DB con le specifiche necessarie al funzionamento dello script

#### Tabella dei civici e tabelle accessorie ai civici, comuni ad entrambi i servizi
    
Le tabelle previste sono:

- 1 tabella dei `civici`
- 1 tabella accessoria delle `vie` che riporta il progressivo nazionale ANNCSU della via
- 1 tabella geometrica accessoria con la lista della `sezione censimento` dei civici. 

La struttura tabellare deve prevedere la presenza di UNA SOLA tabella che contiene la lista dei civici che sarà da base di riferimento sia per il servizio di `conferimento` che per il servizio di `aggiornamento` civici.

Tale tabella dei `civici` dovrà avere:

- un campo denominato `id` **univoco** come chiave primaria della tabella (obbligatorio per entrambi i servizi)
- un campo identificativo obbligatorio del `progressivo nazionale` della via. Questo campo è in foreign key con la tabella accessoria delle `vie` in modo che ad ogni civico sia correttamente associato univocamente il progressivo nazionale della via.
- un campo identificativo obbligatorio della sezione censimento in foreign key con la tabella accessoria della `sezione censimento` in modo che ad ogni civico sia correttamente associato univocamente la propria sezione censimento
- un campo identificativo obbligatorio della `geometria` puntuale del civico
- un campo identificativo obbligatorio del `metodo` di ottenimento delle coordinate
- un campo identificativo obbligatorio del `civico`
- un campo identificativo dell'`esponente`
- un campo identificativo del `progressivo accesso` del civico
- un `campo booleano` che indica se il civico è allineato o meno con DB ANNCSU

#### Regole di scrittura sulla tabella dei civici e tabelle accessorie

- ANNCSU considera l'esponente case insensitive, per cui non possono esistere su ANNCSU due record con stesso civico ed esponente uguale, indipendentemente che questo sia scritto maiuscolo o minuscolo (es. non possono esistere Via Rossi 4a e Via Rossi 4A). Per tale ragione è opportuno
inserire un contraint `unique` in questa tabella su `progressivo nazionale + civico + esponente` case insensitive.
- I campi `progressivo nazionale` e `progressivo accesso` devono essere impostati come **NON MODIFICABILI**, salvo la prima scrittura (ovvero se sono NULL e vengono valorizzati questo è permesso, ma non è permesso modificare un dato già esistente)
- La tabella accessoria della sezione censimento è costituita da una lista di record geometrici delle sezioni dei censimenti. Ogni volta che si modifica un civico è consigliato predisporre un tirgger che vada a compilare automaticamente per intersezione la sezione censimento corrispondente e lasciare NON modificabile la sezione censimento lato interfaccia utente. Alternativamente è possibile, sempre lato interfaccia utente, andare a compilare manualmente la sezione censimento del civico scegliendolo da un menu a tendina. Sconsigliato l'inserimento libero testuale

#### Conferimento coordinate

Da premettere che per questa operazione NON sono necessari i dati riguardanti il `progressivo nazionale` e la `sezione censimento`, i quali sono invece obbligatori per le operazioni di aggiornamento civici.

La struttura tabellare aggiuntiva, oltre a quella comune per entrambi i servizi, che lo script si aspetta per l'esecuzione è composta da:

1. Una vista in cui sono presenti i civici da conferire, che riporta **OBBLIGATORIAMENTE** per ciascun record almeno:
    - l'`id` univoco del civico, coincidente con l'id univoco della tabella dei civici
    - il `progressivo accesso` ANNCSU del civico
    - le coordinate `x` e `y` del civico espresse in WGS84 ed arrotondate alla settima cifra decimale
    - il `metodo` di ottenimento cordinate

Non è previsto alcun trigger specifico di gestione dei dati su questa vista.

#### Aggiornamento civici

La struttura tabellare aggiuntiva, oltre a quella comune per entrambi i servizi, che lo script si aspetta per l'esecuzione è composta da:

- 1 tabella accessoria che viene popolata da trigger specifico ogni volta che viene effettuata un operazione di inserimento/modifica/cancellazione sulla tabella principale dei civici. Tale tabella deve **OBBLIGATORIAMENTE** contenere i seguenti campi:

- un campo `id` univoco come chiave primaria (NOT NULL)
- un campo `id_civico`, coincidente con l'id univoco della tabella dei civici (NOT NULL)
- un campo identificativo del `progressivo nazionale` (NOT NULL)
- un campo identificativo del `progressivo accesso` (NULL, obbligatorio solo per operazioni di `modifica` e `cancellazione`)
- un campo identificativo del `civico` (NOT NULL)
- un campo identificativo dell'esponente (NULL)
- un campo identificativo della sezione censimento (NOT NULL)
- due campi identificativi delle coordinate `x` e `y` del civico espresse in WGS84 ed arrotondate alla settima cifra decimale (NOT NULL)
- un campo identificativo del `metodo` di ottenimento delle coordinate (NOT NULL)
- un campo identificativo del `tipo di operazione` da esegurire sul civico (ENUM, 'I' = Inserimento, 'R' = Aggiornamento, 'S' = Eliminazione)
- un `campo booleano` denominato ad esempio `allineato` che indica se il civico è allineato o meno con DB ANNCSU
- un campo testuale `error`, su cui verranno loggati eventuali errore di processo

#### Trigger di popolamento tabella accessoria

Occorre predisporre un trigger sulla tabella dei civici che vada a popolare la tabella accessoria con i record da processare che sia allineato alle seguenti logiche:

- Inserimento di un nuovo civico sulla tabella dei civici: 
    - il trigger inserisce un nuovo record nella tabella accessoria con il campo `allineato = FALSE` e `tipo operazione = I`
- Aggiornamento di un civico:
    - il trigger NON DEVE ESSERE ESEGUITO se le modifiche riguardano i campi `progressivo accesso` o il campo booleano `allineato`
    - se la modifica riguarda un record inserito e non ancora allineato (es ho inserito un nuovo civico e subito dopo l'ho modificato) il trigger deve **semplicemente aggiornare i campi** del record nella tabella accessoria corrispondente al civico già inserito senza modificarne il `tipo operazione` (operazione resta quindi = I) o lo stato `allineato`
    - se la modifica riguarda un record inserito che è già stato allineato (modifica su record esistente su DB ANNCSU), allora il trigger dovrà inserire una nuova riga con tipo `operazione = 'R'` e  `allineato = FALSE`.
    - se la modifica riguarda un record già stato modificato in precedenza e non ancora allineato (caso precedente), il trigger dovrà semplicemente aggiornare i campi del record già inserito, senza modificare il tipo di operazione che rimane impostatata ad `R` o lo stato `allineato`
- Eliminazione di un civico:
    - se è presente un record con `tipo operazione = I`con lo stesso id civico non ancora allineato (ad esempio ho inserito un civico per errore, e lo vado a rimuovere) allora il trigger dovrà **CANCELLARE** la riga riferita all'operazione di inserimento
    - se è presente un operazione di aggiornamento (`tipo operazione = R`) con lo stesso id_civico non ancora allineato, allora il trigger dovrà **CANCELLARE** la riga riferita all'operazione di aggiornamento ed inserire una nuova riga con tipo di operazione `S` e `allineato = FALSE`
    - in tutti gli altri casi, il trigger dovrà aggiungere una riga con tipo di operazione `S` per il civico specifico e `allineato = FALSE`

Riassumiamo qui le specifiche minime per le operazioni di aggiornamento:

- progressivo nazionale, tutti i tipi di operazione
- sezione censimento, solo per nuovi inserimenti
- coordinate wgs84 arrotondate alla 7a cifra, obbligatorie per nuovi inserimenti
- metodo ottenimento coordinate, obbligatorie per nuovi inserimenti
- civico, obbligatorie per nuovi inserimenti

### Configurazione per l'esecuzione delle chiamate verso piattaforma PagoPA

La configurazione va impostata all'interno del file `config/anncsu_client_config.ini` creando una sezione specifica per ogni servizio/ambiente che si vuole contattare, in modo analogo a quanto visto per le cartelle del materiale crittografico. In questo file, al posto delle cartelle, andranno create delle sezioni denominate `[anncsu_{alias}_{ambiente}]`.
Ad esempio, se si desidera connettersi al servizio di conferimento in ambiente di collaudo, occorrerà definire una sezione specifica denominata `[anncsu_coordinate_test]`

#### Campi della configurazione comuni ad ogni servizio

I campi **OBBLIGATORI** comuni ad ogni servizio da riportare per ciascuna sezione sono i seguenti:

| **Parametro**   | **Descrizione**                                                                                                                                                                                            | **Dove trovarlo**                                                               |
|-----------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| iss             | Identificativo univoco del soggetto che inoltra la richiesta.   Si trova su piattaforma PagoPA nelle informazioni relative  al client API e-service. E' univoco per ogni client                            | Piattaforma PagoPA                                                              |
| sub             | Identificativo univoco del soggetto che inoltra la richiesta.   Coincide spesso con iss.   Si trova su piattaforma PagoPA nelle informazioni relative  al client API e-service. E' univoco per ogni client | Piattaforma PagoPA                                                              |
| aud             | Valore di aud per il servzio di autenticazione                                                                                                                                                             | Piattaforma PagoPA                                                              |
| auth_url        | Endpoint del servizio di autenticazione                                                                                                                                                                    | Piattaforma PagoPA                                                              |
| service_url     | Base URI del servizio richiesto  Specifico per ogni servizio, ottenibile consultando  le informazioni tecniche di ciascun e-service abilitato                                                              | Piattaforma PagoPA                                                              |
| purpose_id      | Si trova su piattaforma PagoPA nelle informazioni relative  al client API e-service. E' univoco per ogni f inalità                                                                                         | Piattaforma PagoPA                                                              |
| client_id       | Identificativo univoco del client API e-service.  Si trova su piattaforma PagoPA nelle informazioni relative  al client API e-service. E' univoco per ogni client                                          | Piattaforma PagoPA                                                              |
| key_id          | Corrisponde al kid della **chiave voucher** (vedi sopra).  Si trova su piattaforma PagoPA nelle informazioni relative  al client API e-service. E' univoco per ogni client                                 | Piattaforma PagoPA                                                              |
| modi_key_id     | Corrisponde al kid della **chiave ModI**, distinta dalla chiave voucher (vedi sezione "Caricamento del materiale crittografico" sopra). Necessaria per firmare gli header `Agid-JWT-Signature` e `Agid-JWT-TrackingEvidence` | Piattaforma PagoPA, dopo il caricamento della seconda chiave pubblica            |
| user_location   | Parametro non meglio documentato, indica la postazione da cui viene eseguito il servizio                                                                                                                   | Valore fisso impostato come "pc-1"                                              |
| LoA             | Parametro indicativo del metodo di autenticazione che l'utente utilizza per connnettersi alla piattaforma selfacare.pagopa.it                                                                              | Valore fisso impostato come "LoA2 / SPID"                                       |
| user_id         | Identificativo univoco dell'utente interno al dominio del  fruitore che ha determinato l'esigenza della request.


#### Campi della configurazione obbligatori da definire per il servizio di conferimento coordinate

| **Parametro**   | **Descrizione**                                                                                                                                                                                      | **Dove trovarlo**                                       |
|-----------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------|
| schema          | schema del database in cui sono presenti le tabelle/viste dei civici                                                                                                                                 | Definito internamente in base all'infrastruttura del db |
| vista_accessi     | Nome della vista utilizzata per recuperare i record da leggere                                                                                                                                       | Definito internamente in base all'infrastruttura del db |
| tabella_accessi    | Nome della tabella contenente la lista dei civici                                                                                                                                                    | Definito internamente in base all'infrastruttura del db |
| id_tabella_accessi | Nome della colonna presente sulla tabella tabella_accessi che identifica univocamente il civico (chiave primaria)                                                                                    | Definito internamente in base all'infrastruttura del db |
| id_vista_accessi   | Nome della colonna presente nella vista vista_accessi che identifica univocamente il civico, coincidente con id_tabella_accessi                                                                     | Definito internamente in base all'infrastruttura del db |
| allineato_tabella_accessi | Nome della colonna presente sulla tabella tabella_accessi che identifica se un civico è stato conferito o meno                                                                                          | Definito internamente in base all'infrastruttura del db |
| progr_vista_accessi     | Nome della colonna nella vista vista_accessi che contiene  il valore del progressivo_accesso anncsu                                                                                                    | Definito internamente in base all'infrastruttura del db |
| coord_x   | Nome della colonna nell vista vista_accessi che contiene il valore la coordinata x del civico. Il valore deve essere espresso in coordinate WGS84 e deve essere arrotondato all settima cifra decimale | Definito internamente in base all'infrastruttura del db |
| coord_y   | Nome della colonna nell vista vista_accessi che contiene il valore la coordinata y del civico. Il valore deve essere espresso in coordinate WGS84 e deve essere arrotondato all settima cifra decimale | Definito internamente in base all'infrastruttura del db |
| metodo            | Nome della colonna nella vista vista_accessi che identifica il metodo di ottenimento delle cordinate del civico                                                                                         | Definito internamente in base all'infrastruttura del db  |
| codcom          | Codice del comune                                                                                                                                                                                    | es. F551                                                |

#### Campi della configurazione obbligatori da definire per il servizio di aggiornamento accessi

| **Parametro**           | **Descrizione**                                                                                                                                                                                           | **Dove trovarlo**                                        |
|-------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------|
| schema                  | schema del database in cui sono presenti le tabelle/viste dei civici                                                                                                                                      | Definito internamente in base all'infrastruttura del db  |
| tabella_operazioni           | Nome della tabella di appoggio da cui leggere i record da processare                                                                                                                                      | Definito internamente in base all'infrastruttura del db  |
| tabella_accessi            | Nome della tabella contenente la lista dei civici                                                                                                                                                         | Definito internamente in base all'infrastruttura del db  |
| id_tabella_accessi | Nome della colonna presente sulla tabella tabella_accessi che identifica univocamente il civico (chiave primaria)                                                                                    | Definito internamente in base all'infrastruttura del db  |
| id_tabella_operazioni | Nome della colonna presente sulla tabella tabella_operazioni che identifica univocamente la riga di operazione (chiave primaria)                                                                     | Definito internamente in base all'infrastruttura del db  |
| id_civico_operazioni | Nome della colonna presente sulla tabella tabella_operazioni che contiene il riferimento (foreign key) a id_tabella_accessi                                                                          | Definito internamente in base all'infrastruttura del db  |
| progr_naz         | Nome della colonna presente sulla tabella tabella_operazioni  che contiene il progressivo nazionale dell'odonimo                                                                                               | Definito internamente in base all'infrastruttura  del db |
| progr_tabella_operazioni     | Nome della colonna presente sulla tabella tabella_operazioni   che contiene il progressivo accesso del civico                                                                                                  | Definito internamente in base all'infrastruttura del db  |
| progr_tabella_accessi      |Nome della colonna presente sulla tabella tabella_accessi che contiene il progressivo accesso del civico                                                                                               | Definito internamente in base all'infrastruttura del db  |
| civico            | Nome della colonna nella tabella tabella_operazioni che contiene  il valore numerico del civico                                                                                                                | Definito internamente in base all'infrastruttura del db  |
| esp               | Nome della colonna nella tabella tabella_operazioni che contiene il valore alfanumerico dell'esponente del civico                                                                                              | Definito internamente in base all'infrastruttura del db  |
| sez_cens          | Nome della colonna nella tabella tabella_operazioni che contiene il valore della sezione censimento del civico                                                                                                 | Definito internamente in base all'infrastruttura del db  |
| coord_x           | Nome della colonna nella tabella tabella_operazioni che contiene il valore la coordinata x del civico. Il valore deve essere espresso in coordinate WGS84 e deve essere arrotondato all settima cifra decimale | Definito internamente in base all'infrastruttura del db  |
| coord_y           | Nome della colonna nella tabella tabella_operazioni che contiene il valore la coordinata y del civico. Il valore deve essere espresso in coordinate WGS84 e deve essere arrotondato all settima cifra decimale | Definito internamente in base all'infrastruttura del db  |
| metodo            | Nome della colonna nella tabella tabella_operazioni che identifica il metodo di ottenimento delle cordinate del civico                                                                                         | Definito internamente in base all'infrastruttura del db  |
| tipo_operazione   | Nome della colonna nella tabella tabella_operazioni che identifica il tipo da operazione da eseguire. ENUM I=Inserimento, R = Aggiornamento, S=Cancellazione                                                   | Definito internamente in base all'infrastruttura del db  |
| allineato_tabella_operazioni | Nome della colonna booleana presente nella tabella tabella_operazioni che indica se il civico è allineato con DB ANNCSU                                                                                        | Definito internamente in base all'infrastruttura del db  |
| allineato_tabella_accessi  | Nome della colonna booleana presente nella tabella tabella_accessi che indica se il civico è allineato con DB ANNCSU                                                                                         | Definito internamente in base all'infrastruttura del db  |
| codcom                  | Codice del comune                                                                                                                                                                                         | es. F551                                                 |

Di seguito un esempio di configurazione per il servizio di conferimento coordinate in ambiete di produzione:

```ìni
[anncsu_coordinate_prod]
iss=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
sub=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
aud=auth.interop.pagopa.it/client-assertion
auth_url=https://auth.interop.pagopa.it/token.oauth2
service_url=https://modipa.agenziaentrate.gov.it/govway/rest/in/AgenziaEntrate-PDND/anncsu-aggiornamento-coordinate/v1
purpose_id=xxxxxxxx-yyyy-zzzz-jjjj-xxxxxxxxxxxx
client_id=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
key_id=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
modi_key_id=yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
user_location=pc-1
LoA=LoA2 / SPID
user_id=MARIO ROSSI
schema=accessi
tabella_accessi=civici
vista_accessi=conf_coord
id_tabella_accessi=acc_id
id_vista_accessi=id
allineato_tabella_accessi=allineato
progr_vista_accessi=progressivo_accesso
coord_x=coord_x
coord_y=coord_y
metodo=metodo
codcom=F551
```

Altro esempio di una configurazione per il servizio di aggiornamento civici in ambiente di test

```ìni
[anncsu_aggiornamento_test]
iss=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
sub=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
aud=auth.uat.interop.pagopa.it/client-assertion
auth_url=https://auth.uat.interop.pagopa.it/token.oauth2
service_url=https://modipa-val.agenziaentrate.it/govway/rest/in/AgenziaEntrate-PDND/anncsu-aggiornamento-accessi/v1
purpose_id=xxxxxxxx-yyyy-zzzz-jjjj-xxxxxxxxxxxx
client_id=zzzzzzzz-hhhh-tttt-gggg-xxxxxxxxxxxx
key_id=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
modi_key_id=yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
user_location=pc-1
LoA=LoA2 / SPID
user_id=MARIO ROSSI
schema=accessi
tabella_accessi=civici
tabella_operazioni=sync_anncsu_queue
id_tabella_accessi=acc_id
id_tabella_operazioni=id
id_civico_operazioni=id_civico
progr_naz=progressivo_nazionale
progr_tabella_operazioni=progressivo_civico
progr_tabella_accessi=progressivo_civico
civico=civico
esp=esponente
sez_cens=sezione_censimento
coord_x=coord_x
coord_y=coord_y
metodo=metodo
tipo_operazione=operazione
allineato_tabella_operazioni=allineato
allineato_tabella_accessi=allineato
codcom=F551
```

## Utilizzo della funzionalità

Una volta completati gli step di configurazione precedenti, è possibile lanciare la funzionalità direttamente da riga di comando posizionandosi nella cartella principale (stesso livello del file `anncsu.php`)

```cli
$> php anncsu.php [args]
```

### Parametri dello script

Per ragioni di sicurezza è obbligatorio lanciare lo script definendo l'ambiente di esecuzione ed almeno una tra le tipologie di operazioni disponibili.

Parametri di ambiente:

- `--test`, lancio in ambiente di test
- `--prod`, lancio in ambiente di produzione

in caso nessuna o entrambe le opzioni vengano specificate, lo script terminerà con errore.

Parametri operativi:

- `-c`, conferimento coordinate
- `-a`, aggiornamento civici

in caso nessuna o entrambe le opzioni vengano specificate, lo script terminerà con errore.

Esempio di lancio dello script per conferimento coordinate in ambiente di produzione:

```cli
$> php anncsu.php --prod -c
```

Esempio di lancio dello script per aggiornamento civici in ambiente di test:

```cli
$> php anncsu.php --test -a
```

> NOTA
>
>Lo script è impostato per eseguire un numero di conferimenti massimo 
>pari al liite giornaliero di chiamate imposto dal piattaforma PDND che 
>attualmente è pari a 2000 chiamate per entrambi i servizi.

### Logs

Lo script è in modalità verbosa per default, significa che durante l'esecuzione notifica in console i vari step e passaggi che sta eseguendo. Al termine di ciascuna esecuzione viene poi prodotto un file testuale nella cartella `logs` denominato `{timestamp} {alias}_{ambiente}.log`.

All'interno del file si possono trovare tutti gli output dettagliati sugli accessi che sono stati processati e sull'esito dell'operazione per ciascun record.

## Risoluzione problemi

### Errore `400 InteroperabilityInvalidRequest` (o simile, generico) sulla chiamata all'e-service

Questo errore viene restituito dal gateway GovWay quando la richiesta non supera i controlli del profilo di interoperabilità ModI, e per motivi di sicurezza **non specifica quale controllo esatto è fallito**. Cause più comuni riscontrate:

1. **Chiave ModI mancante o uguale a quella del voucher** — vedi sopra, sezione "Caricamento del materiale crittografico". Verificare che `modi_key_id` sia valorizzato e diverso (consigliato) da `key_id`, e che corrisponda a una chiave effettivamente caricata sul portachiavi del client.
2. **Orologio di sistema disallineato (clock skew)** — i JWT usati (voucher, `Agid-JWT-Signature`, `Agid-JWT-TrackingEvidence`) hanno una validità di pochi minuti (`nbf`/`exp`). Se l'orologio del server è anche solo di qualche decina di secondi avanti o indietro rispetto all'ora reale (tipicamente perché il servizio NTP è bloccato da un firewall aziendale), GovWay può rifiutare la richiesta con lo stesso errore generico. Verificare con:
   ```bash
   date -u
   curl -sI --max-time 8 https://auth.interop.pagopa.it | grep -i '^date:'
   ```
   Le due date devono coincidere entro pochi secondi. Se il server non può essere risincronizzato via NTP (es. porta UDP 123 bloccata dal firewall e nessun accesso amministrativo per sbloccarla), lo script dalla versione corrente compensa automaticamente leggendo l'ora esatta dall'header HTTP `Date` del server PDND ad ogni esecuzione (funzione `ANNCSUUtilities::getAccurateTimestamp()`), quindi questo problema non dovrebbe più presentarsi anche con un orologio locale non sincronizzato — ma resta comunque buona norma segnalare e correggere l'NTP a livello di infrastruttura.
3. **Header `Content-Encoding` non valido** — se si personalizzano gli header della richiesta, ricordare che `UTF-8` non è un valore HTTP `Content-Encoding` valido (è un charset, non uno schema di codifica); usare `identity` o omettere l'header.
4. **Sezione ini "test" che punta in realtà alla produzione** — vedi l'avviso nella sezione "Configurazione del servizio di collegamento al database PostGIS" più sopra.

In caso il problema persista dopo aver verificato questi punti, contattare l'assistenza tecnica di Agenzia delle Entrate/SOGEI all'indirizzo `infopdnd_anncsu@sogei.it`, allegando il valore `govway_id` presente nella risposta di errore (identifica univocamente la richiesta nei log del gateway, permettendo una diagnosi lato server che dall'esterno non è possibile ottenere).

### Errore applicativo `ORA-01403 - nessun dato trovato` (o simile)

A differenza del precedente, questo è un errore restituito dal database applicativo di ANNCSU dopo che la richiesta è stata accettata e autenticata correttamente: significa che il `progr_civico` (o l'accesso identificato dai dati inviati) non è stato trovato lato ANNCSU per l'operazione di aggiornamento/cancellazione richiesta. Cause tipiche:

- il civico non è mai stato effettivamente conferito su ANNCSU nonostante risulti tale nei dati locali (es. per un'esecuzione fallita in precedenza il cui esito non è stato registrato correttamente)
- il `progr_civico` salvato in locale è obsoleto o errato

Prima di correggere manualmente i dati locali, è consigliabile verificare l'effettiva esistenza del civico su ANNCSU tramite il servizio di consultazione PDND, oppure — se non disponibile — tramite l'endpoint pubblico open data (senza autenticazione):

```bash
curl -s "https://anncsu.open.agenziaentrate.gov.it/age-inspire/opendata/anncsu/querydata.php?resource=accessi&progressivoodonimo=<PROGR_NAZIONALE_VIA>&accesso=<NUMERO_CIVICO>"
```

Nota: questo endpoint filtra solo sul numero civico e non distingue civici con lo stesso numero ma esponente diverso (es. "12" e "12A" possono comparire come un solo risultato); in caso di ambiguità è necessario verificare per altra via quale sia l'accesso corretto.

### Come attribuire
A titolo d'esempio, è sufficiente indicare, ove opportuno, "anncsu-postgresql-sync-pdnd Copyright © 2025 Comune di Montelupo Fiorentino."
