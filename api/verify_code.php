<?php
/**
 * POST { code }  · segundo paso de la verificación
 * Necesita que login.php haya dejado pending_uid en la sesión.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$pending = (int) ($_SESSION['pending_uid'] ?? 0);
if ($pending <= 0) {
    fail('La verificación ha caducado. Vuelve a entrar con tu contraseña.', 440);
}

$code = preg_replace('/\D/', '', param('code'));
if (strlen($code) !== 6) {
    fail('El código son seis dígitos.', 400);
}

$st = db()->prepare(
    'SELECT id, code_hash, attempts FROM login_codes
      WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
      ORDER BY id DESC LIMIT 1'
);
$st->execute([$pending]);
$row = $st->fetch();

if (!$row) {
    fail('Ese código no es correcto o ya ha caducado.', 401);
}

if ((int) $row['attempts'] >= 5) {
    fail('Demasiados intentos con este código. Pide uno nuevo.', 429);
}

if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
    $up = db()->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?');
    $up->execute([$row['id']]);
    fail('Ese código no es correcto o ya ha caducado.', 401);
}

$up = db()->prepare('UPDATE login_codes SET used_at = NOW() WHERE id = ?');
$up->execute([$row['id']]);

login_user($pending);

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for($pending),
]);
