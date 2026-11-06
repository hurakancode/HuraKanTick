<?php
/**
 * Restauración de una copia de seguridad.
 *
 *   php tools/restaurar.php copias/hurakantick-20260724-120000.json.gz
 *   php tools/restaurar.php --listar
 *
 * Sólo desde la línea de órdenes: borra los datos actuales y no debe
 * poder lanzarse desde el navegador por accidente.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script sólo se ejecuta desde la línea de órdenes.\n");
}

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';
require __DIR__ . '/../api/lib/Permisos.php';
require __DIR__ . '/../api/lib/Auth.php';
require __DIR__ . '/../api/lib/Hoteles.php';
require __DIR__ . '/../api/lib/Registro.php';
require __DIR__ . '/../api/lib/Copias.php';

Db::init($config['db']);

$log = static function (string $m): void { echo $m . "\n"; };
$args = array_slice($argv, 1);

/* Listar las copias disponibles */
if (!$args || in_array('--listar', $args, true)) {
    $dir = Copias::carpeta();
    $log('Copias en ' . $dir . ':');
    $hay = false;
    foreach (glob($dir . '/*.json*') ?: [] as $f) {
        $log(sprintf('  %-46s %8s KB   %s',
            basename($f), number_format(filesize($f) / 1024, 0), date('d/m/Y H:i', filemtime($f))));
        $hay = true;
    }
    if (!$hay) { $log('  (ninguna)'); }
    $log('');
    $log('Uso: php tools/restaurar.php <archivo>');
    exit(0);
}

$ruta = $args[0];
if (!is_file($ruta)) {
    $prueba = Copias::carpeta() . '/' . basename($ruta);
    if (is_file($prueba)) {
        $ruta = $prueba;
    } else {
        exit("No se encuentra el archivo: $ruta\n");
    }
}

$log('');
$log('  ATENCIÓN');
$log('  Se van a BORRAR todos los datos actuales y sustituirlos por los');
$log('  de la copia. Esto no se puede deshacer.');
$log('');
$log('  Archivo : ' . basename($ruta));
$log('  Base    : ' . ($config['db']['driver'] === 'mysql'
                        ? $config['db']['database'] . ' en ' . $config['db']['host']
                        : $config['db']['sqlite_path']));

if (Db::tablaExiste('ticket_incidencias')) {
    $log('  Ahora   : ' . (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias') . ' partes, '
                        . (int)Db::value('SELECT COUNT(*) FROM login') . ' personas');
}
$log('');

if (!in_array('--si', $args, true)) {
    echo '  Escribe RESTAURAR para continuar: ';
    $r = trim((string)fgets(STDIN));
    if ($r !== 'RESTAURAR') {
        exit("Cancelado. No se ha tocado nada.\n");
    }
}

$log('');
try {
    Copias::restaurar($ruta, $log);
    $log('');
    $log('Comprueba que puedes entrar antes de dar por buena la restauración.');
} catch (Throwable $e) {
    $log('');
    $log('ERROR: ' . $e->getMessage());
    $log('No se ha aplicado ningún cambio: la operación va en una transacción.');
    exit(1);
}
