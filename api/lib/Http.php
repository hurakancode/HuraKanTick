<?php
/**
 * Utilidades HTTP: entrada JSON, salida JSON, CORS y errores.
 */
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    public array $extra;
    public function __construct(string $mensaje, int $codigo = 400, array $extra = [])
    {
        parent::__construct($mensaje, $codigo);
        $this->extra = $extra;
    }
}

final class Http
{
    private static ?array $body = null;

    public static function cabecerasSeguridad(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-site');
        header_remove('X-Powered-By');
    }

    public static function cors(array $origenes): void
    {
        self::cabecerasSeguridad();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $ok = in_array('*', $origenes, true) || in_array($origin, $origenes, true);
            if ($ok) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: true');
            }
        }
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Dispositivo, X-Plataforma');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Cuerpo de la petición: JSON o formulario, indistintamente. */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($tipo, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $j   = json_decode($raw, true);
            self::$body = is_array($j) ? $j : [];
        } elseif (!empty($_POST)) {
            self::$body = $_POST;
        } else {
            $raw = file_get_contents('php://input') ?: '';
            $j   = json_decode($raw, true);
            if (is_array($j)) {
                self::$body = $j;
            } else {
                parse_str($raw, $p);
                self::$body = is_array($p) ? $p : [];
            }
        }
        return self::$body;
    }

    public static function campo(string $nombre, $def = null)
    {
        $b = self::body();
        return array_key_exists($nombre, $b) ? $b[$nombre] : $def;
    }

    public static function requerido(string $nombre): string
    {
        $v = self::campo($nombre);
        if ($v === null || (is_string($v) && trim($v) === '')) {
            throw new ApiError("Falta el campo «{$nombre}».", 422, ['campo' => $nombre]);
        }
        /* Si llega una lista o un objeto donde se esperaba un texto, se
           dice. Antes se convertía a la fuerza y PHP guardaba la palabra
           «Array» —además de dejar un aviso en el registro del servidor
           por cada intento—. Llega de verdad: un móvil con la aplicación
           a medio actualizar manda el campo con otra forma. */
        if (is_array($v) || is_object($v)) {
            throw new ApiError("El campo «{$nombre}» tiene que ser un texto.",
                422, ['campo' => $nombre]);
        }
        return is_string($v) ? trim($v) : (string)$v;
    }

    /**
     * Un campo como texto, sin sorpresas.
     *
     * Para los sitios donde da igual lo que manden: una lista o un objeto
     * donde iba un texto se quedan en vacío en vez de convertirse en la
     * palabra «Array», que es lo que acababa guardado en la base.
     */
    public static function texto(string $nombre, string $def = ''): string
    {
        $v = self::campo($nombre, $def);
        if (is_array($v) || is_object($v) || $v === null) {
            return $def;
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }
        return is_string($v) ? trim($v) : (string)$v;
    }

    public static function query(string $nombre, $def = null)
    {
        return $_GET[$nombre] ?? $def;
    }

    public static function queryInt(string $nombre, ?int $def = null): ?int
    {
        $v = $_GET[$nombre] ?? null;
        if ($v === null || $v === '') { return $def; }
        return (int)$v;
    }

    public static function json($datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function error(string $mensaje, int $codigo = 400, array $extra = []): void
    {
        self::json(array_merge(['ok' => false, 'error' => $mensaje], $extra), $codigo);
    }

    public static function ok($datos = [], int $codigo = 200): void
    {
        if (is_array($datos) && !isset($datos['ok'])) {
            $datos = array_merge(['ok' => true], $datos);
        }
        self::json($datos, $codigo);
    }

    /** Convierte cualquier valor a entero positivo o null. */
    public static function id($v): ?int
    {
        if ($v === null || $v === '' || $v === 'null') { return null; }
        $n = (int)$v;
        return $n > 0 ? $n : null;
    }

    public static function bool($v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'si', 'sí', 'on'], true);
    }

    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    public static function esUuid(?string $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }
}
