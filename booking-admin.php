<?php
require_once __DIR__ . '/admin-auth.php';

try {
  $pdo = dbConnection();
} catch (Throwable $e) {
  http_response_code(503);
  die('Database non raggiungibile. Verifica la configurazione privata e i dati Aruba.');
}

$weekdays = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];
$adminMessage = '';
$selectedStudioId = (int)($_GET['studio_id'] ?? $_POST['studio_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['schedule_action'])) {
  $providedToken = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_csrf'], $providedToken)) {
    http_response_code(400);
    $adminMessage = 'La sessione è scaduta. Ricarica la pagina e riprova.';
  } else {
    $scheduleAction = (string)$_POST['schedule_action'];
    $selectedStudioId = (int)($_POST['studio_id'] ?? 0);
    $studioCheck = $pdo->prepare('SELECT id FROM studi WHERE id = :id AND attivo = 1');
    $studioCheck->execute([':id' => $selectedStudioId]);

    if (!$studioCheck->fetchColumn()) {
      $adminMessage = 'Seleziona uno studio attivo.';
    } elseif ($scheduleAction === 'add') {
      $weekday = (int)($_POST['giorno_settimana'] ?? 0);
      $startTime = trim((string)($_POST['ora_inizio'] ?? ''));
      $endTime = trim((string)($_POST['ora_fine'] ?? ''));
      $slotInterval = (int)($_POST['intervallo_minuti'] ?? 30);
      $validTime = static fn(string $time): bool => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;

      if (!isset($weekdays[$weekday]) || !$validTime($startTime) || !$validTime($endTime) || $startTime >= $endTime || !in_array($slotInterval, [15, 30, 60], true)) {
        $adminMessage = 'Controlla giorno, orari e intervallo degli slot.';
      } else {
        $overlap = $pdo->prepare(
          'SELECT COUNT(*) FROM orari_studio
           WHERE id_studio = :studio AND giorno_settimana = :weekday AND attivo = 1
             AND ora_inizio < :end_time AND ora_fine > :start_time'
        );
        $overlap->execute([
          ':studio' => $selectedStudioId,
          ':weekday' => $weekday,
          ':end_time' => $endTime,
          ':start_time' => $startTime,
        ]);

        if ((int)$overlap->fetchColumn() > 0) {
          $adminMessage = 'Questa fascia si sovrappone a un orario già configurato.';
        } else {
          $insert = $pdo->prepare(
            'INSERT INTO orari_studio (id_studio, giorno_settimana, ora_inizio, ora_fine, intervallo_minuti)
             VALUES (:studio, :weekday, :start_time, :end_time, :interval)'
          );
          $insert->execute([
            ':studio' => $selectedStudioId,
            ':weekday' => $weekday,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':interval' => $slotInterval,
          ]);
          header('Location: booking-admin.php?studio_id=' . $selectedStudioId . '&notice=added#agenda');
          exit;
        }
      }
    } elseif ($scheduleAction === 'delete') {
      $scheduleId = (int)($_POST['schedule_id'] ?? 0);
      $delete = $pdo->prepare('DELETE FROM orari_studio WHERE id = :id AND id_studio = :studio');
      $delete->execute([':id' => $scheduleId, ':studio' => $selectedStudioId]);
      header('Location: booking-admin.php?studio_id=' . $selectedStudioId . '&notice=deleted#agenda');
      exit;
    }
  }
}

$studios = $pdo->query('SELECT id, nome FROM studi WHERE attivo = 1 ORDER BY nome')->fetchAll();
if ($selectedStudioId === 0 && !empty($studios)) {
  $selectedStudioId = (int)$studios[0]['id'];
}

$scheduleRows = [];
$scheduleTableReady = true;
try {
  $scheduleStmt = $pdo->prepare(
    'SELECT id, giorno_settimana, ora_inizio, ora_fine, intervallo_minuti
     FROM orari_studio WHERE id_studio = :studio AND attivo = 1
     ORDER BY giorno_settimana, ora_inizio'
  );
  $scheduleStmt->execute([':studio' => $selectedStudioId]);
  $scheduleRows = $scheduleStmt->fetchAll();
} catch (PDOException $e) {
  $scheduleTableReady = false;
}

if (isset($_GET['notice'])) {
  $adminMessage = $_GET['notice'] === 'added' ? 'Fascia oraria aggiunta.' : 'Fascia oraria rimossa.';
}

$stmt = $pdo->query(
    "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.stato, s.nome AS servizio, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email, st.nome AS studio_nome
     FROM appuntamenti a
     LEFT JOIN servizi s ON s.id = a.id_servizio
     LEFT JOIN clienti c ON c.id = a.id_cliente
     LEFT JOIN studi st ON st.id = a.id_studio
     ORDER BY a.data_appuntamento DESC, a.ora_inizio DESC
     LIMIT 20"
);

$appointments = $stmt->fetchAll();
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin prenotazioni</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 32px; background: #f5f1eb; color: #1d1a18; }
      table { width: 100%; border-collapse: collapse; background: white; }
      th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
      th { background: #2a201d; color: white; }
      .status { padding: 4px 8px; border-radius: 999px; font-size: 12px; background: #f2e6d9; color: #5a382c; }
      .empty { padding: 16px; background: white; border: 1px solid #ddd; }
      .admin-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
      .admin-links { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
      .admin-links a, .admin-links button { padding: 10px 14px; border: 0; background: #2a201d; color: #fff; font: inherit; font-size: 13px; text-decoration: none; cursor: pointer; }
      .schedule-panel { margin: 24px 0 34px; padding: 22px; border: 1px solid #ddd; background: #fff; }
      .schedule-panel h2 { margin: 0 0 8px; }
      .schedule-panel p { color: #716862; }
      .schedule-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 12px; align-items: end; margin: 18px 0; }
      .schedule-form label { display: grid; gap: 6px; font-size: 13px; font-weight: 700; }
      .schedule-form input, .schedule-form select { width: 100%; min-height: 42px; padding: 8px; border: 1px solid #b9b1aa; background: #fff; font: inherit; }
      .schedule-form button, .schedule-list button { min-height: 42px; padding: 8px 14px; border: 0; background: #2a201d; color: #fff; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
      .schedule-list button { min-height: 34px; background: #8b4438; }
      .schedule-list { margin-top: 18px; }
      .notice { margin: 12px 0; padding: 12px 14px; border-left: 3px solid #8b4438; background: #f7f1ea; }
      .inline-form { margin: 0; }
      @media (max-width: 640px) { body { margin: 16px; } .schedule-panel { padding: 16px; } table { font-size: 13px; } th, td { padding: 7px; } }
    </style>
  </head>
  <body>
    <div class="admin-header">
      <h1>Gestione prenotazioni e agenda</h1>
      <div class="admin-links">
        <a href="booking-admin-advanced.php">Gestione avanzata</a>
        <form method="post" class="inline-form">
          <input type="hidden" name="admin_action" value="logout" />
          <button type="submit">Esci</button>
        </form>
      </div>
    </div>

    <section class="schedule-panel" id="agenda" aria-labelledby="agenda-title">
      <h2 id="agenda-title">Disponibilità settimanale</h2>
      <p>Configura i giorni di presenza, le fasce orarie e ogni quanti minuti proporre uno slot per ciascuno studio.</p>
      <p>Il passo resta quello configurato. Dopo una prenotazione, gli orari liberi ripartono dalla fine del trattamento più 5 minuti di pausa, senza spostare gli appuntamenti esistenti.</p>
      <?php if ($adminMessage !== ''): ?>
        <p class="notice" role="status"><?= htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <?php if (!$scheduleTableReady): ?>
        <div class="empty">Manca la tabella agenda. Importa <strong>backend-aruba-agenda.sql</strong> in phpMyAdmin e ricarica questa pagina.</div>
      <?php elseif (empty($studios)): ?>
        <div class="empty">Non risultano studi attivi nel database. Aggiungi prima le sedi alla tabella <strong>studi</strong>.</div>
      <?php else: ?>
        <form method="get" class="schedule-form">
          <label for="studio-id">Studio
            <select id="studio-id" name="studio_id" onchange="this.form.submit()">
              <?php foreach ($studios as $studio): ?>
                <option value="<?= (int)$studio['id'] ?>" <?= (int)$studio['id'] === $selectedStudioId ? 'selected' : '' ?>><?= htmlspecialchars((string)$studio['nome'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </form>
        <form method="post" class="schedule-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
          <input type="hidden" name="studio_id" value="<?= $selectedStudioId ?>" />
          <input type="hidden" name="schedule_action" value="add" />
          <label for="weekday">Giorno
            <select id="weekday" name="giorno_settimana" required>
              <?php foreach ($weekdays as $day => $label): ?>
                <option value="<?= $day ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label for="start-time">Dalle
            <input id="start-time" name="ora_inizio" type="time" required />
          </label>
          <label for="end-time">Alle
            <input id="end-time" name="ora_fine" type="time" required />
          </label>
          <label for="slot-interval">Nuovo slot ogni
            <select id="slot-interval" name="intervallo_minuti">
              <option value="15">15 minuti</option>
              <option value="30" selected>30 minuti</option>
              <option value="60">60 minuti</option>
            </select>
          </label>
          <button type="submit">Aggiungi fascia</button>
        </form>
        <?php if (empty($scheduleRows)): ?>
          <div class="empty">Nessuna disponibilità configurata per questo studio. Finché non aggiungi una fascia, il sito non mostrerà orari prenotabili.</div>
        <?php else: ?>
          <table class="schedule-list">
            <thead><tr><th>Giorno</th><th>Presenza</th><th>Passo slot</th><th>Azioni</th></tr></thead>
            <tbody>
              <?php foreach ($scheduleRows as $schedule): ?>
                <tr>
                  <td><?= htmlspecialchars($weekdays[(int)$schedule['giorno_settimana']] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(substr((string)$schedule['ora_inizio'], 0, 5) . '–' . substr((string)$schedule['ora_fine'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= (int)$schedule['intervallo_minuti'] ?> min</td>
                  <td>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
                      <input type="hidden" name="studio_id" value="<?= $selectedStudioId ?>" />
                      <input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>" />
                      <input type="hidden" name="schedule_action" value="delete" />
                      <button type="submit">Rimuovi</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <h2>Ultime prenotazioni</h2>
    <?php if (empty($appointments)): ?>
      <div class="empty">Nessuna prenotazione trovata.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Studio</th>
            <th>Cliente</th>
            <th>Servizio</th>
            <th>Data</th>
            <th>Ora</th>
            <th>Stato</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $booking): ?>
            <tr>
              <td><?= htmlspecialchars((string)$booking['id']) ?></td>
              <td><?= htmlspecialchars((string)$booking['studio_nome']) ?></td>
              <td><?= htmlspecialchars((string)$booking['cliente_nome'] . ' ' . ($booking['cliente_cognome'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)$booking['servizio']) ?></td>
              <td><?= htmlspecialchars((string)$booking['data_appuntamento']) ?></td>
              <td><?= htmlspecialchars((string)$booking['ora_inizio']) ?></td>
              <td><span class="status"><?= htmlspecialchars((string)$booking['stato']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </body>
</html>
