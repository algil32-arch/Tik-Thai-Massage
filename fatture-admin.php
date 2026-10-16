<?php
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/fattura-cortesia-renderer.php';
require_once __DIR__ . '/fattura-numerazione.php';

function renumberInvoice(PDO $pdo, int $invoiceId, string $newNumber): void
{
  if (!class_exists(DOMDocument::class)) {
    throw new RuntimeException('Estensione PHP DOM non disponibile: impossibile aggiornare in sicurezza XML e PDF.');
  }

  $invoiceStmt = $pdo->prepare(
    "SELECT f.id, f.numero_fattura, f.data_emissione, f.data_scadenza, f.stato, f.data_invio_sdi,
            f.protocollo_sdi, f.xml_path, f.natura_iva, f.bollo_virtuale, f.bollo_addebitato,
            f.importo_bollo, f.importo_netto, f.iva_totale, f.importo_totale,
            c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale,
            c.codice_fiscale AS cliente_cf, c.partita_iva AS cliente_piva, c.indirizzo AS cliente_indirizzo,
            c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
            p.nome AS fornitore_nome, p.cognome AS fornitore_cognome, p.ragione_sociale AS fornitore_ragione_sociale,
            p.partita_iva AS fornitore_piva, p.codice_fiscale AS fornitore_cf, p.email AS fornitore_email,
            p.indirizzo AS fornitore_indirizzo, p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
            p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
     FROM fatture f
     LEFT JOIN clienti c ON c.id = f.id_cliente
     LEFT JOIN professionisti p ON p.id = f.id_professionista
     WHERE f.id = :id
     FOR UPDATE"
  );
  $invoiceStmt->execute([':id' => $invoiceId]);
  $invoice = $invoiceStmt->fetch();
  if (!$invoice) {
    throw new RuntimeException('Fattura non trovata.');
  }
  if (in_array((string)$invoice['stato'], ['inviata', 'annullata'], true)
      || !empty($invoice['data_invio_sdi'])
      || !empty($invoice['protocollo_sdi'])) {
    throw new RuntimeException('La numerazione non è modificabile dopo l’invio allo SDI o l’annullamento della fattura.');
  }
  if ($newNumber === (string)$invoice['numero_fattura']) {
    return;
  }
  if (substr($newNumber, 3, 4) !== substr((string)$invoice['data_emissione'], 0, 4)) {
    throw new RuntimeException('L’anno del numero fattura deve coincidere con l’anno di emissione.');
  }

  $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM fatture WHERE numero_fattura = :number AND id <> :id');
  $duplicateStmt->execute([':number' => $newNumber, ':id' => $invoiceId]);
  if ((int)$duplicateStmt->fetchColumn() > 0) {
    throw new RuntimeException('Questo numero fattura è già in uso.');
  }

  $xmlRelativePath = (string)($invoice['xml_path'] ?? '');
  if (!str_starts_with($xmlRelativePath, 'documents/fatture/xml/')) {
    throw new RuntimeException('Percorso XML fattura mancante o non valido.');
  }
  $oldXmlPath = __DIR__ . '/' . $xmlRelativePath;
  if (!is_file($oldXmlPath)) {
    throw new RuntimeException('File XML originale non trovato: nessuna modifica salvata.');
  }

  $xml = new DOMDocument('1.0', 'UTF-8');
  $xml->preserveWhiteSpace = false;
  $xml->formatOutput = true;
  if (!$xml->load($oldXmlPath, LIBXML_NONET)) {
    throw new RuntimeException('Impossibile leggere l’XML originale: nessuna modifica salvata.');
  }
  $xpath = new DOMXPath($xml);
  $numeroNodes = $xpath->query('//*[local-name()="Numero"]');
  if ($numeroNodes === false) {
    throw new RuntimeException('Impossibile individuare il numero nell’XML originale.');
  }
  if ($numeroNodes->length !== 1) {
    throw new RuntimeException('Nodo Numero non univoco nell’XML: nessuna modifica salvata.');
  }
  $numeroNodes->item(0)->textContent = $newNumber;

  $linesStmt = $pdo->prepare(
    'SELECT descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva
     FROM righe_fattura WHERE id_fattura = :id ORDER BY id'
  );
  $linesStmt->execute([':id' => $invoiceId]);
  $invoiceData = $invoice;
  $invoiceData['righe'] = $linesStmt->fetchAll();
  $invoiceData['iva_percentuale'] = (float)($invoiceData['righe'][0]['iva_percentuale'] ?? 0);
  foreach (['bollo_virtuale', 'bollo_addebitato'] as $field) {
    $invoiceData[$field] = (int)($invoiceData[$field] ?? 0);
  }

  ensureInvoiceDirectoriesForAdmin();
  $pdfRelativePath = 'documents/fatture/pdf/' . $newNumber . '.pdf';
  $copyRelativePath = 'documents/fatture/copie/' . $newNumber . '_copia_di_cortesia.pdf';
  $newXmlRelativePath = 'documents/fatture/xml/' . $newNumber . '.xml';
  $invoicePdf = renderInvoiceCourtesyPdf($newNumber, $invoiceData);
  $courtesyPdf = renderInvoiceCourtesyPdf($newNumber, $invoiceData, true);
  $xmlContents = $xml->saveXML();
  if ($xmlContents === false
      || file_exists(__DIR__ . '/' . $pdfRelativePath)
      || file_exists(__DIR__ . '/' . $copyRelativePath)
      || file_exists(__DIR__ . '/' . $newXmlRelativePath)) {
    throw new RuntimeException('Il numero indicato è già associato a file sul server, oppure l’XML non è leggibile.');
  }
  $createdFiles = [];
  try {
    foreach ([
      $pdfRelativePath => $invoicePdf,
      $copyRelativePath => $courtesyPdf,
      $newXmlRelativePath => $xmlContents,
    ] as $relativePath => $contents) {
      if (file_put_contents(__DIR__ . '/' . $relativePath, $contents) === false) {
        throw new RuntimeException('Impossibile aggiornare i file fattura; il numero non è stato modificato nel database.');
      }
      $createdFiles[] = __DIR__ . '/' . $relativePath;
    }

    $pdo->prepare('UPDATE fatture SET numero_fattura = :number, pdf_path = :pdf, xml_path = :xml WHERE id = :id')
      ->execute([':number' => $newNumber, ':pdf' => $pdfRelativePath, ':xml' => $newXmlRelativePath, ':id' => $invoiceId]);
    foreach ([
      'originale' => $pdfRelativePath,
      'copia_di_cortesia' => $copyRelativePath,
      'xml' => $newXmlRelativePath,
    ] as $type => $path) {
      $documentStmt = $pdo->prepare(
        'UPDATE documenti_fattura SET path_file = :path, created_at = NOW()
         WHERE id_fattura = :id AND tipo_documento = :type'
      );
      $documentStmt->execute([':path' => $path, ':id' => $invoiceId, ':type' => $type]);
      if ($documentStmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare(
          'SELECT COUNT(*) FROM documenti_fattura WHERE id_fattura = :id AND tipo_documento = :type'
        );
        $existsStmt->execute([':id' => $invoiceId, ':type' => $type]);
        if ((int)$existsStmt->fetchColumn() === 0) {
          $pdo->prepare(
            'INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at)
             VALUES (:id, :type, :path, NOW())'
          )->execute([':id' => $invoiceId, ':type' => $type, ':path' => $path]);
        }
      }
    }
  } catch (Throwable $exception) {
    foreach ($createdFiles as $createdFile) {
      if (is_file($createdFile) && !unlink($createdFile)) {
        error_log('Could not remove incomplete invoice file after renumbering: ' . $createdFile);
      }
    }
    throw $exception;
  }
}

function ensureInvoiceDirectoriesForAdmin(): void
{
  foreach (['pdf', 'xml', 'copie'] as $directory) {
    $path = __DIR__ . '/documents/fatture/' . $directory;
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
      throw new RuntimeException('Impossibile creare la cartella documenti fattura.');
    }
  }
}

try {
  $pdo = dbConnection();
} catch (Throwable $e) {
  http_response_code(503);
  die('Database non raggiungibile. Verifica la configurazione privata e i dati Aruba.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'renumber_invoice') {
  if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])) {
    http_response_code(400);
    die('Sessione scaduta. Ricarica la pagina e riprova.');
  }
  $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  $newNumber = strtoupper(trim((string)($_POST['new_invoice_number'] ?? '')));
  if ($invoiceId === false || preg_match('/^TS-\d{4}-\d{5}$/', $newNumber) !== 1) {
    header('Location: fatture-admin.php?delete=error&msg=' . rawurlencode('Inserisci un numero nel formato TS-AAAA-NNNNN.'));
    exit;
  }
  try {
    $lockName = acquireInvoiceNumberLock($pdo, substr($newNumber, 3, 4) . '-01-01');
    try {
      $pdo->beginTransaction();
      renumberInvoice($pdo, (int)$invoiceId, $newNumber);
      $pdo->commit();
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    } finally {
      releaseInvoiceNumberLock($pdo, $lockName);
    }
    header('Location: fatture-admin.php?delete=ok&msg=' . rawurlencode('Numero aggiornato; PDF originale, copia di cortesia e XML sono stati rigenerati.'));
    exit;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $message = $exception instanceof RuntimeException && !($exception instanceof PDOException)
      ? $exception->getMessage()
      : 'Modifica numero fattura non completata.';
    if (!$exception instanceof RuntimeException || $exception instanceof PDOException) {
      error_log('Invoice renumbering failed: ' . $exception->getMessage());
    }
    header('Location: fatture-admin.php?delete=error&msg=' . rawurlencode($message));
    exit;
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_invoice') {
  if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])) {
    http_response_code(400);
    die('Sessione scaduta. Ricarica la pagina e riprova.');
  }

  $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($invoiceId === false) {
    header('Location: fatture-admin.php?delete=error&msg=' . rawurlencode('Identificativo fattura non valido.'));
    exit;
  }

  try {
    $pdo->beginTransaction();
    $invoiceStmt = $pdo->prepare('SELECT id, numero_fattura, stato FROM fatture WHERE id = :id FOR UPDATE');
    $invoiceStmt->execute([':id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch();
    if (!$invoice) {
      throw new RuntimeException('Fattura non trovata.');
    }
    if ((string)$invoice['stato'] === 'pagata') {
      throw new RuntimeException('La fattura pagata non può essere eliminata.');
    }

    $paymentStmt = $pdo->prepare('SELECT COUNT(*) FROM pagamenti WHERE id_fattura = :id');
    $paymentStmt->execute([':id' => $invoiceId]);
    if ((int)$paymentStmt->fetchColumn() > 0) {
      throw new RuntimeException('La fattura ha pagamenti registrati: non è possibile eliminarla.');
    }

    if (preg_match('/^TS-\d{4}-(\d{5})$/', (string)$invoice['numero_fattura'], $matches) === 1) {
      $appointmentStmt = $pdo->prepare(
        "SELECT stato_pagamento, metodo_pagamento
         FROM appuntamenti
         WHERE id = :id
         LIMIT 1"
      );
      $appointmentStmt->execute([':id' => (int)$matches[1]]);
      $appointment = $appointmentStmt->fetch();
      if ($appointment && $appointment['metodo_pagamento'] === 'online' && $appointment['stato_pagamento'] === 'pagato') {
        throw new RuntimeException('La fattura è collegata a un appuntamento online pagato: non è possibile eliminarla.');
      }
    }

    $pdo->prepare('DELETE FROM righe_fattura WHERE id_fattura = :id')->execute([':id' => $invoiceId]);
    $pdo->prepare('DELETE FROM documenti_fattura WHERE id_fattura = :id')->execute([':id' => $invoiceId]);
    $pdo->prepare('DELETE FROM fatture WHERE id = :id')->execute([':id' => $invoiceId]);
    $pdo->commit();
    header('Location: fatture-admin.php?delete=ok&msg=' . rawurlencode('Fattura eliminata dal database. I file PDF/XML su disco non sono stati rimossi.'));
    exit;
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $message = $exception instanceof RuntimeException && !($exception instanceof PDOException)
      ? $exception->getMessage()
      : 'Eliminazione non completata. Nessuna modifica salvata.';
    if (!$exception instanceof RuntimeException || $exception instanceof PDOException) {
      error_log('Invoice deletion failed: ' . $exception->getMessage());
    }
    header('Location: fatture-admin.php?delete=error&msg=' . rawurlencode($message));
    exit;
  }
}

function documentPublicHref(string $path): string
{
  $value = trim($path);
  if ($value === '') {
    return '';
  }

  $normalized = str_replace('\\', '/', $value);

  if (preg_match('/^https?:\/\//i', $normalized) === 1) {
    return $normalized;
  }

  $root = str_replace('\\', '/', __DIR__);
  if (strpos($normalized, $root . '/') === 0) {
    $normalized = substr($normalized, strlen($root) + 1);
  } else {
    $docsPos = strpos($normalized, 'documents/');
    if ($docsPos !== false) {
      $normalized = substr($normalized, $docsPos);
    }
  }

  return ltrim($normalized, '/');
}

$invoiceStmt = $pdo->query(
    "SELECT f.id, f.numero_fattura, f.data_emissione, f.data_invio_sdi, f.protocollo_sdi,
            f.xml_path, f.importo_totale, f.stato,
            f.tipo_documento, f.natura_iva, f.importo_rivalsa, f.bollo_virtuale, f.bollo_addebitato,
            c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale, c.tipo_cliente, c.email AS cliente_email,
            st.nome AS studio_nome
     FROM fatture f
     LEFT JOIN clienti c ON c.id = f.id_cliente
     LEFT JOIN studi st ON st.id = f.id_studio
     ORDER BY f.data_emissione DESC, f.id DESC"
);
$invoices = $invoiceStmt->fetchAll();

$docStmt = $pdo->query(
    "SELECT id_fattura, tipo_documento, path_file, created_at FROM documenti_fattura ORDER BY created_at DESC"
);
$documents = $docStmt->fetchAll();

$onlinePaidStmt = $pdo->query(
    "SELECT a.id AS appointment_id,
            a.data_appuntamento,
            a.ora_inizio,
            a.created_at,
            c.nome AS cliente_nome,
            c.cognome AS cliente_cognome,
            s.nome AS servizio_nome,
            st.nome AS studio_nome,
            f.id AS existing_invoice_id
     FROM appuntamenti a
     INNER JOIN clienti c ON c.id = a.id_cliente
     INNER JOIN servizi s ON s.id = a.id_servizio
     INNER JOIN studi st ON st.id = a.id_studio
     LEFT JOIN fatture f
       ON f.numero_fattura = CONCAT('TS-', YEAR(CURDATE()), '-', LPAD(a.id, 5, '0'))
       OR f.note LIKE CONCAT('%[appuntamento:', a.id, ']%')
     WHERE a.metodo_pagamento = 'online'
       AND a.stato_pagamento = 'pagato'
     ORDER BY a.created_at DESC
     LIMIT 20"
);
$onlinePaidRows = $onlinePaidStmt->fetchAll();

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

$exportMessage = '';
$exportMessageType = '';
if (isset($_GET['export'])) {
  $exportMessage = trim((string)($_GET['msg'] ?? ''));
  $exportMessageType = ((string)$_GET['export'] === 'ok') ? 'success' : 'error';
}

if (isset($_GET['delete'])) {
  $exportMessage = trim((string)($_GET['msg'] ?? ''));
  $exportMessageType = (string)$_GET['delete'] === 'ok' ? 'success' : 'error';
}

$batchPath = trim((string)($_GET['batch'] ?? ''));
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
      .topbar-actions { display: flex; flex-wrap: wrap; gap: 10px; }
      .message {
        margin: 0 0 18px;
        padding: 12px 14px;
        border: 1px solid var(--line);
        background: #fff;
      }
      .message.success {
        border-color: #b9d8c4;
        background: #eff8f2;
        color: #2f5d3f;
      }
      .message.error {
        border-color: #e2b7b1;
        background: #fff4f2;
        color: #8b4438;
      }
      .export-panel {
        border: 1px solid var(--line);
        background: #fff;
        padding: 16px;
        margin-bottom: 20px;
      }
      .export-panel h2 {
        margin: 0 0 12px;
        font-size: 18px;
      }
      .export-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
      }
      .export-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .export-field label {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
      }
      .export-field input,
      .export-field select {
        min-height: 40px;
        border: 1px solid var(--line);
        padding: 8px 10px;
        font: inherit;
        background: #fff;
      }
      .export-actions {
        margin-top: 12px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
      }
      .checkline {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
      }
      .path-hint {
        margin: 10px 0 0;
        font-size: 13px;
      }
      .path-links {
        margin-top: 10px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
      }
      .quick-panel {
        border: 1px solid var(--line);
        background: #fff;
        padding: 16px;
        margin-bottom: 20px;
      }
      .quick-panel h2 {
        margin: 0 0 10px;
        font-size: 17px;
      }
      .quick-list {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
      }
      .quick-list th,
      .quick-list td {
        border: 1px solid var(--line);
        padding: 8px;
        font-size: 13px;
        vertical-align: top;
      }
      .quick-list th {
        background: #f4eee6;
      }
      .mini-btn {
        min-height: 30px;
        padding: 6px 10px;
        border: 1px solid var(--line);
        background: #fff;
        color: var(--ink);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        cursor: pointer;
      }
      .number-editor {
        display: grid;
        gap: 5px;
        margin: 8px 0;
        min-width: 150px;
      }
      .number-editor label { color: var(--muted); font-size: 11px; }
      .number-editor input {
        width: 100%;
        min-width: 0;
        padding: 7px;
        border: 1px solid var(--line);
        border-radius: 4px;
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
      .btn.alt {
        background: #76665d;
      }
      .table-actions {
        margin-top: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
      }
      .small {
        font-size: 13px;
      }
      .check-cell {
        width: 46px;
        text-align: center;
      }
      @media (max-width: 760px) {
        .topbar { flex-direction: column; align-items: flex-start; }
        table { display: block; overflow-x: auto; }
      }
    </style>
  </head>
  <body>
      <?php renderAdminToolbar(); ?>
      <div class="container">
      <div class="topbar">
        <h1>Gestione fatture</h1>
        <div class="topbar-actions">
          <a class="btn" href="fattura-manuale.php">Nuova fattura manuale</a>
          <a class="btn" href="booking-admin-advanced.php">Torna all'admin</a>
        </div>
      </div>
      <?php if (isset($_GET['manuale']) && $_GET['manuale'] === 'creata'): ?>
        <p class="empty" role="status">Bozza manuale registrata correttamente.</p>
      <?php endif; ?>
      <?php if ($exportMessage !== ''): ?>
        <p class="message <?= $exportMessageType ?>" role="status"><?= htmlspecialchars($exportMessage) ?></p>
      <?php endif; ?>

      <section class="export-panel" aria-labelledby="export-title">
        <h2 id="export-title">Esporta lotto XML per Aruba (drag and drop)</h2>
        <form method="post" action="fatture-export-lotto.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
          <input type="hidden" name="export_mode" value="period" />
          <div class="export-grid">
            <div class="export-field">
              <label for="period">Tipo lotto</label>
              <select id="period" name="period">
                <option value="daily">Giornaliero</option>
                <option value="weekly">Settimanale</option>
              </select>
            </div>
            <div class="export-field">
              <label for="reference_date">Data di riferimento</label>
              <input id="reference_date" name="reference_date" type="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required />
            </div>
          </div>
          <div class="export-actions">
            <label class="checkline" for="include_drafts">
              <input id="include_drafts" name="include_drafts" type="checkbox" value="1" />
              <span>Includi anche bozze</span>
            </label>
            <button class="btn" type="submit">Genera cartella XML</button>
          </div>
        </form>
        <?php if ($batchPath !== ''): ?>
          <p class="path-hint">Ultimo lotto creato: <strong><?= htmlspecialchars($batchPath) ?></strong></p>
        <?php endif; ?>
        <?php $zipPath = trim((string)($_GET['zip'] ?? '')); ?>
        <?php if ($zipPath !== ''): ?>
          <p class="path-hint">ZIP creato: <strong><?= htmlspecialchars($zipPath) ?></strong></p>
          <div class="path-links">
            <a class="btn alt" href="<?= htmlspecialchars($zipPath) ?>" download>Download ZIP</a>
          </div>
        <?php endif; ?>
      </section>

      <section class="quick-panel" aria-labelledby="quick-invoice-title">
        <h2 id="quick-invoice-title">Crea fattura da pagamento online</h2>
        <form method="post" action="fattura-da-pagamento.php" class="export-actions">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
          <div class="export-field" style="max-width:220px;">
            <label for="payment_id">ID pagamento</label>
            <input id="payment_id" name="payment_id" type="number" min="1" step="1" placeholder="es. 12" required />
          </div>
          <button class="btn" type="submit">Crea fattura</button>
        </form>
        <?php if (empty($onlinePaidRows)): ?>
          <p class="path-hint">Nessun pagamento online pagato trovato negli ultimi record.</p>
        <?php else: ?>
          <table class="quick-list">
            <thead>
              <tr>
                <th>ID appuntamento</th>
                <th>Cliente</th>
                <th>Servizio</th>
                <th>Studio</th>
                <th>Quando</th>
                <th>Stato fattura</th>
                <th>Azione</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($onlinePaidRows as $row): ?>
                <tr>
                  <td>#<?= (int)$row['appointment_id'] ?></td>
                  <td><?= htmlspecialchars(trim((string)$row['cliente_nome'] . ' ' . (string)$row['cliente_cognome'])) ?></td>
                  <td><?= htmlspecialchars((string)$row['servizio_nome']) ?></td>
                  <td><?= htmlspecialchars((string)$row['studio_nome']) ?></td>
                  <td><?= htmlspecialchars((string)$row['data_appuntamento']) ?> <?= htmlspecialchars(substr((string)$row['ora_inizio'], 0, 5)) ?></td>
                  <td>
                    <?php if (!empty($row['existing_invoice_id'])): ?>
                      Gia creata (ID <?= (int)$row['existing_invoice_id'] ?>)
                    <?php else: ?>
                      Da creare
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (empty($row['existing_invoice_id'])): ?>
                      <form method="post" action="fattura-da-pagamento.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
                        <input type="hidden" name="payment_id" value="<?= (int)$row['appointment_id'] ?>" />
                        <button class="mini-btn" type="submit">Crea ora</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="fattura-da-pagamento.php" onsubmit="return confirm('Confermi annullamento e rigenerazione della fattura?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
                        <input type="hidden" name="payment_id" value="<?= (int)$row['appointment_id'] ?>" />
                        <input type="hidden" name="action" value="replace" />
                        <button class="mini-btn" type="submit">Annulla e rigenera</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <div class="overview">
        <div class="chip">Totale fatture: <strong><?= (int)$totalInvoices ?></strong></div>
        <div class="chip">Emesse: <strong><?= (int)$emesse ?></strong></div>
        <div class="chip">Pagate: <strong><?= (int)$pagate ?></strong></div>
      </div>

      <?php if (empty($invoices)): ?>
        <div class="empty">Nessuna fattura generata.</div>
      <?php else: ?>
        <form method="post" action="fatture-export-lotto.php" id="selectedExportForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
          <input type="hidden" name="export_mode" value="selected" />
        <table>
          <thead>
            <tr>
              <th class="check-cell"><input type="checkbox" id="selectAllInvoices" aria-label="Seleziona tutte le fatture" /></th>
              <th>Numero</th>
              <th>Cliente</th>
              <th>Studio</th>
              <th>Data</th>
              <th>Totale</th>
              <th>Stato</th>
              <th>Documenti</th>
              <th>Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $invoice): ?>
              <tr>
                <td class="check-cell"><input type="checkbox" name="invoice_ids[]" value="<?= (int)$invoice['id'] ?>" class="invoice-check" /></td>
                <td><?= htmlspecialchars((string)$invoice['numero_fattura']) ?></td>
                <td>
                  <?php if (($invoice['tipo_cliente'] ?? '') === 'azienda' && !empty($invoice['ragione_sociale'])): ?>
                    <strong><?= htmlspecialchars((string)$invoice['ragione_sociale']) ?></strong><br>
                  <?php endif; ?>
                  <?= htmlspecialchars((string)$invoice['cliente_nome'] . ' ' . ($invoice['cliente_cognome'] ?? '')) ?><br>
                  <span class="muted"><?= htmlspecialchars((string)$invoice['cliente_email']) ?></span>
                </td>
                <td><?= htmlspecialchars((string)$invoice['studio_nome']) ?></td>
                <td><?= htmlspecialchars((string)$invoice['data_emissione']) ?></td>
                <td>
                  € <?= number_format((float)$invoice['importo_totale'], 2, ',', '.') ?><br>
                  <span class="muted">
                    <?= htmlspecialchars((string)$invoice['tipo_documento']) ?>
                    <?php if (!empty($invoice['natura_iva'])): ?> · <?= htmlspecialchars((string)$invoice['natura_iva']) ?><?php endif; ?>
                    <?php if ((float)$invoice['importo_rivalsa'] > 0): ?> · Rivalsa € <?= number_format((float)$invoice['importo_rivalsa'], 2, ',', '.') ?><?php endif; ?>
                    <?php if ((int)$invoice['bollo_virtuale'] === 1): ?> · Bollo virtuale<?= (int)$invoice['bollo_addebitato'] === 1 ? ' addebitato' : '' ?><?php endif; ?>
                  </span>
                </td>
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
                        <?php $path = documentPublicHref((string)$doc['path_file']); ?>
                        <?php if ($path === ''): continue; endif; ?>
                        <?php $cacheVersion = strtotime((string)($doc['created_at'] ?? '')) ?: 0; ?>
                        <?php $separator = strpos($path, '?') === false ? '?' : '&'; ?>
                        <a class="document-link" href="<?= htmlspecialchars($path . $separator . 'v=' . $cacheVersion) ?>" target="_blank" rel="noreferrer">
                          <?= htmlspecialchars((string)$doc['tipo_documento']) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <button
                    class="mini-btn"
                    type="submit"
                    form="refresh-invoice-<?= (int)$invoice['id'] ?>"
                    title="Aggiorna il layout dei PDF senza creare una nuova fattura"
                  >Rigenera PDF</button>
                  <?php if (!in_array((string)$invoice['stato'], ['inviata', 'annullata'], true)
                      && empty($invoice['data_invio_sdi']) && empty($invoice['protocollo_sdi'])
                      && !empty($invoice['xml_path'])): ?>
                    <div class="number-editor">
                      <label for="new-invoice-number-<?= (int)$invoice['id'] ?>">Numero fattura</label>
                      <input
                        id="new-invoice-number-<?= (int)$invoice['id'] ?>"
                        type="text"
                        name="new_invoice_number"
                        value="<?= htmlspecialchars((string)$invoice['numero_fattura']) ?>"
                        pattern="TS-[0-9]{4}-[0-9]{5}"
                        maxlength="13"
                        required
                        form="renumber-invoice-<?= (int)$invoice['id'] ?>"
                      />
                      <button
                        class="mini-btn"
                        type="submit"
                        form="renumber-invoice-<?= (int)$invoice['id'] ?>"
                        onclick="return confirm('Aggiornare la numerazione e rigenerare PDF originale, copia di cortesia e XML?');"
                      >Aggiorna numero</button>
                    </div>
                  <?php else: ?>
                    <span class="muted">Numerazione bloccata o XML non disponibile</span>
                  <?php endif; ?>
                  <?php if (($invoice['stato'] ?? '') === 'pagata'): ?>
                    <span class="muted">Protetta: pagata</span>
                  <?php else: ?>
                    <button
                      class="mini-btn"
                      type="submit"
                      form="delete-invoice-<?= (int)$invoice['id'] ?>"
                      onclick="return confirm('Eliminare definitivamente dal database questa fattura e i relativi record di dettaglio/documento? I file su disco resteranno.');"
                    >Elimina</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="table-actions">
          <span class="small muted">Seleziona una o piu fatture e genera un unico ZIP da caricare su Aruba.</span>
          <button class="btn alt" type="submit">Esporta selezionate in ZIP + email copia</button>
        </div>
        </form>
        <?php foreach ($invoices as $invoice): ?>
          <form
            id="renumber-invoice-<?= (int)$invoice['id'] ?>"
            method="post"
            action="fatture-admin.php"
            hidden
          >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
            <input type="hidden" name="action" value="renumber_invoice" />
            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>" />
          </form>
          <form
            id="delete-invoice-<?= (int)$invoice['id'] ?>"
            method="post"
            action="fatture-admin.php"
            hidden
          >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
            <input type="hidden" name="action" value="delete_invoice" />
            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>" />
          </form>
          <form
            id="refresh-invoice-<?= (int)$invoice['id'] ?>"
            method="post"
            action="fattura-da-pagamento.php"
            hidden
          >
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf']) ?>" />
            <input type="hidden" name="action" value="refresh_pdf" />
            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>" />
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <script>
      (function () {
        var master = document.getElementById('selectAllInvoices');
        if (!master) return;
        var checks = Array.prototype.slice.call(document.querySelectorAll('.invoice-check'));
        master.addEventListener('change', function () {
          checks.forEach(function (node) {
            node.checked = master.checked;
          });
        });
      })();
    </script>
  </body>
</html>
