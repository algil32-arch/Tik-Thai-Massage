<?php
/**
 * Generates a simple invoice workflow for the Aruba architecture.
 * - reads a confirmed appointment
 * - creates a document record in DB
 * - writes a PDF-style stub and a simplified XML FatturaPA payload
 * - stores a copy of courtesy document
 * - exposes JSON output for HTTP or CLI use
 */

require_once __DIR__ . '/app-config.php';

function dbConnect(): PDO
{
    return dbConnection();
}

function ensureDirs(): void
{
    $base = __DIR__ . '/documents/fatture';
    foreach (['pdf', 'xml', 'copie'] as $folder) {
        $path = $base . '/' . $folder;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}

function calculateInvoiceTotals(float $price, float $vatPercent): array
{
    $imponibile = round($price, 2);
    $iva = round($imponibile * ($vatPercent / 100), 2);
    $totale = round($imponibile + $iva, 2);

    return [
        'imponibile' => $imponibile,
        'iva' => $iva,
        'totale' => $totale,
    ];
}

function generatePdfStub(string $invoiceNumber, string $clientName, string $serviceName, float $total): string
{
    $base = __DIR__ . '/documents/fatture/pdf';
    $file = $base . '/' . $invoiceNumber . '.pdf';

    $content = "FATTURA\n";
    $content .= "Numero: {$invoiceNumber}\n";
    $content .= "Cliente: {$clientName}\n";
    $content .= "Servizio: {$serviceName}\n";
    $content .= "Totale: € " . number_format($total, 2, ',', '.') . "\n";

    file_put_contents($file, $content);

    return $file;
}

function generateXmlFatturaPa(string $invoiceNumber, string $clientName, string $serviceName, float $price, float $vatPercent, float $total): string
{
    $base = __DIR__ . '/documents/fatture/xml';
    $file = $base . '/' . $invoiceNumber . '.xml';

    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= "\n<ns:Invoice xmlns:ns=\"http://www.fatturapa.gov.it/sdi/fatturapa/v1.2\">";
    $xml .= "\n  <ns:Header>";
    $xml .= "\n    <ns:InvoiceNumber>{$invoiceNumber}</ns:InvoiceNumber>";
    $xml .= "\n    <ns:Client>{$clientName}</ns:Client>";
    $xml .= "\n    <ns:Service>{$serviceName}</ns:Service>";
    $xml .= "\n  </ns:Header>";
    $xml .= "\n  <ns:Body>";
    $xml .= "\n    <ns:Price>{$price}</ns:Price>";
    $xml .= "\n    <ns:VatPercent>{$vatPercent}</ns:VatPercent>";
    $xml .= "\n    <ns:Total>{$total}</ns:Total>";
    $xml .= "\n  </ns:Body>";
    $xml .= "\n</ns:Invoice>";

    file_put_contents($file, $xml);

    return $file;
}

function createCopyOfCourtesy(string $invoiceNumber, string $clientName): string
{
    $base = __DIR__ . '/documents/fatture/copie';
    $file = $base . '/' . $invoiceNumber . '_copia_di_cortesia.pdf';

    $content = "COPIA DI CORTESIA\n";
    $content .= "Fattura numero: {$invoiceNumber}\n";
    $content .= "Cliente: {$clientName}\n";
    $content .= "Questo documento è da intendersi come copia di cortesia e non sostituisce la fattura originale.\n";

    file_put_contents($file, $content);

    return $file;
}

function generateInvoiceFromAppointment(int $appointmentId): array
{
    $pdo = dbConnect();
    ensureDirs();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.id_cliente, a.id_servizio, a.id_studio, a.data_appuntamento, a.ora_inizio, a.stato,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email,
                s.nome AS servizio_nome, s.prezzo AS servizio_prezzo, s.iva_percentuale,
                st.nome AS studio_nome
         FROM appuntamenti a
         LEFT JOIN clienti c ON c.id = a.id_cliente
         LEFT JOIN servizi s ON s.id = a.id_servizio
         LEFT JOIN studi st ON st.id = a.id_studio
         WHERE a.id = :id"
    );
    $stmt->execute([':id' => $appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        throw new RuntimeException('Appointment not found.');
    }

    if (($appointment['stato'] ?? '') !== 'confermato') {
        throw new RuntimeException('Invoice can be generated only for confirmed appointments.');
    }

    $clientName = trim($appointment['cliente_nome'] . ' ' . $appointment['cliente_cognome']);
    $serviceName = (string)$appointment['servizio_nome'];
    $price = (float)$appointment['servizio_prezzo'];
    $vatPercent = (float)($appointment['iva_percentuale'] ?? 22.0);
    $totals = calculateInvoiceTotals($price, $vatPercent);

    $invoiceNumber = sprintf('TS-%s-%05d', date('Y'), $appointmentId);

    $insertInvoice = $pdo->prepare(
        "INSERT INTO fatture (numero_fattura, serie, data_emissione, data_scadenza, id_cliente, id_studio, id_professionista, importo_netto, iva_totale, importo_totale, stato, created_at)
         VALUES (:numero, 'TS', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), :cliente, :studio, 1, :netto, :iva, :totale, 'emessa', NOW())"
    );
    $insertInvoice->execute([
        ':numero' => $invoiceNumber,
        ':cliente' => $appointment['id_cliente'],
        ':studio' => $appointment['id_studio'],
        ':netto' => number_format($totals['imponibile'], 2, '.', ''),
        ':iva' => number_format($totals['iva'], 2, '.', ''),
        ':totale' => number_format($totals['totale'], 2, '.', ''),
    ]);

    $invoiceId = (int)$pdo->lastInsertId();

    $insertLine = $pdo->prepare(
        "INSERT INTO righe_fattura (id_fattura, id_servizio, descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva, importo_totale, created_at)
         VALUES (:id_fattura, :id_servizio, :descrizione, 1, :prezzo, :iva, :netto, :iva_valore, :totale, NOW())"
    );
    $insertLine->execute([
        ':id_fattura' => $invoiceId,
        ':id_servizio' => $appointment['id_servizio'],
        ':descrizione' => $serviceName,
        ':prezzo' => number_format($price, 2, '.', ''),
        ':iva' => number_format($vatPercent, 2, '.', ''),
        ':netto' => number_format($totals['imponibile'], 2, '.', ''),
        ':iva_valore' => number_format($totals['iva'], 2, '.', ''),
        ':totale' => number_format($totals['totale'], 2, '.', ''),
    ]);

    $pdfPath = generatePdfStub($invoiceNumber, $clientName, $serviceName, $totals['totale']);
    $xmlPath = generateXmlFatturaPa($invoiceNumber, $clientName, $serviceName, $totals['imponibile'], $vatPercent, $totals['totale']);
    $copyPath = createCopyOfCourtesy($invoiceNumber, $clientName);

    $pdo->prepare("UPDATE fatture SET pdf_path = :pdf, xml_path = :xml WHERE id = :id")
        ->execute([
            ':pdf' => $pdfPath,
            ':xml' => $xmlPath,
            ':id' => $invoiceId,
        ]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'originale', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $pdfPath]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'xml', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $xmlPath]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'copia_di_cortesia', :path, NOW())")
        ->execute([':id' => $invoiceId, ':path' => $copyPath]);

    return [
        'success' => true,
        'invoice_id' => $invoiceId,
        'numero_fattura' => $invoiceNumber,
        'cliente' => $clientName,
        'servizio' => $serviceName,
        'totale' => $totals['totale'],
        'pdf_path' => $pdfPath,
        'xml_path' => $xmlPath,
        'copy_path' => $copyPath,
    ];
}

if (php_sapi_name() === 'cli') {
    $appointmentId = (int)($argv[1] ?? 0);
    if ($appointmentId <= 0) {
        fwrite(STDERR, "Usage: php fattura-pa-generator.php <appointment_id>\n");
        exit(1);
    }

    try {
        echo json_encode(generateInvoiceFromAppointment($appointmentId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing appointment_id.']);
        exit;
    }

    try {
        $result = generateInvoiceFromAppointment($appointmentId);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Fattura generator ready.',
    'usage' => [
        'php fattura-pa-generator.php 1',
        'POST with appointment_id'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
