<?php
/**
 * POST { code } · acceso del jugador, sin contraseña.
 * Abre una sesión distinta de la del cuerpo técnico: guarda player_id,
 * nunca uid, para que un código de jugador no dé acceso al panel.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$code = mb_strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', param('code')));

if (mb_strlen($code) < 6 || mb_strlen($code) > 12) {
    fail('Ese código no es válido.', 400);
}

if (($wait = login_throttle('player:' . $code)) > 0) {
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
      WHERE p.access_code = ? AND p.active = 1
      LIMIT 1'
);
$st->execute([$code]);
$player = $st->fetch();

if (!$player) {
    record_attempt('player:' . $code, false);
    fail('Ese código no existe o ya no está activo.', 401);
}

record_attempt('player:' . $code, true);

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
