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

    // ID de cliente de OAuth (tipo «Aplicación web»). Es un identificador
    // público: viaja en el HTML de acceso.html, y lo que protege la cuenta
    // es la lista de orígenes autorizados en Google Cloud Console, no que
    // este valor sea secreto. Debe ser idéntico al de acceso.html.
    'google_client_id' => '677151412548-tiel2gkq6vbhdjlphejskrph9adop5d0.apps.googleusercontent.com',

    // Sin barra final. Se usa para los enlaces de los correos.
    'app_url' => 'https://tudominio.com',

    // Debe ser un buzón real de tu dominio en Hostinger, o el correo
    // saldrá marcado como spam o directamente rechazado.
    'mail_from'      => 'no-reply@tudominio.com',
    'mail_from_name' => 'PlayLoad',

    // true solo mientras depuras: enseña el detalle de los errores.
    'debug' => false,
];
