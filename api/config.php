<?php
/**
 * HuraKan Tick — Configuración
 * --------------------------------------------------------------------
 * Copia este fichero a config.local.php para tus datos reales; ese
 * fichero tiene prioridad y está excluido del control de versiones.
 */
declare(strict_types=1);

$config = [

    // ---------------------------------------------------------------
    // Base de datos: 'mysql' o 'sqlite'
    // ---------------------------------------------------------------
    'db' => [
        'driver'   => 'sqlite',                       // mysql | sqlite
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'hurakantick',
        'user'     => 'hurakantick',
        'password' => '',
        'charset'  => 'utf8mb4',
        // Sólo para sqlite: ruta del fichero
        'sqlite_path' => __DIR__ . '/../db/hurakantick.sqlite',
    ],

    // ---------------------------------------------------------------
    // Seguridad
    // ---------------------------------------------------------------
    /* Saber si hay una versión nueva publicada (lib/Actualizaciones.php).
       Lee, sin mandar nada del hotel, el repositorio público. Si el
       servidor no debe salir a internet, «comprobar» => false. */
    'actualizaciones' => [
        'comprobar'   => true,
        'repositorio' => 'hurakancode/HuraKan-Tick',
        'api'         => 'https://api.github.com',
        'raw'         => 'https://raw.githubusercontent.com',
    ],

    'auth' => [
        // Cadena aleatoria y secreta. Cámbiala en producción.
        'app_key'          => 'CAMBIA-ESTA-CLAVE-POR-UNA-ALEATORIA-DE-64-CARACTERES',
        'token_dias'       => 30,      // caducidad de sesión en la app
        'intentos_maximos' => 8,       // bloqueo tras N contraseñas mal
        /* Cuánto dura ese bloqueo.
         *
         * Antes no duraba: era para siempre, y sólo lo quitaba un
         * administrador. Pero los nombres de usuario de un hotel son
         * adivinables, así que cualquiera dejaba al turno entero sin
         * entrar escribiendo mal ocho contraseñas por persona —al
         * administrador también—. Un bloqueo que caduca molesta a quien
         * lo provoca y no para el hotel. Con 0 vuelve a ser para
         * siempre. */
        'bloqueo_minutos'  => 15,
        'bcrypt_cost'      => 12,
    ],

    // Orígenes permitidos para la app nativa y el desarrollo local.
    // '*' acepta cualquiera (cómodo en intranet, no recomendado en internet).
    'cors_origins' => [
        'http://localhost',
        'http://localhost:5173',
        'http://localhost:8080',
        'https://localhost',            // Capacitor Android
        'capacitor://localhost',        // Capacitor iOS
        'ionic://localhost',
    ],

    // ---------------------------------------------------------------
    // Adjuntos
    // ---------------------------------------------------------------
    'uploads' => [
        'dir'        => __DIR__ . '/uploads',
        'url'        => 'uploads',
        'max_bytes'  => 12 * 1024 * 1024,             // 12 MB por archivo
        'mimes'      => ['image/jpeg','image/png','image/webp','image/gif','image/heic','application/pdf'],
        'max_lado'   => 1920,                          // redimensionado si hay GD
        'calidad'    => 82,

        /* El vídeo de los manuales va aparte: otros tipos y otro tope.
         *
         * No se mezcla con lo de arriba a propósito. Esos límites son
         * para las fotos de un parte, que las hace cualquiera desde el
         * móvil cien veces al día; un vídeo lo sube un responsable al
         * escribir un manual, de uvas a peras, y pesa diez veces más.
         * Con un solo tope habría que subirlo para el vídeo y quedaría
         * abierto para las fotos. */
        'video_mimes' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'video_max_bytes' => 40 * 1024 * 1024,         // 40 MB por clip

        /* OJO: estos números son lo que PIDE la aplicación, no lo que
           PHP admite. Si upload_max_filesize o post_max_size son más
           bajos, manda PHP y la subida falla antes de llegar aquí. El
           tope de verdad lo calcula Uploads::topeReal() y lo avisa
           tools/diagnostico.php. */
    ],

    // ---------------------------------------------------------------
    // Aplicación
    // ---------------------------------------------------------------
    'app' => [
        // Anota en el registro de PHP las consultas que pasen de estos
        // milisegundos. 0 las apaga. Útil para saber por qué una pantalla
        // va lenta sin tener que adivinar.
        'consultas_lentas_ms' => 0,

        'nombre'      => 'HuraKan Tick',
        'organizacion'=> 'Servicios Técnicos',
        'zona_horaria'=> 'Europe/Madrid',
        // Nombres reales de la jerarquía, según el esquema original.
        // El índice es el nivel en la base: zona_1, zona_2, zona_3, zona_4.
        'niveles_zona'=> ['Planta', 'Zona', 'Lugar', 'SubLugar'],

        // En qué orden se pregunta al elegir una ubicación.
        //
        // No tiene por qué coincidir con el de la base. Preguntar primero
        // por la Zona («Habitaciones», «Cocinas») reduce mucho más que
        // preguntar por la planta, y es como se piensa al dar un aviso:
        // primero qué clase de sitio es, después dónde exactamente.
        'orden_zona'  => [2, 1, 3, 4],
        'debug'       => false,
    ],
];

// Sobrescritura local (no versionada)
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

// Sobrescritura por variables de entorno (Docker, hosting gestionado…)
$env = static function (string $k, $def = null) {
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : $v;
};
$config['db']['driver']   = $env('HURAKANTICK_DB_DRIVER',   $config['db']['driver']);
$config['db']['host']     = $env('HURAKANTICK_DB_HOST',     $config['db']['host']);
$config['db']['database'] = $env('HURAKANTICK_DB_NAME',     $config['db']['database']);
$config['db']['user']     = $env('HURAKANTICK_DB_USER',     $config['db']['user']);
$config['db']['password'] = $env('HURAKANTICK_DB_PASS',     $config['db']['password']);
/* El fichero de SQLite también, que faltaba.
   Se podía cambiar por el entorno el motor, el servidor, la base, el
   usuario y la contraseña —todo lo de MySQL— pero no la única cosa que
   hace falta en una instalación SQLite: dónde está el fichero. Así se
   puede apuntar a otra base para probar sin tocar la de verdad. */
$config['db']['sqlite_path'] = $env('HURAKANTICK_DB_SQLITE', $config['db']['sqlite_path']);
$config['auth']['app_key']= $env('HURAKANTICK_APP_KEY',     $config['auth']['app_key']);

// Para las pruebas: un GitHub de mentira en esta máquina.
$config['actualizaciones']['api'] = $env('HURAKANTICK_ACT_API', $config['actualizaciones']['api']);
$config['actualizaciones']['raw'] = $env('HURAKANTICK_ACT_RAW', $config['actualizaciones']['raw']);

/* La base de antes del cambio de nombre, mientras no se haya movido.
 *
 * Hasta la 7.89 el fichero era db/sstt.sqlite. Quien suba el código nuevo
 * y todavía no haya pasado el instalador —que es quien lo renombra—
 * tendría una aplicación que no encuentra su base y se presenta como SIN
 * INSTALAR, con el asistente de instalación abierto a cualquiera. Así
 * que, si la nueva no existe y la vieja sí, se usa la vieja. Sólo con la
 * ruta de fábrica: una ruta puesta a mano se respeta tal cual. */
$basePorDefecto = __DIR__ . '/../db/hurakantick.sqlite';
$baseAntigua    = __DIR__ . '/../db/sstt.sqlite';
if ($config['db']['sqlite_path'] === $basePorDefecto
    && !is_file($basePorDefecto) && is_file($baseAntigua)) {
    $config['db']['sqlite_path'] = $baseAntigua;
}

return $config;
