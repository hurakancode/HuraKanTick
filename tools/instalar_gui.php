<?php
/**
 * Asistente de instalación.
 *
 *   tools/instalar_gui.php?clave=instalar
 *   tools/instalar.php?clave=instalar&gui=1     (la misma pantalla)
 *
 * Cinco pasos: base de datos, quién administra, qué se instala, los
 * sitios del negocio y un resumen. Nada se toca hasta el último paso.
 *
 * No repite la lógica de instalar: ejecuta los scripts de siempre
 * —instalar.php, clasificar.php, ubicaciones.php, importar.php— con sus
 * parámetros. Hereda sus protecciones, y si mañana cambian, cambia con
 * ellos. Lo que borra datos sigue necesitando el fichero db/REINSTALAR.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
if ($cli) {
    exit("Esto es el asistente para el navegador. Desde la consola:\n  php tools/instalar.php --limpia\n");
}
header('Content-Type: text/html; charset=utf-8');

if (($_GET['clave'] ?? '') !== 'instalar') {
    http_response_code(403);
    exit('Añade ?clave=instalar a la dirección.');
}

require_once __DIR__ . '/../api/lib/compat.php';
require_once __DIR__ . '/../api/lib/Http.php';
require_once __DIR__ . '/../api/lib/Db.php';

$RUTA_CONFIG = __DIR__ . '/../api/config.php';
$config = require $RUTA_CONFIG;

$e    = static fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$post = static fn(string $k, $d = '') => $_POST[$k] ?? $d;
$aqui = $e($_SERVER['REQUEST_URI'] ?? '?clave=instalar');
$paso = (int)($_POST['paso'] ?? 1);

/* El permiso para tocar datos: el fichero de siempre. */
$permiso = null;
foreach (['REINSTALAR', 'REINSTALAR.txt'] as $n) {
    if (is_file(__DIR__ . '/../db/' . $n)) { $permiso = __DIR__ . '/../db/' . $n; break; }
}
$permisoVale = $permiso !== null && (time() - (int)filemtime($permiso)) < 3600;

/* Como esta la base ahora mismo. */
$estado = ['instalada' => false, 'partes' => 0, 'personas' => 0];
try {
    Db::init($config['db']);
    $estado['instalada'] = Db::tablaExiste('login') && Db::tablaExiste('ticket_incidencias');
    if ($estado['instalada']) {
        $estado['personas'] = (int)Db::value('SELECT COUNT(*) FROM login');
        $estado['partes']   = (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias');
    }
} catch (Throwable $ex) { /* se dirá en su paso */ }

/* ------------------------------------------------------------------ */
/* Sobre un sistema YA INSTALADO, este asistente no hace nada sin el   */
/* fichero de permiso. Y esto no es una precaución de más.             */
/* ------------------------------------------------------------------ */
/*
 * La única llave que pedía era «?clave=instalar». Esa palabra está
 * escrita aquí arriba, en el código, que va dentro del paquete: no es
 * una llave, es un cartel. Y esta carpeta está permitida a propósito en
 * tools/.htaccess —el asistente tiene que poder abrirse—, así que el
 * candado de la carpeta tampoco tapaba esto.
 *
 * Resultado, comprobado en la instalación de pruebas con el hotel
 * funcionando: cualquiera de la red abría
 *
 *     http://servidor/hurakantick/tools/instalar_gui.php?clave=instalar
 *
 * y tenía el asistente delante. Sin el fichero REINSTALAR no podía
 * vaciar la base, cierto. Pero sí podía:
 *
 *   · reescribir api/config.php y apuntar el hotel a OTRA base de
 *     datos —la suya—, y entonces entra como administrador en su
 *     propia copia y el hotel se queda sin sistema;
 *   · marcar «demo» y crear las cuentas de ejemplo, cuya contraseña
 *     está escrita en el manual.
 *
 * O sea que lo que protegía los datos no protegía el acceso. Ahora la
 * regla es una sola y se entiende de una vez: **si ya hay un sistema
 * montado, el asistente por web no hace nada sin que alguien ponga un
 * fichero en el disco del servidor**. Quien puede poner un fichero ahí
 * es, por definición, quien manda en esa máquina.
 *
 * Para instalar de cero no cambia nada: si no hay base, no hay nada que
 * proteger y el asistente se abre como siempre.
 */
if ($estado['instalada'] && !$permisoVale) {
    http_response_code(403);
    $personas = $estado['personas'];
    $partes   = $estado['partes'];
    echo '<!doctype html><html lang="es"><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>HuraKan Tick ya está instalado</title>'
       . '<style>body{font:16px/1.6 system-ui,sans-serif;margin:0;'
       . 'padding:2.5rem 1.25rem;max-width:40rem;color:#223}'
       . 'code{background:#eef1f4;padding:.15em .4em;border-radius:.3em}'
       . 'h1{font-size:1.4rem}</style>'
       . '<h1>Aquí ya hay un HuraKan Tick funcionando</h1>'
       . '<p>Esta instalación tiene <strong>' . $personas . '</strong> persona(s) '
       . 'y <strong>' . $partes . '</strong> parte(s). El asistente de '
       . 'instalación no se abre sobre un sistema en marcha.</p>'
       . '<p>Si de verdad quieres usarlo, crea en el servidor un fichero vacío '
       . 'llamado <code>REINSTALAR</code> dentro de la carpeta <code>db/</code> '
       . 'y vuelve a entrar. El permiso dura una hora.</p>'
       . '<p>Y si lo que querías era actualizar, se hace desde la consola:<br>'
       . '<code>php tools/instalar.php</code></p>';
    exit;
}

/** Ejecuta un script de tools/ y se queda con lo que escribe. */
function correr(string $fichero, array $get, array $extras = []): array
{
    $antes = $_GET;
    $_GET = $get;
    ob_start();
    $ok = true;
    try {
        (static function () use ($fichero, $extras) {
            foreach ($extras as $k => $v) { ${$k} = $v; }
            $argv = [];
            include $fichero;
        })();
    } catch (Throwable $ex) {
        $ok = false;
        echo "\nERROR: " . $ex->getMessage();
    }
    $salida = trim((string)ob_get_clean());
    $_GET = $antes;
    if (stripos($salida, 'ERROR') !== false) { $ok = false; }
    return ['ok' => $ok, 'salida' => $salida !== '' ? $salida : '(sin mensajes)'];
}

/** Deja escrita la configuración de la base, conservando la anterior. */
function guardarConfig(string $ruta, array $d): string
{
    $s = (string)file_get_contents($ruta);
    copy($ruta, $ruta . '.bak');
    foreach (['driver','host','database','user','password'] as $clave) {
        $s = preg_replace("/('" . $clave . "'\s*=>\s*)'[^']*'/",
                          "\\1'" . str_replace("'", "\\'", (string)$d[$clave]) . "'", $s, 1);
    }
    file_put_contents($ruta, $s);

    /* PHP guarda los ficheros ya compilados y, si se lee en el mismo
       segundo en que se escribe, sirve el anterior. Eso hacía que el
       asistente dijera «instalado en MySQL» y lo instalara en SQLite,
       que es lo que había antes. Aquí se le obliga a releerlo. */
    clearstatcache(true, $ruta);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($ruta, true);
    }
    @touch($ruta, time() - 1);   // por si la caché mira la fecha

    return $ruta . '.bak';
}

/** Prueba la conexión con lo escrito en el formulario. */
function probarConexion(array $d): array
{
    try {
        if ($d['driver'] === 'sqlite') {
            $dir = dirname($d['sqlite_path']);
            if (!is_dir($dir))      { return [false, 'No existe la carpeta ' . $dir]; }
            if (!is_writable($dir)) { return [false, 'La carpeta ' . $dir . ' no deja escribir']; }
            new PDO('sqlite:' . $d['sqlite_path']);
            return [true, 'El fichero se puede crear y escribir.'];
        }
        $pdo = new PDO("mysql:host={$d['host']};charset=utf8mb4", $d['user'], $d['password'],
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $hay = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($d['database']))->fetch();
        if (!$hay) {
            if (empty($d['crear'])) {
                return [false, 'La base «' . $d['database'] . '» no existe. Marca «crearla» o créala tu.'];
            }
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $d['database'])
                     . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            return [true, 'Conecta, y he creado la base «' . $d['database'] . '».'];
        }
        return [true, 'Conecta y la base «' . $d['database'] . '» existe.'];
    } catch (Throwable $ex) {
        return [false, $ex->getMessage()];
    }
}

/** Repite en campos ocultos lo ya contestado. */
function ocultos(array $claves): string
{
    $html = '';
    foreach ($claves as $k) {
        if (!isset($_POST[$k])) { continue; }
        $html .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="'
               . htmlspecialchars((string)$_POST[$k], ENT_QUOTES) . '">';
    }
    return $html;
}

$TODOS = ['driver','host','database','usuario_bd','clave_bd','crear_bd','guardar_config',
          'modo','forzar','paq_hotel','paq_benalma','demo','clasificar','equipo_benalma',
          'admin_usuario','admin_nombre','admin_apellido','admin_correo','admin_clave',
          'bloquear_admin_defecto','sitios','plantas','habitaciones','lugares','sublugares','zona'];

/* ------------------------------------------------------------------ */
/* El último paso: hacerlo                                             */
/* ------------------------------------------------------------------ */

$pasos = [];
if ($paso === 5 && ($_POST['ejecutar'] ?? '') === '1') {

    $limpia = (string)$post('modo') === 'limpia';
    $forzar = !empty($_POST['forzar']);
    if (($forzar || !empty($_POST['equipo_benalma'])) && !$permisoVale) {
        $pasos[] = ['titulo' => 'Falta el permiso', 'ok' => false,
                    'salida' => "Lo que has marcado toca datos y necesita el fichero db/REINSTALAR.\n"
                              . 'Crealo y vuelve a empezar.'];
    } else {
        $seguir = true;
        $paquetes = [];
        if (!empty($_POST['paq_hotel']))   { $paquetes[] = 'hotel'; }
        if (!empty($_POST['paq_benalma'])) { $paquetes[] = 'benalma'; }

        /* La base de MySQL se creaba sólo al probar la conexión, en el
           primer paso. Quien llegara aquí por otro camino —volviendo
           atrás, o si alguien la borró entre medias— se encontraba un
           «Unknown database» en crudo. Se asegura aquí también. */
        if ((string)$post('driver') === 'mysql' && !empty($_POST['crear_bd'])) {
            [$vale, $dice] = probarConexion([
                'driver'   => 'mysql',
                'host'     => (string)$post('host', 'localhost'),
                'database' => (string)$post('database', 'hurakantick'),
                'user'     => (string)$post('usuario_bd', 'hurakantick'),
                'password' => (string)$post('clave_bd', ''),
                'crear'    => true,
            ]);
            if (!$vale) {
                $pasos[] = ['titulo' => 'La base de datos', 'ok' => false, 'salida' => $dice];
                $seguir = false;
            }
        }

        if ($seguir && !empty($_POST['guardar_config'])) {
            $bak = guardarConfig($RUTA_CONFIG, [
                'driver'   => (string)$post('driver', 'sqlite'),
                'host'     => (string)$post('host', 'localhost'),
                'database' => (string)$post('database', 'hurakantick'),
                'user'     => (string)$post('usuario_bd', 'hurakantick'),
                'password' => (string)$post('clave_bd', ''),
            ]);
            $pasos[] = ['titulo' => 'Configuración de la base guardada', 'ok' => true,
                        'salida' => "Escrita en api/config.php.\nLa anterior queda en " . basename($bak) . '.'];
            $config = require $RUTA_CONFIG;
        }

        $get = ['clave' => 'instalar'];
        if ($forzar) { $get['forzar'] = '1'; }
        if ($limpia) { $get['limpia'] = '1'; }
        else         { $get['paquetes'] = $paquetes ? implode(',', $paquetes) : 'hotel'; }
        if (!empty($_POST['demo'])) { $get['demo'] = '1'; }
        if (!$forzar && $estado['instalada']) { $get['actualizar'] = '1'; }
        if ($seguir) {
            $pasos[] = ['titulo' => $limpia ? 'Instalación limpia' : 'Instalación con paquetes']
                     + correr(__DIR__ . '/instalar.php', $get);
        }

        if ($seguir && !empty($_POST['clasificar'])) {
            $pasos[] = ['titulo' => 'Tipos agrupados por área']
                     + correr(__DIR__ . '/clasificar.php', ['clave' => 'instalar', 'aplicar' => '1'],
                              ['CLASIFICAR_INCLUIDO' => true]);
        }

        if ($seguir && (string)$post('sitios') === 'generar' && trim((string)$post('plantas')) !== '') {
            $g = ['clave' => 'instalar', 'plantas' => (string)$post('plantas'), 'aplicar' => '1'];
            foreach (['habitaciones','lugares','sublugares','zona'] as $campo) {
                if (trim((string)$post($campo)) !== '') { $g[$campo] = (string)$post($campo); }
            }
            $pasos[] = ['titulo' => 'Sitios creados']
                     + correr(__DIR__ . '/ubicaciones.php', $g, ['INSTALADOR_INCLUIDO' => true]);
        }

        if ($seguir && (string)$post('sitios') === 'hoja' && !empty($_FILES['hoja']['tmp_name'])) {
            $destino = sys_get_temp_dir() . '/hurakantick-' . bin2hex(random_bytes(4)) . '-'
                     . basename((string)$_FILES['hoja']['name']);
            move_uploaded_file($_FILES['hoja']['tmp_name'], $destino);
            $pasos[] = ['titulo' => 'Sitios importados de la hoja']
                     + correr(__DIR__ . '/importar.php',
                              ['clave' => 'instalar', 'que' => 'ubicaciones',
                               'archivo' => $destino, 'aplicar' => '1'],
                              ['INSTALADOR_INCLUIDO' => true]);
            @unlink($destino);
        }

        /* El equipo de demostración va ANTES del administrador: si crea
           una cuenta con el mismo usuario, el paso siguiente la actualiza
           en lugar de dejar dos iguales. */
        if ($seguir && !empty($_POST['equipo_benalma'])) {
            $pasos[] = ['titulo' => 'Equipo del Hotel Benalma']
                     + correr(__DIR__ . '/equipo_benalma.php', ['clave' => 'instalar'],
                              ['EQUIPO_INCLUIDO' => true]);
        }

        $usuario = trim((string)$post('admin_usuario'));
        $clave   = (string)$post('admin_clave');
        if ($seguir && $usuario !== '' && $clave !== '') {
            try {
                require_once __DIR__ . '/../api/lib/Auth.php';
                Db::init($config['db']);
                /* «usuario_plano» es la columna con la que se entra: sin
                   tildes y en minúsculas, y con el índice único encima. Si
                   no se rellena, la cuenta no sirve para identificarse y
                   además se cuela repetida, porque dos nulos no chocan. */
                $plano = Auth::normalizarUsuario($usuario);
                $ya = Db::value('SELECT id_login FROM login WHERE usuario_plano = ?', [$plano]);
                $datos = [
                    'usuario'       => $usuario,
                    'usuario_plano' => $plano,
                    'correo'    => trim((string)$post('admin_correo')) !== '' ? trim((string)$post('admin_correo')) : null,
                    'pwd_php'   => Auth::hashPassword($clave),
                    'nombre'    => mb_substr(trim((string)$post('admin_nombre')) !== '' ? trim((string)$post('admin_nombre')) : $usuario, 0, 60),
                    'apellido1' => mb_substr(trim((string)$post('admin_apellido')), 0, 60),
                    'rol' => 'admin', 'admin' => 1, 'bloqueo' => 0, 'intentos' => 0,
                    'id_departamento' => (int)(Db::value('SELECT id_departamento FROM departamentos ORDER BY id_departamento LIMIT 1') ?? 1),
                    'updated_at' => Db::now(),
                ];
                if ($ya) {
                    Db::update('login', $datos, 'id_login = :id', ['id' => (int)$ya]);
                    $texto = 'Actualizado el administrador «' . $usuario . '».';
                } else {
                    $datos['fecha_alta'] = Db::now();
                    Db::insert('login', $datos);
                    $texto = 'Creado el administrador «' . $usuario . '».';
                }
                $porDefecto = Db::value("SELECT id_login FROM login WHERE correo = 'daniel.melgar@hotelbenalma.com'");
                if ($porDefecto && !empty($_POST['bloquear_admin_defecto'])) {
                    Db::run('UPDATE login SET bloqueo = 1 WHERE id_login = ?', [(int)$porDefecto]);
                    $texto .= "\nLa cuenta de fábrica «admin» queda bloqueada.";
                }
                $pasos[] = ['titulo' => 'Administrador', 'ok' => true, 'salida' => $texto];
            } catch (Throwable $ex) {
                $pasos[] = ['titulo' => 'Administrador', 'ok' => false, 'salida' => $ex->getMessage()];
            }
        }


        try {
            Db::init($config['db']);
            $estado['instalada'] = Db::tablaExiste('login') && Db::tablaExiste('ticket_incidencias');
            $estado['personas']  = $estado['instalada'] ? (int)Db::value('SELECT COUNT(*) FROM login') : 0;
            $estado['partes']    = $estado['instalada'] ? (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias') : 0;
        } catch (Throwable $ex) { /* lo dirá el paso que falló */ }
    }
    $paso = 6;
}

/* Prueba de conexión pedida desde el paso 1 */
$prueba = null;
if ($paso === 2 && ($_POST['probar'] ?? '') === '1') {
    $prueba = probarConexion([
        'driver'      => (string)$post('driver', 'sqlite'),
        'host'        => (string)$post('host', 'localhost'),
        'database'    => (string)$post('database', 'hurakantick'),
        'user'        => (string)$post('usuario_bd', 'hurakantick'),
        'password'    => (string)$post('clave_bd', ''),
        'sqlite_path' => $config['db']['sqlite_path'] ?? (__DIR__ . '/../db/hurakantick.sqlite'),
        'crear'       => !empty($_POST['crear_bd']),
    ]);
    if (!$prueba[0]) { $paso = 1; }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalar HuraKan Tick</title>
<style>
  :root { --marca:#0B5563; --tinta:#0B191D; --tinta2:#5A6B70; --borde:#DCE3E5;
          --fondo:#F2F5F6; --ok:#15803D; --mal:#B91C1C; --aviso:#B45309; }
  * { box-sizing:border-box }
  body { margin:0; padding:18px; background:var(--fondo); color:var(--tinta);
         font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif }
  .caja { max-width:720px; margin:0 auto }
  h1 { font-size:22px; margin:0 0 2px }
  .sub { color:var(--tinta2); margin:0 0 16px }
  .pasos { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap }
  .pasos span { font-size:12px; padding:4px 10px; border-radius:99px; background:#fff;
                border:1px solid var(--borde); color:var(--tinta2) }
  .pasos .hoy { background:var(--marca); color:#fff; border-color:var(--marca) }
  .tarjeta { background:#fff; border:1px solid var(--borde); border-radius:14px; padding:16px; margin-bottom:14px }
  .tarjeta h2 { font-size:15px; margin:0 0 12px; text-transform:uppercase; letter-spacing:.04em; color:var(--tinta2) }
  label.campo { display:block; margin-bottom:12px }
  label.campo b { display:block; font-weight:640; margin-bottom:4px }
  label.campo small { color:var(--tinta2); display:block; margin-top:3px }
  input[type=text], input[type=password], input[type=file], select {
    width:100%; padding:11px 12px; font:inherit; border:1.5px solid var(--borde);
    border-radius:10px; background:#fff; color:var(--tinta) }
  label.op { display:flex; gap:12px; align-items:flex-start; padding:11px 0; border-top:1px solid var(--borde); cursor:pointer }
  label.op input { width:20px; height:20px; margin-top:2px; flex:0 0 auto }
  .op b { display:block; font-weight:650 }
  .op small { color:var(--tinta2) }
  .peligro { color:var(--mal) }
  .btn { width:100%; padding:14px; font-size:16px; font-weight:650; color:#fff; background:var(--marca);
         border:0; border-radius:12px; cursor:pointer; margin-top:6px }
  .nota { border-left:3px solid var(--aviso); background:#FFF8EC; padding:12px 14px; border-radius:0 10px 10px 0; margin-bottom:14px }
  .nota.ok { border-color:var(--ok); background:#F0FAF3 }
  .nota.mal { border-color:var(--mal); background:#FEF2F2 }
  pre { background:#0B191D; color:#D7E3E6; padding:12px; border-radius:10px; overflow:auto;
        font-size:13px; line-height:1.5; white-space:pre-wrap }
  .paso h3 { margin:0 0 6px; font-size:16px }
  .paso.mal h3 { color:var(--mal) } .paso.bien h3 { color:var(--ok) }
  .dato { display:flex; justify-content:space-between; gap:12px; padding:5px 0; border-top:1px solid var(--borde) }
  .dato:first-of-type { border-top:0 }
  a { color:var(--marca) }
</style>
</head>
<body>
<div class="caja">
  <h1>Instalar HuraKan Tick</h1>
  <p class="sub">Cinco pasos. Nada se toca hasta el último.</p>

  <div class="pasos">
<?php foreach (['Base de datos','Administrador','Qué instalar','Sitios','Resumen'] as $i => $t): ?>
    <span class="<?= ($i + 1) === min($paso, 5) ? 'hoy' : '' ?>"><?= $i + 1 ?>. <?= $e($t) ?></span>
<?php endforeach; ?>
  </div>

<?php if (!$permisoVale && $paso < 6): ?>
  <div class="nota">
    <b>Para borrar o tocar datos hace falta permiso.</b><br>
    Crea un fichero vacio llamado <code>REINSTALAR</code> —o <code>REINSTALAR.txt</code>— en la
    carpeta <code>db/</code> del servidor y recarga. Vale una vez y caduca en una hora.
  </div>
<?php endif; ?>

<?php if ($paso === 1): ?>
<?php if ($prueba && !$prueba[0]): ?>
  <div class="nota mal"><b>No conecta.</b><br><?= $e($prueba[1]) ?></div>
<?php endif; ?>
  <form method="post" action="<?= $aqui ?>">
    <input type="hidden" name="paso" value="2">
    <div class="tarjeta">
      <h2>Dónde se guardan los datos</h2>
      <label class="campo"><b>Motor</b>
        <select name="driver" onchange="document.getElementById('mysql').style.display = this.value === 'mysql' ? 'block' : 'none'">
          <option value="sqlite" <?= ($config['db']['driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite — un fichero, sin instalar nada</option>
          <option value="mysql"  <?= ($config['db']['driver'] ?? '') === 'mysql'  ? 'selected' : '' ?>>MySQL / MariaDB — servidor aparte</option>
        </select>
        <small>SQLite vale de sobra para un hotel. MySQL, si ya lo tienes montado.</small>
      </label>
      <div id="mysql" style="display:<?= ($config['db']['driver'] ?? '') === 'mysql' ? 'block' : 'none' ?>">
        <label class="campo"><b>Servidor</b><input type="text" name="host" value="<?= $e($config['db']['host'] ?? 'localhost') ?>"></label>
        <label class="campo"><b>Base de datos</b><input type="text" name="database" value="<?= $e($config['db']['database'] ?? 'hurakantick') ?>"></label>
        <label class="campo"><b>Usuario</b><input type="text" name="usuario_bd" value="<?= $e($config['db']['user'] ?? 'hurakantick') ?>"></label>
        <label class="campo"><b>Contraseña</b><input type="password" name="clave_bd" value=""></label>
        <label class="op"><input type="checkbox" name="crear_bd" value="1" checked>
          <span class="op"><b>Crear la base si no existe</b></span></label>
      </div>
      <label class="op"><input type="checkbox" name="guardar_config" value="1" checked>
        <span class="op"><b>Guardar esto en api/config.php</b>
          <small>La configuración anterior se conserva como config.php.bak.</small></span></label>
    </div>
    <button class="btn" type="submit" name="probar" value="1">Probar la conexión y seguir</button>
  </form>

<?php elseif ($paso === 2): ?>
<?php if ($prueba && $prueba[0]): ?>
  <div class="nota ok"><b>Conexión correcta.</b><br><?= $e($prueba[1]) ?></div>
<?php endif; ?>
  <form method="post" action="<?= $aqui ?>">
    <input type="hidden" name="paso" value="3"><?= ocultos($TODOS) ?>
    <div class="tarjeta">
      <h2>Quién administra</h2>
      <label class="campo"><b>Usuario</b>
        <input type="text" name="admin_usuario" value="<?= $e($post('admin_usuario')) ?>" placeholder="daniel.melgar" required>
        <small>Con esto entra.</small></label>
      <label class="campo"><b>Nombre</b><input type="text" name="admin_nombre" value="<?= $e($post('admin_nombre')) ?>" placeholder="Daniel"></label>
      <label class="campo"><b>Apellido</b><input type="text" name="admin_apellido" value="<?= $e($post('admin_apellido')) ?>" placeholder="Melgar"></label>
      <label class="campo"><b>Correo · opcional</b>
        <input type="text" name="admin_correo" value="<?= $e($post('admin_correo')) ?>" placeholder="daniel@hotel.com">
        <small>Hace falta para recuperar la contraseña por su cuenta.</small></label>
      <label class="campo"><b>Contraseña</b>
        <input type="password" name="admin_clave" value="" required>
        <small>La eliges tú: no se genera ninguna por detrás.</small></label>
      <label class="op"><input type="checkbox" name="bloquear_admin_defecto" value="1" checked>
        <span class="op"><b>Bloquear la cuenta de fábrica</b>
          <small>El instalador crea una cuenta «admin». Dejarla abierta con una contraseña conocida es un regalo.</small></span></label>
    </div>
    <button class="btn" type="submit">Seguir</button>
  </form>

<?php elseif ($paso === 3): ?>
  <form method="post" action="<?= $aqui ?>">
    <input type="hidden" name="paso" value="4"><?= ocultos($TODOS) ?>
    <div class="tarjeta">
      <h2>Qué se instala</h2>
      <label class="campo"><b>Punto de partida</b>
        <select name="modo">
          <option value="limpia">Limpia — nada de negocio, se define todo</option>
          <option value="paquetes">Con paquetes — se cargan los de abajo</option>
        </select>
      </label>
      <label class="op"><input type="checkbox" name="paq_hotel" value="1">
        <span class="op"><b>Paquete «hotel»</b>
          <small>Departamentos, 16 áreas y 313 tipos de avería de un hotel.</small></span></label>
      <label class="op"><input type="checkbox" name="paq_benalma" value="1">
        <span class="op"><b>Paquete «Benalma»</b>
          <small>Las plantas y habitaciones reales del Hotel Benalma: 1.285 sitios.</small></span></label>
      <label class="op"><input type="checkbox" name="clasificar" value="1" checked>
        <span class="op"><b>Agrupar los tipos por área</b>
          <small>Sólo toca los que no tienen área; lo puesto a mano no se mueve.</small></span></label>
      <label class="op"><input type="checkbox" name="demo" value="1">
        <span class="op"><b>Datos de demostración</b>
          <small>Partes y movimiento de ejemplo para probar.</small></span></label>
      <label class="op"><input type="checkbox" name="equipo_benalma" value="1" <?= $permisoVale ? '' : 'disabled' ?>>
        <span class="op"><b class="peligro">Equipo del Hotel Benalma</b>
          <small class="peligro">Crea sus cuentas con una contraseña conocida. Para demostraciónes.</small></span></label>
      <label class="op"><input type="checkbox" name="forzar" value="1" <?= $permisoVale ? '' : 'disabled' ?>>
        <span class="op"><b class="peligro">Empezar de cero</b>
          <small class="peligro">Borra TODO lo que haya: partes, fotos, personas y ajustes.</small></span></label>
    </div>
    <button class="btn" type="submit">Seguir</button>
  </form>

<?php elseif ($paso === 4): ?>
  <form method="post" action="<?= $aqui ?>" enctype="multipart/form-data">
    <input type="hidden" name="paso" value="5"><?= ocultos($TODOS) ?>
    <div class="tarjeta">
      <h2>Los sitios del negocio</h2>
      <label class="campo"><b>Cómo se crean</b>
        <select name="sitios">
          <option value="nada">Ahora no — los crearé desde la aplicación</option>
          <option value="generar">Por rangos — plantas y habitaciones numeradas</option>
          <option value="hoja">Desde una hoja de Excel o CSV</option>
        </select>
      </label>
      <label class="campo"><b>Plantas</b>
        <input type="text" name="plantas" value="<?= $e($post('plantas')) ?>" placeholder="1-12   ·   o 1-12,14-16 si no hay planta 13"></label>
      <label class="campo"><b>Habitaciones por planta</b>
        <input type="text" name="habitaciones" value="<?= $e($post('habitaciones')) ?>" placeholder="1-20">
        <small>Se numeran como «1205»: planta y número.</small></label>
      <label class="campo"><b>O una lista de sitios</b>
        <input type="text" name="lugares" value="<?= $e($post('lugares')) ?>" placeholder="Recepción, Bar, Piscina, Gimnasio"></label>
      <label class="campo"><b>Dentro de cada sitio</b>
        <input type="text" name="sublugares" value="<?= $e($post('sublugares')) ?>" placeholder="Baño, Dormitorio, Terraza"></label>
      <label class="campo"><b>Zona</b>
        <input type="text" name="zona" value="<?= $e($post('zona')) ?>" placeholder="Habitaciones"></label>
      <label class="campo"><b>Hoja de Excel o CSV</b>
        <input type="file" name="hoja" accept=".csv,.xlsx">
        <small>Plantillas con ejemplos: <code>php tools/importar.php --plantillas</code></small></label>
    </div>
    <button class="btn" type="submit">Ver el resumen</button>
  </form>

<?php elseif ($paso === 5): ?>
  <form method="post" action="<?= $aqui ?>">
    <input type="hidden" name="paso" value="5"><input type="hidden" name="ejecutar" value="1"><?= ocultos($TODOS) ?>
    <div class="tarjeta">
      <h2>Esto es lo que voy a hacer</h2>
      <div class="dato"><span>Motor</span><b><?= $e($post('driver', 'sqlite')) ?></b></div>
      <div class="dato"><span>Punto de partida</span><b><?= (string)$post('modo') === 'limpia' ? 'Limpia' : 'Con paquetes' ?></b></div>
      <div class="dato"><span>Paquetes</span><b><?= $e(implode(', ', array_filter([
          !empty($_POST['paq_hotel']) ? 'hotel' : '', !empty($_POST['paq_benalma']) ? 'Benalma' : ''])) ?: 'ninguno') ?></b></div>
      <div class="dato"><span>Demostración</span><b><?= !empty($_POST['demo']) ? 'sí' : 'no' ?></b></div>
      <div class="dato"><span>Administrador</span><b><?= $e($post('admin_usuario')) ?: '—' ?></b></div>
      <div class="dato"><span>Sitios</span><b><?= $e((string)$post('sitios', 'nada')) ?></b></div>
      <div class="dato"><span>Empezar de cero</span><b class="<?= !empty($_POST['forzar']) ? 'peligro' : '' ?>"><?= !empty($_POST['forzar']) ? 'SÍ, se borra todo' : 'no' ?></b></div>
<?php if ($estado['instalada']): ?>
      <div class="dato"><span>Ahora hay</span><b><?= $estado['partes'] ?> partes · <?= $estado['personas'] ?> personas</b></div>
<?php endif; ?>
    </div>
    <button class="btn" type="submit"<?= !empty($_POST['forzar']) ? ' onclick="return confirm(\'Vas a BORRAR todos los datos. ¿Seguro?\')"' : '' ?>>Instalar</button>
  </form>

<?php else: ?>
  <div class="tarjeta">
    <h2>Lo que se ha hecho</h2>
<?php foreach ($pasos as $p): ?>
    <div class="paso <?= $p['ok'] ? 'bien' : 'mal' ?>">
      <h3><?= $p['ok'] ? '&#10003;' : '&#10007;' ?> <?= $e($p['titulo']) ?></h3>
      <pre><?= $e($p['salida']) ?></pre>
    </div>
<?php endforeach; ?>
    <div class="dato"><span>La base tiene ahora</span><b><?= $estado['partes'] ?> partes · <?= $estado['personas'] ?> personas</b></div>
    <p><a href="../www/index.html">Abrir la aplicación</a> · <a href="?clave=instalar">Volver a empezar</a></p>
  </div>
<?php endif; ?>

  <p class="sub" style="margin-top:16px">
    Desde la consola: <code>php tools/instalar.php --limpia</code> ·
    <code>php tools/ubicaciones.php --plantas=1-12 --habitaciones=1-20</code>
  </p>
</div>
</body>
</html>
