# Thai Tik Massage — Site Ver 4.0

## Rilascio

Versione: 4.0  
Data: 2026-10-04

## Novità principali

- Nuovo logo trasparente e aggiornamento del brand in tutto il sito
- Nuovo testo introduttivo nella home e descrizioni aggiornate delle sedi e dei trattamenti
- Sezione informativa ampliata sulla storia del Nuad Thai
- Nuova pagina Foot Massage con immagini dedicate e formule Express (30 minuti, 35 €) e Completo (60 minuti, 50 €)
- Formule Foot Massage aggiunte alla selezione della prenotazione e script SQL idempotente per i servizi degli studi attivi
- Selettore lingue IT/EN/TH/ES al centro dell'header delle pagine pubbliche
- Indirizzo email aggiornato a `info@thaitikmassage.it`
- Header sticky, titolo del brand più leggibile e link admin reso meno invasivo
- In caso di errore del backend, il form di prenotazione chiarisce che la richiesta non è stata registrata

## Verifiche e limiti

- Verificati nel browser i percorsi di selezione delle due formule Foot Massage e i relativi prezzi e durate
- I file HTML/CSS/JS modificati non presentano errori statici
- Il sito locale usa un server statico: invio PHP e salvataggio MySQL non sono stati collaudati
- Il selettore usa il widget automatico esterno GTranslate. In locale la scelta cambia l'etichetta ma non traduce il contenuto; verificare il servizio sul dominio pubblico prima del rilascio e dichiararlo nell'informativa privacy
- La fatturazione e l'area admin richiedono ancora configurazione e verifiche sull'hosting Aruba