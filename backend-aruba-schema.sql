-- Schema iniziale per un sistema di prenotazioni e fatturazione
-- Compatibile con PHP + MySQL/MariaDB su hosting Aruba

CREATE TABLE professionisti (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nome VARCHAR(100) NOT NULL,
  cognome VARCHAR(100) NOT NULL,
  partita_iva VARCHAR(11),
  codice_fiscale VARCHAR(16),
  email VARCHAR(255) NOT NULL,
  pec VARCHAR(255),
  iban VARCHAR(34),
  regime_fiscale VARCHAR(50),
  indirizzo VARCHAR(255),
  citta VARCHAR(100),
  cap VARCHAR(10),
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE studi (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nome VARCHAR(150) NOT NULL,
  indirizzo VARCHAR(255),
  citta VARCHAR(100),
  cap VARCHAR(10),
  email_studio VARCHAR(255),
  telefono VARCHAR(50),
  id_professionista INT NOT NULL,
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_professionista) REFERENCES professionisti(id)
);

CREATE TABLE clienti (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nome VARCHAR(100) NOT NULL,
  cognome VARCHAR(100) NOT NULL,
  email VARCHAR(255),
  telefono VARCHAR(50),
  codice_fiscale VARCHAR(16),
  partita_iva VARCHAR(11),
  indirizzo VARCHAR(255),
  citta VARCHAR(100),
  cap VARCHAR(10),
  tipo_cliente ENUM('privato','azienda') DEFAULT 'privato',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE servizi (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_studio INT NOT NULL,
  nome VARCHAR(150) NOT NULL,
  durata_minuti INT NOT NULL,
  prezzo DECIMAL(10,2) NOT NULL,
  iva_percentuale DECIMAL(5,2) DEFAULT 22.00,
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_studio) REFERENCES studi(id)
);

CREATE TABLE appuntamenti (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_studio INT NOT NULL,
  id_professionista INT NOT NULL,
  id_cliente INT NOT NULL,
  id_servizio INT NOT NULL,
  data_appuntamento DATE NOT NULL,
  ora_inizio TIME NOT NULL,
  ora_fine TIME NOT NULL,
  stato ENUM('in_attesa','confermato','completato','annullato') DEFAULT 'in_attesa',
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_studio) REFERENCES studi(id),
  FOREIGN KEY (id_professionista) REFERENCES professionisti(id),
  FOREIGN KEY (id_cliente) REFERENCES clienti(id),
  FOREIGN KEY (id_servizio) REFERENCES servizi(id)
);

CREATE TABLE fatture (
  id INT PRIMARY KEY AUTO_INCREMENT,
  numero_fattura VARCHAR(50) NOT NULL,
  serie VARCHAR(10) DEFAULT 'TS',
  data_emissione DATE NOT NULL,
  data_scadenza DATE,
  id_cliente INT NOT NULL,
  id_studio INT NOT NULL,
  id_professionista INT NOT NULL,
  importo_netto DECIMAL(10,2) NOT NULL,
  iva_totale DECIMAL(10,2) NOT NULL,
  importo_totale DECIMAL(10,2) NOT NULL,
  stato ENUM('bozza','emessa','inviata','pagata','annullata') DEFAULT 'bozza',
  xml_path VARCHAR(255),
  pdf_path VARCHAR(255),
  protocollo_sdi VARCHAR(100),
  data_invio_sdi DATETIME,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_cliente) REFERENCES clienti(id),
  FOREIGN KEY (id_studio) REFERENCES studi(id),
  FOREIGN KEY (id_professionista) REFERENCES professionisti(id)
);

CREATE TABLE righe_fattura (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_fattura INT NOT NULL,
  id_servizio INT,
  descrizione VARCHAR(255) NOT NULL,
  quantita DECIMAL(10,2) DEFAULT 1,
  prezzo_unitario DECIMAL(10,2) NOT NULL,
  iva_percentuale DECIMAL(5,2) NOT NULL,
  importo_netto DECIMAL(10,2) NOT NULL,
  importo_iva DECIMAL(10,2) NOT NULL,
  importo_totale DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_fattura) REFERENCES fatture(id),
  FOREIGN KEY (id_servizio) REFERENCES servizi(id)
);

CREATE TABLE documenti_fattura (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_fattura INT NOT NULL,
  tipo_documento ENUM('originale','copia_di_cortesia','xml','pdf','nota_credito') NOT NULL,
  path_file VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_fattura) REFERENCES fatture(id)
);

CREATE TABLE pagamenti (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_fattura INT NOT NULL,
  id_appuntamento INT,
  importo DECIMAL(10,2) NOT NULL,
  metodo_pagamento ENUM('contanti','bonifico','carta','altro') DEFAULT 'contanti',
  stato ENUM('in_attesa','pagato','parziale','annullato') DEFAULT 'in_attesa',
  data_pagamento DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_fattura) REFERENCES fatture(id),
  FOREIGN KEY (id_appuntamento) REFERENCES appuntamenti(id)
);

CREATE INDEX idx_studi_professionista ON studi(id_professionista);
CREATE INDEX idx_servizi_studio ON servizi(id_studio);
CREATE INDEX idx_appuntamenti_data ON appuntamenti(data_appuntamento, ora_inizio);
CREATE INDEX idx_fatture_cliente ON fatture(id_cliente);
CREATE INDEX idx_fatture_studio ON fatture(id_studio);
