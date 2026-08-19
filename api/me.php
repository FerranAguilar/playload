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


/**
 * Reanuda tantas cuentas como pares válidos traiga la cookie "recordar"
 * —una por cuenta enlazada en este navegador—, no solo la primera.
 * Las cuentas caducadas, manipuladas o suspendidas se sueltan sin
 * cortar la reanudación del resto.
 */
function resume_from_cookie(): ?array
{
    $pairs = remember_pairs_from_cookie();
    if (!$pairs) {
        return null;
    }

    $valid = [];   // user_id => "selector:nuevoValidador"
    foreach ($pairs as $pair) {
        $parts = explode(':', $pair, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$selector, $validator] = $parts;

        $st = db()->prepare(
            'SELECT id, user_id, validator_hash FROM remember_tokens
              WHERE selector = ? AND expires_at > NOW() LIMIT 1'
        );
        $st->execute([$selector]);
        $row = $st->fetch();

        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
            continue;
        }
        if (!account_is_active((int) $row['user_id'])) {
            continue;
        }

        // Validador de un solo uso: se rota en cada reanudación.
        $new = bin2hex(random_bytes(32));
        $up  = db()->prepare('UPDATE remember_tokens SET validator_hash = ? WHERE id = ?');
        $up->execute([hash('sha256', $new), $row['id']]);

        $valid[(int) $row['user_id']] = $selector . ':' . $new;
    }

    if (!$valid) {
        clear_account_cookies();
        return null;
    }

    set_remember_cookie(array_values($valid));

    $activeId = (int) ($_COOKIE['playload_active'] ?? 0);
    if (!isset($valid[$activeId])) {
        $activeId = array_key_first($valid);
    }

    $_SESSION['linked'] = array_keys($valid);
    login_user($activeId);
    return current_user();
}

/** Como assert_active(), pero sin cortar la petición: solo dice sí/no. */
function account_is_active(int $userId): bool
{
    $st = db()->prepare('SELECT plan FROM users WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row !== false && $row['plan'] !== 'suspendido';
}
