<?php
/**
 * Rutas /api/errores
 *
 *   POST /api/errores            el móvil manda un fallo suyo
 *   GET  /api/errores            la lista, para quien administra
 *   POST /api/errores/visto      dar por vistos (todos o uno)
 *
 * Mandar un fallo lo puede hacer cualquiera que haya entrado: si sólo
 * pudieran los administradores, no nos enteraríamos justo de lo que le
 * pasa a la gente que usa la aplicación todo el día.
 */
declare(strict_types=1);

$u   = Auth::exigir();
$que = $partes[1] ?? '';

/* ------------------------------------------------------------------ */

if ($que === '' && $metodo === 'POST') {
    $mensaje = Http::texto('mensaje');
    if ($mensaje === '') {
        throw new ApiError('Falta el mensaje del error.', 422);
    }

    Errores::apuntar(
        'movil',
        $mensaje,
        (string)Http::campo('detalle', ''),
        Http::campo('ruta') !== null ? (string)Http::campo('ruta') : null,
        $u,
        Http::campo('version') !== null ? (string)Http::campo('version') : null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );

    Http::ok(['mensaje' => 'Apuntado.'], 201);
}

/* A partir de aquí, sólo quien administra. */
if (!Auth::esAdmin($u)) {
    throw new ApiError('Sólo un administrador puede ver los errores.', 403);
}

if ($que === '' && $metodo === 'GET') {
    Http::ok([
        'errores' => Errores::listar(Http::bool(Http::query('todos')) === false, 150),
        'resumen' => Errores::sinVer(),
    ]);
}

if ($que === 'visto' && $metodo === 'POST') {
    $id = Http::id(Http::campo('id_error'));
    $n  = Errores::marcarVisto($id);
    Http::ok(['mensaje' => $n . ' error(es) dados por vistos.', 'marcados' => $n]);
}

throw new ApiError('Ruta no encontrada: errores/' . $que, 404);
