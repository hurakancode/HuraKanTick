<?php
/** Rutas /api/tickets */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Tickets.php';

$u   = Auth::exigir();

/* Lo programado que ya toca vuelve a la cola antes de montar la lista.
   Es un UPDATE con índice: cuesta nada y evita depender de una tarea
   programada, que en un hotel es una cosa más que puede dejar de
   funcionar sin que nadie se entere. */
try { Tickets::despertarProgramados(); } catch (Throwable $e) { /* la lista se da igual */ }
$id  = Http::id($partes[1] ?? null);
$sub = $partes[2] ?? '';

/* ---------------------------------------------------------------- */
/* GET /api/tickets                                                 */
/* ---------------------------------------------------------------- */
if ($id === null && $metodo === 'GET') {
    $filtros = [
        'q'            => Http::query('q'),
        'estado'       => Http::query('estado') !== null ? explode(',', (string)Http::query('estado')) : null,
        'prioridad'    => Http::query('prioridad') !== null ? explode(',', (string)Http::query('prioridad')) : null,
        'elemento'     => Http::queryInt('elemento'),
        'departamento' => Http::queryInt('departamento'),
        'asignado'     => Http::queryInt('asignado'),
        'zona'         => Http::queryInt('zona'),
        'zona1'        => Http::queryInt('zona1'),
        'zona2'        => Http::queryInt('zona2'),
        'mios'         => Http::bool(Http::query('mios')),
        'creados_por_mi' => Http::bool(Http::query('creados_por_mi')),
        'sin_asignar'  => Http::bool(Http::query('sin_asignar')),
        'abiertos'     => Http::bool(Http::query('abiertos')),
        'vencidos'     => Http::bool(Http::query('vencidos')),
        // Lo que está aparcado para más adelante, y cuántos días mirar.
        'programados'  => Http::bool(Http::query('programados')),
        'dias'         => Http::queryInt('dias'),
        'desde'        => Http::query('desde'),
        'hasta'        => Http::query('hasta'),
        'orden'        => Http::query('orden', 'reciente'),
        'limite'       => Http::queryInt('limite', 50),
        'offset'       => Http::queryInt('offset', 0),
    ];
    Http::ok(Repo::listarTickets($filtros, $u));
}

/* ---------------------------------------------------------------- */
/* ------------------------------------------------------------------ */
/* POST /api/tickets/lote — actuar sobre varios a la vez              */
/*                                                                    */
/* Va antes que el alta porque «lote» ocupa el sitio del número de     */
/* parte, y si no lo atiende antes, la petición cae en «crear».        */
/* ------------------------------------------------------------------ */
if (($partes[1] ?? '') === 'lote' && $metodo === 'POST') {
    /* Se lee con campo() y no con requerido(): éste devuelve texto, y
       un array convertido a texto es la palabra «Array». Costó de ver
       porque el error que salía era «no has indicado ningún parte». */
    $ids = array_values(array_filter(
        array_map('intval', (array)(Http::campo('ids') ?? [])), static fn($v) => $v > 0));
    if (!$ids) {
        throw new ApiError('No has indicado ningún parte.', 422, ['campo' => 'ids']);
    }
    if (count($ids) > 200) {
        throw new ApiError('Demasiados de una vez: máximo 200.', 422);
    }

    $accion = (string)(Http::campo('accion') ?? '');
    $hechos = 0;
    $saltados = 0;

    $fallidos = [];

    foreach ($ids as $idT) {
        /* Para el borrado definitivo también valen los que ya están en la
           papelera: son justamente los que se quieren quitar de en medio.
           Repo::ticket() no los devuelve —están ocultos—, así que antes
           se contaban como «sin permisos» y no había manera de borrarlos. */
        $t = $accion === 'eliminar'
            ? Db::one('SELECT * FROM ticket_incidencias WHERE id_ticket = ?', [$idT])
            : Repo::ticket($idT);

        if ($t === null) { $saltados++; continue; }
        if ($accion !== 'eliminar' && !Permisos::puedeEditar($u, $t)) { $saltados++; continue; }

        if ($accion === 'eliminar') {
            if (!Auth::esAdmin($u)) { $saltados++; continue; }
            try {
                Tickets::eliminarDefinitivo($idT, $u, 'borrado en bloque');
                $hechos++;
            } catch (Throwable $e) {
                /* Antes esto se tragaba el fallo y lo contaba como «sin
                   tocar por permisos», que es mentira y manda a mirar
                   donde no es. Ahora se dice qué pasó y queda apuntado. */
                $fallidos[] = $idT;
                Errores::apuntar('servidor',
                    'No se pudo borrar el parte ' . $idT . ': ' . $e->getMessage(),
                    $e->getTraceAsString(), 'tickets/lote/eliminar', $u);
            }
            continue;
        }

        /* Ocultar: la misma regla que de uno en uno —sólo un administrador—
           y dejando apunte. Antes, en lote, podía cualquiera que pudiera
           editar el parte, y sin rastro: un técnico podía hacer desaparecer
           partes de las listas sin que constara en ningún sitio. */
        if ($accion === 'borrar') {
            if (!Auth::esAdmin($u)) { $saltados++; continue; }
            Tickets::eliminar($idT, $u);
            $hechos++;
            continue;
        }

        if ($accion === 'estado' || $accion === 'asignar') {
            $cambios = [];
            if ($accion === 'estado') {
                $cambios['id_estado'] = Http::id(Http::campo('id_estado'));
            } else {
                $cambios['id_persona_asignada'] = Http::id(Http::campo('id_persona_asignada'));
                $cambios['id_grupo_asignado']   = Http::id(Http::campo('id_grupo_asignado'));
            }
            try {
                Tickets::actualizar($idT, $cambios, $u);
                $hechos++;
            } catch (Throwable $e) { $saltados++; }
            continue;
        }

        $saltados++;
    }

    if ($accion === 'eliminar' && $hechos) {
        Registro::anotar('borrado', (int)$u['id_login'],
            "Borrado definitivo en lote: $hechos partes");
    }

    Http::ok([
        // Si algo se ha resistido, se dice: un «0 eliminados» sin motivo
        // deja a cualquiera dando vueltas.
        'fallidos' => count($fallidos),
        'hechos'   => $hechos,
        'saltados' => $saltados,
        'mensaje'  => $hechos . ' ' . ($hechos === 1 ? 'parte' : 'partes')
                    . ' ' . ($accion === 'eliminar' ? 'eliminados' : 'actualizados')
                    . ($saltados ? " · $saltados sin tocar por permisos" : '')
                    . ($fallidos ? ' · ' . count($fallidos)
                        . ' no se han podido borrar (míralos en Administración → Errores)' : ''),
    ]);
}

/* POST /api/tickets                                                */
/* ---------------------------------------------------------------- */
if ($id === null && $metodo === 'POST') {
    $t = Tickets::crear(Http::body(), $u);

    // Fotos adjuntas en el mismo envío (array de data-url)
    $fotos = Http::campo('fotos', []);
    $subidas = [];
    if (is_array($fotos)) {
        foreach (array_slice($fotos, 0, 10) as $i => $foto) {
            $datos  = is_array($foto) ? ($foto['datos'] ?? '') : (string)$foto;
            $nombre = is_array($foto) ? ($foto['nombre'] ?? "foto-$i.jpg") : "foto-$i.jpg";
            $fuuid  = is_array($foto) ? ($foto['uuid'] ?? null) : null;
            if ($datos !== '') {
                $subidas[] = Uploads::desdeBase64((int)$t['id_ticket'], (string)$datos, (string)$nombre, (int)$u['id_login'], $fuuid);
            }
        }
    }

    Http::ok(['ticket' => $t, 'adjuntos' => $subidas], isset($t['duplicado']) ? 200 : 201);
}

/* ---------------------------------------------------------------- */
/* Rutas con id                                                     */
/* ---------------------------------------------------------------- */
if ($id !== null) {

    /* El historial y las fotos de un parte son del parte: sólo los ve —y
     * sólo sube fotos— quien puede ver el parte.
     *
     * Estas sub-rutas no lo comprobaban. Cualquiera con sesión podía leer
     * el historial completo de cualquier parte, con sus notas, recorriendo
     * los números; ver la lista de sus fotos; y subirle fotos. La ficha del
     * parte sí lo comprobaba, y por eso no se notaba en la pantalla.
     * Se hace aquí, una vez, para todas: así una sub-ruta nueva no puede
     * olvidarse. */
    if (in_array($sub, ['historial', 'adjuntos', 'repetidos', 'chat', 'observadores'], true)) {
        $visto = Repo::ticket($id);
        if ($visto === null) {
            throw new ApiError('El parte ' . Repo::codigo($id) . ' no existe.', 404);
        }
        if (!Permisos::puedeVer($u, $visto)) {
            throw new ApiError('No tienes permiso para ver este parte.', 403);
        }
    }

    /* GET /api/tickets/{id} */
    if ($sub === '' && $metodo === 'GET') {
        $t = Repo::ticket($id);
        if ($t === null) {
            throw new ApiError('El parte ' . Repo::codigo($id) . ' no existe.', 404);
        }
        if (!Permisos::puedeVer($u, $t)) {
            throw new ApiError('No tienes permiso para ver este parte.', 403);
        }
        /* Cuántos mensajes tiene sin leer quien mira: el icono del chat
           lleva el número. Va DENTRO del parte, no al lado: la pantalla
           guarda el parte y lee de él, así que fuera se perdía y el
           contador no aparecía nunca. */
        $t['chat_sin_leer'] = class_exists('Chat') ? Chat::sinLeerEn($id, (int)$u['id_login']) : 0;

        /* Y cuántos hay en total. Sin esto, una conversación ya leída se
           veía igual que un parte sin conversación, y quien volvía al
           parte no sabía que allí se había hablado. */
        $t['chat_total'] = Db::tablaExiste('chat_mensajes')
            ? (int)Db::value('SELECT COUNT(*) FROM chat_mensajes WHERE id_ticket = ? AND borrado = 0', [$id])
            : 0;

        Http::ok([
            'ticket'     => $t,
            'historial'  => Repo::historial($id),
            'adjuntos'   => Repo::adjuntos($id),
            'campos'     => Repo::camposDe($id),
            'puede_editar' => Permisos::puedeEditar($u, $t),
        ]);
    }

    /* PATCH|PUT /api/tickets/{id} */
    if ($sub === '' && ($metodo === 'PATCH' || $metodo === 'PUT')) {
        Http::ok(['ticket' => Tickets::actualizar($id, Http::body(), $u)]);
    }

    /* DELETE /api/tickets/{id}
       Con ?definitivo=1 sale de la base viva y va a la papelera; sin
       ella sólo deja de verse, que es lo que se quiere casi siempre. */
    if ($sub === '' && $metodo === 'DELETE') {
        if (Http::bool(Http::query('definitivo'))) {
            Tickets::eliminarDefinitivo($id, $u);
            Http::ok(['mensaje' => 'Parte eliminado. Queda en la papelera.']);
        }
        Tickets::eliminar($id, $u);
        Http::ok(['mensaje' => 'Parte eliminado.']);
    }

    /* POST /api/tickets/{id}/movimientos */
    if ($sub === 'movimientos' && $metodo === 'POST') {
        $t = Tickets::movimiento($id, Http::body(), $u);
        Http::ok(['ticket' => $t, 'historial' => Repo::historial($id)]);
    }

    /* GET /api/tickets/{id}/historial */
    /* Qué más ha pasado con esto, aquí.
     *
     * Dos listas: los partes del mismo tipo de avería en el mismo sitio
     * —que es lo que dice si algo se repite— y el resto del historial de
     * ese tipo por todo el hotel. Sale al final de la ficha del parte.
     *
     * Se filtra por lo que puede ver quien mira: el historial no es una
     * rendija para leer partes de otro departamento. */
    /* La conversación del parte.
     *
     *   GET  /tickets/{id}/chat        los mensajes y quién los ha leído
     *   POST /tickets/{id}/chat        escribir
     *   POST /tickets/{id}/chat/leido  marcar como leídos los pendientes
     *
     * El permiso ya se ha comprobado arriba, con el resto de sub-rutas:
     * quien no puede ver el parte tampoco entra en su conversación. */
    /* Los observadores del parte.
     *
     *   GET    /tickets/{id}/observadores      quién lo está siguiendo
     *   POST   /tickets/{id}/observadores      añadir persona o departamento
     *   DELETE /tickets/{id}/observadores/{n}  quitarlo
     *
     * Poner observadores es repartir acceso, así que lo hace quien puede
     * repartir: responsables y administradores. */
    if ($sub === 'observadores') {
        if ($metodo === 'GET') {
            Http::ok(['observadores' => Repo::observadores($id)]);
        }

        if (!Auth::esResponsable($u)) {
            throw new ApiError('Sólo un responsable puede poner observadores.', 403);
        }

        if ($metodo === 'POST') {
            $idPersona = Http::id(Http::campo('id_login'));
            $idDepto   = Http::id(Http::campo('id_departamento'));
            if ($idPersona === null && $idDepto === null) {
                throw new ApiError('Dime a quién: una persona o un departamento.', 422);
            }
            Http::ok(Repo::ponerObservador($id, $idPersona, $idDepto, $u), 201);
        }

        if ($metodo === 'DELETE') {
            $idObs = Http::id($partes[3] ?? null);
            if ($idObs === null) {
                throw new ApiError('Falta cuál quitar.', 422);
            }
            Repo::quitarObservador($id, $idObs, $u);
            Http::ok(['mensaje' => 'Observador quitado.']);
        }
    }

    if ($sub === 'chat') {
        if ($metodo === 'GET') {
            Http::ok([
                'mensajes'      => Chat::mensajes($id, (int)$u['id_login']),
                'participantes' => Chat::participantes($id),
                'sin_leer'      => Chat::sinLeerEn($id, (int)$u['id_login']),
            ]);
        }

        if ($metodo === 'POST' && ($partes[3] ?? '') === 'leido') {
            $leidos = Chat::marcarLeido($id, (int)$u['id_login']);
            /* Y cuántos avisos le quedan: leer la conversación apaga los
               suyos, y así la campana se pone al día en el momento en vez
               de esperar a la siguiente sincronización. */
            Http::ok([
                'leidos'    => $leidos,
                'no_leidas' => (int)Db::value(
                    'SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0',
                    [(int)$u['id_login']]),
            ]);
        }

        if ($metodo === 'POST') {
            $r = Chat::escribir(
                $id, $u,
                (string)Http::requerido('texto'),
                Http::bool(Http::campo('importante')),
                Http::campo('uuid') ? (string)Http::campo('uuid') : null);
            Http::ok($r + ['mensaje' => 'Enviado.'], $r['duplicado'] ? 200 : 201);
        }
    }

    if ($sub === 'repetidos' && $metodo === 'GET') {
        $t = Repo::ticket($id);
        if ($t === null) { throw new ApiError('El parte no existe.', 404); }

        [$sqlVis, $pVis] = Permisos::filtroVisibilidad($u, 't', 'r');
        $vis = $sqlVis !== '' ? ' AND ' . $sqlVis : '';

        $base = 'SELECT t.id_ticket, t.titulo_ticket, t.fecha_creado, t.fecha_cierre,
                        t.id_estado, e.nombre_estado, e.color AS color_estado,
                        r.ruta, d.nombre AS departamento
                   FROM ticket_incidencias t
                   LEFT JOIN estados e ON e.id_estado = t.id_estado
                   LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
                   LEFT JOIN departamentos d ON d.id_departamento = t.id_grupo_asignado
                  WHERE t.borrado = 0 AND t.id_ticket <> :yo AND t.id_elemento = :el';

        $p = ['yo' => $id, 'el' => (int)($t['id_elemento'] ?? 0)] + $pVis;

        if ((int)($t['id_elemento'] ?? 0) === 0) {
            Http::ok(['aqui' => [], 'otros' => [], 'total_aqui' => 0, 'total_otros' => 0]);
        }

        // Mismo tipo y mismo sitio.
        $aqui = [];
        $totalAqui = 0;
        if ($t['id_rel_zona'] !== null) {
            $pAqui = $p + ['zona' => (int)$t['id_rel_zona']];
            $aqui = Db::all($base . ' AND t.id_rel_zona = :zona' . $vis
                          . ' ORDER BY t.fecha_creado DESC LIMIT 20', $pAqui);
            $totalAqui = (int)Db::value(
                'SELECT COUNT(*) FROM ticket_incidencias t
                   LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
                  WHERE t.borrado = 0 AND t.id_ticket <> :yo AND t.id_elemento = :el
                    AND t.id_rel_zona = :zona' . $vis, $pAqui);
        }

        // El mismo tipo, en cualquier otro sitio.
        $otros = Db::all($base . ($t['id_rel_zona'] !== null ? ' AND (t.id_rel_zona IS NULL OR t.id_rel_zona <> :zona2)' : '') . $vis
                       . ' ORDER BY t.fecha_creado DESC LIMIT 20',
                         $p + ($t['id_rel_zona'] !== null ? ['zona2' => (int)$t['id_rel_zona']] : []));
        $totalOtros = (int)Db::value(
            'SELECT COUNT(*) FROM ticket_incidencias t
               LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
              WHERE t.borrado = 0 AND t.id_ticket <> :yo AND t.id_elemento = :el'
            . ($t['id_rel_zona'] !== null ? ' AND (t.id_rel_zona IS NULL OR t.id_rel_zona <> :zona2)' : '') . $vis,
            $p + ($t['id_rel_zona'] !== null ? ['zona2' => (int)$t['id_rel_zona']] : []));

        Http::ok([
            // El nombre del tipo no viene en la ficha: se busca aquí, que
            // es lo que se enseña en el bloque y en el informe preparado.
            'elemento'    => $t['nombre_elemento'] ?? Db::value(
                'SELECT nombre_elemento FROM elemento WHERE id_elemento = ?',
                [(int)$t['id_elemento']]),
            'ruta'        => $t['ruta'] ?? null,
            'aqui'        => $aqui,
            'otros'       => $otros,
            'total_aqui'  => $totalAqui,
            'total_otros' => $totalOtros,
        ]);
    }

    if ($sub === 'historial' && $metodo === 'GET') {
        Http::ok(['historial' => Repo::historial($id)]);
    }

    /* POST /api/tickets/{id}/adjuntos */
    if ($sub === 'adjuntos' && $metodo === 'POST') {
        $t = Repo::ticket($id);
        if ($t === null) {
            throw new ApiError('El parte no existe.', 404);
        }
        $resultado = [];

        if (!empty($_FILES['archivo'])) {
            $f = $_FILES['archivo'];
            if (is_array($f['name'])) {                     // varios archivos
                foreach ($f['name'] as $i => $n) {
                    $resultado[] = Uploads::desdeFiles($id, [
                        'name' => $n, 'tmp_name' => $f['tmp_name'][$i],
                        'error' => $f['error'][$i], 'size' => $f['size'][$i],
                    ], (int)$u['id_login']);
                }
            } else {
                $resultado[] = Uploads::desdeFiles($id, $f, (int)$u['id_login'], Http::campo('uuid'));
            }
        } else {
            $datos = (string)Http::requerido('datos');       // data-url base64
            $resultado[] = Uploads::desdeBase64(
                $id, $datos, (string)Http::campo('nombre', 'foto.jpg'),
                (int)$u['id_login'], Http::campo('uuid')
            );
        }

        Repo::anotar([
            'id_ticket' => $id, 'tipo' => 'adjunto',
            'detalle'   => count($resultado) . ' archivo(s) adjuntado(s)',
            'id_estado' => (int)$t['id_estado'], 'id_estado_old' => (int)$t['id_estado'],
            'autor'     => (int)$u['id_login'],
        ]);
        Db::update('ticket_incidencias', ['updated_at' => Db::now()], 'id_ticket = :id', ['id' => $id]);

        Http::ok(['adjuntos' => $resultado], 201);
    }

    /* GET /api/tickets/{id}/adjuntos */
    if ($sub === 'adjuntos' && $metodo === 'GET') {
        Http::ok(['adjuntos' => Repo::adjuntos($id)]);
    }
}

throw new ApiError('Ruta de tickets no encontrada.', 404);
