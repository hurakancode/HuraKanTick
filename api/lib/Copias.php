<?php
/**
 * Copias de seguridad.
 *
 * Se exporta todo a un fichero JSON comprimido. Se eligió JSON y no un
 * volcado SQL porque así la copia sirve igual venga de MySQL o de SQLite,
 * y se puede restaurar en cualquiera de los dos.
 *
 * Las fotos van aparte por peso: se incluyen sólo si se pide.
 */
declare(strict_types=1);

final class Copias
{
    /** Orden de restauración: las tablas referenciadas van primero. */
    /**
     * El orden en que se rellenan al restaurar.
     *
     * Importa porque unas dependen de otras: no se pueden meter partes
     * antes que los estados a los que apuntan. Lo que no esté en esta
     * lista se guarda igual y se restaura al final, en el orden en que
     * aparezca.
     *
     * Antes esto era la lista COMPLETA de lo que se copiaba, escrita a
     * mano. Cada tabla añadida desde entonces —hoteles, mantenimientos,
     * números de serie, qué departamentos usa cada hotel— se quedaba
     * fuera sin que nadie lo notara: la copia se creaba, decía «ok», y
     * al restaurarla faltaba media aplicación. Ahora las tablas se
     * descubren solas y esto es sólo el orden.
     */
    private const ORDEN = [
        'hoteles', 'departamentos', 'hotel_departamento', 'login',
        'estados', 'prioridades', 'familias', 'elemento',
        'zona_1', 'zona_2', 'zona_3', 'zona_4', 'rel_zonas',
        'unidades', 'unidades_mov',
        'ticket_incidencias', 'ticket_incidencias_historial', 'adjuntos',
        'notificaciones', 'preferencias', 'ajustes_sistema',
        'campos_elemento', 'valores_campo', 'atajos', 'informes', 'avisos_pref',
        'instrucciones', 'mantenimientos',
    ];

    /**
     * Lo que NO se copia, y por qué.
     *
     * Son cosas de la sesión de ahora mismo o de este servidor: llevarlas
     * a otro sitio no aporta nada y algunas son un riesgo.
     */
    private const FUERA = [
        'api_tokens',        // sesiones abiertas: no valen en otro servidor
        'intentos_acceso',   // quién falló la contraseña esta semana
        'recuperaciones',    // códigos de un solo uso, ya caducados
        'push_destinos',     // direcciones atadas a un navegador concreto
        'copias',            // el registro de copias, dentro de una copia
    ];

    /** Las tablas que se copian: se descubren solas. */
    private static function tablas(): array
    {
        $todas = Db::tablas();
        $fuera = array_flip(self::FUERA);

        $orden = [];
        foreach (self::ORDEN as $t) {
            if (in_array($t, $todas, true) && !isset($fuera[$t])) {
                $orden[] = $t;
            }
        }
        // Y lo que exista pero no esté en la lista de orden, al final.
        foreach ($todas as $t) {
            if (!isset($fuera[$t]) && !in_array($t, $orden, true)) {
                $orden[] = $t;
            }
        }
        return $orden;
    }

    public static function carpeta(): string
    {
        $cfg = require __DIR__ . '/../config.php';
        $dir = dirname($cfg['uploads']['dir']) . '/copias';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    /* ---------------------------------------------------------------- */
    public static function crear(int $idLogin, bool $conFotos = false): array
    {
        @set_time_limit(300);

        $datos = [
            'version'  => '2.2',
            'motor'    => Db::driver(),
            'fecha'    => Db::now(),
            'tablas'   => [],
        ];

        $registros = 0;
        $nTablas   = 0;

        foreach (self::tablas() as $tabla) {
            if (!Db::tablaExiste($tabla)) {
                continue;
            }
            $filas = Db::all("SELECT * FROM $tabla");

            // Las contraseñas viajan en la copia porque si no, restaurar
            // dejaría a todo el mundo sin poder entrar. Ya son hashes
            // bcrypt, pero por eso el fichero se guarda fuera del alcance
            // del servidor web y conviene custodiarlo como un secreto.
            $datos['tablas'][$tabla] = $filas;
            $registros += count($filas);
            $nTablas++;
        }

        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new ApiError('No se ha podido preparar la copia: ' . json_last_error_msg(), 500);
        }

        $nombre = 'hurakantick-' . gmdate('Ymd-His') . ($conFotos ? '-con-fotos' : '') . '.json';
        $ruta   = self::carpeta() . '/' . $nombre;

        if (function_exists('gzencode')) {
            $nombre .= '.gz';
            $ruta   .= '.gz';
            $contenido = gzencode($json, 6);
        } else {
            $contenido = $json;
        }

        if (file_put_contents($ruta, $contenido) === false) {
            throw new ApiError('No se ha podido escribir la copia en el servidor.', 500);
        }
        @chmod($ruta, 0640);

        // Las fotos, si se piden, en un fichero paralelo.
        if ($conFotos) {
            self::copiarFotos($nombre);
        }

        $id = Db::insert('copias', [
            'archivo'       => $nombre,
            'tamano'        => strlen($contenido),
            'incluye_fotos' => $conFotos ? 1 : 0,
            'tablas'        => $nTablas,
            'registros'     => $registros,
            'hash'          => hash('sha256', $contenido),
            'id_login'      => $idLogin,
            'automatica'    => 0,
            'fecha'         => Db::now(),
        ]);

        self::limpiarViejas();

        return [
            'id_copia'  => $id,
            'archivo'   => $nombre,
            'tamano'    => strlen($contenido),
            'tablas'    => $nTablas,
            'registros' => $registros,
            // Para que quien la guarda sepa lo que lleva.
            'con_fotos' => self::puedeFotos(),
            'aviso'     => self::puedeFotos() ? null
                : 'Este servidor no puede empaquetar las fotos (le falta la extensión '
                . 'ZipArchive de PHP), así que la copia lleva los datos pero no las '
                . 'imágenes. Cópialas aparte desde api/uploads.',
        ];
    }

    /** Empaqueta las fotos con ZipArchive si está disponible. */
    /** ¿Se pueden empaquetar las fotos en este servidor? */
    public static function puedeFotos(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Devuelve las fotos del ZIP que acompaña a la copia.
     *
     * No borra lo que ya hay: si una foto existe, se deja. Restaurar no
     * debería hacer desaparecer imágenes que estaban bien.
     */
    private static function restaurarFotos(string $rutaCopia, ?callable $log = null): void
    {
        $zipRuta = preg_replace('/\.json(\.gz)?$/', '', $rutaCopia) . '-fotos.zip';
        if (!is_file($zipRuta)) {
            return;   // copia sin fotos: no hay nada que devolver
        }
        if (!class_exists('ZipArchive')) {
            if ($log) { $log('  aviso: hay fotos en la copia pero este servidor no abre ZIP.'); }
            return;
        }

        $cfg = require __DIR__ . '/../config.php';
        $destino = $cfg['uploads']['dir'];
        if (!is_dir($destino)) { @mkdir($destino, 0775, true); }

        $zip = new ZipArchive();
        if ($zip->open($zipRuta) !== true) {
            if ($log) { $log('  aviso: no he podido abrir ' . basename($zipRuta)); }
            return;
        }

        $puestas = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if ($nombre === false || str_ends_with($nombre, '/')) { continue; }
            // Nada de rutas que se salgan de la carpeta de fotos.
            $limpio = str_replace('\\', '/', $nombre);
            if (str_contains($limpio, '../')) { continue; }

            $ruta = $destino . '/' . $limpio;
            if (is_file($ruta)) { continue; }
            $dir = dirname($ruta);
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $datos = $zip->getFromIndex($i);
            if ($datos !== false && file_put_contents($ruta, $datos) !== false) {
                /* 0644, no 0640: restaurar suele hacerse desde la consola
                   como administrador del servidor, y con 0640 el servidor
                   web se quedaba sin poder leer las fotos —volvían al
                   disco pero se veían rotas igual—. La carpeta ya está
                   cerrada al navegador por su .htaccess. */
                @chmod($ruta, 0644);
                $puestas++;
            }
        }
        $zip->close();
        if ($log) { $log('  Fotos devueltas: ' . $puestas . '.'); }
    }

    private static function copiarFotos(string $nombreBase): void
    {
        /* Sin ZipArchive no hay forma de empaquetarlas.
         *
         * Antes se salía sin más y la copia decía «ok»: guardaba la fila
         * que dice «este parte tiene una foto» pero no la foto. Al
         * restaurar quedaban partes señalando imágenes que ya no existen,
         * y eso no se descubre hasta que alguien abre un parte viejo
         * buscando justo esa foto.
         *
         * Ahora se avisa. La copia se hace igual —los datos valen más que
         * las fotos— pero quien la guarda sabe lo que lleva. */
        if (!self::puedeFotos()) {
            return;
        }
        $cfg = require __DIR__ . '/../config.php';
        $origen = $cfg['uploads']['dir'];
        if (!is_dir($origen)) {
            return;
        }

        $zip = new ZipArchive();
        $destino = self::carpeta() . '/' . preg_replace('/\.json(\.gz)?$/', '', $nombreBase) . '-fotos.zip';
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() !== 'htaccess') {
                $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($origen) + 1));
            }
        }
        $zip->close();
        @chmod($destino, 0640);
    }

    /* ---------------------------------------------------------------- */
    public static function listar(): array
    {
        $filas = Db::all(
            'SELECT c.*, ' . Db::nombreCompleto('l') . ' AS autor
               FROM copias c LEFT JOIN login l ON l.id_login = c.id_login
              ORDER BY c.fecha DESC LIMIT 60'
        );
        $dir = self::carpeta();
        foreach ($filas as &$f) {
            $f['id_copia']      = (int)$f['id_copia'];
            $f['tamano']        = (int)$f['tamano'];
            $f['registros']     = (int)$f['registros'];
            $f['tablas']        = (int)$f['tablas'];
            $f['incluye_fotos'] = (int)$f['incluye_fotos'];
            $f['existe']        = is_file($dir . '/' . $f['archivo']);
        }
        return $filas;
    }

    public static function descargar(int $id): void
    {
        $c = Db::one('SELECT * FROM copias WHERE id_copia = ?', [$id]);
        if ($c === null) {
            throw new ApiError('La copia no existe.', 404);
        }
        $ruta = self::carpeta() . '/' . $c['archivo'];
        if (!is_file($ruta)) {
            throw new ApiError('El archivo de la copia ya no está en el servidor.', 404);
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: attachment; filename="' . $c['archivo'] . '"');
        header('Cache-Control: no-store');
        readfile($ruta);
        exit;
    }

    public static function borrar(int $id): void
    {
        $c = Db::one('SELECT * FROM copias WHERE id_copia = ?', [$id]);
        if ($c === null) {
            return;
        }
        $ruta = self::carpeta() . '/' . $c['archivo'];
        if (is_file($ruta)) { @unlink($ruta); }
        $fotos = self::carpeta() . '/' . preg_replace('/\.json(\.gz)?$/', '', (string)$c['archivo']) . '-fotos.zip';
        if (is_file($fotos)) { @unlink($fotos); }
        Db::run('DELETE FROM copias WHERE id_copia = ?', [$id]);
    }

    /** Se conservan las 10 últimas: una copia vieja ocupa y no sirve. */
    private static function limpiarViejas(int $conservar = 10): void
    {
        $viejas = Db::all('SELECT id_copia FROM copias ORDER BY fecha DESC LIMIT 100 OFFSET ' . $conservar);
        foreach ($viejas as $v) {
            self::borrar((int)$v['id_copia']);
        }
    }

    /* ---------------------------------------------------------------- */
    /**
     * Restauración. Sólo desde la línea de órdenes: es una operación
     * destructiva y no debe poder lanzarse desde el navegador por error.
     */
    public static function restaurar(string $ruta, callable $log): void
    {
        if (!is_file($ruta)) {
            throw new RuntimeException("No se encuentra $ruta");
        }
        $bruto = (string)file_get_contents($ruta);
        if (str_ends_with($ruta, '.gz')) {
            $bruto = (string)gzdecode($bruto);
        }
        $datos = json_decode($bruto, true);
        if (!is_array($datos) || !isset($datos['tablas'])) {
            throw new RuntimeException('El fichero no es una copia válida.');
        }

        $log('Copia del ' . ($datos['fecha'] ?? '?') . ' (motor ' . ($datos['motor'] ?? '?') . ')');

        /* Las claves foráneas se apagan mientras dura la restauración.
         *
         * Se vacían y rellenan todas las tablas, y en algún momento del
         * proceso la base queda incoherente por fuerza: al borrar
         * «departamentos» antes de rellenar «login», las personas
         * apuntan a departamentos que ya no están. Con las claves
         * activas eso hace fallar la restauración entera — que es
         * exactamente lo que pasaba: la copia se creaba bien pero no
         * había forma de devolverla.
         *
         * Al terminar se vuelven a activar y se comprueba que todo
         * cuadra. Si no cuadrara, la transacción lo deshace y la base
         * se queda como estaba. */
        $fkAntes = null;
        if (Db::driver() === 'sqlite') {
            $fkAntes = (int)Db::pdo()->query('PRAGMA foreign_keys')->fetchColumn();
            // En SQLite no se puede cambiar dentro de una transacción.
            Db::pdo()->exec('PRAGMA foreign_keys = OFF');
        } else {
            Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        Db::begin();
        try {
            foreach (self::tablas() as $tabla) {
                if (!isset($datos['tablas'][$tabla]) || !Db::tablaExiste($tabla)) {
                    continue;
                }
                Db::run("DELETE FROM $tabla");
                $n = 0;
                foreach ($datos['tablas'][$tabla] as $fila) {
                    Db::insert($tabla, $fila);
                    $n++;
                }
                $log("  $tabla: $n registros");
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            self::volverAActivarClaves($fkAntes);
            throw $e;
        }

        self::volverAActivarClaves($fkAntes);

        /* Y se comprueba que lo restaurado es coherente.
           Una copia de una versión vieja puede traer filas que apuntan a
           algo que ya no existe; mejor saberlo ahora que descubrirlo
           semanas después con un error suelto. */
        if (Db::driver() === 'sqlite') {
            $rotas = Db::pdo()->query('PRAGMA foreign_key_check')->fetchAll();
            if ($rotas) {
                $log('  aviso: ' . count($rotas) . ' referencias no cuadran tras restaurar.');
            }
        }
        /* La base es otra: los móviles deben vaciar lo que guardan y volver a
           descargar. Si no, se quedarían con partes posteriores a la copia
           que ya no existen, y les faltarían los restaurados. */
        Permisos::nuevaInstalacion();
        if (Db::tablaExiste('sync_bajas')) { Db::run('DELETE FROM sync_bajas'); }
        $log('Restauración terminada.');
        /* Las fotos van en un ZIP al lado de la copia. Se devolvían las
           fichas de los adjuntos pero no los ficheros, así que tras
           restaurar las imágenes salían rotas: la ficha decía que había
           foto y el servidor no la tenía. */
        self::restaurarFotos($ruta, $log);

    }

    /** Deja las claves foráneas como estaban antes de restaurar. */
    private static function volverAActivarClaves(?int $antes): void
    {
        try {
            if (Db::driver() === 'sqlite') {
                Db::pdo()->exec('PRAGMA foreign_keys = ' . ($antes ? 'ON' : 'OFF'));
            } else {
                Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        } catch (Throwable $e) {
            // Si no se pueden reactivar, la conexión muere con la
            // petición y la siguiente arranca con los valores normales.
        }
    }
}
