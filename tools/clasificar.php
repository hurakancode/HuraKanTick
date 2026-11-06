<?php
/**
 * Clasificación de los elementos en familias.
 *
 *   php tools/clasificar.php            informa de lo que haría
 *   php tools/clasificar.php --aplicar  lo aplica
 *   php tools/clasificar.php --dudosos  lista sólo lo que no encaja claro
 *
 * Las reglas se leen de arriba abajo y gana la primera que coincide,
 * así que el orden importa: «luz piscina» tiene que caer en Zonas
 * comunes antes de que la regla de Electricidad la reclame.
 *
 * Sólo toca elementos sin familia: reasignar a mano desde la aplicación
 * no se pierde al volver a ejecutarlo.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    // También desde el navegador: en un XAMPP recién montado no todo el
    // mundo tiene la línea de órdenes a mano, y este paso hace falta.
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['clave'] ?? '') !== 'instalar') {
        http_response_code(403);
        exit("Añade ?clave=instalar a la dirección.\n\n"
           . "  ?clave=instalar            ver qué haría\n"
           . "  ?clave=instalar&aplicar    aplicarlo\n"
           . "  ?clave=instalar&dudosos    ver los que no encajan\n");
    }
}

$config = require __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/compat.php';
require_once __DIR__ . '/../api/lib/Http.php';
require_once __DIR__ . '/../api/lib/Db.php';
require_once __DIR__ . '/permiso_navegador.php';

Db::init($config['db']);

$args    = $cli ? array_slice($argv, 1) : array_keys($_GET);
$aplicar = in_array('--aplicar', $args, true) || isset($_GET['aplicar'])
        || !empty($CLASIFICAR_INCLUIDO);   // el instalador lo llama para aplicar
$dudosos = in_array('--dudosos', $args, true) || isset($_GET['dudosos']);
$rehacer = in_array('--rehacer', $args, true) || isset($_GET['rehacer']);
/* «Rehacer» reescribe la familia de TODOS los tipos, también los que un
   administrador ha colocado a mano. Desde el navegador —con una clave
   que está en la documentación— no se permite. */
if (!$cli && $rehacer && empty($CLASIFICAR_INCLUIDO)) {
    http_response_code(403);
    exit("Rehacer la clasificación entera sólo se puede desde la consola del servidor.\n");
}
/* Aplicar escribe en la base. Cuando lo llama el instalador
   ($CLASIFICAR_INCLUIDO) ya se ha comprobado el permiso antes; una
   petición suelta del navegador, no. */
if ($aplicar && empty($CLASIFICAR_INCLUIDO)) {
    exigir_permiso_navegador_escritura('clasificar los tipos');
}

/* ---------------------------------------------------------------- */
/* Reglas. El orden manda: la primera que coincide gana.            */
/* ---------------------------------------------------------------- */
$reglas = [

    // --- Peticiones de cliente ------------------------------------
    // Van primero porque son frases inequívocas: «cama extra» sólo
    // puede ser una petición, mientras que «cama» a secas es mobiliario.
    [12, ['cama extra', 'cuna', 'juntar camas', 'montar cama', 'montar x',
          'salida tarde', 'salida imprevista', 'entrada imprevista',
          'prolonga', 'vip', 'bombones', 'carta de almohadas', 'aromaterapia',
          'ambientar', 'amenit', 'silla adaptada', 'silla extra',
          'hamaca extra', 'petición', 'peticion', 'directorios', 'calzador',
          'perchas', 'colgador']],

    // --- Trabajos de limpieza -------------------------------------
    [11, ['limpieza', 'repaso', 'segunda limpieza', 'habitación terminada',
          'habitacion terminada', 'revisión habitacion', 'revision habitacion',
          'revisar habitacion', 'mal olor', 'olores', 'humedad', 'aspiradora',
          'papelera', 'cenicero', 'escobilla', 'cepillo inodoro',
          'papel higiénico', 'papel higienico', 'secamanos', 'dispensador',
          'dosificador', 'vertedero', 'pegatinas', 'papeleria']],

    // --- Obra: los desconchones son obra aunque la pared sea la del TV
    [4,  ['desconchon', 'desconchón', 'pladur', 'escayola', 'albañil',
          'albanil', 'silicona', 'lijar', 'barniz', 'pintura', 'baldosa',
          'alicatad', 'rodapié', 'rodapie', 'zócalo', 'zocalo', 'tarima',
          'moqueta', 'pavimento', 'obra']],

    // --- Seguridad -------------------------------------------------
    [13, ['contra incendios', 'incendio', 'fuego', 'detector', 'humo',
          'emergencia', 'evacuación', 'evacuacion', 'extintor', 'manguera de',
          'alarma', 'cámara', 'camara', 'insecto', 'cartel rojo', 'salto']],

    // --- Zonas comunes ---------------------------------------------
    // Sin «terraza»: es una ubicación, no una familia. La puerta de la
    // terraza es carpintería y su luz es electricidad.
    [14, ['piscina', 'jacuzzi', 'sauna', 'spa', 'gimnasio', 'cinta de andar',
          'ascensor', 'montacargas', 'farolillo', 'lavander', 'lavadora',
          'tren de lavado', 'maletero', 'vallado', 'barandilla',
          'tumbona', 'hamaca', 'balinesa']],

    // --- Cocina ----------------------------------------------------
    // Antes de climatización: el abatidor y la campana son de cocina
    // aunque técnicamente sean frío y extracción.
    [9,  ['cocina', 'horno', 'marmita', 'crepera', 'corta fiambre',
          'cortadora', 'lavavajillas', 'lavaplatos', 'máquinas de hielo',
          'maquinas de hielo', 'hielera', 'batidora', 'microondas',
          'plancha cocina', 'vinoteca', 'tolva', 'abrelatas', 'vajilla',
          'cristalería', 'cristaleria', 'cubertería', 'cuberteria',
          'bandeja', 'maquinaria', 'abatidor', 'campana', 'baño maría',
          'bano maria', 'office']],

    // --- Equipamiento de la habitación -----------------------------
    // Cafetera, secador o plancha: cuando fallan son una avería, y quien
    // los repone es housekeeping. No encajan ni en peticiones ni en un
    // oficio concreto.
    [16, ['cafetera', 'caferera', 'nespresso', 'kettle', 'hervidor',
          'secador', 'plancha de habitación', 'plancha de habitacion',
          'minibar', 'caja fuerte', 'cuadro', 'espejo', 'jabonera',
          'portarollos', 'toallero', 'cepillo', 'número habitación',
          'numero habitacion', 'tarjetero luz']],

    // --- Oficios ---------------------------------------------------
    [1,  ['agua', 'grifo', 'fuga', 'gotera', 'lavabo', 'lavamanos', 'ducha',
          'bañera', 'banera', 'inodoro', 'cisterna', 'desagüe', 'desague',
          'sifón', 'sifon', 'fontan', 'tuber', 'arqueta', 'bomba de achique',
          'bombas algibe', 'alcachofa', 'bidé', 'bide', 'wc', 'urinario',
          'monomando', 'tapón', 'tapon', 'varilla', 'plato de ducha',
          'termo', 'calentador', 'baño']],

    [2,  ['luz', 'luces', 'lámpara', 'lampara', 'aplique', 'bombilla',
          'enchufe', 'interruptor', 'eléctric', 'electric', 'diferencial',
          'magnetot', 'foco', 'led', 'fluorescente', 'halógen', 'halogen',
          'corriente', 'generador', 'lectura', 'proyectores',
          'iluminación', 'iluminacion']],

    [3,  ['aire', 'climat', 'split', 'ventilad', 'extractor', 'calefac',
          'caldera', 'radiador', 'refriger', 'frigorí', 'frigori',
          'congelad', 'fan coil', 'enfriadora', 'rejilla', 'respiradero']],

    [6,  ['cerradur', 'llave', 'candado', 'onity', 'maneta', 'tirador',
          'retenedor', 'cierre', 'domotica', 'domótica', 'tarjetero']],

    [7,  ['tv', 'televis', 'pantalla', 'mando', 'canal', 'altavoz', 'bose',
          'audio', 'sonido', 'megafon', 'hilo musical', 'video wall',
          'totem', 'cartelería', 'carteleria', 'dock station']],

    [8,  ['wifi', 'wi-fi', 'internet', 'router', 'switch', 'ordenador',
          'impresora', 'fotocopiadora', 'pms', 'tpv', 'pda', 'comandero',
          'informát', 'informat', 'teléfono', 'telefono', 'centralita',
          'software', 'ipad', 'scanner', 'instalación / config',
          'instalacion / config']],

    [10, ['toalla', 'sábana', 'sabana', 'albornoz', 'almohada', 'colcha',
          'edredón', 'edredon', 'manta', 'plaid', 'cortina', 'visillo',
          'estor', 'foscurit', 'cubre colchón', 'cubre colchon', 'topper',
          'alfombrín', 'alfombrin', 'cojín', 'cojin', 'riel', 'funda',
          'lencer']],

    [4,  ['puerta', 'ventana', 'marco', 'carpint', 'madera', 'cajón', 'cajon',
          'estante', 'bisagra', 'persiana', 'mampara', 'pared', 'techo',
          'suelo', 'canto', 'cristal', 'barra', 'terraza']],

    [5,  ['cama', 'colchón', 'colchon', 'somier', 'silla', 'sillón', 'sillon',
          'sofá', 'sofa', 'butaca', 'taburete', 'mesa', 'mesita', 'escritorio',
          'armario', 'mobiliario', 'cabecero', 'pata', 'cuadro',
          'estanteria', 'estantería', 'soporte']],
];

/* ---------------------------------------------------------------- */
$normaliza = static function (string $t): string {
    $t = mb_strtolower(trim($t));
    return strtr($t, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
    ]);
};

$familias = [];
foreach (Db::all('SELECT id_familia, nombre, clase FROM familias ORDER BY orden') as $f) {
    $familias[(int)$f['id_familia']] = $f;
}
if (!$familias) {
    if (!empty($CLASIFICAR_INCLUIDO)) { return; }
    exit("No hay familias. Ejecuta antes la ampliación 2.3.\n");
}

$sql = 'SELECT id_elemento, nombre_elemento, id_familia FROM elemento';
if (!$rehacer) {
    $sql .= ' WHERE id_familia IS NULL';
}
$elementos = Db::all($sql . ' ORDER BY nombre_elemento');

if (!$elementos) {
    echo "Todos los elementos tienen ya familia. Usa --rehacer para reclasificar.\n";
    if (!empty($CLASIFICAR_INCLUIDO)) { return; }
    exit(0);
}

$asignados = [];
$sinCasar  = [];

foreach ($elementos as $el) {
    $nombre = $normaliza((string)$el['nombre_elemento']);
    $encontrada = null;

    foreach ($reglas as [$idFamilia, $claves]) {
        foreach ($claves as $clave) {
            if (str_contains($nombre, $normaliza($clave))) {
                $encontrada = $idFamilia;
                break 2;
            }
        }
    }

    if ($encontrada === null) {
        $sinCasar[] = $el;
        $encontrada = 15;                       // Otros
    }
    $asignados[$encontrada][] = $el['nombre_elemento'];
}

/* ---------------------------------------------------------------- */
/* Informe                                                          */
/* ---------------------------------------------------------------- */
if ($dudosos) {
    echo "Elementos que ninguna regla reconoce (" . count($sinCasar) . "):\n\n";
    foreach ($sinCasar as $e) {
        printf("  %-40s  (#%d)\n", $e['nombre_elemento'], $e['id_elemento']);
    }
    echo "\nQuedarían en «Otros». Reasígnalos desde Administración › Familias.\n";
    if (!empty($CLASIFICAR_INCLUIDO)) { return; }
    exit(0);
}

echo "Clasificación de " . count($elementos) . " elementos\n\n";
$total = 0;
foreach ($familias as $id => $f) {
    $n = count($asignados[$id] ?? []);
    if ($n === 0) { continue; }
    $total += $n;
    printf("  %-26s %-9s %3d   %s\n",
        $f['nombre'], '(' . $f['clase'] . ')', $n,
        mb_substr(implode(', ', array_slice($asignados[$id], 0, 5)), 0, 62)
        . (count($asignados[$id]) > 5 ? '…' : ''));
}

echo "\n  Reconocidos por alguna regla: " . ($total - count($sinCasar)) . "\n";
echo "  Sin regla, van a «Otros»:     " . count($sinCasar) . "\n";

if (!$aplicar) {
    echo "\nNo se ha escrito nada.\n";
    if ($cli) {
        echo "  php tools/clasificar.php --dudosos   ver los que no encajan\n";
        echo "  php tools/clasificar.php --aplicar   aplicarlo\n";
    } else {
        echo "  Añade &dudosos para ver los que no encajan\n";
        echo "  Añade &aplicar para aplicarlo\n";
    }
    if (!empty($CLASIFICAR_INCLUIDO)) { return; }
    exit(0);
}

/* ---------------------------------------------------------------- */
/* Aplicación                                                       */
/* ---------------------------------------------------------------- */
Db::begin();
try {
    $n = 0;
    foreach ($asignados as $idFamilia => $nombres) {
        foreach ($nombres as $nombre) {
            Db::run(
                'UPDATE elemento SET id_familia = ?, updated_at = ? WHERE nombre_elemento = ?'
                . ($rehacer ? '' : ' AND id_familia IS NULL'),
                [$idFamilia, Db::now(), $nombre]
            );
            $n++;
        }
    }

    /* El icono y el departamento de la familia, SÓLO a los tipos que se
       acaban de clasificar. Antes se aplicaban a todos: al clasificar un
       único tipo nuevo, se deshacía el reparto que el administrador
       hubiera ajustado a mano —los televisores a Informática, por
       ejemplo— y esos partes empezaban a llegar al departamento
       equivocado sin que nadie lo notara. Con «rehacer», que se pide a
       propósito, sí se aplica a todos. */
    $tocados = $asignados ? array_merge(...array_values($asignados)) : [];
    if ($rehacer) {
        $donde = 'id_familia IS NOT NULL';
        $valores = [];
    } else {
        $donde = $tocados
            ? 'nombre_elemento IN (' . implode(',', array_fill(0, count($tocados), '?')) . ')'
            : '1 = 0';
        $valores = $tocados;
    }
    Db::run('UPDATE elemento SET icono = (SELECT f.icono FROM familias f WHERE f.id_familia = elemento.id_familia)
              WHERE ' . $donde, $valores);
    // Los tipos marcados como excepción conservan su departamento.
    $sinExcepciones = in_array('dep_propio', Db::columnas('elemento'), true)
        ? ' AND COALESCE(dep_propio, 0) = 0' : '';
    Db::run('UPDATE elemento SET id_departamento =
                (SELECT f.id_departamento FROM familias f WHERE f.id_familia = elemento.id_familia)
              WHERE ' . $donde . $sinExcepciones, $valores);

    Db::commit();
    echo "\nAplicado a $n elementos.\n";
    echo "Revisa «Otros» en Administración › Familias y reasigna lo que haga falta.\n";
} catch (Throwable $e) {
    Db::rollback();
    echo "\nERROR: " . $e->getMessage() . "\nNo se ha aplicado nada.\n";
    if (!empty($CLASIFICAR_INCLUIDO)) { return; }
    exit(1);
}
