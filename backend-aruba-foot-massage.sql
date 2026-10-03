INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT studio.id, 'Foot Massage Express', 30, 35.00
FROM studi AS studio
WHERE studio.attivo = 1
  AND NOT EXISTS (
    SELECT 1
    FROM servizi AS servizio
    WHERE servizio.id_studio = studio.id
      AND servizio.nome = 'Foot Massage Express'
  );

INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT studio.id, 'Foot Massage Completo', 60, 50.00
FROM studi AS studio
WHERE studio.attivo = 1
  AND NOT EXISTS (
    SELECT 1
    FROM servizi AS servizio
    WHERE servizio.id_studio = studio.id
      AND servizio.nome = 'Foot Massage Completo'
  );