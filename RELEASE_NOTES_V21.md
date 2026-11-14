# Thai Tik Massage - Site Ver 21.0

## Rilascio

Versione: 21.0

Data: 2026-11-14

Aggiornamento rispetto a Site Ver 20.0.

## Novita

- Emissione manuale dall'area amministrativa dei voucher pagati al centro,
  con codice per prenotare, prezzo modificabile e non riportato nel PDF.
- Prenotazioni con voucher manuale senza dati di fatturazione e email
  facoltativa per tutte le prenotazioni con voucher.
- Annullamento delle prenotazioni voucher dall'area admin oppure dal link
  nelle email, fino a tre ore prima, con restituzione della seduta e
  sincronizzazione dell'agenda.
- Eliminazione dei voucher manuali senza utilizzi e disattivazione dei
  voucher con storico o acquistati online, conservando i dati.
- Email delle prenotazioni voucher con codice e sedute residue, senza il
  prezzo di una seduta acquistata singolarmente.
- Voucher stampabile con logo, dedica, sede completa e istruzioni di
  prenotazione. Per i nuovi voucher si sceglie Sig. o Sig.ra; quelli gia
  emessi usano una formula neutra.

## Pubblicazione su Aruba

Eseguire prima un backup dei file e del database. Il database deve avere le
migrazioni voucher di base `backend-aruba-vouchers.sql` e
`backend-aruba-voucher-bookings.sql`.

Prima di caricare i file aggiornati, controllare le colonne di `gift_vouchers`
in phpMyAdmin ed eseguire le migrazioni seguenti **una sola volta**, solo se
la relativa colonna non esiste gia:

1. `backend-aruba-voucher-manuali.sql`, per `source`.
2. `backend-aruba-voucher-disattivazione.sql`, per `disabled_at`.
3. `backend-aruba-voucher-appellativo.sql`, per `recipient_title`.

`backend-aruba-voucher-indirizzi.sql` completa il CAP di Monte Sacro solo se
mancante. Verificare indirizzo, citta e CAP di entrambe le sedi nel database.

Caricare nella root del sito:

- `area-admin.php`, `vouchers-admin.php`, `booking-admin-advanced.php`.
- `voucher-manual.php`, `voucher-manual-admin.js`,
  `voucher-admin-actions.php`, `annulla-voucher.php`.
- `booking-mailer.php`, `booking-voucher.php`, `google-calendar-sync.php`,
  `php-booking-backend.php`.
- `prenota-massaggio.html`, `prenota-voucher.html`, `gift-voucher.js`,
  `gift-voucher.css`.
- `voucher.php`, `voucher-model.php`, `voucher-store.php`, `voucher-pdf.php`.

Per il logo PDF verificare che siano presenti `fattura-cortesia-renderer.php`
e `invoice-template-logo.jpg` con `invoice-template-logo-mask.deflate`;
in alternativa viene usato `thai-tik-logo-alternativo.png`. Non caricare
credenziali, test, backup o documenti personali. Git non pubblica su Aruba.

## Verifiche

Provare l'emissione manuale e online, il PDF, la prenotazione con e senza
email e l'annullamento con restituzione della seduta. Verificare email reali,
agenda e sincronizzazione Google Calendar nell'ambiente Aruba.
