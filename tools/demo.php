<?php

/** Datos de ejemplo: un hotel pequeño con partes repartidos. */
declare(strict_types=1);

/* Sólo se usa incluido desde el instalador; abierto directamente, nada. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit("Este fichero no se abre directamente.\n");
}

/**
 * La gente de la demostración: correo, nombre, apellidos, rol y
 * departamento (1 Servicios Técnicos · 2 Pisos · 3 Recepción · 5 Informática).
 *
 * Es una función y no una lista dentro de sembrarDemo() porque
 * tools/equipo_benalma.php necesita saber quién es de ejemplo para
 * retirarlo. Antes lo adivinaba por el dominio —«@sstt.local»—, y desde
 * que la demo trae correos del hotel ya no encontraba a nadie: dejaba
 * «Test 1», «Test 2» y la recepción de ejemplo activos junto al equipo
 * de verdad, con la contraseña del manual.
 */
function personasDemo(): array
{
    return [
        ['carlos.duarte@hotelbenalma.com',   'Carlos',    'Duarte',   '',  'responsable', 1],
        ['david.sstt@hotelbenalma.com',  'David',   '', '',    'tecnico',     1],
        ['fany.prieto@hotelbenalma.com', 'Fany',  'Prieto',  '',    'tecnico',     2],
        ['test1@hotelbenalma.com',  'Test',   '1',  '',    'tecnico',     5],
        ['recepcion.costadelsol@hotelbenalma.com', 'Recepcion',  'Benalma',  '',   'reportador',  3],
        ['test2@hotelbenalma.com',  'Test',   '2',    '',   'responsable', 2],
    ];
}

/** Los correos de ejemplo de antes de la 7.90, por si quedan en una base vieja. */
function correosDemoAntiguos(): array
{
    return ['ana@sstt.local', 'luis@sstt.local', 'marta@sstt.local',
            'jose@sstt.local', 'pilar@sstt.local', 'rosa@sstt.local',
            'admin@sstt.local'];
}

function sembrarDemo(callable $log): void
{
    if ((int)Db::value('SELECT COUNT(*) FROM ticket_incidencias') > 0) {
        $log('· Ya hay partes en la base de datos: no se generan datos de ejemplo.');
        return;
    }

    // ---- Personas -----------------------------------------------------
    // Los tres grupos de un hotel, que es donde se usa esto:
    // 1 Servicios Técnicos · 2 Pisos/Limpieza · 3 Recepción · 5 Informática
    $personas = personasDemo();
    $claveDemo = require __DIR__ . '/clave_demo.php';
    $ids = [];
    foreach ($personas as [$correo, $n, $a1, $a2, $rol, $dep]) {
        $existe = Db::value('SELECT id_login FROM login WHERE correo = ?', [$correo]);
        if ($existe) { $ids[] = (int)$existe; continue; }
        $ids[] = Db::insert('login', [
            'correo' => $correo, 'pwd_php' => Auth::hashPassword($claveDemo),
            'nombre' => $n, 'apellido1' => $a1, 'apellido2' => $a2,
            'rol' => $rol, 'admin' => 0, 'bloqueo' => 0, 'intentos' => 0,
            'id_departamento' => $dep, 'fecha_alta' => Db::now(), 'updated_at' => Db::now(),
        ]);
    }
    $log('· ' . count($ids) . ' personas de ejemplo (contraseña: ' . $claveDemo . ').');

    // ---- Ubicaciones reales -------------------------------------------
    // No se inventan zonas: se usan las del hotel cargadas por el instalador.
    $ubicaciones = Db::all('SELECT id_rel FROM rel_zonas ORDER BY id_rel');
    $ubicaciones = array_column($ubicaciones, 'id_rel');
    if (!$ubicaciones) {
        $log('· No hay ubicaciones cargadas: los partes de ejemplo saldrán sin zona.');
    } else {
        $log('· ' . count($ubicaciones) . ' ubicaciones reales disponibles.');
    }

    // Elementos frecuentes de un hotel, buscados por nombre en el catálogo real.
    $buscarElemento = static function (string $texto): ?int {
        $id = Db::value('SELECT id_elemento FROM elemento WHERE LOWER(nombre_elemento) LIKE ? ORDER BY id_elemento LIMIT 1',
            ['%' . mb_strtolower($texto) . '%']);
        return $id === null ? null : (int)$id;
    };

    // ---- Partes -------------------------------------------------------
    $plantillas = [
        ['aire acondicionado', 'No enfría el aire acondicionado', 'El equipo arranca pero sale aire caliente. El cliente se ha quejado esta mañana.', 3],
        ['lavabo',       'Goteo en el lavabo',            'Pierde agua por el sifón. Hay una toalla puesta debajo.', 2],
        ['enchufe',      'Enchufe sin corriente',         'La toma junto a la mesilla derecha no da tensión.', 2],
        ['revisar tv',      'La televisión no sintoniza',    'Se ve la señal pero faltan canales de pago.', 1],
        ['cerradura',    'Cerradura floja',               'La tarjeta abre a la tercera. Conviene revisar el pomo.', 3],
        ['wifi',         'Wi-Fi intermitente',            'Cae la conexión cada pocos minutos en toda el ala este.', 3],
        ['armario',      'Puerta del armario descolgada', 'Una bisagra suelta, roza contra el marco.', 1],
        ['silla',        'Silla de escritorio rota',      'Pistón de altura bloqueado, no sube.', 1],
        ['moqueta',      'Mancha en la moqueta',          'Zona de paso frente al ascensor.', 1],
        ['cisterna',     'Cisterna con pérdida continua', 'Se oye correr el agua todo el rato.', 3],
        ['fluorescente', 'Fluorescente parpadeando',      'Pasillo de servicio, molesta bastante.', 2],
        ['ascensor',     'Ruido al frenar el ascensor',   'Chirrido metálico al detenerse en planta baja.', 4],
    ];

    /* Se resuelven una sola vez contra el catálogo real.
     *
     * Si alguna palabra no casa con ningún tipo —el catálogo cambia según
     * el paquete instalado: «televis» no está en el del hotel— se usa
     * otro tipo cualquiera. Antes se quedaba en nulo y el parte nacía sin
     * tipo; desde que ese campo es obligatorio, eso tiraba la instalación
     * entera con un «Indica qué falla» en mitad del sembrado. */
    $deRepuesto = Http::id(Db::value('SELECT id_elemento FROM elemento ORDER BY id_elemento LIMIT 1'));
    $sinCasar = 0;
    foreach ($plantillas as $i => $pl) {
        $id = $buscarElemento($pl[0]);
        if ($id === null) { $id = $deRepuesto; $sinCasar++; }
        $plantillas[$i][0] = $id;
    }
    if ($sinCasar > 0) {
        $log('· ' . $sinCasar . ' ejemplo(s) sin tipo equivalente en este catálogo.');
    }
    if ($deRepuesto === null) {
        $log('· Catálogo vacío: no se siembran partes de ejemplo.');
        return;
    }

    // Filas completas: Tickets::actualizar consulta el rol para los permisos.
    $usuarios = [];
    foreach ($ids as $idl) {
        $usuarios[$idl] = Db::one('SELECT * FROM login WHERE id_login = ?', [$idl]);
    }
    $responsable = null;
    foreach ($usuarios as $us) {
        if ($us['rol'] === 'responsable') { $responsable = $us; break; }
    }
    $responsable = $responsable ?? reset($usuarios);
    $creados = 0;

    /* A qué departamento va cada avería.
       Se reparte por el oficio que toca, no al azar: así el reparto de
       la demo se parece al de un hotel y las pruebas por departamento
       tienen sentido. */
    $grupoDe = static function (?int $idElemento): int {
        if ($idElemento === null) { return 1; }

        /* Se mira el nombre del propio tipo de avería, no su familia.
           La demo se genera antes de que clasificar.php agrupe los
           elementos por oficio, así que en este momento la familia
           todavía está vacía y todo caía en Servicios Técnicos. */
        $fam = mb_strtolower((string)Db::value(
            'SELECT COALESCE(f.nombre, e.nombre_elemento) FROM elemento e
               LEFT JOIN familias f ON f.id_familia = e.id_familia
              WHERE e.id_elemento = ?', [$idElemento]));
        foreach ([
            2 => ['limpieza', 'lencería', 'toalla', 'sábana', 'almohada', 'cortina', 'moqueta'],
            4 => ['cocina', 'office', 'horno', 'cámara frigor', 'lavavajillas', 'campana'],
            5 => ['informát', 'red', 'wifi', 'televis', 'tv', 'ordenador', 'impresora', 'telefon'],
            6 => ['jardín', 'jardin', 'riego', 'piscina', 'exterior', 'césped'],
        ] as $grupo => $palabras) {
            foreach ($palabras as $x) {
                if (str_contains($fam, $x)) { return $grupo; }
            }
        }
        return 1;                                   // Servicios Técnicos
    };

    for ($i = 0; $i < 24; $i++) {
        $p = $plantillas[$i % count($plantillas)];
        $autor = $usuarios[$ids[array_rand($ids)]];

        $diasAtras = random_int(0, 21);
        $fecha = gmdate('Y-m-d H:i:s', time() - $diasAtras * 86400 - random_int(0, 80000));

        /* El tipo de avería: la plantilla lo nombra en palabras y aquí se
           busca su número en el catálogo real. Antes se mandaba el texto
           tal cual, así que los partes de ejemplo nacían sin tipo. No se
           notaba porque el campo no era obligatorio; al serlo, la
           instalación con demo dejó de funcionar. */
        $idElemento = Http::id($p[0]);

        $t = Tickets::crear([
            'uuid'         => Http::uuid(),
            'titulo'       => $p[1],
            'detalle'      => $p[2],
            'id_elemento'  => $idElemento,
            'id_prioridad' => $p[3],
            'id_rel_zona'  => $ubicaciones ? $ubicaciones[array_rand($ubicaciones)] : null,
            /* El departamento va siempre.
               Es obligatorio al dar un parte, así que una demo sin él
               deja 27 de 28 partes en un estado que la aplicación ya no
               permite crear: al probar «asignar persona» no sale nadie y
               parece roto cuando el problema son los datos. */
            'id_grupo_asignado' => $grupoDe($idElemento),
            'fecha_creado' => $fecha,
            'origen'       => random_int(0, 4) === 0 ? 'offline' : 'app',
        ], $autor);
        $creados++;

        // Avance del parte según su antigüedad
        $avance = random_int(0, 100);
        if ($diasAtras > 1 && $avance > 25) {
            Tickets::actualizar((int)$t['id_ticket'], [
                'id_persona_asignada' => $ids[array_rand($ids)],
                'id_estado' => 2,
                'nota' => 'Recibido, lo veo esta tarde.',
                'fecha' => gmdate('Y-m-d H:i:s', strtotime($fecha) + 3600),
            ], $responsable);
        }
        if ($diasAtras > 3 && $avance > 55) {
            Tickets::actualizar((int)$t['id_ticket'], [
                'id_estado' => 3,
                'nota' => 'Empiezo la revisión. Necesito repuesto del almacén.',
                'minutos' => random_int(15, 60),
                'fecha' => gmdate('Y-m-d H:i:s', strtotime($fecha) + 7200),
            ], $responsable);
        }
        if ($diasAtras > 5 && $avance > 70) {
            Tickets::actualizar((int)$t['id_ticket'], [
                'id_estado' => 5,
                'nota' => 'Reparado y comprobado. Queda en servicio.',
                'minutos' => random_int(20, 120),
                'fecha' => gmdate('Y-m-d H:i:s', strtotime($fecha) + 86400),
            ], $responsable);
        }
    }

    $log("· $creados partes de ejemplo generados.");

    /* ---- Mantenimiento programado ------------------------------------
       Los de un hotel de verdad: algunos son obligatorios por normativa
       y su historial es lo que se enseña en una inspección. */
    if (Db::tablaExiste('mantenimientos')
        && (int)Db::value('SELECT COUNT(*) FROM mantenimientos') === 0) {

        $fam = static fn(string $t) => Http::id(Db::value(
            'SELECT id_familia FROM familias WHERE LOWER(nombre) LIKE ?', ['%' . mb_strtolower($t) . '%']));

        $plan = [
            ['Revisión de extintores y BIE', 'Comprobar precintos, presión y accesibilidad. Registrar el número de cada equipo.',
             $fam('seguridad'), 6, 'meses', 1, 7, 3],
            ['Control de legionela en ACS', 'Medición de temperatura en puntos terminales y registro de resultados.',
             $fam('fontanería'), 1, 'meses', 1, 1, 4],
            ['Limpieza de filtros de climatización', 'Filtros de fancoils y unidades de zonas comunes.',
             $fam('climatización'), 3, 'meses', 0, 1, 2],
            ['Inspección periódica del ascensor', 'Acompañar a la empresa mantenedora y archivar el acta.',
             $fam('zonas comunes'), 12, 'meses', 1, 1, 3],
            ['Revisión de alumbrado de emergencia', 'Comprobar autonomía y señalización de recorridos.',
             $fam('seguridad'), 6, 'meses', 1, 7, 3],
            ['Limpieza de campana y conductos de cocina', 'Desengrasado de campana, filtros y conducto de extracción.',
             $fam('cocina'), 6, 'meses', 1, 4, 3],
            ['Tratamiento del agua de piscina', 'Analítica completa y ajuste de dosificación.',
             $fam('zonas comunes'), 1, 'semanas', 1, 1, 3],
            ['Revisión de cerraduras electrónicas', 'Cambio de pilas y prueba de apertura en habitaciones.',
             $fam('cerrajería'), 6, 'meses', 0, 1, 2],
        ];

        $n = 0;
        foreach ($plan as [$nombre, $desc, $idFam, $intervalo, $unidad, $oblig, $dep, $prio]) {
            // Repartidos por el calendario para que no venzan todos a la vez
            $dias = random_int(-10, 80);
            Db::insert('mantenimientos', [
                'nombre'      => $nombre,
                'descripcion' => $desc,
                'id_familia'  => $idFam,
                'intervalo'   => $intervalo,
                'unidad'      => $unidad,
                'proximo'     => gmdate('Y-m-d', time() + $dias * 86400),
                'aviso_dias'  => $unidad === 'semanas' ? 1 : 7,
                'id_departamento' => $dep,
                'id_prioridad'=> $prio,
                'obligatorio' => $oblig,
                'activo'      => 1,
                'veces'       => 0,
                'creado'      => Db::now(),
                'updated_at'  => Db::now(),
            ]);
            $n++;
        }
        $log("· $n mantenimientos programados de ejemplo.");
    }

    /* ---- Instrucciones -----------------------------------------------
       Lo que sabe el técnico veterano, escrito. Son ejemplos: sirven
       para ver la forma, no para seguirlos en una instalación real. */
    if (Db::tablaExiste('instrucciones')
        && (int)Db::value('SELECT COUNT(*) FROM instrucciones') === 0) {

        $fam = static fn(string $t) => Http::id(Db::value(
            'SELECT id_familia FROM familias WHERE LOWER(nombre) LIKE ?', ['%' . mb_strtolower($t) . '%']));

        $textos = [
            [
                'Purgar el circuito de climatización',
                'Cuando un fancoil no enfría y el filtro está limpio, casi siempre es aire en el circuito.',
                "1. Cierra la llave de impulsión del fancoil.\n"
                . "2. Coloca un cubo debajo del purgador: sale agua sucia.\n"
                . "3. Abre el purgador con la llave cuadrada de 5 mm hasta que deje de silbar.\n"
                . "4. Cierra en cuanto salga agua limpia sin burbujas.\n"
                . "5. Vuelve a abrir la llave de impulsión y comprueba la presión del circuito:\n"
                . "   debe quedar entre 1,2 y 1,8 bar.\n"
                . "6. Si la presión baja sola en dos días, hay fuga: no la persigas purgando.",
                $fam('climat'), 'purgar, fancoil, aire, no enfría, presión',
                'No purgues con la bomba en marcha: puedes meter más aire del que sacas.',
            ],
            [
                'Arranque de la cocina por la mañana',
                'El orden importa: encender la campana antes que los fuegos evita que salte la alarma.',
                "1. Campana extractora primero, siempre. Espera a que arranque el motor.\n"
                . "2. Abre la llave general de gas y comprueba que el manómetro sube.\n"
                . "3. Enciende los fuegos de uno en uno, esperando a que prenda cada piloto.\n"
                . "4. Hornos y planchas los últimos: son los que más corriente piden al arrancar.\n"
                . "5. Si salta el diferencial, es casi siempre la plancha: déjala para el final\n"
                . "   y avisa a Servicios Técnicos.",
                $fam('cocina'), 'cocina, arranque, gas, campana, diferencial',
                'Si huele a gas, NO enciendas nada ni toques interruptores. Corta la llave general y avisa.',
            ],
            [
                'Cambiar una cerradura electrónica de habitación',
                'Las pilas duran unos dos años. Cambiarlas antes de que fallen evita clientes en el pasillo.',
                "1. Pide la llave mecánica de emergencia en recepción: está en el sobre precintado.\n"
                . "2. Retira el embellecedor interior con el destornillador plano fino.\n"
                . "3. Cuatro pilas AA alcalinas. No mezcles marcas ni pilas usadas con nuevas.\n"
                . "4. Tras cambiarlas, la cerradura pita dos veces y el led parpadea verde.\n"
                . "5. Comprueba con la tarjeta maestra ANTES de cerrar la puerta.\n"
                . "6. Si sigue sin abrir, reprograma con el PDA desde recepción.",
                $fam('cerrajer'), 'cerradura, pilas, onity, tarjeta, habitación',
                'Nunca cierres la puerta sin haber probado la tarjeta: te quedas fuera y hay que taladrar.',
            ],
            [
                'Analítica del agua de la piscina',
                'Se hace dos veces al día en temporada. Los valores fuera de rango hay que corregirlos en el momento.',
                "Valores correctos:\n"
                . "  · Cloro libre: 0,5 a 2,0 mg/l\n"
                . "  · pH: 7,2 a 7,6\n"
                . "  · Turbidez: menos de 5 UNF\n\n"
                . "1. Toma la muestra a 30 cm de profundidad, lejos de los impulsores.\n"
                . "2. Mide primero el pH: si está fuera, el cloro no actúa aunque el valor sea correcto.\n"
                . "3. Corrige el pH antes que el cloro. Siempre en ese orden.\n"
                . "4. Anota los valores en el parte, aunque estén bien: es lo que pide sanidad.",
                $fam('zonas comunes'), 'piscina, cloro, ph, analitica, sanidad',
                'Nunca mezcles cloro y reductor de pH en el mismo recipiente: la reacción libera gas tóxico.',
            ],
            [
                'Qué hacer si un cliente se queja de ruido en el aire',
                'La mayoría de las quejas de ruido no son avería: son el filtro o la velocidad del ventilador.',
                "1. Pregunta si el ruido es constante o intermitente. El intermitente suele ser dilatación.\n"
                . "2. Comprueba la velocidad del ventilador en el termostato: en máxima, muchos equipos vibran.\n"
                . "3. Mira el filtro. Sucio, el motor trabaja forzado y suena.\n"
                . "4. Si el ruido es metálico y continuo, es el rodamiento: eso sí es avería y hay que\n"
                . "   cambiar la habitación al cliente mientras se repara.\n"
                . "5. Anota siempre qué habitación y a qué hora: si se repite, hay patrón.",
                $fam('climat'), 'ruido, aire, cliente, queja, ventilador',
                null,
            ],
        ];

        $autor = (int)Db::value('SELECT MIN(id_login) FROM login WHERE admin = 1');
        $ni = 0;
        foreach ($textos as [$titulo, $resumen, $contenido, $idFam, $etiquetas, $aviso]) {
            Db::insert('instrucciones', [
                'titulo'     => $titulo,
                'resumen'    => $resumen,
                'contenido'  => $contenido,
                'id_familia' => $idFam,
                'etiquetas'  => $etiquetas,
                'aviso'      => $aviso,
                'id_autor'   => $autor,
                'vistas'     => random_int(0, 40),
                'activo'     => 1,
                'creado'     => Db::now(),
                'updated_at' => Db::now(),
            ]);
            $ni++;
        }
        $log("· $ni instrucciones de ejemplo.");
    }

    /* ---- Unidades con número de serie ---------------------------------
       Se siembra el caso que se quiere poder consultar: una habitación
       que ha ido rompiendo televisores. */
    if (Db::tablaExiste('unidades')
        && (int)Db::value('SELECT COUNT(*) FROM unidades') === 0) {

        require_once __DIR__ . '/../api/lib/Unidades.php';

        // El televisor en sí, no el mando ni la pared donde cuelga
        $idTv = Http::id(Db::value(
            "SELECT id_elemento FROM elemento
              WHERE LOWER(nombre_elemento) IN ('tv', 'televisión', 'television')
              ORDER BY id_elemento LIMIT 1"));
        $idAa = Http::id(Db::value(
            "SELECT id_elemento FROM elemento WHERE LOWER(nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1"));
        $idCaja = Http::id(Db::value(
            "SELECT id_elemento FROM elemento WHERE LOWER(nombre_elemento) LIKE '%caja fuerte%' LIMIT 1"));

        // Una habitación concreta, con su baño y su terraza
        $hab = Db::one(
            "SELECT r.id_rel, z3.nombre_zona_3 AS lugar
               FROM rel_zonas r JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
              WHERE z3.nombre_zona_3 = '1008' LIMIT 1");
        if ($hab === null) {
            $hab = Db::one(
                "SELECT r.id_rel, z3.nombre_zona_3 AS lugar
                   FROM rel_zonas r JOIN zona_3 z3 ON z3.id_zona_3 = r.id_zona_3
                  WHERE z3.nombre_zona_3 GLOB '[0-9]*' LIMIT 1");
        }

        $nu = 0;
        if ($hab !== null && $idTv !== null) {
            $idRel = (int)$hab['id_rel'];
            $admin = (int)Db::value('SELECT MIN(id_login) FROM login WHERE admin = 1');

            // Cinco televisores en la misma habitación: cuatro de baja
            $anterior = null;
            $historia = [
                ['SNTV-2019-4471', 'Samsung', 'UE43RU7105', '2019-03-14', 'rotura'],
                ['SNTV-2021-0938', 'Samsung', 'UE43AU7105', '2021-06-02', 'rotura'],
                ['SNTV-2022-7742', 'LG',      '43UQ75006LF','2022-11-20', 'rotura'],
                ['SNTV-2024-1156', 'LG',      '43UR78006LK','2024-02-08', 'obsoleto'],
                ['SNTV-2026-3390', 'Samsung', 'UE43DU7105', '2026-04-19', null],
            ];
            foreach ($historia as [$serie, $marca, $modelo, $alta, $motivo]) {
                $id = Db::insert('unidades', [
                    'numero_serie' => $serie,
                    'id_elemento'  => $idTv,
                    'id_rel_zona'  => $idRel,
                    'estado'       => $motivo === null ? 'activa' : 'baja',
                    'motivo_baja'  => $motivo,
                    'marca'        => $marca,
                    'modelo'       => $modelo,
                    'fecha_alta'   => $alta,
                    'fecha_baja'   => $motivo === null ? null : gmdate('Y-m-d', strtotime($alta . ' +2 years')),
                    'id_sustituye' => $anterior,
                    'id_alta'      => $admin,
                    'updated_at'   => Db::now(),
                ]);
                Db::insert('unidades_mov', [
                    'id_unidad' => $id, 'id_rel_zona' => $idRel, 'estado' => 'activa',
                    'motivo' => 'Alta del aparato', 'id_login' => $admin,
                    'fecha' => $alta . ' 09:00:00',
                ]);
                if ($motivo !== null) {
                    Db::insert('unidades_mov', [
                        'id_unidad' => $id, 'id_rel_zona' => $idRel, 'estado' => 'baja',
                        'motivo' => 'Dado de baja: ' . $motivo, 'id_login' => $admin,
                        'fecha' => gmdate('Y-m-d', strtotime($alta . ' +2 years')) . ' 11:00:00',
                    ]);
                }
                $anterior = $id;
                $nu++;
            }

            // Y otros aparatos de la misma habitación, para que la
            // consulta «qué hay aquí» tenga más de un tipo.
            foreach ([[$idAa, 'SNAA-2021-8820', 'Daikin', 'FTXM35R'],
                      [$idCaja, 'SNCF-2018-0114', 'Onity', 'Safe Elite']] as [$el, $serie, $marca, $modelo]) {
                if ($el === null) { continue; }
                $id = Db::insert('unidades', [
                    'numero_serie' => $serie, 'id_elemento' => $el, 'id_rel_zona' => $idRel,
                    'estado' => 'activa', 'marca' => $marca, 'modelo' => $modelo,
                    'fecha_alta' => '2021-05-10', 'id_alta' => $admin, 'updated_at' => Db::now(),
                ]);
                Db::insert('unidades_mov', [
                    'id_unidad' => $id, 'id_rel_zona' => $idRel, 'estado' => 'activa',
                    'motivo' => 'Alta del aparato', 'id_login' => $admin, 'fecha' => '2021-05-10 09:00:00',
                ]);
                $nu++;
            }
            /* Partes ligados a esos aparatos: es lo que permite
               preguntar «cuántas incidencias de TV ha tenido la 1008»
               y ver cuál de los cinco televisores dio guerra. */
            $unidades = Db::all(
                'SELECT id_unidad, numero_serie, fecha_alta FROM unidades
                  WHERE id_elemento = ? AND id_rel_zona = ? ORDER BY fecha_alta',
                [$idTv, $idRel]);
            $estadoFinal = (int)Db::value('SELECT id_estado FROM estados WHERE es_final = 1 ORDER BY orden LIMIT 1');
            $averias = [
                'No se ve la imagen, sólo sonido',
                'No enciende',
                'Se apaga sola a los diez minutos',
                'Se ve con líneas verdes',
                'No coge los canales',
            ];
            $np = 0;
            foreach ($unidades as $k => $un) {
                if ($k >= count($averias)) { break; }
                $cuando = gmdate('Y-m-d H:i:s', strtotime((string)$un['fecha_alta'] . ' +' . random_int(200, 600) . ' days'));
                if (strtotime($cuando) > time()) { continue; }
                $idT = Db::insert('ticket_incidencias', [
                    'uuid' => Http::uuid(),
                    'titulo_ticket' => $averias[$k],
                    'detalle_ticket' => 'Aparato ' . $un['numero_serie'],
                    'fecha_creado' => $cuando,
                    'fecha_cierre' => gmdate('Y-m-d H:i:s', strtotime($cuando . ' +2 days')),
                    'id_estado' => $estadoFinal,
                    'id_prioridad' => 2,
                    'id_elemento' => $idTv,
                    'id_rel_zona' => $idRel,
                    'id_unidad' => (int)$un['id_unidad'],
                    'id_realiza_accion' => $admin,
                    'origen' => 'app',
                    'fecha_creado_sin_hora' => substr($cuando, 0, 10),
                    'id_version' => 1, 'borrado' => 0,
                    'updated_at' => $cuando,
                ]);
                Db::insert('unidades_mov', [
                    'id_unidad' => (int)$un['id_unidad'], 'id_rel_zona' => $idRel,
                    'estado' => 'reparacion', 'motivo' => $averias[$k],
                    'id_ticket' => $idT, 'id_login' => $admin, 'fecha' => $cuando,
                ]);
                $np++;
            }

            $log("· $nu aparatos con número de serie en la habitación " . $hab['lugar']
                . ($np ? ", con $np partes ligados." : '.'));
        }
    }
}
