<?php
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/voucher-store.php';
date_default_timezone_set('Europe/Rome');
header('Cache-Control: no-store');

function voucherEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
try {
    $pdo = dbConnection();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['voucher_action'])) {
        if (!is_string($_POST['csrf_token'] ?? null)
            || !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])) {
            throw new InvalidArgumentException('Sessione scaduta. Ricarica la pagina e riprova.');
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
        if ($_POST['voucher_action'] === 'verify') {
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
        "SELECT * FROM gift_vouchers WHERE (:all_rows = 1 OR voucher_code = :code)
         ORDER BY id DESC LIMIT 100"
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
    $message = 'Registro non disponibile. Verifica il database e importa backend-aruba-vouchers.sql e backend-aruba-voucher-bookings.sql. Nessuna operazione completata.';
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
    input,button{padding:10px;font:inherit;max-width:100%;box-sizing:border-box}button{cursor:pointer}
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
  <form method="get"><label for="code">Codice voucher</label> <input id="code" name="code" maxlength="40" value="<?= voucherEscape($_GET['code'] ?? '') ?>"><button>Cerca</button></form>
  <?php foreach ($vouchers as $voucher): ?>
    <article>
      <h2><?= voucherEscape($voucher['voucher_code'] ?? ('Acquisto #' . $voucher['id'])) ?></h2>
      <p><?= voucherEscape($voucher['recipient_name']) ?> · <?= voucherEscape($voucher['service_name']) ?> · <?= (int)$voucher['duration_minutes'] ?> min</p>
      <p>Stato pagamento: <?= voucherEscape($voucher['status']) ?> · Importo: <?= voucherEscape($voucher['amount']) ?> EUR</p>
      <p>Sedute regalate: <?= (int)$voucher['total_sessions'] ?> · Residue: <?= (int)$voucher['remaining_sessions'] ?> · Scadenza: <?= voucherEscape($voucher['valid_until'] ?? 'non ancora emesso') ?></p>
      <?php if (!empty($bookingsByVoucher[(int)$voucher['id']])): ?>
        <details><summary>Prenotazioni collegate al voucher</summary><ul>
          <?php foreach ($bookingsByVoucher[(int)$voucher['id']] as $booking): ?>
            <li>Appuntamento #<?= (int)$booking['appointment_id'] ?> · <?= voucherEscape($booking['data_appuntamento']) ?> <?= voucherEscape($booking['ora_inizio']) ?> · <?= voucherEscape($booking['stato']) ?> · <?= $booking['restored_at'] !== null ? 'seduta restituita' : 'seduta scalata' ?></li>
          <?php endforeach; ?>
        </ul><a href="booking-admin-advanced.php">Gestisci gli appuntamenti</a></details>
      <?php endif; ?>
      <?php if ($voucher['status'] === 'paid'): ?>
        <a href="voucher.php?action=pdf&amp;token=<?= voucherEscape($voucher['token']) ?>" rel="noreferrer">Scarica voucher PDF</a>
        <details><summary>Dati acquirente per fatturazione manuale</summary><dl>
        <?php foreach (json_decode($voucher['customer_data'], true, 512, JSON_THROW_ON_ERROR) as $key => $value): ?>
          <dt><?= voucherEscape($key) ?></dt><dd><?= voucherEscape($value) ?></dd>
        <?php endforeach; ?>
        </dl></details>
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
    </article>
  <?php endforeach; ?>
</main></body>
</html>
