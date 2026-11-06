<?php
/** Rutas /api/ajustes, /api/campos, /api/atajos y /api/copias */
declare(strict_types=1);

$seccion = $partes[0];

/* La descarga con vale va ANTES de exigir sesión, y tiene que ir aquí.
 *
 * Es el único sitio de toda la API al que se llega sin la cabecera
 * «Authorization», porque una descarga es una navegación del navegador
 * y una navegación no lleva cabeceras. Antes esto se resolvía metiendo
 * el token de sesión en la dirección, y ahí se quedaba escrito: en el
 * registro de Apache aparecieron 134 líneas con tokens de
 * administrador completos, que valen 30 días.
 *
 * El vale dura 60 segundos, va atado a esta copia y a quien lo pidió, y
 * va firmado con la clave de la casa. En el registro queda igual, pero
 * al minuto siguiente no sirve para nada. */
if ($seccion === 'copias' && $metodo === 'GET' && isset($_GET['vale'])) {
    require_once __DIR__ . '/../lib/Copias.php';
    $idVale = Http::id($partes[1] ?? null);
    $quien  = $idVale === null ? null
            : Descargas::dueno((string)$_GET['vale'], 'copias/' . $idVale);
    if ($quien === null) {
        throw new ApiError('El enlace de descarga ha caducado. '
                         . 'Vuelve a pulsar sobre la copia.', 403);
    }
    $persona = Db::one('SELECT * FROM login WHERE id_login = ? AND bloqueo = 0',
                       [$quien]);
    if ($persona === null || !(int)($persona['admin'] ?? 0)) {
        throw new ApiError('Sin permiso.', 403);
    }
    Copias::descargar($idVale);
}

$u       = Auth::exigir();

/* ================================================================= */
/* AJUSTES DEL SISTEMA                                               */
/* ================================================================= */
/* ¿Puede esta persona cambiar este ajuste?
 *
 * Administrador y superadministrador son lo mismo: quien administra lo
 * cambia todo, incluida la vista smartphone y los enlaces de
 * auto-entrada. El resto lo ve, sin poder tocarlo.
 *
 * La regla vive aquí una sola vez y la usan las dos puertas: la lectura
 * —para pintar el candado— y el guardado —que es quien lo impide—. */
$porQueNo = function (string $clave, string $cat) use ($u): ?string {
    return Auth::esAdmin($u) ? null : 'Sólo un administrador cambia la configuración.';
};

/* Lo que no puede salir de aquí con su valor.
 *
 * Esto era una lista escrita a mano con tres claves, y el fallo estaba en
 * el planteamiento: lo que no estuviera en la lista salía con su valor.
 * Se añadió después la conexión con recepción, nadie se acordó de la
 * lista, y «sap_clave» —la credencial del sistema de recepción del
 * hotel— se la llevaba entera cualquiera: un técnico, o quien sólo da
 * avisos. Con cincuenta personas dentro, eso es la credencial en
 * cincuenta móviles.
 *
 * Ahora la regla está al revés: se tapa todo lo que PAREZCA una
 * credencial, y lo que no lo parezca se enseña. Así un ajuste nuevo nace
 * tapado y hay que decidir enseñarlo, en vez de nacer destapado y tener
 * que acordarse de taparlo.
 *
 * Tres caminos, a propósito:
 *   · el tipo «clave», que es como el esquema marca una contraseña;
 *   · el nombre, para las que se guardaron como texto;
 *   · y la lista de siempre, por si algo se escapa a las dos anteriores.
 *
 * Lo que NO se tapa, porque la aplicación lo necesita o no es secreto:
 * «push_vapid_publica» —el navegador la necesita para suscribirse—, las
 * direcciones y los identificadores de aplicación. */
$SECRETOS = ['push_vapid_privada', 'push_fcm_cuenta', 'teams_app_secreto',
             'sap_clave', 'sap_usuario'];

$esSecreto = static function (array $f) use ($SECRETOS): bool {
    if (in_array($f['clave'], $SECRETOS, true)) { return true; }
    if (($f['tipo'] ?? '') === 'clave') { return true; }
    return (bool)preg_match('/secreto|privad|password|_clave$|^clave_|token|fcm_cuenta/i',
                            (string)$f['clave']);
};

if ($seccion === 'ajustes') {

    // Cualquiera lee la configuración: la aplicación la necesita para
    // saber qué esconder. Sólo el administrador la cambia.
    if ($metodo === 'GET') {
        Permisos::asegurarAjustes();
        $filas = Db::all('SELECT * FROM ajustes_sistema ORDER BY categoria, orden, clave');
        $porCategoria = [];
        foreach ($filas as $f) {
            $valor = match ($f['tipo']) {
                'interruptor' => (int)$f['valor'] === 1 || $f['valor'] === '1',
                'numero'      => (float)$f['valor'],
                'json'        => json_decode((string)$f['valor'], true),
                default       => $f['valor'],
            };
            /* Los ajustes «interno» son cocina de la aplicación —marcas,
               identificadores, el mapa de iconos— y no se tocan a mano:
               fuera de la pantalla y fuera del buscador. */
            if ($f['categoria'] === 'interno') { continue; }

            /* Los secretos no viajan a quien no es administrador.
             *
             * Esta respuesta la lee cualquiera —la aplicación necesita
             * saber qué esconder—, y la clave privada de los avisos y la
             * cuenta de servicio iban dentro con su valor. Hoy están
             * vacías porque nadie ha configurado los avisos, así que no
             * se notaba; el día que se configuren, cualquier técnico se
             * las descarga con abrir los ajustes. Se mandan vacías y con
             * aviso de que hay algo puesto. */
            if ($esSecreto($f) && !Auth::esAdmin($u)) {
                $porCategoria[$f['categoria']][] = [
                    'clave'       => $f['clave'],
                    'valor'       => '',
                    'tipo'        => $f['tipo'],
                    'titulo'      => $f['titulo'],
                    'descripcion' => $f['descripcion'],
                    'opciones'    => null,
                    'puesto'      => ((string)$f['valor'] !== ''),
                    'bloqueo'     => 'Sólo un administrador ve y cambia esto.',
                ];
                continue;
            }

            $porCategoria[$f['categoria']][] = [
                'clave'       => $f['clave'],
                'valor'       => $valor,
                'tipo'        => $f['tipo'],
                'titulo'      => $f['titulo'],
                'descripcion' => $f['descripcion'],
                'opciones'    => $f['opciones'] ? json_decode((string)$f['opciones'], true) : null,
                'bloqueo'     => $porQueNo((string)$f['clave'], (string)$f['categoria']),
            ];
        }
    
    /* Dos ajustes guardan el número de un departamento. Enseñado tal cual,
       «A qué grupo van los partes por defecto: 1» no le dice nada a nadie:
       se convierten en lista con los nombres. */
    if (Db::tablaExiste('departamentos')) {
        $opcionesDep = array_map(
            static fn($d) => ['v' => (string)$d['id_departamento'], 't' => $d['nombre']],
            Db::all('SELECT id_departamento, nombre FROM departamentos WHERE COALESCE(activo,1) = 1 ORDER BY orden, nombre'));
        foreach ($porCategoria as &$grupo) {
            foreach ($grupo as &$aj) {
                if (($aj['clave'] ?? '') === 'alta_departamento_defecto') {
                    $aj['tipo'] = 'lista';
                    $aj['opciones'] = $opcionesDep;
                    $aj['valor'] = (string)$aj['valor'];
                }
            }
            unset($aj);
        }
        unset($grupo);
    }
    Http::ok([
            'ajustes'    => $porCategoria,
            'categorias' => [
                'general'       => 'Identidad',
                'modulos'       => 'Funciones activas',
                'visibilidad'   => 'Quién ve qué',
                'avisos'        => 'Avisos por defecto',
                'alta'          => 'Al dar un parte',
                'comportamiento'=> 'Comportamiento',
                /* Seguridad no salía en ninguna pantalla: los intentos
                   antes de bloquear, los días de sesión y el interruptor
                   de los enlaces sólo se podían cambiar por la API. */
                'seguridad'     => 'Seguridad',
                'vista'         => 'Vista smartphone',
                'sap'           => 'Conector con SAP',
                /* Estas dos existían en la base y no estaban aquí, así que
                   sus ajustes no se pintaban en ninguna pantalla: «cerrar
                   el acceso», el mensaje que se enseña al cerrarlo y los
                   días que se guarda lo borrado sólo se podían cambiar por
                   la API. El buscador sí los encontraba y al pulsar no
                   llevaba a ningún sitio. */
                'mantenimiento' => 'Cerrar el acceso y papelera',
                'push'          => 'Avisos al móvil',
                'teams'         => 'Copia a Microsoft Teams',
            ],
            'editable'   => Auth::esAdmin($u),
        ]);
    }

    if ($metodo === 'PATCH' || $metodo === 'POST') {
        Auth::exigirAdmin();
        $cambios = Http::campo('cambios');
        if (!is_array($cambios) || !$cambios) {
            throw new ApiError('No hay cambios que guardar.', 422);
        }

        // La misma regla que pinta los candados en la pantalla.
        foreach (array_keys($cambios) as $clave) {
            $cat = (string)Db::value(
                'SELECT categoria FROM ajustes_sistema WHERE clave = ?', [(string)$clave]);
            $motivo = $porQueNo((string)$clave, $cat);
            if ($motivo !== null) {
                throw new ApiError($motivo, 403, ['campo' => (string)$clave, 'categoria' => $cat]);
            }
        }

        // Por si alguien subió los ficheros sin pasar el actualizador: los
        // ajustes nuevos se crean también aquí, no sólo al leerlos.
        Permisos::asegurarAjustes();

        /* Los iconos se limpian ANTES de guardarlos: ese valor acaba
           dibujándose en la pantalla de todo el hotel, así que sólo se
           acepta «nombre interno → código hexadecimal». La salida también
           lo comprueba, pero no se guarda basura en la base. */
        if (isset($cambios['iconos_personalizados'])) {
            $v = $cambios['iconos_personalizados'];
            $m = is_array($v) ? $v : json_decode((string)$v, true);
            if (is_string($m)) { $m = json_decode($m, true); }
            $limpio = [];
            foreach (is_array($m) ? $m : [] as $k => $c) {
                if (is_string($k) && preg_match('/^[a-z0-9_-]{1,40}$/i', $k)
                    && is_string($c) && preg_match('/^[0-9a-f]{1,5}$/i', $c)) {
                    $limpio[$k] = strtolower($c);
                }
            }
            // El propio guardado de ajustes ya lo codifica: si se codifica
            // aquí también, queda codificado dos veces y no lo reconoce nadie.
            $cambios['iconos_personalizados'] = $limpio;
        }

        /* Un ajuste de lista sólo admite lo que está en su lista.
         *
         * No lo comprobaba nadie: se podía guardar cualquier cosa en los
         * doce ajustes que son una elección —quién ve qué partes, quién
         * puede crear ubicaciones, qué datos de recepción se enseñan—. No
         * daba error, pero la aplicación no hacía lo que se le había
         * pedido: al leerlo, un valor que no reconoce cae en el camino por
         * defecto, que es el restrictivo. Es decir, el administrador
         * guardaba una cosa y pasaba otra, sin que nada lo dijera.
         *
         * Se comprueba contra las opciones que declara el propio ajuste,
         * así que no hay una segunda lista que mantener al día. */
        foreach ($cambios as $clave => $valor) {
            $fila = Db::one('SELECT tipo, opciones FROM ajustes_sistema WHERE clave = ?',
                            [(string)$clave]);
            if (($fila['tipo'] ?? '') !== 'lista' || empty($fila['opciones'])) { continue; }
            $ops = json_decode((string)$fila['opciones'], true);
            if (!is_array($ops) || !$ops) { continue; }
            $validos = array_map(
                static fn($o) => (string)(is_array($o) ? ($o['v'] ?? '') : $o), $ops);
            if (!in_array((string)$valor, $validos, true)) {
                throw new ApiError(
                    'Ese valor no es una de las opciones de «' . $clave . '».',
                    422, ['campo' => (string)$clave]);
            }
        }

        foreach ($cambios as $clave => $valor) {
            $antes = Permisos::ajuste((string)$clave);
            Permisos::fijar((string)$clave, $valor);
            $texto = (string)$clave . ': ' . var_export($antes, true) . ' → ' . var_export($valor, true);
            Registro::anotar(
                (string)$clave === 'modo_mantenimiento' ? 'mantenimiento' : 'ajuste',
                (int)$u['id_login'], $texto
            );
        }
        Http::ok(['mensaje' => 'Configuración guardada.', 'aplicados' => count($cambios)]);
    }
}

/* ================================================================= */
/* GET /api/actualizacion — ¿hay una versión nueva publicada?         */
/* ================================================================= */
/* Sólo quien administra: es quien puede hacer algo con la respuesta.
   Con ?forzar=1 vuelve a preguntar aunque se haya preguntado hace poco. */
if ($seccion === 'actualizacion' && $metodo === 'GET') {
    Auth::exigirAdmin();
    Http::ok(['actualizacion' => Actualizaciones::estado(
        $config, Http::bool(Http::query('forzar')))]);
}

/* ================================================================= */
/* GET /api/integridad — ¿está todo en su sitio?                      */
/* ================================================================= */
/* Sólo lee: ver lib/Integridad.php. La dirección de la aplicación se saca
   de la propia petición, para poder preguntarle al servidor web qué sirve
   (los candados). */
if ($seccion === 'integridad' && $metodo === 'GET') {
    Auth::exigirAdmin();
    require_once __DIR__ . '/../lib/Integridad.php';
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $raiz   = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
    /* Sólo un nombre de servidor con aspecto de nombre: la cabecera Host la
       manda el cliente, y no se le va a pedir a curl cualquier cosa. */
    $base = preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)
        ? ($https ? 'https://' : 'http://') . $host . $raiz . '/'
        : null;
    Http::ok(Integridad::comprobar($config, $base));
}

/* ================================================================= */
/* POST /api/sistema/renovar — que todos vuelvan a descargar          */
/* ================================================================= */
if ($seccion === 'renovar' && $metodo === 'POST') {
    Auth::exigirAdmin();
    Permisos::asegurarAjustes();

    /* Se marca el momento y cada aparato lo atiende la próxima vez que
       hable con el servidor: sube lo que tenga pendiente, vacía lo
       guardado, cierra la sesión y descarga todo de nuevo con la versión
       que haya ahora.

       NO se borran aquí las sesiones: si se cerraran de golpe, un móvil
       con partes sin enviar no podría subirlos y se perderían. La sesión
       la cierra el propio aparato cuando ya ha subido lo suyo. */
    $momento = Db::now();
    Permisos::fijar('purga_generacion', $momento);
    Registro::anotar('renovacion', (int)$u['id_login'],
        'Renovación forzada de todos los aparatos: vaciar y volver a descargar');

    Http::ok([
        'mensaje'    => 'Pedido. Cada aparato lo hará la próxima vez que tenga conexión.',
        'renovacion' => $momento,
    ]);
}

/* ================================================================= */
/* COMPROBAR LA COPIA A TEAMS                                        */
/* ================================================================= */
/* «No me llega nada de Teams» pueden ser cinco cosas, y cuatro las
   arregla el informático de la empresa, no HuraKan Tick. Esto va paso a paso y
   dice en cuál se rompe, para no andar adivinando. */
if ($seccion === 'teams' && $metodo === 'POST') {
    Auth::exigirAdmin();

    if (!class_exists('Teams')) {
        throw new ApiError('Esta instalación no tiene la copia a Teams.', 404);
    }

    // Si se dice a quién, se prueba el camino completo hasta su chat.
    $aQuien = Http::id(Http::campo('id_login'));
    $r = Teams::comprobar($aQuien);

    /* La bandeja también se cuenta: si hay cosas atascadas, es lo
       primero que hay que ver. */
    $cola = Db::tablaExiste('teams_salida')
        ? Db::one("SELECT
                     SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
                     SUM(CASE WHEN estado = 'fallido'   THEN 1 ELSE 0 END) AS fallidos,
                     SUM(CASE WHEN estado = 'enviado'   THEN 1 ELSE 0 END) AS enviados
                   FROM teams_salida")
        : null;

    Http::ok([
        'bien'  => $r['bien'],
        'pasos' => $r['pasos'],
        'cola'  => [
            'pendientes' => (int)($cola['pendientes'] ?? 0),
            'fallidos'   => (int)($cola['fallidos'] ?? 0),
            'enviados'   => (int)($cola['enviados'] ?? 0),
        ],
        'mensaje' => $r['bien']
            ? ($aQuien ? 'Funciona: mírale el Teams, le acaba de llegar un mensaje.'
                       : 'La conexión con Azure funciona. Prueba ahora con una persona.')
            : 'Se ha parado en uno de los pasos. Mira cuál y pásaselo a tu informático.',
    ]);
}

/* ================================================================= */
/* CAMPOS PROPIOS POR TIPO DE AVERÍA                                 */
/* ================================================================= */
if ($seccion === 'campos') {

    /* Leer los campos lo necesita cualquiera: sin ellos no se puede
       pintar el formulario del alta. Crearlos, cambiarlos o quitarlos es
       otra cosa: el catálogo de tipos es común a TODOS los hoteles de la
       cadena, así que un campo nuevo en «Aire acondicionado» aparece en
       el Benalma, en el Moxy y en el Marriott a la vez. Eso lo decide
       quien lleva la cadena, no el administrador de una casa. */
    if ($metodo !== 'GET' && (int)($u['superadmin'] ?? 0) !== 1) {
        throw new ApiError(
            'Los campos por tipo de avería los lleva el superadministrador: '
            . 'son comunes a todos los hoteles.', 403);
    }

    if ($metodo === 'GET') {
        $idElemento = Http::queryInt('elemento');
        $sql = 'SELECT c.*, e.nombre_elemento
                  FROM campos_elemento c
                  LEFT JOIN elemento e ON e.id_elemento = c.id_elemento';
        $params = [];
        if ($idElemento !== null) {
            // Los de este tipo, más los generales (id_elemento NULL)
            $sql .= ' WHERE (c.id_elemento = :el OR c.id_elemento IS NULL) AND c.activo = 1';
            $params['el'] = $idElemento;
        }
        $sql .= ' ORDER BY c.orden, c.id_campo';

        $filas = Db::all($sql, $params);
        foreach ($filas as &$f) {
            $f['id_campo']    = (int)$f['id_campo'];
            $f['id_elemento'] = $f['id_elemento'] === null ? null : (int)$f['id_elemento'];
            $f['obligatorio'] = (int)$f['obligatorio'];
            $f['activo']      = (int)$f['activo'];
            $f['opciones']    = $f['opciones'] ? json_decode((string)$f['opciones'], true) : null;
        }
        unset($f);
        Http::ok(['campos' => $filas]);
    }

    Auth::exigirAdmin();

    if ($metodo === 'POST') {
        $clave = preg_replace('/[^a-z0-9_]/', '', mb_strtolower((string)Http::requerido('clave')));
        if ($clave === '') {
            throw new ApiError('La clave sólo puede llevar letras, números y guión bajo.', 422, ['campo' => 'clave']);
        }
        $opciones = Http::campo('opciones');
        $id = Db::insert('campos_elemento', [
            'id_elemento' => Http::id(Http::campo('id_elemento')),
            'clave'       => $clave,
            'etiqueta'    => mb_substr((string)Http::requerido('etiqueta'), 0, 120),
            'tipo'        => (string)Http::campo('tipo', 'texto'),
            'opciones'    => is_array($opciones) ? json_encode($opciones, JSON_UNESCAPED_UNICODE) : null,
            'unidad'      => mb_substr((string)Http::campo('unidad', ''), 0, 16) ?: null,
            'obligatorio' => Http::bool(Http::campo('obligatorio')) ? 1 : 0,
            'ayuda'       => mb_substr((string)Http::campo('ayuda', ''), 0, 300) ?: null,
            'orden'       => (int)Http::campo('orden', 0),
            'activo'      => 1,
            'updated_at'  => Db::now(),
        ]);
        Http::ok(['id_campo' => $id], 201);
    }

    $idCampo = Http::id($partes[1] ?? null);
    if ($idCampo !== null) {
        if ($metodo === 'DELETE') {
            // No se borra: se desactiva, para no perder los valores ya
            // registrados en los partes antiguos.
            Db::update('campos_elemento', ['activo' => 0, 'updated_at' => Db::now()],
                       'id_campo = :id', ['id' => $idCampo]);
            Http::ok(['mensaje' => 'Campo desactivado. Los datos ya registrados se conservan.']);
        }
        if ($metodo === 'PATCH') {
            $datos = ['updated_at' => Db::now()];
            foreach (['etiqueta', 'tipo', 'unidad', 'ayuda'] as $c) {
                if (array_key_exists($c, Http::body())) {
                    $datos[$c] = mb_substr((string)Http::campo($c), 0, 300) ?: null;
                }
            }
            foreach (['obligatorio', 'activo'] as $c) {
                if (array_key_exists($c, Http::body())) {
                    $datos[$c] = Http::bool(Http::campo($c)) ? 1 : 0;
                }
            }
            if (array_key_exists('orden', Http::body())) {
                $datos['orden'] = (int)Http::campo('orden');
            }
            if (array_key_exists('opciones', Http::body())) {
                $o = Http::campo('opciones');
                $datos['opciones'] = is_array($o) ? json_encode($o, JSON_UNESCAPED_UNICODE) : null;
            }
            Db::update('campos_elemento', $datos, 'id_campo = :id', ['id' => $idCampo]);
            Http::ok(['mensaje' => 'Campo guardado.']);
        }
    }
}

/* ================================================================= */
/* ATAJOS DE LA PANTALLA DE INICIO                                   */
/* ================================================================= */
if ($seccion === 'atajos') {

    if ($metodo === 'GET') {
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
        foreach ($filas as &$f) {
            $f['id_atajo'] = (int)$f['id_atajo'];
            $f['filtro']   = json_decode((string)$f['filtro'], true);
            $f['mio']      = $f['id_login'] !== null && (int)$f['id_login'] === (int)$u['id_login'];
        }
        unset($f);
        Http::ok(['atajos' => $filas]);
    }

    if ($metodo === 'POST') {
        $paraTodos = Http::bool(Http::campo('para_todos'));
        if ($paraTodos && !Auth::esAdmin($u)) {
            throw new ApiError('Sólo un administrador puede crear atajos para todo el equipo.', 403);
        }
        $filtro = Http::campo('filtro');
        if (!is_array($filtro)) {
            throw new ApiError('Falta el filtro del atajo.', 422);
        }
        $id = Db::insert('atajos', [
            'nombre'     => mb_substr((string)Http::requerido('nombre'), 0, 120),
            'icono'      => mb_substr((string)Http::campo('icono', 'lista'), 0, 40),
            'color'      => mb_substr((string)Http::campo('color', '#0B5563'), 0, 9),
            'filtro'     => json_encode($filtro, JSON_UNESCAPED_UNICODE),
            'id_login'   => $paraTodos ? null : (int)$u['id_login'],
            'id_departamento' => Http::id(Http::campo('id_departamento')),
            'solo_rol'   => Http::campo('solo_rol') ?: null,
            'orden'      => (int)Http::campo('orden', 99),
            'activo'     => 1,
            'updated_at' => Db::now(),
        ]);
        Http::ok(['id_atajo' => $id], 201);
    }

    $idAtajo = Http::id($partes[1] ?? null);
    if ($idAtajo !== null) {
        $a = Db::one('SELECT * FROM atajos WHERE id_atajo = ?', [$idAtajo]);
        if ($a === null) {
            throw new ApiError('El atajo no existe.', 404);
        }
        $mio = $a['id_login'] !== null && (int)$a['id_login'] === (int)$u['id_login'];
        if (!$mio && !Auth::esAdmin($u)) {
            throw new ApiError('Sólo puedes cambiar tus propios atajos.', 403);
        }

        if ($metodo === 'DELETE') {
            Db::run('DELETE FROM atajos WHERE id_atajo = ?', [$idAtajo]);
            Http::ok(['mensaje' => 'Atajo eliminado.']);
        }
        if ($metodo === 'PATCH') {
            $datos = ['updated_at' => Db::now()];
            foreach (['nombre', 'icono', 'color'] as $c) {
                if (array_key_exists($c, Http::body())) {
                    $datos[$c] = mb_substr((string)Http::campo($c), 0, 120);
                }
            }
            if (array_key_exists('filtro', Http::body())) {
                $datos['filtro'] = json_encode(Http::campo('filtro'), JSON_UNESCAPED_UNICODE);
            }
            if (array_key_exists('orden', Http::body())) { $datos['orden'] = (int)Http::campo('orden'); }
            if (array_key_exists('activo', Http::body())) { $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0; }
            Db::update('atajos', $datos, 'id_atajo = :id', ['id' => $idAtajo]);
            Http::ok(['mensaje' => 'Atajo guardado.']);
        }
    }
}

/* ================================================================= */
/* FAMILIAS                                                          */
/* ================================================================= */
if ($seccion === 'familias') {

    if ($metodo === 'GET' && ($partes[1] ?? '') === '') {
        $filas = Db::all(
            'SELECT f.*, d.nombre AS departamento, p.nombre AS prioridad,
                    (SELECT COUNT(*) FROM elemento e WHERE e.id_familia = f.id_familia) AS n_elementos
               FROM familias f
               LEFT JOIN departamentos d ON d.id_departamento = f.id_departamento
               LEFT JOIN prioridades p ON p.id_prioridad = f.id_prioridad
              ORDER BY f.orden, f.nombre'
        );
        foreach ($filas as &$f) {
            $f['id_familia']  = (int)$f['id_familia'];
            $f['n_elementos'] = (int)$f['n_elementos'];
            $f['activo']      = (int)$f['activo'];
            $f['id_departamento'] = $f['id_departamento'] === null ? null : (int)$f['id_departamento'];
            $f['id_prioridad']    = $f['id_prioridad'] === null ? null : (int)$f['id_prioridad'];
        }
        unset($f);
        Http::ok([
            'familias'    => $filas,
            'sin_familia' => (int)Db::value('SELECT COUNT(*) FROM elemento WHERE id_familia IS NULL'),
        ]);
    }

    /* GET /api/familias/{id}/elementos */
    $idFam = Http::id($partes[1] ?? null);
    if ($idFam !== null && ($partes[2] ?? '') === 'elementos' && $metodo === 'GET') {
        $filas = Db::all(
            'SELECT id_elemento, nombre_elemento, abreviatura_elemento, usos
               FROM elemento WHERE id_familia = ? ORDER BY nombre_elemento',
            [$idFam]
        );
        foreach ($filas as &$f) { $f['id_elemento'] = (int)$f['id_elemento']; $f['usos'] = (int)$f['usos']; }
        unset($f);
        Http::ok(['elementos' => $filas]);
    }

    Auth::exigirAdmin();

    /* POST /api/familias/mover — reasignar elementos en bloque */
    if ($metodo === 'POST' && ($partes[1] ?? '') === 'mover') {
        $ids = Http::campo('elementos');
        $destino = Http::id(Http::requerido('id_familia'));
        if (!is_array($ids) || !$ids || $destino === null) {
            throw new ApiError('Indica los elementos y la familia de destino.', 422);
        }
        $en = implode(',', array_map('intval', $ids));
        Db::run("UPDATE elemento SET id_familia = ?, updated_at = ? WHERE id_elemento IN ($en)",
                [$destino, Db::now()]);
        // El icono y el grupo se heredan de la familia nueva.
        Db::run("UPDATE elemento
                    SET icono = (SELECT icono FROM familias WHERE id_familia = ?),
                        id_departamento = (SELECT id_departamento FROM familias WHERE id_familia = ?)
                  WHERE id_elemento IN ($en)", [$destino, $destino]);
        Http::ok(['movidos' => count($ids)]);
    }

    if ($metodo === 'POST' && ($partes[1] ?? '') === '') {
        $id = Db::insert('familias', [
            'nombre'      => mb_substr((string)Http::requerido('nombre'), 0, 80),
            'clase'       => in_array(Http::campo('clase'), ['averia','peticion','tarea'], true)
                             ? Http::campo('clase') : 'averia',
            'icono'       => mb_substr((string)Http::campo('icono', 'llave-inglesa'), 0, 40),
            'color'       => mb_substr((string)Http::campo('color', '#0B5563'), 0, 9),
            'id_departamento' => Http::id(Http::campo('id_departamento')),
            'id_prioridad'    => Http::id(Http::campo('id_prioridad')),
            'descripcion' => mb_substr((string)Http::campo('descripcion', ''), 0, 200) ?: null,
            'orden'       => (int)Http::campo('orden', 50),
            'activo'      => 1,
            'updated_at'  => Db::now(),
        ]);
        Http::ok(['id_familia' => $id], 201);
    }

    if ($idFam !== null && $metodo === 'PATCH') {
        $datos = ['updated_at' => Db::now()];
        foreach (['nombre','icono','color','descripcion'] as $c) {
            if (array_key_exists($c, Http::body())) {
                $datos[$c] = mb_substr((string)Http::campo($c), 0, 200) ?: null;
            }
        }
        if (array_key_exists('clase', Http::body())
            && in_array(Http::campo('clase'), ['averia','peticion','tarea'], true)) {
            $datos['clase'] = Http::campo('clase');
        }
        foreach (['id_departamento','id_prioridad'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = Http::id(Http::campo($c)); }
        }
        if (array_key_exists('orden', Http::body()))  { $datos['orden'] = (int)Http::campo('orden'); }
        if (array_key_exists('activo', Http::body())) { $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0; }
        Db::update('familias', $datos, 'id_familia = :id', ['id' => $idFam]);

        /* Si cambia el departamento de la familia, sus tipos van detrás:
           los que no tengan puesto uno a mano. Sin esto, los tipos se
           quedaban con el departamento viejo y los partes seguían yendo
           allí aunque la familia dijera otra cosa. */
        if (array_key_exists('id_departamento', $datos)
            && in_array('dep_propio', Db::columnas('elemento'), true)) {
            Db::run('UPDATE elemento SET id_departamento = ?, updated_at = ?
                      WHERE id_familia = ? AND COALESCE(dep_propio, 0) = 0',
                    [$datos['id_departamento'], Db::now(), $idFam]);
        }

        Http::ok(['mensaje' => 'Familia guardada.']);
    }

    if ($idFam !== null && $metodo === 'DELETE') {
        $n = (int)Db::value('SELECT COUNT(*) FROM elemento WHERE id_familia = ?', [$idFam]);
        if ($n > 0) {
            throw new ApiError("Esa familia tiene $n tipos dentro. Muévelos antes de eliminarla.", 409);
        }
        Db::run('DELETE FROM familias WHERE id_familia = ?', [$idFam]);
        Http::ok(['mensaje' => 'Familia eliminada.']);
    }
}

/* ================================================================= */
/* REGISTRO DE SEGURIDAD                                             */
/* ================================================================= */
if ($seccion === 'seguridad') {
    Auth::exigirAdmin();

    if ($metodo === 'GET' && ($partes[1] ?? '') === '') {
        Http::ok(array_merge(
            Registro::consultar([
                'tipo'        => Http::query('tipo'),
                'id_login'    => Http::queryInt('quien'),
                'ip'          => Http::query('ip'),
                'solo_fallos' => Http::bool(Http::query('fallos')),
                'desde'       => Http::query('desde'),
                'limite'      => Http::queryInt('limite', 100),
                'offset'      => Http::queryInt('offset', 0),
            ]),
            ['resumen' => Registro::resumen()]
        ));
    }

    if ($metodo === 'DELETE' && ($partes[1] ?? '') === '') {
        $n = Registro::limpiar();
        Registro::anotar('borrado', (int)$u['id_login'], "Purga del registro: $n entradas");
        Http::ok(['borrados' => $n]);
    }
}

/* ================================================================= */
/* COPIAS DE SEGURIDAD                                               */
/* ================================================================= */
if ($seccion === 'copias') {
    require_once __DIR__ . '/../lib/Copias.php';

    Auth::exigirAdmin();

    /* Pedir el vale. Esto sí va con la cabecera de siempre. */
    if ($metodo === 'POST' && ($partes[2] ?? '') === 'vale') {
        $idC = Http::id($partes[1] ?? null);
        if ($idC === null) { throw new ApiError('Falta la copia.', 422); }
        Http::ok(['vale' => Descargas::vale((int)$u['id_login'], 'copias/' . $idC),
                  'segundos' => Descargas::SEGUNDOS]);
    }

    if ($metodo === 'GET' && ($partes[1] ?? '') === '') {
        Http::ok([
            'copias'  => Copias::listar(),
            'destino' => Copias::carpeta(),
            'activo'  => Permisos::activo('copias'),
        ]);
    }

    if ($metodo === 'POST' && ($partes[1] ?? '') === '') {
        $c = Copias::crear((int)$u['id_login'], Http::bool(Http::campo('con_fotos')));
        Registro::anotar('copia', (int)$u['id_login'], $c['archivo'] . ' · ' . $c['registros'] . ' registros');
        Http::ok(['copia' => $c], 201);
    }

    $idCopia = Http::id($partes[1] ?? null);
    if ($idCopia !== null && $metodo === 'GET') {
        Copias::descargar($idCopia);
    }
    if ($idCopia !== null && $metodo === 'DELETE') {
        Copias::borrar($idCopia);
        Http::ok(['mensaje' => 'Copia eliminada.']);
    }
}

throw new ApiError('Ruta no encontrada.', 404);
