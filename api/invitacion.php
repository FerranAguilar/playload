<?php
/**
 * GET ?token=…
 * Qué hay detrás de un enlace de invitación. Lo consume registro.html
 * para rellenar el correo y dejarlo fijo.
 *
 * No requiere sesión: quien abre el enlace todavía no tiene cuenta.
 * Solo devuelve el correo al que se mandó ese enlace, y para saberlo hay
 * que tener el enlace, así que no revela nada que quien pregunta no
 * supiera ya.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$token = (string) ($_GET['token'] ?? '');

try {
    $inv = invitation_by_token($token);
} catch (Throwable $e) {
    // Sin la migración 07 no existen las columnas del enlace.
    fail('Los enlaces de invitación todavía no están activos.', 503);
}

if (!$inv) {
    fail('Este enlace ya no vale. Puede que haya caducado, que ya lo hayas '
       . 'usado o que se haya enviado uno nuevo. Pídenos otro.', 410);
}

json_out([
    'ok'    => true,
    'email' => $inv['email'],
    'plan'  => $inv['plan'],
    'nota'  => $inv['note'],
]);
