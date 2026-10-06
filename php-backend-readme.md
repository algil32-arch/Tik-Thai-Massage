# Backend e agenda PHP per Aruba

Il backend usa PHP con PDO e MySQL/MariaDB. L'agenda applica le fasce settimanali configurate per ogni studio e mostra solo gli orari compatibili con durata del servizio e appuntamenti già presenti.

## Installazione database
1. Importa `backend-aruba-schema.sql` nel database.
2. Importa `backend-aruba-agenda.sql` per creare la tabella delle fasce orarie.
3. Importa `backend-aruba-seed.sql` per creare Nuttiporn Sriboust, le sedi Studio Prati e APS Studio Montesacro e i servizi attualmente pubblicati. Lo script evita di duplicare professionista, sedi e servizi se viene reimportato.
4. Per nuovi studi creati successivamente, `backend-aruba-foot-massage.sql` aggiunge le formule Express e Completo.
5. Sui database già creati con lo schema precedente, importa una sola volta `backend-aruba-prenotazioni-fiscali.sql` per aggiungere ragione sociale, PEC, codice destinatario e campi di pagamento.
6. Per le nuove bozze fiscali manuali, importa una sola volta `backend-aruba-fatturazione-fiscale.sql` sui database esistenti; aggiunge provincia, regime/documento, rivalsa e dati bollo. Su database nuovi queste colonne sono già presenti nello schema principale.

## Dati della prenotazione
Nome, cognome, email e dati fiscali sono richiesti; il telefono è facoltativo. Per i privati è richiesto il codice fiscale, per aziende e professionisti ragione sociale, partita IVA e codice destinatario o PEC. Il metodo `studio` viene registrato sull'appuntamento. Il metodo `online` restituisce un errore esplicito finché il checkout SumUp non viene integrato.

## Configurazione hosting
La guida Aruba per Easy Linux documenta versione PHP e parametri `php.ini`, non variabili d'ambiente personalizzate. Per questo progetto usa il file privato:

1. Carica la cartella `private` completa, incluso `.htaccess`.
2. Copia `private/config.example.php` in `private/config.local.php`.
3. In `config.local.php`, inserisci la password MySQL in `DB_PASS` e scegli una password robusta per `ADMIN_PASSWORD`.
4. Non condividere né versionare `config.local.php`. La cartella privata nega l'accesso HTTP; il loader supporta anche variabili d'ambiente, se Aruba le abiliterà in futuro.

`DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PORT` sono già impostati nel template. `ADMIN_PASSWORD` protegge le pagine di gestione prenotazioni, agenda e fatture.

## Email di prenotazione
Dopo aver salvato la richiesta nel database, il backend usa la funzione PHP `mail()` per inviare una notifica a `BOOKING_NOTIFICATION_EMAIL` e una ricevuta al cliente. Per impostazione predefinita mittente e notifica studio sono `info@thaitikmassage.it`; puoi sovrascriverli in `private/config.local.php` con `MAIL_FROM_EMAIL` e `BOOKING_NOTIFICATION_EMAIL`. La ricevuta specifica che l'appuntamento è ancora in attesa di conferma. Il valore di ritorno di `mail()` indica che Aruba ha accettato il messaggio per l'invio, non garantisce la consegna in inbox.

Prima del test prenotazione, verifica l'invio da **Hosting Linux → Strumenti e impostazioni → Gestione PHP → Test PHP mail**. Se Aruba non accetta o consegna i messaggi, le prenotazioni restano comunque registrate e il form segnala il problema; per SMTP servirà configurare un trasporto autenticato.

## Configurare l'agenda
Apri `area-admin.html`, accedi con `ADMIN_PASSWORD` e scegli **Prenotazioni e agenda**. Per ogni studio aggiungi una o più fasce per giorno: ora iniziale, ora finale e passo degli slot (15, 30 o 60 minuti). Per chiudere una fascia, rimuovila. I clienti vedranno solo gli slot restituiti dall'agenda; una prenotazione non può superare la fine della fascia né sovrapporsi a una richiesta in attesa o confermata.

Il backend lascia almeno **5 minuti tra i trattamenti**, sia prima sia dopo gli appuntamenti in attesa o confermati. Gli orari liberi che incontrano un appuntamento ripartono dalla sua fine più 5 minuti, poi mantengono il passo configurato. Con passo 60 minuti: senza prenotazioni 10:00, 11:00, 12:00; dopo una prenotazione 10:00–11:00, 11:05, 12:05; dopo una seconda prenotazione 11:05–12:05, 12:10, 13:10. Gli appuntamenti esistenti non vengono spostati e il trattamento deve comunque terminare entro la fascia. Annullando una prenotazione, gli orari liberi vengono ricalcolati.

La funzione condivisa in `booking-slots.php` viene usata sia per mostrare gli orari sia per validarli durante il salvataggio, sotto il lock dello studio. Se un'altra richiesta cambia le disponibilità, il POST restituisce 409 e il form aggiorna gli slot senza perdere i dati inseriti. Carica anche questo file sull'hosting; non serve una migrazione SQL. Per eseguire i test della logica: `php tests/booking-slots-test.php`.

## Inserimento manuale fatture
Dall'area admin scegli **Fattura manuale** per registrare fornitore, cliente, pagamento e prestazione. Il profilo del professionista viene aggiornato con i dati fiscali inseriti. Le bozze supportano regime forfettario RF19 (IVA 0%, natura N2.2) e ordinario RF01 (IVA 22%), rivalsa INPS facoltativa al 4% e bollo virtuale di 2 euro per il forfettario quando il totale supera 77,47 euro; il bollo può essere addebitato o restare a carico del professionista. Per i privati si registra il codice destinatario convenzionale `0000000`; per aziende è necessario codice destinatario o PEC. La data del documento in bozza coincide con quella di pagamento e la pagina mostra il termine indicativo di 12 giorni.

Il sistema aggiunge la dicitura forfettaria alle note, salva TD01 e gli elementi fiscali distinti, ma il numero `BOZZA-...` non è una numerazione fiscale definitiva: non genera il file FatturaPA e non invia nulla allo SDI. Aliquote, diciture e modalità di applicazione vanno verificate con il commercialista prima di emettere le fatture; per l'invio resta necessaria l'integrazione FatturaPA e del canale Aruba/SDI.

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
