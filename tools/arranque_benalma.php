<?php
/**
 * Los valores con los que arranca el Hotel Benalma.
 *
 * Se aplica una sola vez —queda marcado— para no pisar lo que se ajuste
 * después desde la aplicación. Trae:
 *
 *   · la vista smartphone puesta para todos;
 *   · tres accesos rápidos al dar un parte, los más usados del departamento;
 *   · los iconos de la aplicación y de las áreas que se pidieron;
 *   · y un icono para cada uno de los tipos de avería, elegido por lo que
 *     dice su nombre: «Cisterna» sale con un inodoro y «Extractor» con un
 *     ventilador, sin tener que ponerlos a mano uno a uno.
 *
 * Nada de esto es definitivo: todo se cambia desde Ajustes → Iconografía
 * y desde Configuración.
 */
declare(strict_types=1);

/* Se aplica desde tools/instalar.php, que le pasa la conexión y $log.
   Ejecutado suelto, reventaba con un error que no decía nada. */
if (!isset($log) || !is_callable($log)) {
    fwrite(STDERR, "Esto lo aplica el instalador una sola vez: php tools/instalar.php --actualizar\n");
    exit(1);
}

/** Devuelve el código de un icono de Font Awesome por su nombre. */
function hurakantick_codigo_icono(string $nombre, array $fa): ?string
{
    $ic = $fa['iconos'] ?? [];
    $al = $fa['alias'] ?? [];
    $n = isset($ic[$nombre]) ? $nombre : ($al[$nombre] ?? null);
    return $n !== null && isset($ic[$n]) ? (string)$ic[$n]['c'] : null;
}

/**
 * Qué icono le pega a un tipo de avería por su nombre.
 *
 * De arriba abajo, gana la primera que coincide: «luz de la piscina» debe
 * caer en piscina, no en luz, así que lo más concreto va antes.
 */
function hurakantick_icono_por_nombre(string $nombre): string
{
    $reglas = [
        ['/caja fuerte|caudal|seguridad/iu',                      'vault'],
        ['/ascensor|montacarga/iu',                               'elevator'],
        ['/piscina|jacuzzi|spa|sauna/iu',                         'person-swimming'],
        ['/gimnasio|fitness|cinta de correr|pesas/iu',            'dumbbell'],
        ['/parking|garaje|veh[íi]culo|coche/iu',                  'square-parking'],
        ['/jard[íi]n|planta|riego|c[ée]sped|[áa]rbol|poda/iu',    'seedling'],
        ['/lavadora|secadora|plancha|lavander/iu',                'jug-detergent'],
        ['/lavavajillas|abatidor|horno|freidora|c[áa]mara|nevera|frigor[íi]fic|cocina|office|cafetera|abrelatas|encimera/iu', 'kitchen-set'],
        ['/inodoro|cisterna|wc|sanitari/iu',                      'toilet'],
        ['/grifo|lavabo|ducha|ba[ñn]era|mezclador|desag[üu]e|sif[óo]n|fuga|tuber/iu', 'faucet'],
        ['/bomba|dep[óo]sito|agua|calder[ií]n|descalcificador/iu','droplet'],
        ['/dispensador|jab[óo]n|gel|amenit/iu',                   'soap'],
        ['/fregona|aspirador|carro de limpieza|papelera|basura|limpieza|repaso/iu', 'broom'],
        ['/s[áa]bana|toalla|almohada|colch[óo]n|funda|cortina de ducha|mantel|textil|lencer/iu', 'bed'],
        ['/enchufe|interruptor|cuadro|diferencial|magnetot|cableado|electric|embellecedor/iu', 'plug'],
        ['/bombilla|l[áa]mpara|luz|luminaria|fluorescent|led|foco/iu', 'lightbulb'],
        ['/aire acondicionado|fan\s*coil|climatiz|split|enfriadora|termostato/iu', 'fan'],
        ['/extractor|ventilador|aporte de aire|conducto/iu',      'fan'],
        ['/radiador|calefacci[óo]n|caldera|agua caliente/iu',     'fire-flame-simple'],
        ['/televis|tv\b|mando|canal|antena|sat[ée]lite/iu',       'tv'],
        ['/altavoz|m[úu]sica|sonido|megafon[íi]a|micr[óo]fono/iu','volume-high'],
        ['/wifi|red|router|switch|cableado de datos|internet/iu', 'wifi'],
        ['/ordenador|impresora|tpv|pantalla t[áa]ctil|terminal|inform[áa]tic/iu', 'computer'],
        ['/cerradura|llave|tarjeta|candado|bomb[íi]n|cierre/iu',  'key'],
        ['/puerta|marco|bisagra|manilla|corredera/iu',            'door-closed'],
        ['/ventana|persiana|toldo|mosquitera|contraventana/iu',   'window-maximize'],
        ['/silla|mesa|sof[áa]|butaca|taburete|banqueta/iu',       'chair'],
        ['/armario|c[óo]moda|estanter[íi]a|caj[óo]n|mueble/iu',   'couch'],
        ['/cama|somier|canap[ée]|cuna|supletoria/iu',             'bed'],
        ['/espejo|cuadro|decoraci[óo]n|l[áa]mina/iu',             'image'],
        ['/moqueta|suelo|baldosa|pavimento|tarima|rodapi[ée]/iu', 'trowel-bricks'],
        ['/pared|techo|pintura|yeso|humedad|grieta|obra/iu',      'hammer'],
        ['/extintor|bie|incendio|alarma|evacuaci[óo]n|emergencia/iu', 'fire-extinguisher'],
        ['/c[áa]mara de seguridad|videovigilancia|cctv/iu',       'video'],
        ['/recepci[óo]n|check|conserjer|petici[óo]n|cliente|hu[ée]sped/iu', 'bell-concierge'],
        ['/minibar|botella|copa|vaso|bar\b/iu',                   'wine-glass'],
        ['/caf[ée]|taza|desayuno/iu',                             'mug-saucer'],
        ['/maleta|equipaje|carro portamaletas/iu',                'suitcase-rolling'],
        ['/secador|plancha de pelo|b[áa]scula/iu',                'wind'],
        ['/escalera|peldaño|barandilla/iu',                       'stairs'],
    ];
    foreach ($reglas as [$patron, $icono]) {
        if (preg_match($patron, $nombre)) { return $icono; }
    }
    return 'screwdriver-wrench';   // lo que no encaja: herramientas
}

/* ------------------------------------------------------------------ */

$faRuta = __DIR__ . '/../www/assets/iconos/fontawesome.json';
$fa = is_file($faRuta) ? (json_decode((string)file_get_contents($faRuta), true) ?: []) : [];

if (!$fa) {
    $log('  aviso: no encuentro el índice de iconos; se dejan los de siempre.');
    return;
}

/* 1. Ajustes de arranque ------------------------------------------- */

/* Los ajustes nuevos los crea la aplicación la primera vez que alguien
   entra, no el instalador. Si no se crean aquí antes, este paso actualiza
   filas que todavía no existen —sin error y sin efecto— y los valores se
   quedan en los de fábrica. */
if (class_exists('Permisos')) { Permisos::asegurarAjustes(); }

$porDefecto = [
    'vista_movil_modo'      => 'todos',        // la vista smartphone, para todos
    'alta_rapidos_cantidad' => '3',
    'alta_rapidos_origen'   => 'departamento',
];
foreach ($porDefecto as $clave => $valor) {
    Db::run('UPDATE ajustes_sistema SET valor = ?, updated_at = ? WHERE clave = ?',
            [$valor, Db::now(), $clave]);
}

/* 2. Iconos de la aplicación --------------------------------------- */

$deLaApp = [
    'llaveinglesa' => 'circle-exclamation',    // «Tipo de avería»
    'herramientas' => 'screwdriver-wrench',
    'martillo'     => 'hammer',
    'brillo'       => 'lightbulb',             // «Iluminación»
];
$mapa = [];
foreach ($deLaApp as $interno => $nombreFA) {
    $c = hurakantick_codigo_icono($nombreFA, $fa);
    if ($c !== null) { $mapa[$interno] = $c; }
}
$yaHay = Db::value('SELECT valor FROM ajustes_sistema WHERE clave = ?', ['iconos_personalizados']);
$actual = json_decode((string)($yaHay ?: '{}'), true);
$actual = is_array($actual) ? $actual : [];
// Lo que ya se haya cambiado a mano manda sobre esto.
Db::run('UPDATE ajustes_sistema SET valor = ?, updated_at = ? WHERE clave = ?',
        [json_encode($mapa + $actual, JSON_UNESCAPED_UNICODE), Db::now(), 'iconos_personalizados']);

/* 3. Iconos de las áreas ------------------------------------------- */

$deFamilias = [
    'Carpintería y obra'      => 'hammer',
    'Cocina y office'         => 'utensils',
    'Limpieza y repasos'      => 'broom',
    'Lencería y textil'       => 'shirt',
    'Fontanería'              => 'droplet',
    'Electricidad'            => 'bolt',
    'Climatización'           => 'fan',
    'Mobiliario'              => 'chair',
    'Cerrajería y accesos'    => 'key',
    'Televisión y sonido'     => 'tv',
    'Informática y redes'     => 'wifi',
    'Peticiones de cliente'   => 'bell-concierge',
    'Seguridad y emergencias' => 'fire-extinguisher',
    'Zonas comunes'           => 'tree',
    'Equipamiento habitación' => 'bed',
    'Otros'                   => 'toolbox',
];
$nFam = 0;
foreach ($deFamilias as $nombre => $nombreFA) {
    $c = hurakantick_codigo_icono($nombreFA, $fa);
    if ($c === null) { continue; }
    $nFam += Db::run('UPDATE familias SET icono = ?, updated_at = ? WHERE nombre = ?',
                     ['fa-' . $c, Db::now(), $nombre])->rowCount();
}

/* 4. Un icono para cada tipo de avería ------------------------------ */

$nEl = 0;
foreach (Db::all('SELECT id_elemento, nombre_elemento FROM elemento') as $el) {
    $nombreFA = hurakantick_icono_por_nombre((string)$el['nombre_elemento']);
    $c = hurakantick_codigo_icono($nombreFA, $fa);
    if ($c === null) { continue; }
    Db::run('UPDATE elemento SET icono = ?, updated_at = ? WHERE id_elemento = ?',
            ['fa-' . $c, Db::now(), (int)$el['id_elemento']]);
    $nEl++;
}

$log("· Arranque del hotel: vista smartphone para todos, 3 accesos rápidos"
   . " por departamento, {$nFam} áreas y {$nEl} tipos con su icono.");
