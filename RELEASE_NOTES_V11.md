# Thai Tik Massage — Site Ver 11.0

## Rilascio

Versione: 11.0
Data: 2026-10-14

## Novità

- La pagina Prenota è stata semplificata e ora introduce le modalità online, spiegando le differenze tra Studio Prati e APS Jaqueline.
- Separati i percorsi: `prenota.html` per scegliere cosa fare, `prenota-massaggio.html` per prenotare una seduta e `prenota-voucher.html` per acquistare un voucher regalo.
- Aggiornati i pulsanti di prenotazione della home e della pagina Foot Massage; i vecchi collegamenti `prenota.html` con parametri continuano a reindirizzare al servizio selezionato.
- Ripristinato il caricamento dinamico di sedi, trattamenti e orari nella pagina dedicata ai massaggi.
- Il catalogo propone partenze ogni 15 minuti, applicando la durata reale del servizio, gli orari di apertura e un buffer di almeno 15 minuti.
- Il controllo di disponibilità considera appuntamenti e pagamenti online in sospeso in tutte le sedi della stessa professionista, anche al momento del salvataggio.
- Dopo che Google Calendar accetta un evento, la prenotazione passa da `in_attesa` a `confermato` e il cliente riceve una seconda email di conferma. Se la sincronizzazione non riesce, l'appuntamento resta in attesa.
- La risposta del cron Google indica appuntamenti confermati e email di conferma riuscite/non riuscite; un errore di invio email richiede verifica manuale.

## Pubblicazione su Aruba

1. Eseguire un backup dei file e del database.
2. Pubblicare le pagine aggiornate: `index.html`, `foot-massage.html`, `prenota.html`, `prenota-massaggio.html` e `prenota-voucher.html`.
3. Pubblicare gli script aggiornati: `booking-admin.php`, `booking-mailer.php`, `booking-slots.php`, `gift-voucher.js`, `google-calendar-sync.php`, `php-booking-backend.php` e `sumup-intents.php`.
4. Conservare l'attuale configurazione privata e verificare che il cron Google sia attivo ogni 10 minuti e che `MAIL_FROM_EMAIL` e `BOOKING_NOTIFICATION_EMAIL` siano configurati.
5. Non serve una migrazione SQL. Pubblicare anche gli aggiornamenti documentali `README.md` e `php-backend-readme.md` se si mantengono le guide sul server.
6. Collaudare sul dominio una prenotazione per ciascuna sede, la disponibilità incrociata fra sedi, l'inserimento Google e la mail di conferma. Verificare l'esito del cron e i log in caso di mancato invio.

Non caricare archivi ZIP o configurazioni private nella cartella pubblica. Questo rilascio Git non equivale alla pubblicazione su Aruba.

## Verifiche e limiti

- Superati 38 controlli sulla disponibilità a intervalli di 15 minuti e buffer.
- Superati 123 controlli voucher-prenotazione con PDO simulato e 131 controlli voucher.
- Superati 13 test Node relativi a pagine, consenso, voucher e pacchetti.
- Verificate sintassi PHP e JavaScript e `git diff --check`.
- La sincronizzazione Google, la consegna reale delle email e la concorrenza MySQL devono essere verificate sull'hosting configurato.
