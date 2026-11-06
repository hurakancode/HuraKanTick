<?php

/**
 * Llena la base con el volumen de un hotel real.
 *
 * Los problemas de rendimiento no aparecen con los 28 partes de la
 * demo: aparecen a los dos años de uso, cuando ya hay miles. Esto los
 * adelanta para poder verlos ahora.
 *
 *     php pruebas/carga.php 20000
 *
 * Para volver a la demo:  php tools/instalar.php --demo --forzar
 */
declare(strict_types=1);

/* Sólo desde la consola del servidor. Abierto desde el navegador, este
   script lo podía ejecutar cualquiera de la red del hotel. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script sólo se ejecuta desde la consola del servidor.\n");
}

$raiz = dirname(__DIR__);
require $raiz . '/api/config.php';
foreach (['Db','Http','Auth','Permisos','Registro','Hoteles','Repo'] as $c) {
    $f = $raiz . "/api/lib/$c.php";
    if (is_file($f)) { require $f; }
}
Db::init($config['db']);

if (!Db::tablaExiste('ticket_incidencias')) {
    echo "No hay base de datos. Instálala primero:\n";
    echo "  php tools/instalar.php --demo\n";
    exit(2);
}

$zonas = Db::all('SELECT id_rel FROM rel_zonas WHERE id_hotel = 1');
if (!$zonas) { echo "No hay ubicaciones: pasa antes clasificar.php\n"; exit(2); }
$elems = Db::all('SELECT id_elemento, id_familia FROM elemento LIMIT 200');
$ests  = Db::all('SELECT id_estado, es_final FROM estados');
$gente = Db::all('SELECT id_login FROM login');

$N = (int)($argv[1] ?? 20000);
echo "creando $N partes...\n";
$t0 = microtime(true);

Db::pdo()->beginTransaction();
for ($i = 0; $i < $N; $i++) {
    $z = $zonas[array_rand($zonas)];
    $e = $elems[array_rand($elems)];
    $s = $ests[array_rand($ests)];
    $g = $gente[array_rand($gente)];
    $dias = random_int(0, 730);

    $id = Db::insert('ticket_incidencias', [
        'uuid'                => sprintf('%08x-carga-%06d', random_int(0, 0xffffffff), $i),
        'titulo_ticket'       => 'Parte de carga ' . $i . ' ' . ['Fuga','Ruido','Avería','Goteo','Fallo'][random_int(0,4)],
        'detalle_ticket'      => 'Generado para medir el comportamiento con volumen.',
        'id_elemento'         => $e['id_elemento'],
        'id_familia'          => $e['id_familia'],
        'id_rel_zona'         => $z['id_rel'],
        'id_estado'           => $s['id_estado'],
        'id_prioridad'        => random_int(1, 4),
        'id_realiza_accion'   => $g['id_login'],
        'id_persona_asignada' => random_int(0,3) ? $g['id_login'] : null,
        'id_grupo_asignado'   => random_int(1, 7),
        'id_hotel'            => 1,
        'borrado'             => 0,
        'fecha_creado'        => date('Y-m-d H:i:s', time() - $dias * 86400),
        'updated_at'          => date('Y-m-d H:i:s', time() - $dias * 86400),
    ]);
    if ($i % 4 === 0) {
        Db::insert('ticket_incidencias_historial', [
            'uuid' => sprintf('h%08x-%06d', random_int(0,0xffffffff), $i),
            'id_ticket' => $id, 'id_realiza_accion' => $g['id_login'],
            'tipo' => 'nota', 'detalle_ticket' => 'Movimiento de carga.',
            'fecha_creado' => date('Y-m-d H:i:s'), 'updated_at' => Db::now(),
        ]);
    }
}
Db::pdo()->commit();

printf("hecho en %.1f s\n", microtime(true) - $t0);
echo "partes: ", Db::value('SELECT COUNT(*) FROM ticket_incidencias'), "\n";
echo "historial: ", Db::value('SELECT COUNT(*) FROM ticket_incidencias_historial'), "\n";
/* El tamaño se pregunta distinto según el motor: en SQLite es un
   fichero, en MySQL hay que sumarlo de la propia base. Antes se leía
   siempre el fichero y con MySQL salía un aviso y un cero, que hacía
   pensar que la prueba no había hecho nada. */
if (Db::driver() === 'sqlite') {
    $ruta = $config['db']['sqlite_path'];
    $mb = is_file($ruta) ? filesize($ruta) / 1048576 : 0;
} else {
    $mb = (float)Db::value(
        "SELECT SUM(data_length + index_length) / 1048576
           FROM information_schema.tables WHERE table_schema = DATABASE()");
}
echo "tamaño: ", round($mb, 1), " MB (", Db::driver(), ")\n";
