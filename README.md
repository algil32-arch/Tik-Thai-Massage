# Thai Tik Massage — Site Ver 6.0

Sito statico multipagina per Thai Tik Massage, con backend PHP/MySQL predisposto per l'hosting Aruba.

## Run locally

To preview the static pages, run:

```bash
cd path/to/Tik-Thai-Massage
python3 -m http.server 8000
```

Then visit `http://localhost:8000`.

The booking page shows clearly labeled sample studios, services, and time slots on `localhost`. It does not submit or save bookings. To test real availability and booking submission, use PHP with PDO MySQL and the configured MySQL database; Python's static server cannot execute PHP.

## Versione 6.0

Consulta [RELEASE_NOTES_V6.md](RELEASE_NOTES_V6.md) per le modifiche di questa versione.

Il selettore lingue usa il widget esterno GTranslate per italiano, inglese, thailandese e spagnolo. Prima della pubblicazione verifica la traduzione sul dominio Aruba e aggiorna l'informativa privacy per il servizio esterno. Il backend PHP/MySQL e l'invio email vanno verificati sull'hosting Aruba dopo il caricamento dei file aggiornati.