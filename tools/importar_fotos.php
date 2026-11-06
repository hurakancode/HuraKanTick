<?php
/**
 * Importador de fotografías de la versión antigua.
 *
 * En la versión 1.x las imágenes vivían sueltas en el disco, dentro de
 * ms/uploads/<id_ticket>/, sin ningún registro en la base de datos. Este
 * script las recorre, las copia a api/uploads/ y las da de alta en la tabla
 * «adjuntos» para que aparezcan en la ficha de cada parte.
 *
 * Uso:
 *   php tools/importar_fotos.php --origen=/ruta/a/hurakantick/ms/uploads --simular
 *   php tools/importar_fotos.php --origen=/ruta/a/hurakantick/ms/uploads
 *
 * Opciones:
 *   --origen=RUTA   Carpeta uploads de la instalación antigua (obligatoria)
 *   --simular       No copia ni escribe nada: sólo informa de lo que haría
 *   --mover         Borra el original tras copiarlo (por defecto se conserva)
 *   --autor=ID      Usuario al que se atribuyen las fotos (por defecto, el
 *                   creador de cada parte; si no existe, el primer admin)
 *
 * Es seguro ejecutarlo varias veces: una foto ya registrada se detecta por su
 * hash y se omite.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script sólo se ejecuta desde la línea de órdenes.\n");
}

$config = require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';

/* ---------------------------------------------------------------- */
/* Argumentos                                                       */
/* ---------------------------------------------------------------- */
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$origen  = isset($args['origen']) ? rtrim((string)$args['origen'], '/') : '';
$simular = isset($args['simular']);
$mover   = isset($args['mover']);
$autorFijo = isset($args['autor']) ? (int)$args['autor'] : null;

if ($origen === '' || !is_dir($origen)) {
    exit(
        "Falta la carpeta de origen o no existe.\n\n" .
        "  php tools/importar_fotos.php --origen=/var/www/sstt-antiguo/ms/uploads --simular\n\n"
    );
}

echo "Importación de fotografías\n";
echo "  origen : $origen\n";
echo "  destino: {$config['uploads']['dir']}\n";
echo $simular ? "  modo   : SIMULACIÓN (no se escribe nada)\n\n" : "  modo   : real\n\n";

Db::init($config['db']);

if (!Db::tablaExiste('adjuntos')) {
    exit("La tabla «adjuntos» no existe. Ejecuta antes la migración:\n"
       . "  mysql -u USUARIO -p BASE < db/migracion.legacy.mysql.sql\n");
}

$destinoBase = $config['uploads']['dir'];
if (!$simular && !is_dir($destinoBase)) {
    mkdir($destinoBase, 0775, true);
}

/* ---------------------------------------------------------------- */
/* Utilidades                                                       */
/* ---------------------------------------------------------------- */
$adminPorDefecto = (int)(Db::value('SELECT id_login FROM login WHERE admin = 1 ORDER BY id_login LIMIT 1')
    ?? Db::value('SELECT MIN(id_login) FROM login') ?? 0);

if ($adminPorDefecto === 0) {
    exit("No hay ningún usuario en la tabla «login». Instala primero la aplicación.\n");
}

$extensionDe = static function (string $mime, string $nombre): string {
    $map = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif'  => 'gif', 'image/bmp' => 'bmp', 'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];
    if (isset($map[$mime])) {
        return $map[$mime];
    }
    $e = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{2,5}$/', $e) ? $e : 'bin';
};

$admitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'application/pdf'];
$finfo = new finfo(FILEINFO_MIME_TYPE);

/* ---------------------------------------------------------------- */
/* Recorrido                                                        */
/* ---------------------------------------------------------------- */
$resumen = [
    'carpetas'    => 0,
    'archivos'    => 0,
    'importadas'  => 0,
    'repetidas'   => 0,
    'sin_ticket'  => 0,
    'no_validas'  => 0,
    'errores'     => 0,
    'bytes'       => 0,
];
$sinTicket = [];

$carpetas = scandir($origen) ?: [];
sort($carpetas, SORT_NATURAL);

foreach ($carpetas as $carpeta) {
    if ($carpeta === '.' || $carpeta === '..') {
        continue;
    }
    $ruta = $origen . '/' . $carpeta;
    if (!is_dir($ruta) || !ctype_digit($carpeta)) {
        continue;
    }

    $idTicket = (int)$carpeta;
    $resumen['carpetas']++;

    $ticket = Db::one('SELECT id_ticket, id_realiza_accion FROM ticket_incidencias WHERE id_ticket = ?', [$idTicket]);
    if ($ticket === null) {
        $sinTicket[] = $idTicket;
        $archivosHuerfanos = array_filter(scandir($ruta) ?: [], static fn($f) => $f !== '.' && $f !== '..');
        $resumen['sin_ticket'] += count($archivosHuerfanos);
        continue;
    }

    $autor = $autorFijo ?? (int)($ticket['id_realiza_accion'] ?: $adminPorDefecto);
    $destino = $destinoBase . '/' . $idTicket;

    $archivos = scandir($ruta) ?: [];
    sort($archivos, SORT_NATURAL);

    foreach ($archivos as $archivo) {
        if ($archivo === '.' || $archivo === '..' || $archivo[0] === '.') {
            continue;
        }
        if (strcasecmp($archivo, 'Thumbs.db') === 0 || strcasecmp($archivo, '.htaccess') === 0) {
            continue;
        }

        $fuente = $ruta . '/' . $archivo;
        if (!is_file($fuente)) {
            continue;
        }
        $resumen['archivos']++;

        $mime = (string)$finfo->file($fuente);
        if (!in_array($mime, $admitidos, true)) {
            echo "  · omitida  {$carpeta}/{$archivo}  (tipo no admitido: $mime)\n";
            $resumen['no_validas']++;
            continue;
        }

        $hash = hash_file('sha256', $fuente);
        if ($hash === false) {
            echo "  · error    {$carpeta}/{$archivo}  (no se puede leer)\n";
            $resumen['errores']++;
            continue;
        }

        // ¿Ya registrada en este parte?
        $ya = Db::value('SELECT id_adjunto FROM adjuntos WHERE id_ticket = ? AND hash = ?', [$idTicket, $hash]);
        if ($ya !== null) {
            $resumen['repetidas']++;
            continue;
        }

        $tamano = (int)filesize($fuente);
        $nuevoNombre = $hash . '.' . $extensionDe($mime, $archivo);
        $rutaDestino = $destino . '/' . $nuevoNombre;

        $medidas = @getimagesize($fuente);
        $ancho = $medidas[0] ?? null;
        $alto  = $medidas[1] ?? null;

        if ($simular) {
            echo "  · importaría {$carpeta}/{$archivo} → {$idTicket}/{$nuevoNombre} (" . round($tamano / 1024) . " KB)\n";
            $resumen['importadas']++;
            $resumen['bytes'] += $tamano;
            continue;
        }

        if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
            echo "  · error    no se puede crear $destino\n";
            $resumen['errores']++;
            continue;
        }

        if (!is_file($rutaDestino) && !copy($fuente, $rutaDestino)) {
            echo "  · error    no se puede copiar {$carpeta}/{$archivo}\n";
            $resumen['errores']++;
            continue;
        }
        @chmod($rutaDestino, 0664);

        try {
            Db::insert('adjuntos', [
                'uuid'              => Http::uuid(),
                'id_ticket'         => $idTicket,
                'archivo'           => $nuevoNombre,
                'nombre_original'   => mb_substr($archivo, 0, 250),
                'mime'              => $mime,
                'tamano'            => $tamano,
                'hash'              => $hash,
                'ancho'             => $ancho,
                'alto'              => $alto,
                'id_realiza_accion' => $autor,
                'borrado'           => 0,
                'fecha_creado'      => gmdate('Y-m-d H:i:s', (int)filemtime($fuente)),
                'updated_at'        => Db::now(),
            ]);
        } catch (Throwable $e) {
            echo "  · error    al registrar {$carpeta}/{$archivo}: " . $e->getMessage() . "\n";
            $resumen['errores']++;
            @unlink($rutaDestino);
            continue;
        }

        $resumen['importadas']++;
        $resumen['bytes'] += $tamano;

        if ($mover) {
            @unlink($fuente);
        }
    }

    // El parte queda marcado como modificado para que la app se lo baje.
    if (!$simular) {
        Db::update('ticket_incidencias', ['updated_at' => Db::now()], 'id_ticket = :id', ['id' => $idTicket]);
    }
}

/* ---------------------------------------------------------------- */
/* Resumen                                                          */
/* ---------------------------------------------------------------- */
echo "\n";
echo "Resumen\n";
echo "  carpetas revisadas ...... {$resumen['carpetas']}\n";
echo "  archivos encontrados .... {$resumen['archivos']}\n";
echo "  " . ($simular ? 'se importarían' : 'importadas') . " ........... {$resumen['importadas']}"
   . " (" . round($resumen['bytes'] / 1048576, 1) . " MB)\n";
echo "  ya estaban registradas .. {$resumen['repetidas']}\n";
echo "  sin parte asociado ...... {$resumen['sin_ticket']}\n";
echo "  tipo no admitido ........ {$resumen['no_validas']}\n";
echo "  errores ................. {$resumen['errores']}\n";

if ($sinTicket) {
    echo "\n  Carpetas cuyo parte ya no existe en la base de datos:\n    "
       . implode(', ', array_slice($sinTicket, 0, 40))
       . (count($sinTicket) > 40 ? ' …y ' . (count($sinTicket) - 40) . ' más' : '') . "\n"
       . "  Sus archivos se han dejado intactos: revísalos a mano si hacen falta.\n";
}

if ($simular) {
    echo "\nNo se ha escrito nada. Vuelve a ejecutarlo sin --simular para importar de verdad.\n";
} else {
    echo "\nHecho. Las fotos aparecerán en la ficha de cada parte.\n";
    if (!$mover) {
        echo "Los originales siguen en su sitio; bórralos cuando compruebes que todo está bien.\n";
    }
}
