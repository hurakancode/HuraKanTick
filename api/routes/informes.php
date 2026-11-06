<?php
/** Rutas /api/informes */
declare(strict_types=1);

$u = Auth::exigir();

if (!Permisos::activo('informes')) {
    throw new ApiError('Los informes están desactivados en este sistema.', 403);
}

$accion = $partes[1] ?? '';

/* GET /api/informes/definiciones — campos, operadores y medidas */
if ($accion === 'definiciones' && $metodo === 'GET') {
    Http::ok(Informes::definiciones());
}

/* POST /api/informes/ejecutar */
if ($accion === 'ejecutar' && $metodo === 'POST') {
    Http::ok(Informes::ejecutar(Http::body(), $u));
}

/* GET /api/informes — los guardados */
if ($accion === '' && $metodo === 'GET') {
    $filas = Db::all(
        'SELECT i.id_informe, i.nombre, i.descripcion, i.definicion, i.compartido,
                i.favorito, i.ultimo_uso, i.creado, i.id_login,
                i.alcance, i.id_departamento,
                ' . Db::nombreCompleto('l') . ' AS autor
           FROM informes i
           LEFT JOIN login l ON l.id_login = i.id_login
          WHERE i.id_login = :yo
                 OR i.alcance = \'todos\'
                 OR (i.alcance = \'departamento\' AND i.id_departamento = :dep)
          ORDER BY i.favorito DESC, i.nombre',
        ['yo' => (int)$u['id_login'], 'dep' => (int)($u['id_departamento'] ?? 0)]
    );
    foreach ($filas as &$f) {
        $f['id_informe'] = (int)$f['id_informe'];
        $f['compartido'] = (int)$f['compartido'];
        $f['favorito']   = (int)$f['favorito'];
        $f['mio']        = (int)$f['id_login'] === (int)$u['id_login'];
        $f['alcance']    = (string)($f['alcance'] ?? ($f['compartido'] ? 'todos' : 'yo'));
        $f['definicion'] = json_decode((string)$f['definicion'], true);
    }
    unset($f);
    Http::ok(['informes' => $filas]);
}

/* POST /api/informes — guardar uno */
if ($accion === '' && $metodo === 'POST') {
    $nombre = Http::requerido('nombre');
    $def    = Http::campo('definicion');
    if (!is_array($def)) {
        throw new ApiError('Falta la definición del informe.', 422);
    }
    /* Tres posturas: para mí, para mi departamento o para todo el hotel.
       Compartir con el hotel entero se deja a responsables: una lista de
       informes que cualquiera puede llenar deja de servir en dos semanas.
       Con el propio departamento puede compartir cualquiera. */
    $alcance = (string)Http::campo('alcance', '');
    if ($alcance === '') {
        $alcance = Http::bool(Http::campo('compartido')) ? 'todos' : 'yo';
    }
    if (!in_array($alcance, ['yo', 'departamento', 'todos'], true)) {
        throw new ApiError('Ese alcance no existe.', 422, ['campo' => 'alcance']);
    }
    if ($alcance === 'todos' && !Auth::esResponsable($u)) {
        throw new ApiError('Sólo un responsable puede compartir informes con todo el equipo.', 403);
    }
    if ($alcance === 'departamento' && (int)($u['id_departamento'] ?? 0) === 0) {
        throw new ApiError('No tienes departamento al que compartirlo.', 422);
    }
    $compartido = $alcance === 'todos';
    $id = Db::insert('informes', [
        'nombre'      => mb_substr($nombre, 0, 120),
        'descripcion' => mb_substr((string)Http::campo('descripcion', ''), 0, 400),
        'definicion'  => json_encode($def, JSON_UNESCAPED_UNICODE),
        'id_login'    => (int)$u['id_login'],
        'compartido'  => $compartido ? 1 : 0,
        'alcance'     => $alcance,
        // Se guarda a qué departamento pertenece, no se mira el de su
        // autor cada vez: si cambia de departamento, su informe no debe
        // saltar de lista.
        'id_departamento' => (int)($u['id_departamento'] ?? 0) ?: null,
        'favorito'    => Http::bool(Http::campo('favorito')) ? 1 : 0,
        'creado'      => Db::now(),
        'updated_at'  => Db::now(),
    ]);
    Http::ok(['id_informe' => $id], 201);
}

/* PATCH|DELETE /api/informes/{id} */
$id = Http::id($accion);
if ($id !== null) {
    $inf = Db::one('SELECT * FROM informes WHERE id_informe = ?', [$id]);
    if ($inf === null) {
        throw new ApiError('El informe no existe.', 404);
    }
    $mio = (int)$inf['id_login'] === (int)$u['id_login'];
    if (!$mio && !Auth::esAdmin($u)) {
        throw new ApiError('Sólo puedes modificar tus propios informes.', 403);
    }

    if ($metodo === 'DELETE') {
        Db::run('DELETE FROM informes WHERE id_informe = ?', [$id]);
        Http::ok(['mensaje' => 'Informe eliminado.']);
    }

    if ($metodo === 'PATCH') {
        $datos = ['updated_at' => Db::now()];
        foreach (['nombre', 'descripcion'] as $c) {
            if (array_key_exists($c, Http::body())) {
                $datos[$c] = mb_substr((string)Http::campo($c), 0, 400);
            }
        }
        if (array_key_exists('definicion', Http::body())) {
            $datos['definicion'] = json_encode(Http::campo('definicion'), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('favorito', Http::body())) {
            $datos['favorito'] = Http::bool(Http::campo('favorito')) ? 1 : 0;
        }
        if (array_key_exists('alcance', Http::body())) {
            $nuevo = (string)Http::campo('alcance');
            if (!in_array($nuevo, ['yo', 'departamento', 'todos'], true)) {
                throw new ApiError('Ese alcance no existe.', 422, ['campo' => 'alcance']);
            }
            if ($nuevo === 'todos' && !Auth::esResponsable($u)) {
                throw new ApiError('Sólo un responsable puede compartir informes con todo el equipo.', 403);
            }
            $datos['alcance']    = $nuevo;
            $datos['compartido'] = $nuevo === 'todos' ? 1 : 0;
            if ($nuevo === 'departamento' && ($inf['id_departamento'] ?? null) === null) {
                $datos['id_departamento'] = (int)($u['id_departamento'] ?? 0) ?: null;
            }
        }

        if (array_key_exists('compartido', Http::body())) {
            if (Http::bool(Http::campo('compartido')) && !Auth::esResponsable($u)) {
                throw new ApiError('Sólo un responsable puede compartir informes.', 403);
            }
            $datos['compartido'] = Http::bool(Http::campo('compartido')) ? 1 : 0;
        }
        Db::update('informes', $datos, 'id_informe = :id', ['id' => $id]);
        Http::ok(['mensaje' => 'Informe guardado.']);
    }
}

throw new ApiError('Ruta de informes no encontrada.', 404);
