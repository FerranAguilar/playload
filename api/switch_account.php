<?php
/**
 * POST { user_id }
 * Cambia la cuenta activa a otra ya enlazada en este navegador, sin
 * pedir contraseña. No sirve para entrar con una cuenta nueva: si no
 * está en la lista, hay que iniciar sesión con ella primero.
 *
 * 200 { ok:true, user, spaces }
 * 403 { ok:false, error }  → esa cuenta no está enlazada aquí
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

require_user();

$targetId = (int) (body()['user_id'] ?? 0);
$linked   = $_SESSION['linked'] ?? [];

if ($targetId <= 0 || !in_array($targetId, $linked, true)) {
    fail('Esa cuenta no está disponible en este navegador. Inicia sesión de nuevo.', 403);
}

assert_active($targetId);

// La cuenta usada pasa a ir primero: así el selector enseña siempre la
// más reciente arriba.
$_SESSION['linked'] = array_values(array_unique([
    $targetId,
    ...array_diff($linked, [$targetId]),
]));

login_user($targetId);

if (!empty($_COOKIE['playload_remember'])) {
    set_active_cookie($targetId);
}

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for($targetId),
]);
