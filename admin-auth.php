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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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
        $providedToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $providedToken)) {
            http_response_code(400);
            exit('Richiesta di uscita non valida. Ricarica la pagina e riprova.');
        }

        session_unset();
        session_destroy();
        $cookieParams = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookieParams['path'], $cookieParams['domain'], $cookieParams['secure'], $cookieParams['httponly']);
        header('Location: index.html');
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

function renderAdminToolbar(): void
{
    $csrfToken = htmlspecialchars((string)$_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8');
    ?>
    <style>
      .admin-session-toolbar { position: sticky; top: 0; z-index: 1000; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; box-sizing: border-box; width: 100%; padding: 14px max(16px, calc((100% - 1100px) / 2)); background: #2a201d; color: #fff; box-shadow: 0 3px 12px rgba(0, 0, 0, 0.25); }
      .admin-session-toolbar .admin-session-label { margin: 0; color: #fff; font: 700 16px/1.4 Arial, sans-serif; }
      .admin-session-toolbar form { display: flex; margin: 0; }
      .admin-session-toolbar button { box-sizing: border-box; min-height: 48px; display: inline-flex; align-items: center; justify-content: center; padding: 12px 20px; border: 2px solid #fff; border-radius: 6px; background: #fff; color: #2a201d; font: 700 16px/1.4 Arial, sans-serif; text-align: center; cursor: pointer; }
      .admin-session-toolbar button:hover { background: #eee8df; }
      .admin-session-toolbar button:focus-visible { outline: 3px solid #ffd166; outline-offset: 3px; }
      @media (max-width: 480px) { .admin-session-toolbar form, .admin-session-toolbar button { width: 100%; } }
    </style>
    <nav class="admin-session-toolbar" aria-label="Sessione amministrativa">
      <span class="admin-session-label">Area admin protetta</span>
      <form method="post">
        <input type="hidden" name="admin_action" value="logout" />
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>" />
        <button type="submit">Esci e torna al sito</button>
      </form>
    </nav>
    <?php
}
