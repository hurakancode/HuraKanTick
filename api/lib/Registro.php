<?php
/**
 * Registro de seguridad.
 *
 * Anota quién entra, desde dónde y qué cambia. Sirve para dos cosas muy
 * distintas: reconstruir un acceso indebido, y demostrar que algo se
 * hizo cuando alguien dice que no.
 *
 * Guarda la dirección IP, que es un dato personal. Por eso se conserva
 * un tiempo limitado y se borra sola: tenerla para siempre no aporta
 * nada y sí obliga a más.
 */
declare(strict_types=1);

final class Registro
{
    public const TIPOS = [
        'acceso'         => 'Entrada',
        'acceso_fallido' => 'Intento fallido',
        'salida'         => 'Salida',
        'password'       => 'Cambio de contraseña',
        'correo'         => 'Cambio de correo',
        'recuperacion'   => 'Restablecimiento',
        'bloqueo'        => 'Cuenta bloqueada',
        'desbloqueo'     => 'Cuenta desbloqueada',
        'alta_usuario'   => 'Alta de persona',
        'rol'            => 'Cambio de permisos',
        'ajuste'         => 'Cambio de configuración',
        'copia'          => 'Copia de seguridad',
        'restauracion'   => 'Restauración',
        'mantenimiento'  => 'Modo mantenimiento',
        'borrado'        => 'Borrado de datos',
    ];

    /** Dirección de quien llama, teniendo en cuenta los proxis. */
    public static function ip(): string
    {
        // Con un proxy delante, REMOTE_ADDR es el del proxy. Se mira la
        // cabecera reenviada, pero sólo la primera dirección: el resto
        // las puede poner el cliente y no son de fiar.
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $c) {
            if (!empty($_SERVER[$c])) {
                $primera = trim(explode(',', (string)$_SERVER[$c])[0]);
                if (filter_var($primera, FILTER_VALIDATE_IP)) {
                    return $primera;
                }
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function agente(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    /** Anota un hecho. Nunca lanza excepción: no puede tumbar la acción. */
    /**
     * Deja constancia de algo que importa.
     *
     * «idLogin» es a quién le pasó —de quién es el rol que cambió, la
     * contraseña que se tocó—. «idAutor» es quién lo hizo, que muchas
     * veces es otra persona.
     *
     * Sin separarlos, la pregunta más útil de un registro de seguridad
     * —«¿qué hizo fulano?»— no se puede contestar: sus cambios sobre
     * los demás aparecen bajo el nombre del otro. Si no se dice quién
     * fue, se toma de la sesión.
     */
    public static function anotar(
        string $tipo,
        ?int $idLogin = null,
        string $detalle = '',
        bool $exito = true,
        ?string $correo = null,
        ?int $idAutor = null
    ): void {
        try {
            if (!Db::tablaExiste('registro_seguridad')) {
                return;
            }

            if ($idAutor === null && class_exists('Auth')) {
                $yo = Auth::usuario();
                $idAutor = $yo !== null ? (int)$yo['id_login'] : null;
            }

            $fila = [
                'tipo'     => mb_substr($tipo, 0, 24),
                'id_login' => $idLogin,
                'correo'   => $correo !== null ? mb_substr($correo, 0, 190) : null,
                'ip'       => mb_substr(self::ip(), 0, 45),
                'agente'   => self::agente(),
                'detalle'  => mb_substr($detalle, 0, 500) ?: null,
                'exito'    => $exito ? 1 : 0,
                'fecha'    => Db::now(),
            ];

            // La columna es de la 5.2: si aún no está, se anota sin ella.
            if ($idAutor !== null && in_array('id_autor', Db::columnas('registro_seguridad'), true)) {
                $fila['id_autor'] = $idAutor;
            }

            Db::insert('registro_seguridad', $fila);

            // Limpieza oportunista: la tabla no puede crecer sin control
            // y los datos personales tienen fecha de caducidad.
            if (random_int(1, 100) === 1) {
                self::limpiar();
            }
        } catch (Throwable) {
            // Un fallo al registrar no debe impedir la operación.
        }
    }

    public static function limpiar(): int
    {
        $dias = (int)(class_exists('Permisos') ? Permisos::ajuste('registro_dias', 90) : 90);
        if ($dias <= 0) {
            return 0;
        }
        $corte = gmdate('Y-m-d H:i:s', time() - $dias * 86400);
        return Db::run('DELETE FROM registro_seguridad WHERE fecha < ?', [$corte])->rowCount();
    }

    /** Consulta con filtros, para la pantalla de administración. */
    public static function consultar(array $f = []): array
    {
        if (!Db::tablaExiste('registro_seguridad')) {
            return ['total' => 0, 'registros' => []];
        }
        $where = ['1 = 1'];
        $p = [];

        if (!empty($f['tipo'])) {
            $where[] = 'r.tipo = :tipo';
            $p['tipo'] = (string)$f['tipo'];
        }
        if (!empty($f['id_login'])) {
            $where[] = 'r.id_login = :quien';
            $p['quien'] = (int)$f['id_login'];
        }
        if (!empty($f['ip'])) {
            $where[] = 'r.ip LIKE :ip';
            $p['ip'] = '%' . (string)$f['ip'] . '%';
        }
        if (!empty($f['solo_fallos'])) {
            $where[] = 'r.exito = 0';
        }
        if (!empty($f['desde'])) {
            $where[] = 'r.fecha >= :desde';
            $p['desde'] = (string)$f['desde'];
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);
        $limite = min(max((int)($f['limite'] ?? 100), 1), 500);
        $offset = max((int)($f['offset'] ?? 0), 0);

        $total = (int)Db::value('SELECT COUNT(*) FROM registro_seguridad r' . $sqlWhere, $p);
        $nombre = Db::nombreCompleto('l');

        $filas = Db::all(
            "SELECT r.*, $nombre AS persona
               FROM registro_seguridad r
               LEFT JOIN login l ON l.id_login = r.id_login
             $sqlWhere
              ORDER BY r.fecha DESC, r.id_registro DESC
              LIMIT $limite OFFSET $offset",
            $p
        );
        foreach ($filas as &$r) {
            $r['id_registro'] = (int)$r['id_registro'];
            $r['id_login'] = $r['id_login'] === null ? null : (int)$r['id_login'];
            $r['exito'] = (int)$r['exito'];
            $r['tipo_nombre'] = self::TIPOS[$r['tipo']] ?? $r['tipo'];
        }

        return ['total' => $total, 'registros' => $filas, 'tipos' => self::TIPOS];
    }

    /** Resumen para la pantalla: lo que merece una mirada. */
    public static function resumen(): array
    {
        if (!Db::tablaExiste('registro_seguridad')) {
            return [];
        }
        $dia = gmdate('Y-m-d H:i:s', time() - 86400);
        $semana = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

        return [
            'accesos_hoy'   => (int)Db::value(
                "SELECT COUNT(*) FROM registro_seguridad WHERE tipo = 'acceso' AND fecha >= ?", [$dia]),
            'fallos_hoy'    => (int)Db::value(
                "SELECT COUNT(*) FROM registro_seguridad WHERE tipo = 'acceso_fallido' AND fecha >= ?", [$dia]),
            'fallos_semana' => (int)Db::value(
                "SELECT COUNT(*) FROM registro_seguridad WHERE tipo = 'acceso_fallido' AND fecha >= ?", [$semana]),
            'total'         => (int)Db::value('SELECT COUNT(*) FROM registro_seguridad'),
            // Direcciones con muchos fallos: es lo que delata un intento
            // de entrar probando contraseñas.
            'ips_sospechosas' => Db::all(
                "SELECT ip, COUNT(*) AS n, MAX(fecha) AS ultima
                   FROM registro_seguridad
                  WHERE tipo = 'acceso_fallido' AND fecha >= ?
                  GROUP BY ip HAVING COUNT(*) >= 5
                  ORDER BY n DESC LIMIT 10", [$semana]),
        ];
    }
}

/* ==================================================================== */

/**
 * Modo mantenimiento.
 *
 * Cierra el acceso a todo el mundo menos a los administradores, para
 * poder actualizar o restaurar una copia sin que nadie escriba a la vez.
 * Se comprueba en cada petición porque es más barato que las
 * consecuencias de no hacerlo.
 */
final class Mantenimiento_Modo
{
    public static function activo(): bool
    {
        return (bool)Permisos::ajuste('modo_mantenimiento', false);
    }

    public static function mensaje(): string
    {
        return (string)Permisos::ajuste(
            'mantenimiento_mensaje',
            'Estamos actualizando la aplicación. Volvemos en unos minutos.'
        );
    }

    /**
     * Corta la petición si procede. Se deja pasar:
     *   · a los administradores, que son quienes lo activaron
     *   · el diagnóstico y la propia entrada, para poder volver a entrar
     *   · la sincronización de subida, para que nadie pierda lo que
     *     tenga guardado en el móvil sin enviar
     */
    public static function comprobar(string $ruta, ?array $usuario): void
    {
        if (!self::activo()) {
            return;
        }
        if ($usuario !== null && Auth::esAdmin($usuario)) {
            return;
        }
        $permitidas = ['estado', 'auth/login', 'auth/yo', 'auth/logout', 'sync/push'];
        foreach ($permitidas as $p) {
            if ($ruta === $p || str_starts_with($ruta, $p . '/')) {
                return;
            }
        }
        throw new ApiError(self::mensaje(), 503, ['mantenimiento' => true]);
    }
}
