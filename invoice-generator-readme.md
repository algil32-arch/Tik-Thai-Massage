# Generatore fatture per Aruba

Questo file genera:
- record fattura
- PDF di visualizzazione con il layout del modello grafico
- XML semplificato
- copia di cortesia con gli stessi dati e un'avvertenza dedicata
- record di documenti collegati

Il PDF usa il renderer `fattura-cortesia-renderer.php` e riprende l'impaginazione del modello
allegato (formato lettera, logo e numero in testata, dati cliente, tabella colorata, totali e
note fiscali). Viene usato il logo `invoice-template-logo.jpg` con la maschera
`invoice-template-logo-mask.deflate`; se mancano, si usa `thai-tik-logo-alternativo.png`.
Anche i generatori `fattura-da-pagamento.php`, `fattura-pa-generator.php` e `invoice-generator.php`
usano lo stesso renderer.
La copia di cortesia non sostituisce il documento fiscale elettronico. Il layout PDF non modifica
la generazione dell'XML FatturaPA.
Nella pagina `fatture-admin.php`, il pulsante **Rigenera PDF** aggiorna i documenti di una fattura
già presente senza annullarla o crearne una duplicata. Il logo è facoltativo: se manca, nel PDF
viene mostrato il nome Thai Tik Massage come testata.
Il collegamento ai documenti include una versione cache-busting per mostrare il PDF appena
rigenerato anche quando il browser conserva una copia precedente.

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
