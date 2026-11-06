<?php
/** Rutas /api/instrucciones */
declare(strict_types=1);

$u = Auth::exigir();

if (!Permisos::activo('instrucciones')) {
    throw new ApiError('Las instrucciones están desactivadas.', 403);
}

$accion = $partes[1] ?? '';

/** Ficha completa de una instrucción. */
$normaliza = static function (array $i): array {
    foreach (['id_instruccion','vistas','util','activo','orden'] as $k) {
        $i[$k] = (int)($i[$k] ?? 0);
    }
    foreach (['id_familia','id_elemento','id_zona_1','id_autor'] as $k) {
        $i[$k] = $i[$k] === null ? null : (int)$i[$k];
    }
    /* Cómo hay que leer «contenido»: texto plano como siempre, o la
       estructura por bloques que dibuja la aplicación. Si la columna no
       existe todavía —instalación sin actualizar— se trata como texto,
       que es lo que había. */
    $i['formato'] = in_array($i['formato'] ?? 'texto', ['texto', 'bloques'], true)
        ? (string)($i['formato'] ?? 'texto') : 'texto';
    $i['etiquetas'] = $i['etiquetas']
        ? array_values(array_filter(array_map('trim', explode(',', (string)$i['etiquetas']))))
        : [];
    return $i;
};

$select = 'SELECT i.*, f.nombre AS familia, f.color AS familia_color, f.icono AS familia_icono,
                  el.nombre_elemento AS elemento, z1.nombre_zona_1 AS planta,
                  ' . Db::nombreCompleto('l') . ' AS autor
             FROM instrucciones i
             LEFT JOIN familias f  ON f.id_familia = i.id_familia
             LEFT JOIN elemento el ON el.id_elemento = i.id_elemento
             LEFT JOIN zona_1 z1   ON z1.id_zona_1 = i.id_zona_1
             LEFT JOIN login l     ON l.id_login = i.id_autor';

/* GET /api/instrucciones — listado, con filtros */
if ($accion === '' && $metodo === 'GET') {
    $where = ['i.activo = 1'];
    $p = [];
    if (Db::tablaExiste('hoteles')) {
        [$hc, $hp] = Hoteles::filtro('i', $u);
        $where[] = $hc;
        $p += $hp;
    }

    $q = trim((string)Http::query('q', ''));
    if ($q !== '') {
        // Se busca en título, resumen, etiquetas y cuerpo: quien busca
        // «purgar» no se acuerda de si estaba en el título.
        // Sin acentos, como en el resto de buscadores.
        $where[] = '(' . Repo::sinAcentos('i.titulo') . ' LIKE :q ESCAPE \'!\''
                 . ' OR ' . Repo::sinAcentos("COALESCE(i.resumen,'')") . ' LIKE :q2 ESCAPE \'!\''
                 . ' OR ' . Repo::sinAcentos("COALESCE(i.etiquetas,'')") . ' LIKE :q3 ESCAPE \'!\''
                 . ' OR ' . Repo::sinAcentos("COALESCE(i.contenido,'')") . ' LIKE :q4 ESCAPE \'!\')';
        $t = Repo::patron(Repo::planoTexto($q));
        $p += ['q' => $t, 'q2' => $t, 'q3' => $t, 'q4' => $t];
    }
    $fam = Http::queryInt('familia');
    if ($fam !== null) {
        $where[] = 'i.id_familia = :fam';
        $p['fam'] = $fam;
    }
    $ele = Http::queryInt('elemento');
    if ($ele !== null) {
        // Las de ese tipo concreto, y también las generales de su familia
        $where[] = '(i.id_elemento = :ele
                     OR (i.id_elemento IS NULL AND i.id_familia =
                         (SELECT id_familia FROM elemento WHERE id_elemento = :ele2)))';
        $p['ele'] = $ele;
        $p['ele2'] = $ele;
    }

    $filas = Db::all(
        $select . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY i.orden, i.vistas DESC, i.titulo LIMIT 300',
        $p
    );
    Http::ok(['instrucciones' => array_map($normaliza, $filas)]);
}

/* GET /api/instrucciones/{id} */
$id = Http::id($accion);
if ($id !== null && $metodo === 'GET') {
    $i = Db::one($select . ' WHERE i.id_instruccion = ?', [$id]);
    if ($i !== null && Db::tablaExiste('hoteles') && $i['id_hotel'] !== null
        && !Hoteles::puede((int)$i['id_hotel'], $u)) {
        $i = null;
    }
    if ($i === null) {
        throw new ApiError('Esa instrucción no existe.', 404);
    }
    // Se cuenta la consulta sin tocar updated_at: no es un cambio del
    // contenido y no debe provocar una sincronización a todos.
    Db::run('UPDATE instrucciones SET vistas = vistas + 1 WHERE id_instruccion = ?', [$id]);
    Http::ok(['instruccion' => $normaliza($i)]);
}

/* POST /api/instrucciones/{id}/util — marcar que sirvió */
if ($id !== null && ($partes[2] ?? '') === 'util' && $metodo === 'POST') {
    Db::run('UPDATE instrucciones SET util = util + 1 WHERE id_instruccion = ?', [$id]);
    Http::ok(['mensaje' => 'Gracias.']);
}

/**
 * El formato que llega, comprobado de verdad.
 *
 * Si dice «bloques», el contenido tiene que ser JSON con una lista de
 * bloques. Guardar «bloques» con cualquier otra cosa dentro dejaría el
 * manual en blanco al abrirlo, y el autor no sabría por qué.
 */
$formatoYContenido = static function (string $contenido): array {
    $formato = (string)Http::campo('formato', 'texto');
    if (!in_array($formato, ['texto', 'bloques'], true)) { $formato = 'texto'; }

    /* El texto sencillo también tiene tope.
     *
     * Lo tenía sólo el manual por bloques, y el texto a secas no tenía
     * ninguno: se podían guardar cien mil caracteres de una pegada. Y
     * esto no se queda en el servidor —las instrucciones se precargan
     * para poder leerlas sin cobertura—, así que una instrucción enorme
     * se baja entera a los cincuenta móviles del hotel y se queda ahí
     * ocupando sitio.
     *
     * Veinte mil caracteres son unas ocho páginas escritas a mano. Lo que
     * no cabe ahí no son unos pasos: es un manual, y para eso está el
     * editor de manuales. */
    if ($formato === 'texto' && mb_strlen($contenido) > 20000) {
        throw new ApiError(
            'Esto son más de veinte mil caracteres, que es muchísimo para unos '
            . 'pasos escritos a mano. Pártelo en varias instrucciones, o pásalo '
            . 'al editor de manuales, que admite fotos, tablas y vídeo.',
            413, ['campo' => 'contenido']);
    }

    if ($formato === 'bloques') {
        $d = json_decode($contenido, true);
        if (!is_array($d) || !isset($d['blocks']) || !is_array($d['blocks'])) {
            throw new ApiError(
                'El contenido por bloques no es válido. Vuelve a guardar desde el '
                . 'editor avanzado, o cambia al editor sencillo.',
                422, ['campo' => 'contenido']);
        }
        /* Un tope al tamaño. Esto se sincroniza a cincuenta móviles y se
           guarda en su memoria: un manual de medio megabyte de texto se
           nota en cada arranque. Las fotos no cuentan, van aparte. */
        if (strlen($contenido) > 400000) {
            throw new ApiError(
                'El manual es demasiado largo (más de 400 KB de texto). Pártelo en '
                . 'dos instrucciones: también se leerá mejor.', 413, ['campo' => 'contenido']);
        }
    }
    return [$formato, $contenido];
};

/* A partir de aquí hace falta ser responsable */
if (!Auth::esResponsable($u)) {
    throw new ApiError('Sólo un responsable puede escribir instrucciones.', 403);
}

/* POST /api/instrucciones/medios — una foto o un vídeo del manual
 *
 * Responde en el formato que espera el bloque de imagen de Editor.js:
 * {success:1, file:{url}}. Se añade «url» suelta también porque es lo
 * que lee nuestro propio código y así no hay que recordar cuál es cuál.
 *
 * El guardado va por Uploads::guardarSuelto, que es donde vive la
 * comprobación del tipo REAL del fichero y el candado que impide que
 * algo subido se ejecute. No se repite aquí: repetir eso es repetir un
 * agujero. */
if ($accion === 'medios' && $metodo === 'POST') {
    $f = $_FILES['archivo'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new ApiError('No ha llegado ningún archivo.', 422, ['campo' => 'archivo']);
    }

    $bytes = (string)file_get_contents($f['tmp_name']);

    /* Foto o vídeo: cada uno con sus tipos y su tope.
     *
     * Se mira el contenido del fichero, no lo que diga «tipo» en el
     * formulario: eso lo manda el navegador y es sugerencia, no dato. */
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->buffer($bytes);
    $esVideo = str_starts_with($mime, 'video/');

    global $config;
    if ($esVideo) {
        $tipos = $config['uploads']['video_mimes'] ?? ['video/mp4', 'video/webm'];
        /* El tope del vídeo lo puede bajar un administrador sin tocar
           ficheros: un hotel con poco disco querrá 10 MB y no 40. */
        $mb = (int)Permisos::ajuste('instrucciones_video_mb', 0);
        $tope = $mb > 0
            ? $mb * 1024 * 1024
            : (int)($config['uploads']['video_max_bytes'] ?? 40 * 1024 * 1024);
    } else {
        $tipos = null;      // los de siempre
        $tope  = null;
    }

    /* Y si PHP no da para tanto, se dice con el número de verdad en vez
       de dejar que la subida muera sin explicación. */
    $real = Uploads::topeReal($tope);
    if (strlen($bytes) > $real['bytes']) {
        throw new ApiError(
            'Este archivo pesa ' . round(strlen($bytes) / 1048576, 1) . ' MB y el máximo '
            . 'aquí son ' . $real['mb'] . ' MB (manda ' . $real['manda'] . ').'
            . ($esVideo ? ' Para un vídeo largo usa el bloque «Vídeo de fuera» con un enlace.' : ''),
            413, ['campo' => 'archivo']);
    }

    $r = Uploads::guardarSuelto('instrucciones', $bytes,
                                (string)($f['name'] ?? 'archivo'), $tipos, $tope);

    $idIns = Http::id(Http::campo('id_instruccion'));
    if (Db::tablaExiste('instrucciones_medios')) {
        Db::insert('instrucciones_medios', [
            'id_instruccion' => $idIns,
            'archivo' => $r['archivo'], 'url' => $r['url'], 'mime' => $r['mime'],
            'tamano' => $r['tamano'], 'ancho' => $r['ancho'], 'alto' => $r['alto'],
            'hash' => $r['hash'],
            'nombre_original' => mb_substr((string)($f['name'] ?? ''), 0, 250),
            'id_autor' => (int)$u['id_login'],
            'id_hotel' => Db::tablaExiste('hoteles') ? Hoteles::actual($u) : null,
            'creado' => Db::now(),
        ]);
    }

    Http::ok(['success' => 1, 'url' => $r['url'], 'file' => ['url' => $r['url']],
              'mime' => $r['mime'], 'tamano' => $r['tamano']], 201);
}

if ($accion === '' && $metodo === 'POST') {
    [$formato, $contenido] = $formatoYContenido((string)Http::requerido('contenido'));

    $nuevo = Db::insert('instrucciones', [
        'id_hotel'   => Hoteles::actual($u),
        'titulo'     => mb_substr((string)Http::requerido('titulo'), 0, 180),
        'resumen'    => mb_substr((string)Http::campo('resumen', ''), 0, 300) ?: null,
        'contenido'  => $contenido,
        'formato'    => $formato,
        'id_familia' => Http::id(Http::campo('id_familia')),
        'id_elemento'=> Http::id(Http::campo('id_elemento')),
        'id_zona_1'  => Http::id(Http::campo('id_zona_1')),
        'etiquetas'  => mb_substr((string)Http::campo('etiquetas', ''), 0, 300) ?: null,
        'aviso'      => mb_substr((string)Http::campo('aviso', ''), 0, 500) ?: null,
        // A quién va dirigida. Sólo valen las tres que entiende el móvil:
        // cualquier otra cosa se trata como la de fábrica.
        'visible_para' => in_array(Http::campo('visible_para'), ['todos', 'asignado', 'departamento'], true)
            ? (string)Http::campo('visible_para') : 'departamento',
        'id_autor'   => (int)$u['id_login'],
        'activo'     => 1,
        'creado'     => Db::now(),
        'updated_at' => Db::now(),
    ]);
    Http::ok(['id_instruccion' => $nuevo], 201);
}

if ($id !== null && $metodo === 'PATCH') {
    $datos = ['updated_at' => Db::now()];
    foreach (['titulo', 'resumen', 'contenido', 'etiquetas', 'aviso'] as $c) {
        if (array_key_exists($c, Http::body())) {
            $datos[$c] = (string)Http::campo($c) ?: null;
        }
    }
    foreach (['id_familia', 'id_elemento', 'id_zona_1'] as $c) {
        if (array_key_exists($c, Http::body())) { $datos[$c] = Http::id(Http::campo($c)); }
    }
    // A quién va dirigida, con las mismas tres opciones del alta.
    /* Al cambiar el contenido se recomprueba el formato: pasar del
       editor avanzado al sencillo y al revés es justo lo que se pidió, y
       si el formato no viajara con el contenido quedarían descuadrados
       —texto plano marcado como bloques— y el manual saldría vacío. */
    if (array_key_exists('contenido', Http::body())) {
        [$fmt, $cont] = $formatoYContenido((string)Http::campo('contenido'));
        $datos['contenido'] = $cont;
        if (in_array('formato', Db::columnas('instrucciones'), true)) {
            $datos['formato'] = $fmt;
        }
    }

    if (array_key_exists('visible_para', Http::body())) {
        $datos['visible_para'] = in_array(Http::campo('visible_para'),
            ['todos', 'asignado', 'departamento'], true)
            ? (string)Http::campo('visible_para') : 'departamento';
    }

    if (array_key_exists('activo', Http::body())) {
        $datos['activo'] = Http::bool(Http::campo('activo')) ? 1 : 0;
    }
    if (array_key_exists('orden', Http::body())) { $datos['orden'] = (int)Http::campo('orden'); }
    Db::update('instrucciones', $datos, 'id_instruccion = :id', ['id' => $id]);
    Http::ok(['mensaje' => 'Instrucción guardada.']);
}

if ($id !== null && $metodo === 'DELETE') {
    Db::update('instrucciones', ['activo' => 0, 'updated_at' => Db::now()],
               'id_instruccion = :id', ['id' => $id]);
    Http::ok(['mensaje' => 'Instrucción archivada.']);
}

throw new ApiError('Ruta de instrucciones no encontrada.', 404);
