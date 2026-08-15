<?php
/**
 * POST { credential }
 *
 * `credential` es el token de identidad (JWT) que devuelve el botón de
 * Google Identity Services en el navegador. Se verifica siempre en el
 * servidor: un token no comprobado es solo texto que envía el cliente.
 *
 * Si el correo ya existe, se enlaza la cuenta de Google a esa cuenta.
 * Si no existe, se crea con el tipo que llegue en `account_type`
 * (por defecto 'pro').
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$clientId = trim((string) ($CONFIG['google_client_id'] ?? ''));
if ($clientId === '') {
    fail('El acceso con Google no está configurado todavía.', 501);
}

$credential = param('credential');
if ($credential === '') {
    fail('Falta el token de Google.', 400);
}

// ── Verificación del token ─────────────────────────────────────────
// tokeninfo es el camino simple y sin dependencias. Para mucho tráfico,
// lo correcto es validar la firma contra las claves de
// https://www.googleapis.com/oauth2/v3/certs y cachearlas.
$url  = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$body = http_get($url);

if ($body === null) {
    fail('No se ha podido verificar el token con Google. El servidor no llega a '
       . 'oauth2.googleapis.com: revisa que cURL esté disponible.', 502);
}

$tok = json_decode($body, true);
if (!is_array($tok) || empty($tok['sub'])) {
    fail('El token de Google no es válido.', 401);
}

$issOk = in_array($tok['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
$audOk = hash_equals($clientId, (string) ($tok['aud'] ?? ''));
$expOk = ((int) ($tok['exp'] ?? 0)) > time();

if (!$issOk || !$audOk || !$expOk) {
    fail('El token de Google no es válido.', 401);
}

$sub      = (string) $tok['sub'];
$email    = mb_strtolower((string) ($tok['email'] ?? ''));
$verified = ($tok['email_verified'] ?? 'false') === true
         || ($tok['email_verified'] ?? '') === 'true';
$name     = (string) ($tok['name'] ?? '');
$picture  = (string) ($tok['picture'] ?? '');

if (!valid_email($email) || !$verified) {
    fail('Google no ha confirmado ese correo.', 401);
}

// ── Buscar, enlazar o crear ────────────────────────────────────────
$pdo = db();

$st = $pdo->prepare('SELECT id FROM users WHERE google_sub = ?');
$st->execute([$sub]);
$user = $st->fetch();

if (!$user) {
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user) {
        // Cuenta existente que hasta ahora entraba con contraseña.
        $up = $pdo->prepare(
            'UPDATE users SET google_sub = ?, email_verified = 1,
                    avatar_url = COALESCE(NULLIF(?, \'\'), avatar_url)
              WHERE id = ?'
        );
        $up->execute([$sub, $picture, $user['id']]);
    } else {
        // Cuenta nueva: durante las pruebas cerradas hace falta invitación.
        // Quien ya tenga cuenta sigue entrando con normalidad.
        $invitacion = check_signup_allowed($email);

        $type = body()['account_type'] ?? 'pro';
        $type = in_array($type, ['pro', 'club'], true) ? $type : 'pro';

        $ins = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, account_type, google_sub,
                                avatar_url, email_verified, plan)
             VALUES (?, NULL, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([$email, $name, $type, $sub, $picture, $invitacion['plan'] ?? 'tester']);
        $newId = (int) $pdo->lastInsertId();
        mark_invitation_used($email);

        $mem = $pdo->prepare(
            "INSERT INTO memberships (user_id, scope_type, scope_id, role)
             VALUES (?, 'personal', NULL, 'Propietario')"
        );
        $mem->execute([$newId]);

        $user = ['id' => $newId];
    }
}

$userId = (int) $user['id'];
assert_active($userId);
record_attempt($email, true);
login_user($userId);

json_out([
    'ok'     => true,
    'user'   => current_user(),
    'spaces' => spaces_for($userId),
]);
