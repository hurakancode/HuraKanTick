<?php
/**
 * Unidades con número de serie.
 *
 * Una unidad es un aparato concreto: el televisor que hay ahora en la
 * 1008, no «televisor» como tipo. Sólo existen para los elementos
 * marcados con `usa_serie`, que en una instalación real son unos pocos.
 *
 * Lo que se quiere poder responder:
 *   · qué aparatos hay ahora en la 1008
 *   · cuántos televisores ha tenido esa habitación y qué fue de cada uno
 *   · dónde está el número de serie tal
 */
declare(strict_types=1);

final class Unidades
{
    public const ESTADOS = [
        'activa'     => 'En servicio',
        'reparacion' => 'En reparación',
        'almacen'    => 'En almacén',
        'baja'       => 'De baja',
    ];

    public const MOTIVOS = [
        'rotura'    => 'Rota',
        'obsoleto'  => 'Obsoleta',
        'robo'      => 'Robada o desaparecida',
        'fin_vida'  => 'Fin de vida útil',
        'garantia'  => 'Cambiada en garantía',
        'otro'      => 'Otro motivo',
    ];

    /** Filtro de hotel, siempre presente en las consultas de unidades. */
    private static function hotel(): array
    {
        if (!class_exists('Hoteles') || !Db::tablaExiste('hoteles')) {
            return ['1 = 1', []];
        }
        return Hoteles::filtro('u');
    }

    private static function select(): string
    {
        return 'SELECT u.*, e.nombre_elemento AS elemento, e.id_familia,
                       f.nombre AS familia, f.color AS familia_color, f.icono AS familia_icono,
                       r.ruta AS zona_ruta, r.id_zona_3,
                       z3.nombre_zona_3 AS lugar,
                       s.numero_serie AS serie_anterior
                  FROM unidades u
                  LEFT JOIN elemento e  ON e.id_elemento = u.id_elemento
                  LEFT JOIN familias f  ON f.id_familia = e.id_familia
                  LEFT JOIN rel_zonas r ON r.id_rel = u.id_rel_zona
                  LEFT JOIN zona_3 z3   ON z3.id_zona_3 = r.id_zona_3
                  LEFT JOIN unidades s  ON s.id_unidad = u.id_sustituye';
    }

    public static function normaliza(array $u): array
    {
        foreach (['id_unidad', 'id_elemento'] as $k) {
            $u[$k] = (int)($u[$k] ?? 0);
        }
        foreach (['id_rel_zona', 'id_sustituye', 'id_zona_3', 'id_familia', 'id_alta'] as $k) {
            $u[$k] = $u[$k] === null ? null : (int)$u[$k];
        }
        $u['estado_nombre'] = self::ESTADOS[$u['estado']] ?? $u['estado'];
        $u['motivo_nombre'] = $u['motivo_baja'] ? (self::MOTIVOS[$u['motivo_baja']] ?? $u['motivo_baja']) : null;
        $u['en_servicio']   = $u['estado'] === 'activa';

        // Garantía: interesa saber si sigue viva antes de pagar una
        // reparación que cubre el fabricante.
        if (!empty($u['garantia_hasta'])) {
            $u['garantia_viva'] = strtotime((string)$u['garantia_hasta']) >= strtotime(gmdate('Y-m-d'));
        } else {
            $u['garantia_viva'] = null;
        }
        return $u;
    }

    /* ---------------------------------------------------------------- */
    /* Consultas                                                        */
    /* ---------------------------------------------------------------- */

    /** Una unidad por su identificador. */
    public static function una(int $id): ?array
    {
        [$hc, $hp] = self::hotel();
        $u = Db::one(self::select() . " WHERE $hc AND u.id_unidad = :uid", $hp + ['uid' => $id]);
        return $u === null ? null : self::normaliza($u);
    }

    /** Búsqueda por número de serie, parcial. */
    public static function buscar(string $q, int $limite = 40): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        [$hc, $hp] = self::hotel();
        $filas = Db::all(
            self::select() . " WHERE $hc AND " . Repo::sinAcentos('u.numero_serie') . " LIKE :q ESCAPE '!'
                               ORDER BY u.estado, u.numero_serie LIMIT $limite",
            $hp + ['q' => Repo::patron(Repo::planoTexto($q))]
        );
        return array_map([self::class, 'normaliza'], $filas);
    }

    /**
     * Todo lo que ha pasado por un sitio.
     *
     * Incluye lo que ya no está: si la 1008 ha tenido cinco televisores
     * y cuatro se dieron de baja, la pregunta es justo por esos cuatro.
     * Por eso se mira también el historial de movimientos y no sólo la
     * ubicación actual.
     *
     * @param bool $mismoLugar amplía al lugar entero (1008 y su baño)
     */
    public static function enUbicacion(int $idRel, ?int $idElemento = null, bool $mismoLugar = true): array
    {
        $zona = Db::one('SELECT id_zona_3 FROM rel_zonas WHERE id_rel = ?', [$idRel]);
        $usarLugar = $mismoLugar && $zona !== null && $zona['id_zona_3'] !== null;

        $cond = $usarLugar
            ? '(r.id_zona_3 = :z3 OR u.id_unidad IN (
                    SELECT m.id_unidad FROM unidades_mov m
                    JOIN rel_zonas r2 ON r2.id_rel = m.id_rel_zona
                    WHERE r2.id_zona_3 = :z3b))'
            : '(u.id_rel_zona = :rel OR u.id_unidad IN (
                    SELECT m.id_unidad FROM unidades_mov m WHERE m.id_rel_zona = :rel2))';

        $p = $usarLugar
            ? ['z3' => (int)$zona['id_zona_3'], 'z3b' => (int)$zona['id_zona_3']]
            : ['rel' => $idRel, 'rel2' => $idRel];

        if ($idElemento !== null) {
            $cond .= ' AND u.id_elemento = :el';
            $p['el'] = $idElemento;
        }

        [$hc, $hp] = self::hotel();
        $p += $hp;
        $filas = Db::all(
            self::select() . ' WHERE ' . $hc . ' AND ' . $cond . '
             ORDER BY u.id_elemento,
                      CASE u.estado WHEN \'activa\' THEN 0 WHEN \'reparacion\' THEN 1
                                    WHEN \'almacen\' THEN 2 ELSE 3 END,
                      u.fecha_alta DESC',
            $p
        );
        return array_map([self::class, 'normaliza'], $filas);
    }

    /**
     * Resumen por tipo de aparato en un sitio: cuántos ha habido y en qué
     * estado están. Es la respuesta a «la 1008 ha tenido cinco
     * televisores, cuatro de baja por rotura».
     */
    public static function resumenUbicacion(int $idRel, bool $mismoLugar = true): array
    {
        $unidades = self::enUbicacion($idRel, null, $mismoLugar);
        $porTipo = [];

        foreach ($unidades as $u) {
            $k = $u['id_elemento'];
            if (!isset($porTipo[$k])) {
                $porTipo[$k] = [
                    'id_elemento' => $k,
                    'elemento'    => $u['elemento'],
                    'familia'     => $u['familia'],
                    'color'       => $u['familia_color'],
                    'icono'       => $u['familia_icono'],
                    'total'       => 0,
                    'activas'     => 0,
                    'por_estado'  => [],
                    'por_motivo'  => [],
                ];
            }
            $porTipo[$k]['total']++;
            if ($u['estado'] === 'activa') {
                $porTipo[$k]['activas']++;
            }
            $e = $u['estado'];
            $porTipo[$k]['por_estado'][$e] = ($porTipo[$k]['por_estado'][$e] ?? 0) + 1;
            if ($u['motivo_baja']) {
                $m = $u['motivo_baja'];
                $porTipo[$k]['por_motivo'][$m] = ($porTipo[$k]['por_motivo'][$m] ?? 0) + 1;
            }
        }

        usort($porTipo, static fn($a, $b) => $b['total'] <=> $a['total']);
        return array_values($porTipo);
    }

    /** La vida de una unidad: movimientos y partes. */
    public static function historial(int $idUnidad): array
    {
        $nombre = Db::nombreCompleto('l');
        $movs = Db::all(
            "SELECT m.*, r.ruta AS zona_ruta, $nombre AS persona, t.titulo_ticket
               FROM unidades_mov m
               LEFT JOIN rel_zonas r ON r.id_rel = m.id_rel_zona
               LEFT JOIN login l ON l.id_login = m.id_login
               LEFT JOIN ticket_incidencias t ON t.id_ticket = m.id_ticket
              WHERE m.id_unidad = ?
              ORDER BY m.fecha DESC, m.id_mov DESC",
            [$idUnidad]
        );
        foreach ($movs as &$m) {
            $m['id_mov'] = (int)$m['id_mov'];
            $m['id_ticket'] = $m['id_ticket'] === null ? null : (int)$m['id_ticket'];
            $m['estado_nombre'] = self::ESTADOS[$m['estado']] ?? $m['estado'];
        }
        unset($m);

        $tickets = Db::all(
            Repo::selectTicket() . ' WHERE t.id_unidad = ? ORDER BY t.fecha_creado DESC LIMIT 50',
            [$idUnidad]
        );

        return [
            'movimientos' => $movs,
            'tickets'     => array_map([Repo::class, 'normalizaTicket'], $tickets),
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Escritura                                                        */
    /* ---------------------------------------------------------------- */

    public static function crear(array $d, int $idLogin): int
    {
        $serie = trim((string)($d['numero_serie'] ?? ''));
        if ($serie === '') {
            throw new ApiError('Falta el número de serie.', 422, ['campo' => 'numero_serie']);
        }
        $idElemento = Http::id($d['id_elemento'] ?? null);
        if ($idElemento === null) {
            throw new ApiError('Indica de qué aparato se trata.', 422, ['campo' => 'id_elemento']);
        }

        // El mismo número puede repetirse entre fabricantes distintos,
        // así que se avisa pero no se impide: bloquearlo obligaría a
        // inventarse números cuando dos aparatos coinciden.
        $repetido = Db::value(
            'SELECT id_unidad FROM unidades WHERE LOWER(numero_serie) = ? AND id_elemento = ? AND estado <> ?',
            [mb_strtolower($serie), $idElemento, 'baja']
        );
        if ($repetido !== null && empty($d['forzar'])) {
            throw new ApiError(
                'Ya hay un aparato de ese tipo en servicio con ese número de serie.',
                409, ['campo' => 'numero_serie', 'id_unidad' => (int)$repetido]
            );
        }

        $idRel = Http::id($d['id_rel_zona'] ?? null);
        $sustituye = Http::id($d['id_sustituye'] ?? null);

        Db::begin();
        try {
            $id = Db::insert('unidades', [
                'id_hotel'     => class_exists('Hoteles') ? Hoteles::actual() : null,
                'numero_serie' => mb_substr($serie, 0, 80),
                'id_elemento'  => $idElemento,
                'id_rel_zona'  => $idRel,
                'estado'       => isset(self::ESTADOS[$d['estado'] ?? '']) ? $d['estado'] : 'activa',
                'marca'        => mb_substr((string)($d['marca'] ?? ''), 0, 80) ?: null,
                'modelo'       => mb_substr((string)($d['modelo'] ?? ''), 0, 120) ?: null,
                'garantia_hasta' => !empty($d['garantia_hasta']) ? substr((string)$d['garantia_hasta'], 0, 10) : null,
                'notas'        => mb_substr((string)($d['notas'] ?? ''), 0, 500) ?: null,
                'id_sustituye' => $sustituye,
                'fecha_alta'   => !empty($d['fecha_alta']) ? substr((string)$d['fecha_alta'], 0, 10) : gmdate('Y-m-d'),
                'id_alta'      => $idLogin,
                'updated_at'   => Db::now(),
            ]);

            self::anotarMov($id, $idRel, 'activa', 'Alta del aparato', null, $idLogin);

            // Si sustituye a otro, aquel queda de baja automáticamente:
            // dos televisores no pueden estar puestos en la misma pared.
            if ($sustituye !== null) {
                self::cambiar($sustituye, [
                    'estado'      => 'baja',
                    'motivo_baja' => (string)($d['motivo_anterior'] ?? 'rotura'),
                    'motivo'      => 'Sustituido por ' . $serie,
                ], $idLogin);
            }

            Db::commit();
            return $id;
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** Cambia estado o ubicación, dejando rastro. */
    public static function cambiar(int $idUnidad, array $d, int $idLogin, ?int $idTicket = null): void
    {
        $u = Db::one('SELECT * FROM unidades WHERE id_unidad = ?', [$idUnidad]);
        if ($u === null) {
            throw new ApiError('Ese aparato no existe.', 404);
        }
        if (Db::tablaExiste('hoteles') && $u['id_hotel'] !== null) {
            Hoteles::exigir((int)$u['id_hotel']);
        }

        $datos = ['updated_at' => Db::now()];
        $huboCambio = false;

        if (isset($d['estado']) && isset(self::ESTADOS[$d['estado']]) && $d['estado'] !== $u['estado']) {
            $datos['estado'] = $d['estado'];
            $huboCambio = true;
            if ($d['estado'] === 'baja') {
                $datos['fecha_baja'] = gmdate('Y-m-d');
                $datos['motivo_baja'] = isset(self::MOTIVOS[$d['motivo_baja'] ?? ''])
                    ? $d['motivo_baja'] : 'otro';
            } else {
                $datos['fecha_baja'] = null;
                $datos['motivo_baja'] = null;
            }
        }

        if (array_key_exists('id_rel_zona', $d)) {
            $nuevo = Http::id($d['id_rel_zona']);
            if ($nuevo !== ($u['id_rel_zona'] === null ? null : (int)$u['id_rel_zona'])) {
                $datos['id_rel_zona'] = $nuevo;
                $huboCambio = true;
            }
        }

        foreach (['marca', 'modelo', 'notas'] as $c) {
            if (array_key_exists($c, $d)) {
                $datos[$c] = mb_substr((string)$d[$c], 0, 500) ?: null;
            }
        }
        if (array_key_exists('garantia_hasta', $d)) {
            $datos['garantia_hasta'] = $d['garantia_hasta'] ? substr((string)$d['garantia_hasta'], 0, 10) : null;
        }
        if (array_key_exists('numero_serie', $d) && trim((string)$d['numero_serie']) !== '') {
            $datos['numero_serie'] = mb_substr(trim((string)$d['numero_serie']), 0, 80);
        }

        Db::update('unidades', $datos, 'id_unidad = :id', ['id' => $idUnidad]);

        if ($huboCambio) {
            self::anotarMov(
                $idUnidad,
                $datos['id_rel_zona'] ?? ($u['id_rel_zona'] === null ? null : (int)$u['id_rel_zona']),
                (string)($datos['estado'] ?? $u['estado']),
                (string)($d['motivo'] ?? ''),
                $idTicket,
                $idLogin
            );
        }
    }

    private static function anotarMov(
        int $idUnidad, ?int $idRel, string $estado, string $motivo, ?int $idTicket, int $idLogin
    ): void {
        Db::insert('unidades_mov', [
            'id_unidad'   => $idUnidad,
            'id_rel_zona' => $idRel,
            'estado'      => $estado,
            'motivo'      => mb_substr($motivo, 0, 120) ?: null,
            'id_ticket'   => $idTicket,
            'id_login'    => $idLogin,
            'fecha'       => Db::now(),
        ]);
    }
}
