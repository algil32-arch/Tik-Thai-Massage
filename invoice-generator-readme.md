# Generatore fatture per Aruba

Questo file genera:
- record fattura
- PDF della fattura
- XML semplificato
- copia di cortesia
- record di documenti collegati

## Requisito
Il database deve avere le tabelle già create con lo schema `backend-aruba-schema.sql`.

## Esempio CLI

```bash
php invoice-generator.php 1
```

## Esempio POST

```http
POST /invoice-generator.php
Content-Type: application/x-www-form-urlencoded

appointment_id=1
```

## Output atteso
Il sistema restituisce:
- invoice_id
- numero_fattura
- pdf_path
- xml_path
- copy_path
- totale

## Notes
Questo è un punto di partenza. Per la fattura elettronica reale occorre integrare il formato FatturaPA e il canale di invio SDI.
