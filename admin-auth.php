<?php
require_once __DIR__ . '/app-config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

$adminPassword = (string)(appConfig()['ADMIN_PASSWORD'] ?? '');
if ($adminPassword === '') {
    http_response_code(503);
  exit('Area admin non configurata: imposta ADMIN_PASSWORD in private/config.local.php.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $adminAction = $_POST['admin_action'] ?? '';

    if ($adminAction === 'login') {
        $providedPassword = (string)($_POST['admin_password'] ?? '');
        if (hash_equals($adminPassword, $providedPassword)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            header('Location: ' . basename($_SERVER['SCRIPT_NAME']));
            exit;
        }
        $loginError = 'Password non corretta.';
    } elseif ($adminAction === 'logout') {
        session_unset();
        session_destroy();
        header('Location: ' . basename($_SERVER['SCRIPT_NAME']));
        exit;
    }
}

if (empty($_SESSION['admin_authenticated'])) {
    http_response_code(401);
    $loginError = $loginError ?? '';
    ?>
    <!doctype html>
    <html lang="it">
      <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Accesso area admin</title>
        <style>
          body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f7f2ed; color: #2a201d; font: 16px/1.5 Arial, sans-serif; }
          main { width: min(100% - 36px, 420px); padding: 28px; border: 1px solid #ded5cb; background: #fff; }
          h1 { margin: 0 0 18px; font-size: 24px; }
          label { display: block; margin-bottom: 7px; font-weight: 700; }
          input, button { width: 100%; min-height: 46px; padding: 10px 12px; font: inherit; }
          input { margin-bottom: 14px; border: 1px solid #b8aea5; }
          button { border: 0; background: #2a201d; color: #fff; font-weight: 700; cursor: pointer; }
          .error { margin: 0 0 14px; color: #8b4438; }
        </style>
      </head>
      <body>
        <main>
          <h1>Accesso area admin</h1>
          <?php if ($loginError !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
          <form method="post">
            <input type="hidden" name="admin_action" value="login" />
            <label for="admin-password">Password amministratore</label>
            <input id="admin-password" name="admin_password" type="password" autocomplete="current-password" required />
            <button type="submit">Accedi</button>
          </form>
        </main>
      </body>
    </html>
    <?php
    exit;
}

$_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
