START TRANSACTION;

INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo, iva_percentuale, attivo)
SELECT base.id_studio, 'Aromaterapia', 90, 80.00, MAX(base.iva_percentuale), MAX(base.attivo)
FROM servizi base
WHERE base.nome = 'Aromaterapia'
  AND base.durata_minuti = 60
  AND NOT EXISTS (
    SELECT 1
    FROM servizi existing
    WHERE existing.id_studio = base.id_studio
      AND existing.nome = 'Aromaterapia'
      AND existing.durata_minuti = 90
  )
GROUP BY base.id_studio;

INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo, iva_percentuale, attivo)
SELECT base.id_studio, 'Aromaterapia', 120, 100.00, MAX(base.iva_percentuale), MAX(base.attivo)
FROM servizi base
WHERE base.nome = 'Aromaterapia'
  AND base.durata_minuti = 60
  AND NOT EXISTS (
    SELECT 1
    FROM servizi existing
    WHERE existing.id_studio = base.id_studio
      AND existing.nome = 'Aromaterapia'
      AND existing.durata_minuti = 120
  )
GROUP BY base.id_studio;

UPDATE servizi
SET prezzo = CASE durata_minuti
  WHEN 60 THEN 60.00
  WHEN 90 THEN 80.00
  WHEN 120 THEN 100.00
END
WHERE nome = 'Aromaterapia'
  AND durata_minuti IN (60, 90, 120);

COMMIT;
