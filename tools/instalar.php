<?php
/**
 * Instalador. Crea el esquema y los datos iniciales.
 *
 *   php tools/instalar.php                # esquema + catálogos + admin
 *   php tools/instalar.php --demo         # además, datos de ejemplo
 *   php tools/instalar.php --forzar       # rehace la base de datos SQLite
 *
 * También se puede abrir desde el navegador durante la instalación:
 *   http://tu-servidor/hurakantick/tools/instalar.php?clave=instalar
 * Borra este fichero cuando termines.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['clave'] ?? '') !== 'instalar') {
        http_response_code(403);
        exit("Añade ?clave=instalar a la dirección para ejecutar el instalador.\n");
    }
}

/* El formulario, también desde aquí.
 *
 *   instalar.php?clave=instalar&gui=1
 *
 * La carpeta tools/ está cerrada salvo para una lista de ficheros, y esa
 * lista vive en tools/.htaccess, que empieza por punto: al actualizar en
 * Windows es fácil que no se copie y entonces instalar_gui.php da un
 * «Forbidden». Por esta puerta se abre igual, porque instalar.php sí está
 * permitido desde siempre. */
if (!$cli && isset($_GET['gui']) && is_file(__DIR__ . '/instalar_gui.php')) {
    require __DIR__ . '/instalar_gui.php';
    exit;
}

$args   = $cli ? array_slice($argv, 1) : array_keys($_GET);
$demo   = in_array('--demo', $args, true)   || isset($_GET['demo']);
$forzar = in_array('--forzar', $args, true) || isset($_GET['forzar']);

/**
 * Modo actualización.
 *
 * Al subir una versión nueva de los ficheros, las tablas y los ajustes
 * que trae no existen todavía en la base de datos: hay que aplicarlos.
 * Sin esto, las funciones nuevas «no aparecen» sin dar ningún error, que
 * es lo peor que puede pasar.
 *
 * Es seguro repetirlo: todo va con CREATE TABLE IF NOT EXISTS e INSERT
 * OR IGNORE, así que no toca nada de lo que ya haya.
 */
$actualizar = in_array('--actualizar', $args, true) || isset($_GET['actualizar']);

/* Instalación limpia: el núcleo y nada más, para montar cualquier negocio
   desde cero. Sin esta opción se cargan los paquetes de siempre, para no
   cambiarle la instalación a quien ya la tiene montada. */
$limpia = in_array('--limpia', $args, true) || isset($_GET['limpia']);

/* Qué paquetes cargar, separados por comas. */
$conPaquetes = 'hotel,benalma';
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--paquetes=')) { $conPaquetes = substr((string)$a, 11); }
}
if (isset($_GET['paquetes'])) { $conPaquetes = (string)$_GET['paquetes']; }

$sufijo = 'sqlite';   // se ajusta abajo, al conocer el motor


$config = require __DIR__ . '/../api/config.php';

/* La base cambia de nombre: db/sstt.sqlite pasa a db/hurakantick.sqlite.
 *
 * Se mueven los tres ficheros juntos —la base, su diario («-wal») y su
 * índice compartido («-shm»)—, porque SQLite busca el diario por el
 * nombre de la base: moverla sin él perdería lo último que se escribió.
 * Y la papelera igual, que sigue al nombre de la base. Si la nueva ya
 * existe no se toca nada: no se pisa una base con otra. */
$baseNueva  = __DIR__ . '/../db/hurakantick.sqlite';
$baseVieja  = __DIR__ . '/../db/sstt.sqlite';
if (($config['db']['driver'] ?? '') === 'sqlite'
    && realpath($config['db']['sqlite_path']) === realpath($baseVieja)
    && is_file($baseVieja) && !is_file($baseNueva)) {
    $movidos = 0;
    foreach (['', '-wal', '-shm'] as $suf) {
        foreach ([['sstt', 'hurakantick'], ['sstt-papelera', 'hurakantick-papelera']] as [$de, $a]) {
            $origen  = __DIR__ . "/../db/$de.sqlite$suf";
            $destino = __DIR__ . "/../db/$a.sqlite$suf";
            if (is_file($origen) && !is_file($destino) && @rename($origen, $destino)) {
                $movidos++;
            }
        }
    }
    if (is_file($baseNueva)) {
        $config['db']['sqlite_path'] = $baseNueva;
        echo "· La base pasa a llamarse db/hurakantick.sqlite ($movidos ficheros movidos).\n";
    } else {
        echo "AVISO: no se ha podido renombrar db/sstt.sqlite. Se sigue usando con su nombre;\n"
           . "       comprueba que el usuario que ejecuta esto puede escribir en db/.\n";
    }
}

/* DESDE EL NAVEGADOR NO SE BORRA NUNCA.
 *
 * La clave de la dirección (?clave=instalar) está en la documentación:
 * la conoce cualquiera. Con ella, «&forzar» borraba la base entera y
 * enseñaba en pantalla la contraseña del administrador nuevo a quien
 * hubiera abierto la dirección. Ahora, desde el navegador:
 *   · actualizar —que no toca datos— sigue funcionando;
 *   · la primera instalación, sólo si la base está vacía;
 *   · forzar, nunca: sólo desde la consola del servidor;
 *   · la demostración, sólo sobre una base vacía. */
/* EL PERMISO PARA EMPEZAR DE CERO DESDE EL NAVEGADOR.
 *
 * Quien administra el servidor puede crear un fichero vacío llamado
 * REINSTALAR (o REINSTALAR.txt, que es lo que deja el Bloc de notas) en la
 * carpeta db/. Eso demuestra que tiene acceso a los ficheros del servidor,
 * cosa que un intruso con la dirección no tiene. Con él presente, el
 * navegador puede borrar y cargar la demostración.
 *
 * Vale una vez —se borra al usarlo— y caduca en una hora, para que un
 * fichero olvidado no deje la puerta abierta para siempre. */
$permiso = null;
foreach (['REINSTALAR', 'REINSTALAR.txt'] as $nombrePermiso) {
    $f = __DIR__ . '/../db/' . $nombrePermiso;
    if (is_file($f)) { $permiso = $f; break; }
}
$permisoVale = $permiso !== null && (time() - (int)filemtime($permiso)) < 3600;

$comoHacerlo = "Para empezar de cero —se BORRA todo lo que haya— hay dos formas:\n\n"
    . "  1. Crear un fichero vacío llamado REINSTALAR en la carpeta db/ del\n"
    . "     servidor y volver a abrir esta dirección añadiendo &forzar:\n"
    . "       tools/instalar.php?clave=instalar&demo&forzar\n"
    . "     El fichero vale una vez y caduca en una hora.\n\n"
    . "  2. Desde la consola del servidor:\n"
    . "       php tools/instalar.php --demo --forzar\n"
    . "     (en XAMPP para Windows: C:\\xampp\\php\\php.exe tools\\instalar.php --demo --forzar)\n";

if (!$cli && $permiso !== null && !$permisoVale) {
    @unlink($permiso);
    http_response_code(403);
    exit("El fichero db/" . basename($permiso) . " tenía más de una hora y se ha borrado.\n\n"
       . $comoHacerlo);
}

if (!$cli && $permisoVale) {
    // Se consume ANTES de hacer nada: si algo falla a medias, no queda abierto.
    @unlink($permiso);
    clearstatcache(true, $permiso);
    if (is_file($permiso)) {
        // Si no se puede borrar, valdría otra vez durante una hora: no.
        http_response_code(403);
        exit("No se ha podido borrar db/" . basename($permiso) . " y por seguridad no se sigue.\n"
           . "Bórralo a mano y hazlo desde la consola del servidor:\n\n"
           . "  php tools/instalar.php --demo --forzar\n");
    }
    echo "· Permiso de reinstalación usado (db/" . basename($permiso) . " borrado).\n";
}

// Con permiso, el navegador puede lo mismo que la consola.
if (!$cli && !$permisoVale) {
    if ($forzar) {
        http_response_code(403);
        exit("Desde el navegador no se puede borrar la base de datos sin permiso.\n\n" . $comoHacerlo);
    }
    $hayDatos = false;
    try {
        $c0 = $config['db'];
        $pdo0 = $c0['driver'] === 'mysql'
            ? new PDO("mysql:host={$c0['host']};dbname={$c0['database']};charset=utf8mb4", $c0['user'], $c0['password'])
            : (is_file($c0['sqlite_path'] ?? '') ? new PDO('sqlite:' . $c0['sqlite_path']) : null);
        if ($pdo0) {
            $hayDatos = (int)$pdo0->query('SELECT COUNT(*) FROM login')->fetchColumn() > 0;
        }
    } catch (Throwable $e) { $hayDatos = false; }   // sin tabla: base vacía

    if ($hayDatos && !$actualizar) {
        http_response_code(403);
        exit("La base ya tiene datos. Desde el navegador sólo se puede actualizar:\n\n"
           . "  tools/instalar.php?clave=instalar&actualizar\n\n" . $comoHacerlo);
    }
    if ($hayDatos && $demo) {
        http_response_code(403);
        exit("La demostración sólo se carga sobre una base vacía.\n\n" . $comoHacerlo);
    }
}
require_once __DIR__ . '/../api/lib/compat.php';
require_once __DIR__ . '/../api/lib/Http.php';
require_once __DIR__ . '/../api/lib/Db.php';
require_once __DIR__ . '/../api/lib/Auth.php';
require_once __DIR__ . '/../api/lib/Permisos.php';
require_once __DIR__ . '/../api/lib/Registro.php';
require_once __DIR__ . '/../api/lib/Hoteles.php';
require_once __DIR__ . '/../api/lib/Archivo.php';
require_once __DIR__ . '/../api/lib/Repo.php';
require_once __DIR__ . '/../api/lib/Uploads.php';

$log = static function (string $m): void { echo $m . "\n"; @flush(); };

$versionInstalada = (string)(json_decode((string)@file_get_contents(
    __DIR__ . '/../www/version.json'), true)['version'] ?? '');
$log('HuraKan Tick ' . $versionInstalada . ' — instalación');
/* Poner a salvo la configuración.
 *
 * Los datos reales van en api/config.local.php, que el paquete NO trae:
 * así una actualización no puede pisarlos. Pero mucha gente edita
 * directamente config.php —es lo natural, es el fichero que ve— y
 * entonces al descomprimir la versión nueva encima se pierde todo y hay
 * que volver a escribirlo.
 *
 * Si se detecta ese caso, se copia la configuración a config.local.php
 * antes de seguir. A partir de ahí, actualizar deja de ser peligroso. */
$rutaLocal = __DIR__ . '/../api/config.local.php';

/* La clave secreta de la casa, que no la tenía nadie.
 *
 * `app_key` venía en config.php con el valor
 * «CAMBIA-ESTA-CLAVE-POR-UNA-ALEATORIA-DE-64-CARACTERES», y NADA la
 * cambiaba: ni el instalador la generaba ni el diagnóstico avisaba. O
 * sea que todas las instalaciones del mundo compartían el mismo secreto
 * y ese secreto está escrito en el código, que va dentro del paquete.
 *
 * Con ella se firma el freno de intentos de entrada y, sobre todo,
 * autentican dos programas que se llaman desde fuera:
 * tools/mantenimiento.php y tools/vencidos.php, los dos con ?clave=.
 * La carpeta tools/ está cerrada por su .htaccess, así que hacían falta
 * dos fallos a la vez para que esto se explotara —y el primero de los
 * dos, que el servidor no lea los .htaccess, resulta que es el de
 * fábrica en Debian y Ubuntu—.
 *
 * Así que se genera aquí, una por instalación, y se guarda donde no se
 * pisa al actualizar. */
$claveNueva = '';
$esDeFabrica = static fn(string $k): bool =>
    $k === '' || str_starts_with($k, 'CAMBIA-ESTA-CLAVE') || strlen($k) < 32;

if ($esDeFabrica((string)($config['auth']['app_key'] ?? ''))) {
    $claveNueva = bin2hex(random_bytes(32));
    $config['auth']['app_key'] = $claveNueva;
}

if (!is_file($rutaLocal)) {
    $db = $config['db'];
    $aMano = $db['driver'] !== 'sqlite'
          || $db['database'] !== 'hurakantick'
          || $db['user'] !== 'hurakantick'
          || ($db['password'] ?? '') !== ''
          || $db['host'] !== 'localhost';

    if ($aMano || $claveNueva !== '') {
        $texto = "<?php\n"
               . "/* Tus datos de conexión.\n"
               . " *\n"
               . " * Este fichero NO viene en el paquete: puedes actualizar\n"
               . " * descomprimiendo encima sin perderlo. Lo que pongas aquí manda\n"
               . " * sobre config.php.\n"
               . " */\n"
               . "return [\n"
               . "    'db' => [\n"
               . "        'driver'   => " . var_export($db['driver'], true) . ",\n"
               . "        'host'     => " . var_export($db['host'], true) . ",\n"
               . "        'port'     => " . var_export($db['port'] ?? 3306, true) . ",\n"
               . "        'database' => " . var_export($db['database'], true) . ",\n"
               . "        'user'     => " . var_export($db['user'], true) . ",\n"
               . "        'password' => " . var_export($db['password'] ?? '', true) . ",\n"
               . "    ],\n"
               . ($claveNueva !== ''
                   ? "    /* Secreto de esta instalación. Generado al instalar;\n"
                   . "       no lo copies a otra casa ni lo publiques. */\n"
                   . "    'auth' => ['app_key' => " . var_export($claveNueva, true) . "],\n"
                   : '')
               . "];\n";
/* NO se le toca el modo a este fichero. Probado y pagado.
 *
 * Le puse chmod 0640 pensando que así la contraseña de la base no
 * quedaba a la vista de cualquier usuario de la máquina. Resultado: el
 * instalador se ejecuta normalmente como root y el servidor web corre
 * como www-data, que con 0640 y dueño root NO puede leerlo. Al
 * actualizar, SSTT entero pasó a devolver 500 y no se podía ni entrar.
 * Lo cazó pruebas/actualizar.py; sin esa prueba habría salido en el
 * paquete.
 *
 * Para apretarlo haría falta saber con qué usuario corre el servidor,
 * y eso desde la consola no se sabe. Quien quiera apretarlo, que lo
 * haga a mano sabiendo su grupo:
 *     chown root:www-data api/config.local.php && chmod 640 ... */
        if (@file_put_contents($rutaLocal, $texto) !== false) {
            $log('· Configuración puesta a salvo en api/config.local.php.');
            $log('  A partir de ahora puedes actualizar sin perderla.');
            if ($claveNueva !== '') {
                $log('· Generada la clave secreta de esta instalación.');
            }
        }
    }
}

/* Si config.local.php ya existía pero sin clave propia, se le añade:
   actualizar no puede dejar a nadie con la clave de fábrica. */
if ($claveNueva !== '' && is_file($rutaLocal)) {
    $txt = (string)@file_get_contents($rutaLocal);
    if ($txt !== '' && !str_contains($txt, 'app_key')) {
        $nuevo = preg_replace(
            '/\];\s*$/',
            "    /* Secreto de esta instalación, generado al actualizar. */\n"
          . "    'auth' => ['app_key' => " . var_export($claveNueva, true) . "],\n];\n",
            $txt, 1);
        if (is_string($nuevo) && $nuevo !== $txt
            && @file_put_contents($rutaLocal, $nuevo) !== false) {
            $log('· Generada la clave secreta de esta instalación '
               . '(antes era la de fábrica, igual en todas).');
        }
    }
}

$sufijo = $config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite';
$log('Motor: ' . $config['db']['driver']);

/* Empezar de cero en MySQL.
 *
 * En SQLite bastaba con borrar el fichero, y eso era lo único que se
 * hacía: en MySQL, «--forzar» no borraba NADA. Quien creía haber empezado
 * de cero se quedaba con los datos anteriores mezclados con los nuevos,
 * sin un solo aviso.
 *
 * Se abre aquí su propia conexión porque en este punto la del programa
 * todavía no existe. Se tiran sólo las tablas de la base configurada: si
 * comparte servidor con otras, no se tocan. */
if ($config['db']['driver'] === 'mysql' && $forzar) {
    try {
        $c1 = $config['db'];
        $pdoF = new PDO(
            "mysql:host={$c1['host']};dbname={$c1['database']};charset=utf8mb4",
            $c1['user'], $c1['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tablas = $pdoF->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tablas) {
            $pdoF->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tablas as $t) {
                $pdoF->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string)$t) . '`');
            }
            $pdoF->exec('SET FOREIGN_KEY_CHECKS = 1');
            $log('· Base vaciada: ' . count($tablas) . ' tablas borradas.');
        }
        $pdoF = null;
    } catch (Throwable $e) {
        /* Esto NO es un aviso: es un «no se ha hecho lo que pediste».
         *
         * Con --forzar se pide empezar de cero. Si la base no se puede
         * vaciar, lo que queda es una mezcla: las tablas viejas con sus
         * datos y las nuevas encima. Y el instalador seguía adelante y
         * terminaba diciendo «Listo», así que quien lo lanzara se iba
         * convencido de tener una instalación limpia.
         *
         * Pasa de verdad y es fácil: los permisos que damos en
         * docs/INSTALACION.md no incluían DROP, así que --forzar fallaba
         * en MySQL nada más intentarlo. */
        $log('');
        $log('  NO SE HA PODIDO VACIAR LA BASE, así que NO se ha empezado de');
        $log('  cero. Lo que dijo la base de datos:');
        $log('    ' . $e->getMessage());
        $log('');
        if (str_contains($e->getMessage(), 'DROP command denied')) {
            $log('  Al usuario de la base le falta el permiso DROP. Dáselo con:');
            $log('    GRANT DROP ON `' . ($config['db']['database'] ?? 'tu_base')
               . '`.* TO \'' . ($config['db']['user'] ?? 'tu_usuario') . '\'@\'localhost\';');
            $log('    FLUSH PRIVILEGES;');
        }
        $log('  O borra y vuelve a crear la base a mano, y pasa el instalador');
        $log('  otra vez sin --forzar.');
        exit(1);
    }
}

if ($config['db']['driver'] === 'sqlite' && $forzar && is_file($config['db']['sqlite_path'])) {
    unlink($config['db']['sqlite_path']);
    @unlink($config['db']['sqlite_path'] . '-wal');
    @unlink($config['db']['sqlite_path'] . '-shm');
    $log('· Base de datos anterior eliminada.');
}

Db::init($config['db']);
Auth::init($config['auth']);

/* ---------------------------------------------------------------- */
/* 1. Esquema                                                       */
/* ---------------------------------------------------------------- */
$fichero = __DIR__ . '/../db/schema.' . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
$sql = file_get_contents($fichero);
if ($sql === false) {
    exit("No se encuentra $fichero\n");
}
// Se quitan los comentarios antes de partir por ';', porque si no
// una sentencia precedida de comentarios se descartaría entera.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

foreach (Db::sentencias($sql) as $sentencia) {
    if ($sentencia === '') {
        continue;
    }
    try {
        Db::pdo()->exec($sentencia);
    } catch (PDOException $e) {
        // "ya existe" es esperado al reinstalar
        if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'existe')) {
            $log('  aviso: ' . $e->getMessage());
        }
    }
}
/* Permisos de escritura: es lo que más falla en una instalación nueva,
   y da un error críptico si no se avisa antes. */
foreach ([
    __DIR__ . '/../db'          => 'la base de datos',
    __DIR__ . '/../api/uploads' => 'las fotografías',
] as $dir => $para) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_writable($dir)) {
        $log('');
        $log('  ATENCIÓN: no se puede escribir en ' . realpath($dir));
        $log('  Hace falta para ' . $para . '.');
        $log('  En Windows: botón derecho › Propiedades › Seguridad › dar permiso');
        $log('  de escritura al usuario que ejecuta Apache.');
        $log('');
    }
}

/* El candado de las carpetas de subidas, repasado en cada instalación y
   en cada actualización.

   Esto no es decorativo: la versión anterior dejaba un «php_flag engine
   off» suelto, y en un Apache sin mod_php —o sea, casi cualquiera de hoy,
   y cualquiera con PHP-FPM— eso es «Invalid command» y un error 500 en
   todo lo que haya debajo: TODAS las fotos del hotel dejan de verse. Y la
   comprobación antigua era «si el fichero existe, ya está», así que una
   instalación en marcha no se habría arreglado nunca sola. */
$candados = 0;
foreach ([__DIR__ . '/../api/uploads', __DIR__ . '/../api/uploads/instrucciones'] as $d) {
    if (!is_dir($d)) { continue; }
    $f = $d . '/.htaccess';
    $tiene = is_file($f) && str_contains((string)@file_get_contents($f), Uploads::CANDADO);
    if ($tiene) { continue; }
    if (@file_put_contents($f, Uploads::textoCandado()) !== false) {
        $candados++;
    } else {
        $log('  ATENCIÓN: no se ha podido escribir ' . $f);
        $log('  Sin ese fichero, algo subido podría ejecutarse en el servidor.');
    }
}
if ($candados > 0) { $log("· Carpetas de subidas protegidas: {$candados}."); }

$log($actualizar ? '· Esquema comprobado.' : '· Esquema creado.');


/* ---------------------------------------------------------------- */
/* Catálogos reales del hotel (313 elementos, 16 plantas, 509 lugares,
   1.285 ubicaciones). Sólo en instalación nueva: si has migrado desde
   la versión antigua ya los tienes y este paso no toca nada.          */
/* ---------------------------------------------------------------- */


$sembrar = static function (string $tabla, string $pk, array $filas) use ($log): void {
    $n = 0;
    foreach ($filas as $f) {
        if (Db::value("SELECT 1 FROM $tabla WHERE $pk = ?", [$f[$pk]])) {
            continue;
        }
        Db::insert($tabla, $f);
        $n++;
    }
    if ($n > 0) { $log("· $tabla: $n registros."); }
};

$sembrar('prioridades', 'id_prioridad', [
    ['id_prioridad'=>1,'nombre'=>'Baja',   'color'=>'#64748B','sla_horas'=>168,'orden'=>1],
    ['id_prioridad'=>2,'nombre'=>'Normal', 'color'=>'#0B5563','sla_horas'=>72, 'orden'=>2],
    ['id_prioridad'=>3,'nombre'=>'Alta',   'color'=>'#F2A007','sla_horas'=>24, 'orden'=>3],
    ['id_prioridad'=>4,'nombre'=>'Urgente','color'=>'#DC2626','sla_horas'=>4,  'orden'=>4],
]);

/* Los departamentos de un hotel. En instalación limpia no se siembran:
   los define el negocio, y «Servicios Técnicos» o «Pisos» no significan
   nada en una clínica o en un colegio. */
if (!$limpia) {
$sembrar('departamentos', 'id_departamento', [
    ['id_departamento'=>1,'nombre'=>'Servicios Técnicos','color'=>'#0B5563','activo'=>1],
    ['id_departamento'=>2,'nombre'=>'Pisos / Limpieza',  'color'=>'#7C3AED','activo'=>1],
    ['id_departamento'=>3,'nombre'=>'Recepción',         'color'=>'#0EA5E9','activo'=>1],
    ['id_departamento'=>4,'nombre'=>'Cocina / A&B',      'color'=>'#F2A007','activo'=>1],
    ['id_departamento'=>5,'nombre'=>'Informática',       'color'=>'#0D7C57','activo'=>1],
    ['id_departamento'=>6,'nombre'=>'Jardinería',        'color'=>'#15803D','activo'=>1],
    ['id_departamento'=>7,'nombre'=>'Seguridad',         'color'=>'#334155','activo'=>1],
]);
}

/* El núcleo —los estados— va antes que las ampliaciones: una de ellas
   marca cuál es el estado final, y si los estados aún no existen se queda
   sin marcar y luego no se puede cerrar ningún parte. */
if ((int)Db::value('SELECT COUNT(*) FROM estados') === 0) {
    $archivoNucleo = __DIR__ . '/../db/nucleo.' . $sufijo . '.sql';
    if (is_file($archivoNucleo)) {
        $sqlN = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($archivoNucleo));
        foreach (array_filter(array_map('trim', explode(";\n", $sqlN))) as $sentencia) {
            if (stripos($sentencia, 'INSERT') === false) { continue; }
            try { Db::pdo()->exec(rtrim($sentencia, "; \n")); } catch (PDOException $e) { /* ya estaban */ }
        }
        $log('· Cargado db/nucleo.' . $sufijo . '.sql.');
    }
}

/* Ampliación 2.2: configuración, campos propios, atajos, informes y copias */
$ampliacion = __DIR__ . '/../db/ampliacion.2.2.'
            . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($ampliacion)) {
    $sqlAmp = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($ampliacion));
    foreach (Db::sentencias($sqlAmp) as $sentencia) {
        if ($sentencia === '') { continue; }
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            // «ya existe» es lo esperado al reinstalar
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Ampliación aplicada (configuración, campos, atajos, informes, copias).');
}

/* Ampliación 2.3: familias de elementos */
$fam = __DIR__ . '/../db/ampliacion.2.3.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($fam)) {
    $sqlFam = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($fam));
    foreach (Db::sentencias($sqlFam) as $sentencia) {
        if ($sentencia === '') { continue; }
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Familias de elementos creadas.');
}

/* Ampliación 2.4: estados por clase y acciones rápidas */
$a24 = __DIR__ . '/../db/ampliacion.2.4.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a24)) {
    $sql24 = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($a24));
    foreach (Db::sentencias($sql24) as $sentencia) {
        if ($sentencia === '') { continue; }
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Estados ajustados por clase de parte.');
}

/* Ampliación 2.5: mantenimiento programado */
$a25 = __DIR__ . '/../db/ampliacion.2.5.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a25)) {
    $sql25 = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($a25));
    foreach (Db::sentencias($sql25) as $sentencia) {
        if ($sentencia === '') { continue; }
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Mantenimiento programado listo.');
}

/* Ampliación 2.6: seguridad, cuenta y avisos */
$a26 = __DIR__ . '/../db/ampliacion.2.6.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a26)) {
    $sql26 = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($a26));
    foreach (Db::sentencias($sql26) as $sentencia) {
        if ($sentencia === '') { continue; }
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Registro de seguridad y modo mantenimiento listos.');
}

/* Ampliación 2.8: instrucciones */
$a28 = __DIR__ . '/../db/ampliacion.2.8.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a28)) {
    $sql28 = (string)file_get_contents($a28);
    foreach (Db::sentencias($sql28) as $sentencia) {
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $log('· Instrucciones listas.');
}

/* Ampliación 2.9: unidades con número de serie */
$a29 = __DIR__ . '/../db/ampliacion.2.9.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a29)) {
    foreach (Db::sentencias((string)file_get_contents($a29)) as $sentencia) {
        try {
            Db::pdo()->exec($sentencia);
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) {
                $log('  aviso: ' . $m);
            }
        }
    }
    $n = (int)Db::value('SELECT COUNT(*) FROM elemento WHERE usa_serie = 1');
    $log("· Números de serie listos ($n tipos los usan).");
}

/* Ampliación 3.2: ajustes del formulario de alta */
$a32 = __DIR__ . '/../db/ampliacion.3.2.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a32)) {
    foreach (Db::sentencias((string)file_get_contents($a32)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Ajustes del formulario de alta listos.');
}

/* Ampliación 4.0: varios hoteles */
$a40 = __DIR__ . '/../db/ampliacion.4.0.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a40)) {
    foreach (Db::sentencias((string)file_get_contents($a40)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Varios hoteles listos.');
}

/* Ampliación 5.0: avisos push */
$a50 = __DIR__ . '/../db/ampliacion.5.0.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a50)) {
    foreach (Db::sentencias((string)file_get_contents($a50)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Avisos push listos.');
}

/* Ampliación 5.1: departamentos por hotel */
$a51 = __DIR__ . '/../db/ampliacion.5.1.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a51)) {
    foreach (Db::sentencias((string)file_get_contents($a51)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Departamentos por hotel listos.');
}

/* Ampliación 5.3: nombre de usuario y enlace de auto-entrada */
$a53 = __DIR__ . '/../db/ampliacion.5.3.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a53)) {
    foreach (Db::sentencias((string)file_get_contents($a53)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }

    /* El correo deja de ser obligatorio. En SQLite eso exige rehacer la
       tabla, y se hace aquí y no en el .sql, con cuatro precauciones:
         · sólo si hace falta —si el correo aún es obligatorio—, no en
           cada actualización;
         · con la definición y las columnas que tenga la tabla en ESTA
           base, no con una lista fija: una columna añadida más adelante
           no puede perderse;
         · dentro de una transacción: si se corta a medias no queda la
           tabla borrada y la copia sin renombrar;
         · recreando sus índices. */
    if ($config['db']['driver'] !== 'mysql') {
        $info = Db::all('PRAGMA table_info(login)');
        $col  = array_values(array_filter($info, fn($c) => $c['name'] === 'correo'))[0] ?? null;
        if ($col !== null && (int)$col['notnull'] === 1) {
            $pdo  = Db::pdo();
            $def  = (string)Db::value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'login'");
            $idx  = array_column(Db::all(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'login' AND sql IS NOT NULL"), 'sql');
            $nueva = preg_replace('/(\bcorreo\b\s+[A-Za-z]+(?:\(\d+\))?)\s+NOT\s+NULL/i', '$1', $def, 1);
            $nueva = preg_replace('/^CREATE\s+TABLE\s+["`]?login["`]?/i', 'CREATE TABLE login_nueva', $nueva, 1);
            $cols  = implode(', ', array_map(fn($c) => '"' . $c['name'] . '"', $info));

            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                $pdo->exec('DROP TABLE IF EXISTS login_nueva');
                $pdo->exec($nueva);
                $pdo->exec("INSERT INTO login_nueva ($cols) SELECT $cols FROM login");
                $pdo->exec('DROP TABLE login');
                $pdo->exec('ALTER TABLE login_nueva RENAME TO login');
                foreach ($idx as $sqlIndice) { $pdo->exec($sqlIndice); }
                $pdo->commit();
                $log('· El correo ya no es obligatorio (' . count($info) . ' columnas y '
                     . count($idx) . ' índices conservados).');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $log('  aviso: no se ha podido quitar la obligación del correo: ' . $e->getMessage());
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
    $log('· Nombre de usuario y auto-entrada listos.');
}

/* Ampliación 5.8: mensajes por persona y frases del día */
$a58 = __DIR__ . '/../db/ampliacion.5.8.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a58)) {
    foreach (Db::sentencias((string)file_get_contents($a58)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Mensajes y frases del día listos.');
}

/* Ampliación 5.9: categorías de frases repetidas y frase en cada inicio */
$a59 = __DIR__ . '/../db/ampliacion.5.9.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a59)) {
    $repetidas = Db::tablaExiste('frases_categorias')
        ? (int)Db::value('SELECT COUNT(*) FROM frases_categorias') : 0;
    foreach (Db::sentencias((string)file_get_contents($a59)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $quedan = Db::tablaExiste('frases_categorias')
        ? (int)Db::value('SELECT COUNT(*) FROM frases_categorias') : 0;
    if ($repetidas > $quedan) {
        $log('· Categorías de frases repetidas: ' . ($repetidas - $quedan) . ' quitadas.');
    }
}

/* Ampliación 5.10: el departamento de un tipo sigue al de su familia */
$a510 = __DIR__ . '/../db/ampliacion.5.10.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a510)) {
    foreach (Db::sentencias((string)file_get_contents($a510)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Los tipos de avería siguen al departamento de su familia.');
}

/* Ampliación 5.11: alcance de los informes guardados */
$a511 = __DIR__ . '/../db/ampliacion.5.11.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a511) && Db::tablaExiste('informes')) {
    foreach (Db::sentencias((string)file_get_contents($a511)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Informes guardados: para mí, para mi departamento o para todos.');
}

/* Ampliación 5.12: conversación sobre un parte */
$a512 = __DIR__ . '/../db/ampliacion.5.12.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a512)) {
    foreach (Db::sentencias((string)file_get_contents($a512)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Conversación sobre los partes lista.');
}

/* Ampliación 5.13: campos obligatorios al crear partes */
$a513 = __DIR__ . '/../db/ampliacion.5.13.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a513) && Db::tablaExiste('ajustes_sistema')) {
    foreach (Db::sentencias((string)file_get_contents($a513)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); } catch (PDOException $e) { $log('  aviso: ' . $e->getMessage()); }
    }
    $log('· Campos obligatorios al crear partes listos.');
}

/* Ampliación 5.14: departamentos adicionales y permisos por persona */
$a514 = __DIR__ . '/../db/ampliacion.5.14.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a514)) {
    foreach (Db::sentencias((string)file_get_contents($a514)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Departamentos adicionales y permisos por persona listos.');
}

/* Ampliación 5.15: observadores de un parte */
$a515 = __DIR__ . '/../db/ampliacion.5.15.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a515)) {
    foreach (Db::sentencias((string)file_get_contents($a515)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Observadores de un parte listos.');
}

/* Ampliación 5.16: partes visibles a partir de una fecha */
$a516 = __DIR__ . '/../db/ampliacion.5.16.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a516)) {
    foreach (Db::sentencias((string)file_get_contents($a516)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Partes programados listos.');
}

/* Ampliación 5.17: registro de errores */
$a517 = __DIR__ . '/../db/ampliacion.5.17.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a517)) {
    foreach (Db::sentencias((string)file_get_contents($a517)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Registro de errores listo.');
}

/* Ampliación 5.18: quién ve cada instrucción */
$a518 = __DIR__ . '/../db/ampliacion.5.18.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a518)) {
    foreach (Db::sentencias((string)file_get_contents($a518)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Visibilidad de las instrucciones lista.');
}

/* Ampliación 5.19: tope de repeticiones y latido de tareas */
$a519 = __DIR__ . '/../db/ampliacion.5.19.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a519)) {
    foreach (Db::sentencias((string)file_get_contents($a519)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Mantenimientos con tope y generación automática listos.');
}

/* Ampliación 5.20: copia de los avisos a Teams */
$a520 = __DIR__ . '/../db/ampliacion.5.20.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a520)) {
    foreach (Db::sentencias((string)file_get_contents($a520)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Copia de avisos a Teams lista.');
}

/* Ampliación 5.21: instrucciones por bloques */
$a521 = __DIR__ . '/../db/ampliacion.5.21.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a521)) {
    foreach (Db::sentencias((string)file_get_contents($a521)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Instrucciones por bloques listas.');
}

/* Ampliación 5.22: el bloqueo por intentos se suelta solo */
$a522 = __DIR__ . '/../db/ampliacion.5.22.'
      . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a522)) {
    foreach (Db::sentencias((string)file_get_contents($a522)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· El bloqueo por intentos ya se suelta solo.');
}

/* Los enlaces de auto-entrada pasan a venir encendidos.
 *
 * Sólo se toca si nadie lo ha cambiado a mano: si una instalación lo
 * apagó a propósito —porque perdió una tablet, por ejemplo—, actualizar
 * no puede volver a encenderlo por su cuenta. */
try {
    /* Se hace UNA sola vez y queda marcado. Así, si después alguien lo
       apaga a propósito —se ha perdido una tablet—, la siguiente
       actualización no se lo vuelve a encender por su cuenta. */
    $yaHecho = Db::value("SELECT valor FROM ajustes_sistema WHERE clave = 'autologin_encendido_v752'");

    if ($yaHecho === null) {
        $actual = Db::value("SELECT valor FROM ajustes_sistema WHERE clave = 'autologin_global'");
        if ((string)$actual === '0') {
            Db::run("UPDATE ajustes_sistema SET valor = '1', updated_at = ? WHERE clave = 'autologin_global'",
                    [Db::now()]);
            $log('· Enlaces de auto-entrada encendidos. Se apagan en Configuración → Seguridad.');
        }
        Db::insert('ajustes_sistema', [
            'clave' => 'autologin_encendido_v752', 'valor' => '1', 'tipo' => 'interruptor',
            'categoria' => 'interno', 'titulo' => 'Auto-entrada revisada', 'orden' => 999,
            'updated_at' => Db::now(),
        ]);
    }
} catch (Throwable $e) { /* si no se puede, se queda como estaba */ }


/* Ampliación 5.7: índices para que el inicio no se frene al crecer */
$a57 = __DIR__ . '/../db/ampliacion.5.7.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a57)) {
    foreach (Db::sentencias((string)file_get_contents($a57)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Índices del inicio listos.');
}

/* Ampliación 5.6: a qué departamento va cada familia (una sola vez) */
$marca56 = Db::tablaExiste('ajustes_sistema')
    ? Db::value('SELECT valor FROM ajustes_sistema WHERE clave = ?', ['reparto_56']) : '1';
if ($marca56 === null) {
    $a56 = __DIR__ . '/../db/ampliacion.5.6.'
         . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
    if (is_file($a56)) {
        foreach (Db::sentencias((string)file_get_contents($a56)) as $sentencia) {
            try { Db::pdo()->exec($sentencia); }
            catch (PDOException $e) { $log('  aviso: ' . $e->getMessage()); }
        }
        /* Se deja marcado: el reparto se ajusta luego desde Familias y no
           se vuelve a tocar en cada actualización. */
        Db::insertarSiFalta('ajustes_sistema', [
            'clave' => 'reparto_56', 'valor' => '1', 'tipo' => 'texto',
            'categoria' => 'interno', 'titulo' => 'Reparto inicial por familias aplicado',
            'descripcion' => 'Marca interna: el reparto por departamentos ya se ajustó una vez.',
            'orden' => 0,
        ]);
        $log('· Reparto de familias por departamento ajustado.');
    }
}

/* Ampliación 5.5: los móviles se enteran de los partes eliminados */
$a55 = __DIR__ . '/../db/ampliacion.5.5.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a55)) {
    foreach (Db::sentencias((string)file_get_contents($a55)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'uplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Aviso a los móviles de partes eliminados listo.');
}

/* Ampliación 5.4: vista smartphone (y arreglo de tipos de la 5.3) */
$a54 = __DIR__ . '/../db/ampliacion.5.4.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a54)) {
    foreach (Db::sentencias((string)file_get_contents($a54)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Vista smartphone lista.');
}

/* Ampliación 5.2: quién hizo cada cosa en el registro */
$a52 = __DIR__ . '/../db/ampliacion.5.2.'
     . ($config['db']['driver'] === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
if (is_file($a52)) {
    foreach (Db::sentencias((string)file_get_contents($a52)) as $sentencia) {
        try { Db::pdo()->exec($sentencia); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (!str_contains($m, 'already exists') && !str_contains($m, 'duplicate')
                && !str_contains($m, 'Duplicate')) { $log('  aviso: ' . $m); }
        }
    }
    $log('· Registro con autor listo.');
}

/* Orden y significado de cada estado (el volcado original no los traía) */
$estadosMeta = [
    1 => [1, 1, 0, 1, '#2563EB'],  2  => [2,  0, 0, 1, '#7C3AED'],
    3 => [3, 0, 0, 1, '#F2A007'],  8  => [4,  0, 0, 1, '#0EA5E9'],
    6 => [5, 0, 0, 1, '#64748B'],  7  => [6,  0, 0, 1, '#64748B'],
    9 => [7, 0, 0, 1, '#B45309'],  10 => [8,  0, 1, 1, '#0D7C57'],
    4 => [9, 0, 1, 1, '#0D7C57'],  5  => [10, 0, 1, 1, '#C7362B'],
    100 => [11, 0, 1, 0, '#334155'],
];
foreach ($estadosMeta as $idEstado => [$orden, $inicial, $final, $activo, $color]) {
    Db::run(
        'UPDATE estados SET orden = ?, es_inicial = ?, es_final = ?, activo = ?, color = ?, updated_at = ?
          WHERE id_estado = ?',
        [$orden, $inicial, $final, $activo, $color, Db::now(), $idEstado]
    );
}
Db::run('UPDATE estados SET orden = id_estado + 100 WHERE orden = 0');

/* Icono por familia de elemento */
$familias = [
    'gota'     => ['agua','grifo','fuga','lavabo','ducha','bañera','inodoro','cisterna','desagüe','sifón','fontan','tuber','arqueta','bomba','riego','piscina','alcachofa'],
    'rayo'     => ['luz','luces','lámpara','aplique','bombilla','enchufe','interruptor','eléctric','cuadro','diferencial','foco','led','fluorescente','halógen','corriente','generador'],
    'viento'   => ['aire','climat','split','ventilad','extract','calefac','caldera','radiador','termo','refriger','frigorí','congelad','abatidor'],
    'martillo' => ['puerta','ventana','marco','carpint','madera','mueble','armario','cajón','estante','bisagra','persiana','mampara','albañil','baldosa','pintura','pared','techo','suelo'],
    'silla'    => ['silla','sillón','mesa','cama','colchón','somier','sofá','butaca','escritorio','tumbona','hamaca','mobiliario','cabecero','mesilla'],
    'tele'     => ['tv','televis','pantalla','mando','canal','proyector','altavoz','bose','audio','sonido','megafon'],
    'wifi'     => ['wifi','wi-fi','internet','router','switch','ordenador','impresora','pms','tpv','informát','teléfono','cable','datos'],
    'llave'    => ['cerradur','llave','tarjeta','candado','caja fuerte','acceso','barrera','torno','cierre'],
    'brillo'   => ['limpiez','amenit','toalla','sábana','albornoz','almohada','alfombr','cortina','lencer','ambient','aroma','papeler'],
];
$conIcono = 0;
foreach (Db::all('SELECT id_elemento, nombre_elemento FROM elemento') as $el) {
    $nombre = mb_strtolower($el['nombre_elemento']);
    foreach ($familias as $icono => $claves) {
        foreach ($claves as $clave) {
            if (str_contains($nombre, $clave)) {
                Db::run('UPDATE elemento SET icono = ? WHERE id_elemento = ?', [$icono, (int)$el['id_elemento']]);
                $conIcono++;
                continue 3;
            }
        }
    }
}
if ($conIcono) { $log("· $conIcono elementos con icono asignado por familia."); }

/* ---------------------------------------------------------------- */
/* 2. Catálogos que no existían en la versión 1.x                   */
/* ---------------------------------------------------------------- */



/* Lo que se carga y de dónde.
 *
 * El núcleo trae sólo lo imprescindible —los estados—: ni un tipo de
 * avería ni una habitación. Lo demás son paquetes opcionales:
 *
 *   paquetes/hotel/catalogo.*       los 313 tipos del sector
 *   paquetes/benalma/ubicaciones.*  las plantas y habitaciones del Benalma
 *
 * Así una instalación limpia sirve para cualquier negocio, y Benalma pasa
 * a ser lo que es: un cliente con sus datos.
 *
 * Sin decir nada se cargan los dos paquetes, que es lo que se hacía hasta
 * ahora; con --limpia, ninguno. */
if ((int)Db::value('SELECT COUNT(*) FROM elemento') === 0) {

    $porCargar = [];
    if (!$limpia) {
        foreach (array_filter(array_map('trim', explode(',', $conPaquetes))) as $paq) {
            foreach (['organizacion', 'catalogo', 'campos', 'ubicaciones'] as $pieza) {
                $ruta = __DIR__ . "/../paquetes/{$paq}/{$pieza}.";
                if (is_file($ruta . $sufijo . '.sql')) { $porCargar[] = $ruta; }
            }
        }
    }

    foreach ($porCargar as $baseRuta) {
        $archivo = $baseRuta . $sufijo . '.sql';
        if (!is_file($archivo)) { continue; }
        $sqlCat = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($archivo));
        $n = 0;
        foreach (array_filter(array_map('trim', explode(";\n", $sqlCat))) as $sentencia) {
            if ($sentencia === '' || stripos($sentencia, 'INSERT') === false) { continue; }
            try { Db::pdo()->exec(rtrim($sentencia, "; \n")); $n++; }
            catch (PDOException $e) {
                $m = $e->getMessage();
                if (!str_contains($m, 'uplicate') && !str_contains($m, 'UNIQUE')) {
                    $log('  aviso: ' . substr($m, 0, 120));
                }
            }
        }
        if ($n > 0) {
            $log('· Cargado ' . basename(dirname($archivo)) . '/' . basename($archivo) . '.');
        }
    }
}

/* A qué hotel pertenece lo que traen los paquetes.
 *
 * Las ubicaciones del paquete llegan sin hotel, y con varios hoteles el
 * hotel no es un dato más: es la frontera. Sin él, la ficha de una
 * ubicación respondía «no se ha encontrado» aunque estuviera ahí, porque
 * el servidor no sabía de quién era.
 *
 * Se asigna el primer hotel, que en una instalación recién hecha es el
 * único que hay. */
/* Lo que se quedó suelto al borrar partes en MySQL.
 *
 * Hasta la 7.90, el borrado definitivo de un parte en MySQL no se llevaba
 * su historial ni sus fotos (ver MUEREN_CON_EL_PARTE en lib/Tickets.php).
 * Esas filas no se ven en ninguna parte y la papelera ya guarda su copia,
 * así que quitarlas de la base viva no pierde nada. */
$sueltos = 0;
foreach (['ticket_incidencias_historial', 'adjuntos', 'valores_campo'] as $tabla) {
    if (!Db::tablaExiste($tabla) || !in_array('id_ticket', Db::columnas($tabla), true)) { continue; }
    $sueltos += Db::run("DELETE FROM $tabla WHERE NOT EXISTS
                          (SELECT 1 FROM ticket_incidencias t WHERE t.id_ticket = $tabla.id_ticket)")->rowCount();
}
if ($sueltos > 0) {
    $log("· Quitadas $sueltos filas de partes que ya no existen (la papelera conserva su copia).");
}

if (Db::tablaExiste('hoteles')) {
    $primerHotel = Http::id(Db::value('SELECT id_hotel FROM hoteles ORDER BY id_hotel LIMIT 1'));
    if ($primerHotel !== null) {
        $sinHotel = 0;
        /* Las ubicaciones, primero por el hotel de sus propios niveles.
           Hasta la 7.90 una ubicación creada desde un parte se guardaba
           sin hotel aunque sus niveles sí lo tuvieran; con varios hoteles,
           «el primero» la habría mandado al hotel que no es. */
        if (Db::tablaExiste('rel_zonas') && in_array('id_hotel', Db::columnas('rel_zonas'), true)) {
            for ($n = 1; $n <= 4; $n++) {
                if (!Db::tablaExiste("zona_$n") || !in_array('id_hotel', Db::columnas("zona_$n"), true)) { continue; }
                $sinHotel += Db::run(
                    "UPDATE rel_zonas SET id_hotel = (SELECT z.id_hotel FROM zona_$n z
                                                        WHERE z.id_zona_$n = rel_zonas.id_zona_$n)
                      WHERE id_hotel IS NULL AND id_zona_$n IS NOT NULL
                        AND EXISTS (SELECT 1 FROM zona_$n z
                                     WHERE z.id_zona_$n = rel_zonas.id_zona_$n AND z.id_hotel IS NOT NULL)"
                )->rowCount();
            }
        }
        foreach (['rel_zonas', 'zona_1', 'zona_2', 'zona_3', 'zona_4'] as $tabla) {
            if (!Db::tablaExiste($tabla) || !in_array('id_hotel', Db::columnas($tabla), true)) { continue; }
            $sinHotel += Db::run("UPDATE {$tabla} SET id_hotel = ? WHERE id_hotel IS NULL",
                                 [$primerHotel])->rowCount();
        }
        if ($sinHotel > 0) {
            $log('· ' . $sinHotel . ' sitios asignados al hotel.');
        }
    }
}

/* Las rutas legibles de las ubicaciones, también DESPUÉS de los paquetes.
 *
 * Las ubicaciones llegan con sus niveles pero sin el texto «Planta 01 >
 * Habitaciones > 101 > Baño», que se calcula aquí. Al mover la carga de
 * paquetes detrás de las ampliaciones, este paso se ejecutaba sobre una
 * tabla vacía y las 1.285 ubicaciones se quedaban sin texto: la ficha de
 * una ubicación daba «no se ha encontrado» y las listas salían en blanco. */
/* La caché de ruta legible de cada ubicación (el volcado no la traía) */
$rutas = 0;
foreach (Db::all(
    'SELECT r.id_rel, z1.nombre_zona_1 AS n1, z2.nombre_zona_2 AS n2,
            z3.nombre_zona_3 AS n3, z4.nombre_zona_4 AS n4
       FROM rel_zonas r
       LEFT JOIN zona_1 z1 ON z1.id_zona_1 = r.id_zona_1
       LEFT JOIN zona_2 z2 ON z2.id_zona_2 = r.id_zona_2
       LEFT JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
       LEFT JOIN zona_4 z4 ON z4.id_zona_4 = r.id_zona_4
      WHERE r.ruta IS NULL OR r.ruta = ?', ['']
) as $r) {
    $ruta = implode(' > ', array_filter([$r['n1'], $r['n2'], $r['n3'], $r['n4']]));
    if ($ruta === '') { continue; }
    Db::run('UPDATE rel_zonas SET ruta = ? WHERE id_rel = ?', [$ruta, (int)$r['id_rel']]);
    $rutas++;
}
if ($rutas) { $log("· $rutas rutas de ubicación calculadas."); }

/* Las reglas de reparto, DESPUÉS de cargar los paquetes.
 *
 * La ampliación 5.10 marca la caja fuerte como excepción —está en
 * Equipamiento habitación, que lleva Pisos, pero la arregla Servicios
 * Técnicos— y realinea el resto con su familia. Al mover la carga de
 * paquetes detrás de las ampliaciones, eso se ejecutaba sobre una tabla
 * de tipos todavía vacía: en una instalación nueva la caja fuerte
 * acababa en Pisos y los partes se iban al departamento equivocado. */
if (Db::tablaExiste('elemento') && in_array('dep_propio', Db::columnas('elemento'), true)) {
    $marcadas = Db::run(
        "UPDATE elemento SET dep_propio = 1 WHERE nombre_elemento LIKE 'Caja fuerte%'")->rowCount();

    Db::run('UPDATE elemento
                SET id_departamento = (SELECT f.id_departamento FROM familias f
                                        WHERE f.id_familia = elemento.id_familia)
              WHERE COALESCE(dep_propio, 0) = 0 AND id_familia IS NOT NULL');

    $sstt = Http::id(Db::value("SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios T%'"));
    if ($sstt !== null) {
        Db::run('UPDATE elemento SET id_departamento = ? WHERE dep_propio = 1', [$sstt]);
    }
    if ($marcadas > 0) {
        $log('· Reparto por tipo comprobado (' . $marcadas . ' excepción(es)).');
    }
}

/* ---------------------------------------------------------------- */
/* 3. Administrador                                                 */

/* Una instalación limpia no trae departamentos: se crea uno para que el
   administrador pueda existir. Se renombra desde la aplicación o se
   sustituye importando una hoja. */
if ((int)Db::value('SELECT COUNT(*) FROM departamentos') === 0) {
    Db::insert('departamentos', ['nombre' => 'General', 'color' => '#0B5563', 'orden' => 1, 'activo' => 1]);
    $log('· Departamento «General» creado para el administrador.');
}
/* ---------------------------------------------------------------- */
$correoAdmin = 'daniel.melgar@hotelbenalma.com';
/* El primer administrador se crea cuando NO HAY NINGUNO, no cuando falta
 * este correo.
 *
 * Antes se miraba si existía el correo de fábrica. Mientras ese correo
 * fue siempre el mismo no se notaba; al cambiarlo en la 7.90, cualquier
 * instalación cuyo administrador se llamara de otra forma —todas las de
 * antes, y la de cualquier hotel que no sea el Benalma— recibía en cada
 * pasada del instalador un administrador más, con una contraseña al azar
 * que sólo salía por pantalla. Lo vio pruebas/actualizar.py. */
if (!Db::value('SELECT 1 FROM login WHERE admin = 1')) {
    $pwd = $demo ? (require __DIR__ . '/clave_demo.php') : bin2hex(random_bytes(6));
    Db::insert('login', [
        'correo' => $correoAdmin, 'pwd_php' => Auth::hashPassword($pwd),
        'nombre' => 'Administrador', 'apellido1' => 'del', 'apellido2' => 'Sistema',
        'rol' => 'admin', 'admin' => 1, 'bloqueo' => 0, 'intentos' => 0,
        'id_departamento' => 1, 'fecha_alta' => Db::now(), 'updated_at' => Db::now(),
    ]);
    $log('');
    $log('· Cuenta de administrador creada:');
    $log('    correo:     ' . $correoAdmin);
    $log('    contraseña: ' . $pwd);
    $log('  Cámbiala en cuanto entres.');
    $log('');
}

/* ---------------------------------------------------------------- */
/* 4. Datos de ejemplo                                              */
/* ---------------------------------------------------------------- */
if ($demo) {
    require __DIR__ . '/../api/lib/Tickets.php';
    require __DIR__ . '/demo.php';
    sembrarDemo($log);
}

/* Asignar hotel a quien no lo tenga.
   Va al final a propósito: las ampliaciones se aplican antes de crear
   las personas, así que su UPDATE no alcanzaba a nadie en una
   instalación nueva. Aquí ya existen todas. */
if (Db::tablaExiste('hoteles')) {
    $sinHotel = (int)Db::value('SELECT COUNT(*) FROM login WHERE id_hotel IS NULL');
    if ($sinHotel > 0) {
        Db::run('UPDATE login SET id_hotel = 1 WHERE id_hotel IS NULL');
        $log("· $sinHotel personas asignadas al hotel principal.");
    }
    foreach (['ticket_incidencias', 'rel_zonas', 'zona_1', 'zona_2', 'zona_3', 'zona_4',
              'unidades', 'mantenimientos', 'instrucciones', 'informes', 'atajos'] as $tabla) {
        if (Db::tablaExiste($tabla)) {
            Db::run("UPDATE $tabla SET id_hotel = 1 WHERE id_hotel IS NULL");
        }
    }
    if ((int)Db::value('SELECT COUNT(*) FROM login WHERE superadmin = 1') === 0) {
        $primero = Db::value('SELECT MIN(id_login) FROM login WHERE admin = 1');
        if ($primero !== null) {
            Db::run('UPDATE login SET superadmin = 1 WHERE id_login = ?', [(int)$primero]);
            $log('· Primer administrador convertido en superadministrador.');
        }
    }
}

/* La papelera nunca se toca al instalar ni al reinstalar.
   Es lo que queda de lo que ya se borró: perderla por reinstalar sería
   exactamente lo que la papelera existe para evitar. */
if (class_exists('Archivo') && is_file(Archivo::ruta())) {
    $n = 0;
    try {
        $n = (int)Archivo::pdo()->query('SELECT COUNT(*) FROM papelera_indice')->fetchColumn();
    } catch (Throwable $e) { /* recién creada */ }
    if ($n > 0) {
        $log("· Papelera intacta: $n registros conservados.");
    }
}

/* Partes sin departamento.
   Es obligatorio desde la 3.2, pero los partes anteriores no lo tienen y
   sin él «asignar persona» no ofrece a nadie. Se les pone el grupo
   principal, que es donde habrían ido. */
if (Db::tablaExiste('ticket_incidencias') && Db::tablaExiste('departamentos')) {
    $sinGrupo = (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias WHERE id_grupo_asignado IS NULL');
    if ($sinGrupo > 0) {
        $principal = Db::value('SELECT MIN(id_departamento) FROM departamentos');
        if ($principal !== null) {
            Db::run('UPDATE ticket_incidencias SET id_grupo_asignado = ? WHERE id_grupo_asignado IS NULL',
                    [(int)$principal]);
            $log("· $sinGrupo partes sin departamento asignados al principal.");
        }
    }
}


/* ------------------------------------------------------------------ */
/* Los tipos de avería, agrupados por área                             */
/* ------------------------------------------------------------------ */

/* Antes esto era un paso aparte que el instalador sólo recomendaba al
   final, y era fácil pasarlo por alto. Sin él, los 313 tipos se quedan
   sin área, y al dar un parte, en «¿Qué falla?», tocar un área la daba
   por elegida en vez de enseñar sus tipos: todas parecían vacías.

   Sólo toca los tipos que no tienen área: lo que un administrador haya
   colocado a mano no se mueve. Se ejecuta aislado —en su propio ámbito,
   para que sus variables no pisen las del instalador— y sin volcar su
   informe, que es largo. */
if (Db::tablaExiste('familias') && Db::tablaExiste('elemento')) {
    $sinArea = (int)Db::value('SELECT COUNT(*) FROM elemento WHERE id_familia IS NULL');
    if ($sinArea > 0) {
        ob_start();
        try {
            (function () {
                $CLASIFICAR_INCLUIDO = true;
                $argv = $GLOBALS['argv'] ?? [];
                include __DIR__ . '/clasificar.php';
            })();
        } finally {
            ob_end_clean();
        }
        $quedan = (int)Db::value('SELECT COUNT(*) FROM elemento WHERE id_familia IS NULL');
        $log('· ' . ($sinArea - $quedan) . ' tipos de avería agrupados por área.'
             . ($quedan ? " Quedan $quedan sin área." : ''));
    }

    /* Las excepciones por tipo, DESPUÉS de clasificar: el clasificador
       pone a cada tipo el departamento de su familia, así que puesta antes
       se la llevaba por delante. La caja fuerte está en «Equipamiento
       habitación», que es de Pisos, pero la arregla Servicios Técnicos. */
    $sstt = Db::value("SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios%' LIMIT 1");
    if ($sstt !== null) {
        Db::run("UPDATE elemento SET id_departamento = ? WHERE nombre_elemento LIKE 'Caja fuerte%'", [(int)$sstt]);
    }
}

/* ------------------------------------------------------------------ */
/* Nadie sin nombre de usuario                                        */
/* ------------------------------------------------------------------ */

/* A quien no tenga usuario se le pone uno a partir del correo:
   «carlos.duarte@hotelbenalma.com» pasa a ser «carlos.duarte». Si ya lo
   tiene otro, se numera: carlos.duarte, carlos.duarte2…

   Va al FINAL del instalador, no dentro de la ampliación 5.3: antes se
   hacía allí, y la cuenta de administrador que el instalador crea más
   abajo —y las personas de la demostración— nacían después, sin
   usuario. El usuario es obligatorio; nadie puede quedarse sin él. */
if (in_array('usuario_plano', Db::columnas('login'), true)) {
    $sinUsuario = Db::all('SELECT id_login, correo FROM login WHERE usuario IS NULL OR usuario = ?', ['']);
    foreach ($sinUsuario as $p) {
        $base = strtok((string)$p['correo'], '@') ?: ('usuario' . $p['id_login']);
        $base = Auth::normalizarUsuario($base);
        if (mb_strlen($base) < 3) { $base = 'usuario' . $p['id_login']; }
        $u = $base;
        $n = 2;
        while (Db::value('SELECT 1 FROM login WHERE usuario_plano = ? AND id_login <> ?',
                         [$u, (int)$p['id_login']]) !== null) {
            $u = $base . $n;
            $n++;
        }
        Db::run('UPDATE login SET usuario = ?, usuario_plano = ? WHERE id_login = ?',
                [$u, $u, (int)$p['id_login']]);
    }
    if ($sinUsuario) {
        $log('· ' . count($sinUsuario) . ' nombres de usuario puestos desde el correo.');
    }
}

if ($actualizar) {
    $log('');
    $log('Actualización terminada. No se ha tocado ningún dato:');
    $log('sólo se han creado las tablas y los ajustes que faltaban.');
} else {

$log('Listo. Abre www/index.html en el navegador.');
}
if (!$cli && !$actualizar) {
    $log('');
    $log('Y cuando termines de probar, protege o borra la carpeta tools/:');
    $log('cualquiera que conozca la dirección podría reinstalar encima.');
}
if (!$cli) {
    $log('Borra tools/instalar.php cuando termines.');
}

/* ------------------------------------------------------------------ */
/* Arranque del hotel: valores e iconos de partida                     */
/* ------------------------------------------------------------------ */
/* Va aquí, al final y fuera de las ramas: se aplica tanto al instalar
   como al actualizar, y después de los pasos que tocan iconos —si no,
   se lo llevaban por delante—. Una sola vez: lo que se ajuste luego
   desde la aplicación manda. */
/* Arranque del hotel: valores e iconos de partida. Una sola vez. */
if (Db::tablaExiste('ajustes_sistema')
    && Db::value('SELECT valor FROM ajustes_sistema WHERE clave = ?', ['arranque_benalma']) === null) {
    $arr = __DIR__ . '/arranque_benalma.php';
    if (is_file($arr)) {
        try { (static function () use ($arr, $log) { include $arr; })(); }
        catch (Throwable $e) { $log('  aviso: ' . $e->getMessage()); }
    }
    Db::insertarSiFalta('ajustes_sistema', [
        'clave' => 'arranque_benalma', 'valor' => '1', 'tipo' => 'texto',
        'categoria' => 'interno', 'titulo' => 'Arranque aplicado',
        'descripcion' => 'Marca interna: los valores e iconos de partida ya se pusieron una vez.',
        'orden' => 0,
    ]);
}
