<?php
/**
 * GET · planes y ajustes públicos. Lo consume precios.html.
 * No requiere sesión: es información pública.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$publicos = setting('precios_publicos', '0') === '1';

$rows = db()->query(
    'SELECT id, track, name, tagline, price_m, price_y, teams, players, staff, feats, best
       FROM plans WHERE active = 1 ORDER BY track, sort'
)->fetchAll();

$planes = ['pro' => [], 'club' => []];

foreach ($rows as $r) {
    $planes[$r['track']][] = [
        'id'      => $r['id'],
        'name'    => $r['name'],
        'tag'     => $r['tagline'],
        // Sin precios públicos no se envían: lo que no sale del servidor
        // no puede leerse en el navegador.
        'm'       => $publicos && $r['price_m'] !== null ? (float) $r['price_m'] : null,
        'y'       => $publicos && $r['price_y'] !== null ? (float) $r['price_y'] : null,
        'teams'   => $r['teams'],
        'players' => $r['players'],
        'staff'   => $r['staff'],
        'feats'   => array_values(array_filter(
            array_map('trim', explode("\n", (string) $r['feats'])),
            fn($f) => $f !== ''
        )),
        'best'    => (bool) $r['best'],
    ];
}

json_out([
    'ok'               => true,
    'precios_publicos' => $publicos,
    'registro_abierto' => registration_open(),
    'planes'           => $planes,
]);
