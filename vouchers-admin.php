<?php
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/voucher-store.php';
require_once __DIR__ . '/voucher-manual.php';
require_once __DIR__ . '/voucher-admin-actions.php';
date_default_timezone_set('Europe/Rome');
header('Cache-Control: no-store');

function voucherEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$services = [];
$studios = [];
$formInput = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['voucher_action'] ?? '') === 'create'
    ? $_POST : [];
$createToken = is_string($formInput['request_token'] ?? null) ? $formInput['request_token'] : bin2hex(random_bytes(32));
try {
    $pdo = dbConnection();
    $services = $pdo->query(
        'SELECT sv.id, sv.id_studio, sv.nome, sv.durata_minuti, sv.prezzo, s.nome AS studio_name
         FROM servizi sv INNER JOIN studi s ON s.id = sv.id_studio
         WHERE sv.attivo = 1 AND s.attivo = 1 ORDER BY s.nome, sv.nome, sv.durata_minuti'
    )->fetchAll();
    foreach ($services as $service) {
        $studios[$service['id_studio']] = $service['studio_name'];
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['voucher_action'])) {
        if (!is_string($_POST['csrf_token'] ?? null)
            || !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])) {
            throw new InvalidArgumentException('Sessione scaduta. Ricarica la pagina e riprova.');
        }
        if ($_POST['voucher_action'] === 'create') {
            $created = voucherManualCreate($pdo, $_POST, new DateTimeImmutable('now'));
            $_SESSION['voucher_notice'] = 'Voucher emesso. Codice per prenotare: ' . $created['voucher_code'];
            header('Location: vouchers-admin.php?code=' . rawurlencode($created['voucher_code']));
            exit;
        }
        $id = filter_var($_POST['voucher_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new InvalidArgumentException('Voucher non valido.');
        }
        $stmt = $pdo->prepare('SELECT * FROM gift_vouchers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $voucher = $stmt->fetch();
        if (!$voucher) {
            throw new InvalidArgumentException('Voucher non trovato.');
        }
        if ($_POST['voucher_action'] === 'remove') {
            $message = voucherRemoveOrDisable($pdo, $id);
        } elseif ($_POST['voucher_action'] === 'update_amount') {
            if ($voucher['source'] !== 'manual') {
                throw new InvalidArgumentException('Il prezzo è modificabile solo per i voucher emessi manualmente.');
            }
            $amount = voucherManualAmount($_POST['amount'] ?? null);
            $stmt = $pdo->prepare("UPDATE gift_vouchers SET amount = :amount WHERE id = :id AND source = 'manual'");
            $stmt->execute([':amount' => $amount, ':id' => $id]);
            $message = 'Importo del voucher manuale aggiornato.';
        } elseif ($_POST['voucher_action'] === 'verify') {
            if (!$voucher['checkout_id']) {
                throw new InvalidArgumentException('Nessun pagamento SumUp associato a questo voucher.');
            }
            $result = voucherVerify($pdo, $voucher);
            $message = 'Stato verificato: ' . $result['voucher']['status'];
        } elseif ($_POST['voucher_action'] === 'use') {
            $requestToken = $_POST['request_token'] ?? '';
            if (!is_string($requestToken) || !preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
                throw new InvalidArgumentException('Richiesta di utilizzo non valida.');
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM gift_vouchers WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $voucher = $stmt->fetch();
            $stmt = $pdo->prepare('SELECT voucher_id FROM gift_voucher_uses WHERE request_token = :token');
            $stmt->execute([':token' => $requestToken]);
            $previous = $stmt->fetchColumn();
            if ($previous !== false) {
                if ((int)$previous !== $id) {
                    throw new InvalidArgumentException('Richiesta di utilizzo già associata a un altro voucher.');
                }
                $message = 'Seduta già registrata: nessun ulteriore utilizzo addebitato.';
            } else {
                if (!voucherCanUse($voucher, new DateTimeImmutable('now'))) {
                    throw new InvalidArgumentException('Voucher non utilizzabile: non pagato, scaduto o sedute esaurite.');
                }
                $stmt = $pdo->prepare('INSERT INTO gift_voucher_uses (voucher_id, request_token) VALUES (:id, :token)');
                $stmt->execute([':id' => $id, ':token' => $requestToken]);
                $stmt = $pdo->prepare('UPDATE gift_vouchers SET remaining_sessions = remaining_sessions - 1 WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $message = 'Utilizzo registrato: una seduta scalata dal voucher.';
            }
            $pdo->commit();
        } else {
            throw new InvalidArgumentException('Operazione non valida.');
        }
        $_SESSION['voucher_notice'] = $message;
        header('Location: vouchers-admin.php');
        exit;
    }
    $message = $_SESSION['voucher_notice'] ?? '';
    unset($_SESSION['voucher_notice']);
    $search = trim((string)($_GET['code'] ?? ''));
    $stmt = $pdo->prepare(
        "SELECT gv.*, s.nome AS studio_name FROM gift_vouchers gv
         INNER JOIN studi s ON s.id = gv.id_studio
         WHERE (:all_rows = 1 OR gv.voucher_code = :code)
         ORDER BY gv.id DESC LIMIT 100"
    );
    $stmt->execute([':all_rows' => $search === '' ? 1 : 0, ':code' => $search]);
    $vouchers = $stmt->fetchAll();
    $bookingsByVoucher = [];
    $stmt = $pdo->prepare(
        'SELECT vb.*, a.data_appuntamento, a.ora_inizio, a.stato
         FROM gift_voucher_bookings vb INNER JOIN appuntamenti a ON a.id = vb.appointment_id
         INNER JOIN gift_vouchers v ON v.id = vb.voucher_id
         WHERE (:all_rows = 1 OR v.voucher_code = :code) ORDER BY vb.id DESC LIMIT 500'
    );
    $stmt->execute([':all_rows' => $search === '' ? 1 : 0, ':code' => $search]);
    foreach ($stmt->fetchAll() as $booking) {
        $bookingsByVoucher[(int)$booking['voucher_id']][] = $booking;
    }
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    $message = $e->getMessage();
    $vouchers = [];
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Voucher admin error: ' . $e->getMessage());
    http_response_code(503);
    $message = 'Operazione non disponibile. Verifica il database e le migrazioni voucher. Prima di riprovare, controlla il registro.';
    $vouchers = [];
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registro voucher | Thai Tik Massage</title>
  <style>
    body{margin:0;background:#fbf9f5;color:#2a201d;font:16px/1.6 Arial,sans-serif}
    main{max-width:1100px;margin:auto;padding:24px}article{padding:20px;margin:20px 0;background:#fff;border:1px solid #ded5cb}
    input,select,button{padding:10px;font:inherit;max-width:100%;box-sizing:border-box}button{cursor:pointer}
    .fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:12px 0}
    .fields label{display:flex;flex-direction:column;gap:4px}
    p{overflow-wrap:anywhere}.notice{color:#8b4438}dl{display:grid;grid-template-columns:1fr 2fr;gap:6px}dd{margin:0;overflow-wrap:anywhere}
  </style>
</head>
<body>
  <?php renderAdminToolbar(); ?>
<main>
  <a href="area-admin.php">Torna all’area admin</a>
  <h1>Registro voucher regalo</h1>
  <p>Ultimi 100 acquisti, oppure ricerca per codice completo. La verifica SumUp recupera anche acquisti pagati senza ritorno al sito. Le prenotazioni con codice scalano automaticamente una seduta; l’annullamento dall’agenda la restituisce. Non registrare di nuovo manualmente le sedute già scalate online.</p>
  <?php if ($message !== ''): ?><p class="notice" role="status"><?= voucherEscape($message) ?></p><?php endif; ?>
  <article>
    <h2>Emetti voucher per pagamento al centro</h2>
    <p>Inserisci il destinatario e scegli sede, trattamento e formula. Il prezzo proposto segue il listino online, ma può essere modificato; non comparirà sul PDF. L’emissione attesta che il pagamento è stato ricevuto fuori dal sito e non crea una fattura.</p>
    <form method="post" id="manual-voucher-form">
      <input type="hidden" name="csrf_token" value="<?= voucherEscape($_SESSION['admin_csrf']) ?>">
      <input type="hidden" name="request_token" value="<?= voucherEscape($createToken) ?>">
      <input type="hidden" name="voucher_action" value="create">
      <div class="fields">
        <label>Appellativo <select name="recipient_title" required>
          <option value="">Seleziona</option>
          <option value="sig" <?= ($formInput['recipient_title'] ?? '') === 'sig' ? 'selected' : '' ?>>Sig.</option>
          <option value="sigra" <?= ($formInput['recipient_title'] ?? '') === 'sigra' ? 'selected' : '' ?>>Sig.ra</option>
        </select></label>
        <label>Nome <input name="first_name" required maxlength="74" value="<?= voucherEscape($formInput['first_name'] ?? '') ?>"></label>
        <label>Cognome <input name="last_name" required maxlength="74" value="<?= voucherEscape($formInput['last_name'] ?? '') ?>"></label>
        <label>Sede <select name="studio_id" id="manual-studio" required>
          <option value="">Seleziona la sede</option>
          <?php foreach ($studios as $id => $name): ?>
            <option value="<?= (int)$id ?>" <?= (string)($formInput['studio_id'] ?? '') === (string)$id ? 'selected' : '' ?>><?= voucherEscape(str_replace('Montesacro', 'Monte Sacro', $name)) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Trattamento e durata <select name="service_id" id="manual-service" required>
          <option value="">Seleziona il trattamento</option>
          <?php foreach ($services as $service): ?>
            <option value="<?= (int)$service['id'] ?>" data-studio="<?= (int)$service['id_studio'] ?>" data-price="<?= voucherEscape($service['prezzo']) ?>" <?= (string)($formInput['service_id'] ?? '') === (string)$service['id'] ? 'selected' : '' ?>><?= voucherEscape(str_replace('Montesacro', 'Monte Sacro', $service['studio_name']) . ' · ' . $service['nome']) ?> · <?= (int)$service['durata_minuti'] ?> min</option>
          <?php endforeach; ?>
        </select></label>
        <label>Formula <select name="sessions_per_item" id="manual-formula">
          <?php foreach ([1, 3, 6] as $count): ?>
            <option value="<?= $count ?>" <?= (string)($formInput['sessions_per_item'] ?? '1') === (string)$count ? 'selected' : '' ?>><?= $count ?> <?= $count === 1 ? 'seduta' : 'sedute' ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Quantità <input type="number" name="quantity" id="manual-quantity" min="1" max="20" required value="<?= voucherEscape($formInput['quantity'] ?? '1') ?>"></label>
        <label>Importo pagato (EUR) <input name="amount" id="manual-amount" inputmode="decimal" required maxlength="9" value="<?= voucherEscape($formInput['amount'] ?? '') ?>" placeholder="0,00"></label>
      </div>
      <p>Validità: 12 mesi dall’emissione. Cambiando sede, trattamento o formula, il prezzo proposto viene ricalcolato.</p>
      <button type="submit" <?= !$services ? 'disabled' : '' ?>>Emetti voucher</button>
    </form>
  </article>
  <form method="get"><label for="code">Codice voucher</label> <input id="code" name="code" maxlength="40" value="<?= voucherEscape($_GET['code'] ?? '') ?>"><button>Cerca</button></form>
  <?php foreach ($vouchers as $voucher): ?>
    <article>
      <h2><?= voucherEscape($voucher['voucher_code'] ?? ('Acquisto #' . $voucher['id'])) ?></h2>
      <p><?= voucherEscape($voucher['recipient_name']) ?> · <?= voucherEscape(str_replace('Montesacro', 'Monte Sacro', $voucher['studio_name'])) ?> · <?= voucherEscape($voucher['service_name']) ?> · <?= (int)$voucher['duration_minutes'] ?> min</p>
      <p><?= $voucher['source'] === 'manual' ? 'Emesso manualmente, pagamento al centro' : 'Pagamento online' ?> · Stato: <?= voucherEscape($voucher['status']) ?><?= $voucher['disabled_at'] !== null ? ' · Disattivato' : '' ?> · Importo: <?= voucherEscape($voucher['amount']) ?> EUR</p>
      <?php if ($voucher['source'] === 'manual'): ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= voucherEscape($_SESSION['admin_csrf']) ?>">
          <input type="hidden" name="voucher_id" value="<?= (int)$voucher['id'] ?>">
          <label>Modifica importo (EUR) <input name="amount" inputmode="decimal" maxlength="9" required value="<?= voucherEscape($voucher['amount']) ?>"></label>
          <button name="voucher_action" value="update_amount">Salva importo</button>
        </form>
      <?php endif; ?>
      <p>Sedute regalate: <?= (int)$voucher['total_sessions'] ?> · Residue: <?= (int)$voucher['remaining_sessions'] ?> · Scadenza: <?= voucherEscape($voucher['valid_until'] ?? 'non ancora emesso') ?></p>
      <?php if (!empty($bookingsByVoucher[(int)$voucher['id']])): ?>
        <details><summary>Prenotazioni collegate al voucher</summary><ul>
          <?php foreach ($bookingsByVoucher[(int)$voucher['id']] as $booking): ?>
            <li>Appuntamento #<?= (int)$booking['appointment_id'] ?> · <?= voucherEscape($booking['data_appuntamento']) ?> <?= voucherEscape($booking['ora_inizio']) ?> · <?= voucherEscape($booking['stato']) ?> · <?= $booking['restored_at'] !== null ? 'seduta restituita' : 'seduta scalata' ?>
              <?php if ($booking['stato'] !== 'annullato'): ?>
                <form method="post" action="booking-admin-advanced.php" onsubmit="return confirm('Annullare la prenotazione e restituire la seduta? Lo storico sarà conservato.');">
                  <input type="hidden" name="csrf_token" value="<?= voucherEscape($_SESSION['admin_csrf']) ?>">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="id" value="<?= (int)$booking['appointment_id'] ?>">
                  <button type="submit">Annulla prenotazione e restituisci seduta</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul><a href="booking-admin-advanced.php">Gestisci gli appuntamenti</a></details>
      <?php endif; ?>
      <?php if ($voucher['status'] === 'paid' && $voucher['disabled_at'] === null): ?>
        <a href="voucher.php?action=pdf&amp;token=<?= voucherEscape($voucher['token']) ?>" rel="noreferrer">Scarica voucher PDF</a>
        <?php if ($voucher['source'] !== 'manual'): ?>
        <details><summary>Dati acquirente per fatturazione manuale</summary><dl>
        <?php foreach (json_decode($voucher['customer_data'], true, 512, JSON_THROW_ON_ERROR) as $key => $value): ?>
          <dt><?= voucherEscape($key) ?></dt><dd><?= voucherEscape($value) ?></dd>
        <?php endforeach; ?>
        </dl></details>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= voucherEscape($_SESSION['admin_csrf']) ?>">
        <input type="hidden" name="voucher_id" value="<?= (int)$voucher['id'] ?>">
        <?php if ($voucher['status'] !== 'paid' && $voucher['checkout_id']): ?>
          <button name="voucher_action" value="verify">Verifica pagamento SumUp</button>
        <?php elseif (voucherCanUse($voucher, new DateTimeImmutable('now'))): ?>
          <input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(32)) ?>">
          <button name="voucher_action" value="use">Registra seduta non prenotata online</button>
        <?php endif; ?>
      </form>
      <?php if ($voucher['disabled_at'] === null && $voucher['status'] !== 'pending'): ?>
        <form method="post" onsubmit="return confirm('Un voucher manuale senza utilizzi sarà eliminato definitivamente. Negli altri casi sarà disattivato conservando lo storico. Continuare?');">
          <input type="hidden" name="csrf_token" value="<?= voucherEscape($_SESSION['admin_csrf']) ?>">
          <input type="hidden" name="voucher_id" value="<?= (int)$voucher['id'] ?>">
          <button name="voucher_action" value="remove">Elimina o disattiva voucher</button>
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</main>
<script src="gift-voucher.js"></script>
<script src="voucher-manual-admin.js"></script>
</body>
</html>
