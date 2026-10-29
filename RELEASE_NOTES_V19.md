# Thai Tik Massage - Site Ver 19.0

## Rilascio

Versione: 19.0

Data: 2026-10-29

Aggiornamento rispetto a Site Ver 18.0.

## Novita

- La email di conferma dell'appuntamento, inviata al cliente dopo
  l'inserimento riuscito su Google Calendar, ora arriva anche in copia (Cc)
  a `BOOKING_NOTIFICATION_EMAIL` (`info@thaitikmassage.it`). La notifica
  iniziale della richiesta allo studio resta invariata.
- Le conferme non accettate dal server di posta Aruba non vanno piu perse:
  l'invio riuscito viene registrato in `appuntamenti.conferma_email_inviata_at`
  e gli invii falliti vengono ritentati al sync successivo (immediato, webhook
  o cron), senza ricreare l'evento Google e senza email duplicate.
- Il sync immediato dopo la prenotazione inserisce l'appuntamento su Google e
  invia la conferma anche quando la lettura degli impegni da Google fallisce.
  In precedenza un errore in lettura bloccava inserimento e conferma fino al
  cron. L'errore resta registrato in `google_calendar_stato.last_error` e nel
  log PHP.

## Pubblicazione su Aruba

Eseguire prima un backup di file e database. Ordine obbligatorio:

1. Nel database del sito importare `backend-aruba-booking-confirmation.sql`.
   Aggiunge la colonna `conferma_email_inviata_at` e marca come gia confermati
   gli appuntamenti gia presenti su Google, per evitare reinvii. I file della
   versione 18.0 continuano a funzionare con la nuova colonna.
2. Subito dopo caricare nella root, sovrascrivendo le versioni precedenti:
   - `google-calendar-sync.php`
   - `booking-mailer.php`

Non caricare i file prima della migrazione: il sync fallirebbe per la colonna
mancante e nessuna conferma verrebbe inviata. Non occorrono modifiche HTML,
alla sitemap o alla configurazione privata. Il cron ogni 10 minuti resta attivo
come recupero.

Non caricare configurazioni locali, credenziali, test, documenti personali,
backup ZIP o documentazione tecnica sul sito. Git non pubblica su Aruba.

## Verifiche

- Test della conferma non accettata, del retry al sync successivo senza nuovo
  evento Google e dell'assenza di reinvii dopo un invio riuscito.
- Test del sync con lettura Google in errore: l'appuntamento viene comunque
  esportato e confermato e l'errore viene registrato.
- Regressioni su slot.

```bash
php tests/google-calendar-test.php
php tests/booking-slots-test.php
git diff --check
```

Dopo la pubblicazione aprire
`https://www.thaitikmassage.it/cron-calendar-sync.php?token=IL_TUO_TOKEN` e
verificare `"success": true`. Con una prenotazione di prova controllare la
ricevuta iniziale su `info@`, l'evento su Google Calendar e la conferma al
cliente con copia su `info@`.
