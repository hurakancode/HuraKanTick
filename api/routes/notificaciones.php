<?php
/** Rutas /api/notificaciones */
declare(strict_types=1);

$u  = Auth::exigir();
$yo = (int)$u['id_login'];

/* GET /api/notificaciones/preferencias */
if ($metodo === 'GET' && ($partes[1] ?? '') === 'preferencias') {
    Http::ok(['avisos' => Permisos::avisosDe($yo)]);
}

/* POST /api/notificaciones/preferencias — {evento, activo, sonido} */
if ($metodo === 'POST' && ($partes[1] ?? '') === 'preferencias') {
    $evento = (string)Http::requerido('evento');
    $validos = ['asignado','asignado_grupo','cambio_estado','nota','urgente_nuevo','vencido'];
    if (!in_array($evento, $validos, true)) {
        throw new ApiError('Tipo de aviso desconocido.', 422, ['campo' => 'evento']);
    }
    $activo = Http::bool(Http::campo('activo', true)) ? 1 : 0;
    $sonido = Http::bool(Http::campo('sonido', true)) ? 1 : 0;

    $existe = Db::value('SELECT 1 FROM avisos_pref WHERE id_login = ? AND evento = ?', [$yo, $evento]);
    if ($existe) {
        Db::run('UPDATE avisos_pref SET activo = ?, sonido = ?, updated_at = ? WHERE id_login = ? AND evento = ?',
                [$activo, $sonido, Db::now(), $yo, $evento]);
    } else {
        Db::insert('avisos_pref', [
            'id_login' => $yo, 'evento' => $evento,
            'activo' => $activo, 'sonido' => $sonido, 'updated_at' => Db::now(),
        ]);
    }
    Http::ok(['avisos' => Permisos::avisosDe($yo)]);
}

/* DELETE /api/notificaciones/preferencias — volver a los valores del administrador */
if ($metodo === 'DELETE' && ($partes[1] ?? '') === 'preferencias') {
    Db::run('DELETE FROM avisos_pref WHERE id_login = ?', [$yo]);
    Http::ok(['avisos' => Permisos::avisosDe($yo), 'mensaje' => 'Se han restaurado los avisos por defecto.']);
}

if ($metodo === 'GET') {
    $soloNuevas = Http::bool(Http::query('nuevas'));
    $filas = Db::all(
        'SELECT n.id_notificacion, n.id_ticket, n.titulo, n.cuerpo, n.leida, n.fecha,
                -- De qué es el aviso: los de mensaje abren la conversación,
                -- no la ficha del parte.
                n.evento,
                t.titulo_ticket, e.color AS estado_color
           FROM notificaciones n
           LEFT JOIN ticket_incidencias t ON t.id_ticket = n.id_ticket
           LEFT JOIN estados e ON e.id_estado = t.id_estado
          WHERE n.id_login = ?' . ($soloNuevas ? ' AND n.leida = 0' : '') . '
          ORDER BY n.fecha DESC LIMIT 100',
        [$yo]
    );
    foreach ($filas as &$f) {
        $f['id_notificacion'] = (int)$f['id_notificacion'];
        $f['id_ticket'] = $f['id_ticket'] === null ? null : (int)$f['id_ticket'];
        $f['leida'] = (int)$f['leida'];
    }
    unset($f);

    Http::ok([
        'notificaciones' => $filas,
        'no_leidas' => (int)Db::value('SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0', [$yo]),
    ]);
}

/* POST /api/notificaciones/leidas  — marca todas o una lista */
if ($metodo === 'POST' && ($partes[1] ?? '') === 'leidas') {
    $ids = Http::campo('ids');
    if (is_array($ids) && $ids) {
        $in = implode(',', array_map('intval', $ids));
        Db::run("UPDATE notificaciones SET leida = 1, updated_at = ? WHERE id_login = ? AND id_notificacion IN ($in)", [Db::now(), $yo]);
    } else {
        Db::run('UPDATE notificaciones SET leida = 1, updated_at = ? WHERE id_login = ? AND leida = 0', [Db::now(), $yo]);
    }
    Http::ok(['no_leidas' => (int)Db::value('SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0', [$yo])]);
}

/* DELETE /api/notificaciones/{id} — borrar un aviso */
$idAviso = Http::id($partes[1] ?? null);
if ($idAviso !== null && $metodo === 'DELETE') {
    $suyo = Db::value('SELECT 1 FROM notificaciones WHERE id_notificacion = ? AND id_login = ?',
                      [$idAviso, $yo]);
    if (!$suyo) {
        throw new ApiError('Ese aviso no es tuyo.', 403);
    }
    Db::run('DELETE FROM notificaciones WHERE id_notificacion = ?', [$idAviso]);
    Http::ok(['mensaje' => 'Aviso borrado.']);
}

/* DELETE /api/notificaciones — vaciar
   ?leidas=1 borra sólo las leídas, que es lo que se quiere casi siempre */
if ($metodo === 'DELETE' && ($partes[1] ?? '') === '') {
    $soloLeidas = Http::bool(Http::query('leidas'));
    $n = Db::run(
        'DELETE FROM notificaciones WHERE id_login = ?' . ($soloLeidas ? ' AND leida = 1' : ''),
        [$yo]
    )->rowCount();
    Http::ok(['borrados' => $n]);
}

throw new ApiError('Ruta de notificaciones no encontrada.', 404);
