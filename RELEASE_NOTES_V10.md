# Thai Tik Massage — Site Ver 10.0

## Rilascio

Versione: 10.0
Data: 2026-10-14

## Novità

- Listino completo in PDF A4, con intestazione e immagine di sfondo semitrasparente, disponibile dal menu a tendina Trattamenti con la voce “Listino completo massaggi”.
- Pacchetti da 5 e 10 sedute nella home, nelle cinque pagine dei massaggi e nel PDF. Totali e prezzi per seduta coerenti, arrotondati all'euro superiore per i pacchetti, senza percentuali o spiegazioni commerciali.
- Pausa minima fra appuntamenti portata da 5 a 15 minuti, sia nel calendario delle disponibilità sia nei controlli di prenotazione.
- Box Voucher regalo nella pagina Prenota: destinatario, trattamento, formula singola o pacchetto, quantità e totale calcolato dal server.
- Anteprima stampabile e PDF chiaramente marcati non validi/non pagati; voucher definitivo emesso solo dopo verifica del pagamento SumUp, con codice univoco e validità di 12 mesi.
- Acquisto voucher separato dalle prenotazioni, riservato a Studio Prati; APS Jaqueline esclusa.
- Prenotazione dal normale calendario con codice voucher, controllo di trattamento, durata, scadenza e sedute residue, senza un nuovo pagamento.
- Registrazione dell'appuntamento e decremento di una seduta nella stessa transazione; riferimento univoco per evitare doppi addebiti negli invii duplicati.
- Registro admin dei voucher con ricerca per codice, verifica pagamenti, dati acquirente, sedute residue e appuntamenti collegati.
- Annullamento dall'agenda admin con restituzione automatica della seduta una sola volta, senza prorogare la scadenza.
- Notifiche e documenti aggiornati con le modalità di utilizzo; la seduta coperta dal voucher non propone una seconda fattura dal pulsante dell'agenda.

## Installazione su Aruba

1. Eseguire un backup dei file e del database.
2. Importare [backend-aruba-vouchers.sql](backend-aruba-vouchers.sql) per creare le tabelle dei voucher, se non ancora installate.
3. Importare [backend-aruba-voucher-bookings.sql](backend-aruba-voucher-bookings.sql), anche sui database nuovi, per abilitare il metodo voucher e i collegamenti agli appuntamenti.
4. Pubblicare i file aggiornati indicati nella [guida backend](php-backend-readme.md#voucher-regalo), oltre alle pagine, agli stili condivisi e al listino PDF modificati.
5. Conservare la configurazione privata esistente. Non lasciare SQL o archivi ZIP nella cartella pubblica.
6. Collaudare su Aruba prima delle vendite reali: acquisto, verifica pagamento, prenotazione, doppio invio, ultima seduta concorrente, annullamento ripetuto e notifiche.

Non importare lo schema completo su un database già popolato. Le migrazioni richiedono le tabelle e le migrazioni di prenotazione/SumUp già documentate.

## Verifiche e limiti

- Superati 131 controlli PHP voucher, 123 controlli di prenotazione voucher con PDO simulato e 38 controlli dell'agenda.
- Superati i quattro test Node di coerenza prezzi browser/server e pacchetti home/schede.
- Verificate sintassi PHP/JavaScript, diagnostica editor, anteprima PDF e flusso browser simulato con verifica codice, errore di rete e retry senza cambio del riferimento.
- Verificati il riepilogo senza nuovo pagamento, l'esclusione APS Jaqueline e il mantenimento dell'opzione SumUp.
- Nessun addebito reale eseguito. Transazioni e concorrenza MySQL, SumUp reale e consegna email devono ancora essere collaudati sull'hosting configurato.
- I checkout abbandonati dei voucher si riconciliano dal registro admin; non è previsto un webhook o cron automatico.
- La fatturazione dell'acquisto resta manuale. Trattamento fiscale, condizioni di vendita/recesso e conservazione dei dati richiedono verifica professionale.
- Questo rilascio Git non costituisce pubblicazione su Aruba.
