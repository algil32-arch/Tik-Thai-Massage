# Thai Tik Massage — Site Ver 6.0

## Rilascio

Versione: 6.0
Data: 2026-10-06

## Novità

- Aggiunta nell'area admin la scheda per inserire manualmente bozze per i clienti ricevuti in studio.
- Raccolta dei dati anagrafici e fiscali di cliente e fornitore, inclusi indirizzi e province, con codice destinatario convenzionale `0000000` per i privati.
- Selezione del regime forfettario RF19 (IVA 0%, natura N2.2) o ordinario RF01 (IVA 22%).
- Calcolo e registrazione facoltativa della rivalsa INPS al 4%; gestione del bollo virtuale da 2 euro per le bozze forfettarie oltre 77,47 euro, a carico del professionista o addebitato al cliente.
- Registrazione di TD01, data di pagamento, data della prestazione, riga di dettaglio, aliquota, natura IVA, bollo e dicitura forfettaria nelle note.
- Il registro fatture riporta il regime/documento, la natura IVA, l'eventuale rivalsa e il bollo.
- Accesso admin richiesto per il registro fatture e protezione CSRF sul modulo manuale.
- Aggiunta la migrazione `backend-aruba-fatturazione-fiscale.sql` per i database esistenti e aggiornato lo schema per le nuove installazioni.
- Inserita la partita IVA 18730391002 nel footer delle pagine pubbliche.

## Installazione e limiti

- Per un database già esistente, eseguire una sola volta `backend-aruba-fatturazione-fiscale.sql`. Non eseguire lo schema completo su un database già popolato.
- Caricare le versioni aggiornate di `fattura-manuale.php` e `fatture-admin.php`; per i collegamenti dell'area admin usare anche le versioni aggiornate di `area-admin.html` e `booking-admin-advanced.php`.
- La funzione salva bozze con numerazione provvisoria `BOZZA-...`; non crea un file FatturaPA definitivo, non assegna la numerazione fiscale e non invia documenti allo SDI.
- La scadenza di 12 giorni è mostrata come indicazione a partire dalla data di pagamento inserita. Regime, diciture, aliquote, bollo e rivalsa vanno verificati con il commercialista per la situazione concreta.
- Verificata la sintassi PHP e JavaScript dei file aggiornati, la coerenza delle colonne e i 31 controlli automatici dell'agenda. Il salvataggio su MySQL e l'integrazione Aruba/SDI richiedono test sull'hosting configurato e non sono stati collaudati qui.
