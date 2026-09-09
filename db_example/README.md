# UTILITÀ PER CREAZIONE DB POSTGRESQL PER LA GESTIONE E CONFERIMENTO DEI CIVICI

Questo repository contiene gli script SQL utili per creare e gestire il database **Toponomastica** di esempio, utilizzabile per la gestione di odonimi, civici e processi di sincronizzazione verso ANNCSU.

La cartella `db_example/` include:

- la definizione degli schemi (stradario, accessi)

- le tabelle principali

- le funzioni PL/pgSQL

- i trigger per la sincronizzazione

- la vista per l’esportazione delle coordinate

Gli script sono stati suddivisi per favorire manutenzione, versionamento e lettura

## Requisiti

Per utilizzare questi script è necessario installare:

**PostgreSQL 15+**

**PostGIS 3.3+**

**PgAdmin4** (opzionale)

## Struttura cartella

```bash
|── db_example/
    |── 01_schemas.sql
    |── 02_extensions.sql
    |── 03_table_odonimi.sql
    |── 04_table_civici.sql
    |── 05_table_operations_list.sql
    |── 06_table_operations.sql
    |── 07_views.sql
    |── 08_functions_triggers.sql
    |── README.md

```
### Specifiche campi tabella civici

| **CAMPO**                      | **DESCRIZIONE**     | **COMPILAZIONE AUTOMATICA** |
|-----------------------------------|---------------|------------|
| acc_id | chiave primaria della tabella locale   |     SI     |
| geom | Coordinate geografiche  |     NO     |
| acc_a_top_rif | Odonimo completo   |     NO    |
| acc_numero | Numero civico senza esponente   |     NO     |
| acc_a_esponente | Esponente in lettera   |     NO     |
| acc_a_civico_comp | Civico completo (civico+esponente)   |     SI     |
| acc_a_rif_top_civico | Indirizzo completo (via+civico+esponente)   |     SI     |
| acc_num_int | Numero civico interno   |     NO     |
| acc_a_rif_top_civico_int | Indirizzo completo con interno (via+civico+esponente+interno)   |     SI     |
| acc_a_sez_cens | Sezione censuaria   |     NO     |
| acc_rif_cat_ty | Tipologia di catasto (Terreni/Fabbricati)   |     NO     |
| acc_rif_cat_fog | Catasto (foglio)   |     NO     |
| acc_rif_cat_part | Catasto (particella)   |     NO     |
| acc_rif_cat_sub | Catasto (subalterno)   |     NO     |
| progressivo_nazionale | Progressivo Nazionale    |     SI     |
| progressivo_civico | Progressivo Civico   |     SI     |
| allineato | Allineamento su ANNCSU   |     SI     |
| is_civic | Verifica che sia un civico non interno   |     SI     |

## Installazione

> NOTA
>
> Gli script non contengono **CREATE DATABASE**, per permettere flessibilità negli ambienti di sviluppo.
> Dovrà preliminarmente essere creato il database.
> Esempio **CREATE DATABASE toponomastica**.

Esegui gli script contenuti nei file **.sql** nelle sequenza indicata (01->10)


