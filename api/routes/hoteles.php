<?php
/** Rutas /api/hoteles */
declare(strict_types=1);

/* Esta ruta borra partes al restablecer un hotel, y para eso usa la
   limpieza de Tickets. No se carga sola: index.php sólo trae las clases
   comunes, y la de partes la pide su propia ruta. Sin esto, restablecer
   fallaba en todos los partes con un «Class "Tickets" not found» que se
   quedaba en el registro sin que nadie lo viera. */
require_once __DIR__ . '/../lib/Tickets.php';

$u = Auth::exigir();
$accion = $partes[1] ?? '';

/* GET /api/hoteles — los que esta persona puede ver */
if ($accion === '' && $metodo === 'GET') {
    Http::ok([
        'hoteles'       => Hoteles::listar($u),
        'actual'        => Hoteles::actual($u),
        'es_superadmin' => Hoteles::esSuperadmin($u),
        // Si no lleva la cadena, no hay nada que cambiar: sólo tiene el suyo.
        'puede_cambiar' => (int)($u['superadmin'] ?? 0) === 1,
    ]);
}

/* A partir de aquí, sólo quien lleva la cadena.
 *
 * Crear hoteles, cambiarse a otro o copiar ubicaciones entre ellos son
 * cosas de cadena, no de un hotel. El administrador del Benalma manda en
 * el Benalma; el de al lado no es asunto suyo. */
if ((int)($u['superadmin'] ?? 0) !== 1) {
    throw new ApiError('Sólo quien lleva la cadena puede gestionar hoteles.', 403);
}

if ($accion === '' && $metodo === 'POST') {
    $id = Hoteles::crear(Http::body(), (int)$u['id_login']);

    // Las instrucciones también se copian si venían del mismo origen:
    // saber purgar un circuito vale igual en los dos hoteles.
    $desde = Http::id(Http::campo('copiar_de'));
    if ($desde !== null && Http::bool(Http::campo('copiar_instrucciones'))) {
        Hoteles::copiarInstrucciones($desde, $id, (int)$u['id_login']);
    }

    Http::ok(['id_hotel' => $id, 'hotel' => Hoteles::uno($id)], 201);
}

$id = Http::id($accion);

if ($id !== null && $metodo === 'GET') {
    $h = Hoteles::uno($id);
    if ($h === null) {
        throw new ApiError('Ese hotel no existe.', 404);
    }
    Http::ok(['hotel' => $h]);
}

if ($id !== null && $metodo === 'PATCH') {
    $datos = ['updated_at' => Db::now()];
    foreach (['nombre', 'codigo', 'direccion', 'ciudad', 'telefono', 'color', 'notas'] as $c) {
        if (array_key_exists($c, Http::body())) {
            $datos[$c] = mb_substr((string)Http::campo($c), 0, 200) ?: null;
        }
    }
    if (array_key_exists('activo', Http::body())) {
        $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0;
    }
    Db::update('hoteles', $datos, 'id_hotel = :id', ['id' => $id]);
    Registro::anotar('ajuste', (int)$u['id_login'], 'Hotel #' . $id . ' modificado');
    Http::ok(['hotel' => Hoteles::uno($id)]);
}

/* POST /api/hoteles/{id}/copiar — traer ubicaciones de otro */
if ($id !== null && ($partes[2] ?? '') === 'copiar' && $metodo === 'POST') {
    $desde = Http::id(Http::requerido('desde'));
    if ($desde === null || !Hoteles::existe($desde)) {
        throw new ApiError('El hotel de origen no existe.', 422, ['campo' => 'desde']);
    }
    $n = Hoteles::copiarUbicaciones($desde, $id);
    $ni = Http::bool(Http::campo('instrucciones'))
        ? Hoteles::copiarInstrucciones($desde, $id, (int)$u['id_login'])
        : 0;

    Registro::anotar('ajuste', (int)$u['id_login'],
        "Copiadas $n ubicaciones y $ni instrucciones del hotel #$desde al #$id");

    Http::ok([
        'ubicaciones' => $n,
        'instrucciones' => $ni,
        'mensaje' => "Copiadas $n ubicaciones"
            . ($ni ? " y $ni instrucciones" : '')
            . '. Los números de serie no se copian: cada aparato está donde está.',
    ]);
}

if ($id !== null && $metodo === 'DELETE') {
    $h = Hoteles::uno($id);
    if ($h === null) {
        throw new ApiError('Ese hotel no existe.', 404);
    }
    if ($h['partes'] > 0 || $h['personas'] > 0) {
        // No se borra un hotel con datos: se desactiva. Borrarlo se
        // llevaría por delante partes y personas sin vuelta atrás.
        Db::update('hoteles', ['activo' => 0, 'updated_at' => Db::now()],
                   'id_hotel = :id', ['id' => $id]);
        Http::ok([
            'mensaje' => "Ese hotel tiene {$h['partes']} partes y {$h['personas']} personas: "
                       . 'se ha desactivado en vez de borrarlo.',
            'desactivado' => true,
        ]);
    }
    /* Un hotel sin partes ni personas se borra de verdad, pero antes hay
       que soltar lo que cuelga de él: sus ubicaciones, los departamentos
       que tenía asignados y los aparatos con número de serie. Sin esto
       la clave foránea rechazaba el borrado y salía un «no se ha podido
       completar la operación» que no explicaba nada. */
    Db::begin();
    try {
        if (Db::tablaExiste('hotel_departamento')) {
            Db::run('DELETE FROM hotel_departamento WHERE id_hotel = ?', [$id]);
        }
        Db::run('DELETE FROM rel_zonas WHERE id_hotel = ?', [$id]);
        foreach (['zona_4', 'zona_3', 'zona_2', 'zona_1'] as $z) {
            if (Db::tablaExiste($z)) {
                Db::run("DELETE FROM $z WHERE id_hotel = ?", [$id]);
            }
        }
        foreach (['unidades_mov', 'unidades', 'instrucciones', 'mantenimientos'] as $t) {
            if (!Db::tablaExiste($t)) {
                continue;
            }
            // unidades_mov no tiene hotel: cuelga de la unidad.
            if ($t === 'unidades_mov') {
                Db::run('DELETE FROM unidades_mov WHERE id_unidad IN
                         (SELECT id_unidad FROM unidades WHERE id_hotel = ?)', [$id]);
                continue;
            }
            Db::run("DELETE FROM $t WHERE id_hotel = ?", [$id]);
        }
        Db::run('DELETE FROM hoteles WHERE id_hotel = ?', [$id]);
        Db::commit();
    } catch (Throwable $e) {
        Db::rollback();
        throw new ApiError('No se ha podido borrar el hotel: ' . $e->getMessage(), 500);
    }

    Registro::anotar('borrado', (int)$u['id_login'], 'Hotel eliminado: ' . $h['nombre']);
    Http::ok(['mensaje' => 'Hotel eliminado con sus ubicaciones.', 'desactivado' => false]);
}

/* POST /api/hoteles/{id}/restablecer
   Vaciar un hotel: partes, personas o las dos cosas.

   Todo pasa por la papelera antes de borrarse. Es una operación con la
   que se puede hacer mucho daño, así que:

     · hay que decir qué se borra, con marcas separadas
     · hay que escribir el nombre del hotel para confirmarlo
     · los administradores nunca se borran, aunque se pida
     · queda anotado en el registro de seguridad

   Lo que NO se toca: las ubicaciones, los tipos de avería, las
   instrucciones ni los aparatos con número de serie. Eso es la
   estructura del hotel, no sus datos de uso; montarla otra vez costaría
   días y casi nunca es lo que se quiere. */
if ($id !== null && ($partes[2] ?? '') === 'restablecer' && $metodo === 'POST') {
    $h = Hoteles::uno($id);
    if ($h === null) {
        throw new ApiError('Ese hotel no existe.', 404);
    }

    // Confirmación escrita: el nombre del hotel, tal cual.
    $escrito = trim((string)Http::campo('confirmacion', ''));
    if (mb_strtolower($escrito) !== mb_strtolower((string)$h['nombre'])) {
        throw new ApiError(
            'Para confirmar, escribe el nombre del hotel exactamente: ' . $h['nombre'],
            422, ['campo' => 'confirmacion']);
    }

    $borrarPartes   = Http::bool(Http::campo('partes'));
    $borrarUsuarios = Http::bool(Http::campo('usuarios'));

    /* Lo demás que se puede vaciar.
     *
     * Cada cosa va suelta a propósito: vaciar las conversaciones no es lo
     * mismo que tirar el registro de seguridad, y meterlo todo en un
     * mismo interruptor haría que alguien borrara la auditoría sin
     * querer. */
    $extras = [];
    foreach (['avisos', 'mensajes', 'mantenimientos', 'errores',
              'seguridad', 'preferencias', 'papelera'] as $k) {
        $extras[$k] = Http::bool(Http::campo($k));
    }

    if (!$borrarPartes && !$borrarUsuarios && !array_filter($extras)) {
        throw new ApiError('No has marcado nada que borrar.', 422);
    }

    $nPartes = 0;

    $fallidos = [];
    $nUsuarios = 0;
    $conservados = 0;
    $vaciado = [];     // qué se ha vaciado y cuántas filas

    /* Quién es de este hotel. Los avisos, las conversaciones y las
       preferencias cuelgan de la persona, no del hotel, así que hay que
       pasar por ahí para no tocar los de otro hotel de la cadena. */
    $deEsteHotel = static function () use ($id): array {
        return array_map('intval', array_column(
            Db::all('SELECT id_login FROM login WHERE id_hotel = ?', [$id]), 'id_login'));
    };

    /* Un borrado contado y que nunca tumba el resto.
       Si una de estas tablas da problemas, se apunta y se sigue con las
       demás: dejar el restablecer a medias sin decirlo es el fallo que ya
       tuvimos una vez. */
    $vaciar = static function (string $etiqueta, string $sql, array $args = [])
                              use (&$vaciado, &$fallidos): void {
        try {
            $n = Db::run($sql, $args)->rowCount();
            $vaciado[$etiqueta] = ($vaciado[$etiqueta] ?? 0) + $n;
        } catch (Throwable $e) {
            $fallidos[] = $etiqueta;
            Errores::apuntar('servidor',
                "Al restablecer, no se pudo vaciar $etiqueta: " . $e->getMessage(),
                $e->getTraceAsString(), 'hoteles/restablecer');
        }
    };

    if ($borrarPartes) {
        $ids = Db::all('SELECT id_ticket FROM ticket_incidencias WHERE id_hotel = ?', [$id]);
        foreach ($ids as $fila) {
            $idT = (int)$fila['id_ticket'];
            Db::begin();
            try {
                Archivo::guardarTicket($idT, 'restablecer ' . $h['nombre']);
                // La misma limpieza que al borrar un parte suelto, para que
                // restablecer no se quede a medias con los que tienen
                // conversación u observadores.
                Tickets::borrarDependencias($idT);
                Db::run('DELETE FROM ticket_incidencias WHERE id_ticket = ?', [$idT]);
                Db::commit();
                $nPartes++;
            } catch (Throwable $e) {
                /* Antes esto se tragaba el error y el hotel quedaba a medio
                   limpiar sin que nadie se enterara. Ahora queda apuntado. */
                Db::rollback();
                $fallidos[] = $idT;
                Errores::apuntar('servidor',
                    'No se pudo borrar el parte ' . $idT . ' al restablecer: ' . $e->getMessage(),
                    $e->getTraceAsString(), 'hoteles/restablecer');
            }
        }
    }

    if ($borrarUsuarios) {
        /* Los administradores se quedan siempre.
           Sin ellos no habría quien entrara a arreglar nada, y quien
           lanza esto normalmente no piensa en eso. */
        $gente = Db::all(
            'SELECT id_login, correo, admin, superadmin, rol FROM login WHERE id_hotel = ?', [$id]);

        foreach ($gente as $g) {
            $idU = (int)$g['id_login'];
            $esAdmin = (int)$g['admin'] === 1 || (int)$g['superadmin'] === 1;
            if ($esAdmin || $idU === (int)$u['id_login']) {
                $conservados++;
                continue;
            }
            Db::begin();
            try {
                Archivo::guardarUsuario($idU, 'restablecer ' . $h['nombre']);

                Db::run('UPDATE ticket_incidencias SET id_persona_asignada = NULL WHERE id_persona_asignada = ?', [$idU]);
                Db::run('UPDATE ticket_incidencias SET id_realiza_accion = ? WHERE id_realiza_accion = ?',
                        [(int)$u['id_login'], $idU]);
                foreach (['api_tokens', 'notificaciones', 'avisos_pref', 'preferencias', 'recuperaciones'] as $t) {
                    if (Db::tablaExiste($t)) { Db::run("DELETE FROM $t WHERE id_login = ?", [$idU]); }
                }
                if (Db::tablaExiste('recuperaciones')) {
                    Db::run('UPDATE recuperaciones SET id_emisor = NULL WHERE id_emisor = ?', [$idU]);
                }
                foreach ([
                    'ticket_incidencias_historial' => ['id_realiza_accion'],
                    'adjuntos'                     => ['id_realiza_accion'],
                    'copias'                       => ['id_login'],
                ] as $t => $cols) {
                    if (!Db::tablaExiste($t)) { continue; }
                    foreach ($cols as $c) {
                        Db::run("UPDATE $t SET $c = ? WHERE $c = ?", [(int)$u['id_login'], $idU]);
                    }
                }
                foreach ([
                    'ticket_incidencias_historial' => ['id_login', 'id_login_old'],
                    'informes' => ['id_login'], 'atajos' => ['id_login'],
                    'registro_seguridad' => ['id_login'], 'mantenimientos' => ['id_persona'],
                    'instrucciones' => ['id_autor'], 'unidades' => ['id_alta'],
                    'unidades_mov' => ['id_login'],
                ] as $t => $cols) {
                    if (!Db::tablaExiste($t)) { continue; }
                    foreach ($cols as $c) {
                        Db::run("UPDATE $t SET $c = NULL WHERE $c = ?", [$idU]);
                    }
                }
                Db::run('DELETE FROM login WHERE id_login = ?', [$idU]);
                Db::commit();
                $nUsuarios++;
            } catch (Throwable $e) {
                Db::rollback();
            }
        }
    }

    /* ------------------------------------------------------------------
       Lo demás. Va DESPUÉS de los partes y las personas a propósito: al
       borrar un parte ya se van sus avisos y su conversación, así que lo
       que quede aquí es lo que sobra de verdad —incluidos los huérfanos
       que dejaron versiones anteriores—.
       ------------------------------------------------------------------ */
    $gente = $deEsteHotel();
    $huecos = $gente ? implode(',', array_fill(0, count($gente), '?')) : '';

    if ($extras['avisos'] && Db::tablaExiste('notificaciones')) {
        /* Incluye los que se quedaron sin parte. Son los que decían «2
           mensajes» y no había forma de quitarlos. */
        if ($gente) {
            $vaciar('avisos', "DELETE FROM notificaciones WHERE id_login IN ($huecos)", $gente);
        }
        $vaciar('avisos', 'DELETE FROM notificaciones WHERE id_ticket IS NOT NULL
                             AND id_ticket NOT IN (SELECT id_ticket FROM ticket_incidencias)');
    }

    if ($extras['mensajes'] && Db::tablaExiste('chat_mensajes')) {
        // Las lecturas primero: cuelgan del mensaje, no del parte.
        if (Db::tablaExiste('chat_lecturas')) {
            $vaciar('conversaciones', 'DELETE FROM chat_lecturas WHERE id_mensaje IN
                      (SELECT m.id_mensaje FROM chat_mensajes m
                        LEFT JOIN ticket_incidencias t ON t.id_ticket = m.id_ticket
                       WHERE t.id_hotel = ? OR t.id_ticket IS NULL)', [$id]);
        }
        $vaciar('conversaciones', 'DELETE FROM chat_mensajes WHERE id_ticket IN
                  (SELECT id_ticket FROM ticket_incidencias WHERE id_hotel = ?)', [$id]);
        $vaciar('conversaciones', 'DELETE FROM chat_mensajes
                  WHERE id_ticket NOT IN (SELECT id_ticket FROM ticket_incidencias)');
    }

    /* El registro de errores se vacía LO PRIMERO de todo.
     *
     * Al revés —como lo tenía— un fallo en cualquiera de los pasos de
     * abajo quedaba apuntado y acto seguido lo borraba este mismo paso:
     * el restablecer se quedaba a medias y no había ni rastro de por qué.
     * Me pasó en la primera prueba, con las revisiones. */
    if ($extras['errores'] && Db::tablaExiste('errores')) {
        $vaciar('errores', 'DELETE FROM errores WHERE id_hotel = ? OR id_hotel IS NULL', [$id]);
    }

    if ($extras['mantenimientos'] && Db::tablaExiste('mantenimientos')) {
        /* Antes de borrar una revisión hay que soltar lo que la apunte.
         *
         * No basta con el vínculo hacia el parte: los partes GENERADOS
         * por una revisión la apuntan a ella («id_mantenimiento»), y esa
         * clave foránea rechazaba el borrado. El restablecer se quedaba a
         * medias sin decir nada: la lista de revisiones seguía intacta.
         *
         * Se pregunta al esquema en vez de escribirlo a mano, para que
         * esto no se quede corto la próxima vez que algo apunte aquí. */
        foreach (Db::dependenciasDe('mantenimientos') as $dep) {
            if ($dep['obligatoria']) { continue; }   // ésas ya mueren con su padre
            $vaciar('revisiones',
                    "UPDATE {$dep['tabla']} SET {$dep['columna']} = NULL
                      WHERE {$dep['columna']} IN (SELECT id_mantenimiento
                                                    FROM mantenimientos WHERE id_hotel = ?)",
                    [$id]);
        }
        // Soltar referencias no es borrar: el contador empieza aquí.
        $vaciado['revisiones'] = 0;
        $vaciar('revisiones', 'DELETE FROM mantenimientos WHERE id_hotel = ?', [$id]);
    }

    if ($extras['seguridad'] && Db::tablaExiste('registro_seguridad') && $gente) {
        $vaciar('registro de seguridad',
                "DELETE FROM registro_seguridad WHERE id_login IN ($huecos)", $gente);
    }

    if ($extras['preferencias'] && $gente) {
        /* Lo que cada persona se ha configurado: su barra, sus avisos, sus
           atajos, sus informes guardados. No se tocan sus permisos ni su
           departamento: eso es cómo trabaja, no una preferencia. */
        foreach (['preferencias', 'avisos_pref', 'atajos', 'informes', 'frases_leidas'] as $t) {
            if (Db::tablaExiste($t)) {
                $vaciar('preferencias', "DELETE FROM $t WHERE id_login IN ($huecos)", $gente);
            }
        }
    }

    /* La papelera, al final del todo.
       Es donde acaba de dejar las cosas todo lo anterior, así que vaciarla
       antes no serviría de nada. */
    if ($extras['papelera'] && class_exists('Archivo')) {
        try {
            $vaciado['papelera'] = (int)Archivo::vaciar((int)$u['id_login']);
        } catch (Throwable $e) {
            $fallidos[] = 'papelera';
            Errores::apuntar('servidor', 'No se pudo vaciar la papelera al restablecer: '
                             . $e->getMessage(), $e->getTraceAsString(), 'hoteles/restablecer');
        }
    }

    $detalle = [];
    foreach ($vaciado as $que => $n) {
        if ($n > 0) { $detalle[] = "$n de $que"; }
    }

    Registro::anotar('borrado', (int)$u['id_login'],
        "Restablecer «{$h['nombre']}»: $nPartes partes y $nUsuarios personas a la papelera"
        . ($detalle ? '; también ' . implode(', ', $detalle) : ''));

    $trozos = [];
    if ($borrarPartes)   { $trozos[] = "$nPartes partes"; }
    if ($borrarUsuarios) { $trozos[] = "$nUsuarios personas"; }

    foreach ($vaciado as $que => $n) {
        if ($n > 0) { $trozos[] = "$n de $que"; }
    }

    Http::ok([
        'partes'      => $nPartes,
        'usuarios'    => $nUsuarios,
        'conservados' => $conservados,
        'vaciado'     => $vaciado,
        'fallidos'    => array_values(array_unique($fallidos)),
        'mensaje'     => ($trozos ? implode(', ', $trozos) : 'Nada que borrar')
                       . ($borrarPartes || $borrarUsuarios ? '. Lo borrado está en la papelera' : '')
                       . ($conservados ? ". Se han conservado $conservados administradores" : '')
                       . ($fallidos ? '. OJO: no se pudo con '
                          . implode(', ', array_unique(array_map('strval', $fallidos)))
                          . ' — mira Administración → Errores' : '')
                       . '.',
    ]);
}

throw new ApiError('Ruta de hoteles no encontrada.', 404);
