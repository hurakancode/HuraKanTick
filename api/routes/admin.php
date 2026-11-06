<?php
/** Rutas /api/usuarios y /api/zonas (administración) */
declare(strict_types=1);

$seccion = $partes[0];

/* ================================================================= */
/* USUARIOS                                                          */
/* ================================================================= */
if ($seccion === 'usuarios') {

    $id = Http::id($partes[1] ?? null);

    /* ================================================================ */
    /* POST /api/usuarios/lote — la misma acción sobre varias personas   */
    /* ================================================================ */
    /* Un solo viaje al servidor en vez de cincuenta.
     *
     * No es sólo por velocidad: cincuenta peticiones sueltas desde un
     * móvil con mala cobertura se quedan a medias y nadie sabe por
     * dónde. Aquí va cada persona en su propia transacción —si una falla
     * las demás siguen— y al final se dice exactamente qué pasó con cada
     * una.
     *
     * Las protecciones son LAS MISMAS que al editar una por una, y se
     * comprueban aquí otra vez: un atajo en masa que se salte las reglas
     * del camino individual es la forma más fácil de abrir un agujero. */
    if (($partes[1] ?? '') === 'lote' && $metodo === 'POST') {
        $yo = Auth::exigirAdmin();

        $ids = Http::campo('ids');
        $que = Http::texto('accion');
        if (!is_array($ids) || !$ids) {
            throw new ApiError('No has marcado a nadie.', 422, ['campo' => 'ids']);
        }
        if (count($ids) > 500) {
            throw new ApiError('Demasiadas personas de una vez (máximo 500).', 422);
        }

        $permitidas = ['bloquear', 'desbloquear', 'departamento', 'rol', 'teams'];
        if (!in_array($que, $permitidas, true)) {
            throw new ApiError('Acción no reconocida: ' . $que, 422, ['campo' => 'accion']);
        }

        // Lo que necesita cada acción, comprobado una vez y no por fila.
        $idDepto = null;
        if ($que === 'departamento') {
            $idDepto = Http::id(Http::campo('id_departamento'));
            if ($idDepto !== null && !Db::value(
                    'SELECT 1 FROM departamentos WHERE id_departamento = ?', [$idDepto])) {
                throw new ApiError('Ese departamento no existe.', 422);
            }
        }
        $rol = null;
        if ($que === 'rol') {
            $rol = (string)Http::campo('rol', '');
            if (!in_array($rol, ['admin', 'responsable', 'tecnico', 'reportador'], true)) {
                throw new ApiError('Ese papel no existe.', 422, ['campo' => 'rol']);
            }
        }
        $teams = $que === 'teams' ? Http::bool(Http::campo('valor')) : null;

        $hechas = 0;
        $saltadas = [];

        foreach ($ids as $bruto) {
            $idU = Http::id($bruto);
            if ($idU === null) { continue; }

            $p = Db::one('SELECT id_login, correo, usuario, nombre, apellido1, admin,
                                 superadmin, bloqueo, id_hotel
                            FROM login WHERE id_login = ?', [$idU]);

            $comoSeLlama = $p === null ? ('#' . $idU)
                : (trim(($p['nombre'] ?? '') . ' ' . ($p['apellido1'] ?? '')) ?: (string)$p['usuario']);

            if ($p === null) {
                $saltadas[] = ['quien' => $comoSeLlama, 'por' => 'ya no existe'];
                continue;
            }

            /* Uno mismo nunca. Quitarse el acceso en una acción en masa
               es dejarse fuera de la aplicación sin querer, y en masa es
               fácil marcarse a uno mismo sin darse cuenta. */
            if ($idU === (int)$yo['id_login']) {
                $saltadas[] = ['quien' => $comoSeLlama, 'por' => 'eres tú'];
                continue;
            }

            // A un superadministrador sólo lo toca otro.
            if ((int)($p['superadmin'] ?? 0) === 1 && !Hoteles::esSuperadmin($yo)) {
                $saltadas[] = ['quien' => $comoSeLlama, 'por' => 'es administrador principal'];
                continue;
            }

            /* Y no se deja a un hotel sin ningún administrador con
               acceso: sin eso no hay quien entre a arreglar nada. */
            if (($que === 'bloquear' || ($que === 'rol' && $rol !== 'admin'))
                && (int)($p['admin'] ?? 0) === 1) {
                $otros = (int)Db::value(
                    'SELECT COUNT(*) FROM login
                      WHERE admin = 1 AND bloqueo = 0 AND id_login <> ?'
                    . (Db::tablaExiste('hoteles') && $p['id_hotel'] !== null ? ' AND id_hotel = ?' : ''),
                    Db::tablaExiste('hoteles') && $p['id_hotel'] !== null
                        ? [$idU, (int)$p['id_hotel']] : [$idU]);
                if ($otros === 0) {
                    $saltadas[] = ['quien' => $comoSeLlama,
                                   'por' => 'es el único administrador con acceso'];
                    continue;
                }
            }

            Db::begin();
            try {
                switch ($que) {
                    case 'bloquear':
                        Db::update('login', ['bloqueo' => 1, 'updated_at' => Db::now()],
                                   'id_login = :id', ['id' => $idU]);
                        if (Db::tablaExiste('api_tokens')) {
                            // Si no, sigue dentro con la sesión que ya tenía.
                            Db::run('UPDATE api_tokens SET revocado = 1 WHERE id_login = ?', [$idU]);
                        }
                        Registro::anotar('bloqueo', $idU,
                            'Acceso retirado en lote por ' . ($yo['correo'] ?? ''));
                        break;

                    case 'desbloquear':
                        Db::update('login', ['bloqueo' => 0, 'intentos' => 0,
                                             'updated_at' => Db::now()],
                                   'id_login = :id', ['id' => $idU]);
                        Registro::anotar('bloqueo', $idU,
                            'Acceso devuelto en lote por ' . ($yo['correo'] ?? ''));
                        break;

                    case 'departamento':
                        Db::update('login', ['id_departamento' => $idDepto,
                                             'updated_at' => Db::now()],
                                   'id_login = :id', ['id' => $idU]);
                        break;

                    case 'rol':
                        Db::update('login', ['rol' => $rol,
                                             'admin' => $rol === 'admin' ? 1 : 0,
                                             'updated_at' => Db::now()],
                                   'id_login = :id', ['id' => $idU]);
                        Registro::anotar('rol', $idU,
                            'Papel cambiado en lote a ' . $rol . ' por ' . ($yo['correo'] ?? ''));
                        break;

                    case 'teams':
                        if (in_array('teams_avisar', Db::columnas('login'), true)) {
                            Db::update('login', ['teams_avisar' => $teams ? 1 : 0,
                                                 'updated_at' => Db::now()],
                                       'id_login = :id', ['id' => $idU]);
                        }
                        break;
                }
                Db::commit();
                $hechas++;
            } catch (Throwable $e) {
                Db::rollback();
                $saltadas[] = ['quien' => $comoSeLlama, 'por' => $e->getMessage()];
                Errores::apuntar('servidor',
                    'Acción en lote «' . $que . '» falló con la persona ' . $idU . ': '
                    . $e->getMessage(), $e->getTraceAsString(), 'usuarios/lote');
            }
        }

        $comoSeDice = [
            'bloquear' => 'sin acceso', 'desbloquear' => 'con acceso de nuevo',
            'departamento' => 'con el departamento cambiado',
            'rol' => 'con el papel cambiado', 'teams' => 'con la copia a Teams cambiada',
        ][$que];

        Http::ok([
            'hechas' => $hechas,
            'saltadas' => $saltadas,
            'mensaje' => $hechas === 0
                ? 'No se ha podido con ninguna.'
                : $hechas . ' persona' . ($hechas === 1 ? '' : 's') . ' ' . $comoSeDice . '.'
                  . ($saltadas ? ' ' . count($saltadas) . ' se han quedado como estaban.' : ''),
        ]);
    }

    /**
     * Departamentos adicionales y permisos sueltos de una persona.
     *
     * Los adicionales son para lo de siempre: informática y servicios
     * técnicos que se cubren. Los permisos, para dar una capacidad
     * concreta —apagar el modo sin cobertura, por ejemplo— sin tener que
     * hacer administrador a nadie.
     *
     * Sólo se tocan si vienen en el envío: una edición que no los
     * mencione no debe borrarlos sin querer.
     */
    $guardarExtras = static function (int $idLogin): void {
        // Quién está pidiendo el cambio. Se consulta aquí, no al declarar
        // la función: en ese momento todavía no se ha identificado a nadie.
        $quien = Auth::exigir();
        /* Esto reparte acceso: un departamento adicional abre los partes
           de otro departamento y un permiso da una capacidad suelta. Lo
           decide un administrador, nunca el interesado.
           Sin esta comprobación, cualquiera podía añadirse departamentos
           a sí mismo al editar su propia ficha. */
        if (!Auth::esAdmin($quien)) {
            if (array_key_exists('departamentos_extra', Http::body())
                || array_key_exists('permisos', Http::body())) {
                throw new ApiError(
                    'Sólo un administrador puede dar departamentos adicionales o permisos.', 403);
            }
            return;
        }

        if (array_key_exists('departamentos_extra', Http::body())
            && Db::tablaExiste('login_departamento')) {
            $suyo  = Http::id(Db::value('SELECT id_departamento FROM login WHERE id_login = ?', [$idLogin]));
            $lista = Http::campo('departamentos_extra');
            Db::run('DELETE FROM login_departamento WHERE id_login = ?', [$idLogin]);
            foreach (is_array($lista) ? $lista : [] as $idDep) {
                $idDep = Http::id($idDep);
                if ($idDep === null || $idDep === $suyo) { continue; }
                Db::insertarSiFalta('login_departamento', [
                    'id_login' => $idLogin, 'id_departamento' => $idDep, 'creado' => Db::now(),
                ]);
            }
        }

        if (array_key_exists('permisos', Http::body()) && Db::tablaExiste('login_permisos')) {
            $validos = ['offline_apagar', 'asignar_companero',
                        'asignar_responsable', 'asignar_otro_departamento'];
            $lista = Http::campo('permisos');
            Db::run('DELETE FROM login_permisos WHERE id_login = ?', [$idLogin]);
            foreach (is_array($lista) ? $lista : [] as $permiso) {
                if (!in_array($permiso, $validos, true)) { continue; }
                Db::insertarSiFalta('login_permisos', [
                    'id_login' => $idLogin, 'permiso' => (string)$permiso, 'creado' => Db::now(),
                ]);
            }
        }
    };

    /* GET /api/usuarios/recuperaciones — peticiones pendientes */
    if ($metodo === 'GET' && ($partes[1] ?? '') === 'recuperaciones') {
        Auth::exigirAdmin();
        $nombre = Db::nombreCompleto('l');
        $filas = Db::all(
            "SELECT r.id_recuperacion, r.creado, r.expira, r.ip_solicita, r.codigo_hash,
                    l.id_login, l.correo, $nombre AS persona
               FROM recuperaciones r
               JOIN login l ON l.id_login = r.id_login
              WHERE r.usado = 0 AND r.expira > ?
              ORDER BY r.creado DESC",
            [Db::now()]
        );
        foreach ($filas as &$f) {
            $f['id_recuperacion'] = (int)$f['id_recuperacion'];
            $f['id_login'] = (int)$f['id_login'];
            $f['tiene_codigo'] = $f['codigo_hash'] !== '';
            unset($f['codigo_hash']);          // nunca sale del servidor
        }
        Http::ok(['recuperaciones' => $filas]);
    }

    /* POST /api/usuarios/{id}/codigo — emitir un código de un solo uso */
    $idUsuario = Http::id($partes[1] ?? null);
    /* POST/PATCH/DELETE /api/usuarios/{id}/enlace — la auto-entrada.
     *
     * Sólo el administrador de la cadena: un enlace es una llave, y
     * repartir llaves de otros hoteles no es cosa de un admin local. */
    if ($idUsuario !== null && ($partes[2] ?? '') === 'enlace') {
        /* Quién lo hace. Aquí se usaba $u sin haberla definido nunca: la
           puerta aguantaba de casualidad (esSuperadmin(null) acaba mirando
           la sesión), pero cada llave se generaba, encendía y retiraba a
           nombre de «nadie» (id 0) en el registro de seguridad: justo lo
           que hay que poder consultar si una llave acaba donde no debe.
           216 avisos «Undefined variable $u» en el registro de Apache. */
        $u = Auth::exigir();
        if (!Hoteles::esSuperadmin($u)) {
            throw new ApiError('Sólo un administrador maneja los enlaces de auto-entrada.', 403);
        }

        $base = (string)Http::campo('base', '');

        if ($metodo === 'POST') {
            /* Con la auto-entrada apagada en el hotel, el enlace se generaba
               igual: el administrador le daba a alguien una llave que, en la
               puerta, sólo decía «desactivados en este hotel». Mejor decirlo
               aquí, que es donde se puede arreglar. */
            if (!AutoEntrada::permitidos()) {
                throw new ApiError('Los enlaces de auto-entrada están apagados en este hotel: '
                    . 'el que generes no dejaría entrar. Enciéndelos en Configuración → Seguridad.', 409);
            }
            $r = AutoEntrada::generar($idUsuario, (int)$u['id_login'], $base);
            Http::ok([
                'url'    => $r['url'],
                'creado' => $r['creado'],
                'caduca' => $r['caduca'],
                'aviso'  => 'Cópiala ahora: no se guarda entera y no se puede volver a ver.',
            ]);
        }

        if ($metodo === 'PATCH') {
            AutoEntrada::activar($idUsuario, Http::bool(Http::campo('activo')), (int)$u['id_login']);
            Http::ok(['mensaje' => Http::bool(Http::campo('activo'))
                ? 'Auto-entrada habilitada.' : 'Auto-entrada deshabilitada.']);
        }

        if ($metodo === 'DELETE') {
            AutoEntrada::retirar($idUsuario, (int)$u['id_login']);
            Http::ok(['mensaje' => 'Enlace retirado. Deja de valer al instante.']);
        }
    }

    if ($idUsuario !== null && ($partes[2] ?? '') === 'codigo' && $metodo === 'POST') {
        $yo = Auth::exigirAdmin();
        $destino = Db::one('SELECT id_login, correo FROM login WHERE id_login = ?', [$idUsuario]);
        if ($destino === null) {
            throw new ApiError('Esa persona no existe.', 404);
        }

        // Seis dígitos: se dictan por teléfono o se leen en un papel sin
        // equivocarse, que es como se va a entregar en la práctica.
        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Db::run('UPDATE recuperaciones SET usado = 1 WHERE id_login = ? AND usado = 0', [$idUsuario]);
        Db::insert('recuperaciones', [
            'id_login'    => $idUsuario,
            'codigo_hash' => hash('sha256', $codigo),
            'creado'      => Db::now(),
            'expira'      => gmdate('Y-m-d H:i:s', time() + 3600),
            'usado'       => 0,
            'ip_solicita' => Registro::ip(),
            'id_emisor'   => (int)$yo['id_login'],
            'intentos'    => 0,
        ]);
        Registro::anotar('recuperacion', $idUsuario,
            'Código emitido por ' . ($yo['correo'] ?? 'un administrador'), true, (string)$destino['correo']);

        Http::ok([
            'codigo'  => $codigo,     // se muestra una sola vez
            'caduca'  => 'en 1 hora',
            'correo'  => $destino['correo'],
            'aviso'   => 'Dáselo en persona o por teléfono. No se vuelve a mostrar.',
        ]);
    }

    if ($metodo === 'GET' && $id === null) {
        $u = Auth::exigir();

        /* Quién ve la ficha completa y quién sólo los nombres.
         *
         * La aplicación necesita la lista de personas para poder asignar
         * un parte, así que no se puede cerrar del todo. Pero un
         * teléfono, un correo y la fecha del último acceso son datos
         * personales, y quien sólo avisa de averías no tiene por qué
         * tenerlos: cualquiera con una cuenta podía sacar el listín
         * entero del hotel pidiendo esta ruta a mano. */
        $completo = Auth::esResponsable($u);
        $nombre = Db::nombreCompleto('l');
        [$hc, $hp] = Db::tablaExiste('hoteles') ? Hoteles::filtro('l', $u) : ['1 = 1', []];

        /* El nombre de usuario y el estado del enlace de auto-entrada.
         *
         * Faltaban: la ficha de cada persona enseñaba el correo en lugar
         * del usuario —y el campo de usuario salía vacío—, y el bloque del
         * enlace no sabía si ya había uno, así que los botones de
         * deshabilitarlo y retirarlo no aparecían nunca.
         *
         * Del enlace se manda sólo si existe, no su identificador: la
         * pantalla no lo necesita para nada. Se comprueba que las columnas
         * estén, por si alguien sube el código sin pasar el instalador. */
        $cols = Db::columnas('login');
        $extra = '';
        if (in_array('usuario', $cols, true)) {
            $extra .= ', l.usuario';
        }
        if (in_array('mensaje_bienvenida', $cols, true)) {
            $extra .= ', l.mensaje_bienvenida, l.bienvenida_activa, l.mensaje_proximo,'
                    . ' l.frases_activas, l.frases_categorias';
            if (in_array('frases_cada_inicio', $cols, true)) { $extra .= ', l.frases_cada_inicio'; }
        }
        if (in_array('autologin_id', $cols, true)) {
            $extra .= ', l.autologin_activo, l.autologin_ultimo_uso, l.autologin_usos,'
                    . ' CASE WHEN l.autologin_id IS NULL THEN 0 ELSE 1 END AS tiene_enlace';
        }

        $filas = Db::all(
            "SELECT l.id_login, l.correo, l.nombre, l.apellido1, l.apellido2, l.telefono{$extra},
                    l.rol, l.admin, l.superadmin, l.bloqueo, l.id_departamento, l.ultimo_acceso, l.fecha_alta,
                    $nombre AS nombre_completo, d.nombre AS departamento,
                    (SELECT COUNT(*) FROM ticket_incidencias t
                      LEFT JOIN estados e ON e.id_estado = t.id_estado
                     WHERE t.id_persona_asignada = l.id_login AND t.borrado = 0 AND COALESCE(e.es_final,0) = 0) AS abiertos
               FROM login l
               LEFT JOIN departamentos d ON d.id_departamento = l.id_departamento
              WHERE $hc
              ORDER BY l.bloqueo, $nombre",
            $hp
        );
        foreach ($filas as &$f) {
            if (!$completo) {
                // Lo justo para poder elegir a quién se le asigna algo.
                $f = [
                    'id_login'        => (int)$f['id_login'],
                    'nombre_completo' => $f['nombre_completo'],
                    'nombre'          => $f['nombre'],
                    'id_departamento' => $f['id_departamento'] === null ? null : (int)$f['id_departamento'],
                    // Los departamentos en los que además echa una mano, y
                    // los permisos sueltos que tenga.
                    'departamentos_extra' => Permisos::departamentosDe((int)$f['id_login']),
                    'permisos' => Db::tablaExiste('login_permisos')
                        ? array_column(Db::all('SELECT permiso FROM login_permisos WHERE id_login = ?',
                                               [(int)$f['id_login']]), 'permiso')
                        : [],
                    'departamento'    => $f['departamento'],
                    'rol'             => $f['rol'],
                ];
                continue;
            }
            $f['id_login'] = (int)$f['id_login'];
            $f['admin']    = (int)$f['admin'];
            $f['bloqueo']  = (int)$f['bloqueo'];
            $f['abiertos'] = (int)$f['abiertos'];

            /* Los departamentos donde además echa una mano y sus permisos
               sueltos: sin esto, la pantalla de editar abría siempre con
               las casillas en blanco y al guardar borraba lo que hubiera. */
            $f['departamentos_extra'] = Permisos::departamentosDe((int)$f['id_login']);
            $f['teams_avisar'] = in_array('teams_avisar', Db::columnas('login'), true)
                ? (int)($f['teams_avisar'] ?? 1) : 1;
            $f['permisos'] = Db::tablaExiste('login_permisos')
                ? array_column(Db::all('SELECT permiso FROM login_permisos WHERE id_login = ?',
                                       [(int)$f['id_login']]), 'permiso')
                : [];
        }
        unset($f);
        Http::ok(['usuarios' => $filas]);
    }

    if ($metodo === 'POST' && $id === null) {
        Auth::exigirAdmin();

        /* El nombre de usuario manda; el correo es opcional.
         *
         * En un hotel hay cuentas de puesto —«recepcion», «pisos»— que
         * usa quien esté de turno y que no tienen correo de nadie.
         * Obligar a poner uno hace que se inventen direcciones falsas,
         * y entonces el día que haga falta avisar por correo no llega. */
        $usuario = (string)Http::requerido('usuario');
        $plano   = Auth::normalizarUsuario($usuario);

        if ($plano === '') {
            throw new ApiError(
                'El nombre de usuario sólo puede llevar letras, cifras, punto, guión y guión bajo.',
                422, ['campo' => 'usuario']);
        }
        if (mb_strlen($plano) < 3) {
            throw new ApiError('El nombre de usuario necesita al menos 3 caracteres.',
                422, ['campo' => 'usuario']);
        }
        /* Y un máximo, que no estaba.
         *
         * La columna admite 60 caracteres. En SQLite eso da igual —se traga
         * lo que le eches— pero en MySQL, que es lo que va a haber en el
         * servidor del hotel, la inserción falla y la persona ve «no se ha
         * podido completar la operación en la base de datos», que no le
         * dice nada ni le deja arreglarlo.
         *
         * No se recorta en silencio a propósito: un nombre de usuario es
         * una identidad, y recortarlo podría dejar dos personas con el
         * mismo. Mejor decirlo. */
        /* Se mide el nombre TAL CUAL se escribe, no el normalizado.
           El normalizado ya venía recortado a 60 por su propia función, así
           que comprobarlo a él no servía de nada: el que se guarda en la
           columna «usuario» —de 60— es el original, y ése entraba entero. */
        if (mb_strlen($usuario) > 60) {
            throw new ApiError(
                'El nombre de usuario no puede pasar de 60 caracteres.',
                422, ['campo' => 'usuario']);
        }
        if (Db::value('SELECT 1 FROM login WHERE usuario_plano = ?', [$plano])) {
            throw new ApiError('Ya hay alguien con ese nombre de usuario.', 409, ['campo' => 'usuario']);
        }

        $correo = mb_strtolower(trim((string)Http::campo('correo', '')));

        if ($correo === '' && Permisos::ajuste('correo_obligatorio', false)) {
            throw new ApiError('En este hotel el correo es obligatorio.', 422, ['campo' => 'correo']);
        }
        if ($correo !== '') {
            // 190 es lo que admite el índice único de la columna en MySQL.
            if (mb_strlen($correo) > 190) {
                throw new ApiError('El correo no puede pasar de 190 caracteres.',
                    422, ['campo' => 'correo']);
            }
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new ApiError('El correo no tiene un formato válido.', 422, ['campo' => 'correo']);
            }
            if (Db::value('SELECT 1 FROM login WHERE LOWER(correo) = ?', [$correo])) {
                throw new ApiError('Ya existe una cuenta con ese correo.', 409, ['campo' => 'correo']);
            }
        }
        $pwd = (string)Http::requerido('password');
        if (mb_strlen($pwd) < 8) {
            throw new ApiError('La contraseña debe tener al menos 8 caracteres.', 422, ['campo' => 'password']);
        }
        $rol = (string)Http::campo('rol', 'tecnico');
        if (!in_array($rol, ['admin','responsable','tecnico','reportador'], true)) {
            $rol = 'tecnico';
        }

        $nuevo = Db::insert('login', [
            'usuario'       => $usuario,
            'usuario_plano' => $plano,
            /* Nace en el hotel de quien lo da de alta. Un
               superadministrador puede indicar otro con ?hotel=N; el
               resto no puede crear gente fuera del suyo. */
            // Un superadministrador puede indicar el hotel; el resto
            // sólo puede crear gente en el suyo.
            'id_hotel'  => !Db::tablaExiste('hoteles') ? null
                : (Hoteles::esSuperadmin() && Http::id(Http::campo('id_hotel')) !== null
                    ? Http::id(Http::campo('id_hotel'))
                    : Hoteles::actual()),
            'correo'    => $correo !== '' ? $correo : null,
            'pwd_php'   => Auth::hashPassword($pwd),
            'nombre'    => mb_substr(trim((string)Http::campo('nombre', '')), 0, 80),
            'apellido1' => mb_substr(trim((string)Http::campo('apellido1', '')), 0, 80),
            'apellido2' => mb_substr(trim((string)Http::campo('apellido2', '')), 0, 80),
            'telefono'  => mb_substr(trim((string)Http::campo('telefono', '')), 0, 32),
            'rol'       => $rol,
            'admin'     => $rol === 'admin' ? 1 : 0,
            'id_departamento' => Http::id(Http::campo('id_departamento')),
            'bloqueo'   => 0,
            'intentos'  => 0,
            'fecha_alta'=> Db::now(),
            'updated_at'=> Db::now(),
        ]);

        $guardarExtras($nuevo);

        if (Http::bool(Http::campo('superadmin')) && Hoteles::esSuperadmin()) {
            Db::run('UPDATE login SET superadmin = 1 WHERE id_login = ?', [$nuevo]);
        }

        Registro::anotar('alta_usuario', $nuevo, 'Alta de ' . $correo . ' con rol ' . $rol, true, $correo);
        Http::ok(['id_login' => $nuevo, 'mensaje' => 'Usuario creado.'], 201);
    }

    /* Nadie toca a alguien de otro hotel.
       Va antes de las ramas de escritura, no dentro de cada una: es la
       clase de comprobación que se olvida al añadir la siguiente. */
    if ($id !== null && $metodo !== 'GET' && Db::tablaExiste('hoteles')) {
        $suHotel = Db::value('SELECT id_hotel FROM login WHERE id_login = ?', [$id]);
        if ($suHotel !== null) {
            Hoteles::exigir((int)$suHotel);
        }
    }

    if ($id !== null && ($metodo === 'PATCH' || $metodo === 'PUT')) {
        $yo = Auth::exigir();
        $esAdmin = Auth::esAdmin($yo);
        if (!$esAdmin && $id !== (int)$yo['id_login']) {
            throw new ApiError('Sólo puedes editar tu propia ficha.', 403);
        }

        $datos = [];
        foreach (['nombre','apellido1','apellido2','telefono'] as $c) {
            if (array_key_exists($c, Http::body())) {
                $datos[$c] = mb_substr(trim((string)Http::campo($c)), 0, 80);
            }
        }
        if ($esAdmin) {
            if (array_key_exists('rol', Http::body())) {
                $rol = (string)Http::campo('rol');
                if (in_array($rol, ['admin','responsable','tecnico','reportador'], true)) {
                    $datos['rol']   = $rol;
                    $datos['admin'] = $rol === 'admin' ? 1 : 0;
                }
            }
            /* Si a esta persona se le manda copia a Teams. Viene
               encendido de fábrica; se apaga para quien no lo quiera, y
               quien no tenga correo queda fuera igualmente porque sin
               correo no se la puede encontrar en Azure. */
            if (array_key_exists('teams_avisar', Http::body())
                && in_array('teams_avisar', Db::columnas('login'), true)) {
                $datos['teams_avisar'] = Http::bool(Http::campo('teams_avisar')) ? 1 : 0;
            }
            if (array_key_exists('bloqueo', Http::body())) {
                $datos['bloqueo']  = Http::bool(Http::campo('bloqueo')) ? 1 : 0;
                $datos['intentos'] = 0;
            }
            if (array_key_exists('id_departamento', Http::body())) {
                $datos['id_departamento'] = Http::id(Http::campo('id_departamento'));
            }
            /* El correo.
             *
             * Faltaba: se podía cambiar en la pantalla, el servidor lo
             * ignoraba y respondía «No hay cambios que guardar». Quien
             * corregía una errata en el correo de alguien se quedaba sin
             * saber que no se había guardado —y esa persona seguía sin
             * poder entrar.
             *
             * Es con lo que se entra, así que se valida y se comprueba
             * que no lo tenga ya otra persona. */
            /* El nombre de usuario: es con lo que se entra, así que se
               valida igual que al darlo de alta. */
            if (array_key_exists('usuario', Http::body())) {
                $usuario = trim((string)Http::campo('usuario'));
                $plano   = Auth::normalizarUsuario($usuario);

                if (mb_strlen($plano) < 3) {
                    throw new ApiError('El nombre de usuario necesita al menos 3 caracteres válidos.',
                        422, ['campo' => 'usuario']);
                }
                $ocupado = Db::value(
                    'SELECT id_login FROM login WHERE usuario_plano = ? AND id_login <> ?',
                    [$plano, $id]);
                if ($ocupado !== null) {
                    throw new ApiError('Ya hay otra persona con ese nombre de usuario.',
                        409, ['campo' => 'usuario']);
                }
                $antes = (string)Db::value('SELECT usuario FROM login WHERE id_login = ?', [$id]);
                if ($usuario !== $antes) {
                    $datos['usuario']       = $usuario;
                    $datos['usuario_plano'] = $plano;
                    $datos['usuario_antes'] = $antes;   // sólo para el registro
                }
            }

            /* Los mensajes de esta persona. Al escribir uno nuevo para el
               próximo inicio se borra la marca de visto: si no, se guardaría
               el texto y no se le enseñaría nunca. */
            if (array_key_exists('mensaje_bienvenida', Http::body())) {
                $datos['mensaje_bienvenida'] = mb_substr(trim((string)Http::campo('mensaje_bienvenida')), 0, 500);
            }
            if (array_key_exists('bienvenida_activa', Http::body())) {
                $datos['bienvenida_activa'] = Http::bool(Http::campo('bienvenida_activa')) ? 1 : 0;
                if ($datos['bienvenida_activa'] === 1) { $datos['bienvenida_vista'] = null; }
            }
            if (array_key_exists('mensaje_proximo', Http::body())) {
                $datos['mensaje_proximo'] = mb_substr(trim((string)Http::campo('mensaje_proximo')), 0, 500);
                $datos['mensaje_proximo_visto'] = null;
            }
            if (array_key_exists('frases_activas', Http::body())) {
                $datos['frases_activas'] = Http::bool(Http::campo('frases_activas')) ? 1 : 0;
            }
            if (array_key_exists('frases_cada_inicio', Http::body())
                && in_array('frases_cada_inicio', Db::columnas('login'), true)) {
                $datos['frases_cada_inicio'] = Http::bool(Http::campo('frases_cada_inicio')) ? 1 : 0;
            }
            if (array_key_exists('frases_categorias', Http::body())) {
                $cats = Http::campo('frases_categorias');
                $cats = is_array($cats) ? $cats : (json_decode((string)$cats, true) ?: []);
                $datos['frases_categorias'] = json_encode(array_values(array_filter(array_map('intval', $cats))));
            }

            if (array_key_exists('correo', Http::body())) {
                $correo = mb_strtolower(trim((string)Http::campo('correo')));

                /* Vaciarlo es un cambio legítimo: el correo es opcional
                   desde que se entra con nombre de usuario. Lo único que
                   se comprueba es que, si hay algo, valga. */
                if ($correo === '') {
                    if (Permisos::ajuste('correo_obligatorio', false)) {
                        throw new ApiError('En este hotel el correo es obligatorio.',
                            422, ['campo' => 'correo']);
                    }
                    $antesC = Db::value('SELECT correo FROM login WHERE id_login = ?', [$id]);
                    if ($antesC !== null && $antesC !== '') {
                        $datos['correo'] = null;
                        $datos['correo_anterior'] = (string)$antesC;
                    }
                } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    throw new ApiError('Ese correo no tiene un formato válido.', 422, ['campo' => 'correo']);
                }
                $ocupado = Db::value(
                    'SELECT id_login FROM login WHERE LOWER(correo) = ? AND id_login <> ?',
                    [$correo, $id]);
                if ($ocupado !== null) {
                    throw new ApiError('Ya hay otra persona con ese correo.', 409, ['campo' => 'correo']);
                }
                $anterior = (string)Db::value('SELECT correo FROM login WHERE id_login = ?', [$id]);
                if ($correo !== mb_strtolower($anterior)) {
                    $datos['correo'] = $correo;
                    $datos['correo_anterior'] = $anterior;
                }
            }

            // El hotel al que pertenece, sólo para superadministradores.
            if (array_key_exists('id_hotel', Http::body())
                && Db::tablaExiste('hoteles') && Hoteles::esSuperadmin($yo)) {
                $datos['id_hotel'] = Http::id(Http::campo('id_hotel'));
            }

            if (Http::campo('password')) {
                $p = (string)Http::campo('password');
                if (mb_strlen($p) < 8) {
                    throw new ApiError('La contraseña debe tener al menos 8 caracteres.', 422, ['campo' => 'password']);
                }
                $datos['pwd_php'] = Auth::hashPassword($p);
                Db::run('UPDATE api_tokens SET revocado = 1 WHERE id_login = ?', [$id]);
            }
        }
        /* Los departamentos adicionales y los permisos se guardan antes
           de este corte: son cambios de verdad aunque no toquen ninguna
           columna de «login», y si no, editar sólo eso respondía «no hay
           cambios que guardar» sin guardar nada. */
        $extrasEnviados = array_key_exists('departamentos_extra', Http::body())
                       || array_key_exists('permisos', Http::body());
        if ($extrasEnviados) {
            $guardarExtras($id);
        }

        if ($datos === []) {
            if (!$extrasEnviados) {
                throw new ApiError('No hay cambios que guardar.', 422);
            }
            // Sólo cambiaron los departamentos adicionales o los permisos.
            Http::ok(['mensaje' => 'Usuario guardado.']);
        }
        // Se recuerda para el registro, pero no es una columna.
        $correoAnterior = $datos['correo_anterior'] ?? null;
        $usuarioAntes = $datos['usuario_antes'] ?? null;
        unset($datos['correo_anterior'], $datos['usuario_antes']);

        $datos['updated_at'] = Db::now();
        Db::update('login', $datos, 'id_login = :id', ['id' => $id]);
        $guardarExtras($id);

        if (isset($datos['rol'])) {
            Registro::anotar('rol', $id, 'Rol cambiado a ' . $datos['rol'] . ' por ' . ($yo['correo'] ?? ''));
        }
        if (isset($datos['usuario'])) {
            Registro::anotar('usuario', $id,
                'Nombre de usuario cambiado de «' . ($usuarioAntes ?? '?')
                . '» a «' . $datos['usuario'] . '» por ' . ($yo['correo'] ?? $yo['usuario'] ?? ''));
        }
        if (array_key_exists('correo', $datos)) {
            Registro::anotar('correo', $id,
                $datos['correo'] === null
                    ? 'Correo retirado (tenía ' . ($correoAnterior ?: '?') . ') por '
                      . ($yo['correo'] ?? $yo['usuario'] ?? '')
                    : 'Correo cambiado de ' . ($correoAnterior ?: '(ninguno)')
                      . ' a ' . $datos['correo'] . ' por ' . ($yo['correo'] ?? $yo['usuario'] ?? ''));
        }
        if (isset($datos['bloqueo'])) {
            Registro::anotar($datos['bloqueo'] ? 'bloqueo' : 'desbloqueo', $id,
                'Por ' . ($yo['correo'] ?? 'un administrador'));
        }
        if (isset($datos['pwd_php'])) {
            Registro::anotar('password', $id, 'Cambiada por ' . ($yo['correo'] ?? 'un administrador'));
        }
        Http::ok(['mensaje' => 'Ficha actualizada.']);
    }

    /* DELETE /api/usuarios/{id}?definitivo=1
       Borrado real de una persona.

       Lo que ha hecho no se borra: se desliga. Un parte sin autor es
       recuperable; un historial con huecos, no. */
    if ($id !== null && $metodo === 'DELETE') {
        $yo = Auth::exigirAdmin();

        if ($id === (int)$yo['id_login']) {
            throw new ApiError('No puedes borrarte a ti mismo.', 422);
        }
        $persona = Db::one('SELECT correo, superadmin FROM login WHERE id_login = ?', [$id]);
        if ($persona === null) {
            throw new ApiError('Esa persona no existe.', 404);
        }
        // Un superadministrador sólo lo borra otro superadministrador.
        if ((int)$persona['superadmin'] === 1 && !Hoteles::esSuperadmin($yo)) {
            throw new ApiError('Sólo un administrador puede borrar a otro.', 403);
        }

        if (!Http::bool(Http::query('definitivo'))) {
            // Sin la marca se entiende que se quiere quitar el acceso,
            // que es lo que se quiere casi siempre.
            Db::update('login', ['bloqueo' => 1, 'updated_at' => Db::now()],
                       'id_login = :id', ['id' => $id]);
            Registro::anotar('bloqueo', $id, 'Acceso retirado por ' . ($yo['correo'] ?? ''));
            Http::ok(['mensaje' => 'Se le ha quitado el acceso. Su nombre sigue en el historial.']);
        }

        $nPartes = (int)Db::value(
            'SELECT COUNT(*) FROM ticket_incidencias WHERE id_realiza_accion = ? OR id_persona_asignada = ?',
            [$id, $id]);

        Db::begin();
        try {
            /* Todo lo que apunta a la persona —qué se borra, qué se
               traspasa a quien la borra y qué se queda vacío— está en
               lib/Bajas.php, para que haya UNA lista. Aquí había una y
               en tools/equipo_benalma.php otra más corta, que fallaba
               con «FOREIGN KEY constraint failed» en una base usada. */
            Bajas::borrarDefinitivo($id, (int)$yo['id_login'],
                'borrado por ' . ($yo['correo'] ?? 'un administrador'));
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw new ApiError('No se ha podido borrar: ' . $e->getMessage(), 500);
        }

        Registro::anotar('borrado', null,
            'Persona eliminada: ' . $persona['correo'] . " (se desligaron $nPartes partes)",
            true, (string)$persona['correo']);

        Http::ok([
            'mensaje' => 'Persona eliminada y guardada en la papelera. Sus ' . $nPartes
                       . ' partes se conservan y pasan a tu nombre.',
        ]);
    }
}

/* ================================================================= */
/* ZONAS                                                             */
/* ================================================================= */
if ($seccion === 'zonas') {

    if ($metodo === 'GET') {
        Auth::exigir();
        Http::ok(['zonas' => Repo::arbolZonas(), 'niveles' => $config['app']['niveles_zona']]);
    }

    /* POST /api/zonas — alta de una ubicación completa */
    if ($metodo === 'POST') {
        $u = Auth::exigir();
        // El catálogo de ubicaciones es compartido. Quién puede tocarlo
        // lo decide el administrador: con mucha gente se llena de
        // duplicados —«101», «Hab 101», «101 baño»— y luego no hay quien
        // busque.
        $quien = (string)Permisos::ajuste('quien_crea_zona', 'responsable');
        $puede = match ($quien) {
            'nadie'       => false,
            'todos'       => true,
            'admin'       => Auth::esAdmin($u),
            'responsable' => Auth::esResponsable($u),
            default       => ($u['rol'] ?? '') !== 'reportador',
        };
        if (!$puede || !Permisos::ajuste('permitir_zona_nueva', true)) {
            throw new ApiError('No tienes permiso para crear ubicaciones nuevas.', 403);
        }

        // Se aceptan nombres o ids en cada nivel; los nombres se crean si no existen.
        $ids = [];
        for ($n = 1; $n <= 4; $n++) {
            $porId     = Http::id(Http::campo("zona$n"));
            $porNombre = trim((string)Http::campo("nombre$n", ''));
            if ($porId !== null) {
                $ids[$n] = $porId;
            } elseif ($porNombre !== '') {
                $ids[$n] = Repo::zonaPorNombre($n, $porNombre);
            } else {
                $ids[$n] = null;
            }
        }
        if ($ids[1] === null && $ids[2] === null && $ids[3] === null && $ids[4] === null) {
            throw new ApiError('Indica al menos un nivel de la ubicación.', 422);
        }

        $idRel = Repo::resolverZona($ids[1], $ids[2], $ids[3], $ids[4], (int)$u['id_login']);
        Http::ok([
            'id_rel' => $idRel,
            'ruta'   => Db::value('SELECT ruta FROM rel_zonas WHERE id_rel = ?', [$idRel]),
            'zonas'  => Repo::arbolZonas(),
        ], 201);
    }
}

throw new ApiError('Ruta de administración no encontrada.', 404);
