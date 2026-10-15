<?php

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/fattura-xml-builder.php';

function redirectToInvoices(string $status, string $message, array $extra = []): never
{
    $params = array_merge([
        'export' => $status,
        'msg' => $message,
    ], $extra);
    header('Location: fatture-admin.php?' . http_build_query($params));
    exit;
}

function ensureRequiredInvoiceData(array $invoice): array
{
    $errors = [];

    if (trim((string)$invoice['numero_fattura']) === '') {
        $errors[] = 'numero_fattura mancante';
    }
    if (trim((string)$invoice['data_emissione']) === '') {
        $errors[] = 'data_emissione mancante';
    }

    try {
        feSupplierVat($invoice);
        feSupplierTaxCode($invoice);
        feSupplierEmail($invoice);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }

    $customerVat = trim((string)($invoice['cliente_piva'] ?? ''));
    $customerCf = trim((string)($invoice['cliente_cf'] ?? ''));
    if ($customerVat === '' && $customerCf === '') {
        $errors[] = 'cliente senza partita IVA e codice fiscale';
    }

    if (empty($invoice['righe']) || !is_array($invoice['righe'])) {
        $errors[] = 'nessuna riga fattura';
    }

    return $errors;
}

function buildFatturaPaXml(array $invoice): string
{
    return buildFatturaElettronicaXml($invoice);
}

function invoiceFileName(array $invoice): string
{
    $supplierVat = feSupplierVat($invoice);

    $number = preg_replace('/[^A-Za-z0-9]/', '', (string)$invoice['numero_fattura']) ?? '';
    if ($number === '') {
        $number = 'INV' . (int)$invoice['id'];
    }

    return 'IT' . $supplierVat . '_' . $number . '.xml';
}

function sendZipByEmail(string $zipPath, string $batchFolderName): bool
{
    $config = appConfig();
    $fromEmail = (string)($config['MAIL_FROM_EMAIL'] ?? 'info@thaitikmassage.it');
    $toEmail = 'info@thaitikmassage.it';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $content = @file_get_contents($zipPath);
    if ($content === false) {
        return false;
    }

    $boundary = 'batch-' . bin2hex(random_bytes(12));
    $filename = basename($zipPath);
    $subject = 'Lotto fatture XML Aruba - ' . $batchFolderName;
    $bodyText = "In allegato trovi il lotto XML in formato ZIP pronto per upload manuale su Aruba.\n\n"
        . "Lotto: {$batchFolderName}\n"
        . 'Generato il: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . "\n";

    $headers = [
        'From: Thai Tik Massage <' . $fromEmail . '>',
        'Reply-To: ' . $toEmail,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $message = '';
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $bodyText . "\r\n";

    $message .= '--' . $boundary . "\r\n";
    $message .= 'Content-Type: application/zip; name="' . $filename . '"' . "\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
    $message .= chunk_split(base64_encode($content));
    $message .= "\r\n--" . $boundary . "--\r\n";

    return @mail($toEmail, $subject, $message, implode("\r\n", $headers));
}

function createZipFromDirectory(string $sourceDirectory, string $zipPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $sourceDirectory = rtrim($sourceDirectory, DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $filePath = $file->getPathname();
        $localName = substr($filePath, strlen($sourceDirectory) + 1);
        $zip->addFile($filePath, str_replace('\\', '/', $localName));
    }

    return $zip->close();
}

try {
    $pdo = dbConnection();
} catch (Throwable $e) {
    http_response_code(503);
    exit('Database non raggiungibile.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Metodo non consentito.');
}

$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if (!hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $csrfToken)) {
    redirectToInvoices('error', 'Token CSRF non valido.');
}

$exportMode = (string)($_POST['export_mode'] ?? 'period');
$invoices = [];
$periodLabel = 'selezionate';
$includeDrafts = false;
$startDateLabel = '';
$endDateLabel = '';

if ($exportMode === 'selected') {
    $selectedIds = $_POST['invoice_ids'] ?? [];
    if (!is_array($selectedIds) || count($selectedIds) === 0) {
        redirectToInvoices('error', 'Seleziona almeno una fattura prima di esportare.');
    }

    $invoiceIds = [];
    foreach ($selectedIds as $id) {
        $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed !== false) {
            $invoiceIds[] = (int)$parsed;
        }
    }
    $invoiceIds = array_values(array_unique($invoiceIds));
    if (empty($invoiceIds)) {
        redirectToInvoices('error', 'Le fatture selezionate non sono valide.');
    }

    $placeholdersIds = implode(', ', array_fill(0, count($invoiceIds), '?'));
    $sql = "SELECT f.id, f.numero_fattura, f.serie, f.data_emissione, f.tipo_documento, f.natura_iva,
               f.importo_rivalsa, f.bollo_virtuale, f.bollo_addebitato, f.importo_bollo,
               f.importo_netto, f.iva_totale, f.importo_totale, f.note, f.stato,
               c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale, c.tipo_cliente,
               c.codice_fiscale AS cliente_cf, c.partita_iva AS cliente_piva, c.codice_destinatario, c.pec AS cliente_pec,
               c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
               p.nome AS fornitore_nome, p.cognome AS fornitore_cognome, p.ragione_sociale AS fornitore_ragione_sociale,
               p.partita_iva AS fornitore_piva, p.codice_fiscale AS fornitore_cf, p.email AS fornitore_email,
               p.pec AS fornitore_pec,
               p.indirizzo AS fornitore_indirizzo, p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
               p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
        FROM fatture f
        LEFT JOIN clienti c ON c.id = f.id_cliente
        LEFT JOIN professionisti p ON p.id = f.id_professionista
        WHERE f.id IN ($placeholdersIds)
        ORDER BY f.data_emissione ASC, f.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($invoiceIds);
    $invoices = $stmt->fetchAll();

    if (empty($invoices)) {
        redirectToInvoices('error', 'Nessuna fattura trovata tra quelle selezionate.');
    }

    $dates = array_column($invoices, 'data_emissione');
    sort($dates);
    $startDateLabel = (string)($dates[0] ?? '');
    $endDateLabel = (string)($dates[count($dates) - 1] ?? '');
} else {
    $period = (string)($_POST['period'] ?? 'daily');
    if (!in_array($period, ['daily', 'weekly'], true)) {
        redirectToInvoices('error', 'Periodo non valido. Usa giornaliero o settimanale.');
    }

    $dateInput = (string)($_POST['reference_date'] ?? '');
    $referenceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
    if (!$referenceDate) {
        redirectToInvoices('error', 'Data di riferimento non valida.');
    }

    if ($period === 'daily') {
        $startDate = $referenceDate;
        $endDate = $referenceDate;
        $periodLabel = 'giornaliero';
    } else {
        $startDate = $referenceDate->modify('monday this week');
        $endDate = $startDate->modify('+6 days');
        $periodLabel = 'settimanale';
    }

    $startDateLabel = $startDate->format('Y-m-d');
    $endDateLabel = $endDate->format('Y-m-d');

    $includeDrafts = isset($_POST['include_drafts']) && $_POST['include_drafts'] === '1';
    $statuses = ['emessa', 'inviata', 'pagata'];
    if ($includeDrafts) {
        $statuses[] = 'bozza';
    }

    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
    $sql = "SELECT f.id, f.numero_fattura, f.serie, f.data_emissione, f.tipo_documento, f.natura_iva,
               f.importo_rivalsa, f.bollo_virtuale, f.bollo_addebitato, f.importo_bollo,
               f.importo_netto, f.iva_totale, f.importo_totale, f.note, f.stato,
               c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale, c.tipo_cliente,
               c.codice_fiscale AS cliente_cf, c.partita_iva AS cliente_piva, c.codice_destinatario, c.pec AS cliente_pec,
               c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
               p.nome AS fornitore_nome, p.cognome AS fornitore_cognome, p.ragione_sociale AS fornitore_ragione_sociale,
               p.partita_iva AS fornitore_piva, p.codice_fiscale AS fornitore_cf, p.email AS fornitore_email,
               p.pec AS fornitore_pec,
               p.indirizzo AS fornitore_indirizzo, p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
               p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
        FROM fatture f
        LEFT JOIN clienti c ON c.id = f.id_cliente
        LEFT JOIN professionisti p ON p.id = f.id_professionista
        WHERE f.data_emissione >= ?
          AND f.data_emissione <= ?
          AND f.stato IN ($placeholders)
        ORDER BY f.data_emissione ASC, f.id ASC";

    $params = [
        $startDate->format('Y-m-d'),
        $endDate->format('Y-m-d'),
    ];
    foreach ($statuses as $status) {
        $params[] = $status;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
}

if (empty($invoices)) {
    redirectToInvoices('error', 'Nessuna fattura trovata nel periodo selezionato.');
}

$lineStmt = $pdo->prepare(
    "SELECT id_fattura, descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva, importo_totale
     FROM righe_fattura
     WHERE id_fattura = ?
     ORDER BY id ASC"
);

$byId = [];
foreach ($invoices as $invoice) {
    $lineStmt->execute([(int)$invoice['id']]);
    $invoice['righe'] = $lineStmt->fetchAll();
    $byId[(int)$invoice['id']] = $invoice;
}

$batchFolderName = sprintf(
    '%s_%s_%s_%s',
    (new DateTimeImmutable('now'))->format('Ymd_His'),
    $periodLabel,
    $startDateLabel,
    $endDateLabel
);

$baseDir = __DIR__ . '/documents/fatture/lotti';
if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    redirectToInvoices('error', 'Impossibile creare la cartella base dei lotti.');
}

$batchDir = $baseDir . '/' . $batchFolderName;
if (!mkdir($batchDir, 0777, true) && !is_dir($batchDir)) {
    redirectToInvoices('error', 'Impossibile creare la cartella del lotto.');
}

$exported = [];
$discarded = [];

foreach ($byId as $invoice) {
    $errors = ensureRequiredInvoiceData($invoice);
    if (!empty($errors)) {
        $discarded[] = [
            'id' => (int)$invoice['id'],
            'numero' => (string)$invoice['numero_fattura'],
            'motivo' => implode('; ', $errors),
        ];
        continue;
    }

    $xml = buildFatturaPaXml($invoice);
    if ($xml === '') {
        $discarded[] = [
            'id' => (int)$invoice['id'],
            'numero' => (string)$invoice['numero_fattura'],
            'motivo' => 'xml non generato',
        ];
        continue;
    }

    $fileName = invoiceFileName($invoice);
    $fullPath = $batchDir . '/' . $fileName;
    if (file_put_contents($fullPath, $xml) === false) {
        $discarded[] = [
            'id' => (int)$invoice['id'],
            'numero' => (string)$invoice['numero_fattura'],
            'motivo' => 'scrittura file fallita',
        ];
        continue;
    }

    $exported[] = [
        'id' => (int)$invoice['id'],
        'numero' => (string)$invoice['numero_fattura'],
        'stato' => (string)$invoice['stato'],
        'file' => $fileName,
    ];
}

$manifest = fopen($batchDir . '/manifest.csv', 'wb');
if ($manifest !== false) {
    fputcsv($manifest, ['invoice_id', 'numero_fattura', 'stato', 'xml_file'], ';');
    foreach ($exported as $row) {
        fputcsv($manifest, [$row['id'], $row['numero'], $row['stato'], $row['file']], ';');
    }
    fclose($manifest);
}

$scarti = fopen($batchDir . '/scarti.csv', 'wb');
if ($scarti !== false) {
    fputcsv($scarti, ['invoice_id', 'numero_fattura', 'motivo'], ';');
    foreach ($discarded as $row) {
        fputcsv($scarti, [$row['id'], $row['numero'], $row['motivo']], ';');
    }
    fclose($scarti);
}

$report = [
    'created_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'mode' => $exportMode,
    'period' => $periodLabel,
    'from' => $startDateLabel,
    'to' => $endDateLabel,
    'include_drafts' => $includeDrafts,
    'total_found' => count($byId),
    'total_exported' => count($exported),
    'total_discarded' => count($discarded),
    'exported_files' => $exported,
    'discarded' => $discarded,
];
file_put_contents($batchDir . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$relativeDir = 'documents/fatture/lotti/' . $batchFolderName;

if (count($exported) === 0) {
    redirectToInvoices(
        'error',
        'Nessun XML esportato. Controlla scarti.csv e report.json nel lotto per vedere i motivi di scarto.',
        ['batch' => $relativeDir]
    );
}

$zipPath = $batchDir . '.zip';
$relativeZip = $relativeDir . '.zip';

if (!createZipFromDirectory($batchDir, $zipPath)) {
    redirectToInvoices(
        'error',
        'Cartella lotto creata ma ZIP non disponibile. Verifica estensione ZipArchive sul server.',
        ['batch' => $relativeDir]
    );
}

$mailSent = sendZipByEmail($zipPath, $batchFolderName);
$mailNote = $mailSent ? ' Email inviata a info@thaitikmassage.it.' : ' Email non inviata (verifica configurazione mail).';

redirectToInvoices(
    'ok',
    'Lotto creato: ' . count($exported) . ' XML esportati, ' . count($discarded) . ' scartati. ZIP pronto.' . $mailNote,
    ['batch' => $relativeDir, 'zip' => $relativeZip]
);
