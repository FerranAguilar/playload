<?php
/**
 * POST { email, password, account_type, name, role, club, city }
 * Da de alta la cuenta que crea registro.html y le monta su espacio:
 *  · 'pro'  → membresía personal
 *  · 'club' → club nuevo + membresía de club
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$email = mb_strtolower(param('email'));
$pass  = param('password');
$type  = param('account_type', 'pro');
$name  = param('name');
$role  = param('role');
$club  = param('club');
$city  = param('city');

if (!valid_email($email)) {
    fail('Ese correo no parece válido.');
}
if (mb_strlen($pass) < 8) {
    fail('La contraseña necesita ocho caracteres como mínimo.');
}
if (!in_array($type, ['pro', 'club'], true)) {
    fail('Tipo de cuenta desconocido.');
}
if ($type === 'club' && $club === '') {
    fail('Falta el nombre del club.');
}
if ($type === 'pro' && $name === '') {
    fail('Falta tu nombre.');
}

$pdo = db();

// Pruebas cerradas: sin invitación no se crea cuenta.
$invitacion = invitation_for($email);
if (!$invitacion) {
    fail_not_invited();
}

$st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) {
    fail('Ya hay una cuenta con ese correo. Prueba a iniciar sesión.', 409);
}

try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        'INSERT INTO users (email, password_hash, name, account_type, role, plan)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $email,
        password_hash($pass, PASSWORD_DEFAULT),
        $name !== '' ? $name : $club,
        $type,
        $role,
        $invitacion['plan'] ?: 'tester',
    ]);
    $userId = (int) $pdo->lastInsertId();
    mark_invitation_used($email);

    if ($type === 'club') {
        $c = $pdo->prepare('INSERT INTO clubs (name, city, owner_user_id) VALUES (?, ?, ?)');
        $c->execute([$club, $city, $userId]);
        $clubId = (int) $pdo->lastInsertId();

        $m = $pdo->prepare(
            "INSERT INTO memberships (user_id, scope_type, scope_id, role)
             VALUES (?, 'club', ?, 'Propietario')"
        );
        $m->execute([$userId, $clubId]);
    } else {
        $m = $pdo->prepare(
            "INSERT INTO memberships (user_id, scope_type, scope_id, role)
             VALUES (?, 'personal', NULL, ?)"
        );
        $m->execute([$userId, $role !== '' ? $role : 'Propietario']);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('No se ha podido crear la cuenta.', 500, $CONFIG['debug'] ? $e->getMessage() : null);
}

login_user($userId);

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for($userId),
], 201);
