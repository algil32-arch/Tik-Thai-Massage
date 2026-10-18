# Thai Tik Massage — Site Ver 14.0

## Rilascio

Versione: 14.0

Data: 2026-10-18

## Novita

- Riattivati pagamenti online e voucher regalo per le sedi con servizi attivi, inclusa la sede APS Jaqueline di Valle Borbera.
- Aggiornate le informazioni per la sede APS: i clienti che non sono ancora soci dovranno iscriversi all'associazione al primo appuntamento; tesseramento ed eventuale quota restano fuori dai pagamenti gestiti dal sito.
- Indicata la sede selezionata nei voucher e nelle relative comunicazioni.
- Corretto il collegamento delle fatture al professionista associato all'appuntamento, senza modificare il trattamento fiscale dei clienti privati.
- Ripristinato il tentativo immediato di sincronizzazione con Google Calendar dopo le prenotazioni non online e la registrazione dei pagamenti SumUp. Il cron periodico resta attivo come recupero e per sincronizzare gli impegni del calendario.
- Aggiunto il volantino promozionale A6 in formato HTML, PDF e PowerPoint importabile in Canva.

## File principali aggiornati

- Pagine di prenotazione e informazioni: `prenota.html`, `prenota-massaggio.html`, `prenota-voucher.html`, `dove-trovarmi.html`, `documenti-legali-premium.html`
- Prenotazioni, voucher e pagamenti: `php-booking-backend.php`, `gift-voucher.js`, `voucher.php`, `voucher-store.php`, `voucher-pdf.php`, `sumup-intents.php`, `sumup-payment.php`
- Sincronizzazione Google Calendar: `google-calendar-sync.php`
- Fatturazione: `fattura-da-pagamento.php`, `invoice-generator.php`, `fattura-pa-generator.php`
- Documentazione e test: `README.md`, `php-backend-readme.md`, `tests/booking-pages.test.cjs`, `tests/voucher-test.php`
- Volantino: `volantino-a6.html`, `volantino-a6.pdf`, `volantino-a6-canva.pptx`, `genera-volantino-canva.js`

## Pubblicazione su Aruba

Pubblicare i file applicativi e le pagine aggiornate elencati sopra. La sincronizzazione Google Calendar richiede anche `google-calendar-client.php`, `cron-calendar-sync.php` e la configurazione privata già presente sull'hosting. Mantenere attivo il cron ogni 10 minuti: il tentativo immediato non lo sostituisce. Non è richiesta una nuova migrazione SQL per questo rilascio.

Il rilascio Git non pubblica automaticamente gli aggiornamenti sul server Aruba.
