<?php
/**
 * Generación de los partes de mantenimiento programado.
 *
 * Para una tarea diaria:
 *   30 6 * * *  php /ruta/hurakantick/tools/mantenimiento.php >> /var/log/hurakantick-mant.log 2>&1
 *
 *   php tools/mantenimiento.php            genera lo que toque
 *   php tools/mantenimiento.php --simular  dice qué haría, sin hacerlo
 *   php tools/mantenimiento.php --listar   muestra el calendario
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    $config = require __DIR__ . '/../api/config.php';
    if (!hash_equals((string)$config['auth']['app_key'], (string)($_GET['clave'] ?? ''))) {
        http_response_code(403);
        exit('No autorizado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$config = $config ?? require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';
require __DIR__ . '/../api/lib/Auth.php';
require __DIR__ . '/../api/lib/Permisos.php';
require __DIR__ . '/../api/lib/Repo.php';
require __DIR__ . '/../api/lib/Hoteles.php';
require __DIR__ . '/../api/lib/Push.php';
require __DIR__ . '/../api/lib/Registro.php';
require __DIR__ . '/../api/lib/Archivo.php';
require __DIR__ . '/../api/lib/Mantenimiento.php';

Db::init($config['db']);
Auth::init($config['auth']);

$args = PHP_SAPI === 'cli' ? array_slice($argv, 1) : array_keys($_GET);
$log = static function (string $m): void { echo gmdate('[Y-m-d H:i:s] ') . $m . "\n"; };

if (in_array('--listar', $args, true) || isset($_GET['listar'])) {
    $lista = Mantenimiento::listar();
    if (!$lista) { $log('No hay ningún mantenimiento programado.'); exit(0); }
    echo str_pad('Próximo', 12) . str_pad('Estado', 12) . str_pad('Cada', 16) . "Qué\n";
    echo str_repeat('-', 78) . "\n";
    foreach ($lista as $m) {
        $estado = !$m['activo'] ? 'inactivo'
                : ($m['vencido'] ? 'VENCIDO' : ($m['inminente'] ? 'inminente' : ''));
        echo str_pad((string)$m['proximo'], 12)
           . str_pad($estado, 12)
           . str_pad((string)$m['periodicidad'], 16)
           . $m['nombre'] . ($m['obligatorio'] ? '  [obligatorio]' : '') . "\n";
    }
    exit(0);
}

$simular = in_array('--simular', $args, true) || isset($_GET['simular']);
$log($simular ? 'Simulación: no se creará ningún parte.' : 'Generando partes de mantenimiento…');

$r = Mantenimiento::generar($log, $simular);

$log($simular
    ? 'Se crearían ' . $r['generados'] . ' partes.'
    : 'Creados ' . $r['generados'] . ' partes.');

/* Y de paso se vacía lo viejo de la papelera.
 *
 * Existe para arreglar un borrado por error, y esos se descubren en
 * días. El plazo por defecto es largo a propósito: si alguien vuelve de
 * vacaciones y pregunta por un parte, todavía está. */
if (!$simular && class_exists('Archivo')) {
    $diasPap = (int)Permisos::ajuste('dias_papelera', 180);
    if ($diasPap > 0) {
        try {
            $n = Archivo::purgarViejas($diasPap, 0);
            if ($n > 0) {
                $log("· Papelera: tiradas $n entradas de más de $diasPap días.");
            }
        } catch (Throwable $e) {
            $log('· Papelera: no se ha podido limpiar (' . $e->getMessage() . ')');
        }
    }
}
