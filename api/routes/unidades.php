<?php
/** Rutas /api/unidades */
declare(strict_types=1);

$u = Auth::exigir();

if (!Permisos::activo('unidades')) {
    throw new ApiError('Los números de serie están desactivados.', 403);
}

require_once __DIR__ . '/../lib/Unidades.php';

$accion = $partes[1] ?? '';

/* GET /api/unidades?q=…&zona=…&elemento=…&lugar=1 */
if ($accion === '' && $metodo === 'GET') {
    $q    = trim((string)Http::query('q', ''));
    $zona = Http::queryInt('zona');
    $ele  = Http::queryInt('elemento');

    if ($zona !== null) {
        $unidades = Unidades::enUbicacion($zona, $ele, Http::bool(Http::query('lugar', '1')));
        Http::ok([
            'unidades' => $unidades,
            'resumen'  => $ele === null
                ? Unidades::resumenUbicacion($zona, Http::bool(Http::query('lugar', '1')))
                : [],
            'estados'  => Unidades::ESTADOS,
            'motivos'  => Unidades::MOTIVOS,
        ]);
    }

    if ($q !== '') {
        Http::ok([
            'unidades' => Unidades::buscar($q),
            'estados'  => Unidades::ESTADOS,
            'motivos'  => Unidades::MOTIVOS,
        ]);
    }

    // Sin filtros: las que hay en servicio, por si se quiere un inventario
    $filas = Db::all(
        'SELECT u.id_unidad FROM unidades u WHERE u.estado = ? ORDER BY u.updated_at DESC LIMIT 200',
        ['activa']
    );
    Http::ok([
        'unidades' => array_values(array_filter(array_map(
            static fn($f) => Unidades::una((int)$f['id_unidad']), $filas))),
        'estados'  => Unidades::ESTADOS,
        'motivos'  => Unidades::MOTIVOS,
    ]);
}

/* GET /api/unidades/{id} y su historial */
$id = Http::id($accion);
if ($id !== null && $metodo === 'GET') {
    $unidad = Unidades::una($id);
    if ($unidad === null) {
        throw new ApiError('Ese aparato no existe.', 404);
    }
    Http::ok(array_merge(
        ['unidad' => $unidad],
        Unidades::historial($id),
        ['estados' => Unidades::ESTADOS, 'motivos' => Unidades::MOTIVOS]
    ));
}

/* A partir de aquí hace falta poder tocar el inventario */
if (($u['rol'] ?? '') === 'reportador') {
    throw new ApiError('No tienes permiso para modificar el inventario.', 403);
}

if ($accion === '' && $metodo === 'POST') {
    $nuevo = Unidades::crear(Http::body(), (int)$u['id_login']);
    Http::ok(['id_unidad' => $nuevo, 'unidad' => Unidades::una($nuevo)], 201);
}

if ($id !== null && $metodo === 'PATCH') {
    Unidades::cambiar($id, Http::body(), (int)$u['id_login'], Http::id(Http::campo('id_ticket')));
    Http::ok(['unidad' => Unidades::una($id)]);
}

throw new ApiError('Ruta de unidades no encontrada.', 404);
