<?php
/**
 * Rutas /api/departamentos
 *
 * Los departamentos son de la cadena, no de un hotel: «Servicios
 * Técnicos» significa lo mismo en todos, y un informe conjunto sólo
 * cuadra si es así.
 *
 * Lo que cambia de un hotel a otro es cuáles se usan. Uno de treinta
 * habitaciones no tiene jardinería ni informática, y le sobra verlas en
 * cada desplegable. Por eso hay dos cosas separadas:
 *
 *   · el catálogo común, que sólo toca un superadministrador
 *   · qué usa cada hotel, que se elige por hotel
 *
 * Un administrador de hotel puede elegir de la lista y ponerle el nombre
 * que se use en la casa, pero no puede inventarse departamentos nuevos:
 * si cada hotel crea los suyos, en un mes hay cuatro maneras de llamar a
 * lo mismo y los informes de la cadena dejan de servir.
 */
declare(strict_types=1);

$u = Auth::exigir();
$accion = $partes[1] ?? '';
$id = Http::id($accion);

/* ------------------------------------------------------------------ */
/* GET /api/departamentos — el catálogo, con quién lo usa              */
/* ------------------------------------------------------------------ */
if ($accion === '' && $metodo === 'GET') {
    $hotel = Http::queryInt('hotel') ?? Hoteles::actual();
    $todos = Http::bool(Http::query('todos'));

    $filas = Db::all(
        "SELECT d.*,
                (SELECT COUNT(*) FROM login l
                  WHERE l.id_departamento = d.id_departamento AND l.bloqueo = 0) AS personas,
                (SELECT COUNT(*) FROM ticket_incidencias t
                  WHERE t.id_grupo_asignado = d.id_departamento AND t.borrado = 0) AS partes,
                (SELECT COUNT(*) FROM hotel_departamento hd
                  WHERE hd.id_departamento = d.id_departamento) AS hoteles
           FROM departamentos d
          ORDER BY d.orden, d.nombre");

    // Qué usa el hotel con el que se está trabajando, y cómo lo llama.
    $usados = [];
    if ($hotel !== null && Db::tablaExiste('hotel_departamento')) {
        foreach (Db::all('SELECT id_departamento, alias FROM hotel_departamento WHERE id_hotel = ?',
                         [$hotel]) as $r) {
            $usados[(int)$r['id_departamento']] = $r['alias'];
        }
    }

    $salida = [];
    foreach ($filas as $d) {
        $idD = (int)$d['id_departamento'];
        $enHotel = array_key_exists($idD, $usados);
        if (!$todos && $hotel !== null && !$enHotel) {
            continue;
        }
        $salida[] = [
            'id_departamento' => $idD,
            'nombre'          => $d['nombre'],
            'alias'           => $usados[$idD] ?? null,
            'nombre_en_hotel' => ($usados[$idD] ?? '') !== '' ? $usados[$idD] : $d['nombre'],
            'color'           => $d['color'],
            'icono'           => $d['icono'] ?? null,
            'activo'          => (int)($d['activo'] ?? 1),
            'orden'           => (int)($d['orden'] ?? 0),
            'en_este_hotel'   => $enHotel,
            'personas'        => (int)$d['personas'],
            'partes'          => (int)$d['partes'],
            'hoteles'         => (int)$d['hoteles'],
        ];
    }

    Http::ok([
        'departamentos' => $salida,
        'hotel'         => $hotel,
        'puede_editar'  => Hoteles::esSuperadmin($u),
    ]);
}

/* ------------------------------------------------------------------ */
/* POST /api/departamentos — crear uno en el catálogo común            */
/* ------------------------------------------------------------------ */
if ($accion === '' && $metodo === 'POST') {
    if (!Hoteles::esSuperadmin($u)) {
        throw new ApiError(
            'Sólo un administrador puede crear departamentos: son comunes a toda la cadena.',
            403);
    }
    $nombre = trim((string)Http::requerido('nombre'));
    if ($nombre === '') {
        throw new ApiError('El nombre no puede quedar vacío.', 422, ['campo' => 'nombre']);
    }
    if (Db::value('SELECT 1 FROM departamentos WHERE LOWER(nombre) = ?', [mb_strtolower($nombre)])) {
        throw new ApiError('Ya existe un departamento con ese nombre.', 409, ['campo' => 'nombre']);
    }

    /* El color no puede quedar vacío: la columna viene de una versión
       anterior y no admite nulos. Si no se elige uno se pone el de la
       marca, que es lo que se ve en el resto de la aplicación. */
    $idNuevo = Db::insert('departamentos', [
        'nombre'     => mb_substr($nombre, 0, 80),
        'color'      => (string)(Http::campo('color') ?: '#0B5563'),
        'icono'      => Http::campo('icono') ?: null,
        'activo'     => 1,
        'orden'      => (int)(Http::campo('orden') ?? 0),
        'updated_at' => Db::now(),
    ]);

    /* A qué hoteles va.
       Si no se dice nada, sólo al hotel desde el que se crea: es lo
       menos sorprendente. Añadirlo a todos por defecto llenaría de
       departamentos ajenos a hoteles que no los han pedido. */
    $hoteles = Http::campo('hoteles');
    if (!is_array($hoteles)) {
        $actual = Hoteles::actual();
        $hoteles = $actual !== null ? [$actual] : [];
    }
    foreach ($hoteles as $h) {
        Db::insertarSiFalta('hotel_departamento',
            ['id_hotel' => (int)$h, 'id_departamento' => $idNuevo, 'orden' => $idNuevo]);
    }

    Registro::anotar('catalogo', (int)$u['id_login'],
        'Departamento creado: ' . $nombre . ' (' . count($hoteles) . ' hoteles)');

    Http::ok(['id_departamento' => $idNuevo,
              'mensaje' => 'Departamento creado en ' . count($hoteles)
                         . ' hotel' . (count($hoteles) === 1 ? '' : 'es') . '.'], 201);
}

/* ------------------------------------------------------------------ */
/* PUT /api/departamentos/{id}/hoteles — en qué hoteles se usa         */
/* ------------------------------------------------------------------ */
if ($id !== null && ($partes[2] ?? '') === 'hoteles') {

    if ($metodo === 'GET') {
        $filas = Db::all(
            'SELECT h.id_hotel, h.nombre,
                    CASE WHEN hd.id_hotel IS NULL THEN 0 ELSE 1 END AS usa,
                    hd.alias
               FROM hoteles h
               LEFT JOIN hotel_departamento hd
                      ON hd.id_hotel = h.id_hotel AND hd.id_departamento = ?
              ORDER BY h.nombre', [$id]);
        foreach ($filas as &$f) {
            $f['id_hotel'] = (int)$f['id_hotel'];
            $f['usa'] = (int)$f['usa'] === 1;
        }
        Http::ok(['hoteles' => $filas]);
    }

    if ($metodo === 'PUT' || $metodo === 'POST') {
        if (!Hoteles::esSuperadmin($u)) {
            throw new ApiError('Sólo un administrador reparte departamentos entre hoteles.', 403);
        }
        $quiere = Http::campo('hoteles');
        if (!is_array($quiere)) {
            throw new ApiError('Falta la lista de hoteles.', 422, ['campo' => 'hoteles']);
        }
        $quiere = array_map('intval', $quiere);

        $antes = array_map('intval', array_column(
            Db::all('SELECT id_hotel FROM hotel_departamento WHERE id_departamento = ?', [$id]),
            'id_hotel'));

        /* Quitarlo de un hotel que lo está usando dejaría partes y gente
           apuntando a algo que ese hotel ya no ve. Se avisa en vez de
           hacerlo a la callada. */
        $conflictos = [];
        foreach (array_diff($antes, $quiere) as $h) {
            $n = (int)Db::value(
                'SELECT COUNT(*) FROM ticket_incidencias
                  WHERE id_hotel = ? AND id_grupo_asignado = ? AND borrado = 0', [$h, $id]);
            $p = (int)Db::value('SELECT COUNT(*) FROM login WHERE id_hotel = ? AND id_departamento = ?',
                                [$h, $id]);
            if ($n > 0 || $p > 0) {
                $conflictos[] = [
                    'id_hotel' => $h,
                    'hotel'    => Db::value('SELECT nombre FROM hoteles WHERE id_hotel = ?', [$h]),
                    'partes'   => $n,
                    'personas' => $p,
                ];
            }
        }
        if ($conflictos && !Http::bool(Http::campo('forzar'))) {
            $t = $conflictos[0];
            throw new ApiError(
                'En «' . $t['hotel'] . '» hay ' . $t['partes'] . ' partes y ' . $t['personas']
                . ' personas en este departamento. Quitarlo los dejaría sin sitio. '
                . 'Repite la operación con «forzar» si aun así quieres hacerlo.',
                409, ['conflictos' => $conflictos]);
        }

        /* Los alias SE CONSERVAN. Esto los borraba todos.
         *
         * «Cómo se llama en este hotel» vive en hotel_departamento.alias,
         * en la misma fila que dice que el departamento se usa ahí. Y
         * aquí se borraban todas las filas del departamento para volver a
         * crearlas, sin el alias: cambiar en qué hoteles se usa se
         * llevaba por delante el nombre propio de CADA hotel, no sólo el
         * del que estaba mirando.
         *
         * Y no hacía falta ni tocar el reparto a mano: la pantalla de
         * departamentos guarda las dos cosas de una vez —primero el
         * alias con PATCH, después el reparto con PUT—, así que escribir
         * un alias y pulsar «Guardar» lo escribía y lo borraba en la
         * misma pulsación. Quien lo hiciera vería el campo vacío otra
         * vez y pensaría que no se guarda nada.
         *
         * Lo encontró pruebas/campos.py, que escribe en cada campo y
         * vuelve a leerlo. */
        $aliasPrevios = [];
        foreach (Db::all('SELECT id_hotel, alias FROM hotel_departamento
                           WHERE id_departamento = ?', [$id]) as $r) {
            if (($r['alias'] ?? '') !== '' && $r['alias'] !== null) {
                $aliasPrevios[(int)$r['id_hotel']] = $r['alias'];
            }
        }

        Db::begin();
        try {
            Db::run('DELETE FROM hotel_departamento WHERE id_departamento = ?', [$id]);
            foreach ($quiere as $h) {
                $fila = ['id_hotel' => $h, 'id_departamento' => $id, 'orden' => $id];
                if (isset($aliasPrevios[(int)$h])) {
                    $fila['alias'] = $aliasPrevios[(int)$h];
                }
                Db::insertarSiFalta('hotel_departamento', $fila);
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw new ApiError('No se ha podido guardar: ' . $e->getMessage(), 500);
        }

        Registro::anotar('catalogo', (int)$u['id_login'],
            'Departamento repartido: ' . count($quiere) . ' hoteles');
        Http::ok(['mensaje' => 'Se usa en ' . count($quiere)
                             . ' hotel' . (count($quiere) === 1 ? '' : 'es') . '.']);
    }
}

/* ------------------------------------------------------------------ */
/* PATCH /api/departamentos/{id} — editar                              */
/* ------------------------------------------------------------------ */
if ($id !== null && ($metodo === 'PATCH' || $metodo === 'PUT')) {
    $d = Db::one('SELECT * FROM departamentos WHERE id_departamento = ?', [$id]);
    if ($d === null) {
        throw new ApiError('Ese departamento no existe.', 404);
    }

    $cuerpo = Http::body();
    $datos = [];

    /* El nombre real sólo lo cambia un administrador: lo lleva toda
       la cadena. El alias del hotel lo puede poner su administrador. */
    if (array_key_exists('nombre', $cuerpo)) {
        if (!Hoteles::esSuperadmin($u)) {
            throw new ApiError(
                'El nombre es común a todos los hoteles. Puedes ponerle un nombre propio '
                . 'para tu hotel con «alias».', 403, ['campo' => 'nombre']);
        }
        $nombre = trim((string)Http::campo('nombre'));
        if ($nombre === '') {
            throw new ApiError('El nombre no puede quedar vacío.', 422, ['campo' => 'nombre']);
        }
        if (Db::value('SELECT 1 FROM departamentos WHERE LOWER(nombre) = ? AND id_departamento <> ?',
                      [mb_strtolower($nombre), $id])) {
            throw new ApiError('Ya hay otro departamento con ese nombre.', 409, ['campo' => 'nombre']);
        }
        $datos['nombre'] = mb_substr($nombre, 0, 80);
    }

    foreach (['color', 'icono'] as $c) {
        if (array_key_exists($c, $cuerpo)) {
            if (!Hoteles::esSuperadmin($u)) {
                throw new ApiError('Sólo un administrador cambia el catálogo común.', 403);
            }
            // El color nunca se queda vacío; el icono sí puede.
            $datos[$c] = $c === 'color'
                ? (string)(Http::campo($c) ?: '#0B5563')
                : (Http::campo($c) ?: null);
        }
    }
    if (array_key_exists('orden', $cuerpo) && Hoteles::esSuperadmin($u)) {
        $datos['orden'] = (int)Http::campo('orden');
    }
    if (array_key_exists('activo', $cuerpo)) {
        if (!Hoteles::esSuperadmin($u)) {
            throw new ApiError('Sólo un administrador desactiva un departamento.', 403);
        }
        $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0;
    }

    if ($datos !== []) {
        $datos['updated_at'] = Db::now();
        Db::update('departamentos', $datos, 'id_departamento = :id', ['id' => $id]);
    }

    // El alias es del hotel: no toca el catálogo.
    if (array_key_exists('alias', $cuerpo)) {
        $hotel = Http::queryInt('hotel') ?? Hoteles::actual();
        if ($hotel === null) {
            throw new ApiError('No se sabe de qué hotel es ese nombre.', 422);
        }
        Hoteles::exigir($hotel, $u);
        /* Y que lo administre. Esto sólo miraba que fuera de ese hotel:
           recepción o cualquier técnico podía rebautizar «Servicios
           Técnicos» para todo el hotel —en las listas, en el reparto, en
           cada parte— con una petición. Lo encontró pruebas/matriz.py. */
        if (!Auth::esAdmin($u)) {
            throw new ApiError('Sólo un administrador le pone nombre propio a un departamento.', 403,
                               ['campo' => 'alias']);
        }
        $alias = trim((string)Http::campo('alias'));
        Db::run('UPDATE hotel_departamento SET alias = ? WHERE id_hotel = ? AND id_departamento = ?',
                [$alias !== '' ? mb_substr($alias, 0, 80) : null, $hotel, $id]);
    }

    if ($datos === [] && !array_key_exists('alias', $cuerpo)) {
        throw new ApiError('No hay cambios que guardar.', 422);
    }

    Http::ok(['mensaje' => 'Departamento actualizado.']);
}

/* ------------------------------------------------------------------ */
/* DELETE /api/departamentos/{id}                                      */
/* ------------------------------------------------------------------ */
if ($id !== null && $metodo === 'DELETE') {
    if (!Hoteles::esSuperadmin($u)) {
        throw new ApiError('Sólo un administrador puede borrar departamentos.', 403);
    }
    $d = Db::one('SELECT * FROM departamentos WHERE id_departamento = ?', [$id]);
    if ($d === null) {
        throw new ApiError('Ese departamento no existe.', 404);
    }

    $personas = (int)Db::value('SELECT COUNT(*) FROM login WHERE id_departamento = ?', [$id]);
    $partes   = (int)Db::value(
        'SELECT COUNT(*) FROM ticket_incidencias WHERE id_grupo_asignado = ? AND borrado = 0', [$id]);

    /* Con gente o partes detrás no se borra: se desactiva.
       Borrarlo dejaría partes que no pueden decir de quién eran, y en un
       informe de hace dos años eso es justo lo que se busca. */
    if ($personas > 0 || $partes > 0) {
        Db::update('departamentos', ['activo' => 0, 'updated_at' => Db::now()],
                   'id_departamento = :id', ['id' => $id]);
        Registro::anotar('catalogo', (int)$u['id_login'],
            'Departamento desactivado: ' . $d['nombre']);
        Http::ok([
            'desactivado' => true,
            'mensaje' => 'Tiene ' . $personas . ' personas y ' . $partes . ' partes detrás, '
                       . 'así que se ha desactivado en vez de borrarlo: deja de ofrecerse, '
                       . 'pero el histórico sigue diciendo de quién era cada parte.',
        ]);
    }

    Db::run('DELETE FROM hotel_departamento WHERE id_departamento = ?', [$id]);
    Db::run('DELETE FROM departamentos WHERE id_departamento = ?', [$id]);
    Registro::anotar('catalogo', (int)$u['id_login'], 'Departamento borrado: ' . $d['nombre']);
    Http::ok(['mensaje' => 'Departamento borrado.']);
}

throw new ApiError('Ruta de departamentos no encontrada.', 404);
