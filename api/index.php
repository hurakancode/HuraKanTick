<?php
/**
 * HuraKan Tick — Punto de entrada de la API REST
 *
 *   GET    /api/estado                       diagnóstico
 *   POST   /api/auth/login                   entrar
 *   POST   /api/auth/logout                  salir
 *   GET    /api/auth/yo                      perfil
 *   POST   /api/auth/password                cambiar contraseña
 *   GET    /api/catalogo                     estados, prioridades, zonas, usuarios…
 *   GET    /api/tickets                      listado con filtros
 *   POST   /api/tickets                      alta
 *   GET    /api/tickets/{id}                 detalle + historial + adjuntos
 *   PATCH  /api/tickets/{id}                 modificar
 *   POST   /api/tickets/{id}/movimientos     nota / cambio de estado / asignación
 *   POST   /api/tickets/{id}/adjuntos        subir foto
 *   DELETE /api/adjuntos/{id}                borrar foto
 *   GET    /api/panel                        indicadores del panel
 *   GET    /api/sync/pull?desde=...          cambios del servidor
 *   POST   /api/sync/push                    cola de cambios del móvil
 *   GET    /api/notificaciones               avisos
 *   GET|POST|PATCH /api/usuarios             administración
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

/* Los fallos fatales de PHP no pasan por ningún catch: se recogen al
   terminar. Sin esto, un error de sintaxis o un «undefined method» en
   producción deja la pantalla en blanco y ni rastro en ninguna parte. */
register_shutdown_function(static function (): void {
    $f = error_get_last();
    if ($f === null || !in_array($f['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    try {
        Errores::apuntar('servidor', $f['message'],
            basename((string)$f['file']) . ':' . $f['line'], $_GET['_ruta'] ?? null);
    } catch (Throwable $e) { /* ya no se puede hacer más */ }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

/* Subida más grande de lo que PHP admite.
 *
 * Cuando el cuerpo supera post_max_size, PHP vacía $_POST y $_FILES sin
 * decir nada: la petición llega como si no trajera campos. El error que
 * salía era «Falta el campo», que no ayuda a nadie a entender que la
 * foto pesaba demasiado.
 *
 * Se detecta aquí, antes que nada, porque a partir de este punto ya no
 * hay forma de distinguirlo de una petición mal formada. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {

    $tope = ini_get('post_max_size') ?: '8M';
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'El archivo es demasiado grande. El servidor admite hasta ' . $tope
                 . ' por envío. Haz la foto con menos calidad o súbela en varias.',
        'campo' => 'archivo',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
error_reporting(E_ALL);

require __DIR__ . '/lib/compat.php';
require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/Permisos.php';
require __DIR__ . '/lib/Registro.php';
require __DIR__ . '/lib/Hoteles.php';
require __DIR__ . '/lib/Chat.php';
require __DIR__ . '/lib/Errores.php';
require __DIR__ . '/lib/Plugins.php';   // módulos opcionales
require __DIR__ . '/lib/Archivo.php';
require __DIR__ . '/lib/Push.php';
require __DIR__ . '/lib/Teams.php';   // copia de los avisos a Microsoft Teams
require __DIR__ . '/lib/Repo.php';
require __DIR__ . '/lib/Informes.php';
require __DIR__ . '/lib/Uploads.php';
require __DIR__ . '/lib/AutoEntrada.php';
require __DIR__ . '/lib/Descargas.php';  // vales de un solo rato para bajar ficheros
require __DIR__ . '/lib/Bajas.php';      // borrar a una persona sin dejar huecos
require __DIR__ . '/lib/Actualizaciones.php';  // ¿hay una versión nueva publicada?

Http::cors($config['cors_origins']);

/* ------------------------------------------------------------------ */
/* Resolución de la ruta                                              */
/* ------------------------------------------------------------------ */
$ruta = $_GET['_ruta'] ?? null;
if ($ruta === null) {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $ruta = trim($uri, '/');
}
$ruta   = trim(preg_replace('#[^a-zA-Z0-9/_.-]#', '', (string)$ruta), '/');
$partes = $ruta === '' ? [] : explode('/', $ruta);
$metodo = Http::metodo();

/* ------------------------------------------------------------------ */
/* Ejecución                                                          */
/* ------------------------------------------------------------------ */
try {
    Db::init($config['db']);

/* Si se pide en la configuración, se anotan las consultas que tarden. */
if (!empty($config['app']['consultas_lentas_ms'])) {
    Db::avisarConsultasLentas((float)$config['app']['consultas_lentas_ms']);
}
    Auth::init($config['auth']);
    Uploads::init($config['uploads']);

    // Modo mantenimiento: se comprueba antes de tocar nada, y deja pasar
    // la sincronización de subida para que nadie pierda lo que tenga
    // guardado en el móvil sin enviar.
    Mantenimiento_Modo::comprobar($ruta, Auth::usuario());

    $ctx = ['config' => $config, 'partes' => $partes, 'metodo' => $metodo];

    $seccion = $partes[0] ?? '';
    switch ($seccion) {
        case '':
        case 'estado':
            require __DIR__ . '/routes/estado.php';
            break;
        case 'auth':
            require __DIR__ . '/routes/auth.php';
            break;
        case 'catalogo':
            require __DIR__ . '/routes/catalogo.php';
            break;
        case 'tickets':
            require __DIR__ . '/routes/tickets.php';
            break;
        case 'adjuntos':
            require __DIR__ . '/routes/adjuntos.php';
            break;
        case 'panel':
            require __DIR__ . '/routes/panel.php';
            break;
        case 'elementos':
        case 'ubicaciones-admin':
            require __DIR__ . '/routes/catalogos_admin.php';
            break;
        case 'departamentos':
            require __DIR__ . '/routes/departamentos.php';
            break;
        case 'push':
            require __DIR__ . '/routes/push.php';
            break;
        /* Las conversaciones de esta persona, para poder volver a ellas. */
        /* Los fallos del móvil, y la lista para quien administra. */
        case 'errores':
            require __DIR__ . '/routes/errores.php';
            break;

        case 'conversaciones':
            $u = Auth::exigir();
            Http::ok(['conversaciones' => Chat::recientes($u)]);
            break;

        case 'papelera':
            require __DIR__ . '/routes/papelera.php';
            break;
        case 'hoteles':
            require __DIR__ . '/routes/hoteles.php';
            break;
        case 'unidades':
            require __DIR__ . '/routes/unidades.php';
            break;
        case 'instrucciones':
            require __DIR__ . '/routes/instrucciones.php';
            break;
        case 'mantenimiento':
            require __DIR__ . '/routes/mantenimiento.php';
            break;
        case 'informes':
            require __DIR__ . '/routes/informes.php';
            break;
        case 'ajustes':
        case 'renovar':        // forzar que todos vuelvan a descargar
        case 'campos':
        case 'atajos':
        case 'familias':
        case 'copias':
        case 'seguridad':
        case 'teams':          // comprobar la copia de avisos a Teams
        case 'actualizacion':  // ¿hay una versión nueva publicada?
        case 'integridad':     // ¿está todo en su sitio? (sólo lee)
            require __DIR__ . '/routes/sistema.php';
            break;
        case 'personal':       // alta y salida de personas por ficheros
            require __DIR__ . '/routes/personal.php';
            break;
        case 'mensajes':
        case 'frases':
            require __DIR__ . '/routes/mensajes.php';
            break;
        case 'sync':
            require __DIR__ . '/routes/sync.php';
            break;
        case 'notificaciones':
            require __DIR__ . '/routes/notificaciones.php';
            break;
        case 'ubicaciones':
            require __DIR__ . '/routes/ubicaciones.php';
            break;
        case 'usuarios':
        case 'zonas':
            require __DIR__ . '/routes/admin.php';
            break;
        default:
            /* Antes de rendirse: ¿atiende esto algún módulo opcional?
               Van los últimos a propósito, así un plugin no puede pisar
               una ruta del sistema ni llamándose igual. */
            $deModulo = class_exists('Plugins') ? Plugins::ruta($seccion) : null;
            if ($deModulo !== null) {
                require $deModulo;
                break;
            }
            throw new ApiError('Ruta no encontrada: /' . $ruta, 404);
    }

    throw new ApiError('Método no permitido para esta ruta.', 405);

} catch (ApiError $e) {
    Db::rollback();
    Http::error($e->getMessage(), $e->getCode() ?: 400, $e->extra);
} catch (PDOException $e) {
    /* La base no conecta: MySQL reiniciándose, parado, sin sitio… No es
       un fallo de la petición sino del momento. 503 —«vuelve a
       intentarlo»— y un mensaje que se pueda enseñar tal cual. La cola
       del móvil no descarta nada cuando la petición falla entera: se
       reenvía en la siguiente vuelta. */
    if (!Db::lista()) {
        error_log('HuraKan Tick · la base de datos no conecta: ' . $e->getMessage());
        header('Retry-After: 30');
        Http::error('La base de datos no responde ahora mismo. Inténtalo en un momento: '
            . 'lo que tengas sin enviar se queda guardado en el móvil.', 503);
    }
    Db::rollback();
    /* Se apunta en el registro de errores de PHP. Antes no quedaba en
       ningún sitio: quien llamaba veía un mensaje genérico —bien, no hay
       que darle detalles— pero nadie podía saber después qué había pasado. */
    error_log('HuraKan Tick · base de datos · ' . ($_GET['_ruta'] ?? '?') . ' · ' . $e->getMessage());
    Errores::apuntar('servidor', 'Base de datos: ' . $e->getMessage(),
        $e->getTraceAsString(), $_GET['_ruta'] ?? null, $u ?? null);
    $msg = $config['app']['debug']
        ? 'Base de datos: ' . $e->getMessage()
        : 'No se ha podido completar la operación en la base de datos.';
    Http::error($msg, 500);
} catch (Throwable $e) {
    Db::rollback();
    error_log('HuraKan Tick · error · ' . ($_GET['_ruta'] ?? '?') . ' · ' . $e->getMessage()
        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Errores::apuntar('servidor',
        $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
        $e->getTraceAsString(), $_GET['_ruta'] ?? null, $u ?? null);
    $msg = $config['app']['debug']
        ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
        : 'Error interno del servidor.';
    Http::error($msg, 500);
}
