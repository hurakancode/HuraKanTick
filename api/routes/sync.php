<?php
/**
 * Motor de sincronización.
 *
 *   GET  /api/sync/pull?desde=2026-07-20T10:00:00Z
 *        Devuelve todo lo que ha cambiado en el servidor desde esa marca.
 *
 *   POST /api/sync/push
 *        Recibe la cola de operaciones que el móvil acumuló sin cobertura.
 *
 * Estrategia de conflictos: el historial es de sólo añadir, así que dos
 * personas trabajando a la vez sobre el mismo parte no se pisan — quedan
 * los dos movimientos. Para los campos del ticket manda la última escritura,
 * y toda operación lleva uuid: reenviarla no duplica nada.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Tickets.php';

$u      = Auth::exigir();

/* Lo aparcado que ya toca vuelve a la cola también al sincronizar: un
   móvil que se sincroniza de fondo debe traerse el estado bueno, no el
   de ayer. */
try { Tickets::despertarProgramados(); } catch (Throwable $e) { /* la sincronización sigue */ }
$accion = $partes[1] ?? '';

/* ================================================================= */
/* GET /api/sync/pull                                                */
/* ================================================================= */
if ($accion === 'pull' && $metodo === 'GET') {

    $desde  = Tickets::fecha(Http::query('desde')) ?? '1970-01-01 00:00:00';
    $limite = min(max(Http::queryInt('limite', 300) ?? 300, 1), 1000);
    $ahora  = Db::now();

    /* Qué se baja al móvil.
     *
     * Dos cortes, y los dos hacen falta:
     *
     *   · los cerrados, sólo los del histórico configurado
     *   · los abiertos, también acotados
     *
     * Lo segundo no estaba, y era un problema de verdad. Un hotel con
     * dos años de uso puede arrastrar miles de partes abiertos de hace
     * mucho —los que nadie cerró nunca— y el móvil intentaba bajárselos
     * todos: cientos de vueltas, megas de datos y una primera
     * sincronización eterna.
     *
     * Un parte abierto de hace tres años no lo va a resolver nadie desde
     * el móvil. Se queda en el servidor, donde se consulta cuando hace
     * falta. */
    $dias = (int)Permisos::ajuste('dias_historico', 365);
    $corte = $dias > 0 ? gmdate('Y-m-d H:i:s', time() - $dias * 86400) : null;

    // A los abiertos se les da margen: el doble del histórico, para que
    // uno viejo pero vivo siga estando a mano.
    $corteAbiertos = $dias > 0 ? gmdate('Y-m-d H:i:s', time() - $dias * 2 * 86400) : null;

    $where  = ['t.updated_at > :desde'];
    $params = ['desde' => $desde];
    if ($corte !== null) {
        $where[] = '((t.fecha_creado >= :corte)
                     OR (COALESCE(e.es_final,0) = 0 AND t.fecha_creado >= :corte_abiertos))';
        $params['corte'] = $corte;
        $params['corte_abiertos'] = $corteAbiertos;
    }
    $sqlBase = ' WHERE ' . implode(' AND ', $where);

    // Se piden los cambios sin filtrar y se separan en dos: los que esta
    // persona puede ver, y los que no. Estos últimos viajan sólo como
    // identificador para que el móvil borre su copia: un parte que deja
    // de ser visible no puede quedarse cacheado en el aparato.
    $todos = Db::all(
        Repo::selectTicket() . $sqlBase . ' ORDER BY t.updated_at ASC LIMIT ' . $limite,
        $params
    );

    [$sqlVis, $pVis] = Permisos::filtroVisibilidad($u);
    $visibles = [];
    if ($sqlVis === '') {
        $visibles = array_column($todos, 'id_ticket');
    } else {
        $ids = array_map('intval', array_column($todos, 'id_ticket'));
        if ($ids) {
            $filas = Db::all(
                'SELECT t.id_ticket FROM ticket_incidencias t
                   LEFT JOIN estados e ON e.id_estado = t.id_estado
                  WHERE t.id_ticket IN (' . implode(',', $ids) . ') AND ' . $sqlVis,
                $pVis
            );
            $visibles = array_column($filas, 'id_ticket');
        }
    }
    $visibles = array_map('intval', $visibles);
    $setVisibles = array_flip($visibles);

    $retirar = [];
    $tickets = [];
    foreach ($todos as $t) {
        /* Un parte ocultado se retira del móvil, lo pueda ver o no esta
           persona. Antes, a quien lo veía todo —el administrador— se le
           mandaba entero, marcado como oculto, y el móvil lo seguía
           enseñando en sus listas: ocultar no ocultaba nada allí. */
        if ((int)($t['borrado'] ?? 0) === 0 && isset($setVisibles[(int)$t['id_ticket']])) {
            $tickets[] = Repo::normalizaTicket($t);
        } else {
            $retirar[] = (int)$t['id_ticket'];
        }
    }

    /* Y los eliminados del todo desde la última vez. Con «>=», no «>»: si
       se eliminó en el mismo segundo que el último cambio que ya bajó el
       móvil, con «>» se perdería. Mandarlo dos veces no hace daño. */
    if (Db::tablaExiste('sync_bajas')) {
        foreach (Db::all('SELECT id_ticket FROM sync_bajas WHERE fecha >= ?', [$desde]) as $b) {
            $retirar[] = (int)$b['id_ticket'];
        }
        $retirar = array_values(array_unique($retirar));
    }

    // La marca de avance se calcula sobre TODO lo consultado, no sólo
    // sobre lo visible: si no, un parte ajeno reciente bloquearía el
    // avance y la sincronización se quedaría dando vueltas.
    $ultimo = $todos ? end($todos)['updated_at'] : $desde;

    $ids = array_column($tickets, 'id_ticket');
    $historial = [];
    $adjuntos  = [];

    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $historial = Db::all(
            'SELECT h.*, e.nombre_estado AS estado_nuevo, eo.nombre_estado AS estado_anterior
               FROM ticket_incidencias_historial h
               LEFT JOIN estados e  ON e.id_estado  = h.id_estado
               LEFT JOIN estados eo ON eo.id_estado = h.id_estado_old
              WHERE h.id_ticket IN (' . $in . ')
              ORDER BY h.id_historial ASC'
        );
        $adjuntos = Db::all(
            'SELECT id_adjunto, uuid, id_ticket, archivo, nombre_original, mime, tamano, ancho, alto, borrado, fecha_creado
               FROM adjuntos WHERE id_ticket IN (' . $in . ')'
        );
        foreach ($adjuntos as &$a) {
            $a['id_adjunto'] = (int)$a['id_adjunto'];
            $a['id_ticket']  = (int)$a['id_ticket'];
            $a['borrado']    = (int)$a['borrado'];
            $a['url']        = 'uploads/' . $a['id_ticket'] . '/' . $a['archivo'];
        }
        unset($a);
    }

    // Marca para la siguiente llamada: la del último elemento recibido,
    // no la hora actual, para no perder cambios ocurridos entre medias.
    $siguiente = $ultimo;
    $completo  = count($todos) < $limite;

    Http::ok([
        'desde'      => $desde,
        'hasta'      => $siguiente,
        'instalacion'=> Permisos::instalacion(),   // si cambia, el móvil se vacía
        'renovacion' => (string)(Permisos::ajuste('purga_generacion', '') ?? ''),
        'servidor'   => $ahora,
        'completo'   => $completo,          // false ⇒ vuelve a llamar con 'hasta'
        'tickets'    => $tickets,
        'retirar'    => $retirar,      // ya no visibles: el móvil los borra
        'historial'  => $historial,
        'adjuntos'   => $adjuntos,
        'no_leidas'  => (int)Db::value('SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0', [(int)$u['id_login']]),
    ]);
}

/* ================================================================= */
/* POST /api/sync/push                                               */
/* ================================================================= */
if ($accion === 'push' && $metodo === 'POST') {

    $ops = Http::campo('operaciones', []);
    if (!is_array($ops)) {
        throw new ApiError('El campo «operaciones» debe ser una lista.', 422);
    }
    if (count($ops) > 200) {
        throw new ApiError('Envía como mucho 200 operaciones por tanda.', 413);
    }

    $resultados = [];

    foreach ($ops as $op) {
        $idLocal  = $op['id_local'] ?? null;
        $tipo     = (string)($op['tipo'] ?? '');
        $datos    = is_array($op['datos'] ?? null) ? $op['datos'] : [];
        $idTicket = null;

        try {
            switch ($tipo) {

                case 'ticket.crear':
                    $t = Tickets::crear($datos, $u);
                    $resultados[] = [
                        'id_local'  => $idLocal,
                        'ok'        => true,
                        'tipo'      => $tipo,
                        'uuid'      => $t['uuid'] ?? null,
                        'id_ticket' => (int)($t['id_ticket'] ?? 0),
                        'duplicado' => !empty($t['duplicado']),
                        'ticket'    => $t,
                    ];
                    break;

                /* Un mensaje escrito sin cobertura.
                   Lleva su uuid: si el móvil reintenta porque la primera
                   respuesta se perdió, no aparece dos veces. */
                case 'chat.escribir':
                    $idTicket = Http::id($datos['id_ticket'] ?? null);
                    if ($idTicket === null && !empty($datos['uuid_ticket'])) {
                        $ref = Repo::ticketPorUuid((string)$datos['uuid_ticket']);
                        $idTicket = $ref ? (int)$ref['id_ticket'] : null;
                    }
                    if ($idTicket === null) {
                        throw new ApiError('No se ha encontrado el parte de este mensaje.', 404);
                    }
                    $r = Chat::escribir(
                        $idTicket, $u,
                        (string)($datos['texto'] ?? ''),
                        !empty($datos['importante']),
                        isset($datos['uuid']) ? (string)$datos['uuid'] : null);
                    $resultados[] = [
                        'id_local'  => $idLocal,
                        'ok'        => true,
                        'tipo'      => $tipo,
                        'id_ticket' => $idTicket,
                        'duplicado' => !empty($r['duplicado']),
                    ];
                    break;

                case 'ticket.actualizar':
                case 'ticket.movimiento':
                    $idTicket = Http::id($datos['id_ticket'] ?? null);
                    if ($idTicket === null && !empty($datos['uuid_ticket'])) {
                        $ref = Repo::ticketPorUuid((string)$datos['uuid_ticket']);
                        $idTicket = $ref ? (int)$ref['id_ticket'] : null;
                    }
                    if ($idTicket === null) {
                        throw new ApiError('No se ha encontrado el parte de esta operación.', 404);
                    }
                    // Idempotencia: si el movimiento ya está registrado, se omite.
                    $uuidMov = $datos['uuid_movimiento'] ?? null;
                    if (Http::esUuid($uuidMov)) {
                        $ya = Db::value('SELECT id_historial FROM ticket_incidencias_historial WHERE uuid = ?', [$uuidMov]);
                        if ($ya !== null) {
                            $resultados[] = [
                                'id_local' => $idLocal, 'ok' => true, 'tipo' => $tipo,
                                'duplicado' => true, 'id_ticket' => $idTicket,
                                'ticket' => Repo::ticket($idTicket),
                            ];
                            break;
                        }
                    }
                    $t = Tickets::actualizar($idTicket, $datos, $u);
                    $resultados[] = [
                        'id_local' => $idLocal, 'ok' => true, 'tipo' => $tipo,
                        'id_ticket' => $idTicket, 'ticket' => $t,
                    ];
                    break;

                case 'adjunto.subir':
                    $idTicket = Http::id($datos['id_ticket'] ?? null);
                    if ($idTicket === null && !empty($datos['uuid_ticket'])) {
                        $ref = Repo::ticketPorUuid((string)$datos['uuid_ticket']);
                        $idTicket = $ref ? (int)$ref['id_ticket'] : null;
                    }
                    if ($idTicket === null) {
                        throw new ApiError('No se ha encontrado el parte de esta foto.', 404);
                    }
                    $a = Uploads::desdeBase64(
                        $idTicket,
                        (string)($datos['datos'] ?? ''),
                        (string)($datos['nombre'] ?? 'foto.jpg'),
                        (int)$u['id_login'],
                        $datos['uuid'] ?? null
                    );
                    Db::update('ticket_incidencias', ['updated_at' => Db::now()], 'id_ticket = :id', ['id' => $idTicket]);
                    $resultados[] = [
                        'id_local' => $idLocal, 'ok' => true, 'tipo' => $tipo,
                        'id_ticket' => $idTicket, 'adjunto' => $a,
                    ];
                    break;

                default:
                    throw new ApiError('Operación desconocida: ' . $tipo, 422);
            }

        } catch (ApiError $e) {
            Db::rollback();
            $fila = [
                'id_local' => $idLocal, 'ok' => false, 'tipo' => $tipo,
                'error' => $e->getMessage(), 'codigo' => $e->getCode(),
                // 4xx = el envío nunca funcionará tal cual: la app lo aparta.
                // 5xx / red = se reintentará en la siguiente sincronización.
                'reintentar' => $e->getCode() >= 500,
            ];
            // 409 no es un fallo: es que otra persona tocó el parte mientras
            // este móvil estaba sin cobertura. Se devuelve con el detalle de
            // ambas versiones para que el usuario decida.
            if ($e->getCode() === 409 && isset($e->extra['conflicto'])) {
                $fila['conflicto'] = $e->extra['conflicto'];
                $fila['conflicto']['id_ticket'] = $idTicket ?? null;
                $fila['reintentar'] = false;
                if (isset($idTicket)) {
                    $fila['ticket'] = Repo::ticket($idTicket);
                }
            }
            $resultados[] = $fila;
        } catch (Throwable $e) {
            Db::rollback();
            $resultados[] = [
                'id_local' => $idLocal, 'ok' => false, 'tipo' => $tipo,
                'error' => 'Error del servidor al procesar la operación.',
                'codigo' => 500, 'reintentar' => true,
            ];
        }
    }

    Http::ok([
        'resultados'  => $resultados,
        'aceptadas'   => count(array_filter($resultados, static fn($r) => $r['ok'])),
        'conflictos'  => count(array_filter($resultados, static fn($r) => isset($r['conflicto']))),
        'servidor'    => Db::now(),
    ]);
}

throw new ApiError('Ruta de sincronización no encontrada.', 404);
