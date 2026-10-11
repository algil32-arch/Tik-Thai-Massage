-- Sincronizzazione bidirezionale con Google Calendar
-- Importare una sola volta sui database gia creati.

ALTER TABLE appuntamenti
  ADD COLUMN google_event_id VARCHAR(255) NULL AFTER sumup_transaction_id,
  ADD COLUMN google_synced_at DATETIME NULL AFTER google_event_id,
  ADD UNIQUE KEY uq_appuntamenti_google_event (google_event_id);

CREATE TABLE IF NOT EXISTS agenda_blocchi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  google_event_id VARCHAR(255) NOT NULL,
  data_blocco DATE NOT NULL,
  ora_inizio TIME NOT NULL,
  ora_fine TIME NOT NULL,
  titolo VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agenda_blocchi_evento_giorno (google_event_id, data_blocco),
  INDEX idx_agenda_blocchi_data (data_blocco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS google_calendar_stato (
  id TINYINT UNSIGNED PRIMARY KEY,
  calendar_id VARCHAR(255) NOT NULL,
  sync_token VARCHAR(1024) NULL,
  access_token TEXT NULL,
  access_token_expires_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
