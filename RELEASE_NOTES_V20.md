# Thai Tik Massage - Site Ver 20.0

## Rilascio

Versione: 20.0

Data: 2026-11-08

Aggiornamento rispetto a Site Ver 19.0.

## Novita

- Sostituito il link breve WhatsApp con il collegamento diretto al numero
  aziendale, uniformando intestazioni, pulsanti e materiali promozionali.
- La sincronizzazione tratta una cancellazione Google gia completata
  (HTTP 404/410 su DELETE) come idempotente, senza bloccare il cron.
- Corretto il recupero dei token di sincronizzazione Google scaduti: viene
  eseguita una lettura completa compatibile con l'API e il token precedente
  viene azzerato prima del recupero.
- Se un evento Google collegato a una prenotazione del sito viene eliminato,
  la prenotazione viene annullata sul sito; l'eventuale seduta voucher viene
  ripristinata e lo slot torna disponibile.

## Pubblicazione su Aruba

Eseguire prima un backup dei file. Caricare nella root del sito, sovrascrivendo
le versioni precedenti:

- Le pagine HTML con il contatto WhatsApp aggiornato: `index.html`,
  `prenota.html`, `prenota-massaggio.html`, `prenota-voucher.html`,
  `dove-trovarmi.html`, `centro-prati.html`, `centro-monte-sacro.html`,
  `massaggio-aromaterapia.html`, `massaggio-sportivo.html`,
  `massaggio-thai-tradizionale.html`, `foot-massage.html`, `tok-sen.html`
  e `storia-massaggio-thai.html`.
- `google-calendar-client.php` e `google-calendar-sync.php`.
- `volantino-a6.html` solo se viene pubblicato anche il volantino aggiornato.

`genera-volantino-canva.js` serve solo a rigenerare il materiale Canva e non va
caricato per il funzionamento del sito. Non occorrono modifiche al database o
alla configurazione privata. Lasciare attivo il cron Google Calendar ogni
10 minuti e non pubblicare token o credenziali.

## Verifiche

```bash
php tests/google-calendar-client-test.php
php tests/google-calendar-test.php
php tests/booking-slots-test.php
php tests/booking-voucher-test.php
git diff --check
```

Dopo la pubblicazione, richiamare una volta il cron autenticato e verificare
`"success": true`. Per provare la modifica di disponibilita, eliminare o
spostare un impegno Google e ricaricare gli slot sul sito.
