<?php
/**
 * Rutas /api/ubicaciones
 *
 * La pregunta más frecuente en un hotel no es «dame el listado»: es
 * «¿qué pasa en la 214?». La hace recepción cuando un cliente se queja,
 * housekeeping antes de entregar la habitación, y el técnico que ya va
 * para allá y quiere saber si hay algo más que hacer de camino.
 */
declare(strict_types=1);

$u = Auth::exigir();

/** Comprueba que una ubicación es del hotel de quien pregunta. */
$mismoHotel = static function (int $idRel) use ($u): void {
    if (!Db::tablaExiste('hoteles')) { return; }
    $h = Db::value('SELECT id_hotel FROM rel_zonas WHERE id_rel = ?', [$idRel]);
    if ($h === null) { throw new ApiError('No se ha encontrado.', 404); }
    Hoteles::exigir((int)$h, $u);
};

/* GET /api/ubicaciones/buscar?q=214 */
if (($partes[1] ?? '') === 'buscar' && $metodo === 'GET') {
    $q = trim((string)Http::query('q', ''));
    if (mb_strlen($q) < 1) {
        Http::ok(['ubicaciones' => []]);
    }

    /* DOS filtros, con parámetros de nombre distinto.
     *
     * Aquí el mismo filtro de visibilidad entra en dos subconsultas de
     * una sola sentencia. Con un único juego de nombres, MySQL con
     * preparación nativa contesta «Invalid parameter number» y buscar
     * una ubicación devolvía un error de base de datos. En SQLite
     * funcionaba, y por eso no lo vio nadie: la batería entera se
     * ejecutaba contra SQLite. */
    [$sqlVisA, $pVisA] = Permisos::filtroVisibilidad($u, 't', 'r', '_a');
    [$sqlVisB, $pVisB] = Permisos::filtroVisibilidad($u, 't', 'r', '_b');
    $visA = $sqlVisA === '' ? '' : ' AND ' . $sqlVisA;
    $visB = $sqlVisB === '' ? '' : ' AND ' . $sqlVisB;
    $pVis = array_merge($pVisA, $pVisB);

    $filas = Db::all(
        "SELECT r.id_rel, r.ruta,
                (SELECT COUNT(*) FROM ticket_incidencias t
                   LEFT JOIN estados e ON e.id_estado = t.id_estado
                  WHERE t.id_rel_zona = r.id_rel AND t.borrado = 0
                    AND COALESCE(e.es_final,0) = 0 $visA) AS abiertos,
                (SELECT COUNT(*) FROM ticket_incidencias t
                   LEFT JOIN estados e ON e.id_estado = t.id_estado
                  WHERE t.id_rel_zona = r.id_rel AND t.borrado = 0 $visB) AS total
           FROM rel_zonas r
          WHERE r.id_hotel = :hot AND " . Repo::sinAcentos('r.ruta') . " LIKE :q ESCAPE '!'
          ORDER BY abiertos DESC, r.ruta
          LIMIT 40",
        array_merge($pVis, ['q' => Repo::patron(Repo::planoTexto($q)), 'hot' => Hoteles::actual($u) ?? 0])
    );

    foreach ($filas as &$f) {
        $f['id_rel']   = (int)$f['id_rel'];
        $f['abiertos'] = (int)$f['abiertos'];
        $f['total']    = (int)$f['total'];
    }
    Http::ok(['ubicaciones' => $filas]);
}

/* GET /api/ubicaciones/{id_rel} — la ficha completa del sitio */
$idRel = Http::id($partes[1] ?? null);
if ($idRel !== null) { $mismoHotel($idRel); }
if ($idRel !== null && $metodo === 'GET') {

    $zona = Db::one(
        'SELECT r.*, z1.nombre_zona_1, z2.nombre_zona_2, z3.nombre_zona_3, z4.nombre_zona_4
           FROM rel_zonas r
           LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
           LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
           LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
           LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4
          WHERE r.id_rel = ?',
        [$idRel]
    );
    if ($zona === null) {
        throw new ApiError('Esa ubicación no existe.', 404);
    }

    [$sqlVis, $pVis] = Permisos::filtroVisibilidad($u);
    $visWhere = $sqlVis === '' ? '' : ' AND ' . $sqlVis;

    // Todo lo del mismo lugar, aunque cambie el sublugar: quien pregunta
    // por la 214 quiere saber también lo del baño de la 214.
    $mismoLugar = Http::bool(Http::query('lugar', '1'));
    $filtroZona = $mismoLugar && $zona['id_zona_3'] !== null
        ? 'r2.id_zona_3 = :z3'
        : 't.id_rel_zona = :idrel';
    $pZona = $mismoLugar && $zona['id_zona_3'] !== null
        ? ['z3' => (int)$zona['id_zona_3']]
        : ['idrel' => $idRel];

    $base = 'FROM ticket_incidencias t
             LEFT JOIN estados e ON e.id_estado = t.id_estado
             LEFT JOIN rel_zonas r2 ON r2.id_rel = t.id_rel_zona
             WHERE t.borrado = 0 AND ' . $filtroZona . $visWhere;
    $params = array_merge($pVis, $pZona);

    $tickets = Db::all(
        Repo::selectTicket() . ' WHERE t.id_ticket IN (SELECT t.id_ticket ' . $base . ')'
        . ' ORDER BY COALESCE(e.es_final,0) ASC, t.fecha_creado DESC LIMIT 100',
        $params
    );
    $tickets = array_map([Repo::class, 'normalizaTicket'], $tickets);

    // Qué se repite aquí: si la misma avería vuelve tres veces, no es
    // mala suerte, es que no se arregló bien.
    $porFamilia = Db::all(
        'SELECT COALESCE(fa.nombre, \'(sin clasificar)\') AS familia, fa.color,
                COUNT(t.id_ticket) AS n
           FROM ticket_incidencias t
           LEFT JOIN estados e ON e.id_estado = t.id_estado
           LEFT JOIN rel_zonas r2 ON r2.id_rel = t.id_rel_zona
           LEFT JOIN elemento el ON el.id_elemento = t.id_elemento
           LEFT JOIN familias fa ON fa.id_familia = COALESCE(t.id_familia, el.id_familia)
          WHERE t.borrado = 0 AND ' . $filtroZona . $visWhere . '
          GROUP BY fa.nombre, fa.color
          ORDER BY n DESC LIMIT 8',
        $params
    );
    foreach ($porFamilia as &$f) { $f['n'] = (int)$f['n']; }
    unset($f);

    $resumen = Db::one(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN COALESCE(e.es_final,0) = 0 THEN 1 ELSE 0 END) AS abiertos,
                MIN(t.fecha_creado) AS primero,
                MAX(t.fecha_creado) AS ultimo,
                COALESCE(SUM(t.minutos_trabajo), 0) AS minutos
           ' . $base,
        $params
    ) ?? [];

    Http::ok([
        'ubicacion' => [
            'id_rel' => $idRel,
            'ruta'   => $zona['ruta'] ?: implode(' › ', array_filter([
                $zona['nombre_zona_1'], $zona['nombre_zona_2'],
                $zona['nombre_zona_3'], $zona['nombre_zona_4'],
            ])),
            'lugar'  => $zona['nombre_zona_3'],
            'z1' => $zona['id_zona_1'] === null ? null : (int)$zona['id_zona_1'],
            'z2' => $zona['id_zona_2'] === null ? null : (int)$zona['id_zona_2'],
            'z3' => $zona['id_zona_3'] === null ? null : (int)$zona['id_zona_3'],
            'z4' => $zona['id_zona_4'] === null ? null : (int)$zona['id_zona_4'],
        ],
        'mismo_lugar' => $mismoLugar,
        'resumen' => [
            'total'    => (int)($resumen['total'] ?? 0),
            'abiertos' => (int)($resumen['abiertos'] ?? 0),
            'minutos'  => (int)($resumen['minutos'] ?? 0),
            'primero'  => $resumen['primero'] ?? null,
            'ultimo'   => $resumen['ultimo'] ?? null,
        ],
        'por_familia' => $porFamilia,
        'tickets'     => $tickets,
    ]);
}

/* GET /api/ubicaciones/abiertos?zona=N — para avisar de duplicados */
if (($partes[1] ?? '') === 'abiertos' && $metodo === 'GET') {
    $idZona = Http::queryInt('zona');
    $idLugar = Http::queryInt('lugar');
    if ($idZona === null && $idLugar === null) {
        Http::ok(['tickets' => []]);
    }

    [$sqlVis, $pVis] = Permisos::filtroVisibilidad($u);
    $visWhere = $sqlVis === '' ? '' : ' AND ' . $sqlVis;

    $cond = $idLugar !== null ? 'r.id_zona_3 = :lugar' : 't.id_rel_zona = :zona';
    $p = $idLugar !== null ? ['lugar' => $idLugar] : ['zona' => $idZona];

    $filas = Db::all(
        Repo::selectTicket()
        . ' WHERE t.borrado = 0 AND COALESCE(e.es_final,0) = 0 AND ' . $cond . $visWhere
        . ' ORDER BY t.fecha_creado DESC LIMIT 12',
        array_merge($pVis, $p)
    );
    Http::ok(['tickets' => array_map([Repo::class, 'normalizaTicket'], $filas)]);
}

throw new ApiError('Ruta de ubicaciones no encontrada.', 404);
