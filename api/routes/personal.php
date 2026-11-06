<?php
/**
 * Rutas /api/personal — alta y salida de personas por ficheros de texto.
 *
 * Todo esto es sólo para administradores, y no por costumbre: un fichero
 * de alta puede crear un administrador, y la carpeta de salida lleva los
 * nombres, correos y teléfonos de toda la plantilla.
 *
 *   GET    /api/personal                   cómo está todo
 *   GET    /api/personal/plantilla         la plantilla para rellenar
 *   GET    /api/personal/plantilla/{id}    la ficha de una persona
 *   POST   /api/personal/importar          dar de alta lo que haya
 *   POST   /api/personal/exportar          escribir fichas en download
 *   GET    /api/personal/descargar?f=...   el contenido de un exportado
 *   DELETE /api/personal/exportados        limpiar la carpeta de salida
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Personal.php';

Auth::exigirAdmin();
$u = Auth::exigir();

$que = $partes[1] ?? '';

/* GET /api/personal — el estado de las dos carpetas y lo que hay */
if ($metodo === 'GET' && $que === '') {
    Http::ok([
        'carpetas'   => Personal::revisarCarpetas(),
        'pendientes' => Personal::pendientes(),
        'exportados' => Personal::exportados(),
    ]);
}

/* GET /api/personal/plantilla[/{id}] — texto plano, para guardar tal cual */
if ($metodo === 'GET' && $que === 'plantilla') {
    $id = Http::id($partes[2] ?? null);
    $de = null;
    if ($id !== null) {
        $de = Db::one('SELECT * FROM login WHERE id_login = ?', [$id]);
        if ($de === null) {
            throw new ApiError('Esa persona no existe.', 404);
        }
    }
    Http::ok([
        'nombre' => $de === null
            ? 'plantilla-persona.txt'
            : preg_replace('/[^a-z0-9._-]+/', '-', mb_strtolower((string)$de['usuario'])) . '.txt',
        'texto'  => Personal::plantilla($de),
    ]);
}

/* POST /api/personal/importar — recorrer los .txt de upload/user */
if ($metodo === 'POST' && $que === 'importar') {
    /* «Simular» primero es lo sensato con cuarenta ficheros: dice qué
       pasaría sin crear a nadie y sin borrar nada. */
    $simular = Http::bool(Http::campo('simular'));
    $r = Personal::importar((int)$u['id_login'], $simular);

    $trozos = [];
    if ($r['creadas'])      { $trozos[] = $r['creadas'] . ' creada(s)'; }
    if ($r['actualizadas']) { $trozos[] = $r['actualizadas'] . ' actualizada(s)'; }
    if ($r['malas'])        { $trozos[] = count($r['malas']) . ' con problemas'; }

    Http::ok($r + ['mensaje' => $simular
        ? 'Prueba hecha: no se ha creado ni borrado nada.'
        : ($trozos
            ? implode(', ', $trozos) . '.'
              . ($r['malas'] ? ' Los que fallaron se han quedado con la extensión '
                             . '.error y un .motivo.txt al lado.' : '')
            : 'No había ningún fichero en bak_config/upload/user.')]);
}

/* POST /api/personal/exportar — escribir las fichas marcadas */
if ($metodo === 'POST' && $que === 'exportar') {
    $ids = Http::campo('ids');

    /* «Todos» se resuelve aquí y no en el móvil: con la plantilla entera
       el navegador tendría que mandar cien identificadores, y si alguien
       se da de alta entre medias se quedaría fuera sin que se note. */
    if (Http::bool(Http::campo('todos'))) {
        $sql = 'SELECT id_login FROM login';
        $arg = [];
        if (Db::tablaExiste('hoteles') && !Hoteles::esSuperadmin()) {
            $sql .= ' WHERE id_hotel = ?';
            $arg[] = Hoteles::actual($u);
        }
        $ids = array_column(Db::all($sql . ' ORDER BY id_login', $arg), 'id_login');
    }

    if (!is_array($ids) || !$ids) {
        throw new ApiError('No has marcado a nadie.', 422, ['campo' => 'ids']);
    }

    $r = Personal::exportar($ids);
    Registro::anotar('exportacion', (int)$u['id_login'],
        'Exportadas ' . $r['cuantos'] . ' fichas de personal a bak_config/download/user');

    Http::ok($r + ['mensaje' => $r['cuantos'] . ' ficha(s) en ' . $r['carpeta']
                              . '. No llevan contraseña.']);
}

/* GET /api/personal/descargar?f=... — para bajarlo desde la aplicación */
if ($metodo === 'GET' && $que === 'descargar') {
    $f = (string)Http::requerido('f');
    Http::ok(['nombre' => basename($f), 'texto' => Personal::leerExportado($f)]);
}

/* DELETE /api/personal/exportados — limpiar la carpeta de salida */
if ($metodo === 'DELETE' && $que === 'exportados') {
    $ficheros = Http::campo('ficheros');
    if (Http::bool(Http::campo('todos'))) {
        $ficheros = array_column(Personal::exportados(), 'fichero');
    }
    if (!is_array($ficheros) || !$ficheros) {
        throw new ApiError('No has dicho qué borrar.', 422);
    }
    $n = Personal::borrarExportados($ficheros);
    Http::ok(['borrados' => $n, 'mensaje' => "$n fichero(s) quitados."]);
}

throw new ApiError('Ruta de personal no encontrada.', 404);
