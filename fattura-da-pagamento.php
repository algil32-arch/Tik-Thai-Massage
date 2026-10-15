<?php

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/fattura-xml-builder.php';

function redirectToInvoices(string $status, string $message): never
{
    header('Location: fatture-admin.php?' . http_build_query([
        'export' => $status,
        'msg' => $message,
    ]));
    exit;
}

function paymentInvoiceNumber(int $appointmentId): string
{
    return sprintf('TS-%s-%05d', date('Y'), $appointmentId);
}

function ensureInvoiceDirs(): void
{
    $base = __DIR__ . '/documents/fatture';
    foreach (['pdf', 'xml', 'copie'] as $folder) {
        $path = $base . '/' . $folder;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

function buildStyledTextPdf(array $entries): string
{
    $escape = static function (string $value): string {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($encoded === false) {
            $encoded = $value;
        }
        $encoded = str_replace(["\r", "\n"], ['', ' '], $encoded);
        $encoded = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
        return $encoded;
    };

    $stream = "0.98 0.98 0.97 rg 0 0 595 842 re f\n";
    $stream .= "0.12 0.22 0.20 rg 30 760 535 58 re f\n";
    $stream .= "0.65 0.65 0.65 RG 30 50 535 700 re S\n";

    foreach ($entries as $entry) {
        $text = $escape((string)$entry['text']);
        $size = (int)($entry['size'] ?? 11);
        $x = (int)($entry['x'] ?? 45);
        $y = (int)($entry['y'] ?? 740);
        $r = (float)($entry['r'] ?? 0.14);
        $g = (float)($entry['g'] ?? 0.14);
        $b = (float)($entry['b'] ?? 0.14);
        $stream .= sprintf("%.2F %.2F %.2F rg BT /F1 %d Tf %d %d Td (%s) Tj ET\n", $r, $g, $b, $size, $x, $y, $text);
    }

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function createInvoicePdfStub(string $invoiceNumber, array $invoiceData): string
{
    $relativePath = 'documents/fatture/pdf/' . $invoiceNumber . '.pdf';
    $path = __DIR__ . '/' . $relativePath;

    $fmtMoney = static fn(float $v): string => 'EUR ' . number_format($v, 2, ',', '.');
    $supplier = trim((string)($invoiceData['fornitore_ragione_sociale'] ?? ''));
    if ($supplier === '') {
        $supplier = trim((string)($invoiceData['fornitore_nome'] ?? '') . ' ' . (string)($invoiceData['fornitore_cognome'] ?? ''));
    }
    $customer = trim((string)($invoiceData['cliente_ragione_sociale'] ?? ''));
    if ($customer === '') {
        $customer = trim((string)($invoiceData['cliente_nome'] ?? '') . ' ' . (string)($invoiceData['cliente_cognome'] ?? ''));
    }

    $net = (float)($invoiceData['importo_netto'] ?? 0.0);
    $vat = (float)($invoiceData['iva_totale'] ?? 0.0);
    $stamp = (float)($invoiceData['importo_bollo'] ?? 0.0);
    $total = (float)($invoiceData['importo_totale'] ?? 0.0);
    $serviceName = (string)($invoiceData['servizio_nome'] ?? 'Prestazione professionale');
    $qty = (float)($invoiceData['quantita'] ?? 1.0);
    $unitPrice = (float)($invoiceData['prezzo_unitario'] ?? $net);
    $vatRate = (float)($invoiceData['iva_percentuale'] ?? 0.0);

    $entries = [
        ['text' => 'THAI TIK MASSAGE', 'size' => 18, 'x' => 45, 'y' => 792, 'r' => 1, 'g' => 1, 'b' => 1],
        ['text' => 'FATTURA', 'size' => 14, 'x' => 430, 'y' => 792, 'r' => 1, 'g' => 1, 'b' => 1],
        ['text' => 'Numero: ' . $invoiceNumber, 'size' => 12, 'x' => 430, 'y' => 774, 'r' => 1, 'g' => 1, 'b' => 1],

        ['text' => 'Cedente/Prestatore', 'size' => 12, 'x' => 45, 'y' => 730],
        ['text' => $supplier, 'size' => 11, 'x' => 45, 'y' => 712],
        ['text' => 'P.IVA: ' . (string)($invoiceData['fornitore_piva'] ?? ''), 'size' => 10, 'x' => 45, 'y' => 696],
        ['text' => 'CF: ' . (string)($invoiceData['fornitore_cf'] ?? ''), 'size' => 10, 'x' => 45, 'y' => 682],
        ['text' => trim((string)($invoiceData['fornitore_indirizzo'] ?? '') . ', ' . (string)($invoiceData['fornitore_cap'] ?? '') . ' ' . (string)($invoiceData['fornitore_citta'] ?? '') . ' (' . (string)($invoiceData['fornitore_provincia'] ?? '') . ')'), 'size' => 10, 'x' => 45, 'y' => 668],
        ['text' => 'Regime: ' . (string)($invoiceData['fornitore_regime_fiscale'] ?? 'RF19'), 'size' => 10, 'x' => 45, 'y' => 654],

        ['text' => 'Cessionario/Committente', 'size' => 12, 'x' => 315, 'y' => 730],
        ['text' => $customer, 'size' => 11, 'x' => 315, 'y' => 712],
        ['text' => 'P.IVA: ' . (string)($invoiceData['cliente_piva'] ?? ''), 'size' => 10, 'x' => 315, 'y' => 696],
        ['text' => 'CF: ' . (string)($invoiceData['cliente_cf'] ?? ''), 'size' => 10, 'x' => 315, 'y' => 682],
        ['text' => trim((string)($invoiceData['cliente_indirizzo'] ?? '') . ', ' . (string)($invoiceData['cliente_cap'] ?? '') . ' ' . (string)($invoiceData['cliente_citta'] ?? '') . ' (' . (string)($invoiceData['cliente_provincia'] ?? '') . ')'), 'size' => 10, 'x' => 315, 'y' => 668],

        ['text' => 'Data emissione: ' . date('d/m/Y'), 'size' => 10, 'x' => 45, 'y' => 628],
        ['text' => 'Modalita pagamento: Online (MP05)', 'size' => 10, 'x' => 315, 'y' => 628],

        ['text' => 'Dettaglio prestazione', 'size' => 12, 'x' => 45, 'y' => 596],
        ['text' => 'Descrizione', 'size' => 10, 'x' => 45, 'y' => 578],
        ['text' => 'Q.ta', 'size' => 10, 'x' => 330, 'y' => 578],
        ['text' => 'Prezzo', 'size' => 10, 'x' => 390, 'y' => 578],
        ['text' => 'Totale riga', 'size' => 10, 'x' => 470, 'y' => 578],
        ['text' => $serviceName, 'size' => 10, 'x' => 45, 'y' => 560],
        ['text' => number_format($qty, 2, ',', '.'), 'size' => 10, 'x' => 330, 'y' => 560],
        ['text' => $fmtMoney($unitPrice), 'size' => 10, 'x' => 390, 'y' => 560],
        ['text' => $fmtMoney($net), 'size' => 10, 'x' => 470, 'y' => 560],

        ['text' => 'Riepilogo fiscale', 'size' => 12, 'x' => 45, 'y' => 520],
        ['text' => 'Imponibile: ' . $fmtMoney($net), 'size' => 10, 'x' => 45, 'y' => 502],
        ['text' => 'IVA (' . number_format($vatRate, 2, ',', '.') . '%): ' . $fmtMoney($vat), 'size' => 10, 'x' => 45, 'y' => 486],
        ['text' => 'Bollo: ' . $fmtMoney($stamp), 'size' => 10, 'x' => 45, 'y' => 470],
        ['text' => 'Totale documento: ' . $fmtMoney($total), 'size' => 12, 'x' => 45, 'y' => 446],
    ];

    if ((string)($invoiceData['natura_iva'] ?? '') !== '') {
        $entries[] = ['text' => 'Natura IVA: ' . (string)$invoiceData['natura_iva'], 'size' => 10, 'x' => 45, 'y' => 428];
        $entries[] = ['text' => 'Operazione in regime forfettario - non soggetta a IVA.', 'size' => 10, 'x' => 45, 'y' => 412];
    }

    $entries[] = ['text' => 'Documento generato automaticamente dal sistema gestionale.', 'size' => 9, 'x' => 45, 'y' => 88, 'r' => 0.35, 'g' => 0.35, 'b' => 0.35];

    $pdf = buildStyledTextPdf($entries);
    file_put_contents($path, $pdf);
    return $relativePath;
}

function createInvoiceXmlStub(string $invoiceNumber, array $context, string $clientName, string $serviceName, float $price, float $vatPercent, float $total): string
{
    $relativePath = 'documents/fatture/xml/' . $invoiceNumber . '.xml';
    $path = __DIR__ . '/' . $relativePath;

    $xml = buildFatturaElettronicaXml(array_merge($context, [
        'numero_fattura' => $invoiceNumber,
        'tipo_documento' => 'TD01',
        'data_emissione' => date('Y-m-d'),
        'importo_totale' => $total,
        'bollo_virtuale' => $total > 77.47 ? 1 : 0,
        'importo_bollo' => $total > 77.47 ? 2.00 : 0.00,
        'natura_iva' => $vatPercent == 0.0 ? 'N2.2' : '',
        'line_descrizione' => $serviceName,
        'line_quantita' => 1.0,
        'line_prezzo_unitario' => $price,
        'line_importo_netto' => $price,
        'line_importo_iva' => 0.0,
        'line_iva_percentuale' => $vatPercent,
        'progressivo_invio' => (int)($context['appointment_id'] ?? 1),
    ]));

    file_put_contents($path, $xml);
    return $relativePath;
}

function createCourtesyCopy(string $invoiceNumber, string $clientName, float $total): string
  {
    $relativePath = 'documents/fatture/copie/' . $invoiceNumber . '_copia_di_cortesia.pdf';
    $path = __DIR__ . '/' . $relativePath;
    $pdf = buildStyledTextPdf([
        ['text' => 'THAI TIK MASSAGE', 'size' => 18, 'x' => 45, 'y' => 792, 'r' => 1, 'g' => 1, 'b' => 1],
        ['text' => 'COPIA DI CORTESIA', 'size' => 14, 'x' => 360, 'y' => 792, 'r' => 1, 'g' => 1, 'b' => 1],
        ['text' => 'Fattura numero: ' . $invoiceNumber, 'size' => 12, 'x' => 45, 'y' => 720],
        ['text' => 'Cliente: ' . $clientName, 'size' => 11, 'x' => 45, 'y' => 700],
        ['text' => 'Totale: EUR ' . number_format($total, 2, ',', '.'), 'size' => 11, 'x' => 45, 'y' => 680],
        ['text' => 'Questa copia non sostituisce l originale fiscalmente valido.', 'size' => 10, 'x' => 45, 'y' => 640],
    ]);
    file_put_contents($path, $pdf);
    return $relativePath;
}

function generateInvoiceFromAppointment(PDO $pdo, int $appointmentId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.id_cliente, a.id_servizio, a.id_studio, a.stato, a.metodo_pagamento, a.stato_pagamento,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome,
                  s.nome AS servizio_nome, s.prezzo AS servizio_prezzo, s.iva_percentuale,
                  c.ragione_sociale AS cliente_ragione_sociale, c.codice_fiscale AS cliente_cf,
                  c.partita_iva AS cliente_piva, c.codice_destinatario AS cliente_codice_destinatario,
                  c.pec AS cliente_pec, c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta,
                  c.provincia AS cliente_provincia, c.cap AS cliente_cap,
                  p.nome AS fornitore_nome, p.cognome AS fornitore_cognome,
                  p.ragione_sociale AS fornitore_ragione_sociale, p.partita_iva AS fornitore_piva,
                  p.codice_fiscale AS fornitore_cf, p.indirizzo AS fornitore_indirizzo,
                  p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
                  p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
         FROM appuntamenti a
         LEFT JOIN clienti c ON c.id = a.id_cliente
         LEFT JOIN servizi s ON s.id = a.id_servizio
              LEFT JOIN professionisti p ON p.id = a.id_professionista
         WHERE a.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $appointmentId]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        throw new RuntimeException('Appuntamento non trovato.');
    }

    if (($appointment['metodo_pagamento'] ?? '') !== 'online' || ($appointment['stato_pagamento'] ?? '') !== 'pagato') {
        throw new RuntimeException('L appuntamento non risulta pagato online.');
    }

    if (($appointment['stato'] ?? '') !== 'confermato') {
        throw new RuntimeException('La fattura puo essere generata solo per appuntamenti confermati.');
    }

    $invoiceNumber = paymentInvoiceNumber($appointmentId);
    $existingStmt = $pdo->prepare('SELECT id FROM fatture WHERE numero_fattura = :num LIMIT 1');
    $existingStmt->execute([':num' => $invoiceNumber]);
    $existingId = (int)($existingStmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        throw new RuntimeException('Esiste gia una fattura con numero ' . $invoiceNumber . '.');
    }

    // For invoices generated from paid online appointments we apply the
    // current forfettario profile: VAT excluded, Natura N2.2.
    $price = round((float)$appointment['servizio_prezzo'], 2);
    $vatPercent = 0.0;
    $vat = 0.0;
    $total = $price;

    $insertInvoice = $pdo->prepare(
        "INSERT INTO fatture
            (numero_fattura, serie, data_emissione, data_scadenza, id_cliente, id_studio, id_professionista,
             tipo_documento, data_prestazione, natura_iva, importo_rivalsa,
             bollo_virtuale, bollo_addebitato, importo_bollo,
             importo_netto, iva_totale, importo_totale, stato, note, created_at)
         VALUES
            (:numero, 'TS', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), :cliente, :studio, 1,
             'TD01', CURDATE(), 'N2.2', 0,
             0, 0, 0,
             :netto, :iva, :totale, 'emessa', :note, NOW())"
    );
    $insertInvoice->execute([
        ':numero' => $invoiceNumber,
        ':cliente' => $appointment['id_cliente'],
        ':studio' => $appointment['id_studio'],
        ':netto' => number_format($price, 2, '.', ''),
        ':iva' => number_format($vat, 2, '.', ''),
        ':totale' => number_format($total, 2, '.', ''),
        ':note' => 'Regime forfettario RF19 - operazione senza applicazione IVA (natura N2.2).',
    ]);

    $invoiceId = (int)$pdo->lastInsertId();

    $insertLine = $pdo->prepare(
        "INSERT INTO righe_fattura
            (id_fattura, id_servizio, descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva, importo_totale, created_at)
         VALUES
            (:id_fattura, :id_servizio, :descrizione, 1, :prezzo, :iva_percentuale, :netto, :iva, :totale, NOW())"
    );
    $insertLine->execute([
        ':id_fattura' => $invoiceId,
        ':id_servizio' => $appointment['id_servizio'],
        ':descrizione' => (string)$appointment['servizio_nome'],
        ':prezzo' => number_format($price, 2, '.', ''),
        ':iva_percentuale' => number_format($vatPercent, 2, '.', ''),
        ':netto' => number_format($price, 2, '.', ''),
        ':iva' => number_format($vat, 2, '.', ''),
        ':totale' => number_format($price, 2, '.', ''),
    ]);

    ensureInvoiceDirs();
    $clientName = trim((string)$appointment['cliente_nome'] . ' ' . (string)$appointment['cliente_cognome']);
    $serviceName = (string)$appointment['servizio_nome'];

    $pdfPath = createInvoicePdfStub($invoiceNumber, [
        'fornitore_nome' => (string)($appointment['fornitore_nome'] ?? ''),
        'fornitore_cognome' => (string)($appointment['fornitore_cognome'] ?? ''),
        'fornitore_ragione_sociale' => (string)($appointment['fornitore_ragione_sociale'] ?? ''),
        'fornitore_piva' => (string)($appointment['fornitore_piva'] ?? ''),
        'fornitore_cf' => (string)($appointment['fornitore_cf'] ?? ''),
        'fornitore_indirizzo' => (string)($appointment['fornitore_indirizzo'] ?? ''),
        'fornitore_citta' => (string)($appointment['fornitore_citta'] ?? ''),
        'fornitore_provincia' => (string)($appointment['fornitore_provincia'] ?? ''),
        'fornitore_cap' => (string)($appointment['fornitore_cap'] ?? ''),
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? 'RF19'),
        'cliente_nome' => (string)$appointment['cliente_nome'],
        'cliente_cognome' => (string)$appointment['cliente_cognome'],
        'cliente_ragione_sociale' => (string)($appointment['cliente_ragione_sociale'] ?? ''),
        'cliente_cf' => (string)($appointment['cliente_cf'] ?? ''),
        'cliente_piva' => (string)($appointment['cliente_piva'] ?? ''),
        'cliente_indirizzo' => (string)($appointment['cliente_indirizzo'] ?? ''),
        'cliente_citta' => (string)($appointment['cliente_citta'] ?? ''),
        'cliente_provincia' => (string)($appointment['cliente_provincia'] ?? ''),
        'cliente_cap' => (string)($appointment['cliente_cap'] ?? ''),
        'servizio_nome' => $serviceName,
        'quantita' => 1.0,
        'prezzo_unitario' => $price,
        'iva_percentuale' => $vatPercent,
        'natura_iva' => 'N2.2',
        'importo_netto' => $price,
        'iva_totale' => $vat,
        'importo_bollo' => 0.0,
        'importo_totale' => $total,
    ]);
    $xmlPath = createInvoiceXmlStub($invoiceNumber, [
        'appointment_id' => $appointmentId,
        'cliente_nome' => (string)$appointment['cliente_nome'],
        'cliente_cognome' => (string)$appointment['cliente_cognome'],
        'cliente_ragione_sociale' => (string)($appointment['cliente_ragione_sociale'] ?? ''),
        'cliente_cf' => (string)($appointment['cliente_cf'] ?? ''),
        'cliente_piva' => (string)($appointment['cliente_piva'] ?? ''),
        'cliente_codice_destinatario' => (string)($appointment['cliente_codice_destinatario'] ?? ''),
        'cliente_pec' => (string)($appointment['cliente_pec'] ?? ''),
        'cliente_indirizzo' => (string)($appointment['cliente_indirizzo'] ?? ''),
        'cliente_citta' => (string)($appointment['cliente_citta'] ?? ''),
        'cliente_provincia' => (string)($appointment['cliente_provincia'] ?? ''),
        'cliente_cap' => (string)($appointment['cliente_cap'] ?? ''),
        'fornitore_nome' => (string)($appointment['fornitore_nome'] ?? ''),
        'fornitore_cognome' => (string)($appointment['fornitore_cognome'] ?? ''),
        'fornitore_ragione_sociale' => (string)($appointment['fornitore_ragione_sociale'] ?? ''),
        'fornitore_piva' => (string)($appointment['fornitore_piva'] ?? ''),
        'fornitore_cf' => (string)($appointment['fornitore_cf'] ?? ''),
        'fornitore_indirizzo' => (string)($appointment['fornitore_indirizzo'] ?? ''),
        'fornitore_citta' => (string)($appointment['fornitore_citta'] ?? ''),
        'fornitore_provincia' => (string)($appointment['fornitore_provincia'] ?? ''),
        'fornitore_cap' => (string)($appointment['fornitore_cap'] ?? ''),
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? ''),
    ], $clientName, $serviceName, $price, $vatPercent, $total);
    $copyPath = createCourtesyCopy($invoiceNumber, $clientName, $total);

    $pdo->prepare('UPDATE fatture SET pdf_path = :pdf, xml_path = :xml WHERE id = :id')
        ->execute([':pdf' => $pdfPath, ':xml' => $xmlPath, ':id' => $invoiceId]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'originale', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $pdfPath]);
    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'xml', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $xmlPath]);
    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'copia_di_cortesia', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $copyPath]);

    return [
        'invoice_id' => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'appointment_id' => $appointmentId,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Metodo non consentito.');
}

$csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if (!hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $csrf)) {
    redirectToInvoices('error', 'Token CSRF non valido.');
}

$paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($paymentId === false) {
    redirectToInvoices('error', 'ID pagamento non valido.');
}

$action = (string)($_POST['action'] ?? 'create');
if (!in_array($action, ['create', 'replace'], true)) {
    redirectToInvoices('error', 'Azione non valida.');
}

try {
    $pdo = dbConnection();

    $paymentStmt = $pdo->prepare('SELECT id_appuntamento, id_fattura, stato, metodo_pagamento FROM pagamenti WHERE id = :id LIMIT 1');
    $paymentStmt->execute([':id' => $paymentId]);
    $payment = $paymentStmt->fetch();

    $appointmentId = 0;
    if ($payment) {
        if ((int)($payment['id_fattura'] ?? 0) > 0) {
            redirectToInvoices('error', 'Il pagamento ha gia una fattura associata (ID ' . (int)$payment['id_fattura'] . ').');
        }
        $appointmentId = (int)($payment['id_appuntamento'] ?? 0);
    }

    if ($appointmentId <= 0) {
        $intentStmt = $pdo->prepare('SELECT id, status, appointment_id FROM sumup_payment_intents WHERE id = :id LIMIT 1');
        $intentStmt->execute([':id' => $paymentId]);
        $intent = $intentStmt->fetch();
        if ($intent) {
            if (($intent['status'] ?? '') !== 'paid') {
                redirectToInvoices('error', 'Il pagamento online non risulta paid.');
            }
            $appointmentId = (int)($intent['appointment_id'] ?? 0);
        }

        if ($appointmentId <= 0) {
            $appointmentStmt = $pdo->prepare(
                "SELECT id
                 FROM appuntamenti
                 WHERE id = :id
                   AND metodo_pagamento = 'online'
                   AND stato_pagamento = 'pagato'
                 LIMIT 1"
            );
            $appointmentStmt->execute([':id' => $paymentId]);
            $appointmentId = (int)($appointmentStmt->fetchColumn() ?: 0);
        }

        if ($appointmentId <= 0) {
            redirectToInvoices('error', 'ID non trovato come pagamento online. Verifica se l\'ID corretto e quello dell\'appuntamento online pagato o dell\'intento SumUp.');
        }
    }

    if ($appointmentId <= 0) {
        redirectToInvoices('error', 'Pagamento trovato ma senza appuntamento associato.');
    }

    $pdo->beginTransaction();
    try {
        if ($action === 'replace') {
            $invoiceNumber = paymentInvoiceNumber($appointmentId);
            $existingByNumberStmt = $pdo->prepare(
                'SELECT id, numero_fattura, note FROM fatture WHERE numero_fattura = :numero LIMIT 1 FOR UPDATE'
            );
            $existingByNumberStmt->execute([':numero' => $invoiceNumber]);
            $existing = $existingByNumberStmt->fetch();

            if ($existing) {
                $existingId = (int)$existing['id'];
                $archivedNumber = $invoiceNumber . '-ANN-' . $existingId;
                $cancelNote = trim((string)($existing['note'] ?? ''));
                $cancelNote .= ($cancelNote !== '' ? "\n\n" : '') . 'Fattura annullata e rigenerata da pagamento online il ' . date('Y-m-d H:i:s') . '.';

                $cancelStmt = $pdo->prepare(
                    "UPDATE fatture
                     SET numero_fattura = :archived,
                         stato = 'annullata',
                         note = :note
                     WHERE id = :id"
                );
                $cancelStmt->execute([
                    ':archived' => $archivedNumber,
                    ':note' => $cancelNote,
                    ':id' => $existingId,
                ]);
            }
        }

        $result = generateInvoiceFromAppointment($pdo, $appointmentId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $verb = $action === 'replace' ? 'Fattura annullata e rigenerata' : 'Fattura creata';
    redirectToInvoices('ok', $verb . ' da pagamento #' . $paymentId . ': ' . $result['invoice_number'] . ' (ID ' . $result['invoice_id'] . ').');
} catch (Throwable $e) {
    redirectToInvoices('error', 'Creazione fattura fallita: ' . $e->getMessage());
}
