<?php

/**
 * El equipo real del Hotel Benalma.
 *
 * Sustituye las personas de ejemplo por las de verdad, conservando los
 * partes: cada uno se reasigna a quien le corresponde por departamento,
 * así la guía enseña pantallas con nombres reconocibles en vez de
 * «Usuario 1».
 *
 *   php tools/equipo_benalma.php
 */
declare(strict_types=1);

/* Sólo desde la consola del servidor. Abierto desde el navegador, este
   script lo podía ejecutar cualquiera de la red del hotel. */
if (PHP_SAPI !== 'cli' && empty($EQUIPO_INCLUIDO)) {
    /* Desde la consola siempre; desde el navegador sólo cuando lo llama el
       formulario de instalación, que antes ha comprobado el fichero de
       permiso. Suelto en una dirección seguiría siendo un regalo: este
       script pone la misma contraseña conocida a todo el equipo. */
    http_response_code(403);
    exit("Este script sólo se ejecuta desde la consola del servidor,\n"
       . "o desde tools/instalar_gui.php con el fichero de permiso puesto.\n");
}

$config = require __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/lib/compat.php';
require_once __DIR__ . '/../api/lib/Http.php';
require_once __DIR__ . '/../api/lib/Db.php';
require_once __DIR__ . '/../api/lib/Auth.php';
require_once __DIR__ . '/../api/lib/Permisos.php';
require_once __DIR__ . '/../api/lib/Hoteles.php';
require_once __DIR__ . '/../api/lib/Registro.php';
require_once __DIR__ . '/../api/lib/Repo.php';
require_once __DIR__ . '/../api/lib/Archivo.php';
require_once __DIR__ . '/../api/lib/Bajas.php';
require_once __DIR__ . '/demo.php';

Db::init($config['db']);
Auth::init($config['auth'] ?? []);

$log = static fn(string $m) => print($m . PHP_EOL);
/* La misma contraseña conocida que la demo, y del mismo sitio.
 *
 * Aquí ponía «benalma2026»: una SEGUNDA contraseña conocida —va escrita
 * en este fichero y se enseña en pantalla al terminar— que el
 * diagnóstico no sabía que existía. Con el administrador entrando con
 * ella, decía que no quedaba ninguna cuenta con contraseña de fábrica.
 * Con una sola, el aviso la caza. Hay que cambiarla al entrar. */
$clave = (string)(require __DIR__ . '/clave_demo.php');

/* El departamento se busca por nombre: si alguien los renombró, se
   queda con el que más se parezca en vez de fallar. */
$dep = static function (string $nombre): ?int {
    $id = Db::value('SELECT id_departamento FROM departamentos WHERE nombre = ?', [$nombre]);
    if ($id !== null) { return (int)$id; }
    $id = Db::value('SELECT id_departamento FROM departamentos WHERE nombre LIKE ?', ['%' . $nombre . '%']);
    return $id !== null ? (int)$id : null;
};

/* Correo, nombre de usuario, nombre, apellido, papel, departamento.
   «pisos» y «recepcion» son cuentas de puesto: las usa quien esté de
   turno, y el correo que llevan es del hotel, no de una persona. */
$equipo = [
    ['daniel.melgar@hotelbenalma.com', 'daniel.melgar', 'Daniel',   'Melgar', 'admin',      'Servicios Técnicos'],
    ['carlos.duarte@hotelbenalma.com', 'carlos.duarte', 'Carlos',   'Duarte', 'responsable','Servicios Técnicos'],
    ['david.sstt@hotelbenalma.com',    'david',         'David',    '',       'tecnico',    'Servicios Técnicos'],
    ['fany.prieto@hotelbenalma.com',   'fany.prieto',   'Fany',     'Prieto', 'responsable','Pisos'],
    ['pisos@hotelbenalma.com',         'pisos',         'Pisos',    '',       'tecnico',    'Pisos'],
    ['recepcion@hotelbenalma.com',     'recepcion',     'Recepción','',       'reportador', 'Recepción'],
];

$hotel = (int)(Db::value('SELECT id_hotel FROM hoteles ORDER BY id_hotel LIMIT 1') ?? 1);
$nuevos = [];

Db::begin();
try {
    foreach ($equipo as [$correo, $usuario, $nombre, $apellido, $rol, $nombreDep]) {
        $ya = Db::value('SELECT id_login FROM login WHERE LOWER(correo) = ?', [mb_strtolower($correo)]);
        $plano = Auth::normalizarUsuario($usuario);
        $datos = [
            'correo'          => mb_strtolower($correo),
            'usuario'         => $usuario,
            'usuario_plano'   => $plano,
            'pwd_php'         => Auth::hashPassword($clave),
            'nombre'          => $nombre,
            'apellido1'       => $apellido,
            /* El segundo apellido se vacía, no se deja como estaba.
               La lista de arriba sólo lleva uno, y al actualizar a quien ya
               existía se le quedaba el de antes: el administrador que crea
               el instalador se llama «Administrador / del / Sistema», así
               que pasaba a ser «Daniel Melgar Sistema» en las listas, en
               los historiales y en cada parte que se le asignara. */
            'apellido2'       => '',
            'rol'             => $rol,
            'id_departamento' => $dep($nombreDep),
            'id_hotel'        => $hotel,
            'bloqueo'         => 0,
            'updated_at'      => Db::now(),
        ];
        if ($ya !== null) {
            Db::update('login', $datos, 'id_login = :id', ['id' => (int)$ya]);
            $nuevos[$correo] = (int)$ya;
            $log("· Actualizado: $correo");
        } else {
            $nuevos[$correo] = Db::insert('login', $datos);
            $log("· Creado: $correo ($rol, $nombreDep)");
        }
    }

    /* El indicador «admin» va aparte del rol: es lo que abre las
       pantallas de administración. Y Daniel manda además en toda la
       cadena, no sólo en su hotel. */
    /* El indicador «admin» sólo para el rol Administrador, que es lo que
       hace la pantalla de Equipo. Antes se ponía también a los
       responsables, y la demostración les daba más poder del que tienen en
       una instalación real. */
    Db::run("UPDATE login SET admin = CASE WHEN rol = 'admin' THEN 1 ELSE 0 END WHERE correo LIKE ?",
            ['%hotelbenalma.com']);
    Db::run('UPDATE login SET superadmin = 1, admin = 1 WHERE correo = ?',
            ['daniel.melgar@hotelbenalma.com']);

    /* Los partes que tenían las personas de ejemplo pasan a quien
       corresponde por departamento: sin esto la guía enseñaría partes
       asignados a gente que ya no está. */
    $porDepartamento = [
        $dep('Servicios Técnicos') => $nuevos['david.sstt@hotelbenalma.com'],
        $dep('Pisos')              => $nuevos['pisos@hotelbenalma.com'],
        $dep('Recepción')          => $nuevos['recepcion@hotelbenalma.com'],
    ];
    $movidos = 0;
    foreach ($porDepartamento as $idDep => $idPersona) {
        if ($idDep === null) { continue; }
        $movidos += Db::run(
            'UPDATE ticket_incidencias SET id_persona_asignada = ?
              WHERE id_grupo_asignado = ? AND id_persona_asignada IS NOT NULL',
            [$idPersona, $idDep])->rowCount();
    }
    $log("· $movidos partes reasignados al equipo real.");

    // Quien creó cada parte pasa a ser alguien del equipo real.
    Db::run('UPDATE ticket_incidencias SET id_realiza_accion = ? WHERE id_realiza_accion NOT IN
             (SELECT id_login FROM login WHERE correo LIKE ?)',
            [$nuevos['recepcion@hotelbenalma.com'], '%hotelbenalma.com']);

    /* Las personas de ejemplo se van, pero antes hay que soltar lo que
       cuelga de ellas: el historial y los avisos las señalan, y la clave
       foránea no deja borrarlas mientras alguien las apunte. Lo que
       hicieron se reasigna a quien ocupa ahora su puesto: el historial
       sigue contando lo que pasó, con un nombre que existe. */
    /* Quién es de ejemplo: la lista de la propia demo, no el dominio.
     *
     * Esto buscaba «%@sstt.local». Desde la 7.90 la demo trae correos
     * del hotel, así que no encontraba a nadie: «0 cuentas de ejemplo
     * retiradas», y «Test 1», «Test 2» y la recepción de ejemplo se
     * quedaban activos junto al equipo de verdad, con la contraseña del
     * manual. De ejemplo es lo que está en la demo y NO en el equipo. */
    $delEquipo = array_map(static fn($e) => mb_strtolower($e[0]), $equipo);
    $deEjemplo = array_values(array_diff(
        array_merge(array_map(static fn($p) => mb_strtolower($p[0]), personasDemo()),
                    correosDemoAntiguos()),
        $delEquipo));
    $viejas = $deEjemplo
        ? Db::all('SELECT id_login, id_departamento, correo FROM login WHERE LOWER(correo) IN ('
                . implode(',', array_fill(0, count($deEjemplo), '?')) . ')', $deEjemplo)
        : [];
    $porDep = $porDepartamento;
    $porDefecto = $nuevos['daniel.melgar@hotelbenalma.com'];

    foreach ($viejas as $v) {
        $rele = $porDep[(int)($v['id_departamento'] ?? 0)] ?? $porDefecto;
        $id = (int)$v['id_login'];

        Db::run('UPDATE ticket_incidencias SET id_realiza_accion = ? WHERE id_realiza_accion = ?', [$rele, $id]);
        Db::run('UPDATE ticket_incidencias SET id_persona_asignada = ? WHERE id_persona_asignada = ?', [$rele, $id]);
        Db::run('UPDATE ticket_incidencias_historial SET id_realiza_accion = ? WHERE id_realiza_accion = ?', [$rele, $id]);
        Db::run('UPDATE ticket_incidencias_historial SET id_login = ? WHERE id_login = ?', [$rele, $id]);

        Db::run('UPDATE ticket_incidencias_historial SET id_login_old = ? WHERE id_login_old = ?', [$rele, $id]);

        /* Todo lo demás que apunta a la persona, con la lista común
           (lib/Bajas.php). Aquí había una copia a mano a la que le
           faltaban siete tablas: en una base usada, el borrado chocaba
           con una clave foránea y se deshacía la operación entera. */
        Bajas::borrarDefinitivo($id, $rele, 'cuenta de ejemplo retirada por equipo_benalma');
    }

    $borradas = count($viejas);
    $log("· $borradas cuentas de ejemplo retiradas (su trabajo queda a nombre del equipo actual)."); 

    Db::commit();
} catch (Throwable $e) {
    Db::rollback();
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}

$log('');
$log('Equipo del Hotel Benalma listo. Contraseña de todos: ' . $clave);
