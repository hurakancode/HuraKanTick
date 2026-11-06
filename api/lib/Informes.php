<?php
/**
 * Motor de informes.
 *
 * Un informe es: unas condiciones, una forma de agrupar y una medida.
 * Con eso se responden preguntas como «cuántas incidencias de televisión
 * hubo el mes pasado» o «cuántas veces se ha reparado el grifo de la 214».
 *
 * Los nombres de columna nunca vienen del usuario: se eligen de una lista
 * cerrada. Los valores sí, y van siempre como parámetros vinculados.
 */
declare(strict_types=1);

final class Informes
{
    /* ---------------------------------------------------------------- */
    /* Campos sobre los que se puede filtrar y agrupar                  */
    /* ---------------------------------------------------------------- */
    public static function campos(): array
    {
        return [
            'estado'       => ['sql' => 't.id_estado',           'etiqueta' => 'Estado',            'tipo' => 'catalogo', 'catalogo' => 'estados',       'grupo_sql' => 'e.nombre_estado'],
            'prioridad'    => ['sql' => 't.id_prioridad',        'etiqueta' => 'Prioridad',         'tipo' => 'catalogo', 'catalogo' => 'prioridades',   'grupo_sql' => 'p.nombre'],
            'familia'      => ['sql' => 'COALESCE(t.id_familia, el.id_familia)', 'etiqueta' => 'Familia', 'tipo' => 'catalogo', 'catalogo' => 'familias', 'grupo_sql' => 'fa.nombre'],
            'clase'        => ['sql' => 'fa.clase',              'etiqueta' => 'Clase de parte',    'tipo' => 'catalogo', 'catalogo' => 'clases',        'grupo_sql' => 'fa.clase'],
            'elemento'     => ['sql' => 't.id_elemento',         'etiqueta' => 'Tipo de avería',    'tipo' => 'catalogo', 'catalogo' => 'elementos',     'grupo_sql' => 'el.nombre_elemento'],
            'departamento' => ['sql' => 't.id_grupo_asignado',   'etiqueta' => 'Grupo asignado',    'tipo' => 'catalogo', 'catalogo' => 'departamentos', 'grupo_sql' => 'd.nombre'],
            'asignado'     => ['sql' => 't.id_persona_asignada', 'etiqueta' => 'Persona asignada',  'tipo' => 'catalogo', 'catalogo' => 'usuarios',      'grupo_sql' => 'la.nombre'],
            'creador'      => ['sql' => 't.id_realiza_accion',   'etiqueta' => 'Quién lo abrió',    'tipo' => 'catalogo', 'catalogo' => 'usuarios',      'grupo_sql' => 'lc.nombre'],
            'zona'         => ['sql' => 't.id_rel_zona',         'etiqueta' => 'Ubicación exacta',  'tipo' => 'catalogo', 'catalogo' => 'zonas',         'grupo_sql' => 'r.ruta'],
            'planta'       => ['sql' => 'r.id_zona_1',           'etiqueta' => 'Planta',            'tipo' => 'catalogo', 'catalogo' => 'zona_1',        'grupo_sql' => 'z1.nombre_zona_1'],
            'area'         => ['sql' => 'r.id_zona_2',           'etiqueta' => 'Zona',              'tipo' => 'catalogo', 'catalogo' => 'zona_2',        'grupo_sql' => 'z2.nombre_zona_2'],
            'lugar'        => ['sql' => 'r.id_zona_3',           'etiqueta' => 'Lugar / habitación','tipo' => 'catalogo', 'catalogo' => 'zona_3',        'grupo_sql' => 'z3.nombre_zona_3'],
            'sublugar'     => ['sql' => 'r.id_zona_4',           'etiqueta' => 'Sublugar',          'tipo' => 'catalogo', 'catalogo' => 'zona_4',        'grupo_sql' => 'z4.nombre_zona_4'],
            'origen'       => ['sql' => 't.origen',              'etiqueta' => 'Origen',            'tipo' => 'texto',    'grupo_sql' => 't.origen'],
            'creado'       => ['sql' => 't.fecha_creado',        'etiqueta' => 'Fecha de alta',     'tipo' => 'fecha',    'grupo_sql' => null],
            'cierre'       => ['sql' => 't.fecha_cierre',        'etiqueta' => 'Fecha de cierre',   'tipo' => 'fecha',    'grupo_sql' => null],
            'limite'       => ['sql' => 't.fecha_limite',        'etiqueta' => 'Plazo',             'tipo' => 'fecha',    'grupo_sql' => null],
            'minutos'      => ['sql' => 't.minutos_trabajo',     'etiqueta' => 'Minutos dedicados', 'tipo' => 'numero',   'grupo_sql' => null],
            'coste'        => ['sql' => 't.coste',               'etiqueta' => 'Coste',             'tipo' => 'numero',   'grupo_sql' => null],
            'titulo'       => ['sql' => 't.titulo_ticket',       'etiqueta' => 'Resumen',           'tipo' => 'texto',    'grupo_sql' => null],
            'detalle'      => ['sql' => 't.detalle_ticket',      'etiqueta' => 'Detalle',           'tipo' => 'texto',    'grupo_sql' => null],
            'abierto'      => ['sql' => 'COALESCE(e.es_final,0)','etiqueta' => 'Abierto o cerrado', 'tipo' => 'booleano', 'grupo_sql' => null],
            'vencido'      => ['sql' => 'vencido',               'etiqueta' => 'Fuera de plazo',    'tipo' => 'especial', 'grupo_sql' => null],
            'adjuntos'     => ['sql' => 'n_adjuntos',            'etiqueta' => 'Número de fotos',   'tipo' => 'especial', 'grupo_sql' => null],
        ];
    }

    /**
     * Los campos propios de cada tipo de avería también se pueden
     * consultar. Se identifican con el prefijo «campo:» seguido de su
     * clave, así no chocan nunca con los campos fijos.
     */
    public static function camposPropios(): array
    {
        if (!Db::tablaExiste('campos_elemento')) {
            return [];
        }
        $out = [];
        foreach (Db::all(
            /* También los retirados.
             *
             * Al quitar un campo, sus datos se conservan —el borrado sólo
             * lo desactiva—. Si además desapareciera de aquí, cualquier
             * informe guardado que lo usara reventaría con un «campo
             * desconocido», y quien lo hubiera montado hace un año no
             * tendría forma de saber por qué. Sigue estando, marcado. */
            'SELECT c.id_campo, c.clave, c.etiqueta, c.tipo, c.unidad, c.opciones,
                    c.activo, e.nombre_elemento
               FROM campos_elemento c
               LEFT JOIN elemento e ON e.id_elemento = c.id_elemento
              ORDER BY c.activo DESC, c.orden, c.id_campo'
        ) as $c) {
            $out['campo:' . $c['clave']] = [
                'id_campo' => (int)$c['id_campo'],
                'clave'    => $c['clave'],
                'etiqueta' => $c['etiqueta'] . ($c['unidad'] ? ' (' . $c['unidad'] . ')' : '')
                              . ((int)($c['activo'] ?? 1) === 1 ? '' : ' · retirado'),
                'retirado' => (int)($c['activo'] ?? 1) !== 1,
                'tipo'     => match ($c['tipo']) {
                    'numero', 'temperatura' => 'numero',
                    'lista'                 => 'lista_propia',
                    'interruptor'           => 'booleano',
                    'fecha'                 => 'fecha',
                    default                 => 'texto',
                },
                'opciones' => $c['opciones'] ? json_decode((string)$c['opciones'], true) : null,
                'elemento' => $c['nombre_elemento'],
            ];
        }
        return $out;
    }

    /** Agrupaciones por periodo, además de por campo. */
    public static function periodos(): array
    {
        return ['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes', 'trimestre' => 'Trimestre', 'anio' => 'Año'];
    }

    public static function operadores(): array
    {
        return [
            'es'        => 'es',
            'no_es'     => 'no es',
            'en'        => 'es uno de',
            'contiene'  => 'contiene',
            'no_contiene' => 'no contiene',
            'empieza'   => 'empieza por',
            'mayor'     => 'mayor que',
            'menor'     => 'menor que',
            'entre'     => 'entre',
            'vacio'     => 'está vacío',
            'no_vacio'  => 'tiene algo',
            'ultimos_dias' => 'en los últimos N días',
        ];
    }

    public static function medidas(): array
    {
        return [
            'cuenta'         => 'Número de partes',
            'minutos'        => 'Minutos dedicados',
            'coste'          => 'Coste total',
            'media_horas'    => 'Horas medias hasta el cierre',
            'porcentaje_plazo' => 'Porcentaje resuelto en plazo',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Construcción de la consulta                                      */
    /* ---------------------------------------------------------------- */
    private static function juntas(): string
    {
        return "FROM ticket_incidencias t
                LEFT JOIN estados      e  ON e.id_estado       = t.id_estado
                LEFT JOIN prioridades  p  ON p.id_prioridad    = t.id_prioridad
                LEFT JOIN elemento     el ON el.id_elemento    = t.id_elemento
                LEFT JOIN familias     fa ON fa.id_familia    = COALESCE(t.id_familia, el.id_familia)
                LEFT JOIN departamentos d ON d.id_departamento = t.id_grupo_asignado
                LEFT JOIN rel_zonas    r  ON r.id_rel          = t.id_rel_zona
                LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
                LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
                LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
                LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4
                LEFT JOIN login la ON la.id_login = t.id_persona_asignada
                LEFT JOIN login lc ON lc.id_login = t.id_realiza_accion";
    }

    /**
     * Traduce una condición a SQL. Devuelve [sql, parámetros].
     * El campo se busca en la lista cerrada: nunca se interpola texto libre.
     */
    private static function condicion(array $c, int $i): array
    {
        $campos = self::campos() + self::camposComoDefinicion();
        $clave  = (string)($c['campo'] ?? '');
        if (!isset($campos[$clave])) {
            throw new ApiError("Campo desconocido en las condiciones: «{$clave}».", 422);
        }

        $def = $campos[$clave];
        $col = $def['sql'];

        $op  = (string)($c['operador'] ?? 'es');
        $val = $c['valor'] ?? null;
        $p   = "c$i";
        // Un campo propio no está en la tabla de partes: se resuelve con
        // una subconsulta sobre sus valores. Se hace así, y no con un
        // JOIN, para que varias condiciones sobre campos distintos no se
        // multipliquen entre sí.
        if (!empty($def['es_campo_propio'])) {
            $col = '(SELECT v.valor FROM valores_campo v
                      WHERE v.id_ticket = t.id_ticket AND v.id_campo = ' . (int)$def['id_campo'] . ')';
            // Los numéricos se comparan como números, no como texto:
            // si no, «9» saldría mayor que «28».
            if (in_array($op, ['mayor', 'menor', 'entre'], true) && ($def['tipo'] ?? '') === 'numero') {
                $col = Db::driver() === 'sqlite'
                    ? "CAST($col AS REAL)"
                    : "CAST($col AS DECIMAL(12,3))";
            }
        }

        // En un campo de catálogo hay dos formas de referirse a un valor:
        // por su identificador (lo que manda la interfaz) o por su nombre
        // (lo que escribe una persona). Se admiten las dos: preguntar por
        // «televisión» o por la habitación «317» tiene que funcionar sin
        // saberse el número interno.
        $esCatalogo = ($def['tipo'] ?? '') === 'catalogo' && !empty($def['grupo_sql']);
        $colNombre  = $esCatalogo ? $def['grupo_sql'] : $col;
        if ($esCatalogo && $clave === 'asignado') { $colNombre = Db::nombreCompleto('la'); }
        if ($esCatalogo && $clave === 'creador')  { $colNombre = Db::nombreCompleto('lc'); }

        if ($esCatalogo) {
            // «por» decide contra qué se compara. Hace falta porque los
            // nombres de habitación son números: «317» es a la vez un
            // nombre plausible y un identificador plausible, y adivinarlo
            // daría resultados vacíos sin explicación.
            //   'id'     → contra el identificador (lo que manda la interfaz)
            //   'nombre' → contra el texto visible
            //   ausente  → texto si el operador es de texto o el valor no
            //              es un número; identificador en el resto
            $por = (string)($c['por'] ?? '');
            $porTexto = in_array($op, ['contiene', 'no_contiene', 'empieza'], true);

            if ($por === 'nombre') {
                $col = $colNombre;
            } elseif ($por !== 'id') {
                $noNumerico = in_array($op, ['es', 'no_es'], true)
                    && $val !== null && $val !== '' && !is_numeric($val);
                $listaNoNumerica = $op === 'en' && is_array($val) && $val
                    && count(array_filter((array)$val, 'is_numeric')) === 0;
                if ($porTexto || $noNumerico || $listaNoNumerica) {
                    $col = $colNombre;
                }
            }
        }

        // Dos campos son calculados y necesitan su propia expresión.
        if ($clave === 'vencido') {
            $sql = "(t.fecha_limite IS NOT NULL AND t.fecha_limite < :{$p}_ahora AND COALESCE(e.es_final,0) = 0)";
            return [Http::bool($val) ? $sql : "NOT $sql", [$p . '_ahora' => Db::now()]];
        }
        if ($clave === 'adjuntos') {
            $col = '(SELECT COUNT(*) FROM adjuntos a WHERE a.id_ticket = t.id_ticket AND a.borrado = 0)';
        }
        if ($clave === 'abierto') {
            return [Http::bool($val) ? 'COALESCE(e.es_final,0) = 0' : 'COALESCE(e.es_final,0) = 1', []];
        }

        switch ($op) {
            case 'es':
                if ($val === null || $val === '' ) { return ["$col IS NULL", []]; }
                return ["$col = :$p", [$p => $val]];

            case 'no_es':
                return ["($col <> :$p OR $col IS NULL)", [$p => $val]];

            case 'en':
                $lista = array_values(array_filter((array)$val, static fn($v) => $v !== '' && $v !== null));
                if (!$lista) { return ['1 = 0', []]; }
                $marcas = [];
                $params = [];
                foreach ($lista as $k => $v) {
                    $marcas[] = ":{$p}_$k";
                    $params["{$p}_$k"] = $v;
                }
                return ["$col IN (" . implode(',', $marcas) . ')', $params];

            case 'contiene':
                return ["LOWER($col) LIKE :$p", [$p => '%' . mb_strtolower((string)$val) . '%']];

            case 'no_contiene':
                return ["(LOWER($col) NOT LIKE :$p OR $col IS NULL)", [$p => '%' . mb_strtolower((string)$val) . '%']];

            case 'empieza':
                return ["LOWER($col) LIKE :$p", [$p => mb_strtolower((string)$val) . '%']];

            case 'mayor':
                return ["$col > :$p", [$p => $val]];

            case 'menor':
                return ["$col < :$p", [$p => $val]];

            case 'entre':
                $a = is_array($val) ? ($val[0] ?? null) : null;
                $b = is_array($val) ? ($val[1] ?? null) : null;
                if ($a === null || $b === null) { return ['1 = 1', []]; }
                return ["$col BETWEEN :{$p}_a AND :{$p}_b", ["{$p}_a" => $a, "{$p}_b" => $b]];

            case 'ultimos_dias':
                $n = max(1, (int)$val);
                return ["$col >= :$p", [$p => gmdate('Y-m-d H:i:s', time() - $n * 86400)]];

            case 'vacio':
                return ["($col IS NULL OR $col = '')", []];

            case 'no_vacio':
                return ["($col IS NOT NULL AND $col <> '')", []];
        }

        throw new ApiError("Operador desconocido: «{$op}».", 422);
    }

    /** Los campos propios en el mismo formato que los fijos. */
    private static function camposComoDefinicion(): array
    {
        $out = [];
        foreach (self::camposPropios() as $clave => $c) {
            $out[$clave] = [
                'sql'      => '',                 // lo compone condicion()
                'etiqueta' => $c['etiqueta'],
                'tipo'     => $c['tipo'] === 'lista_propia' ? 'texto' : $c['tipo'],
                'grupo_sql'=> '(SELECT v.valor FROM valores_campo v
                                 WHERE v.id_ticket = t.id_ticket AND v.id_campo = ' . $c['id_campo'] . ')',
                'es_campo_propio' => true,
                'id_campo' => $c['id_campo'],
            ];
        }
        return $out;
    }

    /** Expresión de agrupación por periodo, portable entre motores. */
    private static function agrupaPeriodo(string $periodo, string $col): string
    {
        if (Db::driver() === 'sqlite') {
            return match ($periodo) {
                'dia'       => "strftime('%Y-%m-%d', $col)",
                'semana'    => "strftime('%Y-S%W', $col)",
                'mes'       => "strftime('%Y-%m', $col)",
                'trimestre' => "strftime('%Y', $col) || '-T' || ((CAST(strftime('%m', $col) AS INTEGER) + 2) / 3)",
                default     => "strftime('%Y', $col)",
            };
        }
        return match ($periodo) {
            'dia'       => "DATE_FORMAT($col, '%Y-%m-%d')",
            'semana'    => "DATE_FORMAT($col, '%x-S%v')",
            'mes'       => "DATE_FORMAT($col, '%Y-%m')",
            'trimestre' => "CONCAT(YEAR($col), '-T', QUARTER($col))",
            default     => "YEAR($col)",
        };
    }

    private static function expresionMedida(string $medida): string
    {
        return match ($medida) {
            'minutos'     => 'COALESCE(SUM(t.minutos_trabajo), 0)',
            'coste'       => 'COALESCE(SUM(t.coste), 0)',
            'media_horas' => Db::driver() === 'sqlite'
                ? "ROUND(AVG(CASE WHEN t.fecha_cierre IS NOT NULL
                        THEN (julianday(t.fecha_cierre) - julianday(t.fecha_creado)) * 24 END), 1)"
                : "ROUND(AVG(CASE WHEN t.fecha_cierre IS NOT NULL
                        THEN TIMESTAMPDIFF(MINUTE, t.fecha_creado, t.fecha_cierre) / 60 END), 1)",
            'porcentaje_plazo' => "ROUND(100.0 * SUM(CASE WHEN t.fecha_cierre IS NOT NULL
                        AND (t.fecha_limite IS NULL OR t.fecha_cierre <= t.fecha_limite) THEN 1 ELSE 0 END)
                        / NULLIF(SUM(CASE WHEN t.fecha_cierre IS NOT NULL THEN 1 ELSE 0 END), 0), 1)",
            default       => 'COUNT(t.id_ticket)',
        };
    }

    /* ---------------------------------------------------------------- */
    /* Ejecución                                                        */
    /* ---------------------------------------------------------------- */
    /**
     * @param array $d  condiciones, union ('y'|'o'), agrupar, periodo,
     *                  medida, orden, limite, detalle
     */
    public static function ejecutar(array $d, array $usuario): array
    {
        $condiciones = is_array($d['condiciones'] ?? null) ? $d['condiciones'] : [];
        $union  = strtolower((string)($d['union'] ?? 'y')) === 'o' ? ' OR ' : ' AND ';
        $medida = (string)($d['medida'] ?? 'cuenta');
        if (!isset(self::medidas()[$medida])) { $medida = 'cuenta'; }

        $where  = ['t.borrado = 0'];
        $params = [];

        // Visibilidad: el informe nunca enseña más de lo que la persona
        // puede ver en el listado.
        [$sqlVis, $pVis] = Permisos::filtroVisibilidad($usuario, 't', 'r');
        if ($sqlVis !== '') {
            $where[] = $sqlVis;
            $params += $pVis;
        }

        $trozos = [];
        foreach ($condiciones as $i => $c) {
            [$sql, $p] = self::condicion(is_array($c) ? $c : [], $i);
            $trozos[] = $sql;
            $params  += $p;
        }
        if ($trozos) {
            $where[] = '(' . implode($union, $trozos) . ')';
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $campos   = self::campos() + self::camposComoDefinicion();

        // ---- Agrupación ----
        $agrupar = (string)($d['agrupar'] ?? '');
        $periodo = (string)($d['periodo'] ?? '');
        $etiqueta = 'Total';
        $expresion = "'Total'";

        if ($periodo !== '' && isset(self::periodos()[$periodo])) {
            $colFecha = $campos[$d['campo_fecha'] ?? 'creado']['sql'] ?? 't.fecha_creado';
            $expresion = self::agrupaPeriodo($periodo, $colFecha);
            $etiqueta  = self::periodos()[$periodo];
        } elseif ($agrupar !== '' && isset($campos[$agrupar]) && $campos[$agrupar]['grupo_sql']) {
            $expresion = $campos[$agrupar]['grupo_sql'];
            $etiqueta  = $campos[$agrupar]['etiqueta'];
            if ($agrupar === 'asignado')  { $expresion = Db::nombreCompleto('la'); }
            if ($agrupar === 'creador')   { $expresion = Db::nombreCompleto('lc'); }
        }

        $medidaSql = self::expresionMedida($medida);
        $limite    = min(max((int)($d['limite'] ?? 50), 1), 500);
        $orden     = ($d['orden'] ?? 'valor') === 'etiqueta' ? 'etiqueta ASC' : 'valor DESC';

        $sql = "SELECT COALESCE($expresion, '(sin dato)') AS etiqueta,
                       $medidaSql AS valor,
                       COUNT(t.id_ticket) AS n_partes
                " . self::juntas() . $sqlWhere . "
                GROUP BY $expresion
                ORDER BY $orden
                LIMIT $limite";

        $filas = Db::all($sql, $params);
        foreach ($filas as &$f) {
            $f['valor']    = $f['valor'] === null ? 0 : (float)$f['valor'];
            $f['n_partes'] = (int)$f['n_partes'];
        }
        unset($f);

        // ---- Totales ----
        $total = Db::one(
            "SELECT COUNT(t.id_ticket) AS partes,
                    COALESCE(SUM(t.minutos_trabajo),0) AS minutos,
                    COALESCE(SUM(t.coste),0) AS coste,
                    SUM(CASE WHEN COALESCE(e.es_final,0)=0 THEN 1 ELSE 0 END) AS abiertos
             " . self::juntas() . $sqlWhere,
            $params
        ) ?? [];

        $resultado = [
            'etiqueta_grupo' => $etiqueta,
            'medida'         => $medida,
            'medida_nombre'  => self::medidas()[$medida],
            'filas'          => $filas,
            'total'          => [
                'partes'   => (int)($total['partes'] ?? 0),
                'minutos'  => (int)($total['minutos'] ?? 0),
                'coste'    => round((float)($total['coste'] ?? 0), 2),
                'abiertos' => (int)($total['abiertos'] ?? 0),
            ],
            'generado'       => Db::now(),
        ];

        // ---- Detalle opcional: los partes que hay detrás ----
        if (!empty($d['detalle'])) {
            $resultado['partes'] = array_map(
                [Repo::class, 'normalizaTicket'],
                Db::all(
                    /* La subconsulta va envuelta en una tabla derivada.
                       «IN (SELECT … LIMIT 300)» lo admite SQLite pero no
                       MySQL ni MariaDB («doesn't yet support 'LIMIT & IN'»):
                       en el motor de producción, pedir los partes de un
                       informe daba error 500. Así vale en los dos. */
                    Repo::selectTicket() . ' WHERE t.id_ticket IN (SELECT sub.id_ticket FROM (SELECT t.id_ticket '
                    . self::juntas() . $sqlWhere . ' LIMIT 300) sub) ORDER BY t.fecha_creado DESC',
                    $params
                )
            );
        }

        return $resultado;
    }

    /** Las opciones que la interfaz necesita para montar el formulario. */
    public static function definiciones(): array
    {
        $campos = [];
        foreach (self::campos() as $k => $c) {
            $campos[] = [
                'clave'    => $k,
                'etiqueta' => $c['etiqueta'],
                'tipo'     => $c['tipo'],
                'catalogo' => $c['catalogo'] ?? null,
                'agrupable'=> $c['grupo_sql'] !== null,
            ];
        }
        foreach (self::camposPropios() as $clave => $c) {
            $campos[] = [
                'clave'    => $clave,
                'etiqueta' => $c['etiqueta'],
                'tipo'     => $c['tipo'],
                'catalogo' => null,
                'opciones' => $c['opciones'],
                'agrupable'=> true,
                'propio'   => true,
                // Se marca el retirado para que, al montar un informe, se
                // vea que ese campo ya no se rellena en partes nuevos.
                'retirado' => !empty($c['retirado']),
                'elemento' => $c['elemento'],
            ];
        }

        return [
            'campos'     => $campos,
            'operadores' => self::operadores(),
            'medidas'    => self::medidas(),
            'periodos'   => self::periodos(),
        ];
    }
}
