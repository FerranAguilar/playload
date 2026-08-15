<?php
/**
 * PlayLoad · arranque común de la API.
 * Conexión a la base de datos, sesión, respuestas JSON y utilidades.
 * Todos los endpoints empiezan por: require __DIR__.'/bootstrap.php';
 */

declare(strict_types=1);

// ── Configuración ──────────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'Falta api/config.php. Copia api/config.example.php y rellénalo.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$CONFIG = require $configFile;

error_reporting(E_ALL);
ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');

// ── Sesión ─────────────────────────────────────────────────────────
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
]);
session_name('playload_sid');
session_start();

// ── Base de datos ──────────────────────────────────────────────────
function db(): PDO
{
    global $CONFIG;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d   = $CONFIG['db'];
    $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";
    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fail('No se puede conectar con la base de datos.', 500,
             $CONFIG['debug'] ? $e->getMessage() : null);
    }
    return $pdo;
}

// ── Respuestas ─────────────────────────────────────────────────────
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400, ?string $detail = null): void
{
    $out = ['ok' => false, 'error' => $message];
    if ($detail !== null) {
        $out['detail'] = $detail;
    }
    json_out($out, $code);
}

/** Cuerpo de la petición como array. Acepta JSON y formulario. */
function body(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $raw  = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    $data = is_array($json) ? $json : $_POST;
    return $data;
}

function param(string $key, string $default = ''): string
{
    $v = body()[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        fail('Método no permitido.', 405);
    }
}

// ── Utilidades ─────────────────────────────────────────────────────
function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = @inet_pton($ip);
    return $packed === false ? null : $packed;
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
}

/**
 * Freno a la fuerza bruta: cuenta los fallos recientes de este correo y
 * de esta IP. Devuelve los segundos que faltan para volver a intentarlo,
 * o 0 si puede seguir.
 */
function login_throttle(string $email): int
{
    $limit  = 8;      // fallos permitidos
    $window = 900;    // en 15 minutos

    // $window es una constante nuestra, no entrada del usuario: se
    // interpola porque no todos los MySQL admiten un marcador dentro de
    // INTERVAL. El resto sigue con consulta preparada.
    $st = db()->prepare(
        'SELECT COUNT(*) AS n FROM login_attempts
          WHERE ok = 0 AND at > (NOW() - INTERVAL ' . (int) $window . ' SECOND)
            AND (email = ? OR (ip IS NOT NULL AND ip = ?))'
    );
    $st->execute([$email, client_ip()]);
    $n = (int) ($st->fetch()['n'] ?? 0);

    return $n >= $limit ? $window : 0;
}

function record_attempt(string $email, bool $ok): void
{
    $st = db()->prepare('INSERT INTO login_attempts (email, ip, ok) VALUES (?, ?, ?)');
    $st->execute([$email, client_ip(), $ok ? 1 : 0]);
}

// ── Sesión de la persona que entra ─────────────────────────────────
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid']        = $userId;
    $_SESSION['login_at']   = time();
    unset($_SESSION['pending_uid']);

    $st = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $st->execute([$userId]);
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare(
        'SELECT id, email, name, account_type, role, avatar_url, two_factor,
                is_admin, plan, plan_until, locale, theme, created_at
           FROM users WHERE id = ?'
    );
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        fail('No has iniciado sesión.', 401);
    }
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if (empty($u['is_admin'])) {
        fail('Esta zona es solo para administradores.', 403);
    }
    return $u;
}

/**
 * Límites de la licencia de una cuenta, leídos de la tabla `plans`.
 * null = sin límite. Durante las pruebas cerradas, 'tester' no tiene
 * techo: la idea es que los testers puedan romper cosas.
 */
function plan_limits(string $plan): array
{
    if ($plan === 'tester' || $plan === '') {
        return ['plan' => 'tester', 'name' => 'Tester', 'teams' => null, 'players' => null, 'staff' => null];
    }

    $st = db()->prepare('SELECT id, name, teams, players, staff FROM plans WHERE id = ?');
    $st->execute([$plan]);
    $row = $st->fetch();

    if (!$row) {
        // Plan desconocido: se trata como tester para no bloquear a nadie
        // por un dato mal escrito en la base.
        return ['plan' => $plan, 'name' => $plan, 'teams' => null, 'players' => null, 'staff' => null];
    }

    $num = fn($v) => ($v === '∞' || $v === '' || $v === null) ? null : (int) $v;

    return [
        'plan'    => $row['id'],
        'name'    => $row['name'],
        'teams'   => $num($row['teams']),
        'players' => $num($row['players']),
        'staff'   => $num($row['staff']),
    ];
}

/**
 * Equipos que le gastan licencia a una cuenta: los que ha creado ella y
 * los de su propio club.
 *
 * Los equipos que un club le ha dado como staff NO entran, y por eso la
 * consulta mira `owner_user_id` y no `team_staff`: quien entrena tres
 * categorías de un club y quiere además la suya propia solo paga por la
 * suya. Es la regla que hace que merezca la pena repartir staff.
 */
function team_count(int $userId): int
{
    $st = db()->prepare(
        'SELECT COUNT(*) AS n FROM teams t
          WHERE t.owner_user_id = ?
             OR t.club_id IN (SELECT id FROM clubs WHERE owner_user_id = ?)'
    );
    $st->execute([$userId, $userId]);
    return (int) ($st->fetch()['n'] ?? 0);
}

// ── Ajustes ────────────────────────────────────────────────────────
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT k, v FROM settings')->fetchAll() as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Throwable $e) {
            // Tabla aún no migrada: se usan los valores por defecto.
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $st = db()->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $st->execute([$key, $value]);
}

/** ¿Puede registrarse cualquiera, o solo los correos invitados? */
function registration_open(): bool
{
    return setting('registro_abierto', '0') === '1';
}

/**
 * Puerta del alta. Con el registro abierto deja pasar a cualquiera; con
 * el registro cerrado exige invitación y corta si no la hay.
 * Devuelve la invitación cuando existe, para heredar su licencia.
 */
function check_signup_allowed(string $email): ?array
{
    $inv = invitation_for($email);
    if (!$inv && !registration_open()) {
        fail_not_invited();
    }
    return $inv;
}

/**
 * Pruebas cerradas: solo se puede crear cuenta con un correo invitado.
 * Devuelve la fila de allowed_emails, o null si no está autorizado.
 */
function invitation_for(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM allowed_emails WHERE email = ? LIMIT 1');
    $st->execute([mb_strtolower($email)]);
    $row = $st->fetch();
    return $row ?: null;
}

function mark_invitation_used(string $email): void
{
    $st = db()->prepare(
        'UPDATE allowed_emails SET registered_at = NOW()
          WHERE email = ? AND registered_at IS NULL'
    );
    $st->execute([mb_strtolower($email)]);
}

/** Una cuenta suspendida existe, pero no entra. */
function assert_active(int $userId): void
{
    $st = db()->prepare('SELECT plan FROM users WHERE id = ?');
    $st->execute([$userId]);
    if (($st->fetch()['plan'] ?? '') === 'suspendido') {
        fail('Esta cuenta está suspendida. Escríbenos si crees que es un error.', 403);
    }
}

/** Mensaje único para quien intenta entrar sin invitación. */
function fail_not_invited(): void
{
    fail('PlayLoad está en pruebas cerradas: por ahora solo pueden crear cuenta '
       . 'los correos invitados. Escríbenos si quieres entrar en la lista.', 403);
}

/**
 * Espacios a los que la cuenta tiene acceso, en el formato que espera
 * el selector «¿Dónde quieres entrar?» de acceso.html.
 */
function spaces_for(int $userId): array
{
    $st = db()->prepare(
        "SELECT m.scope_type, m.scope_id, m.role,
                c.name AS club_name, c.city AS club_city,
                t.name AS team_name, t.category AS team_category, t.tint AS team_tint,
                (SELECT COUNT(*) FROM teams tt WHERE tt.club_id = c.id) AS club_teams
           FROM memberships m
           LEFT JOIN clubs c ON m.scope_type = 'club' AND c.id = m.scope_id
           LEFT JOIN teams t ON m.scope_type = 'team' AND t.id = m.scope_id
          WHERE m.user_id = ?
          ORDER BY FIELD(m.scope_type, 'club', 'team', 'personal'), m.id"
    );
    $st->execute([$userId]);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['scope_type'] === 'club') {
            $out[] = [
                'type'  => 'club',
                'id'    => (int) $r['scope_id'],
                'name'  => $r['club_name'],
                'sub'   => 'Club · ' . (int) $r['club_teams'] . ' equipos',
                'role'  => $r['role'],
                'tint'  => '#9184d9',
                'href'  => 'PlayLoad-club.html',
                'badge' => initials($r['club_name'] ?? ''),
            ];
        } elseif ($r['scope_type'] === 'team') {
            $out[] = [
                'type'  => 'team',
                'id'    => (int) $r['scope_id'],
                'name'  => $r['team_name'],
                'sub'   => $r['team_category'] ?: 'Equipo',
                'role'  => $r['role'],
                'tint'  => $r['team_tint'] ?: '#84b6d9',
                'href'  => 'PlayLoad-dashboard.html',
                'badge' => initials($r['team_name'] ?? ''),
            ];
        } else {
            $st2 = db()->prepare(
                'SELECT COUNT(*) AS n FROM teams WHERE owner_user_id = ? AND club_id IS NULL'
            );
            $st2->execute([$userId]);
            $n = (int) ($st2->fetch()['n'] ?? 0);
            $out[] = [
                'type'  => 'personal',
                'id'    => 0,
                'name'  => 'Mi cuenta profesional',
                'sub'   => $n . ($n === 1 ? ' equipo propio' : ' equipos propios'),
                'role'  => $r['role'] ?: 'Propietario',
                'tint'  => '#9fd984',
                'href'  => 'PlayLoad-dashboard.html',
                'badge' => 'MC',
            ];
        }
    }
    return $out;
}

function initials(string $text): string
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $out   = '';
    foreach ($words as $w) {
        if ($w !== '') {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
    }
    return mb_substr($out !== '' ? $out : 'PL', 0, 3);
}

/**
 * GET a una URL externa. Usa cURL, y si no está, file_get_contents.
 * En hosting compartido allow_url_fopen suele venir apagado, así que el
 * orden importa: cURL primero. Devuelve el cuerpo o null.
 */
function http_get(string $url, int $timeout = 8): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PlayLoad/1.0',
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        // Un 400 también trae cuerpo JSON, y ese cuerpo nos interesa.
        if ($out !== false) {
            return (string) $out;
        }
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out !== false) {
            return (string) $out;
        }
    }

    return null;
}

/** Correo de texto plano. Hostinger admite mail() si el remitente es del dominio. */
function send_mail(string $to, string $subject, string $body): bool
{
    global $CONFIG;
    $from = $CONFIG['mail_from'];
    $name = $CONFIG['mail_from_name'] ?? 'PlayLoad';

    $headers = implode("\r\n", [
        'From: ' . sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $from),
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
        'X-Mailer: PHP/' . phpversion(),
    ]);
    $subject = sprintf('=?UTF-8?B?%s?=', base64_encode($subject));

    return @mail($to, $subject, $body, $headers, '-f' . $from);
}
