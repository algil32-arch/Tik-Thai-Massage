<?php

$root = dirname(__DIR__);
$temporaryDirectory = sys_get_temp_dir() . '/admin-auth-test-' . bin2hex(random_bytes(8));
mkdir($temporaryDirectory, 0700);
$process = null;
$checks = 0;
$pages = [
    'area-admin.php',
    'booking-admin.php',
    'booking-admin-advanced.php',
    'vouchers-admin.php',
    'fatture-admin.php',
    'fattura-manuale.php',
    'fattura-da-pagamento.php',
    'fatture-export-lotto.php',
];

function checkAdminAuth(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function adminAuthRequest(string $url, string $cookie = '', ?array $post = null): array
{
    $headers = $cookie === '' ? [] : ['Cookie: ' . $cookie];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed: ' . $url);
    }
    $responseHeaders = http_get_last_response_headers();
    return ['body' => $body, 'headers' => $responseHeaders];
}

try {
    foreach (array_merge($pages, ['admin-auth.php']) as $file) {
        copy($root . '/' . $file, $temporaryDirectory . '/' . $file);
    }
    file_put_contents($temporaryDirectory . '/app-config.php',
        "<?php\nfunction appConfig(): array { return ['ADMIN_PASSWORD' => 'test-password-only']; }\n");

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException($errorMessage, $errorCode);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $temporaryDirectory, '-S', $address, '-t', $temporaryDirectory],
        [0 => ['pipe', 'r'], 1 => ['file', $temporaryDirectory . '/server.log', 'a'], 2 => ['file', $temporaryDirectory . '/server.log', 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the PHP test server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    checkAdminAuth($ready, 'PHP test server did not become ready.');
    $baseUrl = 'http://' . $address . '/';

    foreach ($pages as $page) {
        $response = adminAuthRequest($baseUrl . $page);
        checkAdminAuth(str_contains($response['headers'][0], '401'), $page . ' must reject unauthenticated access.');
        checkAdminAuth(str_contains($response['body'], 'name="admin_password"'), $page . ' must show the login form.');
    }

    $response = adminAuthRequest($baseUrl . 'area-admin.php', '', [
        'admin_action' => 'login', 'admin_password' => 'wrong-password',
    ]);
    checkAdminAuth(str_contains($response['headers'][0], '401'), 'Wrong passwords must be rejected.');
    checkAdminAuth(str_contains($response['body'], 'Password non corretta.'), 'Wrong passwords must show an error.');

    $response = adminAuthRequest($baseUrl . 'area-admin.php', '', [
        'admin_action' => 'login', 'admin_password' => 'test-password-only',
    ]);
    checkAdminAuth(str_contains($response['headers'][0], '302'), 'Successful login must redirect.');
    $cookie = '';
    foreach ($response['headers'] as $header) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $matches)) {
            $cookie = $matches[1];
        }
    }
    checkAdminAuth($cookie !== '', 'Successful login must set a session cookie.');
    $response = adminAuthRequest($baseUrl . 'area-admin.php', $cookie);
    checkAdminAuth(str_contains($response['headers'][0], '200'), 'Authenticated access must succeed.');
    checkAdminAuth(str_contains($response['body'], 'Esci e torna al sito'), 'Toolbar must offer return with logout.');
    checkAdminAuth(str_contains($response['body'], 'Area admin protetta'), 'Toolbar must clearly identify the protected area.');
    checkAdminAuth(str_contains($response['body'], 'position: sticky; top: 0;'), 'Toolbar must remain visible while scrolling.');
    checkAdminAuth(str_contains($response['body'], 'min-height: 48px;'), 'Logout button must have a large touch target.');
    checkAdminAuth(!str_contains($response['body'], 'class="admin-public-link"'), 'Toolbar must not bypass logout.');
    checkAdminAuth(preg_match('/name="csrf_token" value="([^"]+)"/', $response['body'], $matches) === 1, 'Logout must have a CSRF token.');
    $token = $matches[1];

    $response = adminAuthRequest($baseUrl . 'area-admin.php', $cookie, [
        'admin_action' => 'logout', 'csrf_token' => 'invalid-token',
    ]);
    checkAdminAuth(str_contains($response['headers'][0], '400'), 'Logout with an invalid CSRF token must fail.');
    $response = adminAuthRequest($baseUrl . 'area-admin.php', $cookie);
    checkAdminAuth(str_contains($response['headers'][0], '200'), 'Invalid logout must preserve the session.');
    $response = adminAuthRequest($baseUrl . 'area-admin.php', $cookie, [
        'admin_action' => 'logout', 'csrf_token' => $token,
    ]);
    checkAdminAuth(str_contains($response['headers'][0], '302'), 'Returning to the public site must redirect.');
    checkAdminAuth(in_array('Location: index.html', $response['headers'], true), 'Logout must redirect to the public home.');
    foreach ($pages as $page) {
        $response = adminAuthRequest($baseUrl . $page, $cookie);
        checkAdminAuth(str_contains($response['headers'][0], '401'), $page . ' must reject the old session after logout.');
        checkAdminAuth(str_contains($response['body'], 'name="admin_password"'), $page . ' must request a password again.');
    }

    echo "Admin authentication: {$checks} checks passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (new DirectoryIterator($temporaryDirectory) as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
        }
    }
    rmdir($temporaryDirectory);
}
