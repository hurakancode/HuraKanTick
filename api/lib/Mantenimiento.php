<?php
/**
 * Mantenimiento programado.
 *
 * Un mantenimiento es una plantilla con un calendario. Cuando le toca,
 * genera un parte normal: con su historial, sus fotos y la firma de
 * quien lo hizo. Eso es lo que sirve para demostrar en una inspección
 * que la revisión se hizo, y por eso no se inventa un tipo de registro
 * aparte.
 */
declare(strict_types=1);

final class Mantenimiento
{
    public const UNIDADES = [
        'dias'    => 'días',
        'semanas' => 'semanas',
        'meses'   => 'meses',
        'anios'   => 'años',
    ];

    /** Descripción legible de la periodicidad. */
    public static function periodicidad(int $intervalo, string $unidad): string
    {
        $u = self::UNIDADES[$unidad] ?? $unidad;
        if ($intervalo === 1) {
            return match ($unidad) {
                'dias'    => 'Cada día',
                'semanas' => 'Cada semana',
                'meses'   => 'Cada mes',
                'anios'   => 'Cada año',
                default   => 'Cada ' . $u,
            };
        }
        return 'Cada ' . $intervalo . ' ' . $u;
    }

    /** Siguiente fecha a partir de una dada. */
    public static function siguiente(string $desde, int $intervalo, string $unidad): string
    {
        $intervalo = max(1, $intervalo);
        $ts = strtotime($desde) ?: time();
        $expr = match ($unidad) {
            'dias'    => "+$intervalo days",
            'semanas' => "+$intervalo weeks",
            'anios'   => "+$intervalo years",
            default   => "+$intervalo months",
        };
        return gmdate('Y-m-d', strtotime($expr, $ts));
    }

    /* ---------------------------------------------------------------- */
    /* Listado                                                          */
    /* ---------------------------------------------------------------- */
    public static function listar(): array
    {
        if (!Db::tablaExiste('mantenimientos')) {
            return [];
        }
        $nombre = Db::nombreCompleto('l');
        [$hc, $hp] = Db::tablaExiste('hoteles') ? Hoteles::filtro('m') : ['1 = 1', []];
        $filas = Db::all(
            "SELECT m.*, f.nombre AS familia, f.color AS familia_color, f.icono AS familia_icono,
                    el.nombre_elemento AS elemento, r.ruta AS zona_ruta,
                    d.nombre AS departamento, $nombre AS persona, p.nombre AS prioridad
               FROM mantenimientos m
               LEFT JOIN familias f  ON f.id_familia = m.id_familia
               LEFT JOIN elemento el ON el.id_elemento = m.id_elemento
               LEFT JOIN rel_zonas r ON r.id_rel = m.id_rel_zona
               LEFT JOIN departamentos d ON d.id_departamento = m.id_departamento
               LEFT JOIN login l ON l.id_login = m.id_persona
               LEFT JOIN prioridades p ON p.id_prioridad = m.id_prioridad
              WHERE $hc
              ORDER BY m.activo DESC, m.proximo ASC",
            $hp
        );

        $hoy = gmdate('Y-m-d');
        foreach ($filas as &$m) {
            $m['id_mantenimiento'] = (int)$m['id_mantenimiento'];
            $m['intervalo']   = (int)$m['intervalo'];
            $m['aviso_dias']  = (int)$m['aviso_dias'];
            $m['obligatorio'] = (int)$m['obligatorio'];
            $m['activo']      = (int)$m['activo'];
            $m['veces']       = (int)$m['veces'];
            foreach (['id_familia','id_elemento','id_rel_zona','id_departamento',
                      'id_persona','id_prioridad','ultimo_ticket'] as $k) {
                $m[$k] = $m[$k] === null ? null : (int)$m[$k];
            }
            $m['periodicidad'] = self::periodicidad($m['intervalo'], (string)$m['unidad']);

            /* El tope, si la instalación ya lo tiene. Se manda además
               escrito en claro: la pantalla no tiene que calcular
               «quedan 2 de 6» por su cuenta. */
            $m['repeticiones'] = isset($m['repeticiones']) && $m['repeticiones'] !== null
                ? (int)$m['repeticiones'] : null;
            $m['hasta'] = $m['hasta'] ?? null;
            $m['restantes'] = $m['repeticiones'] === null
                ? null : max(0, $m['repeticiones'] - $m['veces']);
            $m['tope_texto'] = self::topeTexto($m);

            /* Por qué está apagada, si lo está por haber llegado a su tope.
               Antes el motivo sólo salía en el registro de «Comprobar
               ahora», que nadie lee: en la lista aparecía «terminado» a
               secas, o nada cuando el tope era una fecha. Un responsable
               que ve una revisión apagada necesita saber si se agotó sola o
               si alguien la desactivó a mano. */
            $m['fin_motivo'] = null;
            if ((int)$m['activo'] === 0) {
                $hastaD = ($m['hasta'] ?? null) ?: null;
                if ($m['repeticiones'] !== null && (int)$m['veces'] >= (int)$m['repeticiones']) {
                    $m['fin_motivo'] = 'terminada: ya se hizo las '
                                     . (int)$m['repeticiones'] . ' veces previstas';
                } elseif ($hastaD !== null && substr((string)$m['proximo'], 0, 10) > substr((string)$hastaD, 0, 10)) {
                    $m['fin_motivo'] = 'terminada: pasó su fecha final ('
                                     . date('d/m/Y', strtotime((string)$hastaD) ?: time()) . ')';
                }
            }
            $dias = (int)floor((strtotime((string)$m['proximo']) - strtotime($hoy)) / 86400);
            $m['dias_restantes'] = $dias;
            $m['vencido']  = $dias < 0;
            $m['inminente'] = $dias >= 0 && $dias <= (int)$m['aviso_dias'];
        }
        return $filas;
    }

    /**
     * El tope de repetición, en una línea que se pueda leer.
     *
     * Sin esto, en la pantalla sólo se veía «Cada 3 meses» y era
     * imposible saber si eso era para siempre o si quedaban dos.
     */
    public static function topeTexto(array $m): string
    {
        $tope  = $m['repeticiones'] ?? null;
        $hasta = ($m['hasta'] ?? null) ?: null;

        if ($tope === null && $hasta === null) {
            return 'Sin fecha final: se repite hasta que lo desactives.';
        }

        $partes = [];
        if ($tope !== null) {
            $quedan = max(0, (int)$tope - (int)($m['veces'] ?? 0));
            $partes[] = $quedan === 0
                ? 'Ya se ha hecho las ' . $tope . ' veces previstas'
                : $quedan . ' de ' . $tope . ' por hacer';
        }
        if ($hasta !== null) {
            $partes[] = 'hasta el ' . date('d/m/Y', strtotime((string)$hasta) ?: time());
        }

        return implode(', ', $partes) . '.';
    }

    /* ---------------------------------------------------------------- */
    /* Latido: generar sin depender de un cron                          */
    /* ---------------------------------------------------------------- */
    /**
     * Genera lo que toque, como mucho una vez al día.
     *
     * Hasta ahora esto sólo pasaba si alguien había puesto un cron. El
     * README lo explicaba, pero documentar algo no lo ejecuta: en un
     * hotel sin cron no se generó nunca un parte de mantenimiento, y lo
     * único que se veía era que las revisiones no aparecían. Un sistema
     * de mantenimiento preventivo que depende de que alguien se acuerde
     * de configurarlo no es mantenimiento preventivo.
     *
     * Así que se engancha a las peticiones normales, igual que los
     * partes programados. Con dos cuidados:
     *
     *  - El candado («en_marcha») evita que, al abrir treinta personas
     *    la aplicación a las siete de la mañana, se lancen treinta
     *    generaciones a la vez y salgan partes repetidos.
     *  - Nunca puede tirar la petición que lo dispara: si falla, se
     *    apunta en el registro de errores y la pantalla sigue.
     *
     * El cron, si existe, sigue valiendo: comparten el mismo candado.
     */
    public static function latido(): int
    {
        if (!Db::tablaExiste('mantenimientos') || !Db::tablaExiste('tareas_latido')) {
            return 0;
        }

        try {
            $ahora = Db::now();
            $fila  = Db::one('SELECT * FROM tareas_latido WHERE clave = ?', ['mantenimientos']);

            if ($fila === null) {
                Db::insert('tareas_latido', [
                    'clave' => 'mantenimientos', 'ultimo' => null,
                    'en_marcha' => null, 'veces' => 0, 'updated_at' => $ahora,
                ]);
                $fila = ['ultimo' => null, 'en_marcha' => null, 'veces' => 0];
            }

            // Ya se hizo hoy.
            if (!empty($fila['ultimo'])
                && substr((string)$fila['ultimo'], 0, 10) === gmdate('Y-m-d')) {
                return 0;
            }

            /* Otra petición está en ello. Se le dan diez minutos: si se
               quedó a medias —se cortó PHP, se reinició el servidor—, el
               candado caduca y alguien lo vuelve a intentar, en vez de
               bloquearse para siempre. */
            if (!empty($fila['en_marcha'])
                && strtotime((string)$fila['en_marcha']) > time() - 600) {
                return 0;
            }

            /* Coger el candado comprobando a la vez que nadie lo tiene:
               si dos peticiones llegan en el mismo milisegundo, sólo una
               de las dos UPDATE afecta a una fila. */
            $cogido = Db::run(
                'UPDATE tareas_latido
                    SET en_marcha = ?, updated_at = ?
                  WHERE clave = ?
                    AND (en_marcha IS NULL OR en_marcha < ?)
                    AND (ultimo IS NULL OR ultimo < ?)',
                [$ahora, $ahora, 'mantenimientos',
                 gmdate('Y-m-d H:i:s', time() - 600), gmdate('Y-m-d') . ' 00:00:00']
            )->rowCount();

            if ($cogido === 0) {
                return 0;
            }

            try {
                $r = self::generar();
            } finally {
                // El candado se suelta siempre, salga bien o mal: dejarlo
                // cogido dejaría el mantenimiento muerto hasta mañana.
                Db::update('tareas_latido', [
                    'ultimo' => Db::now(), 'en_marcha' => null,
                    'veces' => (int)($fila['veces'] ?? 0) + 1, 'updated_at' => Db::now(),
                ], 'clave = :c', ['c' => 'mantenimientos']);
            }

            return (int)($r['generados'] ?? 0);
        } catch (Throwable $e) {
            if (class_exists('Errores')) {
                Errores::apuntar('servidor', 'No se pudieron generar los mantenimientos: '
                                 . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            }
            return 0;
        }
    }

    /* ---------------------------------------------------------------- */
    /* Generación                                                       */
    /* ---------------------------------------------------------------- */
    /**
     * Crea los partes de los mantenimientos que tocan.
     *
     * Se adelanta `aviso_dias` para que dé tiempo a organizarse: una
     * revisión que aparece el mismo día que vence ya llega tarde.
     *
     * @return array{generados:int, detalle:array}
     */
    public static function generar(?callable $log = null, bool $simular = false): array
    {
        $log = $log ?? static fn(string $m) => null;

        if (!Db::tablaExiste('mantenimientos')) {
            $log('No existe la tabla de mantenimientos.');
            return ['generados' => 0, 'detalle' => []];
        }

        /* «Toca ya, contando los días de aviso».
         *
         * Restar días a una fecha se escribe distinto en cada motor:
         * SQLite usa date(f, '-N day') y MySQL DATE_SUB(f, INTERVAL N DAY).
         * Con la forma de SQLite, MySQL ni siquiera prepara la consulta:
         * el cron entero moría antes de generar un solo parte, así que
         * el mantenimiento preventivo no existía en esas instalaciones. */
        $hoy = gmdate('Y-m-d');
        $adelanto = Db::driver() === 'sqlite'
            ? "date(proximo, '-' || aviso_dias || ' day')"
            : 'DATE_SUB(proximo, INTERVAL aviso_dias DAY)';

        $pendientes = Db::all(
            "SELECT * FROM mantenimientos
              WHERE activo = 1
                AND $adelanto <= ?
              ORDER BY proximo ASC",
            [$hoy]
        );

        // MySQL no tiene date() con esa sintaxis: se filtra en PHP.
        if (Db::driver() === 'mysql') {
            $pendientes = array_filter(
                Db::all('SELECT * FROM mantenimientos WHERE activo = 1 ORDER BY proximo ASC'),
                static function ($m) use ($hoy) {
                    $aviso = strtotime((string)$m['proximo']) - ((int)$m['aviso_dias'] * 86400);
                    return $aviso <= strtotime($hoy);
                }
            );
        }

        $detalle = [];
        $n = 0;

        $conTope = in_array('repeticiones', Db::columnas('mantenimientos'), true);

        foreach ($pendientes as $m) {
            $id = (int)$m['id_mantenimiento'];

            /* ¿Se ha terminado? Un mantenimiento puede tener un número
               de veces («las seis revisiones del contrato») o una fecha
               final («hasta que cierre la piscina»). Al agotarse se
               desactiva y se queda ahí, con su historial: borrarlo
               perdería la prueba de que las revisiones se hicieron. */
            if ($conTope) {
                $tope  = $m['repeticiones'] === null ? null : (int)$m['repeticiones'];
                $hasta = $m['hasta'] === null || $m['hasta'] === '' ? null : substr((string)$m['hasta'], 0, 10);
                $motivo = null;

                if ($tope !== null && (int)$m['veces'] >= $tope) {
                    $motivo = 'ya se ha hecho las ' . $tope . ' veces previstas';
                } elseif ($hasta !== null && substr((string)$m['proximo'], 0, 10) > $hasta) {
                    $motivo = 'ha pasado su fecha final (' . $hasta . ')';
                }

                if ($motivo !== null) {
                    if (!$simular) {
                        Db::update('mantenimientos',
                                   ['activo' => 0, 'updated_at' => Db::now()],
                                   'id_mantenimiento = :id', ['id' => $id]);
                    }
                    $log('  · «' . $m['nombre'] . '» termina aquí: ' . $motivo . '.');
                    $detalle[] = ['id' => $id, 'nombre' => $m['nombre'],
                                  'estado' => 'terminado', 'motivo' => $motivo];
                    continue;
                }
            }

            // Si el parte anterior sigue abierto no se crea otro: acumular
            // revisiones sin hacer sólo produce ruido, y quien las tiene
            // pendientes ya lo sabe.
            if ($m['ultimo_ticket'] !== null) {
                $abierto = Db::value(
                    'SELECT 1 FROM ticket_incidencias t
                       LEFT JOIN estados e ON e.id_estado = t.id_estado
                      WHERE t.id_ticket = ? AND t.borrado = 0 AND COALESCE(e.es_final,0) = 0',
                    [(int)$m['ultimo_ticket']]
                );
                if ($abierto) {
                    $log('  · «' . $m['nombre'] . '» todavía tiene el parte anterior abierto: se omite.');
                    $detalle[] = ['id' => $id, 'nombre' => $m['nombre'], 'estado' => 'pendiente_anterior'];
                    continue;
                }
            }

            if ($simular) {
                $log('  · se crearía «' . $m['nombre'] . '» (vence ' . $m['proximo'] . ')');
                $detalle[] = ['id' => $id, 'nombre' => $m['nombre'], 'estado' => 'simulado'];
                $n++;
                continue;
            }

            $idTicket = self::crearParte($m);
            $siguiente = self::siguiente((string)$m['proximo'], (int)$m['intervalo'], (string)$m['unidad']);

            // Si la fecha estaba muy atrasada se avanza hasta superar hoy,
            // en vez de generar un parte por cada ciclo perdido.
            $vueltas = 0;
            while (strtotime($siguiente) <= strtotime($hoy) && $vueltas < 200) {
                $siguiente = self::siguiente($siguiente, (int)$m['intervalo'], (string)$m['unidad']);
                $vueltas++;
            }

            $hechas = (int)$m['veces'] + 1;
            $nuevo = [
                'proximo'         => $siguiente,
                'ultimo_generado' => Db::now(),
                'ultimo_ticket'   => $idTicket,
                'veces'           => $hechas,
                'updated_at'      => Db::now(),
            ];

            /* Si ésta era la última, se apaga ya. Dejarlo activo hasta
               el ciclo siguiente lo haría salir en el calendario con una
               fecha que no va a llegar nunca. */
            if ($conTope) {
                $tope  = $m['repeticiones'] === null ? null : (int)$m['repeticiones'];
                $hasta = $m['hasta'] === null || $m['hasta'] === '' ? null : substr((string)$m['hasta'], 0, 10);
                if (($tope !== null && $hechas >= $tope)
                    || ($hasta !== null && $siguiente > $hasta)) {
                    $nuevo['activo'] = 0;
                }
            }

            Db::update('mantenimientos', $nuevo, 'id_mantenimiento = :id', ['id' => $id]);

            $log('  · ' . Repo::codigo($idTicket) . '  ' . $m['nombre']
                 . '  → siguiente ' . $siguiente);
            $detalle[] = ['id' => $id, 'nombre' => $m['nombre'],
                          'id_ticket' => $idTicket, 'estado' => 'creado'];
            $n++;
        }

        return ['generados' => $n, 'detalle' => $detalle];
    }

    /** Crea el parte de una revisión. */

    /** El final del día de una fecha, venga con hora o sin ella. */
    private static function finDelDia(string $fecha): ?string
    {
        $dia = substr(trim($fecha), 0, 10);
        $ts  = strtotime($dia . ' 23:59:59');
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * El hotel al que pertenece una revisión programada.
     *
     * Por este orden: el suyo, el de la ubicación donde se hace, o el
     * único que haya. Si no se puede saber, null —y entonces el
     * diagnóstico lo canta como un parte que no ve nadie, que es mejor
     * que un silencio—.
     */
    private static function hotelDe(array $m): ?int
    {
        if (!Db::tablaExiste('hoteles')
            || !in_array('id_hotel', Db::columnas('ticket_incidencias'), true)) {
            return null;
        }
        if (!empty($m['id_hotel'])) {
            return (int)$m['id_hotel'];
        }
        if (!empty($m['id_rel_zona'])) {
            $deLaZona = Db::value('SELECT id_hotel FROM rel_zonas WHERE id_rel = ?',
                                  [(int)$m['id_rel_zona']]);
            if ($deLaZona !== null) { return (int)$deLaZona; }
        }
        $activos = Db::all('SELECT id_hotel FROM hoteles WHERE COALESCE(activo,1) = 1');
        return count($activos) === 1 ? (int)$activos[0]['id_hotel'] : null;
    }

    private static function crearParte(array $m): int
    {
        $autor = (int)(Db::value("SELECT id_login FROM login WHERE bloqueo = 0 AND admin = 1 ORDER BY id_login LIMIT 1")
            ?? Db::value('SELECT MIN(id_login) FROM login'));

        $estadoInicial = (int)(Db::value('SELECT id_estado FROM estados WHERE es_inicial = 1 ORDER BY orden LIMIT 1')
            ?? Db::value('SELECT MIN(id_estado) FROM estados') ?? 1);

        $prioridad = (int)($m['id_prioridad'] ?? 2) ?: 2;
        $ahora = Db::now();

        $detalle = trim((string)($m['descripcion'] ?? ''));
        $detalle .= ($detalle !== '' ? "\n\n" : '')
                  . 'Revisión programada · '
                  . self::periodicidad((int)$m['intervalo'], (string)$m['unidad'])
                  . ((int)$m['obligatorio'] === 1 ? ' · de obligado cumplimiento' : '');

        $idTicket = Db::insert('ticket_incidencias', [
            'uuid'                => Http::uuid(),
            'titulo_ticket'       => mb_substr((string)$m['nombre'], 0, 200),
            'detalle_ticket'      => $detalle,
            'fecha_creado'        => $ahora,
            /* El plazo es el final del día en que toca.
             *
             * Antes se le pegaba « 23:59:59» a la fecha tal cual, y si
             * ésta ya traía hora —«2026-10-02 00:00:00»— el resultado no
             * era una fecha válida: strtotime devolvía false y la
             * generación entera se caía a mitad, dejando unos
             * mantenimientos hechos y otros no. */
            'fecha_limite'        => self::finDelDia((string)$m['proximo']),
            'id_estado'           => $estadoInicial,
            'id_prioridad'        => $prioridad,
            'id_elemento'         => $m['id_elemento'] === null ? null : (int)$m['id_elemento'],
            'id_familia'          => $m['id_familia'] === null ? null : (int)$m['id_familia'],
            'id_rel_zona'         => $m['id_rel_zona'] === null ? null : (int)$m['id_rel_zona'],
            'id_realiza_accion'   => $autor,
            'id_persona_asignada' => $m['id_persona'] === null ? null : (int)$m['id_persona'],
            'id_grupo_asignado'   => $m['id_departamento'] === null ? null : (int)$m['id_departamento'],
            'id_mantenimiento'    => (int)$m['id_mantenimiento'],
            /* El hotel de la revisión, que faltaba.
             *
             * Aquí no se ponía, así que TODAS las revisiones programadas
             * nacían sin hotel, y un parte sin hotel no sale en la lista
             * de nadie: el filtro por hotel lo deja fuera siempre. Se
             * creaban puntualmente cada mes, nadie recibía el aviso y no
             * se encontraban ni buscando su código. En esta base había
             * 337 así, y entre ellas «Revisión de extintores y BIE», que
             * es de obligado cumplimiento: la que hay que poder
             * demostrar que se hizo.
             *
             * Se coge el de la propia revisión; si no lo tiene, el de la
             * ubicación donde se hace; y si tampoco, el único hotel que
             * haya. */
            'id_hotel'            => self::hotelDe($m),
            'origen'              => 'app',
            'fecha_creado_sin_hora' => substr($ahora, 0, 10),
            'id_version'          => 1,
            'borrado'             => 0,
            'updated_at'          => $ahora,
        ]);

        Repo::anotar([
            'id_ticket'     => $idTicket,
            'tipo'          => 'alta',
            'detalle'       => 'Generado automáticamente por el mantenimiento programado.',
            'id_estado'     => $estadoInicial,
            'id_estado_old' => $estadoInicial,
            'id_login'      => $m['id_persona'] === null ? null : (int)$m['id_persona'],
            'autor'         => $autor,
            'fecha'         => $ahora,
        ]);

        // Aviso a quien le toca
        if ($m['id_persona'] !== null) {
            Repo::notificar((int)$m['id_persona'], $idTicket,
                'Revisión programada: ' . $m['nombre'],
                'Vence el ' . gmdate('d/m/Y', strtotime((string)$m['proximo'])), 0, 'asignado');
        } elseif ($m['id_departamento'] !== null) {
            /* Sólo la gente de ese hotel: el departamento es común a
               la cadena, y sin filtrar se avisa a los técnicos de todas
               las casas de una revisión que no es suya. */
            $sqlM = 'SELECT id_login FROM login WHERE id_departamento = ? AND bloqueo = 0';
            $parM = [(int)$m['id_departamento']];
            if (Db::tablaExiste('hoteles') && !empty($m['id_hotel'])) {
                $sqlM .= ' AND (id_hotel = ? OR id_hotel IS NULL)';
                $parM[] = (int)$m['id_hotel'];
            }

            foreach (Db::all($sqlM, $parM) as $g) {
                Repo::notificar((int)$g['id_login'], $idTicket,
                    'Revisión programada para tu grupo',
                    $m['nombre'], 0, 'asignado_grupo');
            }
        }

        return $idTicket;
    }

    /* ---------------------------------------------------------------- */
    /** Historial de una revisión: todas las veces que se ha hecho. */
    public static function historial(int $idMantenimiento): array
    {
        $filas = Db::all(
            Repo::selectTicket() . ' WHERE t.id_mantenimiento = ? ORDER BY t.fecha_creado DESC LIMIT 60',
            [$idMantenimiento]
        );
        return array_map([Repo::class, 'normalizaTicket'], $filas);
    }
}
