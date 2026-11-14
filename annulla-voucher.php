<?php
require_once __DIR__ . '/app-config.php';
require_once __DIR__ . '/booking-voucher.php';
require_once __DIR__ . '/google-calendar-client.php';
require_once __DIR__ . '/google-calendar-sync.php';
date_default_timezone_set('Europe/Rome');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    session_start();
}
$_SESSION['voucher_cancel_csrf'] ??= bin2hex(random_bytes(32));
$message = $_SESSION['voucher_cancel_notice'] ?? '';
unset($_SESSION['voucher_cancel_notice']);
$code = '';
$appointments = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['voucher_cancel_csrf'], $csrf)) {
            throw new InvalidArgumentException('Sessione scaduta. Ricarica la pagina e riprova.');
        }
        $code = bookingVoucherCode($_POST['voucher_code'] ?? '');
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['find', 'cancel'], true)) {
            throw new InvalidArgumentException('Operazione non valida.');
        }
        $pdo = dbConnection();
        if ($action === 'cancel') {
            $id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $id === null) throw new InvalidArgumentException('Seleziona un appuntamento valido.');
            bookingVoucherCancel($pdo, $id, $code);
            $message = 'Appuntamento annullato e seduta restituita al voucher. La disponibilità sul sito è stata aggiornata.';
            try {
                $sync = googleCalendarSyncAfterBooking($pdo);
                $synced = $sync['success'];
            } catch (Throwable $e) {
                error_log('Voucher cancellation calendar sync failed for appointment #' . $id . ': ' . $e->getMessage());
                $synced = false;
            }
            if (!$synced) {
                $message .= ' La rimozione da Google Calendar è in attesa; contatta lo studio se necessario.';
            }
            if (!sendVoucherCancellationNotification($id, $code)) {
                $message .= ' Non è stato possibile avvisare lo studio via email: contattalo per confermare l’annullamento.';
            }
            $_SESSION['voucher_cancel_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['voucher_cancel_notice'] = $message;
            header('Location: annulla-voucher.php', true, 303);
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT a.id, a.data_appuntamento, a.ora_inizio, s.nome AS studio, sv.nome AS servizio
             FROM gift_vouchers v
             INNER JOIN gift_voucher_bookings vb ON vb.voucher_id = v.id AND vb.restored_at IS NULL
             INNER JOIN appuntamenti a ON a.id = vb.appointment_id
             INNER JOIN studi s ON s.id = a.id_studio
             INNER JOIN servizi sv ON sv.id = a.id_servizio
             WHERE v.voucher_code = :code AND a.metodo_pagamento = 'voucher'
               AND a.stato IN ('in_attesa', 'confermato')
             ORDER BY a.data_appuntamento, a.ora_inizio"
        );
        $stmt->execute([':code' => $code]);
        $appointments = $stmt->fetchAll();
        if (!$appointments) $message = 'Nessun appuntamento attivo trovato per questo codice.';
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        $message = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Voucher cancellation error: ' . $e->getMessage());
        http_response_code(503);
        $message = 'Operazione non completata. Contatta lo studio prima di riprovare.';
    }
}
function cancelEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Annulla appuntamento con voucher | Thai Tik Massage</title>
  <style>
    body{margin:0;background:#fbf9f5;color:#2a201d;font:16px/1.6 Arial,sans-serif}
    main{max-width:720px;margin:auto;padding:28px 20px}article{padding:18px;margin:16px 0;background:#fff;border:1px solid #ded5cb}
    input,button{padding:12px;font:inherit;max-width:100%;box-sizing:border-box}button{cursor:pointer}label{display:block;font-weight:bold}
    .notice{color:#8b4438;overflow-wrap:anywhere} .disclaimer{padding:14px;background:#eee8df}
  </style>
</head>
<body><main>
  <a href="index.html">Torna al sito</a>
  <h1>Annulla appuntamento con voucher</h1>
  <p class="disclaimer">Puoi annullare online fino a <strong>3 ore prima</strong> dell’orario dell’appuntamento. Dopo questo termine contatta lo studio. La seduta viene restituita al voucher; la sua scadenza non cambia.</p>
  <?php if ($message !== ''): ?><p class="notice" role="status"><?= cancelEscape($message) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= cancelEscape($_SESSION['voucher_cancel_csrf']) ?>">
    <input type="hidden" name="action" value="find">
    <label for="voucher_code">Codice voucher</label>
    <input id="voucher_code" name="voucher_code" autocomplete="off" maxlength="24" required value="<?= cancelEscape($code) ?>" placeholder="TTM-...">
    <button type="submit">Cerca appuntamenti</button>
  </form>
  <?php foreach ($appointments as $appointment):
      $start = new DateTimeImmutable($appointment['data_appuntamento'] . ' ' . $appointment['ora_inizio']);
      $allowed = bookingVoucherCancellationAllowed($start, new DateTimeImmutable('now')); ?>
    <article>
      <p>Appuntamento #<?= (int)$appointment['id'] ?> · <?= cancelEscape($start->format('d/m/Y H:i')) ?><br>
      <?= cancelEscape($appointment['studio']) ?> · <?= cancelEscape($appointment['servizio']) ?></p>
      <?php if ($allowed): ?>
        <form method="post" onsubmit="return confirm('Confermi l’annullamento e la restituzione della seduta?');">
          <input type="hidden" name="csrf_token" value="<?= cancelEscape($_SESSION['voucher_cancel_csrf']) ?>">
          <input type="hidden" name="voucher_code" value="<?= cancelEscape($code) ?>">
          <input type="hidden" name="appointment_id" value="<?= (int)$appointment['id'] ?>">
          <button name="action" value="cancel">Annulla questo appuntamento</button>
        </form>
      <?php else: ?><p>Termine per l’annullamento online scaduto. Contatta lo studio.</p><?php endif; ?>
    </article>
  <?php endforeach; ?>
</main></body></html>
