<?php
/** POST · cierra la sesión y borra la cookie de «mantener la sesión». */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

if (!empty($_COOKIE['playload_remember'])) {
    $selector = explode(':', (string) $_COOKIE['playload_remember'], 2)[0];
    $st = db()->prepare('DELETE FROM remember_tokens WHERE selector = ?');
    $st->execute([$selector]);

    setcookie('playload_remember', '', [
        'expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => 'Lax',
    ]);
}
session_destroy();

json_out(['ok' => true]);
