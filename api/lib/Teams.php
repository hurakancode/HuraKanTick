<?php
/**
 * La copia de los avisos al chat privado de Teams.
 *
 * En el hotel la gente vive en Teams. Un aviso que sólo existe dentro de
 * HuraKan Tick compite con eso y pierde, así que aquí se manda una copia al chat
 * de cada persona con el bot de HuraKan Tick.
 *
 * ES DE IDA, NO DE VUELTA. Lo que alguien conteste en Teams no entra en
 * el parte, y se dice en la propia tarjeta. Hacerlo bidireccional
 * tendría un problema peor que el que resuelve: dos sitios donde está la
 * conversación y ninguno que sea el oficial. Para una inspección, el
 * registro es el parte.
 *
 * ──────────────────────────────────────────────────────────────────────
 * Cómo funciona, porque son tres pasos con Microsoft y conviene saber
 * cuál ha fallado cuando falle:
 *
 *   1. Un testigo (token) de Azure, con el identificador y el secreto de
 *      la aplicación. Vale una hora y se guarda en memoria.
 *   2. Quién es esta persona en Azure. Se busca por su correo en Graph y
 *      se guarda en «login.teams_id»: sólo se pregunta una vez.
 *   3. El chat con el bot. Se crea la primera vez y su identificador se
 *      guarda en «login.teams_conversacion». A partir de ahí se publica
 *      directamente ahí.
 *
 * El paso 3 falla si la aplicación de Teams no está instalada para esa
 * persona. Eso no se arregla desde aquí: lo hace vuestro informático con
 * una directiva de instalación. El error lo dice con esas palabras.
 *
 * NADA DE ESTO PUEDE TUMBAR UN PARTE. Guardar un parte sólo escribe una
 * fila en la bandeja de salida; hablar con Microsoft pasa después, en el
 * latido, y cada fallo se apunta y se reintenta.
 */
declare(strict_types=1);

final class Teams
{
    /** Cuántas veces se reintenta antes de darlo por perdido. */
    private const INTENTOS = 4;

    /** Cuántos se mandan de una vez, para no eternizar una petición. */
    private const POR_TANDA = 15;

    /** El testigo de Azure, mientras dure esta petición. */
    private static array $testigos = [];

    /* ================================================================ */
    /* Encolar                                                          */
    /* ================================================================ */

    /**
     * Apuntar una copia para esta persona. Es sólo un INSERT.
     *
     * Devuelve false sin ruido cuando no hay nada que hacer: apagado,
     * sin configurar, o esa persona lo tiene desactivado.
     */
    public static function encolar(
        int $idLogin,
        string $titulo,
        string $cuerpo,
        ?int $idTicket = null,
        string $ruta = '',
        bool $urgente = false
    ): bool {
        try {
            if (!self::encendido() || !Db::tablaExiste('teams_salida')) { return false; }

            // Sólo lo urgente, si así se ha pedido.
            if (!$urgente && (bool)Permisos::ajuste('teams_solo_urgentes', false)) {
                return false;
            }

            $quien = Db::one('SELECT teams_avisar, correo FROM login WHERE id_login = ?', [$idLogin]);
            if ($quien === null) { return false; }
            if ((int)($quien['teams_avisar'] ?? 1) !== 1) { return false; }
            // Sin correo no hay forma de saber quién es en Azure.
            if (trim((string)($quien['correo'] ?? '')) === '') { return false; }

            Db::insert('teams_salida', [
                'id_login'  => $idLogin,
                'id_ticket' => $idTicket,
                'titulo'    => mb_substr($titulo, 0, 250),
                'cuerpo'    => mb_substr($cuerpo, 0, 1500),
                'ruta'      => mb_substr($ruta, 0, 250) ?: null,
                'urgente'   => $urgente ? 1 : 0,
                'estado'    => 'pendiente',
                'intentos'  => 0,
                'creado'    => Db::now(),
            ]);
            return true;
        } catch (Throwable $e) {
            // Un aviso que no se encola no puede impedir guardar el parte.
            if (class_exists('Errores')) {
                Errores::apuntar('servidor', 'No se pudo encolar el aviso de Teams: '
                                 . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
            }
            return false;
        }
    }

    /* ================================================================ */
    /* Vaciar la bandeja                                                */
    /* ================================================================ */

    /**
     * Manda lo que haya pendiente. Lo llama el latido.
     *
     * @return array{enviados:int, fallidos:int, detalle:array}
     */
    public static function vaciarBandeja(?callable $log = null): array
    {
        $log = $log ?? static fn(string $m) => null;
        $r = ['enviados' => 0, 'fallidos' => 0, 'detalle' => []];

        if (!self::encendido() || !Db::tablaExiste('teams_salida')) { return $r; }

        $pendientes = Db::all(
            'SELECT * FROM teams_salida
              WHERE estado = ? AND intentos < ?
              ORDER BY urgente DESC, creado ASC
              LIMIT ' . self::POR_TANDA,
            ['pendiente', self::INTENTOS]);

        foreach ($pendientes as $fila) {
            $id = (int)$fila['id_salida'];
            try {
                self::mandarUno($fila);
                Db::update('teams_salida',
                           ['estado' => 'enviado', 'enviado' => Db::now(),
                            'intentos' => (int)$fila['intentos'] + 1, 'error' => null],
                           'id_salida = :id', ['id' => $id]);
                $r['enviados']++;
                $log('  · enviado a ' . $fila['id_login'] . ': ' . $fila['titulo']);
            } catch (Throwable $e) {
                $intentos = (int)$fila['intentos'] + 1;
                $agotado  = $intentos >= self::INTENTOS;
                Db::update('teams_salida', [
                    'estado'   => $agotado ? 'fallido' : 'pendiente',
                    'intentos' => $intentos,
                    'error'    => mb_substr($e->getMessage(), 0, 500),
                ], 'id_salida = :id', ['id' => $id]);
                $r['fallidos']++;
                $r['detalle'][] = ['id' => $id, 'error' => $e->getMessage()];
                $log('  · falló (' . $intentos . '/' . self::INTENTOS . '): ' . $e->getMessage());

                if ($agotado && class_exists('Errores')) {
                    Errores::apuntar('servidor',
                        'Teams: no se pudo entregar un aviso tras ' . self::INTENTOS
                        . ' intentos: ' . $e->getMessage(), 'Teams::vaciarBandeja');
                }
            }
        }

        return $r;
    }

    /** Uno, de principio a fin. Lanza si algo falla. */
    private static function mandarUno(array $fila): void
    {
        $idLogin = (int)$fila['id_login'];
        $quien = Db::one('SELECT id_login, correo, teams_id, teams_conversacion
                            FROM login WHERE id_login = ?', [$idLogin]);
        if ($quien === null) {
            throw new RuntimeException('Esa persona ya no existe.');
        }

        $conversacion = (string)($quien['teams_conversacion'] ?? '');

        if ($conversacion === '') {
            $aad = (string)($quien['teams_id'] ?? '');
            if ($aad === '') {
                $aad = self::buscarEnAzure((string)$quien['correo']);
                Db::update('login', ['teams_id' => $aad], 'id_login = :id', ['id' => $idLogin]);
            }
            $conversacion = self::abrirConversacion($aad);
            Db::update('login', ['teams_conversacion' => $conversacion],
                       'id_login = :id', ['id' => $idLogin]);
        }

        self::publicar($conversacion, self::tarjeta($fila));
    }

    /* ================================================================ */
    /* Los tres pasos con Microsoft                                     */
    /* ================================================================ */

    /** Un testigo para el ámbito que sea. */
    private static function testigo(string $ambito): string
    {
        if (isset(self::$testigos[$ambito])
            && self::$testigos[$ambito]['caduca'] > time() + 60) {
            return self::$testigos[$ambito]['valor'];
        }

        $tenant = trim((string)Permisos::ajuste('teams_tenant', ''));
        $app    = trim((string)Permisos::ajuste('teams_app_id', ''));
        $clave  = trim((string)Permisos::ajuste('teams_app_secreto', ''));

        if ($tenant === '' || $app === '' || $clave === '') {
            throw new RuntimeException(
                'Falta configurar Teams: hacen falta el tenant, el identificador '
                . 'de la aplicación y el secreto.');
        }

        $r = self::pedir('POST',
            self::baseLogin() . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $app,
                'client_secret' => $clave,
                'scope'         => $ambito,
            ]),
            ['Content-Type: application/x-www-form-urlencoded']);

        if (!isset($r['access_token'])) {
            throw new RuntimeException(
                'Azure no ha dado el testigo: '
                . (string)($r['error_description'] ?? $r['error'] ?? 'sin explicación'));
        }

        self::$testigos[$ambito] = [
            'valor'  => (string)$r['access_token'],
            'caduca' => time() + (int)($r['expires_in'] ?? 3600),
        ];
        return self::$testigos[$ambito]['valor'];
    }

    /** Quién es esta persona en Azure, por su correo. */
    private static function buscarEnAzure(string $correo): string
    {
        $correo = trim($correo);
        if ($correo === '') {
            throw new RuntimeException('Esa persona no tiene correo en HuraKan Tick: '
                . 'sin correo no se la puede encontrar en Teams.');
        }

        $t = self::testigo('https://graph.microsoft.com/.default');
        $r = self::pedir('GET',
            self::baseGraph() . '/v1.0/users/' . rawurlencode($correo) . '?$select=id',
            null, ['Authorization: Bearer ' . $t]);

        if (!isset($r['id'])) {
            throw new RuntimeException(
                'Microsoft no encuentra a nadie con el correo ' . $correo
                . '. Comprueba que es el mismo que usa en Teams.');
        }
        return (string)$r['id'];
    }

    /** Abre —o recupera— el chat del bot con esa persona. */
    private static function abrirConversacion(string $aad): string
    {
        $t      = self::testigo('https://api.botframework.com/.default');
        $tenant = trim((string)Permisos::ajuste('teams_tenant', ''));
        $app    = trim((string)Permisos::ajuste('teams_app_id', ''));

        $r = self::pedir('POST', self::baseBot() . '/v3/conversations',
            json_encode([
                'bot'        => ['id' => '28:' . $app],
                'members'    => [['id' => '29:' . $aad, 'aadObjectId' => $aad]],
                'channelData'=> ['tenant' => ['id' => $tenant]],
                'isGroup'    => false,
            ], JSON_UNESCAPED_UNICODE),
            ['Authorization: Bearer ' . $t, 'Content-Type: application/json']);

        if (!isset($r['id'])) {
            $detalle = (string)($r['error']['message'] ?? $r['message'] ?? 'sin explicación');
            throw new RuntimeException(
                'No se ha podido abrir el chat de Teams con esa persona. '
                . 'Lo más probable es que la aplicación de HuraKan Tick no esté instalada '
                . 'para ella: eso se hace con una directiva de instalación en el '
                . 'centro de administración de Teams. Microsoft dice: ' . $detalle);
        }
        return (string)$r['id'];
    }

    /** Publica la tarjeta en ese chat. */
    private static function publicar(string $conversacion, array $tarjeta): void
    {
        $t = self::testigo('https://api.botframework.com/.default');
        $r = self::pedir('POST',
            self::baseBot() . '/v3/conversations/' . rawurlencode($conversacion) . '/activities',
            json_encode($tarjeta, JSON_UNESCAPED_UNICODE),
            ['Authorization: Bearer ' . $t, 'Content-Type: application/json']);

        if (isset($r['error'])) {
            throw new RuntimeException('Teams ha rechazado el mensaje: '
                . (string)($r['error']['message'] ?? 'sin explicación'));
        }
    }

    /* ================================================================ */
    /* La tarjeta                                                       */
    /* ================================================================ */

    /**
     * Lo que se ve en Teams.
     *
     * Una Adaptive Card y no texto suelto porque lleva un botón que abre
     * el parte: sin él, la persona lee el aviso en Teams y tiene que ir a
     * buscarlo a mano, que es justo lo que no queremos.
     */
    private static function tarjeta(array $fila): array
    {
        $url = rtrim((string)Permisos::ajuste('teams_url_app', ''), '/');
        $ruta = (string)($fila['ruta'] ?? '');
        /* La barra después del # importa: el enrutador espera «#/panel».
           Sin ella el enlace abre la aplicación en el inicio y la persona
           tiene que buscar el parte a mano, que es lo que el botón venía
           a evitar. */
        $enlace = ($url !== '' && $ruta !== '')
            ? $url . '/#/' . ltrim($ruta, '/')
            : '';

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => (string)$fila['titulo'],
             'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ];

        if (trim((string)($fila['cuerpo'] ?? '')) !== '') {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => (string)$fila['cuerpo'], 'wrap' => true];
        }

        /* Que quede claro que contestar aquí no sirve. Sin esta línea la
           gente respondería al bot y su respuesta se perdería sin que
           nadie se enterase, que es peor que no mandar nada. */
        $cuerpo[] = ['type' => 'TextBlock', 'spacing' => 'Small', 'isSubtle' => true,
                     'size' => 'Small', 'wrap' => true,
                     'text' => 'Copia de un aviso de HuraKan Tick. Para responder, entra en el parte: '
                             . 'lo que escribas aquí no llega a nadie.'];

        $tarjeta = [
            'type'    => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body'    => $cuerpo,
        ];

        if ($enlace !== '') {
            $tarjeta['actions'] = [[
                'type' => 'Action.OpenUrl', 'title' => 'Abrir el parte', 'url' => $enlace,
            ]];
        }

        return [
            'type'        => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content'     => $tarjeta,
            ]],
        ];
    }

    /* ================================================================ */
    /* Comprobación, para el administrador                              */
    /* ================================================================ */

    /**
     * Probar el enlace, paso a paso, y decir dónde se rompe.
     *
     * Esto existe porque «no funciona Teams» puede ser cinco cosas muy
     * distintas y cuatro de ellas las arregla vuestro informático, no yo.
     */
    public static function comprobar(?int $idLogin = null): array
    {
        $pasos = [];
        $paso = static function (string $que, callable $hacer) use (&$pasos): mixed {
            try {
                $v = $hacer();
                $pasos[] = ['paso' => $que, 'bien' => true, 'detalle' => ''];
                return $v;
            } catch (Throwable $e) {
                $pasos[] = ['paso' => $que, 'bien' => false, 'detalle' => $e->getMessage()];
                throw $e;
            }
        };

        try {
            $paso('La configuración está completa', static function () {
                foreach (['teams_tenant' => 'el tenant',
                          'teams_app_id' => 'el identificador de la aplicación',
                          'teams_app_secreto' => 'el secreto'] as $c => $q) {
                    if (trim((string)Permisos::ajuste($c, '')) === '') {
                        throw new RuntimeException('Falta ' . $q . '.');
                    }
                }
                return true;
            });

            $paso('Azure da el testigo del bot',
                  static fn() => self::testigo('https://api.botframework.com/.default'));

            $paso('Azure da el testigo de Graph',
                  static fn() => self::testigo('https://graph.microsoft.com/.default'));

            if ($idLogin !== null) {
                $correo = (string)Db::value('SELECT correo FROM login WHERE id_login = ?', [$idLogin]);
                $aad = $paso('Microsoft reconoce a esa persona por su correo',
                             static fn() => self::buscarEnAzure($correo));
                $conv = $paso('Se puede abrir su chat de Teams',
                              static fn() => self::abrirConversacion((string)$aad));
                $paso('Llega el mensaje', function () use ($conv) {
                    self::publicar((string)$conv, self::tarjeta([
                        'titulo' => 'Prueba de HuraKan Tick',
                        'cuerpo' => 'Si lees esto, la copia a Teams funciona.',
                        'ruta'   => '/panel',
                    ]));
                    return true;
                });
            }
        } catch (Throwable $e) {
            // Ya está apuntado en $pasos: aquí sólo se corta la cadena.
        }

        return [
            'bien'  => !array_filter($pasos, static fn($p) => !$p['bien']),
            'pasos' => $pasos,
        ];
    }

    /* ================================================================ */
    /* Cosas de dentro                                                  */
    /* ================================================================ */

    public static function encendido(): bool
    {
        return (bool)Permisos::ajuste('teams_activo', false);
    }

    /* Las direcciones son configurables por una razón práctica: así se
       puede apuntar a un servidor de pruebas y comprobar todo el camino
       sin tocar el Teams de verdad del hotel. En producción se quedan
       como están. */
    private static function baseLogin(): string
    {
        return rtrim((string)Permisos::ajuste('teams_base_login',
                     'https://login.microsoftonline.com'), '/');
    }

    private static function baseGraph(): string
    {
        return rtrim((string)Permisos::ajuste('teams_base_graph',
                     'https://graph.microsoft.com'), '/');
    }

    private static function baseBot(): string
    {
        return rtrim((string)Permisos::ajuste('teams_base_bot',
                     'https://smba.trafficmanager.net/emea'), '/');
    }

    /** Una petición HTTP, con tiempos cortos y la respuesta ya en array. */
    private static function pedir(string $metodo, string $url, ?string $cuerpo, array $cabeceras): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Este servidor no tiene curl: hace falta para hablar con Microsoft.');
        }

        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            // Cortos a propósito: esto corre dentro de una petición de
            // alguien. Más vale reintentar luego que dejar a nadie esperando.
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($cuerpo !== null) { curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo); }

        $respuesta = curl_exec($c);
        $codigo    = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $fallo     = curl_error($c);
        curl_close($c);

        if ($respuesta === false) {
            throw new RuntimeException('No se ha podido contactar con Microsoft: ' . $fallo);
        }

        $datos = json_decode((string)$respuesta, true);
        if (!is_array($datos)) { $datos = []; }

        if ($codigo >= 400) {
            $msg = (string)($datos['error']['message'] ?? $datos['error_description']
                            ?? $datos['message'] ?? substr((string)$respuesta, 0, 200));
            throw new RuntimeException('Microsoft responde ' . $codigo . ': ' . $msg);
        }

        return $datos;
    }
}
