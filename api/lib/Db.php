<?php
/**
 * Capa de acceso a datos. Una sola conexión PDO reutilizada,
 * consultas siempre preparadas y compatibilidad MySQL / SQLite.
 */
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function init(array $cfg): void
    {
        self::$driver = $cfg['driver'];

        if (self::$driver === 'sqlite') {
            $path = $cfg['sqlite_path'];
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], (int)$cfg['port'], $cfg['database'], $cfg['charset']
            );
            $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
            ]);
        }

        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('La base de datos no está inicializada.');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    /** Marca temporal UTC en el formato que usan todas las tablas. */
    /**
     * Parte un guion SQL en sentencias.
     *
     * Se hace a mano en vez de con explode(';') porque un punto y coma
     * dentro de una cadena parte la sentencia por la mitad y el resto se
     * pierde en silencio. Pasó de verdad con un texto de ayuda que decía
     * «puede pedirlo; un administrador le entrega el código»: la mitad
     * de los ajustes no se creaban y no había ningún error.
     */
    public static function sentencias(string $sql): array
    {
        // Fuera los comentarios de línea, pero sólo los que la empiezan:
        // un «--» dentro de una cadena es texto, no un comentario.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $fuera   = [];
        $actual  = '';
        $comilla = null;
        $n = strlen($sql);

        for ($i = 0; $i < $n; $i++) {
            $c = $sql[$i];

            if ($comilla !== null) {
                $actual .= $c;
                if ($c === $comilla) {
                    // Dos comillas seguidas son una comilla escapada
                    if ($i + 1 < $n && $sql[$i + 1] === $comilla) {
                        $actual .= $sql[++$i];
                    } else {
                        $comilla = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $comilla = $c;
                $actual .= $c;
                continue;
            }

            if ($c === ';') {
                $t = trim($actual);
                if ($t !== '') { $fuera[] = $t; }
                $actual = '';
                continue;
            }

            $actual .= $c;
        }

        $t = trim($actual);
        if ($t !== '') { $fuera[] = $t; }

        return $fuera;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Sólo la parte de fecha de una columna, en cualquiera de los dos motores. */
    public static function comoFecha(string $columna): string
    {
        return self::driver() === 'mysql' ? "DATE($columna)" : "date($columna)";
    }

    /** Ordenar al azar: SQLite y MySQL no lo escriben igual. */
    public static function alAzar(): string
    {
        return self::driver() === 'mysql' ? 'RAND()' : 'RANDOM()';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with((string)$k, ':') ? $k : ':' . $k);
            if (is_int($v))      { $st->bindValue($key, $v, PDO::PARAM_INT); }
            elseif (is_bool($v)) { $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT); }
            elseif ($v === null) { $st->bindValue($key, null, PDO::PARAM_NULL); }
            else                 { $st->bindValue($key, (string)$v, PDO::PARAM_STR); }
        }
        /* Registro de consultas lentas.
         *
         * Apagado salvo que se encienda «consultas_lentas_ms» en la
         * configuración. Sirve para saber POR QUÉ una pantalla tarda, en
         * vez de adivinar: se anota la consulta y lo que ha tardado en el
         * registro de errores de PHP. */
        if (self::$avisarLentas > 0) {
            $t0 = microtime(true);
            $st->execute();
            $ms = (microtime(true) - $t0) * 1000;
            if ($ms >= self::$avisarLentas) {
                error_log(sprintf('HuraKan Tick · consulta lenta · %.0f ms · %s',
                    $ms, preg_replace('/\s+/', ' ', substr($sql, 0, 300))));
            }
            return $st;
        }

        $st->execute();
        return $st;
    }

    /** Milisegundos a partir de los cuales se anota una consulta. 0 = nunca. */
    private static float $avisarLentas = 0;

    public static function avisarConsultasLentas(float $ms): void
    {
        self::$avisarLentas = max(0, $ms);
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $params = [])
    {
        $r = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    public static function insert(string $tabla, array $datos): int
    {
        $cols = array_keys($datos);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tabla,
            implode(',', $cols),
            implode(',', array_map(static fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $datos);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $tabla, array $datos, string $where, array $wp = []): int
    {
        $sets = implode(',', array_map(static fn($c) => "$c = :s_$c", array_keys($datos)));
        $params = [];
        foreach ($datos as $k => $v) { $params['s_' . $k] = $v; }
        foreach ($wp as $k => $v)    { $params[$k] = $v; }
        return self::run("UPDATE $tabla SET $sets WHERE $where", $params)->rowCount();
    }

    /**
     * Abrir una transacción.
     *
     * En SQLite se pide el cerrojo de escritura POR DELANTE («BEGIN
     * IMMEDIATE») en vez de dejar que PDO abra una transacción diferida.
     * La diferencia no se ve con cuatro personas y se ve muchísimo con
     * cincuenta.
     *
     * Con una transacción diferida pasa esto: la primera consulta coge
     * un cerrojo de lectura, y cuando toca escribir hay que subirlo a
     * cerrojo de escritura. Si en ese momento otro ya está escribiendo,
     * SQLite NO espera —falla al instante con «database is locked»—, y
     * no espera a propósito: esperar con un cerrojo de lectura en la
     * mano puede dejar a dos bloqueados para siempre. La espera de cinco
     * segundos que hay configurada no sirve de nada en ese caso.
     *
     * Medido con cincuenta sesiones a la vez durante cuarenta segundos:
     * 269 peticiones de 10.191 se caían con «database is locked». Una de
     * cada cuarenta, y cada una es una nota o un parte que la persona da
     * por escrito. Pidiendo el cerrojo por delante, la espera sí cuenta
     * y el que llega segundo aguarda su turno en vez de fallar.
     *
     * En MySQL no hace falta: su control de concurrencia es otro.
     */
    public static function begin(): void
    {
        if (self::driver() === 'sqlite') {
            self::pdo()->exec('BEGIN IMMEDIATE');
            return;
        }
        self::pdo()->beginTransaction();
    }
    public static function commit(): void
    {
        // Con BEGIN IMMEDIATE lanzado a mano, PDO no sabe que hay una
        // transacción abierta, así que el COMMIT también va a mano.
        if (self::driver() === 'sqlite' && !self::pdo()->inTransaction()) {
            self::pdo()->exec('COMMIT');
            return;
        }
        self::pdo()->commit();
    }
    /** ¿Hay conexión? Falso si Db::init no llegó a conectar. */
    public static function lista(): bool
    {
        return self::$pdo !== null;
    }

    public static function rollback(): void
    {
        /* Sin conexión no hay nada que deshacer. Esto lo llama el manejador
           de errores de index.php, también cuando el error ES que la base
           no conecta: entonces reventaba a su vez («La base de datos no está
           inicializada») y la respuesta salía vacía, un 500 sin JSON. */
        if (self::$pdo === null) { return; }
        if (self::pdo()->inTransaction()) { self::pdo()->rollBack(); return; }
        // Igual que arriba: la abrió «BEGIN IMMEDIATE», no PDO.
        if (self::driver() === 'sqlite') {
            try { self::pdo()->exec('ROLLBACK'); } catch (Throwable $e) { /* no había */ }
        }
    }

    /** ¿Existe la tabla? Útil para el instalador y los diagnósticos. */
    /**
     * Todas las tablas de la base, sea el motor que sea.
     *
     * Hace falta para que la copia de seguridad las descubra sola en vez
     * de trabajar con una lista escrita a mano que se queda vieja en
     * cuanto se añade una tabla.
     */
    /** Los nombres de columna de una tabla, sea el motor que sea. */
    public static function columnas(string $tabla): array
    {
        $out = [];
        if (self::driver() === 'mysql') {
            foreach (self::all("SHOW COLUMNS FROM `$tabla`") as $f) {
                $out[] = (string)($f['Field'] ?? reset($f));
            }
        } else {
            foreach (self::all("PRAGMA table_info(\"$tabla\")") as $f) {
                $out[] = (string)$f['name'];
            }
        }
        return $out;
    }

    /**
     * «Mete esto, y si ya está no hagas nada.»
     *
     * Cada motor lo escribe distinto: SQLite usa INSERT OR IGNORE y
     * MySQL INSERT IGNORE. Escribir la de SQLite en MySQL no da un error
     * claro, da un «syntax error» a secas, y el rastro apunta a la línea
     * de PDO, no a la consulta. Con esto se escribe una vez y vale para
     * los dos.
     */
    public static function insertarSiFalta(string $tabla, array $datos): void
    {
        $cols = array_keys($datos);
        $marc = array_map(static fn($c) => ':' . $c, $cols);

        $sql = self::driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO ' . $tabla
            : 'INSERT IGNORE INTO ' . $tabla;

        self::run($sql . ' (' . implode(', ', $cols) . ') VALUES ('
                  . implode(', ', $marc) . ')', $datos);
    }

    /**
     * Qué tablas apuntan a ésta, leído del esquema de verdad.
     *
     * Antes había una lista escrita a mano de lo que hay que limpiar
     * antes de borrar un parte, y se quedaba corta cada vez que se
     * añadía una tabla nueva: el borrado fallaba con un «FOREIGN KEY
     * constraint failed» que no le dice nada a nadie.
     *
     * Preguntándoselo a la base no hace falta acordarse de nada.
     *
     * @return array<int, array{tabla:string, columna:string, obligatoria:bool}>
     */
    /** La columna que identifica cada fila, si es una sola. */
    public static function clavePrimaria(string $tabla): ?string
    {
        if (self::driver() === 'mysql') {
            $f = self::one(
                "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'
                  LIMIT 1", [$tabla]);
            return $f === null ? null : (string)$f['c'];
        }

        foreach (self::all('PRAGMA table_info("' . $tabla . '")') as $col) {
            if ((int)($col['pk'] ?? 0) === 1) { return (string)$col['name']; }
        }
        return null;
    }

    public static function dependenciasDe(string $tabla): array
    {
        $salida = [];

        if (self::driver() === 'mysql') {
            $filas = self::all(
                'SELECT k.TABLE_NAME AS tabla, k.COLUMN_NAME AS columna,
                        c.IS_NULLABLE AS admite_nulo
                   FROM information_schema.KEY_COLUMN_USAGE k
                   JOIN information_schema.COLUMNS c
                     ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                    AND c.TABLE_NAME   = k.TABLE_NAME
                    AND c.COLUMN_NAME  = k.COLUMN_NAME
                  WHERE k.REFERENCED_TABLE_NAME = ?
                    AND k.TABLE_SCHEMA = DATABASE()', [$tabla]);

            foreach ($filas as $f) {
                $salida[] = [
                    'tabla'       => (string)$f['tabla'],
                    'columna'     => (string)$f['columna'],
                    'obligatoria' => strtoupper((string)$f['admite_nulo']) === 'NO',
                ];
            }
            return $salida;
        }

        foreach (self::tablas() as $otra) {
            foreach (self::all('PRAGMA foreign_key_list("' . $otra . '")') as $fk) {
                if (($fk['table'] ?? '') !== $tabla) { continue; }

                $columna = (string)($fk['from'] ?? '');
                $obligatoria = false;
                foreach (self::all('PRAGMA table_info("' . $otra . '")') as $col) {
                    if (($col['name'] ?? '') === $columna) {
                        $obligatoria = (int)($col['notnull'] ?? 0) === 1;
                    }
                }
                $salida[] = ['tabla' => $otra, 'columna' => $columna, 'obligatoria' => $obligatoria];
            }
        }
        return $salida;
    }

    public static function tablas(): array
    {
        $out = [];
        if (self::driver() === 'mysql') {
            foreach (self::all('SHOW TABLES') as $fila) {
                $out[] = (string)reset($fila);
            }
        } else {
            foreach (self::all(
                "SELECT name FROM sqlite_master
                  WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $fila) {
                $out[] = (string)$fila['name'];
            }
        }
        return $out;
    }

    public static function tablaExiste(string $tabla): bool
    {
        try {
            if (self::$driver === 'sqlite') {
                return (bool)self::value(
                    "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", [$tabla]
                );
            }
            return (bool)self::value(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$tabla]
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** Concatenación de nombre completo, portable entre motores. */
    public static function nombreCompleto(string $alias): string
    {
        $n = "$alias.nombre";
        $a1 = "$alias.apellido1";
        $a2 = "$alias.apellido2";
        if (self::$driver === 'sqlite') {
            return "TRIM(COALESCE($n,'')||' '||COALESCE($a1,'')||' '||COALESCE($a2,''))";
        }
        return "TRIM(CONCAT_WS(' ', NULLIF($n,''), NULLIF($a1,''), NULLIF($a2,'')))";
    }
}
