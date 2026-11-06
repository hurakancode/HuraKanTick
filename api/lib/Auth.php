<?php
/**
 * Autenticación por token portador (Bearer).
 *
 * Se usa token en cabecera —y no cookies— porque la app Android empaquetada
 * con Capacitor se sirve desde otro origen y las cookies de terceros no son
 * fiables. El token viaja en Authorization: Bearer <token>; en la base de
 * datos sólo se guarda su hash SHA-256.
 */
declare(strict_types=1);

final class Auth
{
    private static array $cfg = [];
    private static ?array $usuario = null;

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    /**
     * El nombre de usuario, tal como se compara al entrar.
     *
     * Minúsculas y sin acentos: quien se llama «Pisos» tiene que poder
     * entrar escribiendo «pisos», y quien se llama «recepción» también
     * escribiendo «recepcion», que es lo que sale de un teclado con
     * prisa. Se dejan letras, cifras, punto, guión y guión bajo; lo
     * demás se cae, porque un nombre con espacios o eñes se escribe mal
     * la mitad de las veces.
     */
    public static function normalizarUsuario(string $bruto): string
    {
        $t = mb_strtolower(trim($bruto), 'UTF-8');

        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        $t = preg_replace('/[^a-z0-9._-]+/', '', $t) ?? '';

        return mb_substr($t, 0, 60);
    }

    public static function hashPassword(string $plana): string
    {
        return password_hash($plana, PASSWORD_BCRYPT, ['cost' => self::$cfg['bcrypt_cost'] ?? 12]);
    }

    public static function verificarPassword(string $plana, string $hash): bool
    {
        // Los usuarios migrados pueden tener el hash antiguo; password_verify
        // devuelve false en ese caso y el acceso se deniega con normalidad.
        return $hash !== '' && password_verify($plana, $hash);
    }

    /** Emite un token nuevo y devuelve la cadena en claro (sólo se ve una vez). */
    public static function emitirToken(int $idLogin, string $dispositivo = '', string $plataforma = ''): array
    {
        $token = bin2hex(random_bytes(32));
        $dias  = (int)(self::$cfg['token_dias'] ?? 30);
        Db::insert('api_tokens', [
            'id_login'    => $idLogin,
            'token_hash'  => hash('sha256', $token),
            'dispositivo' => mb_substr($dispositivo, 0, 120),
            'plataforma'  => mb_substr($plataforma, 0, 40),
            'creado'      => Db::now(),
            'ultimo_uso'  => Db::now(),
            'expira'      => gmdate('Y-m-d H:i:s', time() + $dias * 86400),
            'revocado'    => 0,
        ]);
        return ['token' => $token, 'expira' => gmdate('c', time() + $dias * 86400)];
    }

    public static function revocarToken(string $token): void
    {
        Db::run('UPDATE api_tokens SET revocado = 1 WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    public static function tokenDeCabecera(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($h === '' && function_exists('apache_request_headers')) {
            $hs = apache_request_headers();
            foreach ($hs as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
            }
        }
        if (preg_match('/Bearer\s+(\S+)/i', (string)$h, $m)) {
            return $m[1];
        }
        /* Aquí había una puerta de atrás: si no venía la cabecera, se
         * aceptaba ?token=... en la dirección. El comentario decía que
         * era «para descargas directas de imágenes desde <img src>»,
         * pero eso ya no era verdad —las fotos se piden por su ruta de
         * fichero, sin token— y la usaba una sola cosa: el enlace de
         * descarga de las copias de seguridad.
         *
         * El precio era alto. Una dirección queda escrita en todas
         * partes: en el registro de Apache aparecieron 134 líneas con
         * tokens de administrador completos y en claro, que valen 30
         * días. Más el historial del navegador y la cabecera Referer.
         * Y como se aceptaba en CUALQUIER ruta, bastaba con que alguna
         * pantalla volviera a hacerlo para repetir la fuga.
         *
         * Las descargas van ahora con un vale de 60 segundos atado a la
         * ruta y a la persona (ver lib/Descargas.php), así que esto ya
         * no hace falta y se quita: la sesión sólo viaja por cabecera. */
        return null;
    }

    /** Usuario autenticado o null. No lanza excepción. */
    public static function usuario(): ?array
    {
        if (self::$usuario !== null) {
            return self::$usuario;
        }
        $token = self::tokenDeCabecera();
        if ($token === null) {
            return null;
        }
        $fila = Db::one(
            'SELECT t.id_token, l.*
               FROM api_tokens t
               JOIN login l ON l.id_login = t.id_login
              WHERE t.token_hash = ? AND t.revocado = 0
                AND (t.expira IS NULL OR t.expira > ?)
                AND l.bloqueo = 0',
            [hash('sha256', $token), Db::now()]
        );
        if ($fila === null) {
            return null;
        }
        Db::run('UPDATE api_tokens SET ultimo_uso = ? WHERE id_token = ?', [Db::now(), (int)$fila['id_token']]);
        unset($fila['pwd_php'], $fila['pwd_sha2']);
        self::$usuario = $fila;
        return $fila;
    }

    /** Usuario autenticado o error 401. */
    public static function exigir(): array
    {
        $u = self::usuario();
        if ($u === null) {
            throw new ApiError('Sesión no válida o caducada. Vuelve a entrar.', 401);
        }
        return $u;
    }

    /** Exige responsable o administrador. */
    public static function exigirResponsable(): array
    {
        $u = self::exigir();
        if (!self::esResponsable($u)) {
            throw new ApiError('Hace falta ser responsable para esto.', 403);
        }
        return $u;
    }

    public static function exigirAdmin(): array
    {
        $u = self::exigir();
        if (!self::esAdmin($u)) {
            throw new ApiError('Esta acción requiere permisos de administrador.', 403);
        }
        return $u;
    }

    public static function esAdmin(array $u): bool
    {
        return (int)($u['admin'] ?? 0) === 1 || ($u['rol'] ?? '') === 'admin';
    }

    /** Responsable: puede asignar y cerrar tickets de cualquiera. */
    public static function esResponsable(array $u): bool
    {
        return self::esAdmin($u) || ($u['rol'] ?? '') === 'responsable';
    }

    public static function puedeEditarTicket(array $u, array $ticket): bool
    {
        if (self::esResponsable($u)) { return true; }
        $id = (int)$u['id_login'];
        return (int)($ticket['id_persona_asignada'] ?? 0) === $id
            || (int)($ticket['id_realiza_accion'] ?? 0) === $id;
    }
}
