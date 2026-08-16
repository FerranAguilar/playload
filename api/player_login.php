<?php
/**
 * POST { token } · acceso del jugador, con el enlace que le mandó el
 * club por correo. No hay código ni contraseña: el testigo del enlace
 * ES la credencial.
 *
 * Abre una sesión distinta de la del cuerpo técnico: guarda player_id,
 * nunca uid, para que ese testigo no dé acceso al panel del staff.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$token = param('token');

if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
    fail('Ese enlace no es válido.', 400);
}

// Se guarda solo el hash, así que también se compara por hash: quien
// lea la tabla no puede entrar con lo que vea ahí.
$hash = hash('sha256', $token);

if (($wait = login_throttle('player:' . $hash)) > 0) {
    json_out([
        'ok'          => false,
        'error'       => 'Demasiados intentos. Prueba de nuevo en unos minutos.',
        'retry_after' => $wait,
    ], 429);
}

$st = db()->prepare(
    'SELECT p.id, p.name, p.dorsal, p.position, t.name AS team, t.category
       FROM players p
       JOIN teams t ON t.id = p.team_id
      WHERE p.login_token_hash = ? AND p.active = 1
      LIMIT 1'
);
$st->execute([$hash]);
$player = $st->fetch();

if (!$player) {
    record_attempt('player:' . $hash, false);
    fail('Ese enlace no es válido o ha caducado.', 401);
}

record_attempt('player:' . $hash, true);

// «Registrado» es haber entrado alguna vez: entrar es lo que lo
// prueba, no que el enlace en concreto siga siendo el mismo. Solo se
// toca la primera vez.
db()->prepare(
    "UPDATE players SET invite_status = 'registrado', registered_at = NOW()
      WHERE id = ? AND invite_status != 'registrado'"
)->execute([(int) $player['id']]);

session_regenerate_id(true);
unset($_SESSION['uid'], $_SESSION['pending_uid']);
$_SESSION['player_id'] = (int) $player['id'];

json_out([
    'ok'     => true,
    'player' => [
        'name'     => $player['name'],
        'dorsal'   => $player['dorsal'] !== null ? (int) $player['dorsal'] : null,
        'position' => $player['position'],
        'team'     => $player['team'],
        'category' => $player['category'],
    ],
]);
