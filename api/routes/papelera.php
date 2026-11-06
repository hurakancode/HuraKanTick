<?php
/**
 * Rutas /api/papelera
 *
 * Sólo para superadministradores: la papelera cruza hoteles y contiene
 * datos de todos, así que verla es una capacidad distinta de administrar
 * el propio.
 */
declare(strict_types=1);

$u = Auth::exigir();

if (!Hoteles::esSuperadmin($u)) {
    throw new ApiError('Sólo un administrador puede ver la papelera.', 403);
}

$accion = $partes[1] ?? '';

/* GET /api/papelera */
if ($accion === '' && $metodo === 'GET') {
    $r = Archivo::listar([
        'tabla'  => Http::query('tabla'),
        'hotel'  => Http::queryInt('hotel'),
        'q'      => Http::query('q'),
        'motivo' => Http::query('motivo'),
        'limite' => Http::queryInt('limite') ?? 100,
        'incluir_restaurados' => Http::bool(Http::query('restaurados')),
    ]);
    $r['resumen'] = Archivo::resumen(Http::queryInt('hotel'));
    $r['ruta'] = basename(Archivo::ruta());
    Http::ok($r);
}

/* GET /api/papelera/{id} — la fila entera, tal cual se borró */
$id = Http::id($accion);
if ($id !== null && $metodo === 'GET') {
    $f = Archivo::fila($id);
    if ($f === null) {
        throw new ApiError('Esa entrada no existe.', 404);
    }
    Http::ok($f);
}


/* ------------------------------------------------------------------ */
/* POST /api/papelera/{id}/restaurar                                   */
/*                                                                     */
/* Una papelera de la que no se puede sacar nada es un cajón de cosas  */
/* perdidas. Se borra el parte que no era —que pasa más de lo que      */
/* parece— y hasta ahora sólo quedaba mirarlo.                         */
/* ------------------------------------------------------------------ */
if ($id !== null && ($partes[2] ?? '') === 'restaurar' && $metodo === 'POST') {
    $r = Archivo::restaurar($id, (int)$u['id_login']);

    Http::ok([
        'restaurado' => $r,
        'mensaje'    => $r['tabla'] === 'login'
            ? 'Persona restaurada como «' . $r['etiqueta'] . '», sin acceso: '
              . 'la papelera no guarda contraseñas, así que hay que darle una nueva.'
            : 'Restaurado «' . $r['etiqueta'] . '» con su historial.',
    ]);
}

/* ------------------------------------------------------------------ */
/* DELETE /api/papelera/{id} — tirarlo de verdad                       */
/*                                                                     */
/* Hay borrados que tienen que ser definitivos: alguien pide que se    */
/* quiten sus datos, o se metió por error algo que no debía estar ahí. */
/* ------------------------------------------------------------------ */
if ($id !== null && $metodo === 'DELETE') {
    $r = Archivo::purgar($id, (int)$u['id_login']);
    Http::ok([
        'purgado' => $r,
        'mensaje' => 'Se ha tirado «' . $r['etiqueta'] . '» sin vuelta atrás'
                   . ($r['fotos'] ? ', con sus ' . $r['fotos'] . ' fotos' : '') . '.',
    ]);
}

throw new ApiError('Ruta de papelera no encontrada.', 404);
