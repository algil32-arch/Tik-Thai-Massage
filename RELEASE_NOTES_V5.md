# Thai Tik Massage — Site Ver 5.0

## Rilascio

Versione: 5.0

Data: 2026-10-04

## Novità principali

- Agenda settimanale configurabile per ogni studio, con più fasce giornaliere e intervalli slot da 15, 30 o 60 minuti.
- Disponibilità online calcolate da studio, servizio, durata, orari configurati e appuntamenti già registrati; rimossi gli orari demo.
- Pagina "Dove trovarmi" collegata agli orari reali dell'agenda per ciascuna sede.
- Protezione delle pagine admin con password e sessione, controllo CSRF per le modifiche all'agenda e configurazione privata esclusa da Git.
- Seed SQL per Nuttiporn Sriboust, Studio Prati, APS Studio Montesacro e servizi pubblicati; migrazione SQL per la tabella `orari_studio`.
- Notifica email allo studio e ricevuta al cliente dopo il salvataggio della richiesta, con stato esplicito "in attesa di conferma".
- Link email del sito indirizzati alla composizione Gmail, con oggetti precompilati per contatti delle sedi e trattamenti.
- Aggiornamento editoriale e SEO delle pagine Tok Sen, Aromaterapia e Deep Tissue, con nuove immagini e testi accessibili.
- Aggiornamento delle immagini in homepage per Aromaterapia e Foot Massage e nuove hero per Tok Sen e massaggio Thai tradizionale.

## Configurazione e verifiche

- Importare `backend-aruba-agenda.sql` e `backend-aruba-seed.sql` sul database Aruba.
- Configurare `private/config.local.php` sull'hosting; il file è escluso da Git e la cartella `private` è protetta da `.htaccess`.
- Per l'invio email è necessario verificare la funzione PHP `mail()` dal pannello Aruba; la consegna effettiva non è verificabile in locale.
- Controllati i riferimenti agli orari demo, il parsing JavaScript incorporato e gli errori statici dei file modificati.
- In anteprima locale su localhost sono disponibili sedi, servizi e slot dimostrativi; l'invio resta disabilitato e nessuna richiesta viene salvata.
- Il lint PHP da terminale non è stato eseguito: nell'ambiente locale non è installato `php-cli`.

## Da verificare dopo il caricamento su Aruba

- Accesso protetto ad `area-admin.html` e modifica delle fasce per entrambe le sedi.
- Endpoint `php-booking-backend.php?mode=hours` e aggiornamento della pagina `dove-trovarmi.html`.
- Prenotazione di prova, sovrapposizione degli slot e ricezione delle email in studio e cliente.
- Traduzione del widget GTranslate e informativa privacy sul dominio pubblico.
