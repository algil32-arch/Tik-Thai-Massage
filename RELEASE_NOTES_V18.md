# Thai Tik Massage - Site Ver 18.0

## Rilascio

Versione: 18.0

Data: 2026-10-22

Aggiornamento rispetto a Site Ver 17.0.

## Novita

- Aggiunti titoli descrittivi ai link della home e ai link informativi
  generati dal consenso cookie, senza cambiare destinazioni o testi visibili.
  Per pubblicare questa correzione caricare `index.html` e `cookie-consent.js`.

- Aggiunte favicon PNG e ICO a 64x64 derivate dal logo ufficiale e dichiarate
  nell'head di tutte le pagine HTML, incluse pagine pubbliche e alias.

- Aggiunto il webhook per ricevere notifiche Google Calendar e aggiornare gli
  impegni che bloccano gli slot del sito senza attendere il cron periodico.
- Registrazione e rinnovo dei canali Google tramite il cron esistente, con
  autenticazione per canale, segreti salvati solo come hash e controllo della
  risorsa notificata. Le notifiche iniziali non generano esportazioni o email.
- Sincronizzazione immediata dopo conferme, annullamenti, eliminazioni consentite
  e aggiunta/rimozione delle fasce settimanali nell'admin; esito mostrato dopo
  il salvataggio, senza perdere modifiche se Google non risponde.
- Conservato il flusso prenotazione, prima email di ricezione, inserimento su
  Google e successiva conferma. Le prenotazioni online avviano il sync solo
  dopo la verifica del pagamento.
- Lock MySQL condiviso fra cron, webhook e prenotazioni per serializzare le
  sincronizzazioni concorrenti.
- Impedita l'eliminazione definitiva di appuntamenti ancora collegati a un
  evento Google: prima occorre annullarli e sincronizzare la rimozione.
- Disabilitata la cache HTTP per disponibilita e risposte cron.
- Corretto il controllo che tentava il sync senza un ID calendario configurato.
- Corrette al 22 ottobre 2026 le date `lastmod` della home, di `prenota.html`
  e delle due pagine dei centri, modificate nella versione 17.0. Gli endpoint
  PHP della sincronizzazione restano esclusi dalla sitemap.

## Pubblicazione su Aruba

Eseguire prima un backup di file e database. Caricare nella root questi otto
file, conservando i nomi e sovrascrivendo le versioni precedenti dove presenti:

- `app-config.php`
- `booking-admin.php`
- `booking-admin-advanced.php`
- `cron-calendar-sync.php`
- `google-calendar-sync.php`
- `php-booking-backend.php`
- `google-calendar-push.php` (nuovo)
- `google-calendar-webhook.php` (nuovo)

Restano necessarie le versioni correnti di `google-calendar-client.php`,
`sumup-intents.php`, `sumup-payment.php` e `booking-mailer.php`, non modificate
da questo rilascio. Non occorrono aggiornamenti HTML.

Pubblicare anche `sitemap.xml` nella root per aggiornare le date SEO delle
pagine pubbliche. Non occorrono modifiche a `robots.txt`.

Per la favicon pubblicare nella root `favicon.ico`, `favicon.png` e tutti
gli HTML aggiornati con i riferimenti alle icone. Le icone devono essere
accessibili ai crawler; una modifica della sola favicon non richiede di
aggiornare tutte le date `lastmod` della sitemap.

Nel database gia usato dal sito importare `backend-aruba-google-push.sql`:
crea soltanto `google_calendar_canali`, senza modificare dati esistenti.
Le precedenti migrazioni Google Calendar devono essere gia presenti.

Nel file privato **del server**, senza sostituire credenziali o token esistenti,
aggiungere dentro l'array:

```php
'GOOGLE_CALENDAR_WEBHOOK_URL' => 'https://www.thaitikmassage.it/google-calendar-webhook.php',
```

L'endpoint deve essere pubblico via HTTPS valido, senza login o redirect.
Eseguire una volta il cron con il token esistente e verificare
`notifiche_google.enabled: true` e `renewed: true` alla prima attivazione.
Lasciare il cron attivo ogni 10 minuti per rinnovo e recupero notifiche perse:
Google non garantisce la consegna di ogni notifica.

Non caricare configurazioni locali, credenziali, test, documenti personali,
backup ZIP o documentazione tecnica sul sito. Git non pubblica su Aruba.

## Verifiche

- Test isolati per registrazione e riuso dei canali, errori e rilascio lock,
  validazione delle notifiche e risposte dell'endpoint.
- Test della creazione, spostamento e cancellazione degli impegni Google e
  dell'effetto sugli slot, esportazione e annullamento prenotazioni, conferma
  email senza reinvio nei cicli successivi e messaggi admin.
- Regressioni su slot, voucher, autenticazione e pagine prenotazione.

```bash
php tests/google-calendar-test.php
php tests/booking-slots-test.php
php tests/booking-voucher-test.php
php tests/admin-auth-test.php
node --test tests/booking-pages.test.cjs
git diff --check
```

I test locali simulano API Google, email e PDO e non sostituiscono il collaudo
con MySQL e HTTPS dell'hosting. Dopo la pubblicazione e l'importazione SQL,
l'utente ha riferito esito positivo del funzionamento sul sito; la consegna
Google non e stata verificata direttamente dall'ambiente di sviluppo.

Per il collaudo creare, spostare ed eliminare un impegno manuale Google e
ricaricare gli slot senza invocare il cron a mano. Verificare anche prenotazione,
prima email, conferma e annullamento dall'admin. Gli appuntamenti originati dal
sito restano gestiti dal database: non spostarli direttamente su Google.
Le pagine gia aperte rileggono gli slot cambiando selezione o ricaricando;
non ricevono aggiornamenti push nel browser.
