# Thai Tik Massage — Site Ver 11.0

Sito statico multipagina per Thai Tik Massage, con backend PHP/MySQL predisposto per l'hosting Aruba.

Note del rilascio corrente: [Site Ver 11.0](RELEASE_NOTES_V11.md).

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

I box Trattamenti della home e le cinque schede dei massaggi mostrano i pacchetti
da 5 e 10 sedute con durata, totale e prezzo per seduta, senza percentuali
o spiegazioni commerciali. Gli importi restano invariati: il prezzo per seduta
viene arrotondato all'euro superiore, poi moltiplicato per il numero di sedute.
I pacchetti sono informativi; prenotazioni e pagamenti online restano per singole
sedute. Pubblicare anche `packages.css` e `listino-trattamenti.pdf`.
In caso di variazioni aggiornare insieme home, schede e PDF.
Verifica di prezzi, arrotondamenti e coerenza fra le pagine:
`node --test tests/packages.test.cjs`.

## Voucher regalo

La pagina Prenota contiene un acquisto separato di voucher per Studio Prati,
senza prenotazione. Anteprima, PDF definitivo dopo pagamento SumUp e registro
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