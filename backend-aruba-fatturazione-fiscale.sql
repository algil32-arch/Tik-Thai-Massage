-- Eseguire una sola volta sui database gia' installati.
ALTER TABLE professionisti
  ADD COLUMN ragione_sociale VARCHAR(255) NULL AFTER cognome,
  ADD COLUMN provincia CHAR(2) NULL AFTER citta;

ALTER TABLE clienti
  ADD COLUMN provincia CHAR(2) NULL AFTER citta;

ALTER TABLE fatture
  ADD COLUMN tipo_documento CHAR(4) NOT NULL DEFAULT 'TD01' AFTER id_professionista,
  ADD COLUMN data_prestazione DATE NULL AFTER tipo_documento,
  ADD COLUMN natura_iva VARCHAR(4) NULL AFTER data_prestazione,
  ADD COLUMN importo_rivalsa DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER natura_iva,
  ADD COLUMN bollo_virtuale TINYINT NOT NULL DEFAULT 0 AFTER importo_rivalsa,
  ADD COLUMN bollo_addebitato TINYINT NOT NULL DEFAULT 0 AFTER bollo_virtuale,
  ADD COLUMN importo_bollo DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER bollo_addebitato;
