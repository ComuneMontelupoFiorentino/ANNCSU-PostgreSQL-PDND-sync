-- ============================================================
-- SCHEMA AUDIT
-- ============================================================

CREATE SCHEMA IF NOT EXISTS audit;


-- ============================================================
-- TABELLA AUDIT
-- ============================================================

CREATE TABLE IF NOT EXISTS audit.audit_log
(
    audit_id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    audit_timestamp  timestamp with time zone NOT NULL
                     DEFAULT CURRENT_TIMESTAMP,

    schema_name      text NOT NULL,

    table_name       text NOT NULL,

    operation        char(1) NOT NULL,

    record_old       jsonb,

    record_new       jsonb,

    changed_fields   jsonb,

    db_user          text NOT NULL
                     DEFAULT CURRENT_USER,

    client_addr      inet
                     DEFAULT inet_client_addr(),

    application_name text
                     DEFAULT current_setting(
                         'application_name',
                         true
                     ),

    transaction_id   bigint
                     DEFAULT txid_current(),

    CONSTRAINT audit_log_operation_check
        CHECK (operation IN ('I', 'U', 'D'))
);


-- ============================================================
-- COMMENTI
-- ============================================================

COMMENT ON TABLE audit.audit_log IS
'Audit generico delle operazioni INSERT, UPDATE e DELETE.';

COMMENT ON COLUMN audit.audit_log.audit_id IS
'Identificativo univoco dell''evento di audit.';

COMMENT ON COLUMN audit.audit_log.audit_timestamp IS
'Data e ora dell''operazione.';

COMMENT ON COLUMN audit.audit_log.schema_name IS
'Schema della tabella interessata.';

COMMENT ON COLUMN audit.audit_log.table_name IS
'Nome della tabella interessata.';

COMMENT ON COLUMN audit.audit_log.operation IS
'I = INSERT, U = UPDATE, D = DELETE';

COMMENT ON COLUMN audit.audit_log.record_old IS
'Snapshot completo del record prima dell''operazione.';

COMMENT ON COLUMN audit.audit_log.record_new IS
'Snapshot completo del record dopo l''operazione.';

COMMENT ON COLUMN audit.audit_log.changed_fields IS
'Per UPDATE contiene i soli campi modificati, con valore precedente e nuovo.';

COMMENT ON COLUMN audit.audit_log.db_user IS
'Utente PostgreSQL che ha eseguito l''operazione.';

COMMENT ON COLUMN audit.audit_log.client_addr IS
'Indirizzo IP del client PostgreSQL.';

COMMENT ON COLUMN audit.audit_log.application_name IS
'Nome dell''applicazione/client PostgreSQL.';

COMMENT ON COLUMN audit.audit_log.transaction_id IS
'Identificativo della transazione PostgreSQL.';


-- ============================================================
-- TRIGGER FUNCTION GENERICA DI AUDIT
-- ============================================================

CREATE OR REPLACE FUNCTION audit.trg_func_audit_changes()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
AS $$
DECLARE
    old_data jsonb;
    new_data jsonb;
    changed  jsonb;
BEGIN

    ----------------------------------------------------------------
    -- INSERT
    ----------------------------------------------------------------

    IF TG_OP = 'INSERT' THEN

        new_data := to_jsonb(NEW);

        INSERT INTO audit.audit_log
        (
            schema_name,
            table_name,
            operation,
            record_old,
            record_new,
            changed_fields
        )
        VALUES
        (
            TG_TABLE_SCHEMA,
            TG_TABLE_NAME,
            'I',
            NULL,
            new_data,
            NULL
        );

        RETURN NEW;

    END IF;


    ----------------------------------------------------------------
    -- DELETE
    ----------------------------------------------------------------

    IF TG_OP = 'DELETE' THEN

        old_data := to_jsonb(OLD);

        INSERT INTO audit.audit_log
        (
            schema_name,
            table_name,
            operation,
            record_old,
            record_new,
            changed_fields
        )
        VALUES
        (
            TG_TABLE_SCHEMA,
            TG_TABLE_NAME,
            'D',
            old_data,
            NULL,
            NULL
        );

        RETURN OLD;

    END IF;


    ----------------------------------------------------------------
    -- UPDATE
    ----------------------------------------------------------------

    IF TG_OP = 'UPDATE' THEN

        old_data := to_jsonb(OLD);
        new_data := to_jsonb(NEW);

        /*
         * Individua tutti i campi modificati.
         *
         * IS DISTINCT FROM gestisce correttamente anche i NULL.
         */

        SELECT COALESCE(
            jsonb_object_agg(
                field_name,
                jsonb_build_object(
                    'old', old_data -> field_name,
                    'new', new_data -> field_name
                )
            ),
            '{}'::jsonb
        )
        INTO changed
        FROM
        (
            SELECT key AS field_name
            FROM jsonb_object_keys(old_data || new_data) AS t(key)
            WHERE (old_data -> key)
                  IS DISTINCT FROM
                  (new_data -> key)
        ) AS changed_keys;


        INSERT INTO audit.audit_log
        (
            schema_name,
            table_name,
            operation,
            record_old,
            record_new,
            changed_fields
        )
        VALUES
        (
            TG_TABLE_SCHEMA,
            TG_TABLE_NAME,
            'U',
            old_data,
            new_data,
            changed
        );

        RETURN NEW;

    END IF;


    RETURN NULL;

END;
$$;


-- ============================================================
-- TRIGGER ODONIMI
-- ============================================================

DROP TRIGGER IF EXISTS trg_audit_odonimi
ON stradario.odonimi;

CREATE TRIGGER trg_audit_odonimi
AFTER INSERT OR UPDATE OR DELETE
ON stradario.odonimi
FOR EACH ROW
EXECUTE FUNCTION audit.trg_func_audit_changes();
