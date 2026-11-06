<?php
/** Rutas /api/mantenimiento */
declare(strict_types=1);

$u = Auth::exigir();

if (!Permisos::activo('mantenimiento')) {
    throw new ApiError('El mantenimiento programado está desactivado.', 403);
}

require_once __DIR__ . '/../lib/Tickets.php';
require_once __DIR__ . '/../lib/Mantenimiento.php';

$accion = $partes[1] ?? '';

/* GET /api/mantenimiento */
if ($accion === '' && $metodo === 'GET') {
    Http::ok([
        'mantenimientos' => Mantenimiento::listar(),
        'unidades'       => Mantenimiento::UNIDADES,
        'editable'       => Auth::esResponsable($u),
    ]);
}

/* POST /api/mantenimiento/generar — lanzar la revisión de vencimientos */
if ($accion === 'generar' && $metodo === 'POST') {
    Auth::exigirAdmin();
    $simular = Http::bool(Http::campo('simular'));
    $lineas = [];
    $r = Mantenimiento::generar(static function (string $m) use (&$lineas) { $lineas[] = $m; }, $simular);
    Http::ok(['generados' => $r['generados'], 'detalle' => $r['detalle'], 'registro' => $lineas]);
}

/* GET /api/mantenimiento/{id}/historial */
$id = Http::id($accion);
if ($id !== null && ($partes[2] ?? '') === 'historial' && $metodo === 'GET') {
    Http::ok(['tickets' => Mantenimiento::historial($id)]);
}

/**
 * El tope de repetición que venga en la petición.
 *
 * Dos límites, y se termina con el primero que llegue:
 *   repeticiones → cuántas veces en total (las seis del contrato)
 *   hasta        → fecha final (hasta que cierre la piscina)
 *
 * Vacío o 0 significa «sin tope», que es como se ha comportado siempre.
 * Si la instalación todavía no tiene las columnas, devuelve nada y no
 * rompe: así una base sin actualizar sigue guardando mantenimientos.
 *
 * @param bool $soloSiViene en PATCH sólo se toca lo que manden
 */
function topeDeRepeticion(bool $soloSiViene = false): array
{
    $cols = Db::columnas('mantenimientos');
    $out  = [];

    if (in_array('repeticiones', $cols, true)
        && (!$soloSiViene || array_key_exists('repeticiones', Http::body()))) {
        $n = (int)Http::campo('repeticiones', 0);
        $out['repeticiones'] = $n > 0 ? $n : null;
    }

    if (in_array('hasta', $cols, true)
        && (!$soloSiViene || array_key_exists('hasta', Http::body()))) {
        $h = Tickets::fecha(Http::campo('hasta'));
        $out['hasta'] = $h === null ? null : substr($h, 0, 10);
    }

    return $out;
}

/* A partir de aquí hace falta ser responsable */
if (!Auth::esResponsable($u)) {
    throw new ApiError('Sólo un responsable puede gestionar el mantenimiento programado.', 403);
}

/* POST /api/mantenimiento */
if ($accion === '' && $metodo === 'POST') {
    $nombre = Http::requerido('nombre');
    $unidad = Http::texto('unidad', 'meses');
    if (!isset(Mantenimiento::UNIDADES[$unidad])) {
        throw new ApiError('Periodicidad no válida.', 422, ['campo' => 'unidad']);
    }
    $proximo = Tickets::fecha(Http::campo('proximo')) ?? Db::now();

    /* Hasta cuándo se repite. Las dos formas valen y pueden ir juntas:
       se termina con la primera que se cumpla. Vacío = para siempre,
       que es como funcionaba antes. */
    $tope = topeDeRepeticion();

    $idNuevo = Db::insert('mantenimientos', array_merge([
        'id_hotel'    => Db::tablaExiste('hoteles') ? Hoteles::actual($u) : null,
        'nombre'      => mb_substr($nombre, 0, 120),
        'descripcion' => mb_substr((string)Http::campo('descripcion', ''), 0, 1000) ?: null,
        'id_familia'  => Http::id(Http::campo('id_familia')),
        'id_elemento' => Http::id(Http::campo('id_elemento')),
        'id_rel_zona' => Http::id(Http::campo('id_rel_zona')),
        'intervalo'   => max(1, (int)Http::campo('intervalo', 1)),
        'unidad'      => $unidad,
        'proximo'     => substr($proximo, 0, 10),
        'aviso_dias'  => max(0, (int)Http::campo('aviso_dias', 3)),
        'id_departamento' => Http::id(Http::campo('id_departamento')),
        'id_persona'  => Http::id(Http::campo('id_persona')),
        'id_prioridad'=> Http::id(Http::campo('id_prioridad')),
        'obligatorio' => Http::bool(Http::campo('obligatorio')) ? 1 : 0,
        'activo'      => 1,
        'veces'       => 0,
        'creado'      => Db::now(),
        'updated_at'  => Db::now(),
    ], $tope));
    Http::ok(['id_mantenimiento' => $idNuevo], 201);
}

if ($id !== null && ($partes[2] ?? '') === '') {

    if ($metodo === 'DELETE') {
        /* Con partes detrás se desactiva; sin ellos se borra de verdad.
         *
         * Antes se desactivaba siempre, incluida una revisión creada por
         * error y borrada acto seguido: se quedaba en la lista para
         * siempre, tachada, sin haber generado nada. Igual que con los
         * departamentos: si no hay historial que proteger, no hay nada
         * que conservar. Lo notaron las pruebas, que se dejaban sus
         * revisiones de mentira puestas creyendo haberlas borrado. */
        $conPartes = (int)Db::value(
            'SELECT COUNT(*) FROM ticket_incidencias WHERE id_mantenimiento = ?', [$id]);

        if ($conPartes === 0) {
            Db::run('DELETE FROM mantenimientos WHERE id_mantenimiento = ?', [$id]);
            Http::ok(['mensaje' => 'Revisión eliminada. No había generado ningún parte.',
                      'borrada' => true]);
        }

        // Los partes que ya generó conservan su vínculo y su historial
        // sigue teniendo sentido.
        Db::update('mantenimientos', ['activo' => 0, 'updated_at' => Db::now()],
                   'id_mantenimiento = :id', ['id' => $id]);
        Http::ok(['mensaje' => 'Mantenimiento desactivado: tiene ' . $conPartes
                             . ' parte(s) detrás y su historial se conserva.']);
    }

    if ($metodo === 'PATCH') {
        $datos = ['updated_at' => Db::now()];
        foreach (['nombre', 'descripcion'] as $c) {
            if (array_key_exists($c, Http::body())) {
                $datos[$c] = mb_substr((string)Http::campo($c), 0, 1000) ?: null;
            }
        }
        foreach (['id_familia','id_elemento','id_rel_zona','id_departamento','id_persona','id_prioridad'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = Http::id(Http::campo($c)); }
        }
        if (array_key_exists('intervalo', Http::body()))  { $datos['intervalo'] = max(1, (int)Http::campo('intervalo')); }
        if (array_key_exists('aviso_dias', Http::body())) { $datos['aviso_dias'] = max(0, (int)Http::campo('aviso_dias')); }
        if (array_key_exists('unidad', Http::body())) {
            $un = (string)Http::campo('unidad');
            if (isset(Mantenimiento::UNIDADES[$un])) { $datos['unidad'] = $un; }
        }
        if (array_key_exists('proximo', Http::body())) {
            $p = Tickets::fecha(Http::campo('proximo'));
            if ($p !== null) { $datos['proximo'] = substr($p, 0, 10); }
        }
        foreach (['obligatorio', 'activo'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = Http::bool(Http::campo($c)) ? 1 : 0; }
        }
        $datos = array_merge($datos, topeDeRepeticion(true));
        Db::update('mantenimientos', $datos, 'id_mantenimiento = :id', ['id' => $id]);
        Http::ok(['mensaje' => 'Mantenimiento guardado.']);
    }
}

throw new ApiError('Ruta de mantenimiento no encontrada.', 404);
