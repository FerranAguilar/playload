<?php
/**
 * POST { token, password }
 * Cierra el círculo de la recuperación: cambia la contraseña, marca el
 * enlace como usado y tira todas las sesiones recordadas de esa cuenta.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$token = preg_replace('/[^a-f0-9]/i', '', param('token'));
$pass  = param('password');

if (strlen($token) !== 64) {
    fail('El enlace no es válido.', 400);
}
if (mb_strlen($pass) < 8) {
    fail('La contraseña necesita ocho caracteres como mínimo.');
}

$st = db()->prepare(
    'SELECT id, user_id FROM password_resets
      WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
      LIMIT 1'
);
$st->execute([hash('sha256', $token)]);
$row = $st->fetch();

if (!$row) {
    fail('Ese enlace ha caducado o ya se ha usado. Pide uno nuevo.', 410);
}

$pdo = db();
try {
    $pdo->beginTransaction();

    $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $up->execute([password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);

    $used = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?');
    $used->execute([$row['id']]);

    $rem = $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
    $rem->execute([$row['user_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('No se ha podido cambiar la contraseña.', 500, $CONFIG['debug'] ? $e->getMessage() : null);
}

login_user((int) $row['user_id']);

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for((int) $row['user_id']),
]);
