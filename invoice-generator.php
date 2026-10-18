<?php
/**
 * Invoice generator for Aruba / PHP architecture.
 * Generates a simple invoice record, PDF path, and a copy of courtesy document.
 * This is a starting point for the SDI/FatturaPA workflow.
 */

require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/fattura-cortesia-renderer.php';
require_once __DIR__ . '/fattura-numerazione.php';

function connectDatabase(): PDO
{
    return dbConnection();
}

function createInvoiceFromAppointment(int $appointmentId): array
{
    $pdo = connectDatabase();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.id_cliente, a.id_servizio, a.id_studio, a.id_professionista,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email,
                c.ragione_sociale AS cliente_ragione_sociale, c.codice_fiscale AS cliente_cf,
                c.partita_iva AS cliente_piva, c.indirizzo AS cliente_indirizzo,
                c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
                s.nome AS servizio_nome, s.prezzo AS servizio_prezzo, s.iva_percentuale,
                st.nome AS studio_nome, p.nome AS professionista_nome, p.cognome AS professionista_cognome,
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

    $imponibile = (float)$appointment['servizio_prezzo'];
    $ivaPercent = (float)($appointment['iva_percentuale'] ?? 22.0);
    $iva = $imponibile * ($ivaPercent / 100);
    $totale = $imponibile + $iva;

    $invoiceDate = date('Y-m-d');
    $numberLock = acquireInvoiceNumberLock($pdo, $invoiceDate);
    try {
        $number = nextInvoiceNumber($pdo, $invoiceDate);

        $insertInvoice = $pdo->prepare(
            "INSERT INTO fatture (numero_fattura, serie, data_emissione, data_scadenza, id_cliente, id_studio, id_professionista, importo_netto, iva_totale, importo_totale, stato, created_at)
             VALUES (:numero, 'TS', :data_emissione, DATE_ADD(:data_scadenza_base, INTERVAL 30 DAY), :cliente, :studio, :professionista, :netto, :iva, :totale, 'emessa', NOW())"
        );
        $insertInvoice->execute([
            ':numero' => $number,
            ':data_emissione' => $invoiceDate,
            ':data_scadenza_base' => $invoiceDate,
            ':cliente' => $appointment['id_cliente'],
            ':studio' => $appointment['id_studio'],
            ':professionista' => $appointment['id_professionista'],
            ':netto' => number_format($imponibile, 2, '.', ''),
            ':iva' => number_format($iva, 2, '.', ''),
            ':totale' => number_format($totale, 2, '.', ''),
        ]);
    } finally {
        releaseInvoiceNumberLock($pdo, $numberLock);
    }

    $invoiceId = (int)$pdo->lastInsertId();

    $insertLine = $pdo->prepare(
        "INSERT INTO righe_fattura (id_fattura, id_servizio, descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva, importo_totale, created_at)
         VALUES (:fattura, :servizio, :descrizione, 1, :prezzo, :iva, :netto, :iva_valore, :totale, NOW())"
    );
    $insertLine->execute([
        ':fattura' => $invoiceId,
        ':servizio' => $appointment['id_servizio'],
        ':descrizione' => $appointment['servizio_nome'],
        ':prezzo' => number_format($imponibile, 2, '.', ''),
        ':iva' => number_format($ivaPercent, 2, '.', ''),
        ':netto' => number_format($imponibile, 2, '.', ''),
        ':iva_valore' => number_format($iva, 2, '.', ''),
        ':totale' => number_format($totale, 2, '.', ''),
    ]);

    $baseDir = __DIR__ . '/documents/fatture';
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }

    $pdfPath = $baseDir . '/pdf/' . $number . '.pdf';
    $xmlPath = $baseDir . '/xml/' . $number . '.xml';
    $copiedPath = $baseDir . '/copie/' . $number . '_copia_di_cortesia.pdf';

    if (!is_dir($baseDir . '/pdf')) mkdir($baseDir . '/pdf', 0777, true);
    if (!is_dir($baseDir . '/xml')) mkdir($baseDir . '/xml', 0777, true);
    if (!is_dir($baseDir . '/copie')) mkdir($baseDir . '/copie', 0777, true);

    $invoiceData = [
        'fornitore_nome' => (string)($appointment['professionista_nome'] ?? ''),
        'fornitore_cognome' => (string)($appointment['professionista_cognome'] ?? ''),
        'fornitore_ragione_sociale' => (string)($appointment['fornitore_ragione_sociale'] ?? ''),
        'fornitore_piva' => (string)($appointment['fornitore_piva'] ?? ''),
        'fornitore_cf' => (string)($appointment['fornitore_cf'] ?? ''),
        'fornitore_indirizzo' => (string)($appointment['fornitore_indirizzo'] ?? ''),
        'fornitore_citta' => (string)($appointment['fornitore_citta'] ?? ''),
        'fornitore_provincia' => (string)($appointment['fornitore_provincia'] ?? ''),
        'fornitore_cap' => (string)($appointment['fornitore_cap'] ?? ''),
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? ''),
        'cliente_nome' => (string)($appointment['cliente_nome'] ?? ''),
        'cliente_cognome' => (string)($appointment['cliente_cognome'] ?? ''),
        'cliente_ragione_sociale' => (string)($appointment['cliente_ragione_sociale'] ?? ''),
        'cliente_cf' => (string)($appointment['cliente_cf'] ?? ''),
        'cliente_piva' => (string)($appointment['cliente_piva'] ?? ''),
        'cliente_indirizzo' => (string)($appointment['cliente_indirizzo'] ?? ''),
        'cliente_citta' => (string)($appointment['cliente_citta'] ?? ''),
        'cliente_provincia' => (string)($appointment['cliente_provincia'] ?? ''),
        'cliente_cap' => (string)($appointment['cliente_cap'] ?? ''),
        'data_emissione' => date('Y-m-d'),
        'data_scadenza' => date('Y-m-d', strtotime('+30 days')),
        'iva_percentuale' => $ivaPercent,
        'natura_iva' => $ivaPercent === 0.0 ? 'N2.2' : '',
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? ''),
        'importo_netto' => $imponibile,
        'iva_totale' => $iva,
        'importo_totale' => $totale,
        'servizio_nome' => (string)$appointment['servizio_nome'],
        'quantita' => 1,
        'prezzo_unitario' => $imponibile,
    ];
    file_put_contents($pdfPath, renderInvoiceCourtesyPdf($number, $invoiceData));
    file_put_contents($xmlPath, "<fattura><numero>{$number}</numero><totale>" . number_format($totale, 2, '.', '') . "</totale></fattura>");
    file_put_contents($copiedPath, renderInvoiceCourtesyPdf($number, $invoiceData, true));

    $pdo->prepare("UPDATE fatture SET pdf_path = :pdf, xml_path = :xml WHERE id = :id")
        ->execute([
            ':pdf' => $pdfPath,
            ':xml' => $xmlPath,
            ':id' => $invoiceId,
        ]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'originale', :pdf, NOW())")
        ->execute([':id' => $invoiceId, ':pdf' => $pdfPath]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'xml', :xml, NOW())")
        ->execute([':id' => $invoiceId, ':xml' => $xmlPath]);

    $pdo->prepare("INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at) VALUES (:id, 'copia_di_cortesia', :copy, NOW())")
        ->execute([':id' => $invoiceId, ':copy' => $copiedPath]);

    return [
        'invoice_id' => $invoiceId,
        'numero_fattura' => $number,
        'pdf_path' => $pdfPath,
        'xml_path' => $xmlPath,
        'copy_path' => $copiedPath,
        'totale' => $totale,
    ];
}

if (php_sapi_name() === 'cli') {
    $id = (int)($argv[1] ?? 1);
    try {
        $result = createInvoiceFromAppointment($id);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    if ($appointmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
        exit;
    }

    try {
        $result = createInvoiceFromAppointment($appointmentId);
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Invoice generator ready.',
    'usage' => [
        'CLI: php invoice-generator.php 1',
        'POST with appointment_id' => 'Generate invoice from appointment'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
