<?php
/**
 * Rutas /api/mensajes — lo que se le enseña a alguien al entrar.
 *
 * Tres cosas, cada una con su momento:
 *
 *   · la bienvenida, una sola vez en la vida;
 *   · el mensaje que un administrador le ha dejado para el próximo
 *     inicio, una sola vez;
 *   · la frase del día, una distinta cada jornada.
 *
 * Nada de esto se manda si no está activado para esa persona: la
 * aplicación es una herramienta de trabajo, y un mensaje que nadie ha
 * pedido estorba.
 */
declare(strict_types=1);

$u = Auth::exigir();
$yo = (int)$u['id_login'];
$hoy = gmdate('Y-m-d');

/* ----------------------------------------------------------------- */
/* GET /api/mensajes — lo que toca enseñar ahora                      */
/* ----------------------------------------------------------------- */
if ($seccion === 'mensajes' && $metodo === 'GET' && ($partes[1] ?? '') === '') {
    $cols = Db::columnas('login');
    $extra = in_array('frases_cada_inicio', $cols, true) ? ', frases_cada_inicio' : '';
    $p = Db::one('SELECT mensaje_bienvenida, bienvenida_activa, bienvenida_vista,
                         mensaje_proximo, mensaje_proximo_visto,
                         frases_activas, frases_categorias' . $extra . '
                    FROM login WHERE id_login = ?', [$yo]) ?? [];

    $fuera = [];

    if ((int)($p['bienvenida_activa'] ?? 0) === 1
        && trim((string)($p['mensaje_bienvenida'] ?? '')) !== ''
        && ($p['bienvenida_vista'] ?? null) === null) {
        $fuera[] = ['tipo' => 'bienvenida', 'titulo' => 'Bienvenido',
                    'texto' => (string)$p['mensaje_bienvenida']];
    }

    /* El mensaje del próximo inicio se enseña una vez por cada vez que se
       escribe: al escribirlo se borra la marca de visto. */
    if (trim((string)($p['mensaje_proximo'] ?? '')) !== ''
        && ($p['mensaje_proximo_visto'] ?? null) === null) {
        $fuera[] = ['tipo' => 'proximo', 'titulo' => 'Un aviso para ti',
                    'texto' => (string)$p['mensaje_proximo']];
    }

    if ((int)($p['frases_activas'] ?? 0) === 1 && Db::tablaExiste('frases')) {
        /* Con «en cada inicio» marcado no se espera al día siguiente: se
           da una frase nueva cada vez, sin repetir, hasta agotarlas. */
        $frase = fraseDelDia($yo, (string)($p['frases_categorias'] ?? ''), $hoy,
                             (int)($p['frases_cada_inicio'] ?? 0) === 1);
        if ($frase !== null) {
            /* Se apunta al DÁRSELA, no al cerrarla: si se esperara a que la
               cerrase, quien no la cierra recibiría una distinta cada vez
               que abre la aplicación, y la idea es una por día. */
            if (Db::tablaExiste('frases_leidas')) {
                Db::insertarSiFalta('frases_leidas',
                    ['id_login' => $yo, 'id_frase' => (int)$frase['id_frase'], 'fecha' => Db::now()]);
            }
            $fuera[] = ['tipo' => 'frase', 'titulo' => 'Frase del día',
                        'texto' => $frase['texto'], 'autor' => $frase['autor'],
                        'id_frase' => (int)$frase['id_frase']];
        }
    }

    Http::ok(['mensajes' => $fuera]);
}

/* ----------------------------------------------------------------- */
/* POST /api/mensajes/visto — se ha cerrado uno                       */
/* ----------------------------------------------------------------- */
if ($seccion === 'mensajes' && $metodo === 'POST' && ($partes[1] ?? '') === 'visto') {
    $tipo = (string)Http::campo('tipo', '');

    if ($tipo === 'bienvenida') {
        Db::run('UPDATE login SET bienvenida_vista = ? WHERE id_login = ?', [Db::now(), $yo]);
    } elseif ($tipo === 'proximo') {
        Db::run('UPDATE login SET mensaje_proximo_visto = ? WHERE id_login = ?', [Db::now(), $yo]);
    } elseif ($tipo === 'frase') {
        $id = Http::id(Http::campo('id_frase'));
        if ($id !== null && Db::tablaExiste('frases_leidas')) {
            /* Cerrada con el aspa: ya no vuelve a salir. Antes sólo se
               apuntaba que se le había dado, así que al recargar volvía a
               aparecer la misma frase una y otra vez. */
            Db::insertarSiFalta('frases_leidas',
                ['id_login' => $yo, 'id_frase' => $id, 'fecha' => Db::now()]);
            if (in_array('cerrada', Db::columnas('frases_leidas'), true)) {
                Db::run('UPDATE frases_leidas SET cerrada = ? WHERE id_login = ? AND id_frase = ?',
                        [Db::now(), $yo, $id]);
            }
        }
    }

    Http::ok(['mensaje' => 'Anotado.']);
}

/* ================================================================= */
/* /api/frases — el panel de frases (administradores)                 */
/* ================================================================= */
if ($seccion === 'frases') {

    /* GET: todas, con sus categorías. Lo puede leer cualquiera con
       sesión —hace falta para elegir categorías— pero sólo un
       administrador las cambia. */
    if ($metodo === 'GET') {
        Http::ok([
            'categorias' => Db::all('SELECT id_categoria, nombre, activo FROM frases_categorias ORDER BY nombre'),
            'frases'     => Db::all('SELECT id_frase, texto, autor, id_categoria, activo FROM frases ORDER BY id_categoria, id_frase'),
        ]);
    }

    Auth::exigirAdmin();

    if ($metodo === 'POST' && ($partes[1] ?? '') === 'categoria') {
        $nombre = trim((string)Http::requerido('nombre'));
        if ($nombre === '') { throw new ApiError('Ponle nombre a la categoría.', 422, ['campo' => 'nombre']); }
        if (Db::value('SELECT id_categoria FROM frases_categorias WHERE nombre = ?', [$nombre]) !== null) {
            throw new ApiError('Ya hay una categoría con ese nombre.', 422, ['campo' => 'nombre']);
        }
        $id = Db::insert('frases_categorias', ['nombre' => mb_substr($nombre, 0, 80), 'activo' => 1]);
        Http::ok(['id_categoria' => $id, 'mensaje' => 'Categoría creada.']);
    }

    if ($metodo === 'POST' && ($partes[1] ?? '') === '') {
        $texto = trim((string)Http::requerido('texto'));
        if ($texto === '') { throw new ApiError('Escribe la frase.', 422, ['campo' => 'texto']); }
        $id = Db::insert('frases', [
            'texto'        => mb_substr($texto, 0, 400),
            'autor'        => mb_substr(trim((string)Http::campo('autor', '')), 0, 120) ?: null,
            'id_categoria' => Http::id(Http::campo('id_categoria')),
            'activo'       => 1,
        ]);
        Http::ok(['id_frase' => $id, 'mensaje' => 'Frase añadida.']);
    }

    /* Cambiar o borrar una categoría. Faltaba: quien acabó con categorías
       repetidas no tenía forma de tocarlas desde la aplicación. */
    if (($partes[1] ?? '') === 'categoria' && ($partes[2] ?? '') !== '') {
        $idCat = Http::id($partes[2]);
        if ($idCat === null) { throw new ApiError('Categoría no válida.', 422); }

        if ($metodo === 'PATCH') {
            $nombre = trim((string)Http::requerido('nombre'));
            if ($nombre === '') { throw new ApiError('Ponle nombre a la categoría.', 422, ['campo' => 'nombre']); }
            $otra = Db::value('SELECT id_categoria FROM frases_categorias WHERE nombre = ? AND id_categoria <> ?',
                              [$nombre, $idCat]);
            if ($otra !== null) { throw new ApiError('Ya hay una categoría con ese nombre.', 422, ['campo' => 'nombre']); }
            Db::update('frases_categorias', ['nombre' => mb_substr($nombre, 0, 80), 'updated_at' => Db::now()],
                       'id_categoria = :id', ['id' => $idCat]);
            Http::ok(['mensaje' => 'Categoría guardada.']);
        }

        if ($metodo === 'DELETE') {
            /* Las frases que tuviera se quedan sin categoría, no se borran:
               una frase escrita a mano vale más que su etiqueta. */
            $cuantas = (int)Db::value('SELECT COUNT(*) FROM frases WHERE id_categoria = ?', [$idCat]);
            Db::run('UPDATE frases SET id_categoria = NULL WHERE id_categoria = ?', [$idCat]);
            Db::run('DELETE FROM frases_categorias WHERE id_categoria = ?', [$idCat]);
            Http::ok(['mensaje' => $cuantas
                ? "Categoría borrada. Sus $cuantas frases se quedan sin categoría."
                : 'Categoría borrada.']);
        }
    }

    $idFrase = Http::id($partes[1] ?? null);
    if ($idFrase !== null && $metodo === 'PATCH') {
        $datos = [];
        foreach (['texto', 'autor'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = mb_substr(trim((string)Http::campo($c)), 0, 400); }
        }
        if (array_key_exists('id_categoria', Http::body())) { $datos['id_categoria'] = Http::id(Http::campo('id_categoria')); }
        if (array_key_exists('activo', Http::body()))       { $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0; }
        if (!$datos) { throw new ApiError('No hay cambios que guardar.', 422); }
        $datos['updated_at'] = Db::now();
        Db::update('frases', $datos, 'id_frase = :id', ['id' => $idFrase]);
        Http::ok(['mensaje' => 'Frase guardada.']);
    }

    if ($idFrase !== null && $metodo === 'DELETE') {
        /* Se borra también de lo leído: si no, quedarían apuntes hacia una
           frase que ya no existe. */
        Db::run('DELETE FROM frases_leidas WHERE id_frase = ?', [$idFrase]);
        Db::run('DELETE FROM frases WHERE id_frase = ?', [$idFrase]);
        Http::ok(['mensaje' => 'Frase borrada.']);
    }
}

throw new ApiError('Ruta de mensajes no encontrada.', 404);

/* ----------------------------------------------------------------- */

/**
 * La frase de hoy para esta persona.
 *
 * Una por día: si ya leyó una hoy, se le devuelve esa misma —no una
 * nueva cada vez que abre la aplicación—. Si no, una que no haya leído
 * nunca, de sus categorías. Cuando se le acaban, se empieza de nuevo por
 * las más antiguas: es mejor repetir al cabo de meses que dejar de dar
 * frase.
 */
function fraseDelDia(int $yo, string $categoriasJson, string $hoy, bool $cadaInicio = false): ?array
{
    $cats = json_decode($categoriasJson ?: '[]', true);
    $cats = is_array($cats) ? array_values(array_filter(array_map('intval', $cats))) : [];

    $filtroCat = '';
    $par = [];
    if ($cats) {
        $filtroCat = ' AND f.id_categoria IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
        $par = $cats;
    }

    // ¿Ya tiene una de hoy? Con «en cada inicio» esto se salta.
    $hayCerrada = in_array('cerrada', Db::columnas('frases_leidas'), true);
    $deHoy = Db::one(
        'SELECT f.id_frase, f.texto, f.autor' . ($hayCerrada ? ', l.cerrada' : '') . '
           FROM frases_leidas l
           JOIN frases f ON f.id_frase = l.id_frase
          WHERE l.id_login = ? AND ' . Db::comoFecha('l.fecha') . ' = ?
          ORDER BY l.fecha DESC',
        [$yo, $hoy]);

    /* Si la de hoy ya se cerró con el aspa, hoy no hay más frase: los
       mensajes son de leer una vez y quitarlos de en medio. Con «en cada
       inicio» sí se da otra, que para eso está esa casilla. */
    if ($deHoy !== null && $hayCerrada && ($deHoy['cerrada'] ?? null) !== null && !$cadaInicio) {
        return null;
    }
    if ($deHoy !== null && !$cadaInicio) {
        unset($deHoy['cerrada']);
        return $deHoy;
    }

    // Una que no haya leído nunca.
    $nueva = Db::one(
        'SELECT f.id_frase, f.texto, f.autor FROM frases f
          WHERE f.activo = 1' . $filtroCat . '
            AND f.id_frase NOT IN (SELECT id_frase FROM frases_leidas WHERE id_login = ?)
          ORDER BY ' . Db::alAzar() . ' LIMIT 1',
        array_merge($par, [$yo]));
    if ($nueva !== null) {
        return $nueva;
    }

    // Se le han acabado: la que leyó hace más tiempo.
    return Db::one(
        'SELECT f.id_frase, f.texto, f.autor FROM frases f
           JOIN frases_leidas l ON l.id_frase = f.id_frase AND l.id_login = ?
          WHERE f.activo = 1' . $filtroCat . '
          ORDER BY l.fecha ASC LIMIT 1',
        array_merge([$yo], $par));
}
