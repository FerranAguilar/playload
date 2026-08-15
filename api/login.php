<?php
/**
 * POST { email, password, keep }
 *
 * 200 { ok:true, user, spaces }            → dentro
 * 200 { ok:true, needs_code:true, email }  → falta el segundo paso
 * 401 { ok:false, error }                  → credenciales incorrectas
 * 429 { ok:false, error, retry_after }     → demasiados intentos
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$email = mb_strtolower(param('email'));
$pass  = param('password');
$keep  = !empty(body()['keep']);

if (!valid_email($email) || $pass === '') {
    fail('Correo o contraseña incorrectos.', 401);
}

if (($wait = login_throttle($email)) > 0) {
    json_out([
        'ok'          => false,
        'error'       => 'Demasiados intentos fallidos. Prueba de nuevo en unos minutos.',
        'retry_after' => $wait,
    ], 429);
}

$st = db()->prepare('SELECT id, password_hash, two_factor FROM users WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch();

// Se compara siempre contra un hash, exista la cuenta o no, para que el
// tiempo de respuesta no delate qué correos están registrados.
$hash = $user['password_hash'] ?? '$2y$12$usuarioinexistenteusuarioinexistenteusuarioinexistente0';

if (!password_verify($pass, $hash) || empty($user['password_hash'])) {
    record_attempt($email, false);
    fail('Correo o contraseña incorrectos.', 401);
}

record_attempt($email, true);
$userId = (int) $user['id'];

// Rehash si el coste por defecto de PHP ha subido desde el alta.
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $up->execute([password_hash($pass, PASSWORD_DEFAULT), $userId]);
}

// ── Verificación en dos pasos ──────────────────────────────────────
if (!empty($user['two_factor'])) {
    $_SESSION['pending_uid']  = $userId;
    $_SESSION['pending_keep'] = $keep;
    send_login_code($userId, $email);
    json_out(['ok' => true, 'needs_code' => true, 'email' => $email]);
}

login_user($userId);
if ($keep) {
    issue_remember_cookie($userId);
}

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for($userId),
]);


// ── Auxiliares ─────────────────────────────────────────────────────

function send_login_code(int $userId, string $email): void
{
    global $CONFIG;

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $st = db()->prepare(
        'INSERT INTO login_codes (user_id, code_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $st->execute([$userId, hash('sha256', $code)]);

    send_mail(
        $email,
        'Tu código de acceso a PlayLoad',
        "Tu código es: {$code}\n\n" .
        "Caduca en 10 minutos y solo sirve una vez.\n" .
        "Si no has intentado entrar, cambia tu contraseña en {$CONFIG['app_url']}/acceso.html.\n"
    );
}

function issue_remember_cookie(int $userId): void
{
    $selector  = bin2hex(random_bytes(12));   // 24 caracteres
    $validator = bin2hex(random_bytes(32));

    $st = db()->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $st->execute([$userId, $selector, hash('sha256', $validator)]);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie('playload_remember', $selector . ':' . $validator, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
}
