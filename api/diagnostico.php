<?php
/**
 * Diagnóstico de la conexión con la base de datos.
 *
 * ⚠ ARCHIVO TEMPORAL. Enseña el nombre de la base y el usuario (nunca la
 * contraseña). BÓRRALO del servidor en cuanto el acceso funcione.
 *
 * Abrir en el navegador: https://tudominio.com/api/diagnostico.php
 */

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

echo "PlayLoad · diagnóstico de la base de datos\n";
echo str_repeat('=', 52) . "\n\n";

// ── 1. Entorno ─────────────────────────────────────────────────────
echo "1. Entorno\n";
echo "   PHP: " . PHP_VERSION . "\n";
echo "   Extensión pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'sí' : 'NO — avisa a Hostinger') . "\n\n";

// ── 2. Configuración ───────────────────────────────────────────────
echo "2. Archivo de configuración\n";
$configFile = __DIR__ . '/config.php';

if (!is_file($configFile)) {
    echo "   ✗ No existe api/config.php\n";
    echo "     Copia api/config.example.php a api/config.php y rellénalo.\n";
    exit;
}
echo "   ✓ api/config.php encontrado\n";

$CONFIG = require $configFile;

if (!is_array($CONFIG) || empty($CONFIG['db'])) {
    echo "   ✗ El archivo no devuelve la estructura esperada.\n";
    echo "     Debe empezar por <?php y terminar con  ];  sin nada detrás.\n";
    exit;
}

$d = $CONFIG['db'];
foreach (['host', 'name', 'user', 'pass', 'charset'] as $k) {
    if (!array_key_exists($k, $d)) {
        echo "   ✗ Falta la clave '$k' en el bloque db.\n";
        exit;
    }
}

echo "   host    : {$d['host']}\n";
echo "   name    : {$d['name']}\n";
echo "   user    : {$d['user']}\n";
echo "   pass    : " . ($d['pass'] === '' ? '(VACÍA — esa es la causa)' : strlen($d['pass']) . ' caracteres') . "\n";
echo "   charset : {$d['charset']}\n\n";

// Avisos de valores sin tocar
if (str_contains($d['name'], '123456789') || str_contains($d['user'], '123456789')) {
    echo "   ⚠ Siguen los valores de ejemplo: cámbialos por los tuyos.\n\n";
}

// En hosting compartido, Hostinger antepone siempre uNNNNNNNNN_ al
// nombre que escribes en el formulario. Sin ese prefijo, MySQL responde
// «Access denied» aunque la contraseña sea correcta, porque el usuario
// que buscas sencillamente no existe.
$sinPrefijo = [];
if (!preg_match('/^u\d+_/', $d['name'])) { $sinPrefijo[] = "name ({$d['name']})"; }
if (!preg_match('/^u\d+_/', $d['user'])) { $sinPrefijo[] = "user ({$d['user']})"; }

if ($sinPrefijo) {
    echo "   ⚠ Sin el prefijo de Hostinger: " . implode(' y ', $sinPrefijo) . "\n";
    echo "     En hosting compartido los nombres reales empiezan por uNNNNNNNNN_\n";
    echo "     aunque en el formulario escribieras solo la parte final.\n";
    echo "     Cópialos completos desde hPanel → Bases de datos → Lista de bases\n";
    echo "     de datos MySQL actuales. Esta es casi seguro la causa del fallo.\n\n";
}

// ── 3. Conexión ────────────────────────────────────────────────────
echo "3. Conexión\n";
$dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}";

try {
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "   ✓ Conectada\n\n";
} catch (PDOException $e) {
    $msg  = $e->getMessage();
    $code = (string) $e->getCode();

    echo "   ✗ Falló\n";
    echo "     MySQL dice: {$msg}\n\n";
    echo "   Traducción:\n";

    if (str_contains($msg, 'Access denied')) {
        echo "     La contraseña no es la de ese usuario, o el usuario NO está\n";
        echo "     asignado a esa base de datos.\n\n";
        echo "     Arreglo: hPanel → Bases de datos → Administración de bases de\n";
        echo "     datos MySQL. Pulsa «Cambiar contraseña» en la fila de tu usuario,\n";
        echo "     pon una nueva sin comillas ni barras invertidas, y cópiala tal\n";
        echo "     cual en config.php. Comprueba también que el usuario aparece\n";
        echo "     junto a ESA base y no a otra.\n";
    } elseif (str_contains($msg, 'Unknown database')) {
        echo "     El nombre de la base no existe. Suele ser que falta el prefijo\n";
        echo "     u000000000_ que Hostinger añade delante.\n\n";
        echo "     Arreglo: copia el nombre exacto de la columna «Base de datos\n";
        echo "     MySQL» de hPanel.\n";
    } elseif (str_contains($msg, "Can't connect") || str_contains($msg, 'Connection refused')
           || str_contains($msg, 'getaddrinfo')) {
        echo "     No hay servidor MySQL en ese host. Desde el propio hosting el\n";
        echo "     valor correcto es localhost.\n\n";
        echo "     Arreglo: pon 'host' => 'localhost' en config.php.\n";
    } else {
        echo "     Error no habitual. Pásame el texto de arriba y lo miro.\n";
    }
    echo "\n   (código SQLSTATE: {$code})\n";
    exit;
}

// ── 4. Tablas ──────────────────────────────────────────────────────
echo "4. Tablas del esquema\n";
$esperadas = ['users', 'clubs', 'teams', 'memberships', 'password_resets',
              'login_codes', 'remember_tokens', 'login_attempts', 'players'];

$hay = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$faltan = array_diff($esperadas, $hay);
if ($faltan) {
    echo "   ✗ Faltan: " . implode(', ', $faltan) . "\n";
    echo "     No has importado db/schema.sql, o lo importaste en otra base.\n";
    echo "     Arreglo: phpMyAdmin → elige ESTA base → Importar → schema.sql\n";
} else {
    echo "   ✓ Las nueve tablas están\n";
    $n = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "   Cuentas registradas: {$n}\n";
}

// ── 5. Google ──────────────────────────────────────────────────────
echo "\n5. Acceso con Google\n";
$cid = trim((string) ($CONFIG['google_client_id'] ?? ''));
echo "   ID de cliente: " . ($cid === '' ? '✗ vacío' : '✓ ' . substr($cid, 0, 18) . '…') . "\n";

// La verificación del token necesita salir a Internet. Se prueban las
// dos vías por separado, porque en hosting compartido es normal que
// allow_url_fopen esté apagado y solo funcione cURL.
$probe = 'https://oauth2.googleapis.com/tokeninfo?id_token=x';

echo "   cURL disponible: " . (function_exists('curl_init') ? 'sí' : 'NO') . "\n";
echo "   allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'sí' : 'no') . "\n";

$viaCurl = false;
if (function_exists('curl_init')) {
    $ch = curl_init($probe);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $r       = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $viaCurl = ($r !== false);
    echo "   Salida por cURL: " . ($viaCurl ? "✓ funciona" : "✗ falla — {$curlErr}") . "\n";
}

$viaFopen = ini_get('allow_url_fopen') && @file_get_contents($probe) !== false;
echo "   Salida por file_get_contents: " . ($viaFopen ? "✓ funciona" : "✗ no disponible") . "\n";

echo "   → Verificación del token: " .
     ($viaCurl || $viaFopen
        ? "✓ posible\n"
        : "✗ imposible; el acceso con Google fallará. Pide a Hostinger que\n" .
          "     habilite cURL o allow_url_fopen.\n");

echo "\n" . str_repeat('=', 52) . "\n";
echo "Borra este archivo del servidor cuando termines.\n";
