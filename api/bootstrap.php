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
        'SELECT id, email, name, account_type, role, avatar_url, two_factor
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
                'href'  => 'PlayLoad-equipos.html',
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
