<?php

/**
 * ¿Está todo en su sitio? El panel de integridad.
 *
 * Una lista de comprobaciones, cada una con su estado —bien, aviso,
 * falla o «no se ha podido comprobar»— y qué hacer si algo sale mal.
 * La usan Administración → Integridad y php tools/diagnostico.php, así que
 * los dos dicen lo mismo.
 *
 * SÓLO LEE. Abrir el panel no arregla nada a escondidas: si algo está
 * mal, se dice qué es y cómo se arregla, y lo decide quien administra.
 *
 * Por qué hace falta, si las pruebas pasan: las pruebas miran el código
 * en una base de pruebas. Esto mira TUS datos y TU servidor: una
 * migración que dejó partes sin hotel, una foto que alguien borró del
 * disco, una copia de seguridad que dejó de hacerse en marzo, el candado
 * de una carpeta que el servidor no lee. Nada de eso lo ve una prueba.
 */
declare(strict_types=1);

final class Integridad
{
    private const BIEN = 'bien';
    private const AVISO = 'aviso';
    private const FALLA = 'falla';
    private const ND = 'nd';

    /** @var list<array<string,mixed>> */
    private static array $r = [];

    /**
     * Todas las comprobaciones.
     *
     * @param array $config la configuración ya cargada
     * @param ?string $base dirección pública de la aplicación (para mirar
     *        por HTTP qué sirve el servidor); null = no se mira
     * @return array{comprobaciones: list<array>, resumen: array, fecha: string}
     */
    public static function comprobar(array $config, ?string $base = null): array
    {
        self::$r = [];
        $grupos = [
            'Base de datos' => static fn() => self::baseDeDatos($config),
            'Partes'        => static fn() => self::partes(),
            'Personas'      => static fn() => self::personas(),
            'Ubicaciones'   => static fn() => self::ubicaciones(),
            'Ficheros'      => static fn() => self::ficheros($config),
            'Seguridad'     => static fn() => self::seguridad($config, $base),
            'Sistema'       => static fn() => self::sistema($config),
        ];
        foreach ($grupos as $grupo => $hacer) {
            try {
                $hacer();
            } catch (Throwable $e) {
                /* Una comprobación que revienta no tapa las demás: se dice
                   cuál y por qué, y se sigue. */
                self::anotar($grupo, 'error_' . md5($grupo), self::FALLA,
                    "No se ha podido terminar de comprobar «{$grupo}»",
                    $e->getMessage());
            }
        }
        $resumen = ['bien' => 0, 'aviso' => 0, 'falla' => 0, 'nd' => 0];
        foreach (self::$r as $c) { $resumen[$c['estado']]++; }
        return ['comprobaciones' => self::$r, 'resumen' => $resumen, 'fecha' => Db::now()];
    }

    private static function anotar(string $grupo, string $id, string $estado, string $titulo,
                                   string $detalle = '', ?int $n = null): void
    {
        self::$r[] = ['grupo' => $grupo, 'id' => $id, 'estado' => $estado,
                      'titulo' => $titulo, 'detalle' => $detalle, 'n' => $n];
    }

    /** Cuántas filas cumplen esto. Una consulta que no se puede hacer es null. */
    private static function cuantos(string $sql, array $p = []): ?int
    {
        try {
            return (int)Db::value($sql, $p);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Bien si es cero; si no, aviso o falla según la gravedad. */
    private static function cero(string $grupo, string $id, ?int $n, string $bien, string $mal,
                                 string $comoArreglar, string $siHay = self::AVISO): void
    {
        if ($n === null) {
            self::anotar($grupo, $id, self::ND, $bien, 'No se ha podido consultar.');
        } elseif ($n === 0) {
            self::anotar($grupo, $id, self::BIEN, $bien, '', 0);
        } else {
            self::anotar($grupo, $id, $siHay, str_replace('{n}', (string)$n, $mal), $comoArreglar, $n);
        }
    }

    private static function hay(string $tabla): bool
    {
        try { return Db::tablaExiste($tabla); } catch (Throwable $e) { return false; }
    }

    // -----------------------------------------------------------------
    private static function baseDeDatos(array $config): void
    {
        $g = 'Base de datos';
        $imprescindibles = ['login', 'ticket_incidencias', 'ticket_incidencias_historial', 'estados',
                            'prioridades', 'departamentos', 'elemento', 'rel_zonas', 'zona_1',
                            'notificaciones', 'ajustes_sistema', 'hoteles', 'adjuntos',
                            'chat_mensajes', 'api_tokens'];
        $faltan = array_values(array_filter($imprescindibles, static fn($t) => !self::hay($t)));
        if ($faltan) {
            self::anotar($g, 'tablas', self::FALLA, 'Faltan tablas: ' . implode(', ', $faltan),
                'Pasa el instalador en modo actualizar: php tools/instalar.php --actualizar');
        } else {
            self::anotar($g, 'tablas', self::BIEN, 'Están todas las tablas imprescindibles ('
                . count($imprescindibles) . ')');
        }

        $motor = Db::driver();
        if ($motor === 'sqlite') {
            try {
                $r = (string)Db::value('PRAGMA quick_check');
                if ($r === 'ok') {
                    self::anotar($g, 'fisica', self::BIEN, 'El fichero de la base está sano (SQLite)');
                } else {
                    self::anotar($g, 'fisica', self::FALLA, 'El fichero de la base tiene daños',
                        'SQLite dice: ' . mb_substr($r, 0, 200) . '. Restaura la última copia de '
                        . 'seguridad en otra carpeta y compara antes de nada.');
                }
            } catch (Throwable $e) {
                self::anotar($g, 'fisica', self::ND, 'No se ha podido revisar el fichero de la base', $e->getMessage());
            }
        } else {
            try {
                $malas = [];
                foreach (['login', 'ticket_incidencias', 'ticket_incidencias_historial', 'rel_zonas'] as $t) {
                    foreach (Db::all("CHECK TABLE `$t` QUICK") as $f) {
                        $tipo = strtolower((string)($f['Msg_type'] ?? ''));
                        $txt  = strtolower((string)($f['Msg_text'] ?? ''));
                        if ($tipo === 'error' || ($tipo === 'status' && $txt !== 'ok')) {
                            $malas[] = $t;
                        }
                    }
                }
                $malas
                    ? self::anotar($g, 'fisica', self::FALLA, 'MySQL ve daños en: ' . implode(', ', array_unique($malas)),
                        'Haz una copia y repara con: mysqlcheck --repair')
                    : self::anotar($g, 'fisica', self::BIEN, 'MySQL no ve daños en las tablas principales');
            } catch (Throwable $e) {
                self::anotar($g, 'fisica', self::ND, 'No se ha podido revisar las tablas', $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------
    private static function partes(): void
    {
        $g = 'Partes';
        $vivos = 'FROM ticket_incidencias t WHERE t.borrado = 0';

        self::cero($g, 'sin_hotel',
            self::cuantos("SELECT COUNT(*) $vivos AND t.id_hotel IS NULL"),
            'Todos los partes tienen hotel', '{n} partes sin hotel: no los ve nadie que no sea administrador',
            'php tools/diagnostico.php los reparte al hotel principal.', self::FALLA);

        self::cero($g, 'estado',
            self::cuantos("SELECT COUNT(*) $vivos AND NOT EXISTS
                            (SELECT 1 FROM estados e WHERE e.id_estado = t.id_estado)"),
            'Todos los partes tienen un estado que existe', '{n} partes con un estado que ya no existe',
            'Salen sin color ni filtro. Cámbiales el estado desde la lista, marcándolos.', self::FALLA);

        self::cero($g, 'departamento',
            self::cuantos("SELECT COUNT(*) $vivos AND t.id_grupo_asignado IS NOT NULL AND NOT EXISTS
                            (SELECT 1 FROM departamentos d WHERE d.id_departamento = t.id_grupo_asignado)"),
            'Todos los partes van a un departamento que existe', '{n} partes van a un departamento que ya no existe',
            'No están en la cola de nadie. Pásalos a otro departamento desde la lista.', self::FALLA);

        self::cero($g, 'persona',
            self::cuantos("SELECT COUNT(*) $vivos AND t.id_persona_asignada IS NOT NULL AND NOT EXISTS
                            (SELECT 1 FROM login l WHERE l.id_login = t.id_persona_asignada)"),
            'Ningún parte está asignado a alguien que no existe', '{n} partes asignados a alguien que ya no existe',
            'Reasígnalos: nadie los está viendo como suyos.', self::FALLA);

        self::cero($g, 'persona_baja',
            self::cuantos("SELECT COUNT(*) $vivos AND t.fecha_cierre IS NULL AND EXISTS
                            (SELECT 1 FROM login l WHERE l.id_login = t.id_persona_asignada AND l.bloqueo = 1)
                           AND NOT EXISTS (SELECT 1 FROM estados e WHERE e.id_estado = t.id_estado AND e.es_final = 1)"),
            'Ningún parte abierto espera a alguien bloqueado', '{n} partes abiertos asignados a alguien bloqueado',
            'Esa persona no puede entrar a trabajarlos. Reasígnalos.');

        self::cero($g, 'tipo',
            self::cuantos("SELECT COUNT(*) $vivos AND t.id_elemento IS NOT NULL AND NOT EXISTS
                            (SELECT 1 FROM elemento e WHERE e.id_elemento = t.id_elemento)"),
            'Todos los partes tienen un tipo de avería que existe', '{n} partes con un tipo de avería que ya no existe',
            'En los informes salen como «sin tipo».');

        self::cero($g, 'ubicacion',
            self::cuantos("SELECT COUNT(*) $vivos AND t.id_rel_zona IS NOT NULL AND NOT EXISTS
                            (SELECT 1 FROM rel_zonas r WHERE r.id_rel = t.id_rel_zona)"),
            'Todos los partes están en una ubicación que existe', '{n} partes en una ubicación que ya no existe',
            'Salen sin «dónde». Edítalos y elige la ubicación.');

        self::cero($g, 'historial',
            self::cuantos('SELECT COUNT(*) FROM ticket_incidencias_historial h WHERE NOT EXISTS
                            (SELECT 1 FROM ticket_incidencias t WHERE t.id_ticket = h.id_ticket)'),
            'Todo el historial es de partes que existen', '{n} líneas de historial de partes que ya no existen',
            'Ocupan sitio y no se ven. php tools/instalar.php --actualizar las quita (la papelera conserva su copia).');

        self::cero($g, 'fotos_sueltas',
            self::cuantos('SELECT COUNT(*) FROM adjuntos a WHERE NOT EXISTS
                            (SELECT 1 FROM ticket_incidencias t WHERE t.id_ticket = a.id_ticket)'),
            'Todas las fotos son de partes que existen', '{n} fotos de partes que ya no existen',
            'php tools/instalar.php --actualizar las quita de la base (la papelera conserva su copia).');

        if (self::hay('chat_mensajes')) {
            self::cero($g, 'chat',
                self::cuantos('SELECT COUNT(*) FROM chat_mensajes m WHERE NOT EXISTS
                                (SELECT 1 FROM ticket_incidencias t WHERE t.id_ticket = m.id_ticket)'),
                'Todas las conversaciones son de partes que existen', '{n} mensajes de partes que ya no existen',
                'No se ven en ninguna parte.');
        }
        if (self::hay('chat_lecturas')) {
            self::cero($g, 'lecturas',
                self::cuantos('SELECT COUNT(*) FROM chat_lecturas l WHERE NOT EXISTS
                                (SELECT 1 FROM chat_mensajes m WHERE m.id_mensaje = l.id_mensaje)'),
                'Todas las marcas de «leído» son de mensajes que existen', '{n} marcas de «leído» sueltas',
                'No afectan a nada visible.');
        }
        self::cero($g, 'avisos',
            self::cuantos('SELECT COUNT(*) FROM notificaciones n WHERE n.id_ticket IS NOT NULL AND NOT EXISTS
                            (SELECT 1 FROM ticket_incidencias t WHERE t.id_ticket = n.id_ticket)'),
            'Ningún aviso apunta a un parte que no existe', '{n} avisos de partes que ya no existen',
            'Al tocarlos no llevan a ninguna parte. Cada persona puede borrarlos desde Avisos.');
    }

    // -----------------------------------------------------------------
    private static function personas(): void
    {
        $g = 'Personas';
        $admins = self::cuantos('SELECT COUNT(*) FROM login WHERE admin = 1 AND bloqueo = 0');
        if ($admins === 0) {
            self::anotar($g, 'admins', self::FALLA, 'No hay ningún administrador que pueda entrar',
                'Nadie puede cambiar la configuración ni dar de alta a nadie. Desde la consola: '
                . 'php tools/instalar.php --actualizar crea uno si no hay ninguno.', 0);
        } elseif ($admins !== null) {
            self::anotar($g, 'admins', self::BIEN, "$admins administrador(es) que pueden entrar", '', $admins);
        }

        // La contraseña del manual, en un administrador: es la puerta abierta.
        $clave = @include __DIR__ . '/../../tools/clave_demo.php';
        if (is_string($clave) && $clave !== '') {
            $conDemo = [];
            foreach (Db::all('SELECT usuario, correo, pwd_php FROM login WHERE admin = 1 AND bloqueo = 0') as $a) {
                if (!empty($a['pwd_php']) && password_verify($clave, (string)$a['pwd_php'])) {
                    $conDemo[] = (string)($a['usuario'] ?: $a['correo']);
                }
            }
            $conDemo
                ? self::anotar($g, 'clave_demo', self::FALLA,
                    'Hay administradores con la contraseña de demostración: ' . implode(', ', $conDemo),
                    'Esa contraseña va escrita en el manual: quien lo lea entra como administrador. '
                    . 'Cámbiala desde Mi cuenta o bloquea la cuenta desde Equipo.', count($conDemo))
                : self::anotar($g, 'clave_demo', self::BIEN, 'Ningún administrador entra con la contraseña de demostración');
        }

        self::cero($g, 'sin_hotel',
            self::cuantos('SELECT COUNT(*) FROM login WHERE bloqueo = 0 AND id_hotel IS NULL'),
            'Todo el mundo tiene hotel', '{n} personas sin hotel',
            'No ven partes de ningún sitio. Ponles hotel desde Equipo.', self::FALLA);

        self::cero($g, 'sin_departamento',
            self::cuantos('SELECT COUNT(*) FROM login l WHERE l.bloqueo = 0 AND (l.id_departamento IS NULL
                            OR NOT EXISTS (SELECT 1 FROM departamentos d WHERE d.id_departamento = l.id_departamento))'),
            'Todo el mundo tiene un departamento que existe', '{n} personas sin departamento (o con uno que ya no existe)',
            'No les llega trabajo de ninguna cola. Ponles departamento desde Equipo.');

        self::cero($g, 'sin_usuario',
            self::cuantos("SELECT COUNT(*) FROM login WHERE bloqueo = 0 AND (usuario IS NULL OR usuario = '')"),
            'Todo el mundo tiene nombre de usuario', '{n} personas sin nombre de usuario',
            'Sólo pueden entrar con el correo. php tools/instalar.php --actualizar se lo pone.');

        if (self::hay('api_tokens')) {
            self::cero($g, 'sesiones',
                self::cuantos('SELECT COUNT(*) FROM api_tokens s WHERE NOT EXISTS
                                (SELECT 1 FROM login l WHERE l.id_login = s.id_login)'),
                'Todas las sesiones abiertas son de personas que existen', '{n} sesiones de personas que ya no existen',
                'No sirven para entrar (la persona no existe), pero no deberían estar.');
        }
    }

    // -----------------------------------------------------------------
    private static function ubicaciones(): void
    {
        $g = 'Ubicaciones';
        self::cero($g, 'sin_hotel',
            self::cuantos('SELECT COUNT(*) FROM rel_zonas WHERE id_hotel IS NULL'),
            'Todas las ubicaciones tienen hotel', '{n} ubicaciones sin hotel: el buscador no las encuentra',
            'php tools/instalar.php --actualizar les pone el hotel de sus niveles.', self::FALLA);
        self::cero($g, 'sin_ruta',
            self::cuantos("SELECT COUNT(*) FROM rel_zonas WHERE ruta IS NULL OR ruta = ''"),
            'Todas las ubicaciones tienen su nombre completo', '{n} ubicaciones sin nombre completo',
            'Salen en blanco en los partes. php tools/instalar.php --actualizar lo rehace.');
        for ($n = 1; $n <= 4; $n++) {
            if (!self::hay("rel_zonas")) { break; }
            self::cero($g, "nivel_$n",
                self::cuantos("SELECT COUNT(*) FROM rel_zonas r WHERE r.id_zona_$n IS NOT NULL AND NOT EXISTS
                                (SELECT 1 FROM zona_$n z WHERE z.id_zona_$n = r.id_zona_$n)"),
                "Todas las ubicaciones apuntan a niveles que existen (nivel $n)",
                "{n} ubicaciones con un nivel $n que ya no existe",
                'Su nombre completo ya no se puede rehacer.');
        }
    }

    // -----------------------------------------------------------------
    private static function ficheros(array $config): void
    {
        $g = 'Ficheros';
        $dir = (string)($config['uploads']['dir'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            self::anotar($g, 'subidas', self::FALLA, 'No existe la carpeta de las fotos', $dir);
        } else {
            is_writable($dir)
                ? self::anotar($g, 'subidas', self::BIEN, 'Se puede escribir en la carpeta de las fotos')
                : self::anotar($g, 'subidas', self::FALLA, 'No se puede escribir en la carpeta de las fotos',
                    'Nadie podrá subir una foto. Dale permiso de escritura al servidor web sobre ' . $dir);

            // Fotos que la base dice que hay y el disco no tiene. Hasta 5.000:
            // más allá se dice que no se han mirado todas.
            $filas = Db::all('SELECT id_ticket, archivo FROM adjuntos WHERE borrado = 0 ORDER BY id_adjunto DESC LIMIT 5000');
            $faltan = 0;
            foreach ($filas as $a) {
                if (!is_file($dir . '/' . (int)$a['id_ticket'] . '/' . $a['archivo'])) { $faltan++; }
            }
            $total = (int)self::cuantos('SELECT COUNT(*) FROM adjuntos WHERE borrado = 0');
            $mirados = count($filas) < $total ? ' (de las últimas ' . count($filas) . ')' : '';
            $faltan === 0
                ? self::anotar($g, 'fotos', self::BIEN, "Están en el disco todas las fotos$mirados", '', 0)
                : self::anotar($g, 'fotos', self::AVISO, "Faltan en el disco $faltan fotos$mirados",
                    'La ficha del parte las enseña rotas. Si tienes una copia con fotos, restáurala en otra carpeta y '
                    . 'recupera de ahí la carpeta api/uploads.', $faltan);
        }

        $copias = class_exists('Copias') ? Copias::carpeta() : '';
        if ($copias !== '' && is_dir($copias)) {
            is_writable($copias)
                ? self::anotar($g, 'copias', self::BIEN, 'Se puede escribir en la carpeta de las copias')
                : self::anotar($g, 'copias', self::FALLA, 'No se puede escribir en la carpeta de las copias',
                    'No se pueden hacer copias de seguridad.');
        }

        $libre = @disk_free_space($dir !== '' && is_dir($dir) ? $dir : __DIR__);
        if ($libre === false) {
            self::anotar($g, 'disco', self::ND, 'No se sabe cuánto disco queda');
        } else {
            $gb = $libre / 1073741824;
            $txt = sprintf('%.1f GB libres en el disco', $gb);
            if ($gb < 0.2) {
                self::anotar($g, 'disco', self::FALLA, $txt, 'Las fotos y las copias dejarán de guardarse.');
            } elseif ($gb < 1) {
                self::anotar($g, 'disco', self::AVISO, $txt, 'Queda poco. Las copias con fotos ocupan bastante.');
            } else {
                self::anotar($g, 'disco', self::BIEN, $txt);
            }
        }
    }

    // -----------------------------------------------------------------
    private static function seguridad(array $config, ?string $base): void
    {
        $g = 'Seguridad';
        $clave = (string)($config['auth']['app_key'] ?? '');
        if ($clave === '' || str_starts_with($clave, 'CAMBIA-ESTA-CLAVE')) {
            self::anotar($g, 'app_key', self::FALLA, 'La clave secreta es la de fábrica',
                'Es la misma en todas las instalaciones: con ella se pueden fabricar vales de descarga. '
                . 'php tools/instalar.php --actualizar genera una propia.');
        } elseif (strlen($clave) < 32) {
            self::anotar($g, 'app_key', self::AVISO, 'La clave secreta es corta (' . strlen($clave) . ' caracteres)');
        } else {
            self::anotar($g, 'app_key', self::BIEN, 'La clave secreta es propia de esta instalación');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $forzar = class_exists('Permisos') ? (bool)Permisos::ajuste('forzar_https', false) : false;
        if (PHP_SAPI === 'cli') {
            // Desde la consola no hay petición: lo mira el diagnóstico por su cuenta.
        } elseif (!$https) {
            self::anotar($g, 'https', self::AVISO, 'Se está entrando sin https',
                'Sin certificado, las contraseñas viajan en claro por la red del hotel, y no funcionan '
                . 'ni los avisos al móvil ni el trabajo sin cobertura. Ver docs/HTTPS.md.');
        } elseif (!$forzar) {
            self::anotar($g, 'https', self::AVISO, 'Hay https, pero no se obliga',
                'Quien escriba la dirección sin la «s» entra en claro. Configuración → Seguridad → Forzar HTTPS.');
        } else {
            self::anotar($g, 'https', self::BIEN, 'Se entra por https y se obliga');
        }

        // Lo que sirve el servidor web, PIDIÉNDOLO: es la única manera de
        // saber si lee los candados (docs/PENDIENTE.md, «lo primero de todo»).
        if ($base === null) {
            return;
        }
        /* El servidor integrado de PHP (php -S) atiende una petición cada
           vez: mientras calcula esta, no puede contestarle a ella misma, y
           cada pregunta esperaba a su tiempo límite (el panel tardaba
           ~15 s). Ahí no se pregunta, y se dice por qué. */
        if (PHP_SAPI === 'cli-server') {
            self::anotar($g, 'candados', self::ND, 'No se puede preguntar al servidor web qué sirve',
                'Este es el servidor de pruebas de PHP, que no atiende dos peticiones a la vez. '
                . 'En el servidor de verdad sí se comprueba; desde la consola: php tools/diagnostico.php');
            return;
        }
        $mirar = ['db/', 'api/config.php', 'api/copias/', 'tools/diagnostico.php'];
        $bd = basename((string)($config['db']['sqlite_path'] ?? ''));
        if (Db::driver() === 'sqlite' && $bd !== '') { array_unshift($mirar, 'db/' . $bd); }
        $abiertas = [];
        $mirado = 0;
        foreach ($mirar as $ruta) {
            $cod = self::codigoHttp(rtrim($base, '/') . '/' . $ruta);
            // Si no contesta, las demás tampoco van a contestar: no se insiste.
            if ($cod === null) { break; }
            $mirado++;
            /* api/config.php ejecutado devuelve vacío o 403: lo grave es que
               se sirva su texto. Aquí basta el 200 de lo que no debe dar 200. */
            if ($cod === 200 && $ruta !== 'api/config.php') { $abiertas[] = $ruta; }
        }
        if ($mirado === 0) {
            self::anotar($g, 'candados', self::ND, 'No se ha podido preguntar al servidor web qué sirve',
                'Compruébalo desde la consola: php tools/diagnostico.php');
        } elseif ($abiertas) {
            self::anotar($g, 'candados', self::FALLA, 'El servidor web sirve lo que no debe: ' . implode(', ', $abiertas),
                'No está leyendo los candados (.htaccess). En Apache: AllowOverride All en el sitio y recargar. '
                . 'Ver docs/INSTALACION.md, apartado 2.5. Mientras siga así, la base se puede descargar.',
                count($abiertas));
        } else {
            self::anotar($g, 'candados', self::BIEN, 'El servidor web no sirve nada sensible (' . $mirado . ' direcciones probadas)');
        }
    }

    private static function codigoHttp(string $url): ?int
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true],
                                          'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $h = @get_headers($url, false, $ctx);
            if (!$h || !preg_match('#\s(\d{3})\s#', (string)$h[0], $m)) { return null; }
            return (int)$m[1];
        }
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'HuraKan-Tick-integridad']);
        curl_exec($c);
        $cod = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return $cod > 0 ? $cod : null;
    }

    // -----------------------------------------------------------------
    private static function sistema(array $config): void
    {
        $g = 'Sistema';
        if (self::hay('copias')) {
            $ultima = Db::value('SELECT MAX(fecha) FROM copias');
            if ($ultima === null) {
                self::anotar($g, 'copia', self::AVISO, 'No hay ninguna copia de seguridad',
                    'Administración → Copias de seguridad → Crear copia. Y guárdala fuera del servidor.');
            } else {
                $dias = (int)floor((time() - strtotime((string)$ultima)) / 86400);
                $dias > 7
                    ? self::anotar($g, 'copia', self::AVISO, "La última copia de seguridad es de hace $dias días",
                        'Si se hacían solas, la tarea programada ha dejado de funcionar. Ver docs/INSTALACION.md.', $dias)
                    : self::anotar($g, 'copia', self::BIEN,
                        'La última copia de seguridad es de ' . ($dias === 0 ? 'hoy' : "hace $dias día(s)"), '', $dias);
            }
        }

        if (class_exists('Actualizaciones')) {
            try {
                $e = Actualizaciones::estado($config, false);
                if (!empty($e['hay_nueva'])) {
                    self::anotar($g, 'version', self::AVISO, 'Hay una versión nueva: ' . $e['publicada'],
                        'Tienes la ' . $e['actual'] . '. ' . ($e['donde'] ?? ''));
                } elseif (!empty($e['publicada'])) {
                    self::anotar($g, 'version', self::BIEN, 'Es la última versión publicada (' . $e['actual'] . ')');
                } else {
                    self::anotar($g, 'version', self::ND, 'No se sabe si hay una versión nueva',
                        (string)($e['motivo'] ?? ''));
                }
            } catch (Throwable $ex) {
                self::anotar($g, 'version', self::ND, 'No se ha podido preguntar por versiones nuevas', $ex->getMessage());
            }
        }

        if (self::hay('errores')) {
            $n = self::cuantos('SELECT COUNT(*) FROM errores WHERE visto = 0');
            if ($n !== null) {
                $n === 0
                    ? self::anotar($g, 'errores', self::BIEN, 'No hay errores sin revisar', '', 0)
                    : self::anotar($g, 'errores', self::AVISO, "$n errores sin revisar",
                        'Administración → Errores. Ahí se ve qué falló, a quién y en qué pantalla.', $n);
            }
        }
    }
}
