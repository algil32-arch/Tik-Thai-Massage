<?php

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/fattura-xml-builder.php';
require_once __DIR__ . '/fattura-cortesia-renderer.php';
require_once __DIR__ . '/fattura-numerazione.php';

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

function createInvoicePdfStub(string $invoiceNumber, array $invoiceData): string
{
    $relativePath = 'documents/fatture/pdf/' . $invoiceNumber . '.pdf';
    $path = __DIR__ . '/' . $relativePath;
    $pdf = renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData);
    if (file_put_contents($path, $pdf) === false) {
        throw new RuntimeException('Impossibile salvare il PDF della fattura.');
    }
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

function createCourtesyCopy(string $invoiceNumber, array $invoiceData): string
{
    $relativePath = 'documents/fatture/copie/' . $invoiceNumber . '_copia_di_cortesia.pdf';
    $path = __DIR__ . '/' . $relativePath;
    $pdf = renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData, true);
    if (file_put_contents($path, $pdf) === false) {
        throw new RuntimeException('Impossibile salvare la copia di cortesia.');
    }
    return $relativePath;
}

function refreshInvoiceDisplayPdfs(PDO $pdo, int $invoiceId): array
{
    $invoiceStmt = $pdo->prepare(
        "SELECT f.numero_fattura, f.data_emissione, f.data_scadenza, f.natura_iva, f.bollo_virtuale,
                f.bollo_addebitato, f.importo_bollo, f.importo_netto, f.iva_totale, f.importo_totale,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome,
                c.ragione_sociale AS cliente_ragione_sociale, c.codice_fiscale AS cliente_cf,
                c.partita_iva AS cliente_piva, c.indirizzo AS cliente_indirizzo,
                c.citta AS cliente_citta, c.provincia AS cliente_provincia, c.cap AS cliente_cap,
                p.nome AS fornitore_nome, p.cognome AS fornitore_cognome,
                p.ragione_sociale AS fornitore_ragione_sociale, p.partita_iva AS fornitore_piva,
                p.codice_fiscale AS fornitore_cf, p.email AS fornitore_email,
                p.indirizzo AS fornitore_indirizzo,
                p.citta AS fornitore_citta, p.provincia AS fornitore_provincia,
                p.cap AS fornitore_cap, p.regime_fiscale AS fornitore_regime_fiscale
         FROM fatture f
         LEFT JOIN clienti c ON c.id = f.id_cliente
         LEFT JOIN professionisti p ON p.id = f.id_professionista
         WHERE f.id = :id
         LIMIT 1"
    );
    $invoiceStmt->execute([':id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch();
    if (!$invoice) {
        throw new RuntimeException('Fattura non trovata.');
    }

    $linesStmt = $pdo->prepare(
        'SELECT descrizione, quantita, prezzo_unitario, iva_percentuale, importo_netto, importo_iva
         FROM righe_fattura WHERE id_fattura = :id ORDER BY id'
    );
    $linesStmt->execute([':id' => $invoiceId]);

    $invoiceData = [];
    foreach ([
        'cliente_nome', 'cliente_cognome', 'cliente_ragione_sociale', 'cliente_cf', 'cliente_piva',
        'cliente_indirizzo', 'cliente_citta', 'cliente_provincia', 'cliente_cap', 'fornitore_nome',
        'fornitore_cognome', 'fornitore_ragione_sociale', 'fornitore_piva', 'fornitore_cf',
        'fornitore_indirizzo', 'fornitore_citta', 'fornitore_provincia', 'fornitore_cap',
        'fornitore_regime_fiscale', 'data_emissione', 'data_scadenza', 'natura_iva',
    ] as $field) {
        $invoiceData[$field] = (string)($invoice[$field] ?? '');
    }
    foreach (['bollo_virtuale', 'bollo_addebitato'] as $field) {
        $invoiceData[$field] = (int)($invoice[$field] ?? 0);
    }
    foreach (['importo_bollo', 'importo_netto', 'iva_totale', 'importo_totale'] as $field) {
        $invoiceData[$field] = (float)($invoice[$field] ?? 0);
    }
    $invoiceData['righe'] = $linesStmt->fetchAll();
    $invoiceData['iva_percentuale'] = (float)($invoiceData['righe'][0]['iva_percentuale'] ?? 0);

    ensureInvoiceDirs();
    $invoiceNumber = (string)$invoice['numero_fattura'];
    $pdfPath = 'documents/fatture/pdf/' . $invoiceNumber . '.pdf';
    $copyPath = 'documents/fatture/copie/' . $invoiceNumber . '_copia_di_cortesia.pdf';
    $invoicePdf = renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData);
    $copyPdf = renderInvoiceCourtesyPdf($invoiceNumber, $invoiceData, true);
    if (file_put_contents(__DIR__ . '/' . $pdfPath, $invoicePdf) === false) {
        throw new RuntimeException('Impossibile salvare il PDF aggiornato della fattura.');
    }
    if (file_put_contents(__DIR__ . '/' . $copyPath, $copyPdf) === false) {
        throw new RuntimeException('Impossibile salvare la copia di cortesia aggiornata.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE fatture SET pdf_path = :path WHERE id = :id')
            ->execute([':path' => $pdfPath, ':id' => $invoiceId]);
        foreach ([
            'originale' => $pdfPath,
            'copia_di_cortesia' => $copyPath,
        ] as $documentType => $path) {
            $findDocument = $pdo->prepare(
                'SELECT COUNT(*) FROM documenti_fattura
                 WHERE id_fattura = :invoice_id AND tipo_documento = :type'
            );
            $findDocument->execute([':invoice_id' => $invoiceId, ':type' => $documentType]);
            if ((int)$findDocument->fetchColumn() > 0) {
                $pdo->prepare(
                    'UPDATE documenti_fattura SET path_file = :path, created_at = NOW()
                     WHERE id_fattura = :invoice_id AND tipo_documento = :type'
                )->execute([':path' => $path, ':invoice_id' => $invoiceId, ':type' => $documentType]);
            } else {
                $pdo->prepare(
                    'INSERT INTO documenti_fattura (id_fattura, tipo_documento, path_file, created_at)
                     VALUES (:invoice_id, :type, :path, NOW())'
                )->execute([':invoice_id' => $invoiceId, ':type' => $documentType, ':path' => $path]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['pdf_path' => $pdfPath, 'copy_path' => $copyPath];
}

function generateInvoiceFromAppointment(PDO $pdo, int $appointmentId, bool $requireOnlinePaid = true): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.id_cliente, a.id_servizio, a.id_studio, a.id_professionista, a.stato, a.metodo_pagamento, a.stato_pagamento,
                c.nome AS cliente_nome, c.cognome AS cliente_cognome,
                  s.nome AS servizio_nome, s.prezzo AS servizio_prezzo, s.iva_percentuale,
                  c.ragione_sociale AS cliente_ragione_sociale, c.codice_fiscale AS cliente_cf,
                  c.partita_iva AS cliente_piva, c.codice_destinatario AS cliente_codice_destinatario,
                  c.pec AS cliente_pec, c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta,
                  c.provincia AS cliente_provincia, c.cap AS cliente_cap,
                  p.nome AS fornitore_nome, p.cognome AS fornitore_cognome,
                  p.ragione_sociale AS fornitore_ragione_sociale, p.partita_iva AS fornitore_piva,
                  p.codice_fiscale AS fornitore_cf, p.email AS fornitore_email,
                  p.indirizzo AS fornitore_indirizzo,
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

    if (($appointment['stato'] ?? '') !== 'confermato') {
        throw new RuntimeException('La fattura puo essere generata solo per appuntamenti confermati.');
    }
    if (($appointment['metodo_pagamento'] ?? '') === 'voucher') {
        throw new RuntimeException('Seduta gia coperta da voucher: gestisci la fatturazione dell acquisto dal registro voucher.');
    }
    if (($appointment['metodo_pagamento'] ?? '') === 'online' && ($appointment['stato_pagamento'] ?? '') !== 'pagato') {
        throw new RuntimeException('L appuntamento online non risulta pagato.');
    }
    if ($requireOnlinePaid && ($appointment['metodo_pagamento'] ?? '') !== 'online') {
        throw new RuntimeException('La fattura da pagamento richiede un appuntamento pagato online.');
    }

    $appointmentReference = '[appuntamento:' . $appointmentId . ']';
    $linkedInvoiceStmt = $pdo->prepare(
        'SELECT numero_fattura FROM fatture
         WHERE (note LIKE :reference OR numero_fattura = :legacy_number)
           AND stato <> \'annullata\'
         LIMIT 1'
    );
    $linkedInvoiceStmt->execute([
        ':reference' => '%' . $appointmentReference . '%',
        ':legacy_number' => paymentInvoiceNumber($appointmentId),
    ]);
    $linkedInvoice = $linkedInvoiceStmt->fetchColumn();
    if ($linkedInvoice !== false) {
        throw new RuntimeException('Esiste gia una fattura per questo appuntamento: ' . $linkedInvoice . '.');
    }

    $invoiceDate = date('Y-m-d');
    $invoiceNumber = nextInvoiceNumber($pdo, $invoiceDate);
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
            (:numero, 'TS', :data_emissione, DATE_ADD(:data_scadenza_base, INTERVAL 30 DAY), :cliente, :studio, :professionista,
             'TD01', CURDATE(), 'N2.2', 0,
             0, 0, 0,
             :netto, :iva, :totale, 'emessa', :note, NOW())"
    );
    $insertInvoice->execute([
        ':numero' => $invoiceNumber,
        ':data_emissione' => $invoiceDate,
        ':data_scadenza_base' => $invoiceDate,
        ':cliente' => $appointment['id_cliente'],
        ':studio' => $appointment['id_studio'],
        ':professionista' => $appointment['id_professionista'],
        ':netto' => number_format($price, 2, '.', ''),
        ':iva' => number_format($vat, 2, '.', ''),
        ':totale' => number_format($total, 2, '.', ''),
        ':note' => 'Regime forfettario RF19 - operazione senza applicazione IVA (natura N2.2). ' . $appointmentReference,
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

    $invoiceData = [
        'fornitore_nome' => (string)($appointment['fornitore_nome'] ?? ''),
        'fornitore_cognome' => (string)($appointment['fornitore_cognome'] ?? ''),
        'fornitore_ragione_sociale' => (string)($appointment['fornitore_ragione_sociale'] ?? ''),
        'fornitore_piva' => (string)($appointment['fornitore_piva'] ?? ''),
        'fornitore_cf' => (string)($appointment['fornitore_cf'] ?? ''),
        'fornitore_email' => (string)($appointment['fornitore_email'] ?? ''),
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
        'bollo_virtuale' => $total > 77.47 ? 1 : 0,
        'bollo_addebitato' => 0,
        'importo_bollo' => $total > 77.47 ? 2.0 : 0.0,
        'importo_totale' => $total,
        'data_emissione' => date('Y-m-d'),
        'data_scadenza' => date('Y-m-d', strtotime('+30 days')),
        'modalita_pagamento' => 'Pagamento online (MP05)',
    ];
    $pdfPath = createInvoicePdfStub($invoiceNumber, $invoiceData);
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
        'fornitore_email' => (string)($appointment['fornitore_email'] ?? ''),
        'fornitore_indirizzo' => (string)($appointment['fornitore_indirizzo'] ?? ''),
        'fornitore_citta' => (string)($appointment['fornitore_citta'] ?? ''),
        'fornitore_provincia' => (string)($appointment['fornitore_provincia'] ?? ''),
        'fornitore_cap' => (string)($appointment['fornitore_cap'] ?? ''),
        'fornitore_regime_fiscale' => (string)($appointment['fornitore_regime_fiscale'] ?? ''),
    ], $clientName, $serviceName, $price, $vatPercent, $total);
    $copyPath = createCourtesyCopy($invoiceNumber, $invoiceData);

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

$action = (string)($_POST['action'] ?? 'create');
if (!in_array($action, ['create', 'replace', 'refresh_pdf'], true)) {
    redirectToInvoices('error', 'Azione non valida.');
}
if ($action === 'refresh_pdf') {
    $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($invoiceId === false) {
        redirectToInvoices('error', 'ID fattura non valido.');
    }
    try {
        $paths = refreshInvoiceDisplayPdfs(dbConnection(), (int)$invoiceId);
        redirectToInvoices(
            'ok',
            'PDF rigenerati per fattura ID ' . (int)$invoiceId . ': ' . $paths['pdf_path'] . ' e ' . $paths['copy_path'] . '.'
        );
    } catch (Throwable $e) {
        redirectToInvoices('error', 'Rigenerazione PDF fallita: ' . $e->getMessage());
    }
}

$requestedAppointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($requestedAppointmentId !== false && $requestedAppointmentId !== null) {
    if ($action !== 'create') {
        redirectToInvoices('error', 'Azione non valida per la creazione da appuntamento.');
    }
    $appointmentId = (int)$requestedAppointmentId;
    $paymentId = null;
} else {
    $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($paymentId === false) {
        redirectToInvoices('error', 'ID pagamento non valido.');
    }
    $appointmentId = 0;
}

try {
    $pdo = dbConnection();

    if ($paymentId !== null) {
        $paymentStmt = $pdo->prepare('SELECT id_appuntamento, id_fattura, stato, metodo_pagamento FROM pagamenti WHERE id = :id LIMIT 1');
        $paymentStmt->execute([':id' => $paymentId]);
        $payment = $paymentStmt->fetch();

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
    }

    if ($appointmentId <= 0) {
        redirectToInvoices('error', 'Pagamento trovato ma senza appuntamento associato.');
    }

    $lockName = acquireInvoiceNumberLock($pdo, date('Y-m-d'));
    try {
        $pdo->beginTransaction();
        try {
            if ($action === 'replace') {
                $legacyNumber = paymentInvoiceNumber($appointmentId);
                $appointmentReference = '[appuntamento:' . $appointmentId . ']';
                $existingByNumberStmt = $pdo->prepare(
                    'SELECT id, numero_fattura, note FROM fatture
                     WHERE numero_fattura = :numero OR note LIKE :reference
                     ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $existingByNumberStmt->execute([
                    ':numero' => $legacyNumber,
                    ':reference' => '%' . $appointmentReference . '%',
                ]);
                $existing = $existingByNumberStmt->fetch();

                if ($existing) {
                    $existingId = (int)$existing['id'];
                    $archivedNumber = $existing['numero_fattura'] . '-ANN-' . $existingId;
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

            $result = generateInvoiceFromAppointment($pdo, $appointmentId, $paymentId !== null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } finally {
        releaseInvoiceNumberLock($pdo, $lockName);
    }

    $verb = $action === 'replace' ? 'Fattura annullata e rigenerata' : 'Fattura creata';
    $sourceLabel = $paymentId !== null ? 'da pagamento #' . $paymentId : 'da appuntamento #' . $appointmentId;
    redirectToInvoices('ok', $verb . ' ' . $sourceLabel . ': ' . $result['invoice_number'] . ' (ID ' . $result['invoice_id'] . ').');
} catch (Throwable $e) {
    redirectToInvoices('error', 'Creazione fattura fallita: ' . $e->getMessage());
}
