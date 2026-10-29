-- Tracciamento delle email inviate dopo la conferma su Google Calendar.
-- Eseguire una sola volta sui database gia aggiornati con la sincronizzazione Google.

ALTER TABLE appuntamenti
  ADD COLUMN conferma_email_inviata_at DATETIME NULL AFTER google_synced_at;

-- Non reinviare email per appuntamenti gia sincronizzati prima di questo aggiornamento.
UPDATE appuntamenti
SET conferma_email_inviata_at = COALESCE(google_synced_at, NOW())
WHERE google_event_id IS NOT NULL AND stato = 'confermato';
