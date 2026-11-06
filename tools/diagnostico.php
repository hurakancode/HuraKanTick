<?php
/**
 * Diagnóstico antes de poner esto en manos de la gente.
 *
 * Se ejecuta desde la consola y responde a una sola pregunta: ¿está esta
 * instalación en condiciones de que entren cincuenta personas mañana?
 *
 *   php tools/diagnostico.php
 *
 * No arregla nada: mira y cuenta. Lo que salga en rojo hay que resolverlo
 * antes de arrancar; lo amarillo conviene saberlo.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esto se ejecuta desde la consola.\n");
}

require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';
require __DIR__ . '/../api/lib/Auth.php';
require __DIR__ . '/../api/lib/Permisos.php';
require __DIR__ . '/../api/lib/Registro.php';
require __DIR__ . '/../api/lib/Repo.php';
require __DIR__ . '/../api/lib/Hoteles.php';
require __DIR__ . '/../api/lib/Errores.php';
require __DIR__ . '/../api/lib/Uploads.php';   // para saber el tope real de subida

$config = require __DIR__ . '/../api/config.php';

/* Si no se llega a la base, este programa reventaba con un
 * «PHP Fatal error: Uncaught PDOException» y su pila de llamadas.
 *
 * Que es exactamente al revés de lo que tiene que hacer. El diagnóstico
 * se abre cuando algo va mal, y «la base no responde» es lo primero que
 * va mal: se para MySQL, se cambia una contraseña, se reinicia el
 * servidor y arranca antes el web que la base. Quien llegue ahí
 * buscando ayuda se encontraba una pila de llamadas y el nombre de un
 * fichero del programa, y pensaría que lo roto es SSTT. */
try {
    Db::init($config['db']);
    Db::value('SELECT 1');
} catch (Throwable $e) {
    $motor = (string)($config['db']['driver'] ?? '');
    echo "\n[ FALLA] NO SE LLEGA A LA BASE DE DATOS\n";
    echo '          El servidor dice: ' . trim($e->getMessage()) . "\n";
    if ($motor === 'mysql') {
        echo '          Está configurado MySQL en ' . ($config['db']['host'] ?? '?')
           . ', base «' . ($config['db']['database'] ?? '?') . '», usuario «'
           . ($config['db']['user'] ?? '?') . "».\n";
        echo "          Mira si está arrancado (systemctl status mariadb) y si\n"
           . "          la contraseña de api/config.local.php sigue valiendo.\n";
    } else {
        echo '          Está configurado SQLite en '
           . ($config['db']['sqlite_path'] ?? '?') . "\n";
        echo "          Mira si el fichero existe y si el servidor web puede\n"
           . "          escribir en él y en su carpeta.\n";
    }
    echo "\n          Sin base no se puede comprobar nada más.\n\n";
    exit(1);
}

$bien = 0; $avisos = 0; $malos = 0;
$di = static function (string $nivel, string $texto, string $detalle = '') use (&$bien, &$avisos, &$malos): void {
    $marca = ['ok' => '  OK  ', 'aviso' => ' AVISO', 'mal' => ' FALLA'][$nivel];
    if ($nivel === 'ok') { $bien++; } elseif ($nivel === 'aviso') { $avisos++; } else { $malos++; }
    echo "[$marca] $texto" . ($detalle !== '' ? "\n          $detalle" : '') . "\n";
};

echo "\n=== DIAGNÓSTICO DE LA INSTALACIÓN ===\n\n";

/* --- Lo imprescindible ------------------------------------------- */
$tablas = ['login', 'ticket_incidencias', 'estados', 'elemento', 'rel_zonas', 'departamentos'];
$faltan = array_values(array_filter($tablas, static fn($t) => !Db::tablaExiste($t)));
if ($faltan) {
    /* Sin estas tablas no hay nada más que comprobar: todo lo que sigue
       consulta una de ellas. Antes seguía adelante y moría con un
       «no such table» y una traza de PHP, que es lo peor que puede
       hacer justo la herramienta que te avisa de que falta instalar. */
    $di('mal', 'Faltan tablas en la base', implode(', ', $faltan));
    echo "\nLa instalación está sin terminar: no se puede comprobar nada más.\n"
       . "Ejecuta primero:  php tools/instalar.php\n\n";
    exit(1);
}
$di('ok', 'La base de datos tiene todas las tablas básicas');

$personas = (int)Db::value('SELECT COUNT(*) FROM login WHERE bloqueo = 0');
$personas === 0
    ? $di('mal', 'No hay ninguna persona dada de alta')
    : $di('ok', "$personas personas pueden entrar");

$admins = (int)Db::value("SELECT COUNT(*) FROM login WHERE admin = 1 AND bloqueo = 0");
$admins === 0 ? $di('mal', 'No hay ningún administrador')
             : $di('ok', "$admins administrador(es)");

$sinNombre = (int)Db::value('SELECT COUNT(*) FROM login WHERE (usuario IS NULL OR usuario = "") AND bloqueo = 0');
$sinNombre > 0
    ? $di('mal', "$sinNombre persona(s) sin nombre de usuario: no pueden entrar")
    : $di('ok', 'Todo el mundo tiene nombre de usuario');

/* --- El catálogo -------------------------------------------------- */
$tipos  = (int)Db::value('SELECT COUNT(*) FROM elemento');
$sitios = (int)Db::value('SELECT COUNT(*) FROM rel_zonas');
$tipos === 0  ? $di('mal', 'No hay tipos de avería: no se puede dar un parte')
              : $di('ok', "$tipos tipos de avería");
$sitios === 0 ? $di('mal', 'No hay ubicaciones: no se puede decir dónde pasa')
              : $di('ok', "$sitios ubicaciones");

$sinRuta = (int)Db::value('SELECT COUNT(*) FROM rel_zonas WHERE ruta IS NULL OR ruta = ""');
$sinRuta > 0
    ? $di('mal', "$sinRuta ubicaciones sin nombre legible", 'Vuelve a pasar el instalador con --actualizar')
    : $di('ok', 'Todas las ubicaciones tienen su nombre completo');

if (in_array('id_hotel', Db::columnas('rel_zonas'), true)) {
    $sinHotel = (int)Db::value('SELECT COUNT(*) FROM rel_zonas WHERE id_hotel IS NULL');
    $sinHotel > 0
        ? $di('mal', "$sinHotel ubicaciones sin hotel", 'Sus fichas darán «no se ha encontrado»')
        : $di('ok', 'Todas las ubicaciones tienen hotel');
}

/* --- El reparto del trabajo --------------------------------------- */
$sinDepto = (int)Db::value('SELECT COUNT(*) FROM elemento WHERE id_departamento IS NULL');
$sinDepto > 0
    ? $di('aviso', "$sinDepto tipos sin departamento", 'Sus partes nacerán sin grupo asignado')
    : $di('ok', 'Todos los tipos saben a qué departamento van');

$personasSinDepto = (int)Db::value('SELECT COUNT(*) FROM login WHERE id_departamento IS NULL AND bloqueo = 0');
$personasSinDepto > 0
    ? $di('aviso', "$personasSinDepto persona(s) sin departamento", 'Verán menos de lo que deberían')
    : $di('ok', 'Todo el mundo tiene departamento');

/* --- Se puede escribir -------------------------------------------- */
/*
 * Ojo con QUIÉN puede escribir.
 *
 * Esto decía «OK: se puede escribir en db» mientras la aplicación
 * contestaba «No se ha podido completar la operación en la base de
 * datos» al primer intento de entrar. Dos motivos, los dos tontos:
 *
 *   1. miraba la CARPETA y no el FICHERO de la base. SQLite necesita
 *      escribir en los dos;
 *   2. y lo miraba como quien lanza el diagnóstico. Si se instala con
 *      sudo —que es lo que hace todo el mundo— los ficheros quedan de
 *      root, el servidor web no puede escribirlos, y el diagnóstico,
 *      ejecutado también con sudo, daba luz verde.
 *
 * Es el fallo de instalación más corriente que hay, y esta comprobación
 * existe justamente para cazarlo antes de abrir. Ahora, cuando se
 * ejecuta como root, mira si podría escribir EL USUARIO DEL SERVIDOR.
 */
/* ¿Lo sirve un servidor web, o se abre con «php -S»?
 *
 * La diferencia importa y me costó un susto: la comprobación miraba si
 * podría escribir el usuario del servidor web (www-data) siempre que se
 * ejecutara como root. Eso está bien en /var/www, pero en la prueba
 * rápida del manual —descomprimir en una carpeta y lanzar «php -S»— los
 * ficheros son de root, los escribe root, y el diagnóstico decía «NO se
 * puede escribir en db» en una instalación que funcionaba
 * perfectamente. Un diagnóstico que avisa en falso se deja de leer, y
 * entonces tampoco avisa de lo de verdad.
 *
 * Así que primero se mira dónde está: bajo una raíz de servidor web, el
 * que tiene que poder escribir es el servidor; fuera de ahí, basta con
 * que pueda quien lo ejecuta. */
$bajoServidorWeb = static function (): bool {
    $aqui = realpath(__DIR__ . '/..') ?: __DIR__;
    foreach (['/var/www', '/srv/http', '/srv/www', '/usr/share/nginx',
              '/home/*/public_html'] as $raiz) {
        foreach (glob($raiz) ?: [$raiz] as $r) {
            if ($r !== '' && str_starts_with($aqui, rtrim($r, '/') . '/')) {
                return true;
            }
        }
    }
    return false;
};
$servido = $bajoServidorWeb();

$puedeEscribir = static function (string $ruta) use ($servido): bool {
    if (!file_exists($ruta)) { return false; }
    if (!$servido || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return is_writable($ruta);
    }
    // Está en la carpeta de un servidor web y esto corre como root: lo
    // que importa es si puede escribir el usuario del servidor.
    $web = null;
    foreach (['www-data', 'apache', 'nginx', 'http', 'daemon'] as $u) {
        if (function_exists('posix_getpwnam') && ($p = @posix_getpwnam($u))) { $web = $p; break; }
    }
    if ($web === null) { return is_writable($ruta); }

    $s = @stat($ruta);
    if ($s === false) { return false; }
    $modo = $s['mode'] & 0777;
    if ((int)$s['uid'] === (int)$web['uid']) { return (bool)($modo & 0200); }
    $grupos = @posix_getgrgid((int)$s['gid']);
    $enGrupo = (int)$s['gid'] === (int)$web['gid']
            || ($grupos && in_array($web['name'], $grupos['members'] ?? [], true));
    if ($enGrupo) { return (bool)($modo & 0020); }
    return (bool)($modo & 0002);
};

$comoArreglarlo = PHP_OS_FAMILY === 'Windows'
    ? 'Dale permiso de escritura a la carpeta al usuario del servidor web'
    : 'Se instaló con sudo y los ficheros quedaron de root. Arréglalo con: '
    . 'sudo chown -R www-data:www-data ' . realpath(__DIR__ . '/..');

foreach (['db' => 'la base de datos', 'api/uploads' => 'las fotos',
          'api/copias' => 'las copias'] as $carpeta => $para) {
    $ruta = __DIR__ . '/../' . $carpeta;
    is_dir($ruta) && $puedeEscribir($ruta)
        ? $di('ok', "Se puede escribir en $carpeta ($para)")
        : $di('mal', "NO se puede escribir en $carpeta ($para)", $comoArreglarlo);
}

/* Y el fichero de la base, que es el que de verdad se escribe. */
if (($config['db']['driver'] ?? '') !== 'mysql') {
    $fichero = $config['db']['sqlite_path'] ?? (__DIR__ . '/../db/hurakantick.sqlite');
    if (!file_exists($fichero)) {
        $di('mal', 'No existe el fichero de la base de datos',
            'Pasa el instalador: php tools/instalar.php --demo');
    } elseif ($puedeEscribir($fichero)) {
        $di('ok', 'El servidor web puede escribir en la base de datos');
    } else {
        $di('mal', 'El servidor web NO puede escribir en la base de datos',
            $comoArreglarlo . '  (sin esto, nadie puede ni entrar)');
    }
}

/* --- La configuración a salvo ------------------------------------- */
is_file(__DIR__ . '/../api/config.local.php')
    ? $di('ok', 'La configuración está en config.local.php', 'Actualizar no te la va a pisar')
    : $di('aviso', 'No hay api/config.local.php',
          'Si editaste api/config.php a mano, al actualizar lo perderás. Pasa el instalador una vez y se copia solo');

/* --- Seguridad ---------------------------------------------------- */
/* Quién sigue entrando con la contraseña del manual.
 *
 * Esto miraba si seguía activo un usuario LLAMADO «admin». Pero lo que
 * hace peligrosa a una cuenta no es su nombre, es su contraseña: la de
 * demostración va escrita en el manual. Y el nombre no es fijo: desde la
 * 7.90 el administrador de la demo se llama «daniel.melgar», así que la
 * comprobación vieja habría dicho «no queda ninguna cuenta de fábrica»
 * mientras ese usuario entraba con la contraseña publicada. Un OK falso,
 * y justo en lo que importa.
 *
 * Así que se prueba la contraseña de verdad contra cada administrador
 * activo. Son pocos —uno, dos—, y comprobar un hash cuesta un cuarto de
 * segundo; probarla contra cincuenta personas haría esto lento, y lo que
 * es grave es un ADMINISTRADOR con ella: ése puede con todo. */
$claveDemo = (string)(require __DIR__ . '/clave_demo.php');
$adminsConClave = [];
foreach (Db::all('SELECT usuario, correo, pwd_php FROM login
                   WHERE admin = 1 AND bloqueo = 0') as $a) {
    if (Auth::verificarPassword($claveDemo, (string)($a['pwd_php'] ?? ''))) {
        $adminsConClave[] = (string)($a['usuario'] ?: $a['correo']);
    }
}
if ($adminsConClave) {
    $di('mal', 'Hay administradores entrando con la contraseña de demostración',
        implode(', ', $adminsConClave) . '. Esa contraseña va escrita en el manual: '
      . 'quien la lea entra como administrador. Cámbiala desde Mi cuenta o, si la '
      . 'cuenta no se usa, bloquéala desde Equipo');
} else {
    $di('ok', 'Ningún administrador entra con la contraseña de demostración',
        'Comprobado probándola contra cada uno, no mirando cómo se llaman');
}

/* Y el nombre de siempre, aparte: una cuenta «admin» activa es la
   primera que prueba cualquiera, tenga la contraseña que tenga. */
$porDefecto = (int)Db::value("SELECT COUNT(*) FROM login WHERE usuario IN ('admin','administrador') AND bloqueo = 0");
if ($porDefecto > 0) {
    $di('aviso', 'Sigue activa una cuenta llamada «admin»',
        'Es el primer nombre que prueba cualquiera. Si no se usa, bloquéala');
}

/* HTTPS no es un consejo: es el interruptor de media aplicación.
 *
 * Esto decía «aviso: funciona, pero…». No funciona. Sin certificado el
 * navegador no entrega el service worker —comprobado: por IP de red,
 * `navigator.serviceWorker` no existe—, y de ahí cuelgan los avisos al
 * móvil y poder trabajar sin cobertura. Por «localhost» sí va, que es
 * por qué en el propio servidor parece que todo está bien. */
/* Y se mira SI HAY CERTIFICADO, no si está marcada la casilla.
 *
 * Esto consultaba el ajuste «forzar_https», que es una intención, no un
 * hecho. Resultado: con un certificado puesto y funcionando seguía
 * diciendo FALLA —y es el único FALLA que queda, así que el mensaje
 * «NO arranques» se vuelve ruido y se ignora—; y al contrario, con la
 * casilla marcada en un servidor sin certificado habría dicho que todo
 * bien. Las dos equivocaciones en la misma línea.
 *
 * Ahora se intenta una conexión https de verdad contra el propio
 * servidor. Sin certificado la conexión no se establece, y eso no hay
 * casilla que lo disimule. */
$hayCertificado = null;                 // null = no se ha podido saber
$aviso = '';
if (function_exists('stream_socket_client')) {
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false,
        'capture_peer_cert' => true,
    ]]);
    $sock = @stream_socket_client('ssl://127.0.0.1:443', $e, $es, 5,
                                  STREAM_CLIENT_CONNECT, $ctx);
    if ($sock) {
        $hayCertificado = true;
        $params = stream_context_get_params($sock);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert) {
            $datos = openssl_x509_parse($cert);
            $hasta = (int)($datos['validTo_time_t'] ?? 0);
            $quien = (string)($datos['issuer']['CN'] ?? '');
            $suyo  = (string)($datos['subject']['CN'] ?? '');
            if ($hasta > 0 && $hasta < time()) {
                $hayCertificado = false;
                $aviso = 'El certificado CADUCÓ el ' . date('d/m/Y', $hasta)
                       . ': el navegador lo rechaza igual que si no hubiera';
            } elseif ($hasta > 0 && $hasta - time() < 15 * 86400) {
                $aviso = 'Caduca el ' . date('d/m/Y', $hasta)
                       . ', renuévalo antes o se caen los avisos';
            } elseif ($quien !== '' && $quien === $suyo) {
                $aviso = 'Es un certificado hecho a mano («' . $suyo . '»): vale '
                       . 'para probar, pero en los móviles del personal saldrá un '
                       . 'aviso de sitio no seguro';
            }
        }
        fclose($sock);
    } else {
        $hayCertificado = false;
    }
}

if ($hayCertificado === true) {
    $di($aviso === '' ? 'ok' : 'aviso', 'El servidor responde por https', $aviso);
} elseif ($hayCertificado === false) {
    $di('mal', 'NO hay certificado: los avisos y el modo sin cobertura no funcionan',
        ($aviso !== '' ? $aviso . '. ' : '')
      . 'El navegador sólo da service worker en https (o en localhost). Sin '
      . 'certificado: nadie recibe avisos, la aplicación no se instala y no carga '
      . 'sin red. Las contraseñas, además, viajan sin cifrar. Mira docs/HTTPS.md');
} else {
    $di('aviso', 'No se ha podido comprobar si hay certificado',
        'Ábrela por https desde un móvil: si no se instala ni avisa, falta');
}

/* Y aparte, si se obliga a usarlo. Tener certificado y dejar entrar por
   http es dejar la puerta de atrás abierta: las contraseñas de quien
   escriba la dirección sin la «s» viajan en claro. */
(bool)Permisos::ajuste('forzar_https', false)
    ? $di('ok', 'Se obliga a entrar por https')
    : $di($hayCertificado === true ? 'mal' : 'aviso',
          'No se obliga a entrar por https',
          $hayCertificado === true
            ? 'Hay certificado pero se sigue aceptando http: enciéndelo en '
              . 'Configuración → Seguridad, o quien escriba la dirección sin la '
              . '«s» manda su contraseña en claro'
            : 'Cuando tengas el certificado, enciéndelo en Configuración → Seguridad');

/* --- Los avisos al móvil ------------------------------------------ */
$claves = (string)Permisos::ajuste('push_vapid_publica', '') !== ''
       && (string)Permisos::ajuste('push_vapid_privada', '') !== '';
$suscritos = Db::tablaExiste('push_destinos')
    ? (int)Db::value('SELECT COUNT(*) FROM push_destinos') : 0;

if (!$claves) {
    $di('aviso', 'Los avisos al móvil no están preparados',
        'Las claves se generan solas la primera vez que alguien los activa. '
      . 'Si quieres hacerlo ya: php tools/avisos.php --claves');
} elseif ($suscritos === 0) {
    $di('aviso', 'Nadie tiene los avisos activados todavía',
        'Cada persona los activa desde Ajustes → Sonido, vibración y avisos. '
      . 'Hasta entonces los avisos sólo se ven al abrir la aplicación');
} else {
    $di('ok', "$suscritos aparato(s) reciben avisos");
}

/* --- El tope de subida que hay de verdad --------------------------- */
/* La configuración puede pedir 12 MB y PHP admitir 2. Cuando pasa, una
   foto de móvil normal falla con un error que no habla de ningún
   límite, porque PHP descarta la petición antes de llegar al código. Es
   lo que estaba ocurriendo sin que nadie lo supiera. */
{
    Uploads::init($config['uploads']);
    $t = Uploads::topeReal();
    $v = Uploads::topeReal((int)($config['uploads']['video_max_bytes'] ?? 0));

    if ($t['bytes'] < $t['config']) {
        $di('mal', 'Las subidas están limitadas a ' . $t['mb'] . ' MB, no a '
                 . round($t['config'] / 1048576) . ' MB',
            'Manda ' . $t['manda'] . '. Una foto de móvil pasa de eso y fallará. '
          . 'Sube upload_max_filesize y post_max_size en php.ini. Mira docs/SUBIDAS.md');
    } else {
        $di('ok', 'Se pueden subir archivos de hasta ' . $t['mb'] . ' MB');
    }

    if ($v['config'] > 0 && $v['bytes'] < $v['config']) {
        $di('aviso', 'El vídeo de los manuales se queda en ' . $v['mb'] . ' MB',
            'Está pedido ' . round($v['config'] / 1048576) . ' MB, pero manda '
          . $v['manda'] . '. Los clips más largos no se podrán subir');
    }

    /* --- El candado de las carpetas de subidas ---------------------- */
    /* Lo que impide que algo subido se ejecute como código. Se comprueba
       aquí porque es invisible: si falta, todo sigue funcionando igual
       hasta el día que alguien lo aprovecha. */
    $viejos = [];
    $sinCandado = [];
    foreach ([__DIR__ . '/../api/uploads',
              __DIR__ . '/../api/uploads/instrucciones'] as $d) {
        if (!is_dir($d)) { continue; }
        $f = $d . '/.htaccess';
        if (!is_file($f)) { $sinCandado[] = basename($d); continue; }
        if (!str_contains((string)@file_get_contents($f), Uploads::CANDADO)) {
            $viejos[] = basename($d);
        }
    }

    if ($sinCandado) {
        $di('mal', 'Falta el candado en api/uploads/' . implode(', ', $sinCandado),
            'Sin ese .htaccess, un fichero subido podría ejecutarse en el '
          . 'servidor. Vuelve a pasar tools/instalar.php, que lo escribe');
    } elseif ($viejos) {
        $di('aviso', 'El candado de las subidas es de una versión anterior',
            'Pasa tools/instalar.php para renovarlo. El antiguo llevaba un '
          . 'php_flag suelto que en un Apache sin mod_php devuelve error 500 '
          . 'en todas las fotos');
    } else {
        /* Deliberadamente no dice «protegidas»: un .htaccess sólo se lee si
           el servidor tiene AllowOverride, y con «AllowOverride None» —que
           es el valor por defecto de Apache en Debian y Ubuntu— ese fichero
           no lo lee nadie. Comprobado: un .php colocado a mano en la carpeta
           se ejecutaba igual con el candado puesto. Así que el candado es el
           segundo cerrojo, no el primero. */
        $di('ok', 'El candado de las subidas está puesto y al día',
            'Sólo surte efecto si el servidor lee los .htaccess (AllowOverride). '
          . 'Lo que de verdad protege es que un archivo subido nunca se guarda '
          . 'con una extensión ejecutable: se renombra al tipo real del '
          . 'contenido, y lo desconocido acaba en .bin');
    }

    /* En nginx un .htaccess no existe: no lo lee nadie. Quien lo tenga ahí
       cree que está protegido y no lo está, así que se dice claramente. */
    $srv = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if ($srv !== '' && !str_contains($srv, 'apache')) {
        $di('aviso', 'Este servidor no es Apache (' . htmlspecialchars($srv) . ')',
            'Los .htaccess de las carpetas de subidas no los lee nadie aquí. '
          . 'Hay que bloquear la ejecución de PHP bajo api/uploads en la '
          . 'configuración del servidor. Está explicado en docs/SUBIDAS.md');
    }
}

/* --- La clave secreta de la casa ----------------------------------- */
/*
 * Venía en config.php con el valor «CAMBIA-ESTA-CLAVE-...» y nada la
 * cambiaba: el instalador no la generaba y esto no la miraba. Todas las
 * instalaciones compartían el mismo secreto, y ese secreto va escrito
 * en el código, dentro del paquete.
 *
 * Con ella autentican tools/mantenimiento.php y tools/vencidos.php, que
 * se llaman desde fuera con ?clave=. */
$clave = (string)($config['auth']['app_key'] ?? '');
if ($clave === '' || str_starts_with($clave, 'CAMBIA-ESTA-CLAVE')) {
    $di('mal', 'La clave secreta es la de fábrica, igual en todas las casas',
        'Está escrita en el código del paquete, así que no es secreta. Con '
      . 'ella se llaman tools/mantenimiento.php y tools/vencidos.php. Pasa '
      . 'tools/instalar.php, que ahora genera una propia, o escribe a mano '
      . '«auth» => [«app_key» => ...] en api/config.local.php con 64 '
      . 'caracteres al azar');
} elseif (strlen($clave) < 32) {
    $di('aviso', 'La clave secreta es corta (' . strlen($clave) . ' caracteres)',
        'Con menos de 32 se puede probar a adivinarla. Pon 64 al azar');
} else {
    $di('ok', 'La clave secreta es propia de esta instalación');
}

/* --- Lo que se lleva un desconocido con sólo la dirección ---------- */
/*
 * Esto no se deduce: se pide por HTTP y se mira qué contesta.
 *
 * Hasta aquí, todo lo que mira este diagnóstico sobre candados mira si
 * el fichero de candado está puesto. Y estaba. Y aun así, en la
 * instalación de pruebas:
 *
 *     http://servidor/hurakantick/db/hurakantick.sqlite  →  200, nueve megas
 *
 * La base entera —partes, personas, pwd_php, pwd_sha2 y autologin_hash,
 * que es una llave de entrada directa— y la papelera al lado, que
 * guarda copia de todo lo borrado. Sin sesión, sin tocar el programa:
 * esto lo sirve el servidor web él solo.
 *
 * db/.htaccess estaba puesto y era correcto. Lo que pasa es que Apache
 * en Debian y en Ubuntu viene con «AllowOverride None», y con eso no
 * lee ningún .htaccess de nadie. El candado es un papel pegado en una
 * puerta que nadie mira. Y desde dentro —mirando si el fichero está—
 * las dos situaciones son idénticas.
 *
 * Por eso esto pregunta por la puerta de la calle. */
$aqui = realpath(__DIR__ . '/..') ?: __DIR__;
$ruta = null;
foreach (['/var/www/html', '/var/www', '/srv/http', '/srv/www',
          '/usr/share/nginx/html'] as $r) {
    if (str_starts_with($aqui, $r . '/')) { $ruta = substr($aqui, strlen($r)); break; }
}

/* Si esto no está bajo la raíz de ningún servidor web, NO se pregunta.
 *
 * Antes, en ese caso, se preguntaba igual a http://127.0.0.1/ — o sea,
 * a OTRO sitio cualquiera que hubiera en esa máquina— y como aquél
 * contestaba bien, esto decía «Nada de lo sensible se sirve por web».
 * Un OK que no es de esta instalación es peor que no decir nada: el
 * diagnóstico está para quitar dudas, no para inventarse tranquilidad.
 * Lo vi al instalar desde el paquete en una carpeta suelta. */
$base = $ruta === null ? null
      : 'http://127.0.0.1' . rtrim($ruta, '/') . '/';

$mirar = [
    'db/' . basename((string)($config['db']['sqlite_path'] ?? 'hurakantick.sqlite'))
        => 'LA BASE ENTERA, con las contraseñas y las llaves de auto-entrada',
    'db/hurakantick-papelera.sqlite' => 'todo lo borrado, con las filas completas',
    'db/'            => 'los nombres de los ficheros de la base',
    'api/copias/'    => 'las copias de seguridad, que son la base entera',
    'api/uploads/'   => 'las fotos de todos los partes, sin sesión',
    'paquetes/'      => 'los paquetes de versiones',
];

$abiertas = [];
$sePudo = false;
foreach (($base === null ? [] : $mirar) as $r => $que) {
    $ctx = stream_context_create(['http' => [
        'timeout' => 5, 'ignore_errors' => true, 'method' => 'HEAD']]);
    $res = @file_get_contents($base . $r, false, $ctx);
    $cab = $http_response_header ?? [];
    if (!$cab) { continue; }
    $sePudo = true;
    if (preg_match('#\s(\d{3})\s#', (string)($cab[0] ?? ''), $m)
        && (int)$m[1] === 200) {
        $abiertas[] = $r . ' — ' . $que;
    }
}

if ($base === null) {
    $di('aviso', 'No se puede saber desde aquí qué sirve el servidor web',
        'Esta carpeta no está bajo la raíz de ningún servidor conocido, así '
      . 'que no hay a qué dirección preguntar. Cuando la pongas en su sitio, '
      . 'vuelve a pasar esto: es la comprobación más importante de todas');
} elseif (!$sePudo) {
    $di('aviso', 'No se ha podido preguntar al servidor web desde aquí',
        'Pruébalo a mano desde otro ordenador: escribe en el navegador '
      . $base . 'db/ — si sale algo, cualquiera se lleva la base entera');
} elseif ($abiertas) {
    $di('mal', 'EL SERVIDOR SIRVE ' . count($abiertas) . ' COSAS QUE NO DEBE',
        implode(' · ', $abiertas)
      . '. Los candados del proyecto están puestos pero el servidor no los '
      . 'lee: en Apache hace falta «AllowOverride All» en el <Directory> del '
      . 'sitio y recargar con apachectl -k graceful; en nginx, «location ~ '
      . '^/(db|tools|paquetes)/ { deny all; }». Mientras siga así, cambiar '
      . 'las contraseñas no sirve: la base se descarga con ellas dentro');
} else {
    $di('ok', 'Nada de lo sensible se sirve por web',
        'Comprobado pidiéndolo, no mirando los candados');
}

/* --- ¿Hay una versión nueva publicada? ---------------------------- */
/* Lo que se sepa, sin hacer esperar: si se preguntó hace menos de doce
   horas, sale de lo guardado. No es un FALLA nunca: no tener la última
   no impide trabajar, y no tener red tampoco. */
require_once __DIR__ . '/../api/lib/Actualizaciones.php';
$act = Actualizaciones::estado($config);
if ($act['hay_nueva']) {
    $di('aviso', 'Hay una versión nueva: ' . $act['publicada'] . ' (tienes la ' . $act['actual'] . ')',
        'Está en ' . $act['donde']);
} elseif ($act['publicada'] !== null) {
    $di('ok', 'Es la última versión publicada (' . $act['actual'] . ')');
} else {
    $di('aviso', 'No se sabe si hay una versión nueva', $act['motivo']);
}

/* --- Restos de partes borrados ------------------------------------ */
/* Un aviso cuyo parte ya no existe no se puede abrir, y durante un
   tiempo tampoco se podía quitar: se quedaba contando en la chapa de
   «sin leer» para siempre. Era el fallo de los «2 mensajes». Ya no se
   generan, pero los que quedaron de antes siguen ahí hasta que alguien
   los limpie, así que esto los saca a la luz. */
$huerfanos = 0;
if (Db::tablaExiste('notificaciones')) {
    $huerfanos += (int)Db::value(
        'SELECT COUNT(*) FROM notificaciones
          WHERE id_ticket IS NOT NULL
            AND id_ticket NOT IN (SELECT id_ticket FROM ticket_incidencias)');
    $huerfanos += (int)Db::value(
        'SELECT COUNT(*) FROM notificaciones WHERE id_ticket IS NULL');
}
$huerfanos > 0
    ? $di('aviso', "Hay $huerfanos aviso(s) de partes que ya no existen",
          'Cuentan como «sin leer» y al pulsarlos no llevan a ningún sitio. '
        . 'Se quitan desde Hoteles → Restablecer → «Los avisos de todo el mundo»')
    : $di('ok', 'No hay avisos sueltos de partes borrados');

/* --- La copia a Teams --------------------------------------------- */
if ((bool)Permisos::ajuste('teams_activo', false)) {
    $falta = array_values(array_filter(
        ['teams_tenant' => 'el identificador del tenant',
         'teams_app_id' => 'el identificador de la aplicación',
         'teams_app_secreto' => 'el secreto'],
        static fn($t, $c) => (string)Permisos::ajuste($c, '') === '',
        ARRAY_FILTER_USE_BOTH));
    $falta
        ? $di('mal', 'La copia a Teams está encendida pero sin configurar', 'Falta ' . implode(', ', $falta))
        : $di('ok', 'La copia a Teams está configurada');
} else {
    $di('ok', 'La copia a Teams está apagada');
}

/* --- El registro de errores --------------------------------------- */
if (Db::tablaExiste('errores')) {
    $e = Errores::sinVer();
    $e['grupos'] > 0
        ? $di('aviso', "Hay {$e['grupos']} fallo(s) sin revisar", 'Míralos en Administración → Errores')
        : $di('ok', 'El registro de errores está limpio');
} else {
    $di('mal', 'No existe el registro de errores', 'Pasa el instalador con --actualizar');
}

/* --- Copias ------------------------------------------------------- */
$copias = glob(__DIR__ . '/../api/copias/*.{gz,zip}', GLOB_BRACE) ?: [];
$copias
    ? $di('ok', count($copias) . ' copia(s) de seguridad guardadas',
          'La última: ' . date('d/m/Y H:i', filemtime((string)end($copias))))
    : $di('mal', 'No hay ninguna copia de seguridad',
          'Haz una desde Administración → Copias ANTES de que entre nadie');

/* --- Partes que no ve nadie --------------------------------------- */
/*
 * Un parte sin hotel no aparece en ninguna lista de ninguna persona: el
 * filtro por hotel lo deja fuera siempre. Se crea bien, el móvil dice
 * «parte T-00484 creado», y ahí se queda: nadie recibe el aviso y nadie
 * lo encuentra, ni buscando su código.
 *
 * Pasaba cuando quien lo abría no tenía hotel asignado. Al crear ya no
 * puede pasar, pero los que se guardaron así siguen ahí, invisibles, y
 * no hay forma de verlos desde la aplicación —por definición—. Por eso
 * se cuentan aquí, que es donde se mira cuando algo no cuadra.
 *
 * Con --arreglar-huerfanos se les pone el hotel de su ubicación, o el
 * único hotel si no hay más que uno.
 */
if (Db::tablaExiste('hoteles') && Db::tablaExiste('ticket_incidencias')
    && in_array('id_hotel', Db::columnas('ticket_incidencias'), true)) {

    $huerfanos = (int)Db::value(
        'SELECT COUNT(*) FROM ticket_incidencias WHERE id_hotel IS NULL AND borrado = 0');

    if ($huerfanos === 0) {
        $di('ok', 'Todos los partes tienen hotel');
    } elseif (in_array('--arreglar-huerfanos', $argv, true)) {
        /* Primero el hotel de su ubicación: es donde está la avería. */
        Db::run(
            'UPDATE ticket_incidencias SET id_hotel = (
                 SELECT r.id_hotel FROM rel_zonas r WHERE r.id_rel = ticket_incidencias.id_rel_zona
             )
             WHERE id_hotel IS NULL AND id_rel_zona IS NOT NULL');

        /* Y si sólo hay un hotel, los que queden son suyos sin duda. */
        $activos = Db::all('SELECT id_hotel FROM hoteles WHERE COALESCE(activo,1) = 1');
        if (count($activos) === 1) {
            Db::run('UPDATE ticket_incidencias SET id_hotel = ? WHERE id_hotel IS NULL',
                    [(int)$activos[0]['id_hotel']]);
        }

        $quedan = (int)Db::value(
            'SELECT COUNT(*) FROM ticket_incidencias WHERE id_hotel IS NULL AND borrado = 0');
        $arreglados = $huerfanos - $quedan;
        $quedan === 0
            ? $di('ok', "Arreglados $arreglados partes que no veía nadie")
            : $di('aviso', "Arreglados $arreglados; quedan $quedan sin hotel",
                  'No se puede adivinar el suyo: hay varios hoteles y no tienen ubicación');
    } else {
        $di('mal', "$huerfanos partes que NO VE NADIE (sin hotel)",
            'Se crearon invisibles. Arréglalo con: '
          . 'php tools/diagnostico.php --arreglar-huerfanos');
    }
}

/* --- Integridad de los datos --------------------------------------
 * Lo mismo que Administración → Integridad (api/lib/Integridad.php):
 * así consola y pantalla dicen lo mismo. Sólo se enseña lo que no esté
 * bien, y se saltan las comprobaciones que este script ya ha hecho
 * arriba a su manera, para no contarlas dos veces. */
require_once __DIR__ . '/../api/lib/Integridad.php';
echo "\n--- Integridad de los datos ---\n";
$yaHechas = ['Personas.clave_demo', 'Personas.admins', 'Personas.sin_departamento',
             'Personas.sin_usuario', 'Seguridad.app_key', 'Seguridad.candados', 'Seguridad.https',
             'Sistema.version', 'Ubicaciones.sin_hotel', 'Ubicaciones.sin_ruta',
             'Partes.sin_hotel', 'Partes.avisos', 'Ficheros.subidas'];
try {
    $int = Integridad::comprobar($config, null);
    $enOrden = 0;
    foreach ($int['comprobaciones'] as $c) {
        if (in_array($c['grupo'] . '.' . $c['id'], $yaHechas, true)) { continue; }
        if ($c['estado'] === 'bien') { $enOrden++; continue; }
        if ($c['estado'] === 'nd') {
            echo "[  ??  ] {$c['grupo']}: {$c['titulo']}" . ($c['detalle'] !== '' ? "\n          {$c['detalle']}" : '') . "\n";
            continue;
        }
        $di($c['estado'] === 'falla' ? 'mal' : 'aviso', "{$c['grupo']}: {$c['titulo']}", (string)$c['detalle']);
    }
    $di('ok', "$enOrden comprobaciones de integridad en orden");
} catch (Throwable $e) {
    $di('aviso', 'No se ha podido revisar la integridad de los datos', $e->getMessage());
}

/* ------------------------------------------------------------------ */
echo "\n-----------------------------------------------\n";
echo "  $bien bien · $avisos avisos · $malos fallos\n\n";

if ($malos > 0) {
    echo "  NO arranques hasta resolver lo que sale como FALLA.\n\n";
    exit(1);
}
echo $avisos > 0
    ? "  Se puede arrancar. Lee los avisos: ninguno impide trabajar.\n\n"
    : "  Todo en orden.\n\n";
exit(0);
