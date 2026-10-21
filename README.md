# Thai Tik Massage — Site Ver 16.0

Sito statico multipagina per Thai Tik Massage, con backend PHP/MySQL predisposto per l'hosting Aruba.

Note del rilascio corrente: [Site Ver 16.0](RELEASE_NOTES_V16.md).

La sitemap pubblica è in `sitemap.xml`; `robots.txt` indica ai motori di ricerca
le aree non pubbliche e il percorso della sitemap. Dopo aver pubblicato questi
file nella root del dominio, inviare la sitemap da Google Search Console.

Nei testi pubblici, nei metadati SEO e nei documenti usare la grafia **Monte Sacro**.
Il nome storico `APS Studio Montesacro` resta una chiave interna per il database
e il caricamento degli orari; nei voucher viene mostrato con la grafia separata.
Verifica della grafia nelle pagine: `node --test tests/seo.test.cjs`.

## Centri partner e dati strutturati

Le pagine `centro-prati.html` e `centro-monte-sacro.html` descrivono i due centri
partner, con indirizzi, modalita' di accesso e collegamenti al calendario della
sede. L'attivita' professionale di Tik presso APS Jaqueline resta distinta
dall'associazione e dal suo tesseramento.
Il menu superiore "Dove trovarmi" usa la stessa tendina nativa di "Trattamenti":
contiene il riepilogo delle sedi e i collegamenti ai due centri partner.
Il menu funziona anche senza JavaScript e usa gli stili esistenti di `header.css`.

La versione canonica e' `https://www.thaitikmassage.it/`, gia' destinazione del
reindirizzamento dal dominio senza `www`. Ogni pagina pubblica dichiara il proprio
canonico; `mappa-sedi.html` rimane un alias di `dove-trovarmi.html`, escluso dalla
sitemap. Gli identificativi delle due sedi sono `/#location-prati` e
`/#location-monte-sacro`: i nodi `LocalBusiness` descrivono i luoghi dove Tik
riceve, senza dichiarare i partner filiali di sua proprieta'. L'attivita'
professionale e' un nodo `Organization`, i trattamenti sono nodi `Service`.
I dati delle sedi, ripetuti negli HTML per essere disponibili senza JavaScript,
devono restare coerenti: logo `thai-tik-logo-alternativo.png`, Via Giuseppe
Gioachino Belli 1 (00193) e Via Valle Borbera 51 (00141).

Per pubblicare questo aggiornamento caricare le due nuove pagine, tutti gli
HTML aggiornati, `sitemap.xml`, `details.css` e il nuovo `studio-hours.js`.
Le pagine dei centri incorporano la sola mappa della sede, caricata esclusivamente
dopo il consenso a Google Maps, e mostrano gli orari correnti tramite
`php-booking-backend.php?mode=hours`. Lo stesso script degli orari e' usato nella
pagina riepilogativa delle sedi. Errori di caricamento vengono mostrati e registrati
nella console; non si inventano orari o disponibilita'. Senza JavaScript restano
disponibili indirizzi, collegamenti esterni alle mappe e contatto email.
I percorsi `.html` sono pagine reali e canoniche: non richiedono riscritture URL.
Non occorrono modifiche al database o alla configurazione Aruba.
Controlli: `node --test tests/seo.test.cjs tests/booking-pages.test.cjs`.

## Run locally

To preview the static pages, run:

```bash
cd path/to/Tik-Thai-Massage
python3 -m http.server 8000
```

Then visit `http://localhost:8000`.

## Logo

Le pagine pubbliche, l'area amministrativa e le schede cliente usano
`thai-tik-logo-alternativo.png`, con la scritta “THAI TIK MASSAGE”, ornamenti
dorati e verdi e sfondo trasparente. I PDF presenti si
rigenerano con `generate_cliente_dati_fatturazione.py` e
`generate_cliente_dati_fatturazione_bilingue_minima.py` (richiedono ReportLab).
Nelle pagine pubbliche il logo ingrandito e' centrato nell'intestazione,
con il selettore lingue a sinistra e la navigazione sotto.

The booking page shows clearly labeled sample studios, services, and time slots on `localhost`. It does not submit or save bookings. To test real availability and booking submission, use PHP with PDO MySQL and the configured MySQL database; Python's static server cannot execute PHP.

## Pacchetti massaggi

I box Trattamenti della home, le cinque schede dei massaggi e il listino
stampabile mostrano i pacchetti da 3 e 6 sedute con durata, totale e prezzo per
seduta. Il totale viene arrotondato al multiplo di 5 euro superiore, salvo il
pacchetto da 3 sedute a 60 euro, proposto a 170 euro. I pacchetti sono
informativi; prenotazioni e pagamenti online restano per singole sedute.
Pubblicare anche `packages.css`,
`listino-trattamenti.html` e il PDF aggiornato `listino-trattamenti.pdf`.
Rigenerare il PDF da `listino-trattamenti.html` con Google Chrome headless,
disattivando intestazioni e piè di pagina. In caso di variazioni aggiornare
insieme home, schede e listino.
Verifica di prezzi, arrotondamenti e coerenza fra le pagine:
`node --test tests/packages.test.cjs`.

La scheda bilingue italiano/thai delle precauzioni prima del massaggio si trova
in `controindicazioni-massaggio-thai.html` e `controindicazioni-massaggio-thai.pdf`.
Per rigenerare il PDF A4 usare Google Chrome headless senza intestazioni e piè
di pagina.

## Volantino promozionale

Il volantino fronte-retro in formato A6 è disponibile in `volantino-a6.html`
(sorgente), `volantino-a6.pdf` (stampa) e `volantino-a6-canva.pptx` per
l'importazione e la modifica in Canva. I testi e gli elementi grafici del PPTX
sono modificabili separatamente; il logo è un'immagine. Per rigenerare il PPTX
usare `node genera-volantino-canva.js`. Per ristampare il PDF, mantenere il
formato A6, la scala 100% e i colori di sfondo; disattivare intestazioni e piè
di pagina. PDF e PPTX contengono due pagine, una per lato.

## Voucher regalo

La pagina Prenota contiene un acquisto separato di voucher per le sedi con servizi
attivi, senza prenotazione. Anteprima, PDF definitivo dopo pagamento SumUp e registro
admin con sedute residue e validita' di 12 mesi sono descritti in
[php-backend-readme.md](php-backend-readme.md#voucher-regalo).
Il destinatario prenota dal normale calendario scegliendo **Prenota con voucher
regalo** e inserendo il codice. Una seduta viene scalata alla registrazione della
richiesta; l'annullamento dall'admin la restituisce automaticamente.
Prima di pubblicare importare `backend-aruba-vouchers.sql` e poi
`backend-aruba-voucher-bookings.sql`, anche sui database nuovi.

## Footer condiviso

Il footer delle pagine pubbliche, della pagina documenti legali e dell'anteprima
`footer-mobile-first.html` usa `footer.css`. Il foglio e' caricato direttamente
dalla home e dalle due anteprime, e importato da `details.css` nelle altre pagine.
Pubblicare anche `footer.css` e `thai-tik-logo-alternativo.png` insieme agli HTML.
Il layout mobile-first passa da una colonna a due e poi quattro; i collegamenti
hanno un'area di interazione di almeno 44 px e un indicatore di focus da tastiera.

## Consenso ai servizi facoltativi

Le pagine pubbliche caricano `cookie-consent.js` prima di `language-selector.js`
e usano `cookie-consent.css`. Google Maps non ha un `src` attivo prima
dell'autorizzazione; GTranslate viene caricato solo autorizzando la traduzione.
I font usano i fallback del dispositivo e non contattano Google Fonts.
Pubblicare anche questi due nuovi file insieme agli HTML e allo script lingue.

La scelta locale `thaitik-consent-v1` dura 180 giorni. Il footer permette di
riaprire le preferenze; la revoca delle mappe rimuove gli iframe attivi, quella
del traduttore ricarica la pagina per eliminare il codice esterno (attenzione
ai moduli non salvati). Il consenso non blocca il backend di prenotazione.
Se JavaScript e' disattivato, le mappe restano nascoste ma i link esterni restano
disponibili. I problemi di storage sono mostrati al visitatore.

Prima della pubblicazione verificare sul dominio HTTPS: prima visita, rifiuto,
scelta separata delle categorie, persistenza tra pagine, revoca, scadenza,
storage indisponibile, tastiera e mobile. Controllare nel pannello Network
che Maps, GTranslate e Google Fonts non siano contattati prima del consenso
o dopo il rifiuto. Le tecnologie effettive dei fornitori e i testi legali
richiedono una verifica sul dominio live; questa implementazione non certifica
la conformita' legale dell'intero sito.

Test di regressione senza dipendenze aggiuntive:
`node --test tests/cookie-consent.test.cjs`.

## Versione 6.0

Consulta [RELEASE_NOTES_V6.md](RELEASE_NOTES_V6.md) per le modifiche di questa versione.

Il selettore lingue usa il widget esterno GTranslate per italiano, inglese, thailandese e spagnolo. Prima della pubblicazione verifica la traduzione sul dominio Aruba e aggiorna l'informativa privacy per il servizio esterno. Il backend PHP/MySQL e l'invio email vanno verificati sull'hosting Aruba dopo il caricamento dei file aggiornati.