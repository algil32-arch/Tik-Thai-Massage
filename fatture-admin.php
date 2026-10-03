<?php
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

$invoiceStmt = $pdo->query(
    "SELECT f.id, f.numero_fattura, f.data_emissione, f.importo_totale, f.stato,
            c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email,
            st.nome AS studio_nome
     FROM fatture f
     LEFT JOIN clienti c ON c.id = f.id_cliente
     LEFT JOIN studi st ON st.id = f.id_studio
     ORDER BY f.data_emissione DESC, f.id DESC"
);
$invoices = $invoiceStmt->fetchAll();

$docStmt = $pdo->query(
    "SELECT id_fattura, tipo_documento, path_file FROM documenti_fattura ORDER BY created_at DESC"
);
$documents = $docStmt->fetchAll();

$docsByInvoice = [];
foreach ($documents as $doc) {
    $docsByInvoice[(int)$doc['id_fattura']][] = $doc;
}

$totalInvoices = count($invoices);
$emesse = 0;
$pagate = 0;
foreach ($invoices as $invoice) {
    if (($invoice['stato'] ?? '') === 'emessa') {
        $emesse++;
    }
    if (($invoice['stato'] ?? '') === 'pagata') {
        $pagate++;
    }
}
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Fatture | Thai Tik Massage</title>
    <style>
      :root {
        --ink: #2a201d;
        --muted: #716862;
        --paper: #fbf9f5;
        --warm: #eee8df;
        --accent: #8b4438;
        --line: #ded5cb;
        --success: #2f5d3f;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        background: #f7f2ed;
        color: var(--ink);
        font-family: Arial, sans-serif;
      }
      .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 20px 52px;
      }
      h1 {
        margin: 0 0 12px;
        font-size: clamp(30px, 4vw, 42px);
      }
      .overview {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 26px;
      }
      .chip {
        padding: 10px 14px;
        background: #fff;
        border: 1px solid var(--line);
        font-size: 13px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        box-shadow: 0 8px 20px rgba(0,0,0,0.04);
      }
      th, td {
        border: 1px solid var(--line);
        padding: 12px 10px;
        text-align: left;
        vertical-align: top;
      }
      th {
        background: #2a201d;
        color: white;
      }
      .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        background: #f4e9df;
        color: #5d4339;
      }
      .status.paid { background: #e7f0eb; color: var(--success); }
      .muted { color: var(--muted); }
      .documents {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
      }
      .document-link {
        display: inline-block;
        padding: 6px 10px;
        background: var(--warm);
        border: 1px solid var(--line);
        color: var(--ink);
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
      }
      .empty {
        padding: 22px;
        border: 1px solid var(--line);
        background: white;
      }
      .topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 18px;
      }
      .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 10px 16px;
        background: var(--ink);
        color: #fff;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
      }
      @media (max-width: 760px) {
        .topbar { flex-direction: column; align-items: flex-start; }
        table { display: block; overflow-x: auto; }
      }
    </style>
  </head>
  <body>
    <div class="container">
      <div class="topbar">
        <h1>Gestione fatture</h1>
        <a class="btn" href="booking-admin-advanced.php">Torna all'admin</a>
      </div>

      <div class="overview">
        <div class="chip">Totale fatture: <strong><?= (int)$totalInvoices ?></strong></div>
        <div class="chip">Emesse: <strong><?= (int)$emesse ?></strong></div>
        <div class="chip">Pagate: <strong><?= (int)$pagate ?></strong></div>
      </div>

      <?php if (empty($invoices)): ?>
        <div class="empty">Nessuna fattura generata.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Numero</th>
              <th>Cliente</th>
              <th>Studio</th>
              <th>Data</th>
              <th>Totale</th>
              <th>Stato</th>
              <th>Documenti</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $invoice): ?>
              <tr>
                <td><?= htmlspecialchars((string)$invoice['numero_fattura']) ?></td>
                <td>
                  <?= htmlspecialchars((string)$invoice['cliente_nome'] . ' ' . ($invoice['cliente_cognome'] ?? '')) ?><br>
                  <span class="muted"><?= htmlspecialchars((string)$invoice['cliente_email']) ?></span>
                </td>
                <td><?= htmlspecialchars((string)$invoice['studio_nome']) ?></td>
                <td><?= htmlspecialchars((string)$invoice['data_emissione']) ?></td>
                <td>€ <?= number_format((float)$invoice['importo_totale'], 2, ',', '.') ?></td>
                <td>
                  <span class="status <?= (($invoice['stato'] ?? '') === 'pagata') ? 'paid' : '' ?>"><?= htmlspecialchars((string)$invoice['stato']) ?></span>
                </td>
                <td>
                  <?php $docs = $docsByInvoice[(int)$invoice['id']] ?? []; ?>
                  <?php if (empty($docs)): ?>
                    <span class="muted">Nessun documento</span>
                  <?php else: ?>
                    <div class="documents">
                      <?php foreach ($docs as $doc): ?>
                        <?php $path = trim((string)$doc['path_file']); ?>
                        <?php if ($path === ''): continue; endif; ?>
                        <a class="document-link" href="<?= htmlspecialchars($path) ?>" target="_blank" rel="noreferrer">
                          <?= htmlspecialchars((string)$doc['tipo_documento']) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </body>
</html>
