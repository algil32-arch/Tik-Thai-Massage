-- Importare dopo backend-aruba-google-calendar.sql, anche sui database nuovi.
CREATE TABLE IF NOT EXISTS google_calendar_canali (
  id VARCHAR(64) PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  calendar_id VARCHAR(255) NOT NULL,
  callback_url VARCHAR(2048) NOT NULL,
  resource_id VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  INDEX idx_google_canali_scadenza (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
