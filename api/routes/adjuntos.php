<?php
/** Rutas /api/adjuntos/{id} */
declare(strict_types=1);

$u  = Auth::exigir();
$id = Http::id($partes[1] ?? null);

if ($id === null) {
    throw new ApiError('Falta el identificador del adjunto.', 400);
}

/* DELETE /api/adjuntos/{id} */
if ($metodo === 'DELETE') {
    Uploads::borrar($id, $u);
    Http::ok(['mensaje' => 'Foto eliminada.']);
}

/* GET /api/adjuntos/{id} — descarga con control de acceso */
if ($metodo === 'GET') {
    $a = Db::one('SELECT * FROM adjuntos WHERE id_adjunto = ? AND borrado = 0', [$id]);
    if ($a === null) {
        throw new ApiError('El adjunto no existe.', 404);
    }

    /* La foto es del parte: sólo la ve quien puede ver el parte.
     *
     * Antes bastaba con tener sesión, y como los números son correlativos,
     * cualquiera del hotel podía descargar las fotos de todos los partes,
     * de todos los departamentos y de todos los hoteles, pidiéndolas una
     * tras otra. Se responde igual —«no existe»— si no se puede ver, para
     * no confirmar qué números existen. */
    $t = Repo::ticket((int)$a['id_ticket']);
    if ($t === null || !Permisos::puedeVer($u, $t)) {
        throw new ApiError('El adjunto no existe.', 404);
    }

    $ruta = $config['uploads']['dir'] . '/' . (int)$a['id_ticket'] . '/' . $a['archivo'];
    if (!is_file($ruta)) {
        throw new ApiError('El archivo no está disponible en el servidor.', 404);
    }
    header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Disposition: inline; filename="' . rawurlencode((string)$a['nombre_original']) . '"');
    readfile($ruta);
    exit;
}

throw new ApiError('Método no permitido.', 405);
