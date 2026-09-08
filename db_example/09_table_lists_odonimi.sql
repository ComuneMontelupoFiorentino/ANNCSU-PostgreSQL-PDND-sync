-- Lista delle località
CREATE TABLE stradario.localita_list (
    id serial primary key,
    localita_name VARCHAR(200) UNIQUE NOT NULL
);

-- Lista dei DUG utilizzabili
CREATE TABLE stradario.dug_list (
    id serial primary key,
    dug_name VARCHAR(200) UNIQUE NOT NULL
);

-- Lista delle opzioni delibera
CREATE TABLE stradario.flag_delibera_list (
    id serial primary key,
    flag_delibera_ty CHAR(1) UNIQUE NOT NULL,
    mat_pav_desc VARCHAR(200) UNIQUE NOT NULL
);

-- Popolazione dei DUG
INSERT INTO stradario.dug_list (dug_name)
VALUES
('VIA'), ('VIALE'), ('VICOLO'), ('CALLE'), ('SALITA'), ('PIAZZA'), ('PIAZZALE'), ('LARGO'), ('LUNGOMARE'), ('SLARGO'), ('BORGO'), ('LUNGO'), 
('PARCHEGGIO'), ('PASSERELLA'), ('PERCORSO PEDONALE'), ('ROTATORIA'), ('SOTTOPASSO'), ('GIARDINO');

-- Popolazione lista delle pavimentazioni
INSERT INTO stradario.pav_list (pav_name)
VALUES
('non pavimentato'), ('pavimentato'), ('sterrato');

-- Popolazione lista opzioni delibera
INSERT INTO stradario.flag_delibera_list (flag_delibera_ty, mat_pav_desc)
VALUES
('0', 'Delibera presente (atto formale adottato e protocollato dall''ente)'), 
('1', 'Delibera di Giunta/Consiglio (atto approvato dagli organi collegiali)'), 
('2', 'In attesa di delibera / Delibera in corso di adozione'),
('3', 'Determina dirigenziale / Disposizione d''ufficio'),
('4', 'Senza delibera / Intitolazione storica o di fatto (assenza di un atto formale)');
