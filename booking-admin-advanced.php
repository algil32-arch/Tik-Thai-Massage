<?php
require_once __DIR__ . '/admin-auth.php';

try {
  $pdo = dbConnection();
} catch (Throwable $e) {
  http_response_code(503);
  die('Database non raggiungibile. Verifica la configurazione privata e i dati Aruba.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'confirm') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE appuntamenti SET stato = 'confermato' WHERE id = :id")->execute([':id' => $id]);
        header('Location: booking-admin-advanced.php');
        exit;
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE appuntamenti SET stato = 'annullato' WHERE id = :id")->execute([':id' => $id]);
        header('Location: booking-admin-advanced.php');
        exit;
    }

    if ($action === 'generate_invoice') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE appuntamenti SET stato = 'fattura_generata' WHERE id = :id")->execute([':id' => $id]);
        header('Location: fattura-pa-generator.php?appointment_id=' . $id);
        exit;
    }
}

$stmt = $pdo->query(
        "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.stato, a.metodo_pagamento, a.stato_pagamento,
          s.nome AS servizio, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email, c.telefono AS cliente_telefono,
          c.tipo_cliente, c.ragione_sociale, c.codice_fiscale, c.partita_iva, c.codice_destinatario, c.pec,
          c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta, c.cap AS cliente_cap,
          st.nome AS studio_nome, serv.prezzo AS prezzo_servizio
     FROM appuntamenti a
     LEFT JOIN servizi serv ON serv.id = a.id_servizio
     LEFT JOIN clienti c ON c.id = a.id_cliente
     LEFT JOIN studi st ON st.id = a.id_studio
     LEFT JOIN servizi s ON s.id = a.id_servizio
     ORDER BY a.data_appuntamento DESC, a.ora_inizio DESC"
);
$appointments = $stmt->fetchAll();
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin prenotazioni avanzato</title>
    <style>
      body {
        margin: 0;
        background: #f7f1ea;
        font-family: Arial, sans-serif;
        color: #241d1a;
      }
      .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 20px;
      }
      h1 {
        margin-bottom: 20px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        box-shadow: 0 8px 18px rgba(0,0,0,0.05);
      }
      th, td {
        border: 1px solid #e5d9cd;
        padding: 12px 10px;
        text-align: left;
        vertical-align: top;
      }
      th {
        background: #2a201d;
        color: #fff;
      }
      .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        background: #f0e6dd;
        color: #5f4237;
        font-size: 12px;
        font-weight: bold;
      }
      .actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .btn {
        display: inline-block;
        min-height: 36px;
        padding: 8px 12px;
        border: 0;
        cursor: pointer;
        color: white;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .confirm { background: #2a201d; }
      .cancel { background: #8b4438; }
      .invoice { background: #4a5b3d; }
      .empty {
        padding: 20px;
        border: 1px solid #e5d9cd;
        background: white;
      }
      .overview {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 22px;
      }
      .chip {
        padding: 10px 14px;
        background: #fff;
        border: 1px solid #e5d9cd;
        font-size: 13px;
        color: #3b2d2a;
      }
      .link-row {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 18px;
      }
      .link-row a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 8px 14px;
        background: #2a201d;
        color: #fff;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .link-row { flex-wrap: wrap; gap: 10px; }
    </style>
  </head>
  <body>
    <div class="container">
      <h1>Admin prenotazioni</h1>
      <div class="link-row">
        <a href="fattura-manuale.php">Nuova fattura manuale</a>
        <a href="fatture-admin.php">Gestione fatture</a>
      </div>
      <div class="overview">
        <div class="chip">Area gestione appuntamenti</div>
        <div class="chip">Conferma, annulla e fattura</div>
      </div>
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
              <th>Prezzo</th>
              <th>Data</th>
              <th>Ora</th>
              <th>Pagamento</th>
              <th>Dati fiscali</th>
              <th>Stato</th>
              <th>Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($appointments as $booking): ?>
              <tr>
                <td><?= htmlspecialchars((string)$booking['id']) ?></td>
                <td><?= htmlspecialchars((string)$booking['studio_nome']) ?></td>
                <td>
                  <?= htmlspecialchars((string)$booking['cliente_nome'] . ' ' . ($booking['cliente_cognome'] ?? '')) ?><br>
                  <small><?= htmlspecialchars((string)$booking['cliente_email']) ?></small>
                </td>
                <td><?= htmlspecialchars((string)$booking['servizio']) ?></td>
                <td><?= htmlspecialchars((string)$booking['prezzo_servizio']) ?> €</td>
                <td><?= htmlspecialchars((string)$booking['data_appuntamento']) ?></td>
                <td><?= htmlspecialchars((string)$booking['ora_inizio']) ?></td>
                <td><?= htmlspecialchars((string)$booking['metodo_pagamento']) ?><br><small><?= htmlspecialchars((string)$booking['stato_pagamento']) ?></small></td>
                <td>
                  <?php if (($booking['tipo_cliente'] ?? '') === 'azienda'): ?>
                    <?= htmlspecialchars((string)$booking['ragione_sociale']) ?><br>
                    <small>P.IVA <?= htmlspecialchars((string)$booking['partita_iva']) ?></small>
                    <small><?= htmlspecialchars((string)($booking['codice_destinatario'] ?: $booking['pec'])) ?></small>
                  <?php else: ?>
                    <small>CF <?= htmlspecialchars((string)$booking['codice_fiscale']) ?></small>
                  <?php endif; ?><br>
                  <small><?= htmlspecialchars(trim((string)$booking['cliente_indirizzo'] . ', ' . (string)$booking['cliente_cap'] . ' ' . (string)$booking['cliente_citta'])) ?></small>
                  <?php if (!empty($booking['cliente_telefono'])): ?><br><small>Tel. <?= htmlspecialchars((string)$booking['cliente_telefono']) ?></small><?php endif; ?>
                </td>
                <td><span class="status"><?= htmlspecialchars((string)$booking['stato']) ?></span></td>
                <td>
                  <div class="actions">
                    <form method="post" action="booking-admin-advanced.php">
                      <input type="hidden" name="action" value="confirm" />
                      <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                      <button class="btn confirm" type="submit">Conferma</button>
                    </form>
                    <form method="post" action="booking-admin-advanced.php">
                      <input type="hidden" name="action" value="cancel" />
                      <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                      <button class="btn cancel" type="submit">Annulla</button>
                    </form>
                    <?php if (($booking['stato'] ?? '') === 'confermato'): ?>
                      <form method="post" action="booking-admin-advanced.php">
                        <input type="hidden" name="action" value="generate_invoice" />
                        <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                        <button class="btn invoice" type="submit">Genera fattura</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </body>
</html>
