START TRANSACTION;

INSERT INTO professionisti (nome, cognome, email)
SELECT 'Nuttiporn', 'Sriboust', 'info@thaitikmassage.it'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM professionisti
  WHERE nome = 'Nuttiporn' AND cognome = 'Sriboust' AND email = 'info@thaitikmassage.it'
);

SET @professionista_id = (
  SELECT id FROM professionisti
  WHERE nome = 'Nuttiporn' AND cognome = 'Sriboust' AND email = 'info@thaitikmassage.it'
  ORDER BY id LIMIT 1
);

INSERT INTO studi (nome, indirizzo, citta, cap, email_studio, id_professionista)
SELECT 'Studio Prati', 'Via Giuseppe Gioachino Belli 1', 'Roma', '00193', 'info@thaitikmassage.it', @professionista_id
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM studi WHERE nome = 'Studio Prati');

INSERT INTO studi (nome, indirizzo, citta, cap, email_studio, id_professionista)
SELECT 'APS Studio Montesacro', 'Via Valle Borbera 49/50', 'Roma', NULL, 'info@thaitikmassage.it', @professionista_id
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM studi WHERE nome = 'APS Studio Montesacro');

SET @studio_prati_id = (SELECT id FROM studi WHERE nome = 'Studio Prati' ORDER BY id LIMIT 1);
SET @studio_montesacro_id = (SELECT id FROM studi WHERE nome = 'APS Studio Montesacro' ORDER BY id LIMIT 1);

INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Massaggio Thai tradizionale', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Massaggio Thai tradizionale');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Massaggio sportivo', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Massaggio sportivo');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Aromaterapia', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Aromaterapia');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Tok Sen', 60, 70.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Tok Sen');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Foot Massage Express', 30, 35.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Foot Massage Express');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_prati_id, 'Foot Massage Completo', 60, 50.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_prati_id AND nome = 'Foot Massage Completo');

INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Massaggio Thai tradizionale', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Massaggio Thai tradizionale');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Massaggio sportivo', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Massaggio sportivo');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Aromaterapia', 60, 60.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Aromaterapia');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Tok Sen', 60, 70.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Tok Sen');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Foot Massage Express', 30, 35.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Foot Massage Express');
INSERT INTO servizi (id_studio, nome, durata_minuti, prezzo)
SELECT @studio_montesacro_id, 'Foot Massage Completo', 60, 50.00 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM servizi WHERE id_studio = @studio_montesacro_id AND nome = 'Foot Massage Completo');

COMMIT;
