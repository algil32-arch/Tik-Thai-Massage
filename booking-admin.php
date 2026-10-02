<?php
session_start();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'tikthai_booking';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    die('Database connection error: ' . $e->getMessage());
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
    </style>
  </head>
  <body>
    <h1>Gestione prenotazioni</h1>
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
