<?php
/**
 * POST {}            · cierra todas las cuentas enlazadas en este navegador.
 * POST { user_id }   · cierra solo esa cuenta; si quedan otras enlazadas,
 *                       la sesión sigue abierta con otra de ellas activa.
 *
 * 200 { ok:true, active:<id o null> }
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$targetId = (int) (body()['user_id'] ?? 0);
$linked   = $_SESSION['linked'] ?? (empty($_SESSION['uid']) ? [] : [(int) $_SESSION['uid']]);

if ($targetId <= 0 || count($linked) <= 1) {
    logout_everywhere();
    json_out(['ok' => true, 'active' => null]);
}

forget_account($targetId);
$linked = array_values(array_diff($linked, [$targetId]));
$_SESSION['linked'] = $linked;

if (!$linked) {
    logout_everywhere();
    json_out(['ok' => true, 'active' => null]);
}

if (($_SESSION['uid'] ?? 0) === $targetId) {
    login_user($linked[0]);
    if (!empty($_COOKIE['playload_remember'])) {
        set_active_cookie($linked[0]);
    }
}

json_out(['ok' => true, 'active' => $_SESSION['uid'] ?? null]);


// ── Auxiliares ─────────────────────────────────────────────────────

/** Cierra todas las cuentas de este navegador: cookies, tokens y sesión. */
function logout_everywhere(): void
{
    $pairs = remember_pairs_from_cookie();
    if ($pairs) {
        $selectors = array_map(fn($p) => explode(':', $p, 2)[0], $pairs);
        $in = implode(',', array_fill(0, count($selectors), '?'));
        $st = db()->prepare("DELETE FROM remember_tokens WHERE selector IN ($in)");
        $st->execute($selectors);
    }
    clear_account_cookies();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
