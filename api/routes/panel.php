<?php
/**
 * GET /api/panel — indicadores para la pantalla de inicio.
 * Todo se calcula en una tanda de consultas ligeras y la app lo cachea.
 */
declare(strict_types=1);

// Esta ruta despierta los partes programados que ya tocan, y para eso
// usa Tickets, que no se carga sola.
require_once __DIR__ . '/../lib/Tickets.php';
require_once __DIR__ . '/../lib/Mantenimiento.php';

if ($metodo !== 'GET') {
    throw new ApiError('Método no permitido.', 405);
}

$u   = Auth::exigir();

/* Lo programado que ya toca vuelve a la cola antes de montar la lista.
   Es un UPDATE con índice: cuesta nada y evita depender de una tarea
   programada, que en un hotel es una cosa más que puede dejar de
   funcionar sin que nadie se entere. */
try { Tickets::despertarProgramados(); } catch (Throwable $e) { /* la lista se da igual */ }

/* Y los mantenimientos que tocan, por la misma razón: antes esperaban a
   un cron que casi nunca estaba puesto. Como mucho una vez al día, con
   candado para que no se dispare treinta veces al abrir el turno. */
try { Mantenimiento::latido(); } catch (Throwable $e) { /* la lista se da igual */ }

/* Y se vacía lo que haya pendiente de mandar a Teams. Va aquí, con los
   demás latidos, para no depender de un cron que nadie pone. Son como
   mucho quince mensajes por vuelta y con tiempos cortos: si Microsoft
   tarda, se reintenta en la siguiente, no se queda nadie esperando. */
if (class_exists('Teams') && Teams::encendido()) {
    try { Teams::vaciarBandeja(); } catch (Throwable $e) { /* la lista se da igual */ }
}

$yo  = (int)$u['id_login'];

/**
 * Perfil de la pantalla de inicio.
 *
 * Los tres grupos de un hotel abren la aplicación por motivos distintos:
 * recepción para avisar de lo que le cuenta un cliente, housekeeping para
 * avisar y para comprobar que una habitación se puede entregar, y los
 * técnicos para trabajar su cola. Enseñarles a los tres el mismo panel
 * con la carga del equipo y el tiempo medio de resolución es cargarles
 * de datos que no van a mirar.
 */
$perfilForzado = (string)Permisos::ajuste('panel_perfil', 'auto');
$rol = (string)($u['rol'] ?? 'tecnico');
$dep = $u['id_departamento'] === null ? null : (int)$u['id_departamento'];
$nombreDep = $dep ? mb_strtolower((string)Db::value(
    'SELECT nombre FROM departamentos WHERE id_departamento = ?', [$dep])) : '';

if ($perfilForzado === 'completo') {
    $perfil = 'responsable';
} elseif ($perfilForzado === 'simple') {
    $perfil = 'reporta';
} elseif (Auth::esResponsable($u)) {
    $perfil = 'responsable';
} elseif ($rol === 'reportador') {
    $perfil = 'reporta';
} elseif (str_contains($nombreDep, 'limpieza') || str_contains($nombreDep, 'piso')
       || str_contains($nombreDep, 'housekeeping')) {
    $perfil = 'limpieza';
} elseif (str_contains($nombreDep, 'recepc')) {
    $perfil = 'reporta';
} else {
    $perfil = 'tecnico';
}
$hoy = gmdate('Y-m-d');
$ahora = Db::now();

$abiertos = 'COALESCE(e.es_final,0) = 0';

/* Lo aparcado para más adelante no cuenta en las cifras del inicio: no
   es trabajo pendiente de hoy, es trabajo con fecha. Verlo entre lo
   urgente hace que alguien lo empiece antes de tiempo. */
if (in_array('visible_desde', Db::columnas('ticket_incidencias'), true)) {
    $abiertos .= " AND (t.visible_desde IS NULL OR t.visible_desde <= '" . Db::now() . "')";
}

// Las cifras del panel se calculan sobre lo que esta persona puede ver.
// Si no, un técnico leería en portada un total de partes que luego no
// aparecen en el listado, y eso confunde más que ayudar.
[$sqlVis, $pVis] = Permisos::filtroVisibilidad($u);
$visJoin = 'LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona';
$visWhere = $sqlVis === '' ? '' : ' AND ' . $sqlVis;

$base = 'FROM ticket_incidencias t
         LEFT JOIN estados e ON e.id_estado = t.id_estado
         WHERE t.borrado = 0' . $visWhere;

$cuenta = static function (string $extra, array $extraParams = []) use ($base, $pVis): int {
    return (int)Db::value("SELECT COUNT(*) $base $extra", array_merge($pVis, $extraParams));
};

/* Las siete cifras del resumen, en UNA consulta.
 *
 * Eran siete COUNT(*) seguidos, cada uno barriendo la tabla entera por
 * su cuenta. Son todas la misma consulta con una condición distinta, así
 * que se piden juntas y se cuenta cada cosa por el camino: se lee la
 * tabla una vez en vez de siete.
 *
 * Los marcadores (:yo, :ahora, :hoy) salen UNA sola vez cada uno a
 * propósito: con un nombre repetido, MySQL sin emulación de preparadas
 * no falla —simplemente no encuentra nada— y eso ya costó un fallo
 * invisible en el buscador.
 */
$sum = static fn(string $cond): string =>
    "SUM(CASE WHEN $cond THEN 1 ELSE 0 END)";

$fila = Db::one(
    'SELECT ' . $sum($abiertos) . ' AS abiertos, '
  . $sum("$abiertos AND t.id_persona_asignada = :yo") . ' AS mios, '
    // Sin nadie que lo haga. El departamento no cuenta aquí: dice de
    // quién es el trabajo, no si alguien se ha hecho cargo.
  . $sum("$abiertos AND t.id_persona_asignada IS NULL") . ' AS sin_asignar, '
  . $sum("$abiertos AND t.fecha_limite IS NOT NULL AND t.fecha_limite < :ahora") . ' AS vencidos, '
  . $sum("$abiertos AND t.id_prioridad >= 3") . ' AS urgentes, '
  . $sum('t.fecha_creado >= :hoy') . ' AS hoy, '
  . $sum('t.fecha_cierre >= :hoy2') . ' AS cerrados_hoy '
  . $base,
    array_merge($pVis, ['yo' => $yo, 'ahora' => $ahora,
                        'hoy' => $hoy . ' 00:00:00', 'hoy2' => $hoy . ' 00:00:00'])
) ?? [];

$resumen = [];
foreach (['abiertos', 'mios', 'sin_asignar', 'vencidos', 'urgentes',
          'hoy', 'cerrados_hoy'] as $clave) {
    $resumen[$clave] = (int)($fila[$clave] ?? 0);
}

$porEstado = Db::all(
    "SELECT e.id_estado, e.nombre_estado, e.color,
            (SELECT COUNT(*) FROM ticket_incidencias t
              WHERE t.id_estado = e.id_estado AND t.borrado = 0"
            . ($sqlVis === '' ? '' : ' AND ' . str_replace('e.es_final', '0', $sqlVis)) . ") AS n
       FROM estados e
      WHERE COALESCE(e.activo,1) = 1
      ORDER BY e.orden, e.id_estado",
    $pVis
);
foreach ($porEstado as &$r) { $r['id_estado'] = (int)$r['id_estado']; $r['n'] = (int)$r['n']; }
unset($r);

$porElemento = Db::all(
    "SELECT el.id_elemento, el.nombre_elemento AS nombre, el.icono, COUNT(t.id_ticket) AS n
       FROM elemento el
       JOIN ticket_incidencias t ON t.id_elemento = el.id_elemento AND t.borrado = 0
       LEFT JOIN estados e ON e.id_estado = t.id_estado
      WHERE $abiertos $visWhere
      GROUP BY el.id_elemento, el.nombre_elemento, el.icono
      ORDER BY n DESC LIMIT 6",
    $pVis
);
foreach ($porElemento as &$r) { $r['id_elemento'] = (int)$r['id_elemento']; $r['n'] = (int)$r['n']; }
unset($r);

/* Actividad de los últimos 14 días: DOS consultas, no veintiocho.
 *
 * Esto hacía un COUNT(*) por día y por sentido —catorce días, creados y
 * cerrados— así que barría la tabla entera veintiocho veces cada vez que
 * alguien abría el panel. Más las siete del resumen de arriba: treinta y
 * cinco barridos. Con los veintiocho partes de la demostración no se
 * nota; con cien mil el panel tardaba 1,3 segundos, y el panel es la
 * primera pantalla que abren cincuenta personas cada mañana.
 *
 * Ahora se piden los catorce días de una vez, agrupando por día, y se
 * rellenan los huecos aquí. Mismo resultado, dos consultas.
 *
 * SUBSTR(fecha,1,10) se escribe igual en SQLite y en MySQL; DATE() no
 * se comporta igual en los dos, y este código tiene que valer para los
 * dos motores sin ramas.
 */
$desdeSerie = gmdate('Y-m-d', strtotime('-13 day'));

$porDia = static function (string $columna) use ($base, $pVis, $desdeSerie): array {
    $filas = Db::all(
        "SELECT SUBSTR(t.$columna, 1, 10) AS dia, COUNT(*) AS n $base"
      . " AND t.$columna >= :desde GROUP BY SUBSTR(t.$columna, 1, 10)",
        array_merge($pVis, ['desde' => $desdeSerie . ' 00:00:00'])
    );
    $fuera = [];
    foreach ($filas as $f) { $fuera[(string)$f['dia']] = (int)$f['n']; }
    return $fuera;
};

$creadosPorDia  = $porDia('fecha_creado');
$cerradosPorDia = $porDia('fecha_cierre');

$serie = [];
for ($i = 13; $i >= 0; $i--) {
    $dia = gmdate('Y-m-d', strtotime("-$i day"));
    $serie[] = [
        'dia'      => $dia,
        'creados'  => $creadosPorDia[$dia] ?? 0,
        'cerrados' => $cerradosPorDia[$dia] ?? 0,
    ];
}

$nombre = Db::nombreCompleto('l');
$carga = Db::all(
    "SELECT l.id_login, $nombre AS nombre_completo, COUNT(t.id_ticket) AS n
       FROM login l
       JOIN ticket_incidencias t ON t.id_persona_asignada = l.id_login AND t.borrado = 0
       LEFT JOIN estados e ON e.id_estado = t.id_estado
      WHERE $abiertos $visWhere
      GROUP BY l.id_login, l.nombre, l.apellido1, l.apellido2
      ORDER BY n DESC LIMIT 8",
    $pVis
);
foreach ($carga as &$r) { $r['id_login'] = (int)$r['id_login']; $r['n'] = (int)$r['n']; }
unset($r);

// Tiempo medio de resolución (horas) de los últimos 30 días
$cerrados = Db::all(
    "SELECT t.fecha_creado, t.fecha_cierre $base AND t.fecha_cierre IS NOT NULL AND t.fecha_cierre >= :hace30",
    array_merge($pVis, ['hace30' => gmdate('Y-m-d H:i:s', strtotime('-30 day'))])
);
$horas = [];
foreach ($cerrados as $c) {
    $d = strtotime((string)$c['fecha_cierre']) - strtotime((string)$c['fecha_creado']);
    if ($d > 0) { $horas[] = $d / 3600; }
}

/* ------------------------------------------------------------------ */
/* Bloques propios de cada perfil                                     */
/* ------------------------------------------------------------------ */
$extra = [];

/* Resueltos o cerrados, y los que están vivos pero esperando a alguien
   de fuera. Son las dos preguntas que faltaban en la portada. */
$extra['resueltos'] = $cuenta('AND COALESCE(e.es_final,0) = 1
    AND LOWER(e.nombre_estado) NOT LIKE :canc AND LOWER(e.nombre_estado) NOT LIKE :elim',
    ['canc' => '%cancel%', 'elim' => '%elimin%']);

$extra['en_espera'] = $cuenta("AND $abiertos AND (
        LOWER(e.nombre_estado) LIKE :p1 OR LOWER(e.nombre_estado) LIKE :p2
        OR LOWER(e.nombre_estado) LIKE :p3)",
    ['p1' => 'pte%', 'p2' => '%programad%', 'p3' => '%espera%']);

if ($perfil === 'limpieza' || $perfil === 'reporta') {
    // Lo que ha abierto esta persona y sigue vivo: es lo que quiere
    // saber quien avisa, no el estado global del hotel.
    $extra['mios_abiertos'] = $cuenta("AND $abiertos AND t.id_realiza_accion = :mio", ['mio' => $yo]);
    $extra['mios_cerrados_semana'] = $cuenta(
        "AND t.id_realiza_accion = :mio2 AND t.fecha_cierre >= :sem",
        ['mio2' => $yo, 'sem' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
}

if ($perfil === 'limpieza') {
    /* Habitaciones con algo pendiente.
       Es la consulta que housekeeping necesita antes de entregar: si
       queda una avería viva en la habitación, no se entrega. */
    $extra['lugares_pendientes'] = Db::all(
        "SELECT z3.id_zona_3, z3.nombre_zona_3 AS lugar,
                MIN(r.id_rel) AS id_rel,
                COUNT(t.id_ticket) AS n,
                MAX(CASE WHEN t.id_prioridad >= 4 THEN 1 ELSE 0 END) AS urgente
           FROM ticket_incidencias t
           LEFT JOIN estados e ON e.id_estado = t.id_estado
           JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
           JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
          WHERE t.borrado = 0 AND $abiertos $visWhere
          GROUP BY z3.id_zona_3, z3.nombre_zona_3
          ORDER BY urgente DESC, n DESC
          LIMIT 12",
        $pVis
    );
    foreach ($extra['lugares_pendientes'] as &$l) {
        $l['id_rel']  = (int)$l['id_rel'];
        $l['n']       = (int)$l['n'];
        $l['urgente'] = (int)$l['urgente'];
    }
    unset($l);
}

if ($perfil === 'tecnico' || $perfil === 'limpieza') {
    // La cola del grupo: lo que está sin dueño y le toca a los suyos.
    if ($dep !== null) {
        $extra['cola_grupo'] = $cuenta(
            "AND $abiertos AND t.id_persona_asignada IS NULL AND t.id_grupo_asignado = :dep",
            ['dep' => $dep]);
    }
}

Http::ok([
    /* Lo que viene programado.
     *
     * Un técnico necesita saber qué le cae esta semana para organizarse,
     * y los que están a punto de llegar conviene tenerlos a la vista: si
     * mañana toca la 801, mejor saberlo hoy. */
    'programados' => (function () use ($u) {
        if (!in_array('visible_desde', Db::columnas('ticket_incidencias'), true)) {
            return null;
        }
        [$visibles, $args] = Permisos::filtroVisibilidad($u, 't');
        $limite = gmdate('Y-m-d H:i:s', time() + 3 * 86400);

        $filas = Db::all(
            'SELECT t.id_ticket, t.titulo_ticket, t.visible_desde, r.ruta
               FROM ticket_incidencias t
               LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
              WHERE ' . $visibles . ' AND t.borrado = 0
                AND t.visible_desde IS NOT NULL AND t.visible_desde > :ahora
              ORDER BY t.visible_desde
              LIMIT 50',
            $args + ['ahora' => Db::now()]);

        $cercanos = array_values(array_filter($filas,
            static fn($f) => (string)$f['visible_desde'] <= $limite));

        return [
            'total'    => count($filas),
            'cercanos' => count($cercanos),
            'dias'     => 3,
            'partes'   => array_map(static fn($f) => [
                'id_ticket' => (int)$f['id_ticket'],
                'codigo'    => Repo::codigo((int)$f['id_ticket']),
                'titulo'    => $f['titulo_ticket'],
                'cuando'    => $f['visible_desde'],
                'ruta'      => $f['ruta'],
            ], array_slice($cercanos, 0, 5)),
        ];
    })(),

    /* Lo que esta persona tiene sin leer. Va en el inicio porque quien
       abre un parte no vuelve a mirarlo: si alguien le contesta y no se
       entera, el cliente se queja otra vez. */
    'chat' => class_exists('Chat') ? Chat::pendientes($u, 5) : null,

    'perfil'       => $perfil,
    // Se fuerza a objeto: un array vacío en PHP sale como [] en JSON y
    // el navegador esperaría propiedades.
    'extra'        => (object)$extra,
    'resumen'      => $resumen,
    'por_estado'   => $porEstado,
    'por_elemento' => $porElemento,
    'serie'        => $perfil === 'responsable' ? $serie : [],
    'carga'        => $perfil === 'responsable' ? $carga : [],
    'resolucion'   => $perfil === 'responsable' ? [
        'muestras'    => count($horas),
        'media_horas' => $horas ? round(array_sum($horas) / count($horas), 1) : null,
    ] : ['muestras' => 0, 'media_horas' => null],
    'no_leidas'    => (int)Db::value('SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0', [$yo]),
    'generado'     => $ahora,
]);
