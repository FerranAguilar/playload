<?php
/**
 * Copia este archivo a config.php y rellena los datos reales.
 * config.php está en .gitignore: no debe subirse nunca al repositorio.
 *
 * Los datos de la base de datos salen de hPanel → Bases de datos →
 * Administración de bases de datos MySQL. Desde el propio hosting el
 * host casi siempre es 'localhost'.
 */
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'u000000000_playload',
        'user'    => 'u000000000_play',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Google Cloud Console → Credenciales → ID de cliente de OAuth 2.0
    // (tipo «Aplicación web»). Vacío = el botón de Google queda apagado.
    'google_client_id' => '',

    // Sin barra final. Se usa para los enlaces de los correos.
    'app_url' => 'https://tudominio.com',

    // Debe ser un buzón real de tu dominio en Hostinger, o el correo
    // saldrá marcado como spam o directamente rechazado.
    'mail_from'      => 'no-reply@tudominio.com',
    'mail_from_name' => 'PlayLoad',

    // true solo mientras depuras: enseña el detalle de los errores.
    'debug' => false,
];
