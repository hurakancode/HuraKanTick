<?php
/**
 * Generar ubicaciones por rangos.
 *
 * Rellenar a mano una hoja con las 1.285 ubicaciones de un hotel es
 * absurdo cuando lo que hay detrás es «doce plantas, veinte habitaciones
 * cada una, y en cada habitación baño, dormitorio y terraza». Esto lo
 * escribe por ti.
 *
 * Ejemplos:
 *
 *   # doce plantas, habitaciones 01-20, tres sitios por habitación
 *   php tools/ubicaciones.php --plantas=1-12 --habitaciones=1-20 \
 *       --sublugares="Baño,Dormitorio,Terraza"
 *
 *   # lo mismo pero saltándose la planta 13 y con otro formato
 *   php tools/ubicaciones.php --plantas=1-12,14-16 --habitaciones=1-20 \
 *       --formato-lugar="{p}{n2}" --aplicar
 *
 *   # zonas comunes, sin numerar
 *   php tools/ubicaciones.php --planta="Planta baja" --zona="Zonas comunes" \
 *       --lugares="Recepción,Bar,Piscina,Gimnasio" --aplicar
 *
 *   # dejarlo en una hoja para repasarlo en Excel antes de crear nada
 *   php tools/ubicaciones.php --plantas=1-12 --habitaciones=1-20 --csv=hotel.csv
 *
 * Sin --aplicar sólo enseña lo que haría. Eso es a propósito: crear mil
 * ubicaciones equivocadas es mucho más fácil de hacer que de deshacer.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
if (!$cli && ($_GET['clave'] ?? '') !== 'instalar') {
    http_response_code(403);
    exit("Añade ?clave=instalar a la dirección.\n");
}

$config = require __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/compat.php';
require_once __DIR__ . '/../api/lib/Http.php';
require_once __DIR__ . '/../api/lib/Db.php';
require_once __DIR__ . '/../api/lib/Ubicaciones.php';
require_once __DIR__ . '/permiso_navegador.php';

Db::init($config['db']);

/* Un fichero mal hecho o un parámetro absurdo no deben acabar en un
   volcado de PHP: eso no le dice nada a quien está montando un hotel. */
set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent() && PHP_SAPI !== 'cli') { http_response_code(422); }
    echo "\n" . $e->getMessage() . "\n";
    exit(1);
});

$args = $cli ? array_slice($argv, 1) : [];
$opcion = static function (string $nombre, $porDefecto = null) use ($args) {
    foreach ($args as $a) {
        if (str_starts_with((string)$a, "--{$nombre}=")) {
            return substr((string)$a, strlen($nombre) + 3);
        }
    }
    return $_GET[$nombre] ?? $porDefecto;
};
$aplicar = in_array('--aplicar', $args, true) || isset($_GET['aplicar']);
$log = static function (string $t = '') { echo $t . "\n"; };

/* Crear ubicaciones en la base o volcar un CSV a disco: desde el
   navegador, sólo con permiso. El ensayo sigue abierto. Cuando lo llama
   el asistente ($INSTALADOR_INCLUIDO), su primer paso ya pasó el control. */
$incluido  = !empty($INSTALADOR_INCLUIDO);
$csvPedido = (string)($opcion('csv', '') ?? '');
if (!$incluido && ($aplicar || $csvPedido !== '')) {
    exigir_permiso_navegador_escritura('crear ubicaciones o escribir el CSV');
}

/* ------------------------------------------------------------------ */
/* Qué hay que generar                                                 */
/* ------------------------------------------------------------------ */

$plantasRango   = (string)($opcion('plantas', '') ?? '');
$plantaSuelta   = (string)($opcion('planta', '') ?? '');
$habitaciones   = (string)($opcion('habitaciones', '') ?? '');
$lugaresSueltos = (string)($opcion('lugares', '') ?? '');

if ($plantasRango === '' && $plantaSuelta === '') {
    $log('Falta decir las plantas. Ejemplos:');
    $log('  --plantas=1-12                 doce plantas numeradas');
    $log('  --planta="Planta baja"         una sola, con su nombre');
    $log('');
    $log('Y qué hay dentro:');
    $log('  --habitaciones=1-20            numeradas por planta');
    $log('  --lugares="Recepción,Bar"      una lista de sitios');
    $log('');
    $log('Opciones: --zona, --sublugares, --formato-planta, --formato-lugar,');
    $log('          --hotel, --csv=fichero.csv, --aplicar');
    return;
}
if ($habitaciones === '' && $lugaresSueltos === '') {
    $log('Falta decir qué hay en cada planta: --habitaciones=1-20 o --lugares="Recepción,Bar".');
    return;
}

$zona          = (string)($opcion('zona', $habitaciones !== '' ? 'Habitaciones' : 'Zonas comunes'));
$formatoPlanta = (string)($opcion('formato-planta', 'Planta {n2}'));
$formatoLugar  = (string)($opcion('formato-lugar', '{p}{n2}'));
$sublugares    = array_values(array_filter(array_map('trim',
                    explode(',', (string)($opcion('sublugares', '') ?? '')))));

$idHotel = (int)($opcion('hotel', 0) ?: 0);
if ($idHotel === 0) {
    $idHotel = (int)(Db::value('SELECT id_hotel FROM hoteles ORDER BY id_hotel LIMIT 1') ?? 1);
}
$nombreHotel = (string)(Db::value('SELECT nombre FROM hoteles WHERE id_hotel = ?', [$idHotel]) ?? '(sin nombre)');

/* ------------------------------------------------------------------ */
/* Armar la lista                                                      */
/* ------------------------------------------------------------------ */

$plantas = [];
if ($plantasRango !== '') {
    foreach (Ubicaciones::rango($plantasRango) as $n) {
        $plantas[] = ['nombre' => Ubicaciones::nombre($formatoPlanta, $n), 'numero' => $n];
    }
} else {
    foreach (explode(',', $plantaSuelta) as $nombre) {
        $nombre = trim($nombre);
        if ($nombre !== '') { $plantas[] = ['nombre' => $nombre, 'numero' => null]; }
    }
}

$filas = [];
foreach ($plantas as $p) {
    $lugares = [];
    if ($habitaciones !== '') {
        foreach (Ubicaciones::rango($habitaciones) as $n) {
            $lugares[] = Ubicaciones::nombre($formatoLugar, $n, $p['numero']);
        }
    } else {
        foreach (explode(',', $lugaresSueltos) as $l) {
            $l = trim($l);
            if ($l !== '') { $lugares[] = $l; }
        }
    }

    foreach ($lugares as $lugar) {
        if ($sublugares) {
            foreach ($sublugares as $sub) {
                $filas[] = ['planta' => $p['nombre'], 'zona' => $zona, 'lugar' => $lugar, 'sublugar' => $sub];
            }
        } else {
            $filas[] = ['planta' => $p['nombre'], 'zona' => $zona, 'lugar' => $lugar, 'sublugar' => ''];
        }
    }
}

/* ------------------------------------------------------------------ */
/* Enseñarlo antes de tocar nada                                       */
/* ------------------------------------------------------------------ */

$log('Hotel: ' . $nombreHotel . ' (' . $idHotel . ')');
$log(count($plantas) . ' plantas · ' . count($filas) . ' ubicaciones en total.');
$log('');
$log('Así quedarían las primeras:');
foreach (array_slice($filas, 0, 4) as $f) {
    $log('  ' . implode(' > ', array_filter([$f['planta'], $f['zona'], $f['lugar'], $f['sublugar']])));
}
if (count($filas) > 6) {
    $log('  …');
    $u = end($filas);
    $log('  ' . implode(' > ', array_filter([$u['planta'], $u['zona'], $u['lugar'], $u['sublugar']])));
}
$log('');

/* A una hoja, para repasarlo en Excel antes de crear nada. */
$csv = $csvPedido;
if ($csv !== '') {
    // Desde el navegador, el CSV sólo puede caer en db/: si no, la
    // dirección valdría para escribir un fichero en cualquier sitio.
    if (!$incluido) {
        $csv = ruta_fichero_navegador($csv, 'el CSV');
    }
    $f = fopen($csv, 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['planta', 'zona', 'lugar', 'sublugar'], ';');
    foreach ($filas as $fila) {
        fputcsv($f, [$fila['planta'], $fila['zona'], $fila['lugar'], $fila['sublugar']], ';');
    }
    fclose($f);
    $log('Escrito en ' . $csv . '. Repásalo en Excel y cárgalo con:');
    $log('  php tools/importar.php ubicaciones ' . $csv . ' --aplicar');
    return;
}

$r = Ubicaciones::crear($filas, $idHotel, $aplicar);

$log(($aplicar ? 'Creadas ' : 'Se crearían ') . $r['creadas'] . ' ubicaciones.');
if ($r['existentes']) { $log($r['existentes'] . ' ya existían y se han dejado como estaban.'); }
foreach (array_slice($r['problemas'], 0, 8) as $p) { $log('  · ' . $p); }
if (!$aplicar && $r['creadas'] > 0) {
    $log('');
    $log('Esto ha sido un ensayo. Repite con --aplicar para crearlas.');
}
