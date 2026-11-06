<?php
/** Rutas /api/auth/* */
declare(strict_types=1);

$accion = $partes[1] ?? '';

/* ---------------------------------------------------------------- */
/* POST /api/auth/login                                             */
/* ---------------------------------------------------------------- */
if ($accion === 'login' && $metodo === 'POST') {

    /* Se entra con el nombre de usuario O con el correo.
     *
     * No todo el mundo tiene correo: las cuentas de puesto —«recepcion»,
     * «pisos»— las usa quien esté de turno. El campo se sigue llamando
     * «correo» para no romper las aplicaciones que ya lo mandan así,
     * pero admite las dos cosas. */
    $quien = trim((string)(Http::campo('usuario') ?? Http::requerido('correo')));
    $pwd   = (string)Http::requerido('password');

    if ($quien === '') {
        throw new ApiError('Escribe tu usuario o tu correo.', 422, ['campo' => 'correo']);
    }

    // Freno por origen: sin esto se pueden probar contraseñas contra
    // muchas cuentas distintas sin llegar nunca al límite de ninguna.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $huella = hash('sha256', $ip . '|' . ($config['auth']['app_key'] ?? ''));
    $desde  = gmdate('Y-m-d H:i:s', time() - 900);      // ventana de 15 minutos
    /* El intento se apunta ANTES de mirar la contraseña, y luego se
       cuentan los que hay por delante. Antes se contaba primero y se
       apuntaba al fallar: doscientas peticiones lanzadas a la vez
       contaban todas «0» y pasaban todas el freno. Si sale bien se
       borra (más abajo se borran todos los de este origen). */
    $miIntento = Db::insert('intentos_acceso', [
        'huella' => $huella,
        'correo' => mb_substr(mb_strtolower($quien, 'UTF-8'), 0, 190),
        'fecha'  => Db::now(),
    ]);
    $fallos = (int)Db::value(
        'SELECT COUNT(*) FROM intentos_acceso WHERE huella = ? AND fecha > ? AND id < ?',
        [$huella, $desde, $miIntento]
    );
    if ($fallos >= 20) {
        // El rechazado no cuenta: así el freno se suelta a su hora y
        // no se alarga solo mientras alguien insiste.
        Db::run('DELETE FROM intentos_acceso WHERE id = ?', [$miIntento]);
        throw new ApiError('Demasiados intentos desde este equipo. Espera unos minutos.', 429);
    }

    /* Si lleva arroba se busca por correo; si no, por nombre de usuario.
       Se comprueban los dos de todas formas: alguien puede llamarse
       «a@b» de usuario, y al revés hay correos sin arroba que no son
       válidos pero se escriben igual. */
    $llano  = mb_strtolower($quien, 'UTF-8');
    $plano  = Auth::normalizarUsuario($quien);
    $tieneU = in_array('usuario_plano', Db::columnas('login'), true);

    $u = null;
    if ($tieneU && $plano !== '') {
        $u = Db::one('SELECT * FROM login WHERE usuario_plano = ?', [$plano]);
    }
    if ($u === null) {
        $u = Db::one('SELECT * FROM login WHERE LOWER(correo) = ?', [$llano]);
    }

    // Para el registro: lo que escribió, sea lo que sea.
    $correo = $llano;

    // Respuesta y tiempo homogéneos: no se revela si el correo existe.
    $maxIntentos = (int)$config['auth']['intentos_maximos'];
    $minutos     = (int)($config['auth']['bloqueo_minutos'] ?? 15);
    $tieneHasta  = in_array('bloqueo_hasta', Db::columnas('login'), true);

    /* El bloqueo automático se suelta solo cuando pasa su tiempo.
     *
     * Antes era para siempre y sólo lo quitaba un administrador. En un
     * hotel los nombres de usuario son adivinables —ana, luis, el
     * departamento, el nombre de pila—, así que cualquiera, desde la
     * wifi de clientes, dejaba al turno entero sin poder entrar
     * escribiendo mal ocho contraseñas por persona. Al administrador
     * también, que es justo quien tendría que desbloquear a los demás.
     *
     * El que pone un administrador a mano es otra cosa y no caduca: en
     * ése «bloqueo_hasta» se queda vacío. */
    if ($u !== null && $tieneHasta && (int)$u['bloqueo'] === 1
        && !empty($u['bloqueo_hasta']) && $u['bloqueo_hasta'] <= Db::now()) {
        Db::run('UPDATE login SET bloqueo = 0, intentos = 0, bloqueo_hasta = NULL '
              . 'WHERE id_login = ?', [(int)$u['id_login']]);
        Registro::anotar('bloqueo', (int)$u['id_login'],
            'Fin del bloqueo automático: vuelve a poder entrar', false, $correo);
        $u['bloqueo'] = 0;
        $u['intentos'] = 0;
    }

    /* Y si sigue bloqueada, se contesta EXACTAMENTE lo mismo que a una
     * contraseña mal puesta.
     *
     * Decía «Esta cuenta está bloqueada» con un 403, mientras que a un
     * usuario inventado le decía «Usuario o contraseña incorrectos» con
     * un 401. Con eso se averigua quién tiene cuenta en el hotel: se
     * prueban ocho contraseñas contra un nombre y la respuesta canta si
     * ese nombre existe. Ahora las dos respuestas son la misma, y quien
     * esté bloqueado de verdad sólo tiene que esperar: se suelta solo. */
    $bloqueada = $u !== null && ((int)$u['bloqueo'] === 1
                                 || (int)$u['intentos'] >= $maxIntentos);

    /* Y el intento de la cuenta, igual: se reserva antes de comprobar la
       contraseña, en una escritura que sólo sale bien si quedan. Antes se
       leía «intentos», se comprobaba y se sumaba al fallar: treinta
       contraseñas lanzadas a la vez contra una cuenta leían todas «0» y se
       comprobaban las treinta. Si acierta, más abajo vuelve a 0. */
    if ($u !== null && !$bloqueada) {
        $bloqueada = Db::run(
            'UPDATE login SET intentos = intentos + 1
              WHERE id_login = ? AND bloqueo = 0 AND intentos < ?',
            [(int)$u['id_login'], $maxIntentos]
        )->rowCount() !== 1;
    }

    if ($bloqueada || $u === null
        || !Auth::verificarPassword($pwd, (string)$u['pwd_php'])) {
        Registro::anotar('acceso_fallido', $u ? (int)$u['id_login'] : null,
            'Usuario o contraseña incorrectos', false, $correo);
        if ($u !== null && !$bloqueada) {
            $ahora = (int)Db::value('SELECT intentos FROM login WHERE id_login = ?', [(int)$u['id_login']]);
            if ($ahora >= $maxIntentos) {
                // Con la hora a la que deja de estar bloqueada. Con
                // «bloqueo_minutos» a 0 se queda sin hora: para siempre,
                // como antes, para quien lo prefiera así.
                $hasta = $minutos > 0
                    ? gmdate('Y-m-d H:i:s', time() + $minutos * 60) : null;
                if ($tieneHasta) {
                    Db::run('UPDATE login SET bloqueo = 1, bloqueo_hasta = ? WHERE id_login = ?',
                            [$hasta, (int)$u['id_login']]);
                } else {
                    Db::run('UPDATE login SET bloqueo = 1 WHERE id_login = ?', [(int)$u['id_login']]);
                }
                Registro::anotar('bloqueo', (int)$u['id_login'],
                    'Bloqueo automático tras ' . $maxIntentos . ' intentos fallidos'
                    . ($hasta !== null ? ', hasta las ' . substr($hasta, 11, 5) . ' (UTC)' : ''),
                    true, $correo);
            }
        }
        // Se anota el fallo para el freno por origen. Se reutiliza la
        // tabla de sesiones con un marcador, así no hace falta otra tabla.
        // (El intento ya quedó apuntado arriba, antes de comprobar.)
        // Limpieza oportunista: la tabla no crece sin control.
        if (random_int(1, 50) === 1) {
            Db::run('DELETE FROM intentos_acceso WHERE fecha < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
        usleep(random_int(150000, 350000));
        /* La pista va SIEMPRE, acierte quien acierte.
         *
         * Al hacer que una cuenta bloqueada conteste lo mismo que una
         * contraseña mal puesta —para que no se pueda averiguar quién
         * tiene cuenta— quien se equivoca ocho veces de verdad se queda
         * mirando «contraseña incorrecta» sin entender nada, aunque la
         * esté escribiendo bien. Esta frase lo explica y no delata a
         * nadie: es la misma para todo el mundo, exista la cuenta o no. */
        throw new ApiError(
            'Usuario o contraseña incorrectos. Si estás seguro de la '
          . 'contraseña, espera unos minutos: tras varios fallos la cuenta '
          . 'se bloquea un rato y luego se suelta sola.', 401);
    }

    Db::run('UPDATE login SET intentos = 0, ultimo_acceso = ?'
          . ($tieneHasta ? ', bloqueo_hasta = NULL' : '')
          . ' WHERE id_login = ?', [Db::now(), (int)$u['id_login']]);
    Registro::anotar('acceso', (int)$u['id_login'], '', true, $correo);
    // Entrada correcta: se limpia el freno de este origen.
    Db::run('DELETE FROM intentos_acceso WHERE huella = ?', [$huella]);

    $sesion = Auth::emitirToken(
        (int)$u['id_login'],
        (string)($_SERVER['HTTP_X_DISPOSITIVO'] ?? 'navegador'),
        (string)($_SERVER['HTTP_X_PLATAFORMA'] ?? 'web')
    );

    unset($u['pwd_php'], $u['pwd_sha2']);
    $u['id_login'] = (int)$u['id_login'];
    $u['admin']    = (int)$u['admin'];
    $u['es_admin'] = Auth::esAdmin($u);
    $u['es_superadmin'] = Hoteles::esSuperadmin($u);
    /* «De la cadena» es otra cosa que «administrador»: lo primero lo
       decide la marca superadmin, y es lo que abre las pantallas que
       afectan a TODOS los hoteles. Sin esto, un administrador veía filas
       que luego el servidor le rechazaba. */
    $u['es_cadena'] = (int)($u['superadmin'] ?? 0) === 1;
    $u['id_hotel'] = $u['id_hotel'] === null ? null : (int)$u['id_hotel'];
    $u['hotel'] = $u['id_hotel'] ? Hoteles::uno((int)$u['id_hotel']) : null;
    $u['es_responsable'] = Auth::esResponsable($u);

    Http::ok(['token' => $sesion['token'], 'expira' => $sesion['expira'], 'usuario' => $u]);
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/logout                                            */
/* ---------------------------------------------------------------- */
if ($accion === 'logout' && $metodo === 'POST') {
    $yo = Auth::usuario();
    $t = Auth::tokenDeCabecera();
    if ($t !== null) {
        Auth::revocarToken($t);
    }
    Registro::anotar('salida', $yo ? (int)$yo['id_login'] : null);
    Http::ok(['mensaje' => 'Sesión cerrada.']);
}

/* ---------------------------------------------------------------- */
/* GET /api/auth/yo                                                 */
/* ---------------------------------------------------------------- */
if ($accion === 'yo' && $metodo === 'GET') {
    $u = Auth::exigir();
    $u['id_login'] = (int)$u['id_login'];
    $u['admin']    = (int)$u['admin'];
    $u['es_admin'] = Auth::esAdmin($u);
    $u['es_superadmin'] = Hoteles::esSuperadmin($u);
    /* «De la cadena» es otra cosa que «administrador»: lo primero lo
       decide la marca superadmin, y es lo que abre las pantallas que
       afectan a TODOS los hoteles. Sin esto, un administrador veía filas
       que luego el servidor le rechazaba. */
    $u['es_cadena'] = (int)($u['superadmin'] ?? 0) === 1;
    $u['id_hotel'] = $u['id_hotel'] === null ? null : (int)$u['id_hotel'];
    $u['hotel'] = $u['id_hotel'] ? Hoteles::uno((int)$u['id_hotel']) : null;
    $u['es_responsable'] = Auth::esResponsable($u);
    unset($u['id_token']);
    Http::ok(['usuario' => $u]);
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/password                                          */
/* ---------------------------------------------------------------- */
if ($accion === 'password' && $metodo === 'POST') {
    $u = Auth::exigir();

    $actual = (string)Http::requerido('actual');
    $nueva  = (string)Http::requerido('nueva');

    if (mb_strlen($nueva) < 8) {
        throw new ApiError('La nueva contraseña debe tener al menos 8 caracteres.', 422, ['campo' => 'nueva']);
    }

    $hash = (string)Db::value('SELECT pwd_php FROM login WHERE id_login = ?', [(int)$u['id_login']]);
    if (!Auth::verificarPassword($actual, $hash)) {
        throw new ApiError('La contraseña actual no es correcta.', 401, ['campo' => 'actual']);
    }

    Db::update('login', [
        'pwd_php'    => Auth::hashPassword($nueva),
        'pwd_sha2'   => null,
        'updated_at' => Db::now(),
    ], 'id_login = :id', ['id' => (int)$u['id_login']]);

    // Se cierran las demás sesiones por seguridad.
    $token = Auth::tokenDeCabecera();
    Db::run(
        'UPDATE api_tokens SET revocado = 1 WHERE id_login = ? AND token_hash <> ?',
        [(int)$u['id_login'], hash('sha256', (string)$token)]
    );

    Registro::anotar('password', (int)$u['id_login'], 'Cambio hecho por la propia persona');
    Http::ok(['mensaje' => 'Contraseña actualizada.']);
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/correo — cambiar el propio correo                 */
/* ---------------------------------------------------------------- */
if ($accion === 'correo' && $metodo === 'POST') {
    $u = Auth::exigir();

    if (!Permisos::ajuste('cambio_correo', true)) {
        throw new ApiError('El cambio de correo está desactivado. Pídeselo a un administrador.', 403);
    }

    $nuevo = mb_strtolower(trim((string)Http::requerido('correo')));
    $pwd   = (string)Http::requerido('password');

    if (!filter_var($nuevo, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('El correo no tiene un formato válido.', 422, ['campo' => 'correo']);
    }

    // Se pide la contraseña: el correo es con lo que se entra, así que
    // cambiarlo sin confirmar la identidad sería regalar la cuenta a
    // quien pille el móvil desbloqueado.
    $hash = (string)Db::value('SELECT pwd_php FROM login WHERE id_login = ?', [(int)$u['id_login']]);
    if (!Auth::verificarPassword($pwd, $hash)) {
        Registro::anotar('correo', (int)$u['id_login'], 'Contraseña incorrecta al cambiar el correo', false);
        throw new ApiError('La contraseña no es correcta.', 401, ['campo' => 'password']);
    }

    $anterior = mb_strtolower((string)$u['correo']);
    if ($nuevo === $anterior) {
        throw new ApiError('Ese ya es tu correo.', 422, ['campo' => 'correo']);
    }
    if (Db::value('SELECT 1 FROM login WHERE LOWER(correo) = ? AND id_login <> ?', [$nuevo, (int)$u['id_login']])) {
        throw new ApiError('Ya hay otra cuenta con ese correo.', 409, ['campo' => 'correo']);
    }

    Db::update('login', ['correo' => $nuevo, 'updated_at' => Db::now()],
               'id_login = :id', ['id' => (int)$u['id_login']]);
    Registro::anotar('correo', (int)$u['id_login'], $anterior . ' → ' . $nuevo, true, $nuevo);

    Http::ok(['mensaje' => 'Correo actualizado.', 'correo' => $nuevo]);
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/recuperar — pedir el restablecimiento             */
/* ---------------------------------------------------------------- */
if ($accion === 'recuperar' && $metodo === 'POST') {
    if (!Permisos::ajuste('recuperacion_activa', true)) {
        throw new ApiError('El restablecimiento está desactivado. Contacta con un administrador.', 403);
    }

    $correo = mb_strtolower(trim((string)Http::requerido('correo')));
    $u = Db::one('SELECT id_login, correo FROM login WHERE LOWER(correo) = ?', [$correo]);

    // La respuesta es la misma exista o no la cuenta: si no, esto sería
    // una forma cómoda de averiguar qué correos están dados de alta.
    if ($u !== null) {
        // Una petición viva a la vez por persona.
        Db::run('UPDATE recuperaciones SET usado = 1 WHERE id_login = ? AND usado = 0', [(int)$u['id_login']]);
        Db::insert('recuperaciones', [
            'id_login'    => (int)$u['id_login'],
            'codigo_hash' => '',                       // lo pone el administrador
            'creado'      => Db::now(),
            'expira'      => gmdate('Y-m-d H:i:s', time() + 3600),
            'usado'       => 0,
            'ip_solicita' => Registro::ip(),
            'intentos'    => 0,
        ]);
        Registro::anotar('recuperacion', (int)$u['id_login'], 'Solicitud de restablecimiento', true, $correo);
    } else {
        Registro::anotar('recuperacion', null, 'Solicitud para un correo desconocido', false, $correo);
    }

    usleep(random_int(200000, 400000));
    Http::ok([
        'mensaje' => 'Si esa cuenta existe, un administrador podrá darte un código para entrar. '
                   . 'Avísale: verá tu petición en el panel.',
    ]);
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/codigo — entrar con el código de un administrador  */
/* ---------------------------------------------------------------- */
if ($accion === 'codigo' && $metodo === 'POST') {
    /* Lo que escribe la persona: su correo o su nombre de usuario. El
       campo se llama «correo» porque así lo manda la pantalla de entrada.
     *
     * Aquí se leía en $correo y más abajo se buscaba con $quien, que no
     * existía: «Undefined variable $quien», mb_strtolower(null) y error
     * 500. Restablecer la contraseña con el código que da el administrador
     * —Administración → Contraseñas olvidadas— no funcionaba nunca, para
     * nadie. Lo encontró pruebas/recuperar.py haciéndolo de punta a punta;
     * ninguna prueba anterior llegaba a usar el código. */
    $quien  = trim((string)Http::requerido('correo'));
    $correo = mb_strtolower($quien);
    $codigo = trim((string)Http::requerido('codigo'));
    $nueva  = (string)Http::requerido('nueva');

    if (mb_strlen($nueva) < 8) {
        throw new ApiError('La contraseña nueva debe tener al menos 8 caracteres.', 422, ['campo' => 'nueva']);
    }

    /* Si lleva arroba se busca por correo; si no, por nombre de usuario.
       Se comprueban los dos de todas formas: alguien puede llamarse
       «a@b» de usuario, y al revés hay correos sin arroba que no son
       válidos pero se escriben igual. */
    $llano  = mb_strtolower($quien, 'UTF-8');
    $plano  = Auth::normalizarUsuario($quien);
    $tieneU = in_array('usuario_plano', Db::columnas('login'), true);

    $u = null;
    if ($tieneU && $plano !== '') {
        $u = Db::one('SELECT * FROM login WHERE usuario_plano = ?', [$plano]);
    }
    if ($u === null) {
        $u = Db::one('SELECT * FROM login WHERE LOWER(correo) = ?', [$llano]);
    }

    // Para el registro: lo que escribió, sea lo que sea.
    $correo = $llano;
    if ($u === null) {
        usleep(random_int(200000, 400000));
        throw new ApiError('El código no es válido o ha caducado.', 401);
    }

    $r = Db::one(
        "SELECT * FROM recuperaciones
          WHERE id_login = ? AND usado = 0 AND codigo_hash <> '' AND expira > ?
          ORDER BY id_recuperacion DESC LIMIT 1",
        [(int)$u['id_login'], Db::now()]
    );

    /* El intento se RESERVA antes de comparar, en una sola escritura que
       sólo sale bien si quedan intentos. Antes se leía «intentos», se
       comparaba y después se sumaba: treinta peticiones lanzadas a la vez
       leían todas «0» y se comparaban las treinta, con lo que el límite
       de cinco no limitaba nada. */
    $reservado = $r !== null && Db::run(
        'UPDATE recuperaciones SET intentos = intentos + 1
          WHERE id_recuperacion = ? AND intentos < 5 AND usado = 0',
        [(int)$r['id_recuperacion']]
    )->rowCount() === 1;

    if (!$reservado
        || !hash_equals((string)$r['codigo_hash'], hash('sha256', $codigo))) {
        Registro::anotar('recuperacion', (int)$u['id_login'], 'Código incorrecto', false, $correo);
        usleep(random_int(200000, 400000));
        throw new ApiError('El código no es válido o ha caducado.', 401, ['campo' => 'codigo']);
    }

    /* Un solo uso, también si llegan dos a la vez con el código bueno:
       sólo sigue quien consigue marcarlo como usado. */
    $gastado = Db::run('UPDATE recuperaciones SET usado = 1 WHERE id_recuperacion = ? AND usado = 0',
                       [(int)$r['id_recuperacion']])->rowCount() === 1;
    if (!$gastado) {
        usleep(random_int(200000, 400000));
        throw new ApiError('El código no es válido o ha caducado.', 401, ['campo' => 'codigo']);
    }

    Db::update('login', [
        'pwd_php'    => Auth::hashPassword($nueva),
        'pwd_sha2'   => null,
        'bloqueo'    => 0,
        'intentos'   => 0,
        'updated_at' => Db::now(),
    ], 'id_login = :id', ['id' => (int)$u['id_login']]);

    // Todas las sesiones anteriores caen: si alguien había entrado con la
    // contraseña vieja, deja de tener acceso.
    Db::run('UPDATE api_tokens SET revocado = 1 WHERE id_login = ?', [(int)$u['id_login']]);

    Registro::anotar('password', (int)$u['id_login'], 'Restablecida con código de administrador', true, $correo);
    Http::ok(['mensaje' => 'Contraseña restablecida. Ya puedes entrar.']);
}

/* ---------------------------------------------------------------- */
/* GET /api/auth/sesiones  ·  DELETE /api/auth/sesiones             */
/* ---------------------------------------------------------------- */
if ($accion === 'sesiones') {
    $u = Auth::exigir();
    if ($metodo === 'GET') {
        $filas = Db::all(
            'SELECT id_token, dispositivo, plataforma, creado, ultimo_uso, expira, revocado
               FROM api_tokens WHERE id_login = ? ORDER BY ultimo_uso DESC',
            [(int)$u['id_login']]
        );
        Http::ok(['sesiones' => $filas]);
    }
    if ($metodo === 'DELETE') {
        $token = Auth::tokenDeCabecera();
        $propio = hash('sha256', (string)$token);

        /* Cerrar UNA sesión concreta.
         *
         * Es lo que hace falta cuando se pierde un móvil: se quita el
         * acceso de ese aparato y se sigue trabajando en el que se tiene
         * en la mano. Antes sólo existía «cerrar todas las demás», que
         * obliga a volver a entrar en todos los sitios por perder uno.
         *
         * Sólo las propias: el id se comprueba contra quien pregunta. */
        $idSesion = Http::id($partes[2] ?? null);
        if ($idSesion !== null) {
            $suya = Db::one(
                'SELECT id_token, token_hash FROM api_tokens WHERE id_token = ? AND id_login = ?',
                [$idSesion, (int)$u['id_login']]);
            if ($suya === null) {
                throw new ApiError('Esa sesión no es tuya o ya no existe.', 404);
            }
            Db::run('UPDATE api_tokens SET revocado = 1 WHERE id_token = ?', [$idSesion]);

            Registro::anotar('sesion', (int)$u['id_login'],
                'Sesión cerrada a distancia (#' . $idSesion . ')');

            Http::ok([
                'mensaje' => $suya['token_hash'] === $propio
                    ? 'Has cerrado la sesión de este aparato.'
                    : 'Sesión cerrada. Ese aparato tendrá que volver a entrar.',
            ]);
        }

        Db::run('UPDATE api_tokens SET revocado = 1 WHERE id_login = ? AND token_hash <> ?',
            [(int)$u['id_login'], $propio]);
        Http::ok(['mensaje' => 'Se han cerrado el resto de sesiones.']);
    }
}

/* ---------------------------------------------------------------- */
/* POST /api/auth/enlace  ·  entrar con un enlace de auto-entrada    */
/* ---------------------------------------------------------------- */
if ($accion === 'enlace' && $metodo === 'POST') {

    /* El freno por origen también vale aquí: si no, se podrían probar
       llaves a ciegas todo lo rápido que aguante el servidor. */
    $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    /* Contador propio, no el de la entrada con contraseña. Compartido,
       veinte intentos con un enlace caducado —una tablet con un marcador
       viejo— dejaban a ese aparato sin poder entrar tampoco con usuario y
       contraseña durante un cuarto de hora. Cada puerta, su freno. */
    $huella = hash('sha256', 'enlace|' . $ip . '|' . ($config['auth']['app_key'] ?? ''));
    $desde  = gmdate('Y-m-d H:i:s', time() - 900);
    $fallos = (int)Db::value(
        'SELECT COUNT(*) FROM intentos_acceso WHERE huella = ? AND fecha > ?',
        [$huella, $desde]);
    if ($fallos >= 20) {
        throw new ApiError('Demasiados intentos desde este equipo. Espera unos minutos.', 429);
    }

    $id    = (string)Http::campo('u', '');
    $clave = (string)Http::campo('k', '');

    try {
        $u = AutoEntrada::comprobar($id, $clave);
    } catch (ApiError $e) {
        Db::insert('intentos_acceso', ['huella' => $huella, 'fecha' => Db::now()]);
        throw $e;
    }

    $sesion = Auth::emitirToken(
        (int)$u['id_login'],
        (string)($_SERVER['HTTP_X_DISPOSITIVO'] ?? 'enlace'),
        (string)($_SERVER['HTTP_X_PLATAFORMA'] ?? 'web')
    );

    // Lo mismo que devuelve la entrada normal, sin las contraseñas.
    unset($u['pwd_php'], $u['pwd_sha2'], $u['autologin_hash']);
    $u['id_login'] = (int)$u['id_login'];
    $u['admin']    = (int)$u['admin'];
    $u['es_admin'] = Auth::esAdmin($u);
    $u['es_superadmin'] = Hoteles::esSuperadmin($u);
    /* «De la cadena» es otra cosa que «administrador»: lo primero lo
       decide la marca superadmin, y es lo que abre las pantallas que
       afectan a TODOS los hoteles. Sin esto, un administrador veía filas
       que luego el servidor le rechazaba. */
    $u['es_cadena'] = (int)($u['superadmin'] ?? 0) === 1;
    $u['id_hotel'] = $u['id_hotel'] === null ? null : (int)$u['id_hotel'];
    $u['hotel'] = $u['id_hotel'] ? Hoteles::uno((int)$u['id_hotel']) : null;
    $u['es_responsable'] = Auth::esResponsable($u);
    $u['entro_por_enlace'] = true;

    Http::ok(['token' => $sesion['token'], 'expira' => $sesion['expira'], 'usuario' => $u]);
}

throw new ApiError('Ruta de autenticación no encontrada.', 404);
