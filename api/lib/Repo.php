<?php
/**
 * Consultas de negocio. Todo lo que toca varias tablas vive aquí para que
 * las rutas queden cortas y legibles.
 */
declare(strict_types=1);

final class Repo
{
    // -----------------------------------------------------------------
    // Selección base de un ticket con todos sus nombres resueltos
    // -----------------------------------------------------------------
    public static function selectTicket(): string
    {
        $creador  = Db::nombreCompleto('lc');
        $asignado = Db::nombreCompleto('la');

        return "SELECT
                  t.id_ticket, t.uuid, t.titulo_ticket, t.detalle_ticket,
                  t.fecha_creado, t.fecha_limite, t.fecha_cierre, t.updated_at,
                  -- Hasta cuándo está aparcado, si lo está: la pantalla lo
                  -- necesita para no marcarlo como fuera de plazo.
                  t.visible_desde,
                  t.id_estado, t.id_prioridad, t.id_elemento, t.id_familia, t.id_rel_zona,
                  t.id_realiza_accion, t.id_persona_asignada, t.id_grupo_asignado,
                  t.origen, t.minutos_trabajo, t.coste, t.borrado, t.id_version,
                  t.id_mantenimiento, t.id_unidad, un.numero_serie, t.id_hotel,
                  e.nombre_estado, e.color AS estado_color, e.class AS estado_class, e.es_final,
                  p.nombre AS prioridad, p.color AS prioridad_color, p.sla_horas,
                  el.nombre_elemento AS elemento, el.abreviatura_elemento AS elemento_abrev,
                  COALESCE(el.icono, fa.icono) AS elemento_icono,
                  fa.nombre AS familia, fa.clase AS clase, fa.color AS familia_color,
                  fa.icono AS familia_icono,
                  d.nombre AS departamento,
                  r.ruta AS zona_ruta,
                  z1.nombre_zona_1, z2.nombre_zona_2, z3.nombre_zona_3, z4.nombre_zona_4,
                  $creador  AS creado_por,
                  $asignado AS asignado_a,
                  (SELECT COUNT(*) FROM adjuntos a WHERE a.id_ticket = t.id_ticket AND a.borrado = 0) AS n_adjuntos,
                  (SELECT COUNT(*) FROM ticket_incidencias_historial h WHERE h.id_ticket = t.id_ticket) AS n_movimientos
                FROM ticket_incidencias t
                LEFT JOIN estados      e  ON e.id_estado      = t.id_estado
                LEFT JOIN prioridades  p  ON p.id_prioridad   = t.id_prioridad
                LEFT JOIN elemento     el ON el.id_elemento   = t.id_elemento
                LEFT JOIN familias     fa ON fa.id_familia   = COALESCE(t.id_familia, el.id_familia)
                LEFT JOIN unidades     un ON un.id_unidad    = t.id_unidad
                LEFT JOIN departamentos d ON d.id_departamento= t.id_grupo_asignado
                LEFT JOIN rel_zonas    r  ON r.id_rel         = t.id_rel_zona
                LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
                LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
                LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
                LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4
                LEFT JOIN login lc ON lc.id_login = t.id_realiza_accion
                LEFT JOIN login la ON la.id_login = t.id_persona_asignada";
    }

    public static function ticket(int $id): ?array
    {
        $t = Db::one(self::selectTicket() . ' WHERE t.id_ticket = ?', [$id]);
        return $t ? self::normalizaTicket($t) : null;
    }

    public static function ticketPorUuid(string $uuid): ?array
    {
        $t = Db::one(self::selectTicket() . ' WHERE t.uuid = ?', [$uuid]);
        return $t ? self::normalizaTicket($t) : null;
    }

    public static function normalizaTicket(array $t): array
    {
        $t['id_ticket']   = (int)$t['id_ticket'];
        $t['id_estado']   = (int)$t['id_estado'];
        $t['id_prioridad']= (int)$t['id_prioridad'];
        $t['borrado']     = (int)$t['borrado'];
        $t['es_final']    = (int)($t['es_final'] ?? 0);
        $t['n_adjuntos']  = (int)($t['n_adjuntos'] ?? 0);
        $t['n_movimientos'] = (int)($t['n_movimientos'] ?? 0);
        $t['minutos_trabajo'] = (int)($t['minutos_trabajo'] ?? 0);
        $t['id_version']      = (int)($t['id_version'] ?? 1);
        foreach (['id_elemento','id_familia','id_unidad','id_rel_zona','id_persona_asignada','id_grupo_asignado','id_realiza_accion'] as $k) {
            $t[$k] = $t[$k] === null ? null : (int)$t[$k];
        }
        $t['zona_ruta'] = $t['zona_ruta'] ?: self::rutaDesdeNombres($t);
        $t['codigo']    = self::codigo((int)$t['id_ticket']);
        $t['vencido']   = self::estaVencido($t);
        return $t;
    }

    public static function codigo(int $id): string
    {
        return 'T-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    private static function rutaDesdeNombres(array $t): string
    {
        $p = array_filter([
            $t['nombre_zona_1'] ?? null, $t['nombre_zona_2'] ?? null,
            $t['nombre_zona_3'] ?? null, $t['nombre_zona_4'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');
        return implode(' › ', $p);
    }

    private static function estaVencido(array $t): bool
    {
        if (empty($t['fecha_limite']) || (int)($t['es_final'] ?? 0) === 1) {
            return false;
        }
        return strtotime((string)$t['fecha_limite']) < time();
    }

    // -----------------------------------------------------------------
    // Listado con filtros
    // -----------------------------------------------------------------
    /**
     * Una expresión SQL que compara sin acentos.
     *
     * Nadie los escribe en el buscador de un móvil, así que buscar
     * «averia» tiene que encontrar «avería». Se usa en los partes y en
     * las ubicaciones, que es donde se busca de verdad.
     */
    public static function sinAcentos(string $columna): string
    {
        /* En MySQL con COLLATE utf8mb4_unicode_ci esto sobra: la propia
           comparación ya ignora acentos y mayúsculas. Se deja igual
           porque no estorba —sólo repite un trabajo ya hecho— y así hay
           una sola forma de buscar, valga el motor que valga. En SQLite
           es imprescindible: ahí LIKE distingue «avería» de «averia». */
        $sql = "LOWER($columna)";
        foreach (['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u',
                  'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','ñ'=>'n','ç'=>'c'] as $de => $a) {
            $sql = "REPLACE($sql, '$de', '$a')";
        }
        return $sql;
    }

    /** Lo mismo para lo que se escribe. */
    /**
     * El texto de una búsqueda, listo para LIKE.
     *
     * «%» y «_» son comodines en SQL: sin escaparlos, buscar «%» o «_»
     * devolvía todos los partes, y buscar el número de serie «SN_2021»
     * encontraba también «SN-2021», porque «_» vale por cualquier letra.
     * Se escapan con «!», que funciona igual en SQLite y en MySQL —la
     * barra invertida no—, y la consulta debe llevar ESCAPE '!'.
     */
    public static function patron(string $texto): string
    {
        return '%' . strtr($texto, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    public static function planoTexto(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        foreach (['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u',
                  'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','ñ'=>'n','ç'=>'c'] as $de => $a) {
            $t = str_replace($de, $a, $t);
        }
        return $t;
    }

    public static function listarTickets(array $f, array $usuario): array
    {
        $where  = ['t.borrado = 0'];
        $params = [];

        // Visibilidad: nadie ve más de lo que le corresponde. Se aplica
        // aquí y en los informes con la misma función, para que no haya
        // una vía más permisiva que otra.
        [$sqlVis, $pVis] = Permisos::filtroVisibilidad($usuario);
        if ($sqlVis !== '') {
            $where[] = $sqlVis;
            $params += $pVis;
        }

        if (!empty($f['estado'])) {
            $ids = array_values(array_filter(array_map('intval', (array)$f['estado'])));
            if ($ids) {
                $where[] = 't.id_estado IN (' . implode(',', $ids) . ')';
            }
        }
        if (!empty($f['abiertos'])) {
            $where[] = 'COALESCE(e.es_final,0) = 0';
        }
        if (!empty($f['prioridad'])) {
            $ids = array_values(array_filter(array_map('intval', (array)$f['prioridad'])));
            if ($ids) { $where[] = 't.id_prioridad IN (' . implode(',', $ids) . ')'; }
        }
        if (!empty($f['elemento'])) {
            $where[]  = 't.id_elemento = :elemento';
            $params['elemento'] = (int)$f['elemento'];
        }
        if (!empty($f['familia'])) {
            $where[] = 'COALESCE(t.id_familia, el.id_familia) = :familia';
            $params['familia'] = (int)$f['familia'];
        }
        if (!empty($f['clase'])) {
            $clases = array_values(array_filter((array)$f['clase'],
                static fn($c) => in_array($c, ['averia','peticion','tarea'], true)));
            if ($clases) {
                $marcas = [];
                foreach ($clases as $i => $c) { $marcas[] = ":cl$i"; $params["cl$i"] = $c; }
                $where[] = 'fa.clase IN (' . implode(',', $marcas) . ')';
            }
        }
        if (!empty($f['departamento'])) {
            $where[]  = 't.id_grupo_asignado = :dep';
            $params['dep'] = (int)$f['departamento'];
        }
        if (!empty($f['asignado'])) {
            $where[] = 't.id_persona_asignada = :asig';
            $params['asig'] = (int)$f['asignado'];
        }
        if (!empty($f['mios'])) {
            $where[] = 't.id_persona_asignada = :yo';
            $params['yo'] = (int)$usuario['id_login'];
        }
        if (!empty($f['creados_por_mi'])) {
            $where[] = 't.id_realiza_accion = :yo2';
            $params['yo2'] = (int)$usuario['id_login'];
        }
        if (!empty($f['sin_asignar'])) {
            /* «Sin asignar» es «sin nadie que lo haga».
             *
             * Antes exigía además que no tuviera departamento, y desde
             * que el departamento es obligatorio eso no ocurre nunca:
             * la cifra del inicio marcaba 0 permanente mientras había
             * partes esperando a que alguien los cogiera. El
             * departamento dice de quién es el trabajo; la persona, si
             * alguien se ha hecho cargo, que es lo que hay que mirar. */
            $where[] = 't.id_persona_asignada IS NULL';
        }
        if (!empty($f['mi_grupo']) && !empty($usuario['id_departamento'])) {
            $where[] = '(t.id_grupo_asignado = :migrupo
                         OR t.id_persona_asignada IN (SELECT id_login FROM login WHERE id_departamento = :migrupo2))';
            $params['migrupo']  = (int)$usuario['id_departamento'];
            $params['migrupo2'] = (int)$usuario['id_departamento'];
        }
        if (!empty($f['cerrados_semana'])) {
            $where[] = 't.fecha_cierre IS NOT NULL AND t.fecha_cierre >= :semana';
            $params['semana'] = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        }
        if (!empty($f['zona'])) {
            $where[] = 't.id_rel_zona = :zona';
            $params['zona'] = (int)$f['zona'];
        }
        if (!empty($f['zona1'])) {
            $where[] = 'r.id_zona_1 = :z1';
            $params['z1'] = (int)$f['zona1'];
        }
        if (!empty($f['zona2'])) {
            $where[] = 'r.id_zona_2 = :z2';
            $params['z2'] = (int)$f['zona2'];
        }

        /* Lo programado: partes aparcados que todavía no han vuelto a la cola.
           Con «dias» se acota a lo que viene pronto, que es lo que sirve para
           organizarse la semana; sin él salen todos. */
        if (!empty($f['programados']) && in_array('visible_desde', Db::columnas('ticket_incidencias'), true)) {
            $where[] = 't.visible_desde IS NOT NULL AND t.visible_desde > :prog_ahora';
            $params['prog_ahora'] = Db::now();

            $dias = Http::id($f['dias'] ?? null);
            if ($dias !== null && $dias > 0) {
                $where[] = 't.visible_desde <= :prog_hasta';
                $params['prog_hasta'] = gmdate('Y-m-d H:i:s', time() + $dias * 86400);
            }
        }

        if (!empty($f['vencidos'])) {
            $where[] = 't.fecha_limite IS NOT NULL AND t.fecha_limite < :ahora AND COALESCE(e.es_final,0) = 0';
            $params['ahora'] = Db::now();
        }
        if (!empty($f['desde'])) {
            $where[] = 't.fecha_creado >= :desde';
            $params['desde'] = (string)$f['desde'];
        
            /* Un parte aparcado para más adelante no está fuera de plazo:
               nadie ha dejado de atenderlo, está esperando a su fecha. */
            if (in_array('visible_desde', Db::columnas('ticket_incidencias'), true)) {
                $where[] = '(t.visible_desde IS NULL OR t.visible_desde <= :venc_ahora)';
                $params['venc_ahora'] = Db::now();
            }
        }
        if (!empty($f['hasta'])) {
            $where[] = 't.fecha_creado <= :hasta';
            $params['hasta'] = (string)$f['hasta'];
        }
        if (!empty($f['q'])) {
            $texto = trim((string)$f['q']);

            /* Buscar «averia» tiene que encontrar «avería».
             *
             * Nadie escribe acentos en el buscador de un móvil, y menos
             * con una mano y andando por un pasillo. Antes había que
             * escribirlos clavados: buscar «enfria» no encontraba «No
             * enfría el aire acondicionado», que es justo el parte que
             * se buscaba.
             *
             * Se comparan las dos formas —lo escrito y su versión sin
             * acentos— contra el texto también sin acentos. Sale más
             * larga la condición, pero es lo que hace que el buscador
             * sirva de algo. */
            $sinAcentos = static function (string $col): string {
                $pares = [
                    'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
                    'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
                    'ñ' => 'n', 'ç' => 'c',
                ];
                $sql = "LOWER($col)";
                foreach ($pares as $de => $a) {
                    $sql = "REPLACE($sql, '$de', '$a')";
                }
                return $sql;
            };

            $plano = mb_strtolower($texto);
            foreach (['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u',
                      'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','ñ'=>'n','ç'=>'c'] as $de => $a) {
                $plano = str_replace($de, $a, $plano);
            }

            /* El código del parte —«T-00012», lo que se ve en pantalla—
               no está en la base: se compone al leer. Si lo que se busca
               tiene esa pinta, se traduce al número. */
            $porCodigo = null;
            if (preg_match('/^\s*[tT]?-?0*(\d+)\s*$/', $texto, $m)) {
                $porCodigo = (int)$m[1];
            }

            /* Un código completo —«T-00012»— se busca SÓLO por su número.
             *
             * Con cien mil partes, buscar un código tardaba lo mismo que
             * buscar texto: 1,9 s. Y no debería tardar nada, porque es
             * una clave. Lo que lo frenaba es que la condición mezclaba
             * el número con tres LIKE sobre el título, el detalle y la
             * ubicación: al estar unidos por OR hay que mirar las cien
             * mil filas de todas formas, aunque el número salga en la
             * primera.
             *
             * Sólo se aplica cuando lleva la «T» delante, que ya no puede
             * ser otra cosa. Buscar «99» a secas sigue buscando también
             * en el texto: puede ser la habitación 99. */
            $soloCodigo = $porCodigo !== null && preg_match('/^\s*[tT]-/', $texto);

            /* Un marcador distinto por cada sitio donde se compara.
             *
             * Con «:q» repetido cuatro veces esto funcionaba en SQLite y
             * devolvía CERO resultados en MySQL, siempre: con
             * EMULATE_PREPARES desactivado, PDO manda los parámetros al
             * servidor y ahí un nombre sólo puede usarse una vez. No da
             * error —de ahí que no se notara—, simplemente no encuentra
             * nada nunca. */
            if ($soloCodigo) {
                /* Y nada más: ni un LIKE. Es lo que hace que buscar un
                   código sea instantáneo en vez de tardar casi dos
                   segundos con cien mil partes. */
                $where[] = 't.id_ticket = :qcod';
                $params['qcod'] = $porCodigo;
            } else {
                $cast = Db::driver() === 'sqlite' ? 'TEXT' : 'CHAR';
                $like = self::patron($plano);

                $cond = '(' . $sinAcentos('t.titulo_ticket') . ' LIKE :q1 ESCAPE \'!\''
                      . ' OR ' . $sinAcentos("COALESCE(t.detalle_ticket,'')") . ' LIKE :q2 ESCAPE \'!\''
                      . ' OR ' . $sinAcentos("COALESCE(r.ruta,'')") . ' LIKE :q3 ESCAPE \'!\''
                      . " OR CAST(t.id_ticket AS $cast) LIKE :q4 ESCAPE '!'";
                $params['q1'] = $like;
                $params['q2'] = $like;
                $params['q3'] = $like;
                $params['q4'] = $like;

                if ($porCodigo !== null) {
                    $cond .= ' OR t.id_ticket = :qcod';
                    $params['qcod'] = $porCodigo;
                }
                $where[] = $cond . ')';
            }
        }

        $orden = match ($f['orden'] ?? 'reciente') {
            'antiguo'   => 't.fecha_creado ASC',
            'prioridad' => 'p.orden DESC, t.fecha_creado DESC',
            'limite'    => 'CASE WHEN t.fecha_limite IS NULL THEN 1 ELSE 0 END, t.fecha_limite ASC',
            'estado'    => 'e.orden ASC, t.fecha_creado DESC',
            default     => 't.fecha_creado DESC',
        };

        $limite = min(max((int)($f['limite'] ?? 50), 1), 200);
        $offset = max((int)($f['offset'] ?? 0), 0);
        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        /* Las filas primero, y el total sólo si hace falta.
         *
         * Esto hacía dos consultas con la MISMA condición: una para
         * contar y otra para traer la página. Con una búsqueda de texto
         * eso duele el doble, porque la condición es la caneca: hay que
         * quitarle los acentos al título, al detalle y a la ubicación de
         * cada fila —medido con cien mil partes: 39 ms barrer la tabla,
         * 637 ms barrerla quitando acentos—. Dos veces 637 son los dos
         * segundos que se notaban al buscar.
         *
         * Cuando se pide la primera página y vienen MENOS filas que el
         * tope, el total ya se sabe: son las que han venido. No hay que
         * contar nada, y es exacto, no aproximado. Y justo ésa es la
         * búsqueda que iba lenta: la que encuentra pocas cosas.
         *
         * Si la página viene llena, o se ha pasado página, entonces sí
         * hay que contar: no se puede adivinar cuántas hay detrás.
         */
        $filas = Db::all(
            self::selectTicket() . $sqlWhere . " ORDER BY $orden LIMIT $limite OFFSET $offset",
            $params
        );

        if ($offset === 0 && count($filas) < $limite) {
            $total = count($filas);
        } else {
            $total = (int)Db::value(
                'SELECT COUNT(*) FROM ticket_incidencias t
                   LEFT JOIN estados e ON e.id_estado = t.id_estado
                   LEFT JOIN prioridades p ON p.id_prioridad = t.id_prioridad
                   LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
                   LEFT JOIN elemento el ON el.id_elemento = t.id_elemento
                   LEFT JOIN familias fa ON fa.id_familia = COALESCE(t.id_familia, el.id_familia)
                   LEFT JOIN login la ON la.id_login = t.id_persona_asignada' . $sqlWhere,
                $params
            );
        }

        return [
            'total'   => $total,
            'limite'  => $limite,
            'offset'  => $offset,
            'tickets' => array_map([self::class, 'normalizaTicket'], $filas),
        ];
    }

    // -----------------------------------------------------------------
    // Historial
    // -----------------------------------------------------------------

    /**
     * Quién está siguiendo un parte sin ser quien lo trabaja.
     */
    public static function observadores(int $idTicket): array
    {
        if (!Db::tablaExiste('ticket_observadores')) { return []; }

        return array_map(static function (array $f): array {
            return [
                'id_observador'   => (int)$f['id_observador'],
                'id_login'        => $f['id_login'] === null ? null : (int)$f['id_login'],
                'id_departamento' => $f['id_departamento'] === null ? null : (int)$f['id_departamento'],
                'nombre'          => $f['persona'] ?: $f['departamento'],
                'es_departamento' => $f['id_departamento'] !== null,
                'creado'          => $f['creado'],
            ];
        }, Db::all(
            'SELECT o.id_observador, o.id_login, o.id_departamento, o.creado,
                    ' . Db::nombreCompleto('l') . ' AS persona, d.nombre AS departamento
               FROM ticket_observadores o
               LEFT JOIN login l ON l.id_login = o.id_login
               LEFT JOIN departamentos d ON d.id_departamento = o.id_departamento
              WHERE o.id_ticket = ?
              ORDER BY o.id_observador', [$idTicket]));
    }

    /** Poner a alguien —o a un departamento entero— a seguir un parte. */
    public static function ponerObservador(int $idTicket, ?int $idLogin, ?int $idDepto, array $u): array
    {
        $ya = Db::one(
            'SELECT id_observador FROM ticket_observadores
              WHERE id_ticket = ? AND ((id_login IS NOT NULL AND id_login = ?)
                                    OR (id_departamento IS NOT NULL AND id_departamento = ?))',
            [$idTicket, $idLogin ?? 0, $idDepto ?? 0]);
        if ($ya) {
            return ['id_observador' => (int)$ya['id_observador'], 'mensaje' => 'Ya lo estaba siguiendo.'];
        }

        $id = Db::insert('ticket_observadores', [
            'id_ticket'       => $idTicket,
            'id_login'        => $idLogin,
            'id_departamento' => $idDepto,
            'id_puso'         => (int)$u['id_login'],
            'creado'          => Db::now(),
        ]);

        /* Se avisa a quien se ha puesto: si nadie se entera, poner un
           observador no sirve de nada. A un departamento entero se avisa
           a sus responsables, no a los quince técnicos. */
        $codigo = self::codigo($idTicket);
        $quien  = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido1'] ?? '')) ?: 'Alguien';
        $titulo = 'Te siguen en ' . $codigo;
        $cuerpo = $quien . ' te ha puesto a seguir este parte.';

        $destinos = [];
        if ($idLogin !== null) {
            $destinos[] = $idLogin;
        } elseif ($idDepto !== null) {
            foreach (Db::all("SELECT id_login FROM login
                               WHERE id_departamento = ? AND bloqueo = 0
                                 AND rol IN ('responsable', 'admin')", [$idDepto]) as $f) {
                $destinos[] = (int)$f['id_login'];
            }
            /* Si ese departamento no tiene responsables —recepción muchas
               veces no lo tiene—, se avisa a todo el mundo: si no, poner
               un departamento de observador no avisaría a nadie. */
            if (!$destinos) {
                foreach (Db::all('SELECT id_login FROM login
                                   WHERE id_departamento = ? AND bloqueo = 0
                                   LIMIT 20', [$idDepto]) as $f) {
                    $destinos[] = (int)$f['id_login'];
                }
            }
        }
        foreach (array_unique($destinos) as $idDestino) {
            if ($idDestino === (int)$u['id_login']) { continue; }
            self::notificar($idDestino, $idTicket, $titulo, $cuerpo, (int)$u['id_login'], 'observador');
        }

        $t = self::ticket($idTicket);
        self::anotar([
            'id_ticket' => $idTicket, 'tipo' => 'nota',
            'detalle'   => 'Sigue este parte: ' . self::nombreDeObservador($idLogin, $idDepto),
            'id_estado' => (int)($t['id_estado'] ?? 1),
            'id_estado_old' => (int)($t['id_estado'] ?? 1),
            'autor'     => (int)$u['id_login'],
        ]);

        return ['id_observador' => $id, 'mensaje' => 'Ya lo está siguiendo.'];
    }

    /** Dejar de seguir. */
    public static function quitarObservador(int $idTicket, int $idObservador, array $u): void
    {
        $o = Db::one('SELECT * FROM ticket_observadores WHERE id_observador = ? AND id_ticket = ?',
                     [$idObservador, $idTicket]);
        if ($o === null) {
            throw new ApiError('Ese observador no está en este parte.', 404);
        }
        Db::run('DELETE FROM ticket_observadores WHERE id_observador = ?', [$idObservador]);
        $t = self::ticket($idTicket);
        self::anotar([
            'id_ticket' => $idTicket, 'tipo' => 'nota',
            'detalle'   => 'Deja de seguir el parte: ' . self::nombreDeObservador(
                Http::id($o['id_login']), Http::id($o['id_departamento'])),
            'id_estado' => (int)($t['id_estado'] ?? 1),
            'id_estado_old' => (int)($t['id_estado'] ?? 1),
            'autor'     => (int)$u['id_login'],
        ]);
    }

    private static function nombreDeObservador(?int $idLogin, ?int $idDepto): string
    {
        if ($idLogin !== null) {
            return (string)Db::value('SELECT ' . Db::nombreCompleto('login')
                                   . ' FROM login WHERE id_login = ?', [$idLogin]) ?: 'alguien';
        }
        return (string)Db::value('SELECT nombre FROM departamentos WHERE id_departamento = ?',
                                 [$idDepto]) ?: 'un departamento';
    }

    public static function historial(int $idTicket): array
    {
        $autor = Db::nombreCompleto('lr');
        $nuevo = Db::nombreCompleto('ln');
        $viejo = Db::nombreCompleto('lo');

        $filas = Db::all(
            "SELECT h.id_historial, h.uuid, h.tipo, h.detalle_ticket, h.fecha_creado, h.minutos,
                    h.id_estado, h.id_estado_old, h.id_login, h.id_login_old,
                    en.nombre_estado AS estado_nuevo, en.color AS estado_nuevo_color,
                    eo.nombre_estado AS estado_anterior,
                    $autor AS autor, $nuevo AS asignado_nuevo, $viejo AS asignado_anterior
               FROM ticket_incidencias_historial h
               LEFT JOIN estados en ON en.id_estado = h.id_estado
               LEFT JOIN estados eo ON eo.id_estado = h.id_estado_old
               LEFT JOIN login lr ON lr.id_login = h.id_realiza_accion
               LEFT JOIN login ln ON ln.id_login = h.id_login
               LEFT JOIN login lo ON lo.id_login = h.id_login_old
              WHERE h.id_ticket = ?
              ORDER BY h.fecha_creado ASC, h.id_historial ASC",
            [$idTicket]
        );
        foreach ($filas as &$f) {
            $f['id_historial'] = (int)$f['id_historial'];
            $f['minutos'] = (int)$f['minutos'];
        }
        return $filas;
    }

    public static function adjuntos(int $idTicket): array
    {
        $filas = Db::all(
            'SELECT id_adjunto, uuid, archivo, nombre_original, mime, tamano, ancho, alto, fecha_creado
               FROM adjuntos WHERE id_ticket = ? AND borrado = 0 ORDER BY id_adjunto ASC',
            [$idTicket]
        );
        foreach ($filas as &$f) {
            $f['id_adjunto'] = (int)$f['id_adjunto'];
            $f['tamano']     = (int)$f['tamano'];
            $f['url']        = 'uploads/' . $idTicket . '/' . $f['archivo'];
        }
        return $filas;
    }

    /**
     * Recalcula la ruta legible de todas las ubicaciones.
     *
     * Es lo que se busca en el selector, así que renombrar una planta
     * sin actualizarla dejaría la búsqueda mostrando el nombre viejo.
     */
    public static function recalcularRutas(): int
    {
        $hotelR = class_exists('Hoteles') && Db::tablaExiste('hoteles') ? Hoteles::actual() : null;
        $n = 0;
        foreach (Db::all(
            'SELECT r.id_rel, z1.nombre_zona_1 AS n1, z2.nombre_zona_2 AS n2,
                    z3.nombre_zona_3 AS n3, z4.nombre_zona_4 AS n4
               FROM rel_zonas r
               LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
               LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
               LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
               LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4'
            . ($hotelR !== null ? ' WHERE r.id_hotel = ?' : ''),
            $hotelR !== null ? [$hotelR] : []
        ) as $r) {
            $ruta = implode(' > ', array_filter([$r['n1'], $r['n2'], $r['n3'], $r['n4']]));
            Db::run('UPDATE rel_zonas SET ruta = ? WHERE id_rel = ?', [$ruta, (int)$r['id_rel']]);
            $n++;
        }
        return $n;
    }

    /** Valores de los campos propios de un parte, ya resueltos. */
    public static function camposDe(int $idTicket): array
    {
        if (!Db::tablaExiste('valores_campo')) {
            return [];
        }
        /* Todos los campos que ese tipo de avería define, tengan valor o
         * no.
         *
         * Antes sólo salían los que ya estaban rellenos, y eso dejaba un
         * agujero: el parte lo abre recepción, que no mide temperaturas,
         * así que llegaban vacíos; y como vacíos no existían para la
         * ficha, el técnico que sí mide no tenía dónde anotarlas. Se
         * veían al dar el parte y desaparecían después.
         *
         * Haciéndolo al revés —partir de la definición y añadir el valor
         * si lo hay— el técnico ve qué toca medir aunque nadie haya
         * puesto nada todavía. */
        $filas = Db::all(
            'SELECT c.id_campo, c.clave, c.etiqueta, c.tipo, c.unidad, c.opciones,
                    c.ayuda, c.obligatorio, v.valor
               FROM campos_elemento c
               JOIN ticket_incidencias t ON t.id_ticket = :t1
               LEFT JOIN valores_campo v ON v.id_campo = c.id_campo AND v.id_ticket = :t2
              WHERE c.activo = 1 AND c.id_elemento = t.id_elemento
              ORDER BY c.orden, c.id_campo',
            ['t1' => $idTicket, 't2' => $idTicket]
        );
        foreach ($filas as &$f) {
            $f['id_campo'] = (int)$f['id_campo'];
            $f['opciones'] = $f['opciones'] ? json_decode((string)$f['opciones'], true) : null;
            if ($f['valor'] === null || $f['valor'] === '') {
                // Sin anotar todavía: la ficha lo enseña con un guion y
                // ofrece el botón para ponerlo.
                $f['valor_texto'] = '';
            } elseif ($f['tipo'] === 'interruptor') {
                $f['valor_texto'] = $f['valor'] === '1' ? 'Sí' : 'No';
            } else {
                $f['valor_texto'] = $f['valor'] . ($f['unidad'] ? ' ' . $f['unidad'] : '');
            }
        }
        return $filas;
    }

    /** Registra un movimiento y devuelve su id. */
    public static function anotar(array $d): int
    {
        return Db::insert('ticket_incidencias_historial', [
            'uuid'              => $d['uuid'] ?? Http::uuid(),
            'id_ticket'         => (int)$d['id_ticket'],
            'tipo'              => $d['tipo'] ?? 'nota',
            'detalle_ticket'    => $d['detalle'] ?? null,
            'id_estado'         => $d['id_estado'] ?? null,
            'id_estado_old'     => $d['id_estado_old'] ?? null,
            'id_login'          => $d['id_login'] ?? null,
            'id_login_old'      => $d['id_login_old'] ?? null,
            'id_realiza_accion' => (int)$d['autor'],
            'minutos'           => (int)($d['minutos'] ?? 0),
            'fecha_creado'      => $d['fecha'] ?? Db::now(),
            'updated_at'        => Db::now(),
        ]);
    }

    /**
     * Deja un aviso, si la persona lo quiere. El tipo de evento permite
     * que cada cual decida qué le interesa: un técnico puede querer sólo
     * lo que le asignan y no cada nota suelta.
     */
    public static function notificar(?int $idLogin, int $idTicket, string $titulo, string $cuerpo, int $excepto = 0, string $evento = 'cambio_estado'): void
    {
        if ($idLogin === null || $idLogin <= 0 || $idLogin === $excepto) {
            return;
        }
        if (class_exists('Permisos') && !Permisos::quiereAviso($idLogin, $evento)) {
            return;
        }
        // Si esta persona ha pedido que este aviso llegue callado, se
        // marca para que el móvil no lo haga sonar.
        $conSonido = !class_exists('Permisos') || Permisos::avisoSuena($idLogin, $evento);
        Db::insert('notificaciones', [
            'id_login'  => $idLogin,
            'id_ticket' => $idTicket,
            'titulo'    => mb_substr($titulo, 0, 160),
            'cuerpo'    => mb_substr($cuerpo, 0, 400),
            'leida'     => 0,
            'evento'    => $evento,
            'silenciosa'=> $conSonido ? 0 : 1,
            'fecha'     => Db::now(),
            'updated_at'=> Db::now(),
        ]);

        /* Y se empuja al móvil, si esta persona lo tiene registrado.
           Va después de guardar a propósito: el aviso queda en la base
           aunque el push falle, así que al abrir la aplicación se ve
           igual. Un push que no sale no puede perder el aviso. */
        if (class_exists('Push')) {
            try {
                Push::enviar($idLogin, $titulo, $cuerpo, [
                    'evento' => $evento,
                    'ruta'   => '/ticket/' . $idTicket,
                    'tag'    => 'hurakantick-' . $idTicket,
                ]);
            } catch (Throwable $e) {
                // Un aviso que no sale no puede tumbar lo que lo provocó.
            }
        }

        /* Y la copia a Teams, si está encendida.
         *
         * Aquí sólo se apunta en la bandeja de salida: es un INSERT y ya
         * está. Hablar con Microsoft pasa después, en el latido. Si esto
         * esperase a Microsoft, guardar un parte tardaría lo que tarde
         * Microsoft, y un día que no conteste no se podrían guardar
         * partes. Eso no puede pasar por una copia de un aviso. */
        if (class_exists('Teams')) {
            try {
                Teams::encolar($idLogin, $titulo, $cuerpo, $idTicket,
                               '/ticket/' . $idTicket, $conSonido);
            } catch (Throwable $e) {
                // Idem: nunca puede tumbar lo que lo provocó.
            }
        }
    }

    // -----------------------------------------------------------------
    // Zonas
    // -----------------------------------------------------------------
    /**
     * Devuelve el id_rel de la combinación de zonas, creándola si no existe.
     * Así el usuario puede dar de alta una ubicación nueva sin salir del parte.
     */
    public static function resolverZona(?int $z1, ?int $z2, ?int $z3, ?int $z4, int $autor): ?int
    {
        if (!$z1 && !$z2 && !$z3 && !$z4) {
            return null;
        }
        $cond = [];
        $p    = [];
        foreach (['id_zona_1' => $z1, 'id_zona_2' => $z2, 'id_zona_3' => $z3, 'id_zona_4' => $z4] as $col => $val) {
            if ($val === null) {
                $cond[] = "$col IS NULL";
            } else {
                $cond[] = "$col = :$col";
                $p[$col] = $val;
            }
        }
        /* El hotel de la ubicación. Se insertaba sin él: los niveles sí se
           crean dentro del hotel (zonaPorNombre), pero la ubicación que
           los junta se quedaba con id_hotel vacío, y el buscador —que
           filtra por hotel— no la encontraba nunca más. Quien la creaba
           desde un parte la usaba una vez y luego no había forma de
           volver a elegirla. Lo destapó pruebas/jornada.py. */
        $hotel = class_exists('Hoteles') && Db::tablaExiste('hoteles') ? Hoteles::actual() : null;

        $id = Db::value('SELECT id_rel FROM rel_zonas WHERE ' . implode(' AND ', $cond), $p);
        if ($id !== null) {
            // Y las que ya se crearon así, se recogen al volver a usarlas.
            if ($hotel !== null) {
                Db::run('UPDATE rel_zonas SET id_hotel = ? WHERE id_rel = ? AND id_hotel IS NULL',
                        [$hotel, (int)$id]);
            }
            return (int)$id;
        }
        $fila = [
            'id_zona_1' => $z1, 'id_zona_2' => $z2, 'id_zona_3' => $z3, 'id_zona_4' => $z4,
            'ruta' => self::construirRuta($z1, $z2, $z3, $z4),
            'id_realiza_accion' => $autor,
            'updated_at' => Db::now(),
        ];
        if ($hotel !== null) { $fila['id_hotel'] = $hotel; }
        return Db::insert('rel_zonas', $fila);
    }

    public static function construirRuta(?int $z1, ?int $z2, ?int $z3, ?int $z4): string
    {
        $partes = [];
        foreach ([[1,$z1],[2,$z2],[3,$z3],[4,$z4]] as [$n, $id]) {
            if ($id) {
                $v = Db::value("SELECT nombre_zona_$n FROM zona_$n WHERE id_zona_$n = ?", [$id]);
                if ($v) { $partes[] = (string)$v; }
            }
        }
        return implode(' › ', $partes);
    }

    /** Alta de un nombre de zona en un nivel, sin duplicar. */
    public static function zonaPorNombre(int $nivel, string $nombre): int
    {
        $nombre = trim($nombre);
        $tabla  = "zona_$nivel";
        $col    = "nombre_zona_$nivel";
        // Se busca y se crea dentro del hotel: dos hoteles pueden tener
        // una «Planta 01» cada uno y son sitios distintos.
        $hotel = class_exists('Hoteles') && Db::tablaExiste('hoteles') ? Hoteles::actual() : null;

        $id = $hotel !== null
            ? Db::value("SELECT id_$tabla FROM $tabla WHERE LOWER($col) = LOWER(?) AND id_hotel = ?",
                        [$nombre, $hotel])
            : Db::value("SELECT id_$tabla FROM $tabla WHERE LOWER($col) = LOWER(?)", [$nombre]);
        if ($id !== null) {
            return (int)$id;
        }

        $datos = [$col => $nombre, 'orden' => 0, 'updated_at' => Db::now()];
        if ($hotel !== null) { $datos['id_hotel'] = $hotel; }
        return Db::insert($tabla, $datos);
    }

    /**
     * El árbol de ubicaciones del hotel con el que se está trabajando.
     *
     * Va filtrado por hotel en los cinco sitios —los cuatro niveles y
     * las combinaciones—. Es lo que se descarga al móvil, así que una
     * fuga aquí sería la peor de todas: el catálogo de otro hotel
     * entero, guardado en un aparato ajeno.
     */
    public static function arbolZonas(): array
    {
        $hotel = class_exists('Hoteles') && Db::tablaExiste('hoteles') ? Hoteles::actual() : null;
        $donde = $hotel !== null ? ' WHERE id_hotel = ?' : '';
        $p     = $hotel !== null ? [$hotel] : [];

        $niveles = [];
        for ($n = 1; $n <= 4; $n++) {
            $filas = Db::all(
                "SELECT id_zona_$n AS id, nombre_zona_$n AS nombre FROM zona_$n$donde
                  ORDER BY orden, nombre_zona_$n", $p);
            $niveles["zona$n"] = array_map(static fn($f) => ['id' => (int)$f['id'], 'nombre' => $f['nombre']], $filas);
        }
        // La ruta legible se compone aquí a partir de los nombres, no se
        // confía en la columna caché: en una instalación nueva viene vacía
        // y sin ella el buscador de ubicaciones no encontraría nada.
        $rel = Db::all(
            'SELECT r.id_rel, r.id_zona_1, r.id_zona_2, r.id_zona_3, r.id_zona_4, r.ruta,
                    z1.nombre_zona_1, z2.nombre_zona_2, z3.nombre_zona_3, z4.nombre_zona_4
               FROM rel_zonas r
               LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
               LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
               LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
               LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4'
            . ($hotel !== null ? ' WHERE r.id_hotel = ?' : '')
            . ' ORDER BY r.id_rel',
            $p
        );

        $niveles['combinaciones'] = array_map(static function ($f) {
            $partes = array_filter([
                $f['nombre_zona_1'] ?? null, $f['nombre_zona_2'] ?? null,
                $f['nombre_zona_3'] ?? null, $f['nombre_zona_4'] ?? null,
            ], static fn($v) => $v !== null && $v !== '');

            return [
                'id_rel' => (int)$f['id_rel'],
                'z1' => $f['id_zona_1'] === null ? null : (int)$f['id_zona_1'],
                'z2' => $f['id_zona_2'] === null ? null : (int)$f['id_zona_2'],
                'z3' => $f['id_zona_3'] === null ? null : (int)$f['id_zona_3'],
                'z4' => $f['id_zona_4'] === null ? null : (int)$f['id_zona_4'],
                'ruta' => $partes ? implode(' › ', $partes) : ($f['ruta'] ?: ''),
            ];
        }, $rel);

        return $niveles;
    }

    /** Calcula la fecha límite a partir del SLA de la prioridad. */
    public static function fechaLimite(int $idPrioridad, ?string $desde = null): ?string
    {
        $h = Db::value('SELECT sla_horas FROM prioridades WHERE id_prioridad = ?', [$idPrioridad]);
        if ($h === null || (int)$h <= 0) {
            return null;
        }
        $base = $desde ? strtotime($desde) : time();
        return gmdate('Y-m-d H:i:s', $base + ((int)$h * 3600));
    }
}
