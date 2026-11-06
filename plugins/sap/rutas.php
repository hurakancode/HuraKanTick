<?php
/**
 * Rutas /api/sap — las atiende el módulo, no el núcleo.
 *
 *   GET  /api/sap/estado                 si está encendido y configurado
 *   GET  /api/sap/habitacion?numero=214  lo que sepa SAP de esa habitación
 *   POST /api/sap/probar                 prueba de conexión (administradores)
 *
 * Cada dato se recorta según el alcance de quien pregunta: la ruta
 * devuelve sólo lo que esa persona puede ver, no todo con un aviso. Lo
 * que no se debe enseñar, no sale del servidor.
 */
declare(strict_types=1);

require_once __DIR__ . '/Sap.php';

$u = Auth::exigir();
$que = $partes[1] ?? '';

/** ¿Alcanza a esta persona esta opción? */
$puede = static fn(string $clave): bool =>
    Plugins::alcanza((string)Permisos::ajuste($clave, 'nadie'), $u);

/* ------------------------------------------------------------------ */

if ($que === 'estado' && $metodo === 'GET') {
    Http::ok([
        'activo'      => true,
        'configurado' => Sap::configurado(),
        'tipo'        => (string)Permisos::ajuste('sap_tipo', 'demo'),
        'permisos'    => [
            'ocupacion' => $puede('sap_ver_ocupacion'),
            'estado'    => $puede('sap_ver_estado'),
            'huespedes' => $puede('sap_ver_huespedes'),
            'pais'      => $puede('sap_ver_pais'),
            'fechas'    => $puede('sap_ver_fechas'),
        ],
        'en_parte'    => (bool)Permisos::ajuste('sap_en_parte', false),
        'en_ficha'    => (bool)Permisos::ajuste('sap_en_ficha', false),
        'avisar'      => (bool)Permisos::ajuste('sap_avisar_ocupada', false),
    ]);
}

if ($que === 'habitacion' && $metodo === 'GET') {
    $numero = trim((string)Http::query('numero'));
    if ($numero === '') {
        throw new ApiError('Dime qué habitación.', 422, ['campo' => 'numero']);
    }
    if (!$puede('sap_ver_ocupacion') && !$puede('sap_ver_estado')) {
        throw new ApiError('No tienes permiso para consultar las habitaciones.', 403);
    }

    $r = Sap::habitacion($numero);

    /* Se recorta aquí, en el servidor. Mandarlo todo y esconderlo en la
       pantalla sería dejarlo a la vista de cualquiera que mire la
       respuesta con las herramientas del navegador. */
    $salida = ['habitacion' => $r['habitacion'], 'de_cache' => $r['de_cache'] ?? false];

    if ($puede('sap_ver_ocupacion')) { $salida['ocupada'] = $r['ocupada']; }
    if ($puede('sap_ver_estado')) {
        $salida['estado']      = $r['estado'];
        $salida['no_molestar'] = $r['no_molestar'];
        $salida['salida_hoy']  = $r['salida_hoy'];
    }
    if ($puede('sap_ver_huespedes') || $puede('sap_ver_pais')) {
        $salida['huespedes'] = array_map(static function (array $g) use ($puede) {
            $x = [];
            if ($puede('sap_ver_huespedes')) { $x['nombre'] = $g['nombre']; }
            if ($puede('sap_ver_pais')) {
                $x['pais']   = $g['pais'];
                $x['idioma'] = $g['idioma'];
            }
            return $x;
        }, $r['huespedes']);
        if ($puede('sap_ver_huespedes')) {
            $salida['adultos'] = $r['adultos'];
            $salida['ninos']   = $r['ninos'];
        }
    }
    if ($puede('sap_ver_fechas')) {
        $salida['desde'] = $r['desde'];
        $salida['hasta'] = $r['hasta'];
    }

    Http::ok($salida);
}

if ($que === 'probar' && $metodo === 'POST') {
    if (!Auth::esAdmin($u)) {
        throw new ApiError('Sólo un administrador puede probar la conexión.', 403);
    }
    Http::ok(Sap::probar());
}

throw new ApiError('Ruta del módulo SAP no encontrada: ' . $que, 404);
