-- Eseguire una sola volta sui database creati con una versione precedente dello schema.
ALTER TABLE clienti
  ADD COLUMN ragione_sociale VARCHAR(255) NULL AFTER cognome,
  ADD COLUMN codice_destinatario VARCHAR(7) NULL AFTER partita_iva,
  ADD COLUMN pec VARCHAR(255) NULL AFTER codice_destinatario;

ALTER TABLE appuntamenti
  ADD COLUMN metodo_pagamento ENUM('studio','online') NOT NULL DEFAULT 'studio' AFTER stato,
  ADD COLUMN stato_pagamento ENUM('da_saldare','in_attesa','pagato','fallito','rimborsato') NOT NULL DEFAULT 'da_saldare' AFTER metodo_pagamento;