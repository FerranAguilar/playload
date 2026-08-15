<?php
/**
 * POST { email }
 * Responde siempre 200, exista la cuenta o no: la pantalla dice «si ese
 * correo tiene cuenta…», y la API no debe contradecirla revelando qué
 * correos están registrados.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$email = mb_strtolower(param('email'));

if (valid_email($email)) {
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user) {
        // Un enlace vivo por cuenta: los anteriores se dan por usados.
        $old = db()->prepare(
            'UPDATE password_resets SET used_at = NOW()
              WHERE user_id = ? AND used_at IS NULL'
        );
        $old->execute([$user['id']]);

        $token = bin2hex(random_bytes(32));

        $ins = db()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
        );
        $ins->execute([$user['id'], hash('sha256', $token)]);

        $link = rtrim($CONFIG['app_url'], '/') . '/acceso.html?v=reset&token=' . $token;

        send_mail(
            $email,
            'Crea una contraseña nueva en PlayLoad',
            "Has pedido recuperar el acceso a PlayLoad.\n\n" .
            "Abre este enlace para crear una contraseña nueva:\n{$link}\n\n" .
            "Caduca en 30 minutos y solo sirve una vez.\n" .
            "Si no has sido tú, ignora este correo: tu contraseña actual sigue valiendo.\n"
        );
    }
}

json_out(['ok' => true]);
