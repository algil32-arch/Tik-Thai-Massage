# Backend Aruba: struttura per prenotazioni e fatturazione

## Obiettivo
Creare un backend semplice ma serio per un servizio di massaggi con più studi, prezzi variabili e gestione fatture elettroniche.

## Stack consigliato
- Hosting: Aruba
- Linguaggio backend: PHP
- Database: MySQL / MariaDB
- Frontend: HTML + CSS + JS
- Documenti: PDF + XML fatturaPA
- Integrazione SDI: modulo dedicato

## Requisiti principali
- 1 professionista per studio o un professionista responsabile per una sede
- prezzi diversi tra studi
- fatturazione elettronica per i servizi erogati
- copia di cortesia dei documenti
- archiviazione delle prenotazioni e delle fatture

## Flusso previsto
1. Cliente entra nella pagina di prenotazione
2. Sceglie lo studio
3. Sceglie il servizio
4. Sceglie data e ora disponibile
5. Conferma la richiesta
6. Il backend salva l'appuntamento
7. Il professionista o l'admin conferma lo slot
8. Se richiesto, viene generata la fattura
9. Il sistema genera XML e PDF
10. La fattura viene inviata allo SDI
11. Viene salvata anche la copia di cortesia

## Tabelle chiave
- professionisti
- studi
- clienti
- servizi
- appuntamenti
- fatture
- righe_fattura
- documenti_fattura
- pagamenti

## Fattura elettronica
Le fatture elettroniche devono rispettare il formato FatturaPA e devono essere inviate in modo tracciabile allo SDI.

## Copia di cortesia
Questa è una copia del documento fiscale, conservata in PDF e/o in archivio. Serve come supporto per il cliente e per la documentazione interna.

## Evoluzione consigliata
- Prima fase: sito + prenotazioni + fatture bozza
- Seconda fase: fatture emesse + PDF/XML + SDI
- Terza fase: parte admin dedicata per professionisti e studi
