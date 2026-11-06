<?php
/**
 * Importar estructuras desde una hoja de cálculo.
 *
 *   php tools/importar.php --plantillas               deja las plantillas en db/plantillas/
 *   php tools/importar.php ubicaciones fichero.xlsx   ensaya, sin tocar nada
 *   php tools/importar.php ubicaciones fichero.xlsx --aplicar
 *
 * Cuatro estructuras: departamentos, ubicaciones, tipos y equipo. Siempre
 * se ensaya primero: dice cuántas filas crearía, cuáles ya existen y
 * cuáles no entiende. Nada se escribe hasta que se pide --aplicar.
 *
 * La idea es que montar un hotel nuevo sea rellenar cuatro hojas, no
 * escribir SQL ni dar de alta mil habitaciones a mano.
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
require_once __DIR__ . '/../api/lib/Auth.php';
require_once __DIR__ . '/../api/lib/Hoja.php';
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

$args    = $cli ? array_slice($argv, 1) : array_keys($_GET);
$aplicar = in_array('--aplicar', $args, true) || isset($_GET['aplicar']);
$log     = static function (string $t = '') { echo $t . "\n"; };

/* Escribir en la base (crear personas, ubicaciones, departamentos) o
   sacar las plantillas a disco: desde el navegador, sólo con permiso.
   Cuando lo llama el asistente de instalación ($INSTALADOR_INCLUIDO), el
   propio asistente ya ha pasado su control (base vacía o db/REINSTALAR)
   en su primer paso, y el fichero a importar es su subida temporal. */
$incluido = !empty($INSTALADOR_INCLUIDO);
if (!$incluido
    && ($aplicar || in_array('--plantillas', $args, true) || isset($_GET['plantillas']))) {
    exigir_permiso_navegador_escritura('importar o sacar plantillas');
}

/* ------------------------------------------------------------------ */
/* Las plantillas                                                      */
/* ------------------------------------------------------------------ */

/* Columnas de cada estructura, con un ejemplo. El ejemplo importa: una
   plantilla vacía deja a cualquiera adivinando qué se espera en cada
   columna, y ahí es donde se tuerce una importación. */
$PLANTILLAS = [
    'departamentos' => [
        'columnas' => ['nombre', 'color', 'orden'],
        'ejemplos' => [
            ['Servicios Técnicos', '#0B5563', '1'],
            ['Pisos / Limpieza',   '#7C3AED', '2'],
            ['Recepción',          '#0EA5E9', '3'],
        ],
        'ayuda' => 'Un departamento por fila. El color es opcional.',
    ],
    'ubicaciones' => [
        'columnas' => ['planta', 'zona', 'lugar', 'sublugar'],
        'ejemplos' => [
            ['Planta 01', 'Habitaciones', '101', 'Baño'],
            ['Planta 01', 'Habitaciones', '101', 'Dormitorio'],
            ['Planta 01', 'Habitaciones', '102', 'Baño'],
            ['Planta baja', 'Zonas comunes', 'Recepción', ''],
        ],
        'ayuda' => 'Una fila por sitio concreto. «Sublugar» puede ir en blanco.',
    ],
    'tipos' => [
        'columnas' => ['nombre', 'area', 'departamento', 'abreviatura'],
        'ejemplos' => [
            ['Cisterna',        'Fontanería',         'Servicios Técnicos', 'CISTE'],
            ['Sábanas',         'Lencería y textil',  'Pisos / Limpieza',   'SABAN'],
            ['Punto de acceso', 'Informática y redes','Informática',        'PTOAC'],
        ],
        'ayuda' => 'El área se crea si no existe. El departamento debe existir ya.',
    ],
    'equipo' => [
        'columnas' => ['usuario', 'nombre', 'apellido', 'correo', 'papel', 'departamento'],
        'ejemplos' => [
            ['daniel.melgar', 'Daniel', 'Melgar', 'daniel@ejemplo.com', 'admin',       'Servicios Técnicos'],
            ['carlos',        'Carlos', 'Duarte', '',                  'responsable', 'Servicios Técnicos'],
            ['recepcion',     'Recepción', '',    '',                  'reporta',     'Recepción'],
        ],
        'ayuda' => 'Papel: admin, responsable, tecnico o reporta. El correo es opcional.',
    ],
];

if (in_array('--plantillas', $args, true) || isset($_GET['plantillas'])) {
    $dir = __DIR__ . '/../db/plantillas';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    foreach ($PLANTILLAS as $nombre => $p) {
        $ruta = "$dir/$nombre.csv";
        $f = fopen($ruta, 'w');
        fwrite($f, "\xEF\xBB\xBF");                    // para que Excel respete las tildes
        fputcsv($f, $p['columnas'], ';');
        foreach ($p['ejemplos'] as $fila) { fputcsv($f, $fila, ';'); }
        fclose($f);
        $log("· $ruta   ({$p['ayuda']})");
    }
    $log('');
    $log('Ábrelas con Excel, cambia los ejemplos por tus datos y guárdalas.');
    $log('Valen tal cual en .csv, o guardadas como .xlsx.');
    return;
}

/* ------------------------------------------------------------------ */
/* Qué importar                                                        */
/* ------------------------------------------------------------------ */

$que     = null;
$archivo = null;
foreach ($args as $a) {
    $a = (string)$a;
    if (str_starts_with($a, '--')) { continue; }
    if ($que === null && isset($PLANTILLAS[$a])) { $que = $a; continue; }
    if ($archivo === null) { $archivo = $a; }
}
if (!$cli) {
    $que     = $_GET['que']     ?? $que;
    $archivo = $_GET['archivo'] ?? $archivo;
}

if ($que === null || $archivo === null) {
    $log('Uso: php tools/importar.php <' . implode('|', array_keys($PLANTILLAS)) . '> <fichero> [--aplicar]');
    $log('     php tools/importar.php --plantillas');
    return;
}

/* A qué hotel van las personas y los sitios. Sin esto, el equipo se
   quedaba sin hotel: en un sistema con cinco, una persona sin hotel no
   pinta nada y no se sabe qué le toca ver. */
$idHotel = (int)(Db::value('SELECT id_hotel FROM hoteles ORDER BY id_hotel LIMIT 1') ?? 1);
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--hotel=')) { $idHotel = (int)substr((string)$a, 8); }
}
if (isset($_GET['hotel'])) { $idHotel = (int)$_GET['hotel']; }
$nombreHotel = (string)(Db::value('SELECT nombre FROM hoteles WHERE id_hotel = ?', [$idHotel]) ?? '?');

// Desde el navegador, el fichero a leer sólo puede estar en db/: si no,
// la dirección valdría para leer cualquier fichero del servidor. (El
// asistente pasa su propia subida temporal, ya controlada.)
if (!$incluido) {
    $archivo = ruta_fichero_navegador((string)$archivo, 'el fichero a importar');
}
$filas = Hoja::leer($archivo);
/* ¿Trae las columnas que hacen falta? Sin esto, una hoja con las
   columnas cambiadas decía «creadas 0 filas» y a ver quién adivina. */
$necesarias = [
    'departamentos' => ['nombre'],
    'ubicaciones'   => ['planta', 'lugar'],
    'tipos'         => ['nombre'],
    'equipo'        => ['usuario'],
][$que] ?? [];
$tiene = array_keys($filas[0] ?? []);
$faltan = array_values(array_diff($necesarias, $tiene));
if ($faltan) {
    throw new ApiError(
        'A la hoja le faltan columnas: ' . implode(', ', $faltan) . '.' . "\n"
        . 'Tiene: ' . (implode(', ', $tiene) ?: '(ninguna)') . '.' . "\n"
        . 'Saca una plantilla con: php tools/importar.php --plantillas', 422);
}

$log(count($filas) . ' filas leídas de ' . basename($archivo) . '.');
if (in_array($que, ['equipo', 'ubicaciones'], true)) {
    $log('Hotel: ' . $nombreHotel . ' (' . $idHotel . ').');
}
$log($aplicar ? 'Aplicando…' : 'ENSAYO: no se escribe nada. Añade --aplicar cuando lo veas bien.');
$log('');

$hechos = 0; $existentes = 0; $problemas = [];

/* ------------------------------------------------------------------ */

if ($que === 'departamentos') {
    foreach ($filas as $i => $f) {
        $nombre = trim((string)($f['nombre'] ?? ''));
        if ($nombre === '') { $problemas[] = 'fila ' . ($i + 2) . ': sin nombre'; continue; }
        if (Db::value('SELECT id_departamento FROM departamentos WHERE nombre = ?', [$nombre])) {
            $existentes++;
            continue;
        }
        if ($aplicar) {
            Db::insert('departamentos', [
                'nombre' => mb_substr($nombre, 0, 80),
                'color'  => trim((string)($f['color'] ?? '')) ?: '#0B5563',
                'orden'  => (int)($f['orden'] ?? 0),
                'activo' => 1,
            ]);
        }
        $hechos++;
    }
}

if ($que === 'tipos') {
    foreach ($filas as $i => $f) {
        $nombre = trim((string)($f['nombre'] ?? ''));
        if ($nombre === '') { $problemas[] = 'fila ' . ($i + 2) . ': sin nombre'; continue; }
        if (Db::value('SELECT id_elemento FROM elemento WHERE nombre_elemento = ?', [$nombre])) {
            $existentes++;
            continue;
        }

        $idFam = null;
        $area = trim((string)($f['area'] ?? ''));
        if ($area !== '') {
            $idFam = Http::id(Db::value('SELECT id_familia FROM familias WHERE nombre = ?', [$area]));
            if ($idFam === null && $aplicar) {
                $idFam = Db::insert('familias', ['nombre' => mb_substr($area, 0, 80), 'clase' => 'averia']);
            }
        }

        $idDep = null;
        $dep = trim((string)($f['departamento'] ?? ''));
        if ($dep !== '') {
            $idDep = Http::id(Db::value('SELECT id_departamento FROM departamentos WHERE nombre = ?', [$dep]));
            if ($idDep === null) {
                $problemas[] = 'fila ' . ($i + 2) . ": el departamento «{$dep}» no existe";
            }
        }

        if ($aplicar) {
            Db::insert('elemento', [
                'nombre_elemento'      => mb_substr($nombre, 0, 120),
                'abreviatura_elemento' => mb_substr(trim((string)($f['abreviatura'] ?? '')), 0, 12) ?: null,
                'id_familia'           => $idFam,
                'id_departamento'      => $idDep,
                'baja'                 => 0,
            ]);
        }
        $hechos++;
    }
}

if ($que === 'equipo') {
    $papeles = ['admin' => 'admin', 'responsable' => 'responsable',
                'tecnico' => 'tecnico', 'reporta' => 'reporta'];
    foreach ($filas as $i => $f) {
        $usuario = Auth::normalizarUsuario(trim((string)($f['usuario'] ?? '')));
        if ($usuario === '') { $problemas[] = 'fila ' . ($i + 2) . ': sin usuario'; continue; }
        if (Db::value('SELECT id_login FROM login WHERE usuario = ?', [$usuario])) {
            $existentes++;
            continue;
        }
        $papel = $papeles[mb_strtolower(trim((string)($f['papel'] ?? 'tecnico')))] ?? null;
        if ($papel === null) {
            $problemas[] = 'fila ' . ($i + 2) . ': papel no reconocido «' . ($f['papel'] ?? '') . '»';
            continue;
        }
        $idDep = Http::id(Db::value('SELECT id_departamento FROM departamentos WHERE nombre = ?',
                                    [trim((string)($f['departamento'] ?? ''))]));
        if ($idDep === null) {
            $problemas[] = 'fila ' . ($i + 2) . ': departamento desconocido';
            continue;
        }
        if ($aplicar) {
            /* Contraseña al azar: nadie debe quedarse con una conocida por
               haber importado una hoja. Se entrega por «contraseña
               olvidada» o cambiándola el administrador. */
            Db::insert('login', [
                'usuario'         => $usuario,
                // La columna con la que se identifica uno: sin ella, la
                // cuenta se crea pero no sirve para entrar.
                'usuario_plano'   => $usuario,
                'correo'          => trim((string)($f['correo'] ?? '')) ?: null,
                'pwd_php'         => Auth::hashPassword(bin2hex(random_bytes(8))),
                'nombre'          => mb_substr(trim((string)($f['nombre'] ?? $usuario)), 0, 60),
                'apellido1'       => mb_substr(trim((string)($f['apellido'] ?? '')), 0, 60),
                'rol'             => $papel,
                'admin'           => $papel === 'admin' ? 1 : 0,
                'id_departamento' => $idDep,
                'id_hotel'        => $idHotel,
                'bloqueo'         => 0,
                'intentos'        => 0,
                'fecha_alta'      => Db::now(),
                'updated_at'      => Db::now(),
            ]);
        }
        $hechos++;
    }
}

if ($que === 'ubicaciones') {
    /* La creación la hace la pieza común, la misma que usa el generador
       por rangos: si mañana cambia cómo se arma una ubicación, cambia
       para los dos a la vez. */
    $r = Ubicaciones::crear($filas, $idHotel, $aplicar);
    $hechos     = $r['creadas'];
    $existentes = $r['existentes'];
    $problemas  = $r['problemas'];
}

/* ------------------------------------------------------------------ */

$log(($aplicar ? 'Creadas ' : 'Se crearían ') . $hechos . ' filas.');
if ($existentes) { $log($existentes . ' ya existían y se han dejado como estaban.'); }
if ($problemas) {
    $log('');
    $log('Lo que no he entendido (' . count($problemas) . '):');
    foreach (array_slice($problemas, 0, 15) as $p) { $log('  · ' . $p); }
    if (count($problemas) > 15) { $log('  · … y ' . (count($problemas) - 15) . ' más'); }
}
if (!$aplicar && $hechos > 0) {
    $log('');
    $log('Repite con --aplicar para crearlas de verdad.');
}
