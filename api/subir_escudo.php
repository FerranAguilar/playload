<?php
/**
 * POST multipart/form-data { escudo } · el escudo del club, en jpg,
 * png o svg.
 *
 * Va en su propio archivo y no en app.php porque necesita leer
 * `$_FILES`, no el JSON que espera `body()` en el resto del API.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');

$user = require_user();
$club = mi_club((int) $user['id']);
if (!$club) {
    fail('Solo una cuenta de club tiene escudo que subir.', 403);
}

if (!isset($_FILES['escudo']) || $_FILES['escudo']['error'] === UPLOAD_ERR_NO_FILE) {
    fail('No ha llegado ningún archivo.');
}

$archivo = $_FILES['escudo'];

if ($archivo['error'] !== UPLOAD_ERR_OK) {
    $motivos = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo pesa más de lo que el servidor admite.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo pesa más de lo que el servidor admite.',
        UPLOAD_ERR_PARTIAL    => 'El archivo se ha subido a medias. Inténtalo otra vez.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene dónde guardarlo. Avisa al soporte.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no ha podido escribir el archivo.',
    ];
    fail($motivos[$archivo['error']] ?? 'No se ha podido subir el archivo.', 500);
}

// Tres megas es de sobra para un escudo, y poco margen para colar
// cualquier otra cosa.
if ($archivo['size'] > 3 * 1024 * 1024) {
    fail('El archivo pesa más de 3 MB. Recórtalo o comprímelo e inténtalo de nuevo.');
}

$tmp = $archivo['tmp_name'];
if (!is_uploaded_file($tmp)) {
    fail('Ese archivo no ha llegado como se esperaba.');
}

// El tipo lo dice el contenido, no la extensión ni lo que mande el
// navegador: los dos se pueden falsear sin esfuerzo. El nombre final
// tampoco sale de lo que mande quien sube, para que nunca decida él
// dónde acaba escrito ni con qué extensión.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmp);
finfo_close($finfo);

$contenido = null;   // solo se rellena para SVG, que se reescribe saneado
$ext       = null;

if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    // Además del tipo, que de verdad se puedan leer sus medidas: un
    // archivo con la cabecera falseada no pasa de aquí.
    if (@getimagesize($tmp) === false) {
        fail('Ese archivo no es una imagen válida.');
    }
} elseif (in_array($mime, ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'], true)) {
    // finfo no siempre reconoce el SVG como tal —es texto, y depende de
    // qué lleve dentro—, así que sin un tipo concluyente se mira si
    // empieza de verdad como un SVG.
    $texto  = (string) file_get_contents($tmp);
    $inicio = ltrim($texto, "\xEF\xBB\xBF \t\n\r");
    if (!preg_match('/^(<\?xml\b[^>]*>\s*)?<svg[\s>]/i', $inicio)) {
        fail('Ese archivo no es una imagen admitida. Sube un .jpg, .png o .svg.');
    }
    $ext       = 'svg';
    $contenido = escudo_sanear_svg($texto);
} else {
    fail('Ese archivo no es una imagen admitida. Sube un .jpg, .png o .svg.');
}

$dir = __DIR__ . '/../uploads/escudos';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fail('No se ha podido guardar: revisa los permisos de la carpeta uploads/escudos.', 500);
}

$nombre  = bin2hex(random_bytes(16)) . '.' . $ext;
$destino = $dir . '/' . $nombre;

if ($contenido !== null) {
    if (file_put_contents($destino, $contenido) === false) {
        fail('No se ha podido guardar el archivo.', 500);
    }
} elseif (!move_uploaded_file($tmp, $destino)) {
    fail('No se ha podido guardar el archivo.', 500);
}

$ruta     = 'uploads/escudos/' . $nombre;
$anterior = $club['badge_url'] ?? null;

$up = db()->prepare('UPDATE clubs SET badge_url = ? WHERE id = ?');
$up->execute([$ruta, (int) $club['id']]);

// El anterior ya no lo enlaza nadie: se borra para no ir dejando
// huérfanos. Comprobando antes que sigue dentro de uploads/escudos,
// por si esa columna llegara a tener algo distinto algún día.
if ($anterior && $anterior !== $ruta) {
    $viejo = realpath(__DIR__ . '/../' . $anterior);
    $base  = realpath($dir);
    if ($viejo && $base && strpos($viejo, $base) === 0) {
        @unlink($viejo);
    }
}

json_out(['ok' => true, 'badge_url' => $ruta]);

/**
 * Saneado mínimo de un SVG: fuera scripts, manejadores de eventos
 * (`onload`, `onclick`…) y cualquier `javascript:` en un atributo. No
 * es un analizador de XML completo —va por expresiones regulares,
 * sobre texto—, pero el escudo solo se enseña dentro de una etiqueta
 * `<img>`, que de por sí ya no ejecuta nada de lo que haya en el
 * archivo: esto es una capa más, no la única.
 */
function escudo_sanear_svg(string $svg): string
{
    $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg) ?? $svg;
    $svg = preg_replace('/<script\b[^>]*\/>/is', '', $svg) ?? $svg;
    $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg) ?? $svg;
    $svg = preg_replace(
        '/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', '$1=$2#$3', $svg
    ) ?? $svg;
    $svg = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg) ?? $svg;
    return $svg;
}
