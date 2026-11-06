<?php
/** GET /api/estado — comprobación rápida de que todo responde. */
declare(strict_types=1);

if ($metodo !== 'GET') {
    throw new ApiError('Método no permitido.', 405);
}

$instalada = Db::tablaExiste('ticket_incidencias') && Db::tablaExiste('login');

/* Lo que se cuenta sin haber entrado.
 *
 * Esta ruta no pide sesión —la usa la pantalla de entrada para saber si
 * hay servidor—, así que antes le decía a cualquiera cuántas personas hay
 * dadas de alta y la versión exacta de PHP, que es lo primero que busca
 * quien quiere atacar un servidor. Ahora esos detalles sólo salen:
 *   · mientras no está instalada, que es cuando hacen falta para montarla
 *     y no hay nada que proteger;
 *   · o a un administrador con sesión, en la pantalla de diagnóstico. */
$quien = null;
try { $quien = $instalada ? Auth::usuario() : null; } catch (Throwable $e) { $quien = null; }
$detalles = !$instalada || ($quien !== null && Auth::esAdmin($quien));

/* La versión sale de www/version.json, no de un número escrito aquí.
 *
 * Aquí ponía «2.0.0» desde el principio, mientras la aplicación iba por
 * la 7.89. Había CINCO números de versión distintos —éste, el de la
 * etiqueta de index.html (3.0.1), el de version.json (3.0.1), el del
 * trabajador de servicio (7.89) y el de package.json (2.0.0)—, y cada
 * pantalla enseñaba uno. Ahora éste no se escribe: se lee. */
$versionPublicada = '?';
$vj = __DIR__ . '/../../www/version.json';
if (is_file($vj)) {
    $versionPublicada = (string)(json_decode((string)@file_get_contents($vj), true)['version'] ?? '?');
}

$respuesta = [
    'servicio'   => 'HuraKan Tick API',
    'version'    => $versionPublicada,
    'instalada'  => $instalada,
    // Lo mira la pantalla de entrada, antes de identificarse.
    'https'      => $instalada && (bool)Permisos::ajuste('forzar_https', false),
    // Un número al azar que cambia al reinstalar: el móvil lo mira antes de
    // mandar lo pendiente, para no enviarlo a una base que no es la suya.
    'instalacion'=> $instalada ? Permisos::instalacion() : '',
    // La orden de renovar: el móvil la mira antes de mandar lo pendiente.
    'renovacion' => $instalada ? (string)(Permisos::ajuste('purga_generacion', '') ?? '') : '',
    'hora'       => Db::now(),
    'aplicacion' => [
        'nombre'       => $config['app']['nombre'],
        'organizacion' => $config['app']['organizacion'],
        'niveles_zona' => $config['app']['niveles_zona'],
    ],
];
if ($detalles) {
    $respuesta += [
        'motor'    => Db::driver(),
        'usuarios' => $instalada ? (int)Db::value('SELECT COUNT(*) FROM login') : 0,
        'php'      => PHP_VERSION,
        'gd'       => function_exists('imagecreatefromstring'),
    ];
}
Http::ok($respuesta);
