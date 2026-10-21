# Thai Tik Massage - Site Ver 16.0

## Rilascio

Versione: 16.0

Data: 2026-10-22

Aggiornamento rispetto a Site Ver 15.0.

## Novita

- Uniformata la grafia pubblica a **Monte Sacro** nei testi, nei metadati SEO, nei voucher e nel volantino. Conservati i nomi tecnici del database e gli identificativi esistenti per compatibilita.
- Create due pagine reali per i centri partner: `centro-prati.html` e `centro-monte-sacro.html`, con indirizzi, informazioni sui trattamenti, accesso e collegamenti al calendario con sede preselezionata.
- Mantenuta la distinzione fra l'attivita professionale di Tik e APS Jaqueline, inclusi iscrizione associativa, pagamenti e documentazione fiscale.
- Aggiunta la tendina **Dove trovarmi** a tutti i 13 menu pubblici condivisi, con riepilogo delle sedi e collegamenti ai due centri. Navigazione disponibile anche senza JavaScript.
- Incorporata una mappa specifica in ciascuna pagina centro, caricata solo dopo il consenso a Google Maps. La pagina riepilogativa conserva entrambe le mappe.
- Estratto in `studio-hours.js` il caricamento degli orari dall'agenda, condiviso dalle pagine dei centri e dal riepilogo. Errori e dati non disponibili sono segnalati senza mostrare orari inventati.
- Uniformati i canonici pubblici su `https://www.thaitikmassage.it/`, coerentemente con il reindirizzamento esistente dal dominio senza `www`.
- Aggiornati i dati strutturati con due sedi `LocalBusiness`, identificativi distinti e logo reale. Attivita professionale rappresentata come `Organization` e trattamenti come `Service`, senza dichiarare i centri partner filiali di proprieta.
- Uniformati gli indirizzi delle sedi e il CAP 00141 di Monte Sacro; corretta la mappa di Via Valle Borbera al civico 51.
- Aggiornati collegamenti interni, footer e sitemap. L'alias `mappa-sedi.html` resta accessibile ma rimanda al canonico `dove-trovarmi.html` ed e escluso dalla sitemap.
- Rigenerato il volantino in PDF A6 a due pagine e in formato PowerPoint importabile in Canva.

## File da pubblicare su Aruba

Caricare nella root del sito, conservando i nomi e sovrascrivendo le versioni precedenti:

- Nuove pagine: `centro-prati.html`, `centro-monte-sacro.html`.
- Pagine aggiornate: `index.html`, `dove-trovarmi.html`, `mappa-sedi.html`, `foot-massage.html`, `massaggio-aromaterapia.html`, `massaggio-sportivo.html`, `massaggio-thai-tradizionale.html`, `tok-sen.html`, `storia-massaggio-thai.html`, `prenota.html`, `prenota-massaggio.html`, `prenota-voucher.html`, `listino-trattamenti.html`, `documenti-legali-premium.html`.
- Stili e script: `details.css`, `studio-hours.js` (nuovo), `gift-voucher.js`.
- Backend voucher: `voucher-pdf.php`, `voucher-store.php`.
- Sitemap: `sitemap.xml`.
- Volantino: `volantino-a6.html`, `volantino-a6.pdf`; `volantino-a6-canva.pptx` solo se distribuito tramite il sito.

Restano necessari i CSS condivisi, gli script di consenso e il logo gia presenti sul server. Non sono richieste migrazioni SQL o modifiche alle configurazioni Aruba.

Non caricare test, documenti personali, backup ZIP o strumenti di generazione. Non sovrascrivere configurazioni private, credenziali, fatture e documenti prodotti online. Eseguire un backup prima della pubblicazione e non eliminare i file remoti assenti in locale.

## Verifiche

- Test mirati SEO, canonici, dati strutturati, navigazione, selezione della sede, consenso e caricamento degli orari.
- Test JavaScript e PHP dei voucher, inclusa la grafia della sede nei PDF e il mantenimento dell'avviso APS.
- Verificate le pagine dei centri a larghezze desktop e mobile; verificata la tendina anche da tastiera e a 320 px.
- Verificati formato A6 a due pagine e grafia del volantino.

Comandi di controllo:

```bash
node --test --test-skip-pattern='all HTML pages define' tests/seo.test.cjs tests/studio-hours.test.cjs tests/booking-pages.test.cjs tests/cookie-consent.test.cjs tests/gift-voucher.test.cjs
php tests/voucher-test.php
php -l voucher-pdf.php
php -l voucher-store.php
git diff --check
```

Problema preesistente non modificato: il controllo generale delle meta description segnala `area-admin.html`, un reindirizzamento amministrativo con `noindex`, privo di descrizione. Il controllo viene escluso dal comando mirato sopra; non si dichiara superata l'intera suite SEO.

Gli orari effettivi richiedono il backend PHP/MySQL configurato sull'hosting. La pubblicazione Git non aggiorna automaticamente Aruba e non garantisce l'indicizzazione su Google.
