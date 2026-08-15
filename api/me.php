<?php
/**
 * GET · quién ha iniciado sesión.
 * Si la sesión de PHP ha caducado pero queda la cookie de «mantener la
 * sesión», se reanuda aquí rotando el validador.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$user = current_user();

if (!$user && !empty($_COOKIE['playload_remember'])) {
    $user = resume_from_cookie();
}

if (!$user) {
    json_out(['ok' => false, 'user' => null], 401);
}

json_out([
    'ok'     => true,
    'user'   => $user,
    'spaces' => spaces_for((int) $user['id']),
]);


function resume_from_cookie(): ?array
{
    $parts = explode(':', (string) $_COOKIE['playload_remember'], 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $validator] = $parts;

    $st = db()->prepare(
        'SELECT id, user_id, validator_hash FROM remember_tokens
          WHERE selector = ? AND expires_at > NOW() LIMIT 1'
    );
    $st->execute([$selector]);
    $row = $st->fetch();

    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        return null;
    }

    // Validador de un solo uso: se rota en cada reanudación.
    $new = bin2hex(random_bytes(32));
    $up  = db()->prepare('UPDATE remember_tokens SET validator_hash = ? WHERE id = ?');
    $up->execute([hash('sha256', $new), $row['id']]);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie('playload_remember', $selector . ':' . $new, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);

    login_user((int) $row['user_id']);
    return current_user();
}
