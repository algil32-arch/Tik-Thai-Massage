-- Aggiorna il CAP mancante senza sovrascrivere valori già configurati.
UPDATE studi SET cap = '00141'
WHERE nome = 'APS Studio Montesacro' AND (cap IS NULL OR TRIM(cap) = '');
