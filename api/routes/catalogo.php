<?php
/**
 * GET /api/catalogo
 * Todos los datos de referencia en una sola llamada. La app los guarda en
 * IndexedDB para poder crear partes sin cobertura.
 */
declare(strict_types=1);

if ($metodo !== 'GET') {
    throw new ApiError('Método no permitido.', 405);
}

$u = Auth::exigir();

$tieneClases = Db::tablaExiste('estados');
$colClases = '';
try {
    Db::value('SELECT clases FROM estados LIMIT 1');
    $colClases = ', clases, rapido';
} catch (Throwable) { /* instalación sin la ampliación 2.4 */ }

$estados = Db::all("SELECT id_estado, nombre_estado, color, class, orden, es_inicial, es_final$colClases
                      FROM estados WHERE COALESCE(activo,1) = 1 ORDER BY orden, id_estado");
foreach ($estados as &$e) {
    $e['id_estado'] = (int)$e['id_estado'];
    $e['orden']     = (int)$e['orden'];
    $e['es_inicial']= (int)$e['es_inicial'];
    $e['es_final']  = (int)$e['es_final'];
    // Qué clases de parte pueden usar este estado. Vacío = todas.
    $e['clases']    = isset($e['clases']) && $e['clases']
                      ? array_map('trim', explode(',', (string)$e['clases'])) : null;
    $e['rapido']    = (int)($e['rapido'] ?? 0);
}
unset($e);

$prioridades = Db::all('SELECT id_prioridad, nombre, color, sla_horas, orden FROM prioridades ORDER BY orden');
foreach ($prioridades as &$p) {
    $p['id_prioridad'] = (int)$p['id_prioridad'];
    $p['orden']        = (int)$p['orden'];
    $p['sla_horas']    = $p['sla_horas'] === null ? null : (int)$p['sla_horas'];
}
unset($p);

// Son más de 300 en la instalación real, así que se envían ordenados por
// uso: el buscador del formulario los ofrece por ese orden.
$elementos = Db::all(
    'SELECT id_elemento, nombre_elemento, abreviatura_elemento, icono,
            id_departamento, id_familia, usos, usa_serie,
            CASE WHEN dep_propio = 1 THEN 1 ELSE 0 END AS dep_propio
       FROM elemento
      WHERE COALESCE(baja, 0) = 0
      ORDER BY usos DESC, nombre_elemento'
);
/* Los más usados por esta persona y por su departamento, para el acceso
   rápido del alta. Se miran los últimos seis meses: lo de hace dos años
   ya no dice nada de lo que se rompe ahora. */
$desdeUsos = gmdate('Y-m-d H:i:s', time() - 180 * 86400);
$rapidosDe = static function (string $donde, array $par, string $union = '') use ($desdeUsos): array {
    $filas = Db::all(
        'SELECT t.id_elemento, COUNT(*) AS n FROM ticket_incidencias t ' . $union . '
          WHERE t.id_elemento IS NOT NULL AND COALESCE(t.borrado, 0) = 0
            AND t.fecha_creado > ? AND ' . $donde . '
          GROUP BY t.id_elemento ORDER BY n DESC LIMIT 12',
        array_merge([$desdeUsos], $par));
    return array_map(static fn($f) => (int)$f['id_elemento'], $filas);
};
/* Quién dio el parte no está en el parte: está en su historial, en el
   apunte de alta. */
$rapidosUsuario = Db::tablaExiste('ticket_incidencias_historial')
    ? $rapidosDe('h.id_realiza_accion = ?', [(int)$u['id_login']],
        "JOIN ticket_incidencias_historial h ON h.id_ticket = t.id_ticket AND h.tipo = 'alta'")
    : [];
$rapidosGrupo   = ($u['id_departamento'] ?? null) !== null
    ? $rapidosDe('t.id_grupo_asignado = ?', [(int)$u['id_departamento']])
    : [];

foreach ($elementos as &$el) {
    $el['id_elemento']     = (int)$el['id_elemento'];
    $el['nombre']          = $el['nombre_elemento'];          // alias cómodo
    $el['abreviatura']     = $el['abreviatura_elemento'];
    $el['usos']            = (int)$el['usos'];
    $el['id_departamento'] = $el['id_departamento'] === null ? null : (int)$el['id_departamento'];
    // Marcado como excepción: manda sobre el departamento de su familia.
    $el['dep_propio']      = (int)($el['dep_propio'] ?? 0) === 1;
    $el['id_familia']      = $el['id_familia'] === null ? null : (int)$el['id_familia'];
    $el['usa_serie']       = (int)($el['usa_serie'] ?? 0);
}
unset($el);

/* Familias: agrupan los 313 tipos en oficios, y su clase distingue una
   avería de una petición de cliente o de una tarea de limpieza. */
$familias = [];
if (Permisos::activo('familias') && Db::tablaExiste('familias')) {
    foreach (Db::all('SELECT * FROM familias WHERE activo = 1 ORDER BY orden, nombre') as $f) {
        $familias[] = [
            'id_familia'      => (int)$f['id_familia'],
            'nombre'          => $f['nombre'],
            'clase'           => $f['clase'],
            'icono'           => $f['icono'],
            'color'           => $f['color'],
            'descripcion'     => $f['descripcion'],
            'id_departamento' => $f['id_departamento'] === null ? null : (int)$f['id_departamento'],
            'id_prioridad'    => $f['id_prioridad'] === null ? null : (int)$f['id_prioridad'],
            'n_elementos'     => (int)Db::value(
                'SELECT COUNT(*) FROM elemento WHERE id_familia = ? AND COALESCE(baja,0) = 0',
                [(int)$f['id_familia']]),
        ];
    }
}

/* Los departamentos que usa ESTE hotel, con el nombre que le da.
 *
 * El catálogo es común a la cadena, pero cada hotel elige cuáles ofrece:
 * uno de treinta habitaciones no tiene jardinería, y le sobra verla cada
 * vez que reparte un parte. Sin este filtro la elección por hotel era
 * cosmética —se guardaba y no cambiaba nada. */
$hotelCat = Db::tablaExiste('hoteles') ? Hoteles::actual() : null;

if ($hotelCat !== null && Db::tablaExiste('hotel_departamento')) {
    $departamentos = Db::all(
        'SELECT d.id_departamento, d.color,
                COALESCE(NULLIF(hd.alias, \'\'), d.nombre) AS nombre,
                d.nombre AS nombre_cadena
           FROM departamentos d
           JOIN hotel_departamento hd ON hd.id_departamento = d.id_departamento
          WHERE hd.id_hotel = ? AND COALESCE(d.activo, 1) = 1
          ORDER BY hd.orden, nombre', [$hotelCat]);

    /* Si el hotel no tiene ninguno asignado se le dan todos.
       Pasa con instalaciones anteriores a esta versión: quedarse sin
       departamentos significa no poder dar un parte, y eso es peor que
       ver alguno de más. */
    if (!$departamentos) {
        $departamentos = Db::all(
            'SELECT id_departamento, nombre, color, nombre AS nombre_cadena
               FROM departamentos WHERE COALESCE(activo,1) = 1 ORDER BY nombre');
    }
} else {
    $departamentos = Db::all(
        'SELECT id_departamento, nombre, color, nombre AS nombre_cadena
           FROM departamentos WHERE COALESCE(activo,1) = 1 ORDER BY nombre');
}
foreach ($departamentos as &$d) { $d['id_departamento'] = (int)$d['id_departamento']; }
unset($d);
unset($d);

/* La gente del hotel, para poder asignar partes.
 *
 * El correo y el teléfono sólo van para quien reparte. Este catálogo se
 * descarga entero a cada móvil y se queda guardado ahí: mandarle el
 * listín del hotel a un aparato que se pierde en un pasillo no compensa
 * para lo poco que aporta. Quien necesite llamar a alguien lo tiene en
 * Equipo, que sí exige ser responsable. */
$nombre = Db::nombreCompleto('l');
$verFicha = Auth::esResponsable($u);

$usuarios = Db::all(
    "SELECT l.id_login, $nombre AS nombre_completo,
            " . ($verFicha ? 'l.correo, l.telefono' : "NULL AS correo, NULL AS telefono") . ",
            l.rol, l.id_departamento
       FROM login l
       WHERE l.bloqueo = 0
         AND (l.id_hotel IS NULL OR l.id_hotel = " . (int)(Hoteles::actual() ?? 0) . ")
       ORDER BY $nombre"
);
foreach ($usuarios as &$us) {
    $us['id_login'] = (int)$us['id_login'];
    $us['id_departamento'] = $us['id_departamento'] === null ? null : (int)$us['id_departamento'];
    $us['iniciales'] = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $us['nombre_completo']), 0, 1)
        . (str_contains($us['nombre_completo'], ' ')
            ? mb_substr(explode(' ', $us['nombre_completo'])[1] ?? '', 0, 1) : ''));
}
unset($us);

/* Configuración: la app la necesita para saber qué esconder. */
Permisos::asegurarAjustes();
$ajustes = Permisos::ajustes();
$modulos = [];
foreach ($ajustes as $k => $v) {
    if (str_starts_with($k, 'mod_')) { $modulos[substr($k, 4)] = (bool)$v; }
}

/* Campos propios por tipo de avería */
$campos = [];
if (Permisos::activo('campos') && Db::tablaExiste('campos_elemento')) {
    foreach (Db::all('SELECT * FROM campos_elemento WHERE activo = 1 ORDER BY orden, id_campo') as $c) {
        $campos[] = [
            'id_campo'    => (int)$c['id_campo'],
            'id_elemento' => $c['id_elemento'] === null ? null : (int)$c['id_elemento'],
            'clave'       => $c['clave'],
            'etiqueta'    => $c['etiqueta'],
            'tipo'        => $c['tipo'],
            'opciones'    => $c['opciones'] ? json_decode((string)$c['opciones'], true) : null,
            'unidad'      => $c['unidad'],
            'obligatorio' => (int)$c['obligatorio'],
            'ayuda'       => $c['ayuda'],
        ];
    }
}

/* Instrucciones: van enteras al móvil para poder leerlas sin cobertura */
$instrucciones = [];
if (Permisos::activo('instrucciones') && Db::tablaExiste('instrucciones')) {
    foreach (Db::all(
        'SELECT i.id_instruccion, i.titulo, i.resumen, i.contenido, i.aviso,
                i.id_familia, i.id_elemento, i.id_zona_1, i.etiquetas, i.vistas,
                -- A quién se le enseña: una nota técnica no le sirve a quien
                -- sólo da el aviso.
                i.visible_para,
                f.nombre AS familia, f.color AS familia_color, f.icono AS familia_icono,
                el.nombre_elemento AS elemento, z1.nombre_zona_1 AS planta
           FROM instrucciones i
           LEFT JOIN familias f  ON f.id_familia = i.id_familia
           LEFT JOIN elemento el ON el.id_elemento = i.id_elemento
           LEFT JOIN zona_1 z1   ON z1.id_zona_1 = i.id_zona_1
          WHERE i.activo = 1 AND (i.id_hotel IS NULL OR i.id_hotel = ' . (int)(Hoteles::actual() ?? 0) . ')
          ORDER BY i.orden, i.titulo'
    ) as $i) {
        $instrucciones[] = [
            'id_instruccion' => (int)$i['id_instruccion'],
            'titulo'      => $i['titulo'],
            'visible_para'=> $i['visible_para'] ?? 'departamento',
            'resumen'     => $i['resumen'],
            'contenido'   => $i['contenido'],
            'aviso'       => $i['aviso'],
            'id_familia'  => $i['id_familia'] === null ? null : (int)$i['id_familia'],
            'id_elemento' => $i['id_elemento'] === null ? null : (int)$i['id_elemento'],
            'id_zona_1'   => $i['id_zona_1'] === null ? null : (int)$i['id_zona_1'],
            'familia'     => $i['familia'],
            'familia_color' => $i['familia_color'],
            'familia_icono' => $i['familia_icono'],
            'elemento'    => $i['elemento'],
            'planta'      => $i['planta'],
            'etiquetas'   => $i['etiquetas']
                ? array_values(array_filter(array_map('trim', explode(',', (string)$i['etiquetas']))))
                : [],
            'vistas'      => (int)$i['vistas'],
        ];
    }
}

/* Atajos visibles para quien pregunta */
$atajos = [];
if (Permisos::activo('atajos') && Db::tablaExiste('atajos')) {
    $filas = Db::all(
        'SELECT * FROM atajos
          WHERE activo = 1
            AND (id_login IS NULL OR id_login = :yo)
            AND (id_departamento IS NULL OR id_departamento = :dep)
            AND (solo_rol IS NULL OR solo_rol = :rol)
          ORDER BY orden, id_atajo',
        [
            'yo'  => (int)$u['id_login'],
            'dep' => $u['id_departamento'] === null ? -1 : (int)$u['id_departamento'],
            'rol' => (string)($u['rol'] ?? 'tecnico'),
        ]
    );
    foreach ($filas as $a) {
        $atajos[] = [
            'id_atajo' => (int)$a['id_atajo'],
            'nombre'   => $a['nombre'],
            'icono'    => $a['icono'],
            'color'    => $a['color'],
            'filtro'   => json_decode((string)$a['filtro'], true),
            'mio'      => $a['id_login'] !== null && (int)$a['id_login'] === (int)$u['id_login'],
        ];
    }
}

Http::ok([
    'modulos'       => $modulos,
    'orden_zona'    => $config['app']['orden_zona'] ?? [2, 1, 3, 4],
    // El hotel sobre el que se está trabajando, para poder enseñarlo
    'hotel'         => Db::tablaExiste('hoteles') && Hoteles::actual($u)
                       ? Hoteles::uno((int)Hoteles::actual($u)) : null,

    /* Lo que los módulos opcionales quieren enseñar en Ajustes. Vacío
       si no hay ninguno encendido, que es lo normal. */

    /* Las empresas de las que ya se ha estado pendiente alguna vez, para
       ofrecerlas al volver a esperar a un tercero. Se sacan de las notas
       del historial: no hace falta una tabla para esto. */
    'empresas' => array_values(array_filter(array_unique(array_map(
        static function (array $f): string {
            if (preg_match('/\\(([^)]{2,60})\\)/u', (string)$f['detalle_ticket'], $m2)) {
                return trim($m2[1]);
            }
            return '';
        },
        Db::all("SELECT detalle_ticket FROM ticket_incidencias_historial
                  WHERE detalle_ticket LIKE 'Se espera a %'
                  ORDER BY id_historial DESC LIMIT 200"))))),

    /* Cuántos fallos hay sin revisar: sale en Administración para que no
       haya que acordarse de ir a mirarlo. */
    'errores_sin_ver' => (class_exists('Errores') && Auth::esAdmin($u))
        ? Errores::sinVer()['grupos'] : 0,

    /* Lo que esta persona tiene por leer: mensajes de chat más avisos.
     *
     * Va en el catálogo porque lo pinta la barra de abajo, que está en
     * todas las pantallas. Un mensaje urgente que sólo se ve entrando a
     * la pantalla de mensajes no sirve de nada: hay que verlo desde
     * donde se esté. */
    /* Cuánto se puede subir DE VERDAD, para que el editor lo diga antes
       de que alguien grabe un vídeo de 30 MB y lo pierda. No es lo que
       pide la configuración: es el mínimo con lo que admita PHP. */
    'subidas' => (static function () use ($config): array {
        try {
            Uploads::init($config['uploads']);
            $foto = Uploads::topeReal();
            $mb = (int)Permisos::ajuste('instrucciones_video_mb', 0);
            $video = Uploads::topeReal($mb > 0 ? $mb * 1048576
                : (int)($config['uploads']['video_max_bytes'] ?? 0));
            return [
                'foto'  => ['bytes' => $foto['bytes'], 'mb' => $foto['mb']],
                'video' => ['bytes' => $video['bytes'], 'mb' => $video['mb']],
            ];
        } catch (Throwable $e) {
            // Sin esto el editor usa un tope prudente y sigue.
            return [];
        }
    })(),

    'sin_leer' => (static function () use ($u): array {
        $chats = 0;
        $avisos = 0;
        try {
            if (class_exists('Chat')) {
                $chats = Chat::sinLeerDe($u);
            }
        } catch (Throwable $e) { /* la barra se pinta sin número */ }
        try {
            if (Db::tablaExiste('notificaciones')) {
                /* Sin los de chat: ésos ya cuentan como conversación.
                   Cada mensaje deja también un aviso «Mensaje · T-…», y
                   esto los sumaba otra vez: seis mensajes de Fany eran
                   1 conversación + 6 avisos = 7 en la chapa, cuando la
                   guía (y el comentario de mensajes.js) prometen que una
                   conversación cuenta una. */
                $avisos = (int)Db::value(
                    "SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0
                        AND (evento IS NULL OR evento <> 'chat')",
                    [(int)$u['id_login']]);
            }
        } catch (Throwable $e) { /* idem */ }
        return ['chats' => $chats, 'avisos' => $avisos, 'total' => $chats + $avisos];
    })(),
    'modulos_menu' => class_exists('Plugins') ? Plugins::menu($u) : [],
    'ajustes'       => [
        'visibilidad'         => $ajustes['visibilidad_tickets'] ?? 'grupo',
        /* Si la copia a Teams está encendida. La ficha de una persona
           sólo enseña su interruptor de Teams cuando lo está: ofrecer un
           ajuste de algo apagado no sirve de nada. */
        'teams'               => (bool)($ajustes['teams_activo'] ?? false),
        'cerrar_pide_nota'    => (bool)($ajustes['cerrar_pide_nota'] ?? true),
        'autoasignar_al_coger'=> (bool)($ajustes['autoasignar_al_coger'] ?? true),
        'permitir_zona_nueva' => (bool)($ajustes['permitir_zona_nueva'] ?? true),
        'quien_crea_zona'     => $ajustes['quien_crea_zona'] ?? 'responsable',
        'alta_obligatorios'   => is_array($ajustes['alta_obligatorios'] ?? null)
                                 ? $ajustes['alta_obligatorios'] : [],
        'alta_departamento'   => (int)($ajustes['alta_departamento_defecto'] ?? 0) ?: null,
        // Si está puesto, la pantalla del alta pone el departamento que
        // toca según lo que falla, igual que hace el servidor al guardar.
        'rutar_por_familia'   => (bool)($ajustes['rutar_por_familia'] ?? true),
        'forzar_https'        => (bool)($ajustes['forzar_https'] ?? false),
        'historial_elemento'  => (bool)($ajustes['ticket_historial_elemento'] ?? true),
        'guia_api_todos'      => (bool)($ajustes['guia_api_para_todos'] ?? false),
        // Si está apagado, la aplicación no guarda nada a la espera de señal.
        'offline'             => (bool)($ajustes['offline_activo'] ?? true),
        'refresco_auto'       => (bool)($ajustes['refresco_auto'] ?? true),
        'foco_buscador'       => (bool)($ajustes['foco_buscador'] ?? true),
        'puede_apagar_offline'=> Permisos::tienePermiso($u, 'offline_apagar'),
        'alta_prioridad'      => (int)($ajustes['alta_prioridad_defecto'] ?? 2) ?: 2,
        'paneles_area'        => is_array($ajustes['paneles_area'] ?? null) ? $ajustes['paneles_area'] : [],
        'org_nombre'          => $ajustes['org_nombre'] ?? $config['app']['organizacion'],
        'org_color'           => $ajustes['org_color'] ?? '#0B5563',
        // Acceso rápido del alta: cuántos, de dónde y, si es a mano, cuáles.
        'rapidos_cantidad'    => max(0, min(6, (int)($ajustes['alta_rapidos_cantidad'] ?? 6))),
        'rapidos_origen'      => in_array($ajustes['alta_rapidos_origen'] ?? 'hotel',
                                          ['hotel', 'departamento', 'usuario', 'manual'], true)
                                 ? ($ajustes['alta_rapidos_origen'] ?? 'hotel') : 'hotel',
        'rapidos_manual'      => array_values(array_filter(array_map('intval',
                                    is_array($ajustes['alta_rapidos_manual'] ?? null)
                                        ? $ajustes['alta_rapidos_manual'] : []))),
        'rapidos_usuario'     => $rapidosUsuario,
        'rapidos_grupo'       => $rapidosGrupo,
        // Los iconos que se hayan cambiado desde Iconografía.
        'iconos'              => (function ($v) {
            $m = is_array($v) ? $v : json_decode((string)$v, true);
            // Una versión anterior lo guardaba codificado dos veces.
            if (is_string($m)) { $m = json_decode($m, true); }
            if (!is_array($m)) { return (object)[]; }
            // Sólo códigos hexadecimales: lo que llega a un innerHTML se mira.
            $limpio = [];
            foreach ($m as $k => $c) {
                if (is_string($k) && preg_match('/^[a-z0-9_-]{1,40}$/i', $k)
                    && is_string($c) && preg_match('/^[0-9a-f]{1,5}$/i', $c)) {
                    $limpio[$k] = strtolower($c);
                }
            }
            return $limpio ?: (object)[];
        })($ajustes['iconos_personalizados'] ?? '{}'),
        // Vista smartphone: el modo lo decide el superadministrador.
        'vista_movil_modo'    => in_array($ajustes['vista_movil_modo'] ?? 'no',
                                          ['no', 'todos', 'activa', 'inactiva'], true)
                                 ? ($ajustes['vista_movil_modo'] ?? 'no') : 'no',
        'vista_movil_ratio'   => in_array($ajustes['vista_movil_ratio'] ?? '9:19.5',
                                          ['9:16', '9:19.5', '9:20'], true)
                                 ? ($ajustes['vista_movil_ratio'] ?? '9:19.5') : '9:19.5',
    ],
    'familias'      => $familias,
    /* Las instrucciones van con el catálogo a propósito: quien las
       necesita está en un sótano sin cobertura, que es justo donde una
       base de conocimiento que exige conexión no sirve de nada. */
    'instrucciones' => $instrucciones,
    'clases'        => [
        ['clave' => 'averia',   'nombre' => 'Avería',    'icono' => 'llaveinglesa', 'color' => '#C7362B'],
        ['clave' => 'peticion', 'nombre' => 'Petición',  'icono' => 'persona',      'color' => '#0B5563'],
        ['clave' => 'tarea',    'nombre' => 'Tarea',     'icono' => 'ok',           'color' => '#0D7C57'],
    ],
    'campos'        => $campos,
    'atajos'        => $atajos,
    'avisos'        => Permisos::avisosDe((int)$u['id_login']),
    'version'       => (int)(Db::value('SELECT COUNT(*) FROM estados') ?? 0)
                     + (int)(Db::value('SELECT COUNT(*) FROM elemento') ?? 0)
                     + (int)(Db::value('SELECT COUNT(*) FROM login') ?? 0),
    'generado'      => Db::now(),
    'estados'       => $estados,
    'prioridades'   => $prioridades,
    'elementos'     => $elementos,
    'departamentos' => $departamentos,
    'usuarios'      => $usuarios,
    'zonas'         => Repo::arbolZonas(),
    'niveles_zona'  => $config['app']['niveles_zona'],
    'organizacion'  => $config['app']['organizacion'],
]);
