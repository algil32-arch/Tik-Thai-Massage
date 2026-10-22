# Backend e agenda PHP per Aruba

Il backend usa PHP con PDO e MySQL/MariaDB. L'agenda applica le fasce settimanali configurate per ogni studio, mostra solo gli orari compatibili con la durata effettiva del servizio e considera un buffer minimo di 15 minuti. Appuntamenti e checkout in attesa della stessa professionista bloccano gli orari in tutte le sedi, non soltanto in quella selezionata.

## Installazione database
1. Importa `backend-aruba-schema.sql` nel database.
2. Importa `backend-aruba-agenda.sql` per creare la tabella delle fasce orarie.
3. Importa `backend-aruba-seed.sql` per creare Nuttiporn Sriboust, le sedi Studio Prati e APS Studio Montesacro e i servizi attualmente pubblicati. Lo script evita di duplicare professionista, sedi e servizi se viene reimportato.
4. Per nuovi studi creati successivamente, `backend-aruba-foot-massage.sql` aggiunge le formule Express e Completo.
5. Sui database già creati con lo schema precedente, importa una sola volta `backend-aruba-prenotazioni-fiscali.sql` per aggiungere ragione sociale, PEC, codice destinatario e campi di pagamento.
6. Per attivare il checkout SumUp su un database esistente, importa una sola volta `backend-aruba-sumup.sql`.
7. Per registrare gli appuntamenti online solo dopo il pagamento, importa una sola volta `backend-aruba-sumup-intents.sql`. Gli intenti temporanei riservano lo slot per 15 minuti senza creare cliente o appuntamento.
8. Per aggiungere al database esistente le formule Aromaterapia da 90 e 120 minuti e allineare i relativi prezzi, importa una sola volta `backend-aruba-aromaterapia-listino.sql`.
9. Per le nuove bozze fiscali manuali, importa una sola volta `backend-aruba-fatturazione-fiscale.sql` sui database esistenti; aggiunge provincia, regime/documento, rivalsa e dati bollo. Su database nuovi queste colonne sono già presenti nello schema principale.
10. Per la sincronizzazione con Google Calendar, importa una sola volta `backend-aruba-google-calendar.sql`: aggiunge `google_event_id` e `google_synced_at` ad `appuntamenti` e crea le tabelle `agenda_blocchi` e `google_calendar_stato`.
11. Per i voucher regalo, importa `backend-aruba-vouchers.sql` sia su database nuovi sia su quelli esistenti, prima di pubblicare il form. Crea due tabelle nuove senza modificare appuntamenti o fatture.
12. Per prenotare con voucher, importa poi `backend-aruba-voucher-bookings.sql`, anche se i voucher sono gia' installati. Aggiunge la modalita' di pagamento `voucher` agli appuntamenti e una tabella di collegamento/idempotenza; non modifica i voucher esistenti.
13. Per attivare le notifiche immediate da Google, importa `backend-aruba-google-push.sql` dopo la migrazione Google Calendar, anche sui database nuovi.

## Voucher regalo

La pagina `prenota-voucher.html` vende servizi attivi scegliendo la sede, compresa
APS Jaqueline. Si scelgono destinatario, trattamento, formula da 1/3/6 sedute e
quantita' da 1 a 20. Il server recupera il prezzo dal database e calcola il totale:
il prezzo dei pacchetti da 3/6 sedute viene arrotondato al multiplo di 5 euro
superiore, salvo il pacchetto da 3 sedute a 60 euro, proposto a 170 euro.
Un importo inviato dal browser non determina mai l'importo del checkout.
I voucher da 5/10 sedute gia' emessi restano utilizzabili fino a scadenza.

L'anteprima stampabile/scaricabile e' marcata **NON VALIDO / NON PAGATO**. Il
pagamento usa l'SDK e il client SumUp esistenti, ma l'acquisto e' salvato in
`gift_vouchers`, senza creare clienti o appuntamenti e senza bloccare slot.
Il codice definitivo viene emesso solo dopo verifica server di stato PAID,
riferimento, valuta EUR e importo. Vale 12 mesi dalla conferma, nello stesso
trattamento e durata. Le sedute si prenotano dal normale calendario del sito:
selezionare **Prenota con voucher regalo**, la sede indicata sul voucher, lo stesso trattamento
e durata, data/orario, dati cliente e codice. Per APS Jaqueline, chi non è ancora
socio dovrà iscriversi all'associazione al primo appuntamento; l'iscrizione e
l'eventuale quota sono gestite direttamente dall'associazione, fuori dal sito e
dal pagamento del trattamento.
**Verifica codice voucher** mostra
le sedute residue senza modificarle. All'invio il server ricontrolla codice,
pagamento, corrispondenza del servizio, disponibilita' e scadenza (anche rispetto
alla data dell'appuntamento). Non viene creato alcun checkout SumUp aggiuntivo.
La richiesta resta in attesa della sincronizzazione Google; quando l'evento viene
inserito correttamente il sito la conferma e invia un'email al cliente.
Il PDF e' generato in PHP, senza librerie esterne, e si scarica usando un token
segreto: non condividere il link se non con acquirente/destinatario.

Il browser conserva solo il token nella sessione della scheda per recuperare
un acquisto interrotto. Prima di riprovare il pagamento, usare **Verifica
pagamento / recupera voucher**. Il checkout scade dopo 15 minuti ed e'
ricontrollato prima di disattivarlo. Dal registro `vouchers-admin.php`, protetto
dalla normale autenticazione admin, si possono verificare anche acquisti
abbandonati: la verifica emette il voucher se risultano pagati. Le notifiche
email usano la configurazione esistente e indicano il download sul dominio
`www.thaitikmassage.it`; eventuali errori sono mostrati al cliente e registrati.

Il registro mostra gli ultimi 100 acquisti, consente ricerca per codice completo,
mostra i dati acquirente per la **fatturazione manuale** e scala una seduta per
utilizzo. Il controllo e' transazionale, protegge dalle richieste duplicate e
non permette l'utilizzo di voucher non pagati, esauriti o scaduti. Non viene
scalata una seconda seduta per una richiesta online gia' registrata: browser
e server usano un riferimento univoco, conservato nella memoria della scheda
insieme al solo hash dei dati. Appuntamento, collegamento voucher e decremento
sono salvati nella stessa transazione, con blocco del voucher. Un errore di
disponibilita' o salvataggio non modifica i contatori. Il registro mostra gli
appuntamenti collegati (ultimi 500 collegamenti); la registrazione manuale serve
solo per sedute non prenotate online. L'annullamento da
`booking-admin-advanced.php` restituisce una sola volta la seduta nella stessa
transazione. Non prolunga la scadenza del voucher e non riattiva un voucher
scaduto. Una richiesta annullata non puo' essere riconfermata; va riprenotata.
La seduta coperta dal voucher risulta pagata e non genera una seconda fattura
dal pulsante dell'agenda. Non viene
generata automaticamente una fattura: verificare con il commercialista
trattamento fiscale, documenti, condizioni di vendita/recesso e conservazione
dei dati prima di attivare vendite reali.

File da pubblicare: `prenota.html`, `prenota-massaggio.html`, `prenota-voucher.html`,
`area-admin.php`, `area-admin.html`, `documenti-legali-premium.html`,
`gift-voucher.js`, `gift-voucher.css`, `voucher.php`, `voucher-model.php`,
`voucher-store.php`, `voucher-pdf.php`, `vouchers-admin.php`, `booking-voucher.php`,
`php-booking-backend.php`, `booking-admin-advanced.php`, `booking-mailer.php`,
`fatture-admin.php`, `fatture-export-lotto.php`.
Dipendenze gia'
presenti: `app-config.php`, `admin-auth.php`, `sumup-client.php` e logo.
Serve PHP con PDO MySQL, cURL e iconv, un database migrato e SumUp in EUR.

Verifiche locali:
`php tests/voucher-test.php`, `php tests/booking-voucher-test.php` e
`node --test tests/gift-voucher.test.cjs tests/packages.test.cjs tests/booking-pages.test.cjs`.
Prima delle vendite, collaudare su Aruba con credenziali SumUp di test: pagamento
riuscito/fallito/scaduto, recupero dopo ricarica o abbandono, doppia verifica,
due utilizzi concorrenti dell'ultima seduta, scadenza, email e fatturazione.
Collaudare anche una prenotazione con codice, doppio invio, slot occupato,
trattamento/durata errati e annullamento ripetuto: una sola seduta scalata o
restituita. I test con PDO simulato non sostituiscono questo collaudo MySQL.

## Dati della prenotazione
Nome, cognome, email e dati fiscali sono richiesti; il telefono è facoltativo. Per i privati è richiesto il codice fiscale, per aziende e professionisti ragione sociale, partita IVA e codice destinatario o PEC. Il metodo `studio` registra subito la richiesta e invia le notifiche. Il metodo `online` crea un intento temporaneo che riserva lo slot per 15 minuti, senza inserire righe in `clienti` o `appuntamenti`. Cliente, appuntamento e notifiche vengono creati solo dopo che il backend ha verificato lo stato `PAID` direttamente con SumUp. Alla scadenza il checkout viene disattivato e lo slot liberato; se il cliente abbandona la pagina senza pagare non rimane alcun appuntamento.

## Configurazione hosting
La guida Aruba per Easy Linux documenta versione PHP e parametri `php.ini`, non variabili d'ambiente personalizzate. Per questo progetto usa il file privato:

1. Carica la cartella `private` completa, incluso `.htaccess`.
2. Copia `private/config.example.php` in `private/config.local.php`.
3. In `config.local.php`, inserisci la password MySQL in `DB_PASS` e scegli una password robusta per `ADMIN_PASSWORD`.
4. Non condividere né versionare `config.local.php`. La cartella privata nega l'accesso HTTP; il loader supporta anche variabili d'ambiente, se Aruba le abiliterà in futuro.

`DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PORT` sono già impostati nel template. `ADMIN_PASSWORD` protegge le pagine di gestione prenotazioni, agenda, voucher e fatture. Il pulsante **Esci e torna al sito** invalida la sessione e richiede nuovamente la password al prossimo accesso.

## Configurazione SumUp
1. Nel profilo sviluppatore SumUp crea una API key per i pagamenti online e recupera il codice esercente.
2. In `private/config.local.php` valorizza `SUMUP_API_KEY` e `SUMUP_MERCHANT_CODE`; lascia `SUMUP_CURRENCY` impostato su `EUR`.
3. Non inserire mai la API key in HTML o JavaScript. Il browser riceve soltanto l'identificativo temporaneo del checkout.
4. Verifica che nell'hosting Aruba sia attiva l'estensione PHP cURL e che il sito sia pubblicato in HTTPS.
5. Esegui inizialmente un pagamento controllato con l'ambiente e le credenziali di test SumUp, poi passa alle credenziali di produzione.

Il widget ufficiale invia i dati della carta direttamente a SumUp. Dopo il callback del widget, `sumup-payment.php` interroga l'API SumUp e confronta identificativo, riferimento, importo e valuta con l'intento temporaneo prima di creare l'appuntamento. Le chiamate ripetute di verifica sono idempotenti e non inviano email duplicate. Anche le richieste successive degli slot elaborano gli intenti scaduti: un checkout già pagato viene finalizzato, mentre un checkout non pagato viene disattivato e liberato.

## Email di prenotazione
Dopo aver salvato la richiesta nel database, il backend usa la funzione PHP `mail()` per inviare una notifica a `BOOKING_NOTIFICATION_EMAIL` e una ricevuta al cliente. Per impostazione predefinita mittente e notifica studio sono `info@thaitikmassage.it`; puoi sovrascriverli in `private/config.local.php` con `MAIL_FROM_EMAIL` e `BOOKING_NOTIFICATION_EMAIL`. La ricevuta iniziale specifica che la richiesta è in attesa. Quando la sincronizzazione Google inserisce correttamente l'evento, il backend imposta lo stato su confermato e invia una seconda email al cliente. La risposta del cron distingue email inviate e non inviate; un errore e' registrato nei log e richiede verifica manuale. Il valore di ritorno di `mail()` indica che Aruba ha accettato il messaggio per l'invio, non garantisce la consegna in inbox.

Prima del test prenotazione, verifica l'invio da **Hosting Linux → Strumenti e impostazioni → Gestione PHP → Test PHP mail**. Se Aruba non accetta o consegna i messaggi, le prenotazioni restano comunque registrate e il form segnala il problema; per SMTP servirà configurare un trasporto autenticato.

## Configurare l'agenda
Accedi da `area-admin.php` con `ADMIN_PASSWORD` e scegli **Prenotazioni e agenda**. Per ogni studio aggiungi una o più fasce per giorno indicando ora iniziale e ora finale. Il sito propone partenze ogni 15 minuti a partire dall'inizio della fascia; ogni orario è prenotabile solo se il trattamento scelto termina entro la chiusura. Per chiudere una fascia, rimuovila.

Il backend lascia almeno **15 minuti tra i trattamenti**, sia prima sia dopo gli appuntamenti in attesa o confermati. Le partenze sono proposte ogni 15 minuti; quelle che non possono contenere l'intera durata del trattamento, che si sovrappongono a un appuntamento o a un blocco Google, oppure che non lasciano la pausa necessaria, vengono mostrate come non disponibili. Se il cliente le seleziona, il sito spiega il motivo. La stessa regola viene ricontrollata dal server durante il salvataggio. Gli appuntamenti esistenti non vengono spostati; la durata della seduta e gli orari di apertura restano invariati.

La funzione condivisa in `booking-slots.php` viene usata sia per mostrare gli orari sia per validarli durante il salvataggio, sotto il lock della professionista. Appuntamenti e checkout in attesa in qualsiasi sede della stessa professionista partecipano al controllo, così una prenotazione in un centro non lascia libero lo stesso orario negli altri. Se un'altra richiesta cambia le disponibilità, il POST restituisce 409 e il form aggiorna gli slot senza perdere i dati inseriti. Carica anche questo file sull'hosting; non serve una migrazione SQL. Per eseguire i test della logica: `php tests/booking-slots-test.php`.

## Sincronizzazione con Google Calendar
La sincronizzazione è bidirezionale: gli appuntamenti del sito diventano eventi del calendario e gli impegni creati a mano sul calendario chiudono gli slot sul sito. Poiché la professionista è una sola, un impegno Google blocca **tutte le sedi**. Dopo il salvataggio di una prenotazione non online e dopo la registrazione di un pagamento SumUp, il backend tenta subito la sincronizzazione: quando Google accetta l'evento, l'appuntamento viene confermato e il cliente riceve l'email di conferma; se il tentativo fallisce, resta in attesa e il cron riprova. Il cron gira comunque ogni 10 minuti per mantenere aggiornati gli impegni del calendario e recuperare eventuali errori.

Anche conferma, annullamento ed eliminazione dall'admin, e aggiunta/rimozione
delle fasce settimanali, avviano il sync dopo il salvataggio. L'admin mostra
l'esito senza annullare una modifica gia salvata in caso di errore Google.
Le fasce settimanali sono lette direttamente dal database dal sito: non
vengono esportate come eventi Google. Prima dell'eliminazione definitiva
di un appuntamento collegato a Google bisogna annullarlo e sincronizzarlo,
per non lasciare un evento orfano. Cron, webhook e prenotazioni usano lo
stesso lock MySQL per non esportare due volte gli stessi appuntamenti.

### Preparazione su Google Cloud
1. Crea un progetto su [console.cloud.google.com](https://console.cloud.google.com) e abilita **Google Calendar API**.
2. Crea un **account di servizio** e genera una chiave in formato JSON.
3. Salva il file come `private/google-service-account.json` sull'hosting. La cartella privata nega l'accesso HTTP e il file è escluso dal versionamento.
4. In Google Calendar crea (o scegli) il calendario dedicato, apri **Impostazioni e condivisione**, condividilo con l'indirizzo `client_email` dell'account di servizio dando il permesso **Apportare modifiche agli eventi** e copia l'**ID calendario**.

### Configurazione del sito
In `private/config.local.php` valorizza:

- `GOOGLE_CALENDAR_ID`: l'ID del calendario condiviso.
- `GOOGLE_SERVICE_ACCOUNT_FILE`: percorso del JSON, di norma `__DIR__ . '/google-service-account.json'`.
- `GOOGLE_CALENDAR_TIMEZONE`: `Europe/Rome`.
- `GOOGLE_SYNC_TOKEN`: una stringa casuale lunga, necessaria solo se richiami il cron via URL.
- `GOOGLE_CALENDAR_WEBHOOK_URL`: facoltativo; per il sito pubblicato usare
  `https://www.thaitikmassage.it/google-calendar-webhook.php`.
  Non inserire token nell'URL: le notifiche usano un segreto casuale per canale,
  distinto dal token del cron.

Carica sull'hosting `google-calendar-client.php`, `google-calendar-sync.php` e `cron-calendar-sync.php`.

### Cron
Su Aruba serve **un solo cron ogni 10 minuti**, che è la frequenza minima consentita. Se Aruba ti permette di eseguire PHP da riga di comando:

```
*/10 * * * * /usr/bin/php /percorso/assoluto/cron-calendar-sync.php >/dev/null 2>&1
```

In alternativa, con richiesta HTTP (richiede `GOOGLE_SYNC_TOKEN`):

```
*/10 * * * * wget -q -O /dev/null "https://www.thaitikmassage.it/cron-calendar-sync.php?token=IL_TUO_TOKEN"
```

Puoi lanciare manualmente lo stesso URL dal browser per verificare l'esito: la risposta JSON riporta eventi letti, impegni importati, eventi creati, prenotazioni confermate e email di conferma inviate/non inviate. Non richiamare questo URL dal JavaScript della pagina di prenotazione: il token diventerebbe visibile nel browser; il tentativo immediato viene eseguito dal backend.

### Notifiche immediate delle modifiche Google

Il solo cron non viene chiamato da Google a ogni modifica. Per attivare il push:

1. Importare `backend-aruba-google-push.sql`.
2. Pubblicare `google-calendar-webhook.php`, `google-calendar-push.php`,
   `google-calendar-client.php`, `google-calendar-sync.php`, `cron-calendar-sync.php`,
   `app-config.php`, `booking-admin.php`, `booking-admin-advanced.php` e
   `php-booking-backend.php`. Conservare le configurazioni private esistenti;
   per il flusso prenotazioni devono esserci anche le versioni correnti di
   `sumup-intents.php`, `sumup-payment.php` e `booking-mailer.php`.
3. Aggiungere `GOOGLE_CALENDAR_WEBHOOK_URL` alla configurazione privata.
   L'endpoint deve essere pubblico via HTTPS valido, senza login o redirect.
4. Eseguire una volta il cron autenticato: `notifiche_google.enabled: true`
   e `renewed: true` indicano la registrazione riuscita. Non pubblicare il
   token cron o le credenziali. Una chiamata GET al webhook risponde 405:
   Google invia POST con header di autenticazione del canale.
5. Lasciare attivo il cron ogni 10 minuti: rinnova i canali prima della scadenza
   (richiesti 7 giorni, vale la scadenza effettiva restituita da Google) e
   recupera notifiche perse. Il push riduce il ritardo ma Google non garantisce
   la consegna di ogni notifica.

Ogni notifica valida rilegge le variazioni del calendario; le notifiche iniziali
`sync` vengono riconosciute senza esportazioni o email. I canali in rinnovo
possono sovrapporsi: il lock serializza i cicli e gli ID evento evitano una
seconda conferma. Il webhook risponde 503 su errore per consentire i tentativi
successivi di Google; i dettagli sono nel log PHP. Se la registrazione del
canale fallisce, il cron segnala errore anche se il precedente ciclo dati e'
riuscito. Senza URL configurato, `enabled: false` mantiene il funzionamento
precedente con cron e sync immediato delle prenotazioni.

Collaudo su Aruba dopo la pubblicazione: creare, spostare ed eliminare un
impegno manuale Google e ricaricare gli slot senza invocare il cron a mano;
prenotare dal sito e verificare evento, prima email e conferma; annullare
dall'admin e verificare rimozione evento e riapertura slot. Non spostare gli
appuntamenti originati dal sito direttamente su Google: restano gestiti dal
database come prima. Una pagina prenotazioni gia aperta rilegge gli slot
quando si cambia selezione o si ricarica, non riceve aggiornamenti push nel browser.

Test locali: `php tests/google-calendar-test.php` e
`php tests/booking-slots-test.php`. I test isolano API, email e PDO:
non sostituiscono il collaudo con HTTPS, Google e MySQL su Aruba.

### Regole di funzionamento
- Gli appuntamenti `in_attesa` e `confermato` dalla data odierna in poi vengono creati su Google con una proprietà privata `thaitik_appointment_id`, così la sincronizzazione non li reimporta come impegni. Gli `in_attesa` passano a `confermato` solo dopo la risposta positiva di Google; allora viene spedita l'email di conferma.
- Gli appuntamenti `annullato` vengono rimossi dal calendario.
- Gli eventi Google che non provengono dal sito sono salvati in `agenda_blocchi` giorno per giorno e rendono gli slot non prenotabili. Gli eventi marcati come **Disponibile** (`transparency: transparent`) non bloccano nulla.
- Per liberare uno slot occupato a mano basta eliminare o spostare l'evento su Google: al ciclo successivo il sito lo rimette a disposizione.
- Le disdette vanno fatte dall'area amministrativa: se cancelli su Google l'evento di un appuntamento attivo, il cron lo ricrea per evitare doppie prenotazioni.
- Google invalida i token di sincronizzazione dopo lunghi periodi di inattività; in quel caso il cron esegue automaticamente una rilettura completa dei prossimi 12 mesi.
- Finché `GOOGLE_CALENDAR_ID` non è configurato, il sito funziona esattamente come prima.

## Inserimento manuale fatture
Dall'area admin scegli **Fattura manuale** per registrare fornitore, cliente, pagamento e prestazione. Il profilo del professionista viene aggiornato con i dati fiscali inseriti. Le bozze supportano regime forfettario RF19 (IVA 0%, natura N2.2) e ordinario RF01 (IVA 22%), rivalsa INPS facoltativa al 4% e bollo virtuale di 2 euro per il forfettario quando il totale supera 77,47 euro; il bollo può essere addebitato o restare a carico del professionista. Per i privati si registra il codice destinatario convenzionale `0000000`; per aziende è necessario codice destinatario o PEC. La data del documento in bozza coincide con quella di pagamento e la pagina mostra il termine indicativo di 12 giorni.

Il sistema aggiunge la dicitura forfettaria alle note, salva TD01 e gli elementi fiscali distinti, ma il numero `BOZZA-...` non è una numerazione fiscale definitiva: non genera il file FatturaPA e non invia nulla allo SDI. Aliquote, diciture e modalità di applicazione vanno verificate con il commercialista prima di emettere le fatture; per l'invio resta necessaria l'integrazione FatturaPA e del canale Aruba/SDI.

## Export XML a lotti per upload manuale Aruba
Se il piano Aruba non prevede API, usa la funzione di export manuale dalla pagina `fatture-admin.php`:

1. Apri **Gestione fatture** e compila il riquadro **Esporta lotto XML per Aruba**.
2. Scegli lotto **giornaliero** o **settimanale** e la data di riferimento.
3. Conferma con **Genera cartella XML**.
4. Il sistema crea una cartella in `documents/fatture/lotti/` con:
  - file XML (uno per fattura)
  - `manifest.csv` con le fatture esportate
  - `scarti.csv` con le fatture scartate e motivo
  - `report.json` di riepilogo
5. In parallelo crea anche lo ZIP del lotto nella stessa area (`.../lotti/<nome_lotto>.zip`) e tenta l'invio copia via email a `info@thaitikmassage.it`.
6. Carica i file XML (o lo ZIP, se il portale Aruba lo accetta) con drag and drop/import.

In `fatture-admin.php` puoi anche selezionare manualmente le righe in tabella e usare **Esporta selezionate in ZIP + email copia** per creare un lotto personalizzato solo con le fatture spuntate.

La stessa pagina include anche la sezione **Crea fattura da pagamento online**: inserisci l'ID pagamento (es. `12`) e il backend prova a risolvere prima `pagamenti.id`, poi `sumup_payment_intents.id`. Se il pagamento risulta `paid` e ha un appuntamento online confermato, crea la fattura e i documenti collegati.

Il pulsante **Genera fattura** nell'admin appuntamenti usa lo stesso flusso di creazione e registrazione documenti di `fatture-admin.php`; accetta appuntamenti confermati non pagati con voucher e non modifica lo stato dell'appuntamento.

Le fatture generate ricevono un progressivo annuale autonomo (`TS-AAAA-NNNNN`), assegnato sotto lock per evitare duplicati. In **Gestione fatture** si può cambiare il numero anche dopo il pagamento, purché la fattura non sia stata inviata allo SDI né annullata: il formato deve restare `TS-AAAA-NNNNN` e l'anno deve coincidere con la data di emissione. La modifica aggiorna anche PDF originale, copia di cortesia e XML; gli originali già presenti su disco non vengono cancellati.

Nelle aree admin è disponibile l'eliminazione definitiva singola di fatture e appuntamenti, con conferma e token CSRF. Non si possono eliminare fatture pagate o con pagamenti registrati, né appuntamenti pagati o collegati a pagamenti, richieste SumUp, voucher o fatture. Eliminando una fattura non pagata vengono rimossi anche righe e riferimenti ai documenti dal database; i file PDF/XML su disco restano. Per cancellare un appuntamento fatturato, eliminare prima la fattura se non è pagata.

I file XML sono prodotti in struttura FatturaPA (`FPR12`) con i dati disponibili nel database. Se la P.IVA del professionista non è presente, viene usata la P.IVA predefinita `18730391002`; i valori non vuoti ma non validi causano lo scarto. In assenza dei dati nel profilo, il generatore usa il codice fiscale del professionista `SRBNTP85C69Z241F` e l'email `info@thaitikmassage.it`. Il codice fiscale del cliente privato è sufficiente e viene riportato nell'XML anche senza P.IVA. Se mancano i dati fiscali minimi del cliente, la fattura viene scartata e indicata in `scarti.csv`.

## API prenotazioni
Il catalogo attivo è esposto da `php-booking-backend.php?mode=catalog`. Per verificare gli orari, `mode=slots` richiede `studio_id` e `date`; `service_id` è facoltativo e, quando manca, la disponibilità iniziale viene calcolata come per un trattamento da 30 minuti. Il POST richiede invece entrambi gli ID più `date`, `time`, `name` ed `email`; `notes` è facoltativo. Con `payment_method: "online"` la risposta include `payment_intent`, `checkout_id` ed `expires_at`, ma non crea ancora un appuntamento. Dopo il pagamento, il browser invia `payment_intent` e `checkout_id` a `sumup-payment.php`, che verifica lo stato con SumUp e restituisce `booking_id` soltanto dopo la conferma `PAID`.

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
