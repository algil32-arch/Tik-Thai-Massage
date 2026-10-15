# Thai Tik Massage - Site Ver 12.0

## Rilascio

Versione: 12.0
Data: 2026-10-15

## Novita

- Introdotto un builder XML condiviso per la fatturazione elettronica: stesso tracciato tra fattura singola e export lotto Aruba.
- Refactor del flusso admin con allineamento strutturale FatturaElettronica (header/body, riepilogo IVA, natura, pagamento).
- Export lotto XML consolidato con stessi criteri fiscali del flusso da pagamento online.
- Migliorata la gestione documenti in area fatture (originale XML/copia) con percorsi coerenti per la visualizzazione.
- Sostituiti gli stub testuali con PDF validi e visualizzabili nel browser.
- Migliorata la resa della fattura PDF con layout piu leggibile: intestazione, cedente/cessionario, dettaglio prestazione, riepilogo fiscale e note.

## File principali aggiornati

- fattura-da-pagamento.php
- fatture-export-lotto.php
- fattura-xml-builder.php
- fatture-admin.php
- php-backend-readme.md

## Pubblicazione su Aruba

1. Eseguire backup completo file + database.
2. Pubblicare i file backend aggiornati:
   - fattura-da-pagamento.php
   - fatture-export-lotto.php
   - fattura-xml-builder.php
   - fatture-admin.php
3. Pubblicare la documentazione aggiornata: php-backend-readme.md e RELEASE_NOTES_V12.md.
4. Verificare permessi di scrittura su documents/fatture/pdf, documents/fatture/xml, documents/fatture/copie.
5. Rigenerare almeno una fattura da pagamento online (Annulla e rigenera) per ottenere PDF e XML nel nuovo formato.
6. Eseguire un export lotto e verificare ZIP, manifest, report e compatibilita upload manuale su Aruba.

## Verifiche

- Nessun errore statico rilevato sui file PHP modificati durante il refactor.
- Builder XML unico attivo nei due flussi (singolo e lotto).
- PDF ora generati con struttura valida e apertura browser.

Note: questo rilascio Git non sostituisce la pubblicazione su Aruba.