<?php
/**
 * GET · las cuentas enlazadas en este navegador (selector de cuentas
 * del avatar), con la activa marcada.
 *
 * 200 { ok:true, accounts:[{id, name, email, account_type, role,
 *                            avatar_url, active}] }
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$me = require_user();

$linked = $_SESSION['linked'] ?? [];
if (!in_array((int) $me['id'], $linked, true)) {
    $linked[] = (int) $me['id'];
}

$in = implode(',', array_fill(0, count($linked), '?'));
$st = db()->prepare(
    "SELECT id, name, email, account_type, role, avatar_url
       FROM users WHERE id IN ($in)"
);
$st->execute($linked);
$rows = [];
foreach ($st->fetchAll() as $r) {
    $rows[(int) $r['id']] = $r;
}

// En el orden guardado en la sesión (el más reciente al principio),
// no en el que devuelva la consulta.
$accounts = [];
foreach ($linked as $id) {
    if (!isset($rows[$id])) {
        continue;   // cuenta borrada entre medias, por ejemplo
    }
    $r = $rows[$id];
    $accounts[] = [
        'id'           => (int) $r['id'],
        'name'         => $r['name'],
        'email'        => $r['email'],
        'account_type' => $r['account_type'],
        'role'         => $r['role'],
        'avatar_url'   => $r['avatar_url'],
        'active'       => $id === (int) $me['id'],
    ];
}

json_out(['ok' => true, 'accounts' => $accounts]);
