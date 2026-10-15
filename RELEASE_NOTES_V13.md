# Thai Tik Massage — Site Ver 13.0

## Rilascio

Versione: 13.0

Data: 2026-10-15

## Novita

- Aggiornati listino, home e schede dei trattamenti: pacchetti da 3 e 6 sedute con prezzi e arrotondamenti allineati.
- Aggiunto il listino completo stampabile in A4, con una seconda pagina dedicata alle descrizioni dei massaggi.
- Aggiunta la scheda bilingue italiano/thai delle precauzioni prima del massaggio, anche in PDF.
- Aggiornato l'acquisto dei voucher alle formule correnti da 3 e 6 sedute; i voucher da 5 e 10 sedute gia' emessi restano validi fino alla scadenza.
- Unificata la creazione delle fatture da Gestione fatture e dall'admin appuntamenti, con generazione e registrazione di PDF, copia di cortesia e XML.
- Introdotto il progressivo annuale autonomo `TS-AAAA-NNNNN`. Il numero puo' essere modificato anche dopo il pagamento, se la fattura non e' stata inviata allo SDI; PDF, copia di cortesia e XML vengono aggiornati insieme.
- Migliorati i dati fiscali del professionista e del cliente e la coerenza del tracciato XML FatturaPA, inclusa la generazione per clienti identificati dal solo codice fiscale.
- Aggiunta la rigenerazione dei PDF e l'eliminazione amministrativa protetta di fatture e appuntamenti, con controlli sui pagamenti e sulle relazioni collegate.

## File principali aggiornati

- Pagine del sito, listino e schede trattamenti.
- `listino-trattamenti.html`, `listino-trattamenti.pdf`
- `controindicazioni-massaggio-thai.html`, `controindicazioni-massaggio-thai.pdf`
- `prenota-voucher.html`, `gift-voucher.js`, `voucher-model.php`
- `booking-admin-advanced.php`
- `fattura-da-pagamento.php`, `fattura-pa-generator.php`, `invoice-generator.php`
- `fattura-numerazione.php`, `fattura-xml-builder.php`, `fattura-cortesia-renderer.php`
- `fatture-admin.php`, `fatture-export-lotto.php`
- Asset del logo fattura e documentazione backend

## Pubblicazione su Aruba

1. Eseguire il backup di file e database.
2. Pubblicare i file PHP aggiornati e gli asset `invoice-template-logo.jpg` e `invoice-template-logo-mask.deflate`.
3. Pubblicare le pagine e gli asset statici aggiornati, inclusi `listino-trattamenti.html`, `listino-trattamenti.pdf`, `controindicazioni-massaggio-thai.html` e `controindicazioni-massaggio-thai.pdf`.
4. Verificare i permessi di scrittura nelle cartelle `documents/fatture/pdf`, `documents/fatture/xml` e `documents/fatture/copie`.
5. Verificare una fattura non pagata e una pagata non ancora inviata: cambiare il numero e controllare che PDF originale, copia di cortesia e XML riportino il nuovo progressivo. Non modificare fatture inviate o annullate.
6. Verificare la creazione fattura dall'admin appuntamenti, il download dei documenti e l'export lotto per Aruba.
7. Verificare i pacchetti voucher e la validita' dei voucher storici.

Questo rilascio Git non pubblica automaticamente i file sul server Aruba.
