CREATE TABLE IF NOT EXISTS orari_studio (
  id INT PRIMARY KEY AUTO_INCREMENT,
  id_studio INT NOT NULL,
  giorno_settimana TINYINT UNSIGNED NOT NULL COMMENT '1=lunedi, 7=domenica',
  ora_inizio TIME NOT NULL,
  ora_fine TIME NOT NULL,
  intervallo_minuti SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  attivo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_orari_studio_giorno (id_studio, giorno_settimana, attivo),
  CONSTRAINT fk_orari_studio_studio FOREIGN KEY (id_studio) REFERENCES studi(id) ON DELETE CASCADE,
  CONSTRAINT chk_orari_studio_giorno CHECK (giorno_settimana BETWEEN 1 AND 7),
  CONSTRAINT chk_orari_studio_intervallo CHECK (intervallo_minuti BETWEEN 5 AND 240),
  CONSTRAINT chk_orari_studio_orari CHECK (ora_inizio < ora_fine)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
