<?php
/**
 * La conversación de un parte.
 *
 * El seguimiento cuenta lo que se ha hecho; esto sirve para hablar con
 * quien lo abrió. El caso de siempre: recepción avisa de que la caja
 * fuerte de la 801 no va, el técnico sube y resulta que el cliente no
 * sabía usarla. Si eso no vuelve a recepción, el cliente se queja otra
 * vez y el parte se cierra sin haber resuelto nada.
 *
 * Dos decisiones que marcan cómo funciona todo esto:
 *
 * 1. El acuse de lectura importa tanto como el mensaje. Sin saber si
 *    recepción lo ha leído, «ya te lo dije» no sirve de nada.
 *
 * 2. Quien debe leer algo no tiene por qué entrar en el parte a
 *    buscarlo. Un mensaje marcado como importante sale en su inicio,
 *    que es la única pantalla que todo el mundo mira.
 */
declare(strict_types=1);

final class Chat
{
    /**
     * Quién participa en la conversación de un parte.
     *
     * No se pregunta a nadie: se deduce. Quien lo abrió —que es quien
     * espera respuesta—, quien lo tiene asignado, y quien ya haya
     * escrito. Así no hay que mantener una lista a mano, que es lo que
     * siempre acaba desactualizado.
     */
    public static function participantes(int $idTicket): array
    {
        $t = Repo::ticket($idTicket);
        if ($t === null) { return []; }

        $ids = [];
        foreach (['id_realiza_accion', 'id_persona_asignada'] as $campo) {
            $v = Http::id($t[$campo] ?? null);
            if ($v !== null) { $ids[$v] = true; }
        }
        foreach (Db::all('SELECT DISTINCT id_login FROM chat_mensajes
                           WHERE id_ticket = ? AND borrado = 0', [$idTicket]) as $f) {
            $ids[(int)$f['id_login']] = true;
        }

        /* Y quien lo sigue sin trabajarlo.
         *
         * Un observador está ahí para enterarse: si recepción sigue el
         * parte de la caja fuerte y el técnico escribe «no era avería»,
         * dejarla fuera de la conversación vacía el sentido de haberla
         * puesto. De un departamento observador entran sus responsables,
         * y si no los hay, todos: alguien tiene que leerlo. */
        if (Db::tablaExiste('ticket_observadores')) {
            foreach (Db::all('SELECT id_login, id_departamento FROM ticket_observadores
                               WHERE id_ticket = ?', [$idTicket]) as $o) {
                $idPersona = Http::id($o['id_login']);
                if ($idPersona !== null) {
                    $ids[$idPersona] = true;
                    continue;
                }
                $idDepto = Http::id($o['id_departamento']);
                if ($idDepto === null) { continue; }

                $deDepto = Db::all("SELECT id_login FROM login
                                     WHERE id_departamento = ? AND bloqueo = 0
                                       AND rol IN ('responsable', 'admin')", [$idDepto]);
                if (!$deDepto) {
                    $deDepto = Db::all('SELECT id_login FROM login
                                         WHERE id_departamento = ? AND bloqueo = 0
                                         LIMIT 20', [$idDepto]);
                }
                foreach ($deDepto as $f) { $ids[(int)$f['id_login']] = true; }
            }
        }

        if (!$ids) { return []; }

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        return Db::all(
            'SELECT id_login, ' . Db::nombreCompleto('login') . ' AS nombre, rol, id_departamento
               FROM login WHERE id_login IN (' . $marcas . ') AND bloqueo = 0',
            array_keys($ids));
    }

    /**
     * Los mensajes de un parte, con quién ha leído cada uno.
     *
     * Se devuelven todos de una vez: una conversación sobre una avería
     * tiene diez mensajes, no diez mil, y paginar aquí sólo complicaría
     * la pantalla.
     */
    public static function mensajes(int $idTicket, int $idLogin): array
    {
        $filas = Db::all(
            'SELECT m.id_mensaje, m.uuid, m.texto, m.importante, m.creado,
                    m.id_login, ' . Db::nombreCompleto('l') . ' AS autor, l.rol AS rol_autor,
                    d.nombre AS departamento_autor
               FROM chat_mensajes m
               LEFT JOIN login l ON l.id_login = m.id_login
               LEFT JOIN departamentos d ON d.id_departamento = l.id_departamento
              WHERE m.id_ticket = ? AND m.borrado = 0
              ORDER BY m.creado, m.id_mensaje',
            [$idTicket]);

        if (!$filas) { return []; }

        /* Las lecturas de todos los mensajes en una sola consulta: una
           por mensaje serían veinte consultas para abrir un chat. */
        $ids = array_column($filas, 'id_mensaje');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $lecturas = [];
        foreach (Db::all(
            'SELECT le.id_mensaje, le.id_login, le.leido, ' . Db::nombreCompleto('l') . ' AS nombre
               FROM chat_lecturas le
               LEFT JOIN login l ON l.id_login = le.id_login
              WHERE le.id_mensaje IN (' . $marcas . ')', $ids) as $l) {
            $lecturas[(int)$l['id_mensaje']][] = [
                'id_login' => (int)$l['id_login'],
                'nombre'   => $l['nombre'],
                'leido'    => $l['leido'],
            ];
        }

        foreach ($filas as &$f) {
            $f['id_mensaje'] = (int)$f['id_mensaje'];
            $f['id_login']   = (int)$f['id_login'];
            $f['importante'] = (int)$f['importante'] === 1;
            $f['mio']        = $f['id_login'] === $idLogin;
            $f['leido_por']  = array_values(array_filter(
                $lecturas[$f['id_mensaje']] ?? [],
                static fn($l) => $l['id_login'] !== $f['id_login']));   // leerse uno mismo no cuenta
        }
        unset($f);

        return $filas;
    }

    /**
     * Escribir.
     *
     * Avisa a los demás participantes. Si va marcado como importante,
     * además queda pendiente en su inicio hasta que lo lean.
     */
    public static function escribir(int $idTicket, array $u, string $texto, bool $importante, ?string $uuid = null): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            throw new ApiError('El mensaje está vacío.', 422, ['campo' => 'texto']);
        }
        if (mb_strlen($texto) > 2000) {
            throw new ApiError('El mensaje es demasiado largo (máximo 2000 letras).', 422, ['campo' => 'texto']);
        }

        $t = Repo::ticket($idTicket);
        if ($t === null) {
            throw new ApiError('El parte no existe.', 404);
        }
        if (!Permisos::puedeVer($u, $t)) {
            throw new ApiError('No tienes permiso para escribir en este parte.', 403);
        }

        /* Mismo uuid, mismo mensaje: al reintentar desde un móvil con mala
           cobertura no debe aparecer dos veces. */
        if ($uuid) {
            $ya = Db::one('SELECT id_mensaje FROM chat_mensajes WHERE uuid = ?', [$uuid]);
            if ($ya) {
                return ['id_mensaje' => (int)$ya['id_mensaje'], 'duplicado' => true];
            }
        }

        $id = Db::insert('chat_mensajes', [
            'uuid'       => $uuid ?: Http::uuid(),
            'id_ticket'  => $idTicket,
            'id_login'   => (int)$u['id_login'],
            'texto'      => mb_substr($texto, 0, 2000),
            'importante' => $importante ? 1 : 0,
            'id_hotel'   => Http::id($t['id_hotel'] ?? null),
            'creado'     => Db::now(),
            'updated_at' => Db::now(),
        ]);

        /* Quien escribe ya lo ha leído: si no, su propio mensaje le
           saldría como pendiente. */
        Db::insertarSiFalta('chat_lecturas', [
            'id_mensaje' => $id, 'id_login' => (int)$u['id_login'], 'leido' => Db::now(),
        ]);

        self::avisar($idTicket, $id, $u, $texto, $importante, $t);

        return ['id_mensaje' => $id, 'duplicado' => false];
    }

    /** A quién le llega y cómo. */
    private static function avisar(int $idTicket, int $idMensaje, array $u, string $texto, bool $importante, array $t): void
    {
        $codigo = Repo::codigo($idTicket);
        $quien  = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido1'] ?? '')) ?: 'Alguien';
        $titulo = ($importante ? 'Mensaje importante · ' : 'Mensaje · ') . $codigo;
        $cuerpo = $quien . ': ' . mb_substr($texto, 0, 120);

        foreach (self::participantes($idTicket) as $p) {
            if ((int)$p['id_login'] === (int)$u['id_login']) { continue; }
            Repo::notificar((int)$p['id_login'], $idTicket, $titulo, $cuerpo,
                            (int)$u['id_login'], 'chat');
        }
    }

    /**
     * Marcar como leídos los mensajes de un parte.
     *
     * Se marcan todos los que esa persona no había leído: en una
     * conversación abierta, si estás mirándola, los has visto.
     */
    public static function marcarLeido(int $idTicket, int $idLogin): int
    {
        $pendientes = Db::all(
            'SELECT m.id_mensaje FROM chat_mensajes m
              WHERE m.id_ticket = ? AND m.borrado = 0 AND m.id_login <> ?
                AND NOT EXISTS (SELECT 1 FROM chat_lecturas le
                                 WHERE le.id_mensaje = m.id_mensaje AND le.id_login = ?)',
            [$idTicket, $idLogin, $idLogin]);

        foreach ($pendientes as $m) {
            Db::insertarSiFalta('chat_lecturas', [
                'id_mensaje' => (int)$m['id_mensaje'],
                'id_login'   => $idLogin,
                'leido'      => Db::now(),
            ]);
        }

        /* Y sus avisos. Cada mensaje deja además un aviso «Mensaje · T-…»
           en la campana, y leer la conversación no lo tocaba: quien había
           leído los seis mensajes de Fany seguía con seis avisos sin leer,
           la campana en rojo y «Avisos · N» contando lo ya visto. Sólo los
           avisos de CHAT de este parte y de esta persona: un cambio de
           estado del mismo parte no se ha leído por abrir la conversación. */
        Db::run("UPDATE notificaciones SET leida = 1, updated_at = ?
                  WHERE id_login = ? AND id_ticket = ? AND evento = 'chat' AND leida = 0",
                [Db::now(), $idLogin, $idTicket]);

        return count($pendientes);
    }

    /**
     * Lo que esta persona tiene sin leer.
     *
     * Es lo que sale en el inicio. Sin esto, recepción tendría que ir
     * abriendo partes uno a uno para ver si alguien le ha contestado, y
     * no lo va a hacer: son demasiados.
     */
    public static function pendientes(array $u, int $limite = 10): array
    {
        if (!Db::tablaExiste('chat_mensajes')) {
            return ['total' => 0, 'importantes' => 0, 'mensajes' => []];
        }

        $idLogin = (int)$u['id_login'];
        $dep = $u['id_departamento'] === null ? 0 : (int)$u['id_departamento'];

        /* Sólo de los partes en los que participa: un mensaje de un parte
           ajeno no es asunto suyo aunque tenga permiso para verlo. */
        $hayObservadores = Db::tablaExiste('ticket_observadores');

        $sql = 'SELECT m.id_mensaje, m.id_ticket, m.texto, m.importante, m.creado,
                       ' . Db::nombreCompleto('l') . ' AS autor,
                       t.titulo_ticket, t.id_estado, r.ruta
                  FROM chat_mensajes m
                  JOIN ticket_incidencias t ON t.id_ticket = m.id_ticket
                  LEFT JOIN login l ON l.id_login = m.id_login
                  LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
                 WHERE m.borrado = 0 AND t.borrado = 0
                   AND m.id_login <> :yo
                   AND (t.id_realiza_accion = :yo2 OR t.id_persona_asignada = :yo3
                        OR EXISTS (SELECT 1 FROM chat_mensajes m2
                                    WHERE m2.id_ticket = m.id_ticket AND m2.id_login = :yo4)
                        -- Y los partes que sigue como observador: es donde
                        -- más falta hace enterarse, porque no los trabaja y
                        -- no va a entrar a mirarlos por su cuenta.
                        OR EXISTS (SELECT 1 FROM ticket_observadores o
                                    WHERE o.id_ticket = m.id_ticket
                                      AND (o.id_login = :yo6
                                           OR (o.id_departamento IS NOT NULL
                                               AND o.id_departamento = :yo7))))
                   AND NOT EXISTS (SELECT 1 FROM chat_lecturas le
                                    WHERE le.id_mensaje = m.id_mensaje AND le.id_login = :yo5)
                 ORDER BY m.importante DESC, m.creado DESC';

        $filas = Db::all($sql . ' LIMIT ' . max(1, min(50, $limite)), [
            'yo' => $idLogin, 'yo2' => $idLogin, 'yo3' => $idLogin,
            'yo4' => $idLogin, 'yo5' => $idLogin,
            'yo6' => $idLogin, 'yo7' => $dep,
        ]);

        /* Para las cifras basta contar, no hace falta traerse los
           mensajes otra vez: con veinte mil partes, repetir la consulta
           entera para saber «cuántos» cuesta el doble sin motivo. */
        $cuentas = Db::one(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN m.importante = 1 THEN 1 ELSE 0 END) AS importantes
               FROM (' . $sql . ') m',
            ['yo' => $idLogin, 'yo2' => $idLogin, 'yo3' => $idLogin,
             'yo4' => $idLogin, 'yo5' => $idLogin,
             'yo6' => $idLogin, 'yo7' => $dep]);

        foreach ($filas as &$f) {
            $f['id_mensaje'] = (int)$f['id_mensaje'];
            $f['id_ticket']  = (int)$f['id_ticket'];
            $f['importante'] = (int)$f['importante'] === 1;
            $f['codigo']     = Repo::codigo((int)$f['id_ticket']);
            $f['texto']      = mb_substr((string)$f['texto'], 0, 160);
        }
        unset($f);

        return [
            'total'       => (int)($cuentas['total'] ?? 0),
            'importantes' => (int)($cuentas['importantes'] ?? 0),
            'mensajes'    => $filas,
        ];
    }


    /**
     * Las conversaciones de esta persona, por orden de último mensaje.
     *
     * Para poder volver a una conversación sin acordarse de en qué parte
     * era: igual que en cualquier aplicación de mensajes, que es como la
     * gente espera que funcione esto.
     */
    public static function recientes(array $u, int $limite = 40): array
    {
        if (!Db::tablaExiste('chat_mensajes')) { return []; }

        $yo  = (int)$u['id_login'];
        $dep = (int)($u['id_departamento'] ?? 0);

        $filas = Db::all(
            'SELECT m.id_ticket,
                    MAX(m.creado) AS ultimo,
                    COUNT(*) AS mensajes,
                    SUM(CASE WHEN m.id_login <> :yo1
                              AND NOT EXISTS (SELECT 1 FROM chat_lecturas le
                                               WHERE le.id_mensaje = m.id_mensaje AND le.id_login = :yo2)
                             THEN 1 ELSE 0 END) AS sin_leer
               FROM chat_mensajes m
               JOIN ticket_incidencias t ON t.id_ticket = m.id_ticket
              WHERE m.borrado = 0 AND t.borrado = 0
                AND (t.id_realiza_accion = :yo3 OR t.id_persona_asignada = :yo4
                     OR EXISTS (SELECT 1 FROM chat_mensajes m2
                                 WHERE m2.id_ticket = m.id_ticket AND m2.id_login = :yo5)
                     ' . (Db::tablaExiste('ticket_observadores') ? '
                     OR EXISTS (SELECT 1 FROM ticket_observadores o
                                 WHERE o.id_ticket = m.id_ticket
                                   AND (o.id_login = :yo6 OR o.id_departamento = :dep1))' : '') . ')
              GROUP BY m.id_ticket
              ORDER BY ultimo DESC
              LIMIT ' . max(1, min(100, $limite)),
            Db::tablaExiste('ticket_observadores')
                ? ['yo1' => $yo, 'yo2' => $yo, 'yo3' => $yo, 'yo4' => $yo,
                   'yo5' => $yo, 'yo6' => $yo, 'dep1' => $dep]
                : ['yo1' => $yo, 'yo2' => $yo, 'yo3' => $yo, 'yo4' => $yo, 'yo5' => $yo]);

        if (!$filas) { return []; }

        /* El último mensaje y los datos del parte, de dos consultas para
           todos.
           Antes esto pedía dos consultas MÁS por conversación: con 40
           conversaciones eran más de ochenta viajes a la base para pintar
           una lista. Y es la pantalla que más se abre, así que con 50
           personas dentro se notaba. */
        $ids = array_map(static fn($f) => (int)$f['id_ticket'], $filas);
        $hueco = implode(',', array_fill(0, count($ids), '?'));

        $ultimos = [];
        foreach (Db::all(
            'SELECT m.id_ticket, m.texto, m.importante, m.creado, m.id_mensaje,
                    ' . Db::nombreCompleto('l') . ' AS autor
               FROM chat_mensajes m
               LEFT JOIN login l ON l.id_login = m.id_login
              WHERE m.id_ticket IN (' . $hueco . ') AND m.borrado = 0
              ORDER BY m.id_ticket, m.creado DESC, m.id_mensaje DESC', $ids) as $m) {
            // La primera de cada parte es la más reciente: las demás se tiran.
            $ultimos[(int)$m['id_ticket']] ??= $m;
        }

        $partes = [];
        foreach (Db::all(
            'SELECT t.id_ticket, t.titulo_ticket, t.id_estado, e.nombre_estado, e.color,
                    e.es_final, t.id_persona_asignada
               FROM ticket_incidencias t
               LEFT JOIN estados e ON e.id_estado = t.id_estado
              WHERE t.id_ticket IN (' . $hueco . ')', $ids) as $t) {
            $partes[(int)$t['id_ticket']] = $t;
        }

        $salida = [];
        foreach ($filas as $f) {
            $idTicket = (int)$f['id_ticket'];
            $ultimo = $ultimos[$idTicket] ?? null;
            $t      = $partes[$idTicket] ?? null;

            $salida[] = [
                'id_ticket' => $idTicket,
                'codigo'    => Repo::codigo($idTicket),
                'titulo'    => $t['titulo_ticket'] ?? '',
                'estado'    => $t['nombre_estado'] ?? '',
                // El color y si está cerrado: la ventana de conversaciones
                // los enseña para no tener que abrir el parte y volver.
                'estado_color' => $t['color'] ?? null,
                'es_final'  => (int)($t['es_final'] ?? 0) === 1,
                'mio'       => $t !== null
                               && (int)($t['id_persona_asignada'] ?? 0) === $yo,
                'ultimo'    => $f['ultimo'],
                'mensajes'  => (int)$f['mensajes'],
                'sin_leer'  => (int)$f['sin_leer'],
                'autor'     => $ultimo['autor'] ?? '',
                'texto'     => mb_substr((string)($ultimo['texto'] ?? ''), 0, 120),
                'importante'=> (int)($ultimo['importante'] ?? 0) === 1,
            ];
        }
        return $salida;
    }

    /**
     * Cuántos mensajes tiene sin leer esta persona, en total.
     *
     * Una sola cuenta. Lo pinta la barra de abajo, que va en todas las
     * pantallas, así que esto se pide muchas veces: sacar la lista
     * entera de conversaciones para sumarlas costaría cien veces más
     * por un número.
     */
    public static function sinLeerDe(array $u): int
    {
        if (!Db::tablaExiste('chat_mensajes')) { return 0; }

        $yo  = (int)$u['id_login'];
        $dep = (int)($u['id_departamento'] ?? 0);
        $conObs = Db::tablaExiste('ticket_observadores');

        /* CONVERSACIONES con algo sin leer, no mensajes sueltos.
           Tiene que contar lo mismo que la pantalla de Mensajes: si aquí
           se cuentan mensajes y allí conversaciones, la chapa de la barra
           cambia de número sólo con entrar a mirarla, y eso hace dudar de
           todos los demás números de la aplicación. */
        return (int)Db::value(
            'SELECT COUNT(DISTINCT m.id_ticket)
               FROM chat_mensajes m
               JOIN ticket_incidencias t ON t.id_ticket = m.id_ticket
              WHERE m.borrado = 0 AND t.borrado = 0
                AND m.id_login <> :yo0
                AND NOT EXISTS (SELECT 1 FROM chat_lecturas le
                                 WHERE le.id_mensaje = m.id_mensaje AND le.id_login = :yo1)
                AND (t.id_realiza_accion = :yo2 OR t.id_persona_asignada = :yo3
                     OR EXISTS (SELECT 1 FROM chat_mensajes m2
                                 WHERE m2.id_ticket = m.id_ticket AND m2.id_login = :yo4)'
            . ($conObs ? '
                     OR EXISTS (SELECT 1 FROM ticket_observadores o
                                 WHERE o.id_ticket = m.id_ticket
                                   AND (o.id_login = :yo5 OR o.id_departamento = :dep1))' : '') . ')',
            $conObs
                ? ['yo0' => $yo, 'yo1' => $yo, 'yo2' => $yo, 'yo3' => $yo,
                   'yo4' => $yo, 'yo5' => $yo, 'dep1' => $dep]
                : ['yo0' => $yo, 'yo1' => $yo, 'yo2' => $yo, 'yo3' => $yo, 'yo4' => $yo]);
    }

    /** Cuántos sin leer tiene esta persona en un parte concreto. */
    public static function sinLeerEn(int $idTicket, int $idLogin): int
    {
        if (!Db::tablaExiste('chat_mensajes')) { return 0; }
        return (int)Db::value(
            'SELECT COUNT(*) FROM chat_mensajes m
              WHERE m.id_ticket = ? AND m.borrado = 0 AND m.id_login <> ?
                AND NOT EXISTS (SELECT 1 FROM chat_lecturas le
                                 WHERE le.id_mensaje = m.id_mensaje AND le.id_login = ?)',
            [$idTicket, $idLogin, $idLogin]);
    }
}
