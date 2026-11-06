<?php
/**
 * Enlaces de auto-entrada.
 *
 * Un enlace que deja entrar sin escribir nada. Se pensó para la tablet
 * fija de recepción y para la gente que no se acuerda de ninguna
 * contraseña, que en un hotel es más de la que parece.
 *
 * ES UNA LLAVE, NO UN ATAJO. Quien tenga el enlace ES esa persona: no
 * hay segundo factor ni nada que preguntar. De ahí las cinco
 * precauciones:
 *
 *   1. El enlace no se guarda. Se guarda su resumen, como una
 *      contraseña. Si alguien se lleva la base de datos no se lleva las
 *      llaves.
 *   2. Hay un interruptor general. Apagarlo tumba todos los enlaces del
 *      hotel a la vez, sin tocar cuenta por cuenta.
 *   3. Y uno por persona, para el caso normal: se le da a recepción y a
 *      nadie más.
 *   4. Regenerar invalida el anterior al instante. Es lo que se hace
 *      cuando se pierde una tablet.
 *   5. Cada uso queda anotado con su dirección. Un enlace que se usa de
 *      madrugada desde fuera del hotel se ve.
 *
 * La dirección es larga a propósito. Lleva cuatro trozos:
 *
 *   /www/entrar.html?u=<id>&k=<clave>&h=<hotel>&v=<versión>&t=<momento>
 *
 * El «u» dice a quién buscar —sin él habría que comparar el resumen
 * contra todas las filas— y el «k» es la llave de verdad: 48 bytes de
 * azar, 64 caracteres. Los otros tres no dan permisos; sirven para que
 * el enlace se explique solo cuando aparece pegado en un correo viejo.
 */
declare(strict_types=1);

class AutoEntrada
{
    /** Bytes de azar de la llave. 48 son 384 bits: no se adivina. */
    private const BYTES = 48;

    /* ---------------------------------------------------------------- */
    /* Consultar                                                        */
    /* ---------------------------------------------------------------- */

    /** ¿Están permitidos los enlaces en este hotel? */
    public static function permitidos(): bool
    {
        // Encendido de fábrica: ver el ajuste en Configuración → Seguridad.
        return (bool)Permisos::ajuste('autologin_global', true);
    }

    /** Días que vale un enlace. 0 = no caduca. */
    public static function dias(): int
    {
        return max(0, (int)Permisos::ajuste('autologin_dias', 0));
    }

    /** ¿Tiene esta persona un enlace activo y utilizable? */
    public static function activoPara(array $u): bool
    {
        return self::permitidos()
            && (int)($u['autologin_activo'] ?? 0) === 1
            && ($u['autologin_id'] ?? '') !== ''
            && !self::caducado($u);
    }

    private static function caducado(array $u): bool
    {
        $dias = self::dias();
        if ($dias === 0) { return false; }

        $creado = (string)($u['autologin_creado'] ?? '');
        if ($creado === '') { return true; }

        return strtotime($creado) < (time() - $dias * 86400);
    }

    /* ---------------------------------------------------------------- */
    /* Crear                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Genera un enlace nuevo y anula el anterior.
     *
     * Devuelve la dirección completa UNA sola vez: como sólo se guarda
     * el resumen, si no se copia ahora hay que volver a generarla. Eso
     * es a propósito, igual que con las contraseñas.
     */
    public static function generar(int $idLogin, int $idAutor, string $base = ''): array
    {
        $u = Db::one('SELECT * FROM login WHERE id_login = ?', [$idLogin]);
        if ($u === null) {
            throw new ApiError('Esa persona no existe.', 404);
        }

        $clave = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
        $id    = bin2hex(random_bytes(9));      // 18 caracteres, público

        Db::update('login', [
            'autologin_id'         => $id,
            'autologin_hash'       => hash('sha256', $clave),
            'autologin_activo'     => 1,
            'autologin_creado'     => Db::now(),
            'autologin_ultimo_uso' => null,
            'autologin_usos'       => 0,
            'updated_at'           => Db::now(),
        ], 'id_login = :id', ['id' => $idLogin]);

        Registro::anotar('autologin', $idLogin,
            'Enlace de auto-entrada generado (el anterior deja de valer)',
            true, (string)($u['correo'] ?? ''), $idAutor);

        return [
            'url'     => self::url($base, $id, $clave, (int)($u['id_hotel'] ?? 0)),
            'creado'  => Db::now(),
            'caduca'  => self::dias() === 0
                ? null
                : gmdate('Y-m-d H:i:s', time() + self::dias() * 86400),
        ];
    }

    /** Arma la dirección con sus cinco parámetros. */
    private static function url(string $base, string $id, string $clave, int $hotel): string
    {
        $base = rtrim($base, '/');
        if ($base === '') { $base = '.'; }

        return $base . '/entrar.html?' . http_build_query([
            'u' => $id,
            'k' => $clave,
            'h' => $hotel > 0 ? $hotel : 1,
            'v' => 1,                       // versión del formato
            't' => time(),                  // cuándo se generó
        ]);
    }

    /** Apaga o enciende el enlace de alguien, sin regenerarlo. */
    public static function activar(int $idLogin, bool $si, int $idAutor): void
    {
        $u = Db::one('SELECT correo, autologin_id FROM login WHERE id_login = ?', [$idLogin]);
        if ($u === null) {
            throw new ApiError('Esa persona no existe.', 404);
        }
        if ($si && ($u['autologin_id'] ?? '') === '') {
            throw new ApiError('Esa persona todavía no tiene enlace: genera uno primero.', 409);
        }

        Db::update('login',
            ['autologin_activo' => $si ? 1 : 0, 'updated_at' => Db::now()],
            'id_login = :id', ['id' => $idLogin]);

        Registro::anotar('autologin', $idLogin,
            $si ? 'Auto-entrada habilitada' : 'Auto-entrada deshabilitada',
            true, (string)($u['correo'] ?? ''), $idAutor);
    }

    /** Borra el enlace del todo. */
    public static function retirar(int $idLogin, int $idAutor): void
    {
        $u = Db::one('SELECT correo FROM login WHERE id_login = ?', [$idLogin]);

        Db::update('login', [
            'autologin_id'     => null,
            'autologin_hash'   => null,
            'autologin_activo' => 0,
            'updated_at'       => Db::now(),
        ], 'id_login = :id', ['id' => $idLogin]);

        Registro::anotar('autologin', $idLogin, 'Enlace de auto-entrada retirado',
            true, (string)($u['correo'] ?? ''), $idAutor);
    }

    /* ---------------------------------------------------------------- */
    /* Usar                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Comprueba un enlace y devuelve a quién pertenece.
     *
     * Falla siempre con el mismo mensaje y tarda lo mismo: un enlace
     * inventado no puede distinguirse de uno desactivado, porque eso
     * diría qué cuentas existen.
     */
    public static function comprobar(string $id, string $clave): array
    {
        $generico = 'Este enlace ya no vale. Pídele otro a tu responsable.';

        // Un respiro fijo, para que acertar y fallar tarden parecido.
        usleep(random_int(120000, 260000));

        if (!self::permitidos()) {
            throw new ApiError(
                'Los enlaces de auto-entrada están desactivados en este hotel.', 403);
        }
        if ($id === '' || $clave === '') {
            throw new ApiError($generico, 401);
        }

        $u = Db::one('SELECT * FROM login WHERE autologin_id = ?', [$id]);

        // hash_equals compara en tiempo constante: no se puede averiguar
        // la llave a base de medir cuánto tarda en decir que no.
        $vale = $u !== null
             && (string)($u['autologin_hash'] ?? '') !== ''
             && hash_equals((string)$u['autologin_hash'], hash('sha256', $clave));

        if (!$vale) {
            Registro::anotar('autologin_fallido', $u ? (int)$u['id_login'] : null,
                'Enlace de auto-entrada no válido', false, '');
            throw new ApiError($generico, 401);
        }
        if ((int)$u['autologin_activo'] !== 1) {
            Registro::anotar('autologin_fallido', (int)$u['id_login'],
                'Enlace desactivado para esta persona', false, (string)$u['correo']);
            throw new ApiError($generico, 401);
        }
        if (self::caducado($u)) {
            Registro::anotar('autologin_fallido', (int)$u['id_login'],
                'Enlace caducado', false, (string)$u['correo']);
            throw new ApiError('Este enlace ha caducado. Pídele otro a tu responsable.', 401);
        }
        if ((int)$u['bloqueo'] === 1) {
            throw new ApiError('Esta cuenta está bloqueada. Contacta con el administrador.', 403);
        }

        Db::run('UPDATE login SET autologin_ultimo_uso = ?, autologin_usos = autologin_usos + 1,
                 intentos = 0, ultimo_acceso = ? WHERE id_login = ?',
                [Db::now(), Db::now(), (int)$u['id_login']]);

        /* Se anota SIEMPRE, y se nota que fue por enlace. Una entrada
           normal y una por enlace no son lo mismo el día que hay que
           mirar quién estuvo dentro. */
        Registro::anotar('acceso_autologin', (int)$u['id_login'],
            'Entrada con enlace de auto-entrada', true, (string)$u['correo']);

        return $u;
    }
}
