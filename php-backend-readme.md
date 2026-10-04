# Backend e agenda PHP per Aruba

Il backend usa PHP con PDO e MySQL/MariaDB. L'agenda applica le fasce settimanali configurate per ogni studio e mostra solo gli orari compatibili con durata del servizio e appuntamenti già presenti.

## Installazione database
1. Importa `backend-aruba-schema.sql` nel database.
2. Importa `backend-aruba-agenda.sql` per creare la tabella delle fasce orarie.
3. Importa `backend-aruba-seed.sql` per creare Nuttiporn Sriboust, le sedi Studio Prati e APS Studio Montesacro e i servizi attualmente pubblicati. Lo script evita di duplicare professionista, sedi e servizi se viene reimportato.
4. Per nuovi studi creati successivamente, `backend-aruba-foot-massage.sql` aggiunge le formule Express e Completo.
5. Sui database già creati con lo schema precedente, importa una sola volta `backend-aruba-prenotazioni-fiscali.sql` per aggiungere ragione sociale, PEC, codice destinatario e campi di pagamento.

## Dati della prenotazione
Nome, cognome, email e dati fiscali sono richiesti; il telefono è facoltativo. Per i privati è richiesto il codice fiscale, per aziende e professionisti ragione sociale, partita IVA e codice destinatario o PEC. Il metodo `studio` viene registrato sull'appuntamento. Il metodo `online` restituisce un errore esplicito finché il checkout SumUp non viene integrato.

## Configurazione hosting
La guida Aruba per Easy Linux documenta versione PHP e parametri `php.ini`, non variabili d'ambiente personalizzate. Per questo progetto usa il file privato:

1. Carica la cartella `private` completa, incluso `.htaccess`.
2. Copia `private/config.example.php` in `private/config.local.php`.
3. In `config.local.php`, inserisci la password MySQL in `DB_PASS` e scegli una password robusta per `ADMIN_PASSWORD`.
4. Non condividere né versionare `config.local.php`. La cartella privata nega l'accesso HTTP; il loader supporta anche variabili d'ambiente, se Aruba le abiliterà in futuro.

`DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PORT` sono già impostati nel template. `ADMIN_PASSWORD` protegge le pagine di gestione prenotazioni e agenda.

## Email di prenotazione
Dopo aver salvato la richiesta nel database, il backend usa la funzione PHP `mail()` per inviare una notifica a `BOOKING_NOTIFICATION_EMAIL` e una ricevuta al cliente. Per impostazione predefinita mittente e notifica studio sono `info@thaitikmassage.it`; puoi sovrascriverli in `private/config.local.php` con `MAIL_FROM_EMAIL` e `BOOKING_NOTIFICATION_EMAIL`. La ricevuta specifica che l'appuntamento è ancora in attesa di conferma. Il valore di ritorno di `mail()` indica che Aruba ha accettato il messaggio per l'invio, non garantisce la consegna in inbox.

Prima del test prenotazione, verifica l'invio da **Hosting Linux → Strumenti e impostazioni → Gestione PHP → Test PHP mail**. Se Aruba non accetta o consegna i messaggi, le prenotazioni restano comunque registrate e il form segnala il problema; per SMTP servirà configurare un trasporto autenticato.

## Configurare l'agenda
Apri `area-admin.html`, accedi con `ADMIN_PASSWORD` e scegli **Prenotazioni e agenda**. Per ogni studio aggiungi una o più fasce per giorno: ora iniziale, ora finale e passo degli slot (15, 30 o 60 minuti). Per chiudere una fascia, rimuovila. I clienti vedranno solo gli slot restituiti dall'agenda; una prenotazione non può superare la fine della fascia né sovrapporsi a una richiesta in attesa o confermata.

## API prenotazioni
Il catalogo attivo è esposto da `php-booking-backend.php?mode=catalog`. Per verificare gli orari, `mode=slots` richiede `studio_id`, `service_id` e `date`. Il POST richiede gli stessi ID più `date`, `time`, `name` ed `email`; `notes` è facoltativo.

Esempio JSON (gli ID devono esistere nel database):

```json
{
  "studio_id": 1,
  "service_id": 1,
  "date": "2027-06-15",
  "time": "10:30",
  "name": "Mario Rossi",
  "email": "mario@email.com",
  "notes": "Preferenza oraria"
}
```
