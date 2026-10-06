<?php
require_once __DIR__ . '/admin-auth.php';

try {
    $pdo = dbConnection();
} catch (Throwable $e) {
    http_response_code(503);
    die('Database non raggiungibile. Verifica la configurazione privata e i dati Aruba.');
}

$studios = $pdo->query(
    'SELECT s.id, s.nome, s.id_professionista,
            p.nome AS professionista_nome, p.cognome AS professionista_cognome,
            p.ragione_sociale AS professionista_ragione_sociale,
            p.partita_iva AS professionista_partita_iva, p.codice_fiscale AS professionista_codice_fiscale,
            p.regime_fiscale, p.indirizzo AS professionista_indirizzo,
            p.citta AS professionista_citta, p.provincia AS professionista_provincia,
            p.cap AS professionista_cap
     FROM studi s
     INNER JOIN professionisti p ON p.id = s.id_professionista
     WHERE s.attivo = 1 AND p.attivo = 1
     ORDER BY s.nome'
)->fetchAll();
$firstStudio = $studios[0] ?? [];
$supplierProfiles = [];
foreach ($studios as $studioOption) {
    $supplierProfiles[(string)$studioOption['id']] = [
        'fornitore_nome' => (string)$studioOption['professionista_nome'],
        'fornitore_cognome' => (string)$studioOption['professionista_cognome'],
        'fornitore_ragione_sociale' => (string)($studioOption['professionista_ragione_sociale'] ?? ''),
        'fornitore_partita_iva' => (string)($studioOption['professionista_partita_iva'] ?: '18730391002'),
        'fornitore_codice_fiscale' => (string)($studioOption['professionista_codice_fiscale'] ?? ''),
        'regime_fiscale' => (string)($studioOption['regime_fiscale'] ?: 'RF19'),
        'fornitore_indirizzo' => (string)($studioOption['professionista_indirizzo'] ?? ''),
        'fornitore_citta' => (string)($studioOption['professionista_citta'] ?? ''),
        'fornitore_provincia' => (string)($studioOption['professionista_provincia'] ?? ''),
        'fornitore_cap' => (string)($studioOption['professionista_cap'] ?? ''),
    ];
}

$form = [
    'tipo_cliente' => 'privato',
    'nome' => '',
    'cognome' => '',
    'ragione_sociale' => '',
    'email' => '',
    'telefono' => '',
    'codice_fiscale' => '',
    'partita_iva' => '',
    'codice_destinatario' => '',
    'pec' => '',
    'indirizzo' => '',
    'citta' => '',
    'provincia' => '',
    'cap' => '',
    'studio_id' => (string)($firstStudio['id'] ?? ''),
    'fornitore_nome' => (string)($firstStudio['professionista_nome'] ?? ''),
    'fornitore_cognome' => (string)($firstStudio['professionista_cognome'] ?? ''),
    'fornitore_ragione_sociale' => (string)($firstStudio['professionista_ragione_sociale'] ?? ''),
    'fornitore_partita_iva' => (string)($firstStudio['professionista_partita_iva'] ?: '18730391002'),
    'fornitore_codice_fiscale' => (string)($firstStudio['professionista_codice_fiscale'] ?? ''),
    'regime_fiscale' => (string)($firstStudio['regime_fiscale'] ?: 'RF19'),
    'fornitore_indirizzo' => (string)($firstStudio['professionista_indirizzo'] ?? ''),
    'fornitore_citta' => (string)($firstStudio['professionista_citta'] ?? ''),
    'fornitore_provincia' => (string)($firstStudio['professionista_provincia'] ?? ''),
    'fornitore_cap' => (string)($firstStudio['professionista_cap'] ?? ''),
    'data_pagamento' => '',
    'data_prestazione' => '',
    'descrizione' => '',
    'imponibile' => '',
    'addebita_bollo' => '0',
    'applica_rivalsa' => '0',
    'note' => '',
];
$message = '';
$messageType = 'error';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($form as $key => $default) {
        $value = $_POST[$key] ?? $default;
        $form[$key] = is_string($value) || is_numeric($value) ? trim((string)$value) : '';
    }

    $providedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!hash_equals((string)$_SESSION['admin_csrf'], $providedToken)) {
        http_response_code(400);
        $message = 'La sessione è scaduta. Ricarica la pagina e riprova.';
    } else {
        $type = $form['tipo_cliente'];
        $name = $form['nome'];
        $surname = $form['cognome'];
        $companyName = $form['ragione_sociale'];
        $email = $form['email'];
        $taxCode = strtoupper($form['codice_fiscale']);
        $vatNumber = $form['partita_iva'];
        $recipientCode = strtoupper($form['codice_destinatario']);
        $pec = $form['pec'];
        $address = $form['indirizzo'];
        $city = $form['citta'];
        $province = strtoupper($form['provincia']);
        $postalCode = $form['cap'];
        $supplierName = $form['fornitore_nome'];
        $supplierSurname = $form['fornitore_cognome'];
        $supplierCompanyName = $form['fornitore_ragione_sociale'];
        $supplierVat = $form['fornitore_partita_iva'];
        $supplierTaxCode = strtoupper($form['fornitore_codice_fiscale']);
        $supplierAddress = $form['fornitore_indirizzo'];
        $supplierCity = $form['fornitore_citta'];
        $supplierProvince = strtoupper($form['fornitore_provincia']);
        $supplierPostalCode = $form['fornitore_cap'];
        $studioId = filter_var($form['studio_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $description = $form['descrizione'];
        $paymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['data_pagamento']);
        $paymentDateErrors = DateTimeImmutable::getLastErrors();
        $serviceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['data_prestazione']);
        $serviceDateErrors = DateTimeImmutable::getLastErrors();
        $amount = filter_var($form['imponibile'], FILTER_VALIDATE_FLOAT);

        $studioStmt = $pdo->prepare(
            'SELECT s.id, s.id_professionista
             FROM studi s
             INNER JOIN professionisti p ON p.id = s.id_professionista
             WHERE s.id = :id AND s.attivo = 1 AND p.attivo = 1'
        );
        if ($studioId !== false) {
            $studioStmt->execute([':id' => $studioId]);
            $studio = $studioStmt->fetch();
        } else {
            $studio = false;
        }

        $validDate = static function (?DateTimeImmutable $date, array|false $errors, string $raw): bool {
            return $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format('Y-m-d') === $raw
                && $date <= new DateTimeImmutable('today');
        };
        $validEmail = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $validPec = $pec === '' || filter_var($pec, FILTER_VALIDATE_EMAIL) !== false;
        $validClientFiscalId = $type === 'azienda'
            ? ($taxCode === '' || preg_match('/^(?:\d{11}|[A-Z0-9]{16})$/', $taxCode) === 1)
            : preg_match('/^[A-Z0-9]{16}$/', $taxCode) === 1;
        $validVat = static fn(string $value): bool => preg_match('/^\d{11}$/', $value) === 1;
        $validRecipientCode = $recipientCode === '' || preg_match('/^[A-Z0-9]{7}$/', $recipientCode) === 1;
        $validProvince = static fn(string $value): bool => preg_match('/^[A-Z]{2}$/', $value) === 1;
        $supplierCompanyLength = preg_match_all('/./us', $supplierCompanyName);
        $validSupplier = (($supplierName !== '' && $supplierSurname !== '') || $supplierCompanyName !== '')
            && $validVat($supplierVat) && preg_match('/^[A-Z0-9]{16}$/', $supplierTaxCode) === 1
            && $supplierAddress !== '' && $supplierCity !== '' && $validProvince($supplierProvince)
            && $supplierPostalCode !== ''
            && ($supplierCompanyName === '' || ($supplierCompanyLength !== false && $supplierCompanyLength <= 255));
        $descriptionLength = preg_match_all('/./us', $description);
        $noteLength = preg_match_all('/./us', $form['note']);
        $validAmounts = $amount !== false && $amount > 0 && $amount <= 99999999.99;
        $validOptions = in_array($form['addebita_bollo'], ['0', '1'], true)
            && in_array($form['applica_rivalsa'], ['0', '1'], true);
        $validRegime = in_array($form['regime_fiscale'], ['RF19', 'RF01'], true);

        if (!in_array($type, ['privato', 'azienda'], true)
            || ($type === 'privato' && ($name === '' || $surname === ''))
            || ($type === 'azienda' && $companyName === '' && ($name === '' || $surname === ''))
            || !$validClientFiscalId || ($type === 'azienda' && !$validVat($vatNumber))
            || !$validRecipientCode || !$validEmail || !$validPec
            || ($type === 'azienda' && $recipientCode === '' && $pec === '')
            || ($type === 'privato' && preg_match('/^[A-Z]{2}$/', $province) !== 1)
            || $address === '' || $city === '' || !$validProvince($province) || $postalCode === ''
            || !$validSupplier || $studio === false
            || !$validRegime
            || !$validDate($paymentDate, $paymentDateErrors, $form['data_pagamento'])
            || !$validDate($serviceDate, $serviceDateErrors, $form['data_prestazione'])
            || $description === '' || $descriptionLength === false || $descriptionLength > 255
            || $noteLength === false || $noteLength > 2000 || !$validAmounts || !$validOptions
        ) {
            $message = 'Controlla i dati obbligatori, i dati fiscali, le date e gli importi.';
        } else {
            $regime = $form['regime_fiscale'];
            $vatPercent = $regime === 'RF19' ? 0.0 : 22.0;
            $baseAmount = round((float)$amount, 2);
            $rivalAmount = $form['applica_rivalsa'] === '1' ? round($baseAmount * 0.04, 2) : 0.0;
            $taxableAmount = round($baseAmount + $rivalAmount, 2);
            $vatAmount = round($taxableAmount * $vatPercent / 100, 2);
            $virtualStamp = $regime === 'RF19' && $taxableAmount + $vatAmount > 77.47;
            $stampAmount = $virtualStamp ? 2.0 : 0.0;
            $chargedStamp = $virtualStamp && $form['addebita_bollo'] === '1';
            $total = round($taxableAmount + $vatAmount + ($chargedStamp ? $stampAmount : 0), 2);

            if ($total > 99999999.99) {
                $message = 'Il totale supera il limite consentito per una fattura.';
            } else {
                $boilerplate = $regime === 'RF19'
                    ? 'Operazione effettuata ai sensi dell’articolo 1, commi da 54 a 89, della Legge n. 190 del 2014 così come modificato dalla Legge n. 208 del 2015 e dalla Legge n. 145 del 2018. Prestazione non soggetta a ritenuta d’acconto ai sensi del comma 67 della citata Legge n. 190 del 2014.'
                    : '';
                $invoiceNotes = trim(implode("\n\n", array_filter([
                    'Inserita manualmente da area admin.',
                    $regime === 'RF19' ? 'Regime fiscale RF19; operazione non soggetta IVA, natura N2.2.' : 'Regime fiscale RF01.',
                    $virtualStamp ? 'Imposta di bollo virtuale: € 2,00' . ($chargedStamp ? ', addebitata al cliente.' : ', a carico del professionista.') : '',
                    $boilerplate,
                    $form['note'],
                ])));

                try {
                    $pdo->beginTransaction();
                    $updateSupplier = $pdo->prepare(
                        'UPDATE professionisti
                         SET nome = :nome, cognome = :cognome, ragione_sociale = :ragione_sociale,
                             partita_iva = :partita_iva, codice_fiscale = :codice_fiscale,
                             regime_fiscale = :regime_fiscale, indirizzo = :indirizzo,
                             citta = :citta, provincia = :provincia, cap = :cap
                         WHERE id = :id'
                    );
                    $updateSupplier->execute([
                        ':nome' => $supplierName !== '' ? $supplierName : $supplierCompanyName,
                        ':cognome' => $supplierSurname,
                        ':ragione_sociale' => $supplierCompanyName !== '' ? $supplierCompanyName : null,
                        ':partita_iva' => $supplierVat,
                        ':codice_fiscale' => $supplierTaxCode,
                        ':regime_fiscale' => $regime,
                        ':indirizzo' => $supplierAddress,
                        ':citta' => $supplierCity,
                        ':provincia' => $supplierProvince,
                        ':cap' => $supplierPostalCode,
                        ':id' => (int)$studio['id_professionista'],
                    ]);

                    $insertClient = $pdo->prepare(
                        'INSERT INTO clienti
                         (nome, cognome, ragione_sociale, email, telefono, codice_fiscale, partita_iva,
                          codice_destinatario, pec, indirizzo, citta, provincia, cap, tipo_cliente)
                         VALUES
                         (:nome, :cognome, :ragione_sociale, :email, :telefono, :codice_fiscale, :partita_iva,
                          :codice_destinatario, :pec, :indirizzo, :citta, :provincia, :cap, :tipo_cliente)'
                    );
                    $insertClient->execute([
                        ':nome' => $name !== '' ? $name : $companyName,
                        ':cognome' => $surname,
                        ':ragione_sociale' => $type === 'azienda' ? $companyName : null,
                        ':email' => $email !== '' ? $email : null,
                        ':telefono' => $form['telefono'] !== '' ? $form['telefono'] : null,
                        ':codice_fiscale' => $type === 'privato' || $taxCode !== '' ? $taxCode : null,
                        ':partita_iva' => $type === 'azienda' ? $vatNumber : null,
                        ':codice_destinatario' => $type === 'azienda' ? ($recipientCode !== '' ? $recipientCode : null) : '0000000',
                        ':pec' => $type === 'azienda' && $pec !== '' ? $pec : null,
                        ':indirizzo' => $address,
                        ':citta' => $city,
                        ':provincia' => $province,
                        ':cap' => $postalCode,
                        ':tipo_cliente' => $type,
                    ]);
                    $clientId = (int)$pdo->lastInsertId();

                    $insertInvoice = $pdo->prepare(
                        'INSERT INTO fatture
                         (numero_fattura, serie, data_emissione, id_cliente, id_studio, id_professionista,
                          tipo_documento, data_prestazione, natura_iva, importo_rivalsa,
                          bollo_virtuale, bollo_addebitato, importo_bollo,
                          importo_netto, iva_totale, importo_totale, stato, note, created_at)
                         VALUES
                         (:numero, :serie, :data_emissione, :id_cliente, :id_studio, :id_professionista,
                          :tipo_documento, :data_prestazione, :natura_iva, :importo_rivalsa,
                          :bollo_virtuale, :bollo_addebitato, :importo_bollo,
                          :netto, :iva, :totale, "bozza", :note, NOW())'
                    );
                    $insertInvoice->execute([
                        ':numero' => 'BOZZA',
                        ':serie' => 'TS',
                        ':data_emissione' => $paymentDate->format('Y-m-d'),
                        ':id_cliente' => $clientId,
                        ':id_studio' => (int)$studio['id'],
                        ':id_professionista' => (int)$studio['id_professionista'],
                        ':tipo_documento' => 'TD01',
                        ':data_prestazione' => $serviceDate->format('Y-m-d'),
                        ':natura_iva' => $regime === 'RF19' ? 'N2.2' : null,
                        ':importo_rivalsa' => number_format($rivalAmount, 2, '.', ''),
                        ':bollo_virtuale' => $virtualStamp ? 1 : 0,
                        ':bollo_addebitato' => $chargedStamp ? 1 : 0,
                        ':importo_bollo' => number_format($stampAmount, 2, '.', ''),
                        ':netto' => number_format($taxableAmount, 2, '.', ''),
                        ':iva' => number_format($vatAmount, 2, '.', ''),
                        ':totale' => number_format($total, 2, '.', ''),
                        ':note' => $invoiceNotes,
                    ]);
                    $invoiceId = (int)$pdo->lastInsertId();
                    $invoiceNumber = sprintf('BOZZA-%s-%05d', $paymentDate->format('Y'), $invoiceId);
                    $pdo->prepare('UPDATE fatture SET numero_fattura = :numero WHERE id = :id')
                        ->execute([':numero' => $invoiceNumber, ':id' => $invoiceId]);

                    $insertLine = $pdo->prepare(
                        'INSERT INTO righe_fattura
                         (id_fattura, id_servizio, descrizione, quantita, prezzo_unitario, iva_percentuale,
                          importo_netto, importo_iva, importo_totale, created_at)
                         VALUES (:id_fattura, NULL, :descrizione, 1, :prezzo, :iva, :netto, :iva_valore, :totale, NOW())'
                    );
                    $insertLine->execute([
                        ':id_fattura' => $invoiceId,
                        ':descrizione' => $description,
                        ':prezzo' => number_format($baseAmount, 2, '.', ''),
                        ':iva' => number_format($vatPercent, 2, '.', ''),
                        ':netto' => number_format($baseAmount, 2, '.', ''),
                        ':iva_valore' => number_format(round($baseAmount * $vatPercent / 100, 2), 2, '.', ''),
                        ':totale' => number_format(round($baseAmount + $baseAmount * $vatPercent / 100, 2), 2, '.', ''),
                    ]);
                    if ($rivalAmount > 0) {
                        $insertLine->execute([
                            ':id_fattura' => $invoiceId,
                            ':descrizione' => 'Rivalsa INPS 4%',
                            ':prezzo' => number_format($rivalAmount, 2, '.', ''),
                            ':iva' => number_format($vatPercent, 2, '.', ''),
                            ':netto' => number_format($rivalAmount, 2, '.', ''),
                            ':iva_valore' => number_format(round($rivalAmount * $vatPercent / 100, 2), 2, '.', ''),
                            ':totale' => number_format(round($rivalAmount + $rivalAmount * $vatPercent / 100, 2), 2, '.', ''),
                        ]);
                    }
                    $pdo->commit();

                    header('Location: fatture-admin.php?manuale=creata');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Manual invoice draft creation failed: ' . $e->getMessage());
                    http_response_code(500);
                    $message = 'Non è stato possibile salvare la bozza. Verifica la configurazione del database e riprova.';
                }
            }
        }
    }
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$vatPreview = $form['regime_fiscale'] === 'RF01' ? 22 : 0;
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nuova fattura manuale | Thai Tik Massage</title>
    <style>
      :root { --ink: #2a201d; --muted: #716862; --paper: #f7f2ed; --line: #ded5cb; --accent: #8b4438; }
      * { box-sizing: border-box; }
      body { margin: 0; background: var(--paper); color: var(--ink); font: 16px/1.5 Arial, sans-serif; }
      main { width: min(100% - 32px, 960px); margin: 0 auto; padding: 30px 0 56px; }
      .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 22px; }
      h1 { margin: 0; font-size: clamp(28px, 4vw, 38px); }
      h2 { margin: 0 0 16px; font-size: 20px; }
      .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid var(--line); }
      .notice, .message { margin: 0 0 18px; padding: 14px 16px; border: 1px solid var(--line); background: #fff; }
      .notice { border-left: 4px solid #a67837; color: #59452e; }
      .message.error { border-left: 4px solid var(--accent); color: var(--accent); }
      .message.success { border-left: 4px solid #2f5d3f; color: #2f5d3f; }
      .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
      label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; }
      input, select, textarea { width: 100%; min-height: 44px; padding: 9px 11px; border: 1px solid #b8aea5; border-radius: 0; background: #fff; color: var(--ink); font: inherit; }
      textarea { min-height: 90px; resize: vertical; }
      input[readonly] { background: #f5f0eb; }
      .field { margin-bottom: 14px; }
      .full { grid-column: 1 / -1; }
      .muted { color: var(--muted); font-size: 13px; }
      .actions { display: flex; flex-wrap: wrap; gap: 10px; }
      .button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 16px; border: 0; background: var(--ink); color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-decoration: none; text-transform: uppercase; cursor: pointer; }
      .button.secondary { background: #76665d; }
      .check { display: flex; align-items: flex-start; gap: 10px; }
      .check input { width: 18px; min-height: 18px; margin: 2px 0 0; }
      @media (max-width: 640px) { .topbar { align-items: flex-start; flex-direction: column; } .grid { grid-template-columns: 1fr; } .full { grid-column: auto; } .panel { padding: 17px; } }
    </style>
  </head>
  <body>
    <main>
      <div class="topbar">
        <h1>Inserimento fattura manuale</h1>
        <a class="button secondary" href="fatture-admin.php">Torna alle fatture</a>
      </div>

      <p class="notice">Il salvataggio crea una bozza interna (TD01), non una fattura emessa. Non genera il file FatturaPA e non invia documenti allo SDI. Verifica i dati e il trattamento fiscale con il commercialista prima dell’emissione.</p>
      <?php if ($message !== ''): ?><p class="message <?= $messageType ?>" role="alert"><?= $escape($message) ?></p><?php endif; ?>
      <?php if (isset($_GET['manuale']) && $_GET['manuale'] === 'creata'): ?><p class="message success" role="status">Bozza registrata. La trovi nel registro fatture.</p><?php endif; ?>

      <?php if (empty($studios)): ?>
        <p class="message error" role="alert">Non ci sono studi e professionisti attivi: configura i dati prima di inserire una bozza.</p>
      <?php else: ?>
      <form method="post" action="fattura-manuale.php">
        <input type="hidden" name="csrf_token" value="<?= $escape((string)$_SESSION['admin_csrf']) ?>" />
        <section class="panel" aria-labelledby="supplier-title">
          <h2 id="supplier-title">Dati del fornitore</h2>
          <div class="grid">
            <div class="field">
              <label for="studio_id">Studio</label>
              <select id="studio_id" name="studio_id" required>
                <?php foreach ($studios as $studioOption): ?>
                  <option value="<?= (int)$studioOption['id'] ?>" <?= $form['studio_id'] === (string)$studioOption['id'] ? 'selected' : '' ?>><?= $escape((string)$studioOption['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="regime_fiscale">Regime fiscale</label>
              <select id="regime_fiscale" name="regime_fiscale">
                <option value="RF19" <?= $form['regime_fiscale'] === 'RF19' ? 'selected' : '' ?>>Forfettario (RF19)</option>
                <option value="RF01" <?= $form['regime_fiscale'] === 'RF01' ? 'selected' : '' ?>>Ordinario (RF01)</option>
              </select>
            </div>
            <?php
              $supplierFields = [
                  ['fornitore_nome', 'Nome (se non usi la ragione sociale)', false, 100],
                  ['fornitore_cognome', 'Cognome (se non usi la ragione sociale)', false, 100],
                  ['fornitore_ragione_sociale', 'Ragione sociale (facoltativa)', false, 255],
                  ['fornitore_partita_iva', 'Partita IVA', true, 11],
                  ['fornitore_codice_fiscale', 'Codice fiscale', true, 16],
                  ['fornitore_indirizzo', 'Indirizzo sede legale', true, 255],
                  ['fornitore_cap', 'CAP', true, 10],
                  ['fornitore_citta', 'Comune', true, 100],
                  ['fornitore_provincia', 'Provincia (sigla)', true, 2],
              ];
              foreach ($supplierFields as [$fieldName, $label, $required, $maxlength]):
            ?>
              <div class="field">
                <label for="<?= $fieldName ?>"><?= $label ?></label>
                <input id="<?= $fieldName ?>" name="<?= $fieldName ?>" maxlength="<?= $maxlength ?>" <?= $required ? 'required' : '' ?> value="<?= $escape($form[$fieldName]) ?>" />
              </div>
            <?php endforeach; ?>
            <div class="field full">
              <p class="muted">I dati del fornitore vengono salvati nel profilo del professionista associato allo studio selezionato.</p>
            </div>
          </div>
        </section>

        <section class="panel" aria-labelledby="client-title">
          <h2 id="client-title">Dati del cliente</h2>
          <div class="grid">
            <div class="field">
              <label for="tipo_cliente">Tipo cliente</label>
              <select id="tipo_cliente" name="tipo_cliente">
                <option value="privato" <?= $form['tipo_cliente'] === 'privato' ? 'selected' : '' ?>>Privato</option>
                <option value="azienda" <?= $form['tipo_cliente'] === 'azienda' ? 'selected' : '' ?>>Azienda / professionista</option>
              </select>
            </div>
            <div class="field company-only" hidden>
              <label for="ragione_sociale">Ragione sociale</label>
              <input id="ragione_sociale" name="ragione_sociale" maxlength="255" value="<?= $escape($form['ragione_sociale']) ?>" />
            </div>
            <div class="field">
              <label for="nome">Nome / referente</label>
              <input id="nome" name="nome" autocomplete="given-name" maxlength="100" value="<?= $escape($form['nome']) ?>" />
            </div>
            <div class="field">
              <label for="cognome">Cognome / referente</label>
              <input id="cognome" name="cognome" autocomplete="family-name" maxlength="100" value="<?= $escape($form['cognome']) ?>" />
            </div>
            <div class="field">
              <label for="codice_fiscale">Codice fiscale (obbligatorio per privati)</label>
              <input id="codice_fiscale" name="codice_fiscale" maxlength="16" autocomplete="off" value="<?= $escape($form['codice_fiscale']) ?>" />
            </div>
            <div class="field company-only" hidden>
              <label for="partita_iva">Partita IVA</label>
              <input id="partita_iva" name="partita_iva" inputmode="numeric" maxlength="11" autocomplete="off" value="<?= $escape($form['partita_iva']) ?>" />
            </div>
            <div class="field company-only" hidden>
              <label for="codice_destinatario">Codice destinatario (7 caratteri)</label>
              <input id="codice_destinatario" name="codice_destinatario" maxlength="7" autocomplete="off" value="<?= $escape($form['codice_destinatario']) ?>" />
            </div>
            <div class="field company-only" hidden>
              <label for="pec">PEC</label>
              <input id="pec" name="pec" type="email" maxlength="255" autocomplete="email" value="<?= $escape($form['pec']) ?>" />
            </div>
            <div class="field">
              <label for="email">Email (facoltativa)</label>
              <input id="email" name="email" type="email" maxlength="255" autocomplete="email" value="<?= $escape($form['email']) ?>" />
            </div>
            <div class="field">
              <label for="telefono">Telefono (facoltativo)</label>
              <input id="telefono" name="telefono" type="tel" maxlength="50" autocomplete="tel" value="<?= $escape($form['telefono']) ?>" />
            </div>
            <div class="field full">
              <label for="indirizzo">Indirizzo di fatturazione</label>
              <input id="indirizzo" name="indirizzo" maxlength="255" autocomplete="street-address" required value="<?= $escape($form['indirizzo']) ?>" />
            </div>
            <div class="field">
              <label for="citta">Comune</label>
              <input id="citta" name="citta" maxlength="100" autocomplete="address-level2" required value="<?= $escape($form['citta']) ?>" />
            </div>
            <div class="field">
              <label for="provincia">Provincia (sigla)</label>
              <input id="provincia" name="provincia" maxlength="2" autocomplete="address-level1" required value="<?= $escape($form['provincia']) ?>" />
            </div>
            <div class="field">
              <label for="cap">CAP</label>
              <input id="cap" name="cap" maxlength="10" autocomplete="postal-code" required value="<?= $escape($form['cap']) ?>" />
            </div>
            <div class="field private-only">
              <label>Codice destinatario</label>
              <input value="0000000" readonly aria-readonly="true" />
              <span class="muted">Per il consumatore finale, lo SdI usa il codice destinatario convenzionale.</span>
            </div>
          </div>
        </section>

        <section class="panel" aria-labelledby="invoice-title">
          <h2 id="invoice-title">Dati del documento</h2>
          <div class="grid">
            <div class="field">
              <label>Tipo documento</label>
              <input value="TD01 - Fattura ordinaria" readonly aria-readonly="true" />
            </div>
            <div class="field">
              <label>Numero</label>
              <input value="Assegnato come BOZZA al salvataggio" readonly aria-readonly="true" />
            </div>
            <div class="field">
              <label for="data_pagamento">Data pagamento</label>
              <input id="data_pagamento" name="data_pagamento" type="date" required value="<?= $escape($form['data_pagamento']) ?>" />
              <span class="muted">Usata come data documento della bozza. Termine indicativo di invio: 12 giorni dalla data inserita.</span>
            </div>
            <div class="field">
              <label for="termine_invio">Termine indicativo (12 giorni)</label>
              <input id="termine_invio" readonly aria-readonly="true" value="" />
            </div>
            <div class="field">
              <label for="data_prestazione">Data prestazione</label>
              <input id="data_prestazione" name="data_prestazione" type="date" required value="<?= $escape($form['data_prestazione']) ?>" />
            </div>
            <div class="field full">
              <label for="descrizione">Descrizione della prestazione</label>
              <input id="descrizione" name="descrizione" maxlength="255" required placeholder="Trattamento olistico / Massaggio benessere eseguito in data GG/MM/AAAA" value="<?= $escape($form['descrizione']) ?>" />
            </div>
            <div class="field">
              <label for="imponibile">Prezzo della prestazione (€)</label>
              <input id="imponibile" name="imponibile" type="number" min="0.01" max="99999999.99" step="0.01" required value="<?= $escape($form['imponibile']) ?>" />
            </div>
            <div class="field">
              <label>Aliquota e natura IVA</label>
              <input id="vat-summary" value="<?= $vatPreview === 0 ? '0% - N2.2 (regime forfettario)' : '22% (regime ordinario)' ?>" readonly aria-readonly="true" />
            </div>
            <div class="field">
              <label class="check" for="applica_rivalsa">
                <input id="applica_rivalsa" name="applica_rivalsa" type="checkbox" value="1" <?= $form['applica_rivalsa'] === '1' ? 'checked' : '' ?> />
                <span>Addebita rivalsa INPS del 4% (facoltativa)</span>
              </label>
            </div>
            <div class="field" id="bollo-field" <?= $form['regime_fiscale'] === 'RF01' ? 'hidden' : '' ?>>
              <label class="check" for="addebita_bollo">
                <input id="addebita_bollo" name="addebita_bollo" type="checkbox" value="1" <?= $form['addebita_bollo'] === '1' ? 'checked' : '' ?> />
                <span>Addebita al cliente il bollo virtuale di € 2,00 se dovuto. Se non selezionato, resta a carico del professionista.</span>
              </label>
              <p class="muted">Il bollo virtuale viene previsto automaticamente per il forfettario quando l’importo supera € 77,47.</p>
            </div>
            <div class="field full">
              <label for="note">Note aggiuntive</label>
              <textarea id="note" name="note" maxlength="2000"><?= $escape($form['note']) ?></textarea>
              <span class="muted">Per RF19 il sistema aggiunge automaticamente la dicitura normativa indicata.</span>
            </div>
          </div>
          <p class="muted">Prezzo inserito come compenso al netto dell’eventuale rivalsa e del bollo. Il numero BOZZA non è la numerazione fiscale definitiva.</p>
        </section>

        <div class="actions">
          <button class="button" type="submit">Registra bozza</button>
          <a class="button secondary" href="fatture-admin.php">Annulla</a>
        </div>
      </form>
      <?php endif; ?>
    </main>
    <script>
      const customerType = document.getElementById('tipo_cliente');
      const regime = document.getElementById('regime_fiscale');
      const studioSelector = document.getElementById('studio_id');
      const supplierProfiles = <?= json_encode($supplierProfiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const toggleCustomerFields = () => {
        if (!customerType) return;
        const isCompany = customerType.value === 'azienda';
        document.getElementById('nome').required = !isCompany;
        document.getElementById('cognome').required = !isCompany;
        document.querySelectorAll('.company-only').forEach((field) => {
          field.hidden = !isCompany;
          const input = field.querySelector('input');
          if (input) {
            input.required = (input.id === 'ragione_sociale' || input.id === 'partita_iva') && isCompany;
            if (input.id === 'codice_fiscale_azienda') input.required = false;
          }
        });
        const taxCode = document.getElementById('codice_fiscale');
        if (taxCode) taxCode.required = !isCompany;
      };
      const toggleRegimeFields = () => {
        if (!regime) return;
        const forfait = regime.value === 'RF19';
        document.getElementById('vat-summary').value = forfait
          ? '0% - N2.2 (regime forfettario)'
          : '22% (regime ordinario)';
        document.getElementById('bollo-field').hidden = !forfait;
      };
      if (customerType) {
        customerType.addEventListener('change', toggleCustomerFields);
        toggleCustomerFields();
      }
      if (regime) {
        regime.addEventListener('change', toggleRegimeFields);
        toggleRegimeFields();
      }
      if (studioSelector) {
        studioSelector.addEventListener('change', () => {
          const profile = supplierProfiles[studioSelector.value];
          if (!profile) return;
          Object.entries(profile).forEach(([field, value]) => {
            const input = document.getElementById(field);
            if (input) input.value = value;
          });
          toggleRegimeFields();
        });
      }
      const paymentDate = document.getElementById('data_pagamento');
      const deadline = document.getElementById('termine_invio');
      const updateDeadline = () => {
        if (!paymentDate || !deadline || !paymentDate.value) {
          if (deadline) deadline.value = '';
          return;
        }
        const date = new Date(`${paymentDate.value}T00:00:00`);
        date.setDate(date.getDate() + 12);
        deadline.value = date.toLocaleDateString('it-IT');
      };
      if (paymentDate) {
        paymentDate.addEventListener('change', updateDeadline);
        updateDeadline();
      }
    </script>
  </body>
</html>
