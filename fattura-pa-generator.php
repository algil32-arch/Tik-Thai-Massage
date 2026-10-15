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
require_once __DIR__ . '/fattura-cortesia-renderer.php';
require_once __DIR__ . '/fattura-numerazione.php';

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

function generatePdfStub(string $invoiceNumber, string $clientName, string $serviceName, float $total, array $invoiceData = []): string
{
    $base = __DIR__ . '/documents/fatture/pdf';
    $file = $base . '/' . $invoiceNumber . '.pdf';
    $invoiceData += ['cliente_nome' => $clientName, 'servizio_nome' => $serviceName, 'importo_totale' => $total];
    if (file_put_contents($file, renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData)) === false) {
        throw new RuntimeException('Impossibile salvare il PDF della fattura.');
    }

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

function createCopyOfCourtesy(string $invoiceNumber, string $clientName, array $invoiceData = []): string
{
    $base = __DIR__ . '/documents/fatture/copie';
    $file = $base . '/' . $invoiceNumber . '_copia_di_cortesia.pdf';
    $invoiceData += ['cliente_nome' => $clientName];
    if (file_put_contents($file, renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData, true)) === false) {
        throw new RuntimeException('Impossibile salvare la copia di cortesia.');
    }

    return $file;
}

function generateInvoiceFromAppointment(int $appointmentId): array
{
    $pdo = dbConnect();
    ensureDirs();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.id_cliente, a.id_servizio, a.id_studio, a.data_appuntamento, a.ora_inizio, a.stato,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email,
                c.ragione_sociale AS cliente_ragione_sociale, c.codice_fiscale AS cliente_cf,
                c.partita_iva AS cliente_piva, c.indirizzo AS cliente_indirizzo,
                c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
                s.nome AS servizio_nome, s.prezzo AS servizio_prezzo, s.iva_percentuale,
                st.nome AS studio_nome,
                p.nome AS fornitore_nome, p.cognome AS fornitore_cognome,
                p.ragione_sociale AS fornitore_ragione_sociale, p.partita_iva AS fornitore_piva,
                p.codice_fiscale AS fornitore_cf, p.indirizzo AS fornitore_indirizzo,
                p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
                p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
         FROM appuntamenti a
         LEFT JOIN clienti c ON c.id = a.id_cliente
         LEFT JOIN servizi s ON s.id = a.id_servizio
         LEFT JOIN studi st ON st.id = a.id_studio
         LEFT JOIN professionisti p ON p.id = a.id_professionista
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

    $invoiceDate = date('Y-m-d');
    $numberLock = acquireInvoiceNumberLock($pdo, $invoiceDate);
    try {
        $invoiceNumber = nextInvoiceNumber($pdo, $invoiceDate);

        $insertInvoice = $pdo->prepare(
            "INSERT INTO fatture (numero_fattura, serie, data_emissione, data_scadenza, id_cliente, id_studio, id_professionista, importo_netto, iva_totale, importo_totale, stato, created_at)
             VALUES (:numero, 'TS', :data_emissione, DATE_ADD(:data_scadenza_base, INTERVAL 30 DAY), :cliente, :studio, 1, :netto, :iva, :totale, 'emessa', NOW())"
        );
        $insertInvoice->execute([
            ':numero' => $invoiceNumber,
            ':data_emissione' => $invoiceDate,
            ':data_scadenza_base' => $invoiceDate,
            ':cliente' => $appointment['id_cliente'],
            ':studio' => $appointment['id_studio'],
            ':netto' => number_format($totals['imponibile'], 2, '.', ''),
            ':iva' => number_format($totals['iva'], 2, '.', ''),
            ':totale' => number_format($totals['totale'], 2, '.', ''),
        ]);
    } finally {
        releaseInvoiceNumberLock($pdo, $numberLock);
    }

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

    $invoiceData = [
        'cliente_nome' => (string)$appointment['cliente_nome'],
        'cliente_cognome' => (string)$appointment['cliente_cognome'],
        'cliente_ragione_sociale' => (string)($appointment['cliente_ragione_sociale'] ?? ''),
        'cliente_cf' => (string)($appointment['cliente_cf'] ?? ''),
        'cliente_piva' => (string)($appointment['cliente_piva'] ?? ''),
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
        'data_emissione' => date('Y-m-d'),
        'data_scadenza' => date('Y-m-d', strtotime('+30 days')),
        'iva_percentuale' => $vatPercent,
        'natura_iva' => $vatPercent === 0.0 ? 'N2.2' : '',
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? ''),
        'importo_netto' => $totals['imponibile'],
        'iva_totale' => $totals['iva'],
        'importo_totale' => $totals['totale'],
        'servizio_nome' => $serviceName,
        'quantita' => 1,
        'prezzo_unitario' => $price,
    ];
    $pdfPath = generatePdfStub($invoiceNumber, $clientName, $serviceName, $totals['totale'], $invoiceData);
    $xmlPath = generateXmlFatturaPa($invoiceNumber, $clientName, $serviceName, $totals['imponibile'], $vatPercent, $totals['totale']);
    $copyPath = createCopyOfCourtesy($invoiceNumber, $clientName, $invoiceData);

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
