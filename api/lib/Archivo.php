<?php
/**
 * La papelera: una base de datos aparte para lo que se borra.
 *
 * Nada se pierde nunca del todo. Antes de borrar una fila de la base
 * viva, se copia a otra base con la misma estructura. Después se borra
 * aquí. En ese orden y dentro de una transacción: si la copia falla, el
 * borrado no llega a ocurrir.
 *
 * Por qué una base aparte y no una marca de «borrado»:
 *
 *   · La base viva se queda pequeña de verdad. Un hotel con diez años de
 *     partes no arrastra los borrados en cada consulta.
 *   · Un borrado es un borrado. Con marcas, tarde o temprano una
 *     consulta se olvida de filtrarlas y lo borrado reaparece.
 *   · Se puede mirar sin miedo: la papelera es de sólo lectura para
 *     todo el mundo salvo para restaurar.
 *
 * Se guarda la fila entera tal cual estaba, más quién la borró, cuándo y
 * por qué. Con eso se puede reconstruir.
 */
declare(strict_types=1);

final class Archivo
{
    private static ?PDO $pdo = null;
    private static array $clonadas = [];

    /** Columnas que se añaden a cada tabla archivada. */
    private const META = [
        'pap_id'      => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'pap_fecha'   => 'TEXT',
        'pap_autor'   => 'INTEGER',
        'pap_autor_n' => 'TEXT',
        'pap_motivo'  => 'TEXT',
        'pap_hotel'   => 'INTEGER',
    ];

    /**
     * Lo que nunca entra en la papelera.
     *
     * Una contraseña archivada sigue siendo una contraseña: si la
     * papelera acaba en un correo o en una copia de seguridad mal
     * guardada, se llevaría por delante las cuentas. Lo mismo con los
     * códigos de recuperación y los testigos de sesión.
     */
    private static function esSecreta(string $columna): bool
    {
        $c = mb_strtolower($columna);
        foreach (['pwd', 'password', 'contrasena', 'token', 'hash_', 'secreto', 'codigo_recuperacion'] as $x) {
            if (str_contains($c, $x)) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- */
    /* Conexión                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Dónde vive la papelera.
     *
     * Siempre en SQLite, aunque la base viva sea MySQL: es un almacén
     * frío, no se consulta a diario, y así no hace falta pedirle al
     * administrador de la base que cree un esquema nuevo ni dar
     * permisos de CREATE DATABASE.
     */
    public static function ruta(): string
    {
        global $config;
        $r = $config['db']['papelera'] ?? null;
        if ($r) {
            return $r;
        }
        $base = $config['db']['sqlite'] ?? (__DIR__ . '/../../db/hurakantick.sqlite');
        return preg_replace('/\.sqlite$/', '', $base) . '-papelera.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $ruta = self::ruta();
        $dir  = dirname($ruta);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        self::$pdo = new PDO('sqlite:' . $ruta, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA foreign_keys = OFF');   // se archivan filas sueltas
        self::crearIndice();
        return self::$pdo;
    }

    /** Un índice común para poder listar todo lo borrado de una vez. */
    private static function crearIndice(): void
    {
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS papelera_indice (
              id_entrada  INTEGER PRIMARY KEY AUTOINCREMENT,
              tabla       TEXT NOT NULL,
              id_original INTEGER,
              etiqueta    TEXT,
              resumen     TEXT,
              pap_id      INTEGER,          -- fila exacta en su tabla
              id_hotel    INTEGER,
              hotel       TEXT,
              motivo      TEXT,
              id_autor    INTEGER,
              autor       TEXT,
              fecha       TEXT NOT NULL,
              restaurado  INTEGER NOT NULL DEFAULT 0
            )");
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS ix_pap_fecha ON papelera_indice(fecha)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS ix_pap_tabla ON papelera_indice(tabla, id_original)');
    }

    /* ---------------------------------------------------------------- */
    /* Clonado de estructura                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Crea en la papelera una tabla con la misma forma que la viva.
     *
     * Se leen las columnas reales en vez de guardar un esquema fijo: así
     * una columna que se añada mañana se archiva igual, sin tener que
     * acordarse de tocar esto.
     */
    private static function clonar(string $tabla): void
    {
        if (isset(self::$clonadas[$tabla])) {
            return;
        }
        $pdo = self::pdo();

        $ya = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($tabla)
        )->fetchColumn();

        if (!$ya) {
            $cols = [];
            foreach (self::META as $c => $tipo) {
                $cols[] = "\"$c\" $tipo";
            }
            foreach (self::columnasDe($tabla) as $c) {
                if (self::esSecreta($c)) {
                    continue;      // ni la columna: lo que no está no se filtra
                }
                // Todo como TEXT salvo la clave: la papelera no valida,
                // sólo conserva. Un tipo estricto haría fallar el
                // archivado justo cuando más falta hace.
                $cols[] = '"' . $c . '" TEXT';
            }
            $pdo->exec('CREATE TABLE "' . $tabla . '" (' . implode(', ', $cols) . ')');
        } else {
            // La tabla viva pudo ganar columnas desde la última vez.
            $tiene = [];
            foreach ($pdo->query('PRAGMA table_info("' . $tabla . '")') as $r) {
                $tiene[$r['name']] = true;
            }
            foreach (self::columnasDe($tabla) as $c) {
                if (self::esSecreta($c)) {
                    continue;
                }
                if (!isset($tiene[$c])) {
                    $pdo->exec('ALTER TABLE "' . $tabla . '" ADD COLUMN "' . $c . '" TEXT');
                }
            }
        }
        self::$clonadas[$tabla] = true;
    }

    /** Nombres de columna de una tabla viva, sea MySQL o SQLite. */
    private static function columnasDe(string $tabla): array
    {
        $cols = [];
        if (Db::driver() === 'mysql') {
            foreach (Db::all("SHOW COLUMNS FROM `$tabla`") as $r) {
                $cols[] = $r['Field'];
            }
        } else {
            foreach (Db::all('PRAGMA table_info("' . $tabla . '")') as $r) {
                $cols[] = $r['name'];
            }
        }
        return $cols;
    }

    /* ---------------------------------------------------------------- */
    /* Guardar                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Copia una fila a la papelera. No la borra: eso lo hace quien llama,
     * después y dentro de su propia transacción.
     *
     * @param string $tabla    tabla viva de la que sale
     * @param array  $fila     la fila entera, tal cual está
     * @param array  $extra    etiqueta, resumen, motivo, id_hotel
     * @return int             id en el índice de la papelera
     */
    public static function guardar(string $tabla, array $fila, array $extra = []): int
    {
        self::clonar($tabla);
        $pdo = self::pdo();

        $u = Auth::usuario();
        $ahora = date('Y-m-d H:i:s');

        $datos = [
            'pap_fecha'   => $ahora,
            'pap_autor'   => $u['id_login'] ?? null,
            'pap_autor_n' => $u === null ? null : trim(($u['nombre'] ?? '') . ' ' . ($u['apellido1'] ?? '')),
            'pap_motivo'  => $extra['motivo'] ?? 'borrado',
            'pap_hotel'   => $extra['id_hotel'] ?? ($fila['id_hotel'] ?? null),
        ];
        foreach ($fila as $c => $v) {
            if (self::esSecreta($c)) {
                continue;
            }
            $datos[$c] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        $cols   = array_keys($datos);
        $marcas = array_map(static fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO "' . $tabla . '" ("' . implode('", "', $cols) . '") VALUES ('
             . implode(', ', $marcas) . ')';
        $st = $pdo->prepare($sql);
        foreach ($datos as $c => $v) {
            $st->bindValue(':' . $c, $v);
        }
        $st->execute();
        $papId = (int)$pdo->lastInsertId();

        /* El índice apunta a la fila exacta por su pap_id.
           Antes se emparejaban por fecha y motivo, y dos filas
           archivadas en el mismo segundo —que es lo normal en un
           restablecimiento— se confundían entre sí. */
        $st2 = $pdo->prepare(
            'INSERT INTO papelera_indice
               (tabla, id_original, etiqueta, resumen, pap_id, id_hotel, hotel, motivo, id_autor, autor, fecha)
             VALUES (:t, :io, :et, :re, :pi, :ih, :ho, :mo, :ia, :au, :fe)');
        $st2->execute([
            't'  => $tabla,
            'pi' => $papId,
            'io' => $extra['id_original'] ?? null,
            'et' => $extra['etiqueta'] ?? null,
            're' => $extra['resumen'] ?? null,
            'ih' => $datos['pap_hotel'],
            'ho' => $extra['hotel'] ?? null,
            'mo' => $datos['pap_motivo'],
            'ia' => $datos['pap_autor'],
            'au' => $datos['pap_autor_n'],
            'fe' => $ahora,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Archiva un parte con todo lo que cuelga de él.
     *
     * El historial, las fotos y los campos propios se van con él: un
     * parte sin su historial no se puede reconstruir, y dejarlos sueltos
     * en la base viva sería peor que borrarlos.
     */
    public static function guardarTicket(int $id, string $motivo = 'borrado'): void
    {
        $t = Db::one('SELECT * FROM ticket_incidencias WHERE id_ticket = ?', [$id]);
        if ($t === null) {
            return;
        }
        $hotel = null;
        if (!empty($t['id_hotel']) && Db::tablaExiste('hoteles')) {
            $hotel = Db::value('SELECT nombre FROM hoteles WHERE id_hotel = ?', [$t['id_hotel']]);
        }

        self::guardar('ticket_incidencias', $t, [
            'id_original' => $id,
            // El código no se guarda en la tabla: se compone al leer. Se
            // calcula aquí para que la papelera enseñe «T-00012» y no «#12».
            'etiqueta'    => $t['codigo'] ?? self::codigoDe($t),
            'resumen'     => mb_substr((string)($t['titulo_ticket'] ?? ''), 0, 160),
            'id_hotel'    => $t['id_hotel'] ?? null,
            'hotel'       => $hotel,
            'motivo'      => $motivo,
        ]);

        foreach (['ticket_incidencias_historial', 'adjuntos', 'valores_campo'] as $tabla) {
            if (!Db::tablaExiste($tabla)) {
                continue;
            }
            foreach (Db::all("SELECT * FROM $tabla WHERE id_ticket = ?", [$id]) as $fila) {
                $idx = self::guardar($tabla, $fila, [
                    'id_hotel' => $t['id_hotel'] ?? null,
                    'motivo'   => $motivo . ' (del parte ' . ($t['codigo'] ?? self::codigoDe($t)) . ')',
                ]);
                /* Fuera del índice: cuelgan del parte, no son entradas
                   propias, y llenarían la papelera de ruido. La fila
                   archivada se queda: es la que reconstruye el parte. */
                self::pdo()->exec('DELETE FROM papelera_indice WHERE id_entrada = ' . $idx);
            }
        }
    }

    /** El código visible de un parte, como lo compone el listado. */
    private static function codigoDe(array $t): string
    {
        $n = (int)($t['id_ticket'] ?? 0);
        return 'T-' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
    }

    public static function guardarUsuario(int $id, string $motivo = 'borrado'): void
    {
        $u = Db::one('SELECT * FROM login WHERE id_login = ?', [$id]);
        if ($u === null) {
            return;
        }
        unset($u['pwd_php'], $u['pwd_md5']);   // la contraseña no se archiva

        $hotel = null;
        if (!empty($u['id_hotel']) && Db::tablaExiste('hoteles')) {
            $hotel = Db::value('SELECT nombre FROM hoteles WHERE id_hotel = ?', [$u['id_hotel']]);
        }
        self::guardar('login', $u, [
            'id_original' => $id,
            'etiqueta'    => $u['correo'] ?? ('#' . $id),
            'resumen'     => trim(($u['nombre'] ?? '') . ' ' . ($u['apellido1'] ?? '')),
            'id_hotel'    => $u['id_hotel'] ?? null,
            'hotel'       => $hotel,
            'motivo'      => $motivo,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Consultar                                                        */
    /* ---------------------------------------------------------------- */

    public static function listar(array $f = []): array
    {
        $pdo = self::pdo();
        $where = ['1 = 1'];
        $p = [];

        if (!empty($f['tabla']))  { $where[] = 'tabla = :t';       $p['t']  = $f['tabla']; }
        if (!empty($f['hotel']))  { $where[] = 'id_hotel = :h';    $p['h']  = (int)$f['hotel']; }
        if (!empty($f['motivo'])) { $where[] = 'motivo LIKE :m ESCAPE \'!\'';   $p['m']  = Repo::patron((string)$f['motivo']); }
        if (!empty($f['q'])) {
            // La papelera vive en SQLite, así que se normaliza igual.
            $where[] = '(' . Repo::sinAcentos('etiqueta') . ' LIKE :q ESCAPE \'!\''
                     . ' OR ' . Repo::sinAcentos("COALESCE(resumen,'')") . ' LIKE :q2 ESCAPE \'!\''
                     . ' OR ' . Repo::sinAcentos("COALESCE(autor,'')") . ' LIKE :q3 ESCAPE \'!\')';
            $q = Repo::patron(Repo::planoTexto((string)$f['q']));
            $p['q'] = $q; $p['q2'] = $q; $p['q3'] = $q;
        }
        if (empty($f['incluir_restaurados'])) {
            $where[] = 'restaurado = 0';
        }

        $limite = min(500, max(1, (int)($f['limite'] ?? 100)));
        $st = $pdo->prepare('SELECT * FROM papelera_indice WHERE ' . implode(' AND ', $where)
                          . ' ORDER BY fecha DESC, id_entrada DESC LIMIT ' . $limite);
        $st->execute($p);
        $filas = $st->fetchAll();

        $st2 = $pdo->prepare('SELECT COUNT(*) FROM papelera_indice WHERE ' . implode(' AND ', $where));
        $st2->execute($p);

        return ['entradas' => $filas, 'total' => (int)$st2->fetchColumn()];
    }

    /** Cuántas cosas hay en la papelera, por tipo. */
    public static function resumen(?int $hotel = null): array
    {
        $pdo = self::pdo();
        $sql = 'SELECT tabla, COUNT(*) AS n FROM papelera_indice WHERE restaurado = 0'
             . ($hotel !== null ? ' AND id_hotel = ' . (int)$hotel : '')
             . ' GROUP BY tabla';
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[$r['tabla']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Tirar de verdad una entrada de la papelera.
     *
     * Hay borrados que tienen que ser definitivos: alguien pide que se
     * quiten sus datos, o se metió por error algo que no debía estar
     * ahí. Una papelera que no se puede vaciar guarda para siempre justo
     * lo que se quería quitar.
     *
     * Esto no tiene vuelta. Se lleva la fila archivada, lo que colgaba
     * de ella y las fotos del disco.
     */
    public static function purgar(int $idEntrada, int $idAutor): array
    {
        $f = self::fila($idEntrada);
        if ($f === null) {
            throw new ApiError('Esa entrada ya no está.', 404);
        }
        $e = $f['entrada'];
        $tabla = (string)$e['tabla'];
        $idOriginal = (int)$e['id_original'];

        $fotos = 0;
        $pdo = self::pdo();

        /* Las fotos del disco.
         *
         * Los ficheros no se borran al mandar el parte a la papelera —de
         * ahí salen al restaurarlo— así que si nadie los quita se
         * quedan para siempre ocupando sitio, y son lo que más pesa con
         * diferencia. */
        if ($tabla === 'ticket_incidencias' && self::tablaEnPapelera('adjuntos')) {
            $st = $pdo->prepare('SELECT archivo FROM adjuntos WHERE id_ticket = :t');
            $st->execute(['t' => $idOriginal]);
            $dir = dirname(__DIR__) . '/uploads/' . $idOriginal;
            foreach ($st->fetchAll() as $a) {
                $ruta = $dir . '/' . (string)$a['archivo'];
                if (is_file($ruta) && @unlink($ruta)) {
                    $fotos++;
                }
            }
            // Y la carpeta, si se queda sin nada dentro salvo su candado.
            if (is_dir($dir)) {
                $quedan = array_diff(scandir($dir) ?: [], ['.', '..', '.htaccess']);
                if ($quedan === []) {
                    @unlink($dir . '/.htaccess');
                    @rmdir($dir);
                }
            }
        }

        // Lo que colgaba del parte y la fila propia.
        if ($tabla === 'ticket_incidencias') {
            foreach (['ticket_incidencias_historial', 'adjuntos', 'valores_campo'] as $hija) {
                if (self::tablaEnPapelera($hija)) {
                    $pdo->prepare('DELETE FROM ' . self::comillas($hija) . ' WHERE id_ticket = :t')
                        ->execute(['t' => $idOriginal]);
                }
            }
        }
        if (self::tablaEnPapelera($tabla)) {
            $pdo->prepare('DELETE FROM ' . self::comillas($tabla) . ' WHERE pap_id = :p')
                ->execute(['p' => $e['pap_id']]);
        }
        $pdo->prepare('DELETE FROM papelera_indice WHERE id_entrada = :i')
            ->execute(['i' => $idEntrada]);

        Registro::anotar('borrado', $idAutor,
            'Purgado de la papelera (sin vuelta): ' . $e['etiqueta'] . ' (' . $tabla . ')');

        return ['etiqueta' => $e['etiqueta'], 'fotos' => $fotos];
    }

    /**
     * Vaciar lo que lleve mucho tiempo ahí.
     *
     * Lo llama el mantenimiento diario. El plazo por defecto es largo a
     * propósito: la papelera existe para arreglar un borrado por error,
     * y esos se descubren en días, no en meses. Pero si alguien vuelve
     * de vacaciones y pregunta, aún está.
     */
    /**
     * Vaciar la papelera entera.
     *
     * Para «Restablecer de fábrica»: después de mandar todo aquí, dejarlo
     * en la papelera no es vaciar un hotel, es esconderlo. Con una
     * salvedad importante: esto SÍ es definitivo, y la pantalla lo dice
     * con esas palabras, porque es lo único de todo el restablecer que no
     * se puede deshacer.
     *
     * @return int cuántas entradas se han quitado
     */
    public static function vaciar(int $idAutor = 0): int
    {
        $st = self::pdo()->query(
            'SELECT id_entrada FROM papelera_indice ORDER BY id_entrada');

        $n = 0;
        $fallos = [];
        foreach ($st->fetchAll() as $fila) {
            try {
                self::purgar((int)$fila['id_entrada'], $idAutor);
                $n++;
            } catch (Throwable $e) {
                // Igual que arriba: una entrada rota no para el resto.
                $fallos[] = (int)$fila['id_entrada'];
            }
        }

        if ($fallos !== []) {
            Registro::anotar('mantenimiento', $idAutor,
                'Al vaciar la papelera quedaron ' . count($fallos)
                . ' entradas que no se pudieron quitar: '
                . implode(', ', array_slice($fallos, 0, 20)));
        }

        return $n;
    }

    public static function purgarViejas(int $dias, int $idAutor = 0): int
    {
        if ($dias < 7) {
            $dias = 7;   // menos de una semana no es una papelera.
        }
        $limite = gmdate('Y-m-d H:i:s', time() - $dias * 86400);

        $st = self::pdo()->prepare(
            'SELECT id_entrada FROM papelera_indice WHERE fecha < :f ORDER BY id_entrada');
        $st->execute(['f' => $limite]);

        $n = 0;
        $fallos = [];
        foreach ($st->fetchAll() as $fila) {
            try {
                self::purgar((int)$fila['id_entrada'], $idAutor);
                $n++;
            } catch (Throwable $e) {
                /* Una entrada rota no puede parar la limpieza entera,
                   pero tampoco puede quedarse ahí para siempre: se
                   reintentaría cada noche y nadie se enteraría nunca.
                   Se anota, y así aparece en el registro de seguridad
                   si alguien va a mirar por qué la papelera no baja. */
                $fallos[] = (int)$fila['id_entrada'];
            }
        }

        if ($fallos !== []) {
            Registro::anotar('mantenimiento', $idAutor,
                'Papelera: ' . count($fallos) . ' entradas no se han podido tirar ('
                . implode(', ', array_slice($fallos, 0, 10)) . ')');
        }

        return $n;
    }

    /**
     * El nombre de una tabla, entrecomillado como espera cada motor.
     *
     * SQLite acepta "tabla"; MySQL usa `tabla` y con comillas dobles da
     * un «syntax error» que no dice nada de comillas. Como aquí los
     * nombres salen del índice de la papelera —no del usuario— se filtra
     * a lo que puede llevar un nombre de tabla y se entrecomilla.
     */
    private static function comillas(string $tabla): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9_]/', '', $tabla) ?? '';
        if ($limpio === '') {
            throw new ApiError('Nombre de tabla no válido en la papelera.', 500);
        }
        return Db::driver() === 'sqlite' ? '"' . $limpio . '"' : '`' . $limpio . '`';
    }

    /** ¿Existe esa tabla dentro del fichero de la papelera? */
    private static function tablaEnPapelera(string $tabla): bool
    {
        $st = self::pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t");
        $st->execute(['t' => $tabla]);
        return (bool)$st->fetchColumn();
    }

    /** La fila completa de una entrada, para poder mirarla o restaurarla. */
    public static function fila(int $idEntrada): ?array
    {
        $pdo = self::pdo();
        $e = $pdo->query('SELECT * FROM papelera_indice WHERE id_entrada = ' . $idEntrada)->fetch();
        if (!$e) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM "' . $e['tabla'] . '" WHERE pap_id = :p');
        $st->execute(['p' => $e['pap_id']]);
        return ['entrada' => $e, 'datos' => $st->fetch() ?: null];
    }

    /**
     * Devolver algo de la papelera a la base viva.
     *
     * Una papelera de la que no se puede sacar nada es un cajón de
     * cosas perdidas. Se borra un parte por error —o se borra el que no
     * era, que pasa más— y hasta ahora sólo quedaba mirarlo.
     *
     * Vuelve con su mismo número: quien lo tenía apuntado en un papel
     * sigue encontrándolo. Si en ese hueco hay ya otro parte, se avisa
     * en vez de pisarlo.
     */
    public static function restaurar(int $idEntrada, int $idAutor): array
    {
        $f = self::fila($idEntrada);
        if ($f === null || $f['datos'] === null) {
            throw new ApiError('Esa entrada de la papelera ya no está.', 404);
        }
        $e = $f['entrada'];
        $datos = $f['datos'];
        $tabla = (string)$e['tabla'];

        if ((int)($e['restaurado'] ?? 0) === 1) {
            throw new ApiError('Eso ya se restauró antes.', 409);
        }

        // Las columnas propias de la papelera no vuelven.
        unset($datos['pap_id'], $datos['pap_fecha'], $datos['pap_motivo']);

        $idOriginal = (int)$e['id_original'];
        $clave = $tabla === 'login' ? 'id_login' : 'id_ticket';

        if (Db::value('SELECT 1 FROM ' . self::comillas($tabla) . " WHERE $clave = ?", [$idOriginal]) !== null) {
            throw new ApiError(
                'Ya hay otro registro con ese número. Alguien lo ha ocupado desde que se borró, '
                . 'así que restaurarlo lo pisaría.', 409);
        }

        /* Sólo columnas que existan hoy: la tabla puede haber cambiado
           desde que se archivó, y sobra una columna que ya no está. */
        $vivas = [];
        foreach (Db::columnas($tabla) as $col) {
            if (array_key_exists($col, $datos)) {
                $vivas[$col] = $datos[$col];
            }
        }
        if ($vivas === []) {
            throw new ApiError('No queda nada reconocible que restaurar.', 422);
        }
        if ($tabla === 'ticket_incidencias') {
            $vivas['borrado'] = 0;
        }
        if ($tabla === 'login') {
            /* Vuelve sin acceso, y con una contraseña imposible.
             *
             * La papelera no guarda contraseñas —eso es a propósito— pero
             * la columna no admite vacío. Se pone una cadena que ningún
             * hash real puede producir: así nadie entra con ella ni por
             * casualidad, y el administrador tiene que darle una nueva,
             * que es justo lo que debe pasar cuando alguien vuelve.
             *
             * El bloqueo es el cinturón; esto, los tirantes. */
            $vivas['pwd_php']  = '!sin-contrasena-restaurado-' . bin2hex(random_bytes(8));
            $vivas['pwd_sha2'] = null;
            $vivas['bloqueo']  = 1;
        }
        $vivas['updated_at'] = Db::now();
        /* Si vuelve un parte, ya no está de baja. Sin esto, un móvil que
           no hubiera sincronizado entre medias recibiría a la vez «aquí
           está» y «retíralo», y lo borraría. */
        if (($tabla ?? '') === 'ticket_incidencias' && Db::tablaExiste('sync_bajas')
            && isset($vivas['id_ticket'])) {
            Db::run('DELETE FROM sync_bajas WHERE id_ticket = ?', [(int)$vivas['id_ticket']]);
        }

        Db::begin();
        try {
            $cols = array_keys($vivas);
            $sql = 'INSERT INTO ' . self::comillas($tabla) . ' (' . implode(', ', $cols) . ') VALUES ('
                 . implode(', ', array_map(static fn($c) => ':' . $c, $cols)) . ')';
            Db::run($sql, $vivas);

            /* Y todo lo que colgaba del parte.
             *
             * Son las mismas tres tablas que se archivan al borrar, y
             * conviene que sea una sola lista: cuando se añadió el
             * archivo de los campos propios, la vuelta se quedó sin
             * ellos porque estaban escritos en dos sitios distintos.
             *
             * Las fotos importan más de lo que parece. Los ficheros
             * nunca se borran del disco, así que lo que se perdía era la
             * fila que dice a qué parte pertenecen: el parte volvía sin
             * fotos, con las imágenes ocupando sitio sin que nadie
             * pudiera verlas. Y la foto de la avería es la mitad del
             * parte.
             */
            if ($tabla === 'ticket_incidencias') {
                $claves = [
                    'ticket_incidencias_historial' => 'id_historial',
                    'adjuntos'                     => 'id_adjunto',
                    'valores_campo'                => 'id_valor',
                ];

                foreach ($claves as $hija => $pk) {
                    /* Tiene que existir en las dos bases.
                     *
                     * Las tablas de la papelera se crean la primera vez
                     * que se archiva algo de ese tipo, así que una
                     * instalación donde nadie ha usado los campos
                     * propios no tiene esa tabla ahí. Sin comprobarlo,
                     * la consulta reventaba y se perdía TODO lo demás:
                     * el parte volvía sin fotos ni historial por una
                     * tabla que nunca hizo falta. */
                    if (!Db::tablaExiste($hija) || !self::tablaEnPapelera($hija)) {
                        continue;
                    }
                    $st = self::pdo()->prepare('SELECT * FROM ' . self::comillas($hija) . ' WHERE id_ticket = :t');
                    $st->execute(['t' => $idOriginal]);
                    $colsH = Db::columnas($hija);

                    foreach ($st->fetchAll() as $h) {
                        unset($h['pap_id']);
                        $fila = [];
                        foreach ($colsH as $col) {
                            if (array_key_exists($col, $h)) {
                                $fila[$col] = $h[$col];
                            }
                        }
                        unset($fila[$pk]);
                        if ($fila === []) {
                            continue;
                        }

                        /* El uuid es lo que evita que la misma fila entre
                           dos veces cuando llega por dos caminos. Al
                           restaurar hay que respetarlo: si ya está, se
                           salta en vez de reventar la operación entera. */
                        if (!empty($fila['uuid'])
                            && Db::value('SELECT 1 FROM ' . self::comillas($hija) . ' WHERE uuid = ?', [$fila['uuid']]) !== null) {
                            continue;
                        }
                        Db::insert($hija, $fila);
                    }
                }
            }

            Db::commit();
        } catch (Throwable $ex) {
            Db::rollback();
            throw new ApiError('No se ha podido restaurar: ' . $ex->getMessage(), 500);
        }

        self::pdo()->prepare('UPDATE papelera_indice SET restaurado = 1 WHERE id_entrada = :i')
                   ->execute(['i' => $idEntrada]);

        Registro::anotar('restauracion', $idAutor,
            'Restaurado de la papelera: ' . $e['etiqueta'] . ' (' . $tabla . ')');

        return [
            'tabla'       => $tabla,
            'id_original' => $idOriginal,
            'etiqueta'    => $e['etiqueta'],
            'bloqueado'   => $tabla === 'login',
        ];
    }
}
