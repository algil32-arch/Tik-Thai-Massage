# Backend PHP per Aruba

Questo file fornisce il punto di partenza per un backend di prenotazione.

## Come funziona
- riceve una richiesta POST con i dati di studio, servizio, data e orario
- salva il cliente in `clienti`
- salva la prenotazione in `appuntamenti`
- usa il database MySQL definito nel file `backend-aruba-schema.sql`

## Foot Massage
Una volta creati gli studi nella tabella `studi`, importa `backend-aruba-foot-massage.sql` in phpMyAdmin. Lo script aggiunge le formule Express e Completo a ogni studio attivo, evitando di inserirle di nuovo se è già stato eseguito.

## Parametri richiesti
- studio
- service
- date
- time
- name
- email
- notes (opzionale)

## Esempio JSON

```json
{
  "studio": "Studio Roma",
  "service": "Massaggio Thai tradizionale",
  "date": "2026-10-04",
  "time": "10:30",
  "name": "Mario Rossi",
  "email": "mario@email.com",
  "notes": "Vorrei un trattamento rilassante"
}
```

## Eventuali modifiche future
- gestire la conferma da parte dell'admin
- salvare prezzi e IVA
- creare fatture
- generare XML/FatturaPA
- inviare email di conferma
- collegare a un pannello admin
