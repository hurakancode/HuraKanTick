#!/usr/bin/env php
<?php
/**
 * Los avisos al móvil, desde la consola.
 *
 * Sirve para dejarlos listos antes de que entre nadie y para saber por
 * qué no llegan cuando alguien dice «a mí no me avisa».
 *
 *   php tools/avisos.php              cómo está todo
 *   php tools/avisos.php --claves     genera las claves si faltan
 *   php tools/avisos.php --probar 3   manda un aviso de prueba a esa persona
 *
 * Aviso importante que ninguna configuración arregla: el navegador sólo
 * permite avisos en https (o en localhost). Sin certificado no hay nada
 * que configurar aquí, porque nadie podrá ni suscribirse. Mira
 * docs/HTTPS.md.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esto se ejecuta en la consola.\n");
}

require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';
require __DIR__ . '/../api/lib/Auth.php';
require __DIR__ . '/../api/lib/Permisos.php';
require __DIR__ . '/../api/lib/Registro.php';
require __DIR__ . '/../api/lib/Hoteles.php';
require __DIR__ . '/../api/lib/Errores.php';
require __DIR__ . '/../api/lib/Repo.php';
require __DIR__ . '/../api/lib/Push.php';

$config = require __DIR__ . '/../api/config.php';
date_default_timezone_set('UTC');
Db::init($config['db']);

$args    = array_slice($argv, 1);
$claves  = in_array('--claves', $args, true);
$iProbar = array_search('--probar', $args, true);

echo "\n=== AVISOS AL MÓVIL ===\n\n";

/* --- Las claves ---------------------------------------------------- */
$pub = (string)Permisos::ajuste('push_vapid_publica', '');

if ($pub === '' && $claves) {
    try {
        $v = Push::vapid();   // las crea y las guarda
        $pub = $v['publica'];
        echo "Claves generadas.\n";
        echo "  No las cambies nunca: hacerlo obliga a que las 50 personas\n";
        echo "  vuelvan a dar permiso en su móvil.\n\n";
    } catch (Throwable $e) {
        echo "No se han podido generar: " . $e->getMessage() . "\n";
        echo "Hace falta la extensión openssl de PHP.\n\n";
        exit(1);
    }
}

echo $pub === ''
    ? "Claves:        SIN GENERAR  (pásale --claves, o se crean solas la\n"
    . "                            primera vez que alguien active los avisos)\n"
    : "Claves:        puestas\n";

/* --- Quién está suscrito ------------------------------------------- */
if (!Db::tablaExiste('push_destinos')) {
    echo "Suscripciones: no existe la tabla. Pasa el instalador.\n\n";
    exit(1);
}

$n = (int)Db::value('SELECT COUNT(*) FROM push_destinos');
echo "Suscripciones: $n aparato(s)\n";

if ($n > 0) {
    $nombre = Db::nombreCompleto('l');
    foreach (Db::all("SELECT p.id_login, COUNT(*) AS cuantos, $nombre AS quien
                        FROM push_destinos p
                        LEFT JOIN login l ON l.id_login = p.id_login
                       GROUP BY p.id_login ORDER BY quien") as $f) {
        printf("   %-28s %d\n", $f['quien'] ?: ('persona ' . $f['id_login']), (int)$f['cuantos']);
    }
}

/* --- Sin quién, nada sirve ----------------------------------------- */
$personas = (int)Db::value('SELECT COUNT(*) FROM login WHERE bloqueo = 0');
if ($n === 0 && $personas > 0) {
    echo "\nNadie los tiene activados. Cada persona lo hace una vez, desde\n";
    echo "Ajustes → «Sonido, vibración y avisos». Hasta entonces los avisos\n";
    echo "quedan guardados y se ven al abrir la aplicación, pero el móvil no\n";
    echo "suena.\n";
}

/* --- Y el recordatorio que de verdad importa ----------------------- */
if (!(bool)Permisos::ajuste('forzar_https', false)) {
    echo "\n--------------------------------------------------------------\n";
    echo "ATENCIÓN: la aplicación no está en https.\n";
    echo "Hasta que tenga certificado, nadie puede activar los avisos: el\n";
    echo "navegador no lo permite fuera de https. Esto no se arregla desde\n";
    echo "aquí. Mira docs/HTTPS.md.\n";
    echo "--------------------------------------------------------------\n";
}

/* --- Prueba -------------------------------------------------------- */
if ($iProbar !== false) {
    $id = (int)($args[$iProbar + 1] ?? 0);
    if ($id <= 0) {
        echo "\nDime a quién: php tools/avisos.php --probar <id de la persona>\n";
        exit(1);
    }
    // {$id} con llaves: pegado a «…», PHP leía una variable llamada «$id…»
    // (los bytes de ese carácter valen como letras de un nombre) y el
    // número no salía. Lo encontró pruebas/variables.php.
    echo "\nMandando un aviso de prueba a la persona {$id}…\n";
    try {
        $enviados = Push::enviar($id, 'Prueba de HuraKan Tick',
                                 'Si lees esto, los avisos funcionan en este aparato.',
                                 ['evento' => 'prueba', 'ruta' => '/panel']);
        echo $enviados > 0
            ? "  Salió a $enviados aparato(s).\n"
            : "  Esa persona no tiene ningún aparato suscrito.\n";
    } catch (Throwable $e) {
        echo "  Falló: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n";
exit(0);
