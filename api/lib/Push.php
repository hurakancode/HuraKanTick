<?php
/**
 * Avisos push: el servidor empuja, en vez de esperar a que pregunten.
 *
 * Hasta ahora un aviso se generaba cuando la aplicación consultaba al
 * servidor. Con la aplicación cerrada nadie consultaba, así que el aviso
 * esperaba a que alguien la abriera — justo cuando más falta hace, de
 * madrugada y con el móvil en el bolsillo, es cuando peor funcionaba.
 *
 * Dos caminos, porque no hay uno solo que valga:
 *
 *   · Navegador → Web Push (RFC 8030 y 8291). El mensaje va cifrado de
 *     extremo a extremo: ni Google ni Mozilla pueden leerlo, sólo el
 *     navegador que se suscribió. Se firma con claves VAPID para que el
 *     servicio sepa quién empuja.
 *
 *   · APK → Firebase. Android no admite Web Push en una aplicación
 *     empaquetada, así que no hay alternativa. Aquí el mensaje sí pasa
 *     por Google en claro, y por eso no se manda nada delicado: sólo el
 *     código del parte y su título.
 *
 * Nada de esto tumba la aplicación si falla. Un aviso que no sale es un
 * aviso que no sale; el parte sigue estando y se ve al abrir.
 */
declare(strict_types=1);

final class Push
{
    private const FCM_AMBITO = 'https://www.googleapis.com/auth/firebase.messaging';
    private const FALLOS_MAX = 5;

    /* ================================================================ */
    /* Claves VAPID                                                     */
    /* ================================================================ */

    /**
     * Las claves con las que este servidor se identifica.
     *
     * Se generan solas la primera vez y se guardan. No se rehacen nunca:
     * cambiarlas invalidaría todas las suscripciones existentes y habría
     * que volver a pedir permiso en cada navegador.
     */
    public static function vapid(): array
    {
        $pub  = (string)Permisos::ajuste('push_vapid_publica', '');
        $priv = (string)Permisos::ajuste('push_vapid_privada', '');

        if ($pub !== '' && $priv !== '') {
            return ['publica' => $pub, 'privada' => $priv];
        }

        $par = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($par === false) {
            throw new ApiError('Este servidor no puede generar las claves de push.', 500);
        }
        $d = openssl_pkey_get_details($par);

        // La pública va sin comprimir: 0x04 seguido de X e Y, 65 bytes.
        $publica = self::b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                                     . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
        $privada = self::b64u(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT));

        Db::update('ajustes_sistema', ['valor' => $publica], 'clave = :c', ['c' => 'push_vapid_publica']);
        Db::update('ajustes_sistema', ['valor' => $privada], 'clave = :c', ['c' => 'push_vapid_privada']);
        Permisos::olvidarAjustes();

        return ['publica' => $publica, 'privada' => $privada];
    }

    /** Lo único que el cliente necesita saber. */
    public static function clavePublica(): string
    {
        return self::vapid()['publica'];
    }

    /* ================================================================ */
    /* Alta y baja de destinos                                          */
    /* ================================================================ */

    public static function registrar(array $d, int $idLogin): int
    {
        $tipo = ($d['tipo'] ?? '') === 'android' ? 'android' : 'web';
        $destino = trim((string)($d['destino'] ?? ''));
        if ($destino === '') {
            throw new ApiError('Falta la dirección de push.', 422, ['campo' => 'destino']);
        }
        if ($tipo === 'web' && (empty($d['clave_p256']) || empty($d['clave_auth']))) {
            throw new ApiError('Faltan las claves de cifrado.', 422);
        }

        // El mismo aparato puede volver a registrarse: los navegadores
        // renuevan la dirección cada cierto tiempo por su cuenta.
        $ya = Db::value('SELECT id_destino FROM push_destinos WHERE destino = ?', [$destino]);
        if ($ya !== null) {
            Db::update('push_destinos', [
                'id_login'   => $idLogin,
                'clave_p256' => $d['clave_p256'] ?? null,
                'clave_auth' => $d['clave_auth'] ?? null,
                'aparato'    => mb_substr((string)($d['aparato'] ?? ''), 0, 120) ?: null,
                'activo'     => 1,
                'fallos'     => 0,
                'ultimo_uso' => Db::now(),
            ], 'id_destino = :id', ['id' => (int)$ya]);
            return (int)$ya;
        }

        return Db::insert('push_destinos', [
            'id_login'   => $idLogin,
            'tipo'       => $tipo,
            'destino'    => $destino,
            'clave_p256' => $d['clave_p256'] ?? null,
            'clave_auth' => $d['clave_auth'] ?? null,
            'aparato'    => mb_substr((string)($d['aparato'] ?? ''), 0, 120) ?: null,
            'creado'     => Db::now(),
            'activo'     => 1,
        ]);
    }

    public static function borrar(string $destino, int $idLogin): void
    {
        Db::run('DELETE FROM push_destinos WHERE destino = ? AND id_login = ?', [$destino, $idLogin]);
    }

    public static function destinosDe(int $idLogin): array
    {
        return Db::all(
            'SELECT id_destino, tipo, aparato, creado, ultimo_uso, fallos
               FROM push_destinos WHERE id_login = ? AND activo = 1 ORDER BY creado DESC',
            [$idLogin]);
    }

    /* ================================================================ */
    /* Envío                                                            */
    /* ================================================================ */

    /**
     * Empuja un aviso a todos los aparatos de una persona.
     *
     * Nunca lanza excepción hacia arriba: se llama justo después de
     * crear un parte, y que falle un aviso no puede deshacer el parte.
     *
     * @return int cuántos salieron
     */
    public static function enviar(int $idLogin, string $titulo, string $cuerpo, array $datos = []): int
    {
        if (!Permisos::ajuste('mod_push', true)) {
            return 0;
        }
        if (!Db::tablaExiste('push_destinos')) {
            return 0;
        }

        // Con «sólo lo urgente» se filtra aquí: es la diferencia entre
        // un aviso útil de madrugada y un móvil que pita todo el día.
        if (Permisos::ajuste('push_solo_urgentes', false)) {
            $ev = (string)($datos['evento'] ?? '');
            if (!in_array($ev, ['urgente', 'vencido', 'conflicto'], true)) {
                return 0;
            }
        }

        $destinos = Db::all(
            'SELECT * FROM push_destinos WHERE id_login = ? AND activo = 1', [$idLogin]);
        if (!$destinos) {
            return 0;
        }

        $carga = json_encode([
            'titulo' => mb_substr($titulo, 0, 120),
            'cuerpo' => mb_substr($cuerpo, 0, 240),
            'ruta'   => $datos['ruta'] ?? null,
            'evento' => $datos['evento'] ?? 'aviso',
            'tag'    => $datos['tag'] ?? 'hurakantick',
        ], JSON_UNESCAPED_UNICODE);

        $enviados = 0;
        /* Un aviso que no sale deja rastro.
         *
         * Antes el fallo sólo subía el contador del aparato y ahí moría:
         * desde fuera, mandar un aviso y que no llegara era idéntico a
         * mandarlo y que llegara. Y los avisos no llegan por cosas muy
         * concretas —las claves cambiadas, el servidor sin salida a
         * internet, el servicio de Google contestando 403—, cada una con
         * su arreglo. Sin apuntar el motivo no hay por dónde empezar: se
         * mira el móvil, que está bien, y se da por imposible.
         *
         * Se apunta UNA vez por tanda aunque fallen veinte aparatos: lo
         * que importa es el motivo, y suele ser el mismo para todos. */
        $motivo = '';
        foreach ($destinos as $d) {
            try {
                $ok = $d['tipo'] === 'android'
                    ? self::porFirebase($d, $titulo, $cuerpo, $datos)
                    : self::porWebPush($d, $carga);
                if ($ok) {
                    $enviados++;
                    Db::update('push_destinos', ['ultimo_uso' => Db::now(), 'fallos' => 0],
                               'id_destino = :id', ['id' => (int)$d['id_destino']]);
                } else {
                    if ($motivo === '') {
                        $motivo = self::$ultimoMotivo !== ''
                            ? self::$ultimoMotivo
                            : 'el servicio de avisos ha rechazado el envío';
                    }
                    self::anotarFallo((int)$d['id_destino'], (int)$d['fallos']);
                }
            } catch (Throwable $e) {
                if ($motivo === '') { $motivo = $e->getMessage(); }
                self::anotarFallo((int)$d['id_destino'], (int)$d['fallos']);
            }
        }

        if ($enviados === 0 && $destinos && class_exists('Errores')) {
            Errores::apuntar('servidor',
                'No ha salido ningún aviso al móvil',
                'Aparatos a los que se intentó: ' . count($destinos)
              . '. Motivo: ' . ($motivo !== '' ? $motivo : 'sin detalle')
              . '. Mira las claves de push en Configuración y que el servidor '
              . 'tenga salida a internet.');
        }

        return $enviados;
    }

    /**
     * Un destino que falla repetidamente se apaga.
     *
     * Los navegadores desinstalados o los móviles formateados dejan
     * direcciones muertas que responden error para siempre. Sin esto, el
     * envío se iría alargando con cada aviso.
     */
    /** Por qué falló el último envío, para poder apuntarlo. */
    private static string $ultimoMotivo = '';

    private static function anotarFallo(int $id, int $fallos): void
    {
        $n = $fallos + 1;
        Db::update('push_destinos', [
            'fallos' => $n,
            'activo' => $n >= self::FALLOS_MAX ? 0 : 1,
        ], 'id_destino = :id', ['id' => $id]);
    }

    /* ================================================================ */
    /* Web Push: cifrado aes128gcm (RFC 8188 y 8291)                    */
    /* ================================================================ */

    private static function porWebPush(array $d, string $carga): bool
    {
        $cifrado = self::cifrar($carga, (string)$d['clave_p256'], (string)$d['clave_auth']);
        $cabeceras = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . self::cabeceraVapid((string)$d['destino']),
        ];

        [$codigo, $cuerpo] = self::http((string)$d['destino'], $cifrado, $cabeceras);

        // 404 y 410 significan que esa suscripción ya no existe: se borra
        // en vez de reintentarla mil veces.
        if ($codigo === 404 || $codigo === 410) {
            Db::run('DELETE FROM push_destinos WHERE id_destino = ?', [(int)$d['id_destino']]);
            self::$ultimoMotivo = 'ese móvil ya no tiene la aplicación instalada '
                                . '(el servicio contestó ' . $codigo . ')';
            return false;
        }
        if ($codigo < 200 || $codigo >= 300) {
            // Aquí es donde se sabe POR QUÉ, y es lo único que sirve para
            // arreglarlo: un 401 o 403 son las claves; un 0, que este
            // servidor no llega a internet.
            self::$ultimoMotivo = $codigo === 0
                ? 'el servidor no ha podido conectar con el servicio de avisos '
                . '(sin salida a internet, o cortafuegos)'
                : 'el servicio de avisos contestó ' . $codigo
                . ($cuerpo !== '' ? ': ' . mb_substr(trim($cuerpo), 0, 120) : '');
        }
        return $codigo >= 200 && $codigo < 300;
    }

    /**
     * Cifra la carga para un navegador concreto.
     *
     * Sigue el RFC 8291 al pie de la letra. En resumen: se genera un par
     * de claves de usar y tirar, se acuerda un secreto con la clave
     * pública del navegador, y de ahí salen la clave y el vector con los
     * que se cifra. Sólo ese navegador puede deshacerlo.
     */
    private static function cifrar(string $carga, string $p256dh, string $auth): string
    {
        $claveCliente = self::b64uDec($p256dh);      // 65 bytes sin comprimir
        $secretoAuth  = self::b64uDec($auth);        // 16 bytes

        // Par efímero: uno distinto por mensaje.
        $efimero = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $det = openssl_pkey_get_details($efimero);
        $pubEfimera = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT)
                             . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // Secreto compartido con la clave del navegador.
        $compartido = openssl_pkey_derive(self::claveDesdePunto($claveCliente), $efimero, 32);
        if ($compartido === false) {
            throw new RuntimeException('No se pudo acordar el secreto ECDH.');
        }

        // De ahí, por HKDF, salen el material y luego clave y vector.
        $info = "WebPush: info\x00" . $claveCliente . $pubEfimera;
        $ikm  = hash_hkdf('sha256', $compartido, 32, $info, $secretoAuth);

        $sal = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sal);

        // El relleno obligatorio: un 0x02 al final del texto.
        $texto = $carga . "\x02";
        $etiqueta = '';
        $cifrado = openssl_encrypt($texto, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiqueta);
        if ($cifrado === false) {
            throw new RuntimeException('Fallo al cifrar la carga.');
        }

        /* La cabecera que espera el navegador:
             sal(16) · tamaño de registro(4) · largo de la clave(1) · clave(65) */
        return $sal
             . pack('N', 4096)
             . chr(strlen($pubEfimera))
             . $pubEfimera
             . $cifrado . $etiqueta;
    }

    /** Convierte un punto sin comprimir en una clave que openssl entienda. */
    private static function claveDesdePunto(string $punto): OpenSSLAsymmetricKey
    {
        // Se monta un DER de SubjectPublicKeyInfo para prime256v1. El
        // prefijo es fijo para esta curva; sólo cambia el punto.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $punto;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new RuntimeException('La clave pública del navegador no es válida.');
        }
        return $k;
    }

    /**
     * La cabecera VAPID: un JWT firmado que dice quién empuja.
     *
     * El servicio de push no lo usa para cifrar nada; sirve para que
     * puedan avisar al responsable si un servidor empieza a enviar
     * basura, y para limitar por origen.
     */
    private static function cabeceraVapid(string $destino): string
    {
        $v = self::vapid();
        $u = parse_url($destino);
        $origen = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');

        $contacto = (string)Permisos::ajuste('push_vapid_contacto', '');
        $sub = $contacto !== '' ? 'mailto:' . $contacto : 'mailto:admin@localhost';

        $cabecera = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $cuerpo   = self::b64u(json_encode([
            'aud' => $origen,
            'exp' => time() + 43200,          // doce horas
            'sub' => $sub,
        ]));

        $firma = self::firmarEs256($cabecera . '.' . $cuerpo, $v['privada']);
        $jwt = $cabecera . '.' . $cuerpo . '.' . self::b64u($firma);

        return 'vapid t=' . $jwt . ', k=' . $v['publica'];
    }

    /** Firma ES256, con la firma en el formato plano que pide JWS. */
    private static function firmarEs256(string $datos, string $privadaB64): string
    {
        $d = self::b64uDec($privadaB64);
        $pub = self::b64uDec(self::vapid()['publica']);

        // Se recompone la clave privada en PEM a partir de sus bytes.
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\xa1\x44\x03\x42\x00" . $pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";

        $k = openssl_pkey_get_private($pem);
        if ($k === false) {
            throw new RuntimeException('La clave VAPID privada no es válida.');
        }
        $firmaDer = '';
        openssl_sign($datos, $firmaDer, $k, OPENSSL_ALGO_SHA256);

        return self::derAPlano($firmaDer);
    }

    /** Pasa una firma DER (dos enteros) a los 64 bytes que pide JWS. */
    private static function derAPlano(string $der): string
    {
        $p = 0;
        if (ord($der[$p++]) !== 0x30) {
            throw new RuntimeException('Firma con formato inesperado.');
        }
        $p++;                                    // longitud total
        $leer = static function () use ($der, &$p): string {
            $p++;                                // 0x02
            $n = ord($der[$p++]);
            $v = substr($der, $p, $n);
            $p += $n;
            return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
        };
        return $leer() . $leer();
    }

    /* ================================================================ */
    /* Firebase, para el APK                                            */
    /* ================================================================ */

    private static function porFirebase(array $d, string $titulo, string $cuerpo, array $datos): bool
    {
        $cuenta = self::cuentaFirebase();
        if ($cuenta === null) {
            return false;
        }
        $token = self::tokenFirebase($cuenta);
        if ($token === null) {
            return false;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $cuenta['project_id'] . '/messages:send';

        /* Se manda «data» y no «notification» a propósito: así la
           notificación la construye la aplicación, que sabe si está
           abierta, si toca sonido y qué icono usar. Con «notification»
           la pinta Android y no hay forma de intervenir. */
        $mensaje = [
            'message' => [
                'token' => $d['destino'],
                'data'  => [
                    'titulo' => mb_substr($titulo, 0, 120),
                    'cuerpo' => mb_substr($cuerpo, 0, 240),
                    'ruta'   => (string)($datos['ruta'] ?? ''),
                    'evento' => (string)($datos['evento'] ?? 'aviso'),
                ],
                'android' => [
                    'priority' => ($datos['evento'] ?? '') === 'urgente' ? 'high' : 'normal',
                ],
            ],
        ];

        [$codigo, $resp] = self::http($url, json_encode($mensaje, JSON_UNESCAPED_UNICODE), [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);

        // Un token que Firebase ya no reconoce se borra.
        if ($codigo === 404 || ($codigo === 400 && str_contains((string)$resp, 'UNREGISTERED'))) {
            Db::run('DELETE FROM push_destinos WHERE id_destino = ?', [(int)$d['id_destino']]);
            return false;
        }
        return $codigo >= 200 && $codigo < 300;
    }

    private static function cuentaFirebase(): ?array
    {
        $json = (string)Permisos::ajuste('push_fcm_cuenta', '');
        if (trim($json) === '') {
            return null;
        }
        $c = json_decode($json, true);
        if (!is_array($c) || empty($c['private_key']) || empty($c['client_email']) || empty($c['project_id'])) {
            return null;
        }
        return $c;
    }

    /**
     * El testigo de acceso de Firebase.
     *
     * Dura una hora; se guarda en memoria durante la petición para no
     * pedir uno por cada aviso cuando se avisa a diez personas a la vez.
     */
    private static ?array $tokenCache = null;

    private static function tokenFirebase(array $cuenta): ?string
    {
        if (self::$tokenCache !== null && self::$tokenCache['caduca'] > time() + 60) {
            return self::$tokenCache['token'];
        }

        $ahora = time();
        $cab = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cue = self::b64u(json_encode([
            'iss'   => $cuenta['client_email'],
            'scope' => self::FCM_AMBITO,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $ahora,
            'exp'   => $ahora + 3600,
        ]));

        $firma = '';
        $k = openssl_pkey_get_private($cuenta['private_key']);
        if ($k === false) {
            return null;
        }
        openssl_sign($cab . '.' . $cue, $firma, $k, OPENSSL_ALGO_SHA256);
        $jwt = $cab . '.' . $cue . '.' . self::b64u($firma);

        [$codigo, $resp] = self::http('https://oauth2.googleapis.com/token',
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            ['Content-Type: application/x-www-form-urlencoded']);

        if ($codigo !== 200) {
            return null;
        }
        $d = json_decode((string)$resp, true);
        if (!isset($d['access_token'])) {
            return null;
        }
        self::$tokenCache = ['token' => $d['access_token'], 'caduca' => time() + 3500];
        return $d['access_token'];
    }

    /* ================================================================ */
    /* Utilidades                                                       */
    /* ================================================================ */

    /** @return array{0:int,1:string|null} código y cuerpo */
    private static function http(string $url, string $cuerpo, array $cabeceras): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$codigo, $resp === false ? null : (string)$resp];
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function b64uDec(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $r = strlen($s) % 4;
        if ($r) {
            $s .= str_repeat('=', 4 - $r);
        }
        return (string)base64_decode($s);
    }
}
