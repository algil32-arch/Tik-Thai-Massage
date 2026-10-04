# Thai Tik Massage — Site Ver 5.1

## Rilascio

Versione: 5.1  
Data: 2026-10-04

## Novità

- Il modulo prenotazioni richiede i dati fiscali anche per il pagamento in studio.
- Telefono facoltativo per ricontattare il cliente via WhatsApp o SMS.
- Campi condizionali per privati e aziende/professionisti: codice fiscale oppure ragione sociale, partita IVA e recapito SDI (codice destinatario o PEC), oltre all'indirizzo di fatturazione.
- Scelta iniziale tra pagamento in studio e online; il checkout online resta disabilitato finché SumUp non è configurato.
- Il backend salva i dati fiscali e di contatto del cliente e registra modalità e stato del pagamento.
- L'area admin mostra modalità di pagamento, stato e dati fiscali del cliente.
- Aggiunta la migrazione `backend-aruba-prenotazioni-fiscali.sql` per database già esistenti.

## Verifiche e limiti

- Verificati nel browser i campi privato/azienda, il telefono facoltativo e il blocco della modalità SumUp non configurata.
- Controllati gli errori statici dei file aggiornati.
- La persistenza PHP/MySQL non è stata collaudata in locale: richiede PHP e il database Aruba configurato.
- Prima della pubblicazione, verificare con un consulente i dati da raccogliere, l'informativa privacy e gli obblighi di fatturazione elettronica.