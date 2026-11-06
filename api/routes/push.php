<?php
/** Rutas /api/push */
declare(strict_types=1);

$u = Auth::exigir();
$accion = $partes[1] ?? '';

/* GET /api/push/clave — lo que el navegador necesita para suscribirse */
if ($accion === 'clave' && $metodo === 'GET') {
    Http::ok([
        'clave'    => Push::clavePublica(),
        'activo'   => (bool)Permisos::ajuste('mod_push', true),
        'destinos' => Push::destinosDe((int)$u['id_login']),
    ]);
}

/* POST /api/push — dar de alta este aparato */
if ($accion === '' && $metodo === 'POST') {
    $id = Push::registrar(Http::body(), (int)$u['id_login']);
    Http::ok(['id_destino' => $id, 'mensaje' => 'Este aparato recibirá avisos.'], 201);
}

/* DELETE /api/push — dar de baja este aparato */
if ($accion === '' && $metodo === 'DELETE') {
    Push::borrar((string)Http::requerido('destino'), (int)$u['id_login']);
    Http::ok(['mensaje' => 'Este aparato deja de recibir avisos.']);
}

/* POST /api/push/probar — mandarse un aviso a uno mismo */
if ($accion === 'probar' && $metodo === 'POST') {
    /* Cuántos aparatos hay apuntados ANTES de intentarlo.
     *
     * Sin esto el mensaje decía «No hay ningún aparato registrado, o el
     * envío ha fallado», mezclando los dos únicos problemas posibles en
     * una sola frase. Y son muy distintos: en el primero hay que activar
     * los avisos en el móvil, y en el segundo el móvil está bien y lo que
     * falla es el servidor —claves, salida a internet, el servicio de
     * Google—. El día que los avisos no lleguen, esa frase era justo la
     * que había que leer para saber por dónde empezar, y no decía nada. */
    $apuntados = Db::tablaExiste('push_destinos')
        ? (int)Db::value('SELECT COUNT(*) FROM push_destinos WHERE id_login = ?',
                         [(int)$u['id_login']])
        : 0;

    $n = Push::enviar((int)$u['id_login'],
        'Prueba de aviso',
        'Si lees esto con la aplicación cerrada, el push funciona.',
        ['evento' => 'urgente', 'ruta' => '/avisos']);

    if ($n > 0) {
        $mensaje = "Enviado a $n aparato" . ($n === 1 ? '' : 's')
                 . '. Cierra la aplicación y espera unos segundos.';
    } elseif ($apuntados === 0) {
        $mensaje = 'Este aparato no tiene los avisos activados: enciende el '
                 . 'interruptor de arriba y vuelve a probar.';
    } else {
        $mensaje = 'Hay ' . $apuntados . ' aparato' . ($apuntados === 1 ? '' : 's')
                 . ' apuntado' . ($apuntados === 1 ? '' : 's')
                 . ', pero el envío ha fallado. El móvil está bien: mira las '
                 . 'claves de push en Configuración y que el servidor tenga '
                 . 'salida a internet. Queda anotado en el registro de errores.';
    }

    Http::ok([
        'enviados'  => $n,
        'apuntados' => $apuntados,
        'mensaje'   => $mensaje,
    ]);
}

throw new ApiError('Ruta de push no encontrada.', 404);
