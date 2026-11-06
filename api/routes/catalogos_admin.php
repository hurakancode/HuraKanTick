<?php
/**
 * Gestión de los catálogos: tipos de avería y ubicaciones.
 *
 * Faltaba poder crearlos y editarlos desde la aplicación: se cargaban del
 * volcado y ahí se quedaban. En un hotel eso no vale — se compra una
 * máquina nueva, se reforma una planta, y el catálogo tiene que seguir.
 */
declare(strict_types=1);

$u = Auth::exigir();
$seccion = $partes[0];

/* ================================================================= */
/* TIPOS DE AVERÍA                                                   */
/* ================================================================= */
if ($seccion === 'elementos') {

    if ($metodo === 'GET') {
        $q = trim((string)Http::query('q', ''));
        $fam = Http::queryInt('familia');
        $where = ['1 = 1'];
        $p = [];
        if ($q !== '') {
            // Sin acentos: «camara» tiene que encontrar «Cámara frigorífica».
            $where[] = Repo::sinAcentos('e.nombre_elemento') . ' LIKE :q ESCAPE \'!\'';
            $p['q'] = Repo::patron(Repo::planoTexto($q));
        }
        if ($fam !== null) {
            $where[] = 'e.id_familia = :fam';
            $p['fam'] = $fam;
        }
        if (!Http::bool(Http::query('incluir_baja'))) {
            $where[] = 'COALESCE(e.baja, 0) = 0';
        }

        $filas = Db::all(
            'SELECT e.*, f.nombre AS familia, f.color AS familia_color, f.icono AS familia_icono,
                    d.nombre AS departamento,
                    (SELECT COUNT(*) FROM ticket_incidencias t
                      WHERE t.id_elemento = e.id_elemento AND t.borrado = 0) AS n_partes,
                    (SELECT COUNT(*) FROM unidades un WHERE un.id_elemento = e.id_elemento) AS n_unidades
               FROM elemento e
               LEFT JOIN familias f ON f.id_familia = e.id_familia
               LEFT JOIN departamentos d ON d.id_departamento = e.id_departamento
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.nombre_elemento LIMIT 400',
            $p
        );
        foreach ($filas as &$e) {
            $e['id_elemento'] = (int)$e['id_elemento'];
            $e['id_familia'] = $e['id_familia'] === null ? null : (int)$e['id_familia'];
            $e['id_departamento'] = $e['id_departamento'] === null ? null : (int)$e['id_departamento'];
            $e['usa_serie'] = (int)($e['usa_serie'] ?? 0);
            $e['baja']      = (int)($e['baja'] ?? 0);
            $e['n_partes']  = (int)$e['n_partes'];
            $e['n_unidades']= (int)$e['n_unidades'];
            $e['usos']      = (int)($e['usos'] ?? 0);
        }
        Http::ok(['elementos' => $filas]);
    }

    /* Crear, editar o deshabilitar un tipo: sólo el administrador.
     *
     * Esto pedía «responsable». Pero el catálogo de tipos es común a toda
     * la cadena, la guía lo pone en Administración —que sólo ve el
     * administrador— y ninguna pantalla se lo ofrece a un responsable: el
     * permiso de más sólo servía a quien llamara a la API a mano. Lo
     * destapó pruebas/matriz.py, que pide cada ruta como cada papel. */
    Auth::exigirAdmin();

    if ($metodo === 'POST') {
        $nombre = trim((string)Http::requerido('nombre_elemento'));
        if ($nombre === '') {
            throw new ApiError('Falta el nombre.', 422, ['campo' => 'nombre_elemento']);
        }
        if (Db::value('SELECT 1 FROM elemento WHERE LOWER(nombre_elemento) = ?', [mb_strtolower($nombre)])) {
            throw new ApiError('Ya existe un tipo con ese nombre.', 409, ['campo' => 'nombre_elemento']);
        }
        $idFamilia = Http::id(Http::campo('id_familia'));
        $id = Db::insert('elemento', [
            'nombre_elemento'      => mb_substr($nombre, 0, 150),
            'abreviatura_elemento' => mb_substr((string)Http::campo('abreviatura_elemento', ''), 0, 20) ?: null,
            'id_familia'           => $idFamilia,
            'id_departamento'      => Http::id(Http::campo('id_departamento'))
                ?? ($idFamilia ? Http::id(Db::value('SELECT id_departamento FROM familias WHERE id_familia = ?', [$idFamilia])) : null),
            'icono'                => $idFamilia
                ? Db::value('SELECT icono FROM familias WHERE id_familia = ?', [$idFamilia])
                : 'llave-inglesa',
            'usa_serie'            => Http::bool(Http::campo('usa_serie')) ? 1 : 0,
            'baja'                 => 0,
            'usos'                 => 0,
            'updated_at'           => Db::now(),
        ]);
        Registro::anotar('ajuste', (int)$u['id_login'], 'Tipo de avería creado: ' . $nombre);
        Http::ok(['id_elemento' => $id], 201);
    }

    $id = Http::id($partes[1] ?? null);
    if ($id !== null && $metodo === 'PATCH') {
        $datos = ['updated_at' => Db::now()];

        /* El nombre acepta las dos formas.
         *
         * El listado devuelve «nombre» como alias cómodo de
         * «nombre_elemento», así que quien lee un tipo y devuelve el
         * mismo campo cambiado no acertaba: se guardaba todo menos el
         * nombre, en silencio y con un «ok» por respuesta. */
        /* El icono del tipo. Se acepta uno de los propios («gota») o uno
           de Font Awesome («fa-f553»); vacío significa «el de su familia»,
           y se copia en ese momento. Antes no se podía cambiar: los 313
           tipos heredaban el de su familia y ahí se quedaban. */
        if (array_key_exists('icono', Http::body())) {
            $ic = trim((string)Http::campo('icono'));
            if ($ic === '') {
                $fam = Http::id(Http::campo('id_familia'))
                    ?? Http::id(Db::value('SELECT id_familia FROM elemento WHERE id_elemento = ?', [$id]));
                $ic = $fam ? (string)Db::value('SELECT icono FROM familias WHERE id_familia = ?', [$fam]) : 'llave-inglesa';
            }
            if (!preg_match('/^(fa-[0-9a-f]{1,5}|[a-z-]{2,30})$/i', $ic)) {
                throw new ApiError('Ese icono no vale.', 422, ['campo' => 'icono']);
            }
            $datos['icono'] = $ic;
        }

        $claveNombre = array_key_exists('nombre_elemento', Http::body())
            ? 'nombre_elemento'
            : (array_key_exists('nombre', Http::body()) ? 'nombre' : null);

        if ($claveNombre !== null) {
            $n = trim((string)Http::campo($claveNombre));
            if ($n === '') { throw new ApiError('El nombre no puede quedar vacío.', 422); }
            if (Db::value('SELECT 1 FROM elemento WHERE LOWER(nombre_elemento) = ? AND id_elemento <> ?',
                          [mb_strtolower($n), $id])) {
                throw new ApiError('Ya existe otro tipo con ese nombre.', 409, ['campo' => 'nombre_elemento']);
            }
            $datos['nombre_elemento'] = mb_substr($n, 0, 150);
        }
        if (array_key_exists('abreviatura_elemento', Http::body())) {
            $datos['abreviatura_elemento'] = mb_substr((string)Http::campo('abreviatura_elemento'), 0, 20) ?: null;
        }
        foreach (['id_familia', 'id_departamento'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = Http::id(Http::campo($c)); }
        }
        foreach (['usa_serie', 'baja'] as $c) {
            if (array_key_exists($c, Http::body())) { $datos[$c] = Http::bool(Http::campo($c)) ? 1 : 0; }
        }
        /* Al cambiar de familia se hereda su icono y su grupo… salvo que
           en esta misma llamada se haya elegido un icono a propósito.
           La pantalla manda siempre la familia, así que esta herencia
           pisaba el icono recién elegido y el cambio no se guardaba
           nunca: el servidor contestaba «guardado» y el tipo seguía con
           el de su familia. */
        if (isset($datos['id_familia']) && $datos['id_familia']) {
            $f = Db::one('SELECT icono, id_departamento FROM familias WHERE id_familia = ?', [$datos['id_familia']]);
            if ($f) {
                if (!array_key_exists('icono', Http::body()) || trim((string)Http::campo('icono')) === '') {
                    $datos['icono'] = $f['icono'];
                }
                if (!array_key_exists('id_departamento', Http::body())) {
                    $datos['id_departamento'] = $f['id_departamento'] === null ? null : (int)$f['id_departamento'];
                }
            }
        }
        /* Poner departamento a un tipo es marcarlo como excepción: a partir de
   ahí no le afectan los cambios de su familia. Si se deja igual que el de
   su familia —o se quita—, vuelve a seguirla. */
        if (array_key_exists('id_departamento', Http::body())
            && in_array('dep_propio', Db::columnas('elemento'), true)) {
            $famDe = Http::id(Db::value(
                'SELECT f.id_departamento FROM elemento e
                   LEFT JOIN familias f ON f.id_familia = e.id_familia
                  WHERE e.id_elemento = ?', [$id]));
            $puesto = Http::id(Http::campo('id_departamento'));
            $datos['dep_propio'] = ($puesto !== null && $puesto !== $famDe) ? 1 : 0;
        }
        Db::update('elemento', $datos, 'id_elemento = :id', ['id' => $id]);
        Http::ok(['mensaje' => 'Tipo actualizado.']);
    }

    if ($id !== null && $metodo === 'DELETE') {
        $n = (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias WHERE id_elemento = ? AND borrado = 0', [$id]);
        $forzar = Http::bool(Http::query('forzar'));

        if ($n > 0 && !$forzar) {
            // Con partes detrás no se borra: se deshabilita. Borrarlo
            // dejaría el historial hablando de un tipo que ya no existe.
            Db::update('elemento', ['baja' => 1, 'updated_at' => Db::now()], 'id_elemento = :id', ['id' => $id]);
            Http::ok([
                'mensaje' => "Ese tipo tiene $n partes: se ha deshabilitado en vez de borrarlo, "
                           . 'para que el historial siga teniendo sentido.',
                'deshabilitado' => true,
            ]);
        }

        Auth::exigirAdmin();
        Db::run('DELETE FROM elemento WHERE id_elemento = ?', [$id]);
        Registro::anotar('borrado', (int)$u['id_login'], 'Tipo de avería eliminado (#' . $id . ')');
        Http::ok(['mensaje' => 'Tipo eliminado.', 'deshabilitado' => false]);
    }
}

/* ================================================================= */
/* UBICACIONES                                                       */
/* ================================================================= */
if ($seccion === 'ubicaciones-admin') {
    /* Montar ubicaciones en bloque: sólo el administrador, como dice la
       guía («Montar las ubicaciones») y como lo ofrece la aplicación.
       Pedía «responsable», sin pantalla que lo usara. Una ubicación suelta
       desde un parte sigue su propio ajuste (quien_crea_zona). */
    Auth::exigirAdmin();

    /* GET — los niveles y cuántas combinaciones hay */
    if ($metodo === 'GET' && ($partes[1] ?? '') === '') {
        $config = require __DIR__ . '/../config.php';
        $niveles = $config['app']['niveles_zona'];
        $out = [];
        for ($n = 1; $n <= 4; $n++) {
            $filas = Db::all("SELECT id_zona_$n AS id, nombre_zona_$n AS nombre FROM zona_$n ORDER BY nombre_zona_$n");
            foreach ($filas as &$f) {
                $f['id'] = (int)$f['id'];
                $f['usos'] = (int)Db::value("SELECT COUNT(*) FROM rel_zonas WHERE id_zona_$n = ?", [$f['id']]);
            }
            $out['zona' . $n] = $filas;
        }
        Http::ok([
            'niveles'       => $niveles,
            'zonas'         => $out,
            'combinaciones' => (int)Db::value('SELECT COUNT(*) FROM rel_zonas'),
        ]);
    }

    /* POST /api/ubicaciones-admin/rango — habitaciones de golpe
     *
     * Dar de alta una planta a mano son treinta habitaciones por cuatro
     * subLugares: ciento veinte toques para algo que se sabe de
     * antemano. Aquí se dice «de la 101 a la 130, con baño, dormitorio y
     * terraza» y se crea todo.
     *
     * Es la parte propia de cada hotel: los números de habitación no se
     * comparten con nadie. */
    if ($metodo === 'POST' && ($partes[1] ?? '') === 'rango') {
        $u = Auth::exigirAdmin();
        $hotel = Hoteles::actual();

        $idPlanta = Http::id(Http::requerido('id_zona_1'));
        $idZona   = Http::id(Http::campo('id_zona_2'));
        $desde    = (int)Http::requerido('desde');
        $hasta    = (int)Http::requerido('hasta');
        /* El prefijo conserva sus espacios: quien escribe «Bungalow »
           quiere «Bungalow 001», no «Bungalow001». Sólo se recorta lo
           que sobra por fuera del campo, no lo que separa las palabras. */
        $prefijo  = rtrim(ltrim((string)(Http::campo('prefijo') ?? ''), "\t\n\r\0"), "\t\n\r\0");
        $sufijo   = rtrim(ltrim((string)(Http::campo('sufijo') ?? ''), "\t\n\r\0"), "\t\n\r\0");
        $paso     = max(1, (int)(Http::campo('paso') ?? 1));
        $relleno  = (int)(Http::campo('relleno') ?? 0);   // 101 vs 0101
        $subs     = Http::campo('sublugares');            // ids de zona_4
        $saltar   = Http::campo('saltar');                // números a no crear

        if ($hasta < $desde) {
            throw new ApiError('El número final es menor que el inicial.', 422, ['campo' => 'hasta']);
        }
        $cuantas = intdiv($hasta - $desde, $paso) + 1;
        if ($cuantas > 500) {
            throw new ApiError('Son ' . $cuantas . ' habitaciones de una vez. '
                             . 'Hazlo por plantas para no dejar la base a medias si algo falla.',
                             422, ['campo' => 'hasta']);
        }

        $planta = Db::one('SELECT * FROM zona_1 WHERE id_zona_1 = ?', [$idPlanta]);
        if ($planta === null) {
            throw new ApiError('Esa planta no existe.', 404, ['campo' => 'id_zona_1']);
        }
        if ($hotel !== null && $planta['id_hotel'] !== null && (int)$planta['id_hotel'] !== $hotel) {
            throw new ApiError('Esa planta es de otro hotel.', 403);
        }

        $saltados = [];
        if (is_array($saltar)) {
            foreach ($saltar as $x) { $saltados[(string)(int)$x] = true; }
        }
        $listaSubs = is_array($subs) ? array_values(array_filter(array_map('intval', $subs))) : [];

        $creadas = 0;
        $repetidas = 0;
        $combinaciones = 0;
        $nombres = [];

        Db::begin();
        try {
            for ($i = $desde; $i <= $hasta; $i += $paso) {
                if (isset($saltados[(string)$i])) { continue; }

                $numero = $relleno > 0 ? str_pad((string)$i, $relleno, '0', STR_PAD_LEFT) : (string)$i;
                $nombre = $prefijo . $numero . $sufijo;

                // Si ya existe en este hotel se reutiliza, no se duplica.
                $idLugar = Db::value(
                    'SELECT id_zona_3 FROM zona_3
                      WHERE LOWER(nombre_zona_3) = ? AND (id_hotel = ? OR (id_hotel IS NULL AND ? IS NULL))',
                    [mb_strtolower($nombre), $hotel, $hotel]);

                if ($idLugar === null) {
                    $idLugar = Db::insert('zona_3', [
                        'nombre_zona_3' => $nombre,
                        'orden'         => $i,
                        'id_hotel'      => $hotel,
                    ]);
                    $creadas++;
                    $nombres[] = $nombre;
                } else {
                    $repetidas++;
                }

                /* Y sus partes. Sin subLugares se crea la habitación
                   entera como una sola ubicación, que es lo que quiere
                   un hotel pequeño. */
                $paraCombinar = $listaSubs ?: [null];
                foreach ($paraCombinar as $idSub) {
                    $ya = Db::value(
                        'SELECT id_rel FROM rel_zonas
                          WHERE id_zona_1 = ? AND id_zona_3 = ?
                            AND ((id_zona_2 IS NULL AND ? IS NULL) OR id_zona_2 = ?)
                            AND ((id_zona_4 IS NULL AND ? IS NULL) OR id_zona_4 = ?)
                            AND (id_hotel = ? OR (id_hotel IS NULL AND ? IS NULL))',
                        [$idPlanta, $idLugar, $idZona, $idZona, $idSub, $idSub, $hotel, $hotel]);
                    if ($ya !== null) { continue; }

                    Db::insert('rel_zonas', [
                        'id_zona_1'         => $idPlanta,
                        'id_zona_2'         => $idZona,
                        'id_zona_3'         => (int)$idLugar,
                        'id_zona_4'         => $idSub,
                        'id_realiza_accion' => (int)$u['id_login'],
                        'id_hotel'          => $hotel,
                        'updated_at'        => Db::now(),
                    ]);
                    $combinaciones++;
                }
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw new ApiError('No se ha podido crear: ' . $e->getMessage(), 500);
        }

        // Las rutas legibles se recalculan de una vez al final.
        if (class_exists('Repo') && method_exists('Repo', 'recalcularRutas')) {
            Repo::recalcularRutas();
        } else {
            Db::run(
                "UPDATE rel_zonas SET ruta = TRIM(
                    COALESCE((SELECT nombre_zona_1 FROM zona_1 WHERE zona_1.id_zona_1 = rel_zonas.id_zona_1), '')
                    || COALESCE(' > ' || (SELECT nombre_zona_2 FROM zona_2 WHERE zona_2.id_zona_2 = rel_zonas.id_zona_2), '')
                    || COALESCE(' > ' || (SELECT nombre_zona_3 FROM zona_3 WHERE zona_3.id_zona_3 = rel_zonas.id_zona_3), '')
                    || COALESCE(' > ' || (SELECT nombre_zona_4 FROM zona_4 WHERE zona_4.id_zona_4 = rel_zonas.id_zona_4), '')
                 ) WHERE ruta IS NULL OR ruta = ''");
        }

        Registro::anotar('ajuste', (int)$u['id_login'],
            "Rango de ubicaciones: $creadas nuevas en «{$planta['nombre_zona_1']}»");

        Http::ok([
            'creadas'       => $creadas,
            'repetidas'     => $repetidas,
            'combinaciones' => $combinaciones,
            'ejemplos'      => array_slice($nombres, 0, 5),
            'mensaje'       => $creadas . ' ubicaciones nuevas'
                             . ($repetidas ? " ($repetidas ya estaban)" : '')
                             . ' · ' . $combinaciones . ' sitios donde dar un parte',
        ], 201);
    }

    /* POST /api/ubicaciones-admin/nivel — crear un valor de un nivel */
    if ($metodo === 'POST' && ($partes[1] ?? '') === 'nivel') {
        $n = max(1, min(4, (int)Http::requerido('nivel')));
        $nombre = trim((string)Http::requerido('nombre'));
        if ($nombre === '') {
            throw new ApiError('Falta el nombre.', 422, ['campo' => 'nombre']);
        }
        /* Las ubicaciones son de cada hotel: la planta 3 de uno no tiene
           que ver con la del otro. Sin filtrar por hotel, crear una
           planta que ya existía en otro sitio devolvía la ajena, y las
           habitaciones acababan colgando del hotel equivocado. */
        $hotel = Hoteles::actual();
        $ya = Db::value(
            "SELECT id_zona_$n FROM zona_$n
              WHERE LOWER(nombre_zona_$n) = ? AND (id_hotel = ? OR (id_hotel IS NULL AND ? IS NULL))",
            [mb_strtolower($nombre), $hotel, $hotel]);
        if ($ya !== null) {
            Http::ok(['id' => (int)$ya, 'ya_existia' => true]);
        }
        $id = Db::insert("zona_$n", [
            "nombre_zona_$n" => mb_substr($nombre, 0, 150),
            'id_hotel'       => $hotel,
        ]);
        Http::ok(['id' => $id, 'ya_existia' => false], 201);
    }

    /* PATCH /api/ubicaciones-admin/nivel — renombrar */
    if ($metodo === 'PATCH' && ($partes[1] ?? '') === 'nivel') {
        $n = max(1, min(4, (int)Http::requerido('nivel')));
        $id = Http::id(Http::requerido('id'));
        $nombre = trim((string)Http::requerido('nombre'));
        Db::run("UPDATE zona_$n SET nombre_zona_$n = ? WHERE id_zona_$n = ?", [mb_substr($nombre, 0, 150), $id]);
        // La ruta legible se recalcula: es lo que se busca en el selector
        Repo::recalcularRutas();
        Http::ok(['mensaje' => 'Renombrado.']);
    }

    /* DELETE /api/ubicaciones-admin/nivel?nivel=N&id=M */
    if ($metodo === 'DELETE' && ($partes[1] ?? '') === 'nivel') {
        $n = max(1, min(4, (int)Http::query('nivel', '1')));
        $id = Http::id(Http::query('id'));
        $usos = (int)Db::value("SELECT COUNT(*) FROM rel_zonas WHERE id_zona_$n = ?", [$id]);
        if ($usos > 0) {
            throw new ApiError("Se usa en $usos ubicaciones. Bórralas antes o renómbralo.", 409);
        }
        Db::run("DELETE FROM zona_$n WHERE id_zona_$n = ?", [$id]);
        Http::ok(['mensaje' => 'Eliminado.']);
    }

    /* DELETE /api/ubicaciones-admin/{id_rel} — quitar una combinación */
    $idRel = Http::id($partes[1] ?? null);
    if ($idRel !== null && $metodo === 'DELETE') {
        $n = (int)Db::value('SELECT COUNT(*) FROM ticket_incidencias WHERE id_rel_zona = ? AND borrado = 0', [$idRel]);
        if ($n > 0) {
            throw new ApiError("Esa ubicación tiene $n partes. No se puede borrar sin perder su historial.", 409);
        }
        Db::run('DELETE FROM rel_zonas WHERE id_rel = ?', [$idRel]);
        Http::ok(['mensaje' => 'Ubicación eliminada.']);
    }
}

throw new ApiError('Ruta no encontrada.', 404);
