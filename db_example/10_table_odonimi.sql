CREATE TABLE IF NOT EXISTS stradario.odonimi
(
    odo_id serial,

    -- geometria per postgis
    geom geometry NOT NULL,

    -- Località (da ISTAT), vedi tabella localita_list
    odo_loc character varying(255) COLLATE pg_catalog."default",

    -- Denominazione urbanistica generica - DUG (VIA, PIAZZA, SLARGO...), vedi tabella dug_list
    odo_dug character varying(255) COLLATE pg_catalog."default",

    -- Denominazione ufficiale - DUF (Es. ROMA)
    odo_duf character varying(255) COLLATE pg_catalog."default",

    -- Denominazione completa - (DUG + DUF)
    odo_den character varying(255) COLLATE pg_catalog."default",

    -- Denominazione normalizzata (sostituzione spazi con '-' e lowercase)
    odo_norm character varying(255) COLLATE pg_catalog."default",

    -- Progressivo Nazionale
    odo_progressivo_nazionale character varying(255) COLLATE pg_catalog."default",

    -- Data validità amministrativa
    odo_data_valid_amm date,

    -- Denominazione lingua alternativa 1
    odo_denom_in_lingua_1 character varying(150) COLLATE pg_catalog."default",

    -- Denominazione lingua alternativa 2
    odo_denom_in_lingua_2 character varying(150) COLLATE pg_catalog."default",

    -- Se delibera è presente e tipologia, vedi flag_delibera_list
    odo_flag_delibera character(1) COLLATE pg_catalog."default",

    -- Data provvedimento
    odo_provvedimento_data date,

    -- N. protocollo provvedimento
    odo_provvedimento_protocollo character varying(70) COLLATE pg_catalog."default",

    -- Data autorizzazione prefettura
    odo_aut_prefettura_data date,

    -- N. protocollo autorizzazione prefettura
    odo_protocollo_pref character varying(70) COLLATE pg_catalog."default",

    -- Data creazione record
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,

    -- Operazione API PDND (I, R, S), vedi tabella operations_sinc_anncsu
    odo_operazione character varying(1) COLLATE pg_catalog."default" DEFAULT 'I'::character varying,

    -- Indica se è allineato su ANNCSU
    odo_allineato boolean NOT NULL DEFAULT false,

    -- Log errore di allineamento su ANNCSU
    error text COLLATE pg_catalog."default",

    -- Data ultimo allineamento
    allineato_time timestamp without time zone,

    CONSTRAINT odo_pkey PRIMARY KEY (odo_id),

    CONSTRAINT odo_operazione_fkey FOREIGN KEY (odo_operazione)
        REFERENCES accessi.operations_sinc_anncsu (op_ty)
        MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE NO ACTION,

    CONSTRAINT odo_localita_fkey FOREIGN KEY (odo_loc)
        REFERENCES stradario.localita_list (localita_name)
        MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE NO ACTION,

    CONSTRAINT odo_dug_fkey FOREIGN KEY (odo_dug)
        REFERENCES stradario.dug_list (dug_name)
        MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE NO ACTION,

    CONSTRAINT odo_flag_delibera_fkey FOREIGN KEY (odo_flag_delibera)
        REFERENCES stradario.flag_delibera_list (flag_delibera_ty)
        MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE NO ACTION,

    CONSTRAINT odo_aut_prefettura_coerenza_check
        CHECK (
            (
                odo_aut_prefettura_data IS NULL
                AND odo_protocollo_pref IS NULL
            )
            OR
            (
                odo_aut_prefettura_data IS NOT NULL
                AND odo_protocollo_pref IS NOT NULL
            )
        ),

    CONSTRAINT odo_flag_delibera_check
        CHECK (
            odo_flag_delibera IS NULL
            OR odo_flag_delibera = ANY (
                ARRAY[
                    '0'::bpchar,
                    '1'::bpchar,
                    '2'::bpchar,
                    '3'::bpchar,
                    '4'::bpchar
                ]
            )
        ),

    CONSTRAINT odo_progr_nazionale_coerenza_check
        CHECK (
            odo_allineato = true
            OR odo_operazione IS NULL
            OR (
                odo_operazione::text = 'I'::text
                AND odo_progressivo_nazionale IS NULL
            )
            OR (
                odo_operazione::text = ANY (
                    ARRAY[
                        'R'::character varying,
                        'S'::character varying
                    ]::text[]
                )
                AND odo_progressivo_nazionale IS NOT NULL
            )
        ),

    CONSTRAINT odo_provvedimento_coerenza_check
        CHECK (
            odo_allineato = true
            OR odo_flag_delibera IS NULL
            OR (
                odo_flag_delibera = ANY (
                    ARRAY[
                        '0'::bpchar,
                        '1'::bpchar
                    ]
                )
                AND odo_provvedimento_data IS NOT NULL
                AND odo_provvedimento_protocollo IS NOT NULL
            )
            OR (
                odo_flag_delibera = ANY (
                    ARRAY[
                        '2'::bpchar,
                        '3'::bpchar,
                        '4'::bpchar
                    ]
                )
                AND odo_provvedimento_data IS NULL
                AND odo_provvedimento_protocollo IS NULL
            )
        )
);
