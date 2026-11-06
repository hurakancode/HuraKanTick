<?php
/**
 * Permisos y configuración del sistema.
 *
 * Dos cosas que van juntas: qué está encendido (ajustes_sistema) y quién
 * puede ver qué (visibilidad por grupo). La visibilidad se aplica en una
 * sola función que usan por igual el listado, la ficha y los informes,
 * para que no haya forma de que una vía sea más permisiva que otra.
 */
declare(strict_types=1);

final class Permisos
{
    private static ?array $ajustes = null;

    /* ---------------------------------------------------------------- */
    /* Configuración                                                    */
    /* ---------------------------------------------------------------- */
    public static function ajustes(): array
    {
        if (self::$ajustes !== null) {
            return self::$ajustes;
        }
        if (!Db::tablaExiste('ajustes_sistema')) {
            return self::$ajustes = [];
        }
        $filas = Db::all('SELECT clave, valor, tipo FROM ajustes_sistema');
        $out = [];
        foreach ($filas as $f) {
            $out[$f['clave']] = match ($f['tipo']) {
                'interruptor' => (int)$f['valor'] === 1 || $f['valor'] === '1' || $f['valor'] === 'true',
                'numero'      => (float)$f['valor'],
                'json'        => json_decode((string)$f['valor'], true),
                default       => $f['valor'],
            };
        }
        return self::$ajustes = $out;
    }

    /**
     * Olvida los ajustes cacheados.
     *
     * Hace falta cuando algo los cambia dentro de la misma petición —el
     * push genera sus claves y las guarda—, porque si no, lo que se lee
     * a continuación sigue siendo lo de antes.
     */
    /**
     * Los ajustes nuevos, aunque no se haya pasado el actualizador.
     *
     * Si alguien sube los ficheros y no ejecuta instalar.php con
     * «actualizar», estas filas no existen y sus secciones no aparecen en
     * Configuración: la vista smartphone, por ejemplo, sencillamente no
     * está. Se crean aquí con su valor de fábrica si faltan. Son pocas y
     * se miran de una vez, así que no cuesta nada.
     */
    /**
     * Un identificador de esta instalación, al azar.
     *
     * Cambia cuando la base se crea de cero o se restaura una copia. El
     * móvil lo recuerda y, si un día el servidor le contesta con otro, sabe
     * que lo que tiene guardado es de una base que ya no existe —partes que
     * no están, números que ahora son de otros partes— y lo vacía antes de
     * volver a descargar. Sin esto, tras reinstalar el hotel el móvil seguía
     * enseñando los partes viejos, y un parte nuevo podía heredar las notas
     * del antiguo que tenía su mismo número.
     */
    public static function instalacion(): string
    {
        if (!Db::tablaExiste('ajustes_sistema')) {
            return '';
        }
        $id = Db::value('SELECT valor FROM ajustes_sistema WHERE clave = ?', ['instalacion_id']);
        if ($id === null || $id === '') {
            $id = self::nuevaInstalacion();
        }
        return (string)$id;
    }

    /** Pone un identificador nuevo: los móviles vaciarán lo que tengan. */
    public static function nuevaInstalacion(): string
    {
        $id = bin2hex(random_bytes(8));
        Db::run('DELETE FROM ajustes_sistema WHERE clave = ?', ['instalacion_id']);
        // Categoría «interno»: no sale en Configuración, no es para tocarlo.
        Db::insert('ajustes_sistema', [
            'clave' => 'instalacion_id', 'valor' => $id, 'tipo' => 'texto',
            'categoria' => 'interno', 'titulo' => 'Identificador de la instalación',
            'descripcion' => 'Cambia al reinstalar o restaurar. Lo usan los móviles para saber si deben vaciar lo que guardan.',
            'orden' => 0,
        ]);
        self::$ajustes = null;
        return $id;
    }

    public static function asegurarAjustes(): void
    {
        if (!Db::tablaExiste('ajustes_sistema')) {
            return;
        }
        $modo = json_encode([
            ['v' => 'no',       't' => 'No'],
            ['v' => 'todos',    't' => 'Sí, para todos (sin opción de cambiar)'],
            ['v' => 'activa',   't' => 'Habilitada y activada (cada uno puede quitarla)'],
            ['v' => 'inactiva', 't' => 'Habilitada sin activar (cada uno puede ponerla)'],
        ], JSON_UNESCAPED_UNICODE);
        $ratio = json_encode([
            ['v' => '9:16',   't' => '9:16 · teléfono clásico'],
            ['v' => '9:19.5', 't' => '9:19,5 · iPhone'],
            ['v' => '9:20',   't' => '9:20 · Android actual'],
        ], JSON_UNESCAPED_UNICODE);

        $fila = static fn($clave, $valor, $tipo, $cat, $titulo, $desc, $opc, $orden) => [
            'clave' => $clave, 'valor' => $valor, 'tipo' => $tipo, 'categoria' => $cat,
            'titulo' => $titulo, 'descripcion' => $desc, 'opciones' => $opc, 'orden' => $orden,
        ];
        $necesarios = [
            /* La copia de los avisos al chat de Teams.
               Viene apagada: sin lo que tiene que dar vuestro informático
               —tenant, aplicación y secreto— no puede funcionar, y una
               función encendida que falla en silencio es peor que una
               apagada que se enciende cuando toca. */
            $fila('teams_activo', '0', 'interruptor', 'teams', 'Mandar copia de los avisos a Teams',
                'Cada aviso llega también al chat privado de la persona en Microsoft Teams. Es de ida: lo que contesten en Teams no entra en el parte, y la tarjeta lo dice. Hace falta configurarlo antes (mira docs/TEAMS.md).',
                null, 1),
            $fila('teams_solo_urgentes', '0', 'interruptor', 'teams', 'Sólo los urgentes',
                'Para no llenarle el Teams a nadie: sólo se copian los avisos de prioridad alta.',
                null, 2),
            $fila('teams_tenant', '', 'texto', 'teams', 'Identificador del tenant',
                'El de vuestra organización en Microsoft. Lo da vuestro informático.', null, 3),
            $fila('teams_app_id', '', 'texto', 'teams', 'Identificador de la aplicación',
                'El «Application (client) ID» del registro de Azure.', null, 4),
            $fila('teams_app_secreto', '', 'texto', 'teams', 'Secreto de la aplicación',
                'El «client secret» de Azure. No se enseña a nadie que no sea administrador, y caduca: apunta la fecha, porque el día que caduque los avisos dejan de salir.',
                null, 5),
            $fila('teams_url_app', '', 'texto', 'teams', 'Dirección de HuraKan Tick para los enlaces',
                'La dirección con la que la gente entra (por ejemplo https://partes.miempresa.com). Se usa para el botón «Abrir el parte» de la tarjeta; sin ella la tarjeta llega sin botón.',
                null, 6),

            $fila('instrucciones_video_mb', '0', 'numero', 'modulos',
                'Tope del vídeo de los manuales (MB)',
                'Cuánto puede pesar un clip subido a una instrucción. Con 0 se usa el de api/config.php (40 MB). El tope de verdad nunca pasa de lo que admita PHP: míralo en el diagnóstico.',
                null, 40),
            $fila('vista_movil_modo', 'no', 'lista', 'vista', 'Forzar vista smartphone',
                'En ordenadores y tabletas, la aplicación se ve dentro de un teléfono centrado en la pantalla. En los móviles no cambia nada.',
                $modo, 1),
            $fila('vista_movil_ratio', '9:19.5', 'lista', 'vista', 'Proporción del teléfono',
                'La forma del marco. Quien pueda activarla también puede elegir la suya.', $ratio, 2),
            $fila('autologin_global', '1', 'interruptor', 'seguridad', 'Permitir enlaces de auto-entrada',
                'Viene encendido: sin él, los enlaces de entrada rápida no funcionan aunque estén activos para una persona, y eso se nota el primer día, cuando hay que dar acceso a mucha gente a la vez. Apagarlo corta todos de golpe, que es lo que interesa si se pierde una tablet.',
                null, 60),
            $fila('autologin_dias', '0', 'numero', 'seguridad', 'Días que vale un enlace de auto-entrada',
                'Pasados esos días el enlace caduca y hay que regenerarlo. Con 0 no caduca, que es lo que interesa en una tablet fija de recepción.',
                null, 61),
            // Categoría «interno»: se maneja desde Iconografía, no desde
            // Configuración, así que no sale en esa pantalla.
            $fila('alta_rapidos_cantidad', '6', 'numero', 'alta',
                'Cuántos accesos rápidos al dar un parte',
                'Los tipos que salen debajo de «Elegir qué falla», para no tener que buscarlos. De 0 a 6; con 0 no sale ninguno.',
                null, 10),
            $fila('alta_rapidos_origen', 'hotel', 'lista', 'alta',
                'Qué accesos rápidos salen',
                'De dónde se sacan: lo más usado en el hotel, en tu departamento, por ti, o una lista fija que elige el administrador.',
                '[{"v":"hotel","t":"Los más usados en el hotel"},{"v":"departamento","t":"Los más usados por mi departamento"},{"v":"usuario","t":"Los que más uso yo"},{"v":"manual","t":"Los que elija el administrador"}]', 11),
            $fila('alta_rapidos_manual', '[]', 'json', 'alta', 'Qué tipos salen en el acceso rápido',
                'Sólo cuenta si arriba has elegido «Los que elija el administrador». Toca para añadir; se guardan los seis primeros.',
                null, 12),
            /* La orden de «tirad lo que tenéis y empezad de nuevo»: un
               momento. Cada móvil recuerda el último que ha atendido; si el
               del servidor es posterior, se vacía y vuelve a descargar. */
            $fila('purga_generacion', '', 'texto', 'interno', 'Última renovación forzada',
                'Cuándo se pidió a todos los aparatos vaciar lo guardado y volver a descargar.', null, 0),
            $fila('iconos_personalizados', '{}', 'json', 'interno', 'Iconos cambiados',
                'Qué icono de Font Awesome sustituye a cada icono de la aplicación.', null, 0),
            $fila('ticket_historial_elemento', '1', 'interruptor', 'comportamiento',
                'Historial del mismo elemento y sitio',
                'Al final del parte se enseña qué más ha pasado con ese mismo tipo de avería en ese mismo sitio, y desde ahí se llega al historial completo del elemento. Útil para ver si algo se repite.',
                null, 60),
            $fila('guia_api_para_todos', '0', 'interruptor', 'modulos',
                'Guía de la API para todos los administradores',
                'De fábrica sólo la ve el superadministrador: explica cómo entrar al sistema desde otro programa, y eso no es información del día a día de un hotel.',
                null, 80),
            $fila('foco_buscador', '1', 'interruptor', 'comportamiento',
                'Poner el cursor en el buscador al abrirlo',
                'Al abrir un panel de búsqueda —elegir el tipo de avería, la ubicación, una persona— el teclado sale puesto y se puede escribir directamente. En las pantallas con lista, en el móvil, el teclado no sale solo: taparía lo que hay.',
                null, 21),
            $fila('asignar_companero', '1', 'interruptor', 'comportamiento',
                'Permitir asignar un parte a un compañero de departamento',
                'Cualquiera puede pasarle un parte a alguien de su propio departamento. Apagado, sólo los responsables reparten. Se puede dar a una persona suelta desde su ficha, aunque aquí esté apagado.',
                null, 18),
            $fila('asignar_responsable', '1', 'interruptor', 'comportamiento',
                'Permitir asignar un parte a un responsable',
                'Para poder subir un parte a quien manda cuando se atasca o no toca a quien lo tiene. Apagado, hay que avisarle por otro medio.',
                null, 19),
            $fila('asignar_otro_departamento', '1', 'interruptor', 'comportamiento',
                'Permitir asignar a un departamento distinto',
                'Pasar el parte a otro departamento cuando la avería no era de lo que se pensaba: el goteo del lavabo resulta ser del aire acondicionado.',
                null, 20),
            $fila('respetar_asignado', '1', 'interruptor', 'comportamiento',
                'Respetar el parte que ya lleva otra persona',
                'Un técnico no puede quitarle ni quedarse un parte que otro tiene asignado; los responsables sí. Apagado, cualquiera del departamento puede cogerlo, que a veces es lo práctico cuando se cubren entre ellos.',
                null, 16),
            $fila('editar_cerrado', '0', 'interruptor', 'comportamiento',
                'Dejar editar el texto de un parte cerrado',
                'De fábrica, un parte cerrado no se reescribe: si hay que corregirlo se reabre, y así queda constancia. Los responsables pueden hacerlo de todos modos.',
                null, 17),
            $fila('refresco_auto', '1', 'interruptor', 'comportamiento',
                'Refrescar la pantalla sola al sincronizar',
                'Mantiene el inicio y las listas al día sin tocar nada. Nunca se refresca mientras se escribe: lo escrito a medias no se pierde. Apágalo si prefieres que la pantalla no se mueva nunca sin pedirlo.',
                null, 14),
            $fila('offline_activo', '1', 'interruptor', 'modulos',
                'Permitir trabajar sin cobertura',
                'Apagado, la aplicación exige conexión para todo: nada se queda guardado en el móvil a la espera de señal. Sólo tiene sentido si el hotel tiene wifi en todas partes y prefieres que nadie trabaje a ciegas.',
                null, 70),
            $fila('forzar_https', '0', 'interruptor', 'seguridad', 'Forzar HTTPS',
                'Si alguien entra por http, se le manda a https. Hace falta que el servidor tenga certificado: sin él, la aplicación dejaría de abrirse.',
                null, 5),
            $fila('correo_obligatorio', '0', 'interruptor', 'seguridad', 'Exigir correo a todo el mundo',
                'Normalmente no: hay cuentas de puesto que no tienen correo. Actívalo sólo si en tu hotel todo el mundo debe tener uno.',
                null, 62),
        ];

        /* Y los ajustes de los módulos opcionales.
           Si no hay ninguno, esto no añade nada; y si uno está mal
           escrito se queda fuera él solo, sin dejar al resto sin
           configuración. */
        if (class_exists('Plugins')) {
            try {
                foreach (Plugins::ajustes() as $deModulo) {
                    if (!empty($deModulo['clave'])) { $necesarios[] = $deModulo; }
                }
            } catch (Throwable $e) {
                error_log('Ajustes de módulos: ' . $e->getMessage());
            }
        }

        $claves = array_column($necesarios, 'clave');
        $marcas = implode(',', array_fill(0, count($claves), '?'));
        $hay = array_column(Db::all("SELECT clave FROM ajustes_sistema WHERE clave IN ($marcas)", $claves), 'clave');
        if (count($hay) === count($claves)) {
            return;
        }
        foreach ($necesarios as $f) {
            if (!in_array($f['clave'], $hay, true)) {
                Db::insertarSiFalta('ajustes_sistema', $f);
            }
        }
        self::$ajustes = null;   // que la próxima lectura los vea
    }

    public static function olvidarAjustes(): void
    {
        self::$ajustes = null;
    }

    /**
     * ¿Es esta persona de ese departamento?
     *
     * Hoy mira su departamento y los adicionales, si los tiene: en un
     * hotel es normal que informática y servicios técnicos se cubran, y
     * quien esté en los dos debe poder quedarse partes de ambos.
     */
    public static function perteneceA(int $idLogin, int $idDepartamento): bool
    {
        $suyo = Http::id(Db::value('SELECT id_departamento FROM login WHERE id_login = ?', [$idLogin]));
        if ($suyo === $idDepartamento) {
            return true;
        }
        if (Db::tablaExiste('login_departamento')) {
            return Db::value(
                'SELECT 1 FROM login_departamento WHERE id_login = ? AND id_departamento = ?',
                [$idLogin, $idDepartamento]) !== null;
        }
        return false;
    }

    public static function ajuste(string $clave, $porDefecto = null)
    {
        $a = self::ajustes();
        return array_key_exists($clave, $a) ? $a[$clave] : $porDefecto;
    }

    /** ¿Está encendida una función? Los módulos van con el prefijo mod_. */
    public static function activo(string $modulo): bool
    {
        return (bool)self::ajuste('mod_' . $modulo, true);
    }

    public static function fijar(string $clave, $valor): void
    {
        $tipo = Db::value('SELECT tipo FROM ajustes_sistema WHERE clave = ?', [$clave]);
        if ($tipo === null) {
            throw new ApiError("El ajuste «{$clave}» no existe.", 404);
        }
        $texto = match ($tipo) {
            'interruptor' => (Http::bool($valor) ? '1' : '0'),
            'json'        => json_encode($valor, JSON_UNESCAPED_UNICODE),
            'numero'      => (string)(float)$valor,
            default       => (string)$valor,
        };
        Db::update('ajustes_sistema', ['valor' => $texto, 'updated_at' => Db::now()],
                   'clave = :c', ['c' => $clave]);
        self::$ajustes = null;
    }

    /* ---------------------------------------------------------------- */
    /* Visibilidad                                                      */
    /* ---------------------------------------------------------------- */
    /**
     * Condición SQL que limita los partes que una persona puede ver.
     * Devuelve ['', []] si puede verlo todo.
     *
     * @param string $t alias de ticket_incidencias
     * @param string $r alias de rel_zonas (no se usa hoy, reservado)
     */
    /**
     * El filtro de visibilidad, con sus parámetros.
     *
     * `$sufijo` renombra los parámetros, y hace falta cuando el mismo
     * filtro se mete DOS VECES en una sola consulta.
     *
     * En SQLite eso funcionaba: su controlador deja repetir un
     * parámetro con nombre. MySQL con preparación nativa —que es como
     * se conecta aquí, `ATTR_EMULATE_PREPARES => false`— contesta
     * «SQLSTATE[HY093]: Invalid parameter number» y se cae la consulta
     * entera.
     *
     * Resultado: buscar una ubicación —que es como se abre un parte
     * diciendo en qué habitación es— devolvía «No se ha podido
     * completar la operación en la base de datos» en MySQL. En SQLite,
     * perfecto. Y el hotel va a funcionar con MySQL.
     *
     * Se encontró pasando la batería contra la instalación de MySQL por
     * primera vez. Ninguna prueba lo había visto porque todas se
     * ejecutaban contra SQLite.
     */
    public static function filtroVisibilidad(array $u, string $t = 't', string $r = 'r',
                                             string $sufijo = ''): array
    {
        if ($sufijo !== '') {
            [$sql, $p] = self::filtroVisibilidad($u, $t, $r);
            $nuevos = [];
            foreach ($p as $k => $v) {
                $nuevos[$k . $sufijo] = $v;
                /* Se renombra sólo el parámetro entero, con su frontera
                   por delante y por detrás: sin eso, «:hot» dentro de
                   «:hotel» se rompería por la mitad. */
                $sql = preg_replace('/:' . preg_quote($k, '/') . '\b/', ':' . $k . $sufijo, $sql);
            }
            return [$sql, $nuevos];
        }

        /* Aislamiento entre hoteles.
         *
         * Va lo primero y por fuera de todo lo demás: el hotel no es un
         * permiso más que se pueda tener «completo», es una frontera.
         * Un administrador ve todos los partes de SU hotel y ninguno de
         * otro, y un superadministrador ve los del hotel que esté
         * mirando, no los de todos a la vez.
         *
         * Antes de esto, el «return» de la línea siguiente dejaba pasar
         * cualquier parte a cualquier responsable, de cualquier hotel.
         */
        $hotelCond = '';
        $hotelP = [];
        if (class_exists('Hoteles') && Db::tablaExiste('hoteles')) {
            [$hotelCond, $hotelP] = Hoteles::filtro($t, $u);
        }

        $conHotel = static function (string $cond, array $p) use ($hotelCond, $hotelP): array {
            if ($hotelCond === '') {
                return [$cond, $p];
            }
            if ($cond === '') {
                return [$hotelCond, $hotelP];
            }
            return ["($hotelCond) AND ($cond)", $p + $hotelP];
        };

        $yo  = (int)$u['id_login'];
        $dep = $u['id_departamento'] === null ? null : (int)$u['id_departamento'];

        /* Los partes donde le han puesto de observador se ven siempre,
           sea cual sea el modo de visibilidad: es una excepción hecha a
           mano para un parte concreto, y si el modo la anulara no serviría
           de nada. Se añade dentro del envoltorio para no repetirlo en
           cada rama. */
        /* Los partes programados SÍ se ven.
         *
         * Al principio se ocultaban hasta su fecha, y es peor: un técnico
         * que no ve lo que viene no puede organizarse la semana. Se ven,
         * con su fecha delante, y el aviso del inicio enseña los que están
         * cerca. Lo que no hacen es ocupar sitio como si fueran de hoy:
         * para eso están el estado «Programado» y el orden por fecha. */
        $observados = Db::tablaExiste('ticket_observadores')
            ? " OR EXISTS (SELECT 1 FROM ticket_observadores o
                            WHERE o.id_ticket = $t.id_ticket
                              AND (o.id_login = :vis_obs
                                   OR (o.id_departamento IS NOT NULL
                                       AND o.id_departamento = :vis_obs_dep)))"
            : '';
        $conObservados = static function (string $cond, array $args)
                use ($conHotel, $observados, $yo, $dep) {
            /* Condición vacía significa «lo ve todo» —administradores y
               responsables—: añadirles la de observador sobraba y, peor,
               generaba un «( OR EXISTS…)» que no es SQL válido y dejaba a
               todo el mundo sin lista. */
            if ($cond !== '' && $observados !== '') {
                $cond = '(' . $cond . $observados . ')';
                $args['vis_obs'] = $yo;
                $args['vis_obs_dep'] = $dep ?? 0;
            }
            return $conHotel($cond, $args);
        };



        // Administradores y responsables ven todo lo de su hotel: son
        // quienes reparten.
        if (Auth::esResponsable($u)) {
            return $conObservados('', []);
        }

        $modo = (string)self::ajuste('visibilidad_tickets', 'grupo');

        // Quien sólo avisa: si está configurado así, ve lo suyo y nada más.
        if (($u['rol'] ?? '') === 'reportador' && self::ajuste('reportador_ve_propios', true)) {
            return $conObservados("($t.id_realiza_accion = :vis_yo)", ['vis_yo' => $yo]);
        }

        if ($modo === 'todos') {
            return $conObservados('', []);
        }

        if ($modo === 'propios') {
            return $conObservados(
                "($t.id_realiza_accion = :vis_yo OR $t.id_persona_asignada = :vis_yo2)",
                ['vis_yo' => $yo, 'vis_yo2' => $yo]
            );
        }

        // Modo «grupo»: lo mío, más lo de mi departamento.
        if ($dep === null) {
            return $conObservados(
                "($t.id_realiza_accion = :vis_yo OR $t.id_persona_asignada = :vis_yo2)",
                ['vis_yo' => $yo, 'vis_yo2' => $yo]
            );
        }

        /* Y los departamentos adicionales, si los tiene.
         *
         * Es habitual que informática y servicios técnicos se cubran: quien
         * esté en los dos debe ver y poder coger los partes de ambos, sin
         * que eso le abra el hotel entero. */
        $extra = self::departamentosDe($yo);

        $cond = "($t.id_realiza_accion = :vis_yo
                  OR $t.id_persona_asignada = :vis_yo2
                  OR $t.id_grupo_asignado = :vis_dep
                  OR $t.id_persona_asignada IN (SELECT id_login FROM login WHERE id_departamento = :vis_dep2))";
        $args = ['vis_yo' => $yo, 'vis_yo2' => $yo, 'vis_dep' => $dep, 'vis_dep2' => $dep];

        if ($extra) {
            $marcas = [];
            foreach (array_values($extra) as $n => $idDep) {
                $marcas[] = ':vis_x' . $n;
                $args['vis_x' . $n] = $idDep;
            }
            /* Se envuelve la condición entera y se añade fuera: recortar el
               último paréntesis metía el añadido dentro de la subconsulta
               y el filtro dejaba de funcionar sin dar ningún error. */
            $cond = '(' . $cond . " OR $t.id_grupo_asignado IN (" . implode(',', $marcas) . '))';
        }

        /* Y los partes donde le han puesto de observador.
         *
         * Es acceso a ESE parte, no al departamento entero: la caja
         * fuerte la arregla servicios técnicos, pero recepción necesita
         * seguir ese parte porque es quien da la cara con el cliente. */
        if (false && Db::tablaExiste('ticket_observadores')) {
            $cond = '(' . $cond . " OR EXISTS (
                        SELECT 1 FROM ticket_observadores o
                         WHERE o.id_ticket = $t.id_ticket
                           AND (o.id_login = :vis_obs
                                OR (o.id_departamento IS NOT NULL
                                    AND o.id_departamento = :vis_obs_dep)))) ";
            $args['vis_obs'] = $yo;
            $args['vis_obs_dep'] = $dep;
        }

        return $conObservados($cond, $args);
    }


    /**
     * Los departamentos adicionales de una persona.
     *
     * El suyo propio no entra aquí: esto es lo de más. Se consulta a
     * menudo —en cada listado— así que se recuerda por petición.
     */
    public static function departamentosDe(int $idLogin): array
    {
        static $cache = [];
        if (isset($cache[$idLogin])) { return $cache[$idLogin]; }
        if (!Db::tablaExiste('login_departamento')) { return $cache[$idLogin] = []; }

        $cache[$idLogin] = array_map('intval', array_column(
            Db::all('SELECT id_departamento FROM login_departamento WHERE id_login = ?', [$idLogin]),
            'id_departamento'));
        return $cache[$idLogin];
    }

    /**
     * ¿Tiene esta persona un permiso suelto?
     *
     * Sirve para dar una capacidad concreta sin convertir a nadie en
     * administrador. Un administrador los tiene todos por definición.
     */
    public static function tienePermiso(array $u, string $permiso): bool
    {
        if (Auth::esAdmin($u)) { return true; }
        if (!Db::tablaExiste('login_permisos')) { return false; }
        return Db::value('SELECT 1 FROM login_permisos WHERE id_login = ? AND permiso = ?',
                         [(int)$u['id_login'], $permiso]) !== null;
    }



    /**
     * ¿Puede esta persona pasarle el parte a esa otra?
     *
     * Antes sólo los responsables podían asignar a alguien que no fueran
     * ellos mismos, y eso dejaba fuera el caso más normal: un técnico que
     * se atasca y quiere subirle el parte a su responsable, o pasárselo
     * al compañero que lleva el aire acondicionado.
     *
     * Hay tres permisos distintos porque son tres decisiones distintas, y
     * cada hotel querrá una cosa:
     *   · a un compañero del mismo departamento;
     *   · a un responsable;
     *   · a alguien de otro departamento.
     *
     * Cada uno se decide en Configuración para todo el mundo, y se puede
     * dar a una persona suelta desde su ficha. Lo de la persona manda
     * sobre lo global: sirve para dar más, nunca para quitar.
     */
    public static function puedeAsignarA(array $u, ?int $destino, array $ticket): bool
    {
        // Dejarlo sin dueño o quedárselo uno mismo no es repartir trabajo.
        if ($destino === null || $destino === (int)$u['id_login']) { return true; }

        // Quien reparte, reparte.
        if (Auth::esResponsable($u)) { return true; }

        $otro = Db::one('SELECT id_login, id_departamento, rol, admin FROM login WHERE id_login = ?', [$destino]);
        if ($otro === null) { return false; }

        $permitido = static fn(string $clave, string $permiso): bool =>
            (bool)self::ajuste($clave, true) || self::tienePermiso($u, $permiso);

        $esResponsable = in_array((string)($otro['rol'] ?? ''), ['responsable', 'admin'], true)
                         || (int)($otro['admin'] ?? 0) === 1;

        if ($esResponsable) {
            return $permitido('asignar_responsable', 'asignar_responsable');
        }

        $mismoDepto = $otro['id_departamento'] !== null
            && (int)$otro['id_departamento'] === (int)($u['id_departamento'] ?? 0);

        return $mismoDepto
            ? $permitido('asignar_companero', 'asignar_companero')
            : $permitido('asignar_otro_departamento', 'asignar_otro_departamento');
    }

    /**
     * ¿Está esta persona puesta como observadora de este parte?
     *
     * Por sí misma o por su departamento. Un observador ve el parte y
     * puede escribir en su conversación, pero no lo trabaja: no es suyo.
     */
    public static function esObservador(int $idLogin, ?int $dep, ?int $idTicket): bool
    {
        if ($idTicket === null || !Db::tablaExiste('ticket_observadores')) {
            return false;
        }
        return Db::value(
            'SELECT 1 FROM ticket_observadores
              WHERE id_ticket = ?
                AND (id_login = ? OR (id_departamento IS NOT NULL AND id_departamento = ?))',
            [$idTicket, $idLogin, $dep ?? 0]) !== null;
    }

    /** ¿Puede esta persona abrir este parte? */
    public static function puedeVer(array $u, array $ticket): bool
    {
        /* El hotel, lo primero y por encima de todo.
         *
         * Esta función se llama al abrir un parte por su número, que es
         * el camino que se salta el filtro del listado. Sin esto,
         * cualquiera podía leer un parte de otro hotel escribiendo su
         * número en la dirección, incluso siendo sólo técnico. */
        if (Db::tablaExiste('hoteles') && isset($ticket['id_hotel']) && $ticket['id_hotel'] !== null) {
            if (!Hoteles::puede((int)$ticket['id_hotel'], $u)) {
                return false;
            }
        }

        if (Auth::esResponsable($u)) {
            return true;
        }

        /* Observador: acceso a ESTE parte, decidido a mano por un
           responsable. Va antes que el modo de visibilidad porque es
           precisamente la excepción a él: recepción sólo ve lo suyo, y
           aun así tiene que poder seguir el parte de la caja fuerte del
           que da la cara ante el cliente. */
        if (self::esObservador((int)$u['id_login'],
                               $u['id_departamento'] === null ? null : (int)$u['id_departamento'],
                               Http::id($ticket['id_ticket'] ?? null))) {
            return true;
        }

        $yo  = (int)$u['id_login'];
        $dep = $u['id_departamento'] === null ? null : (int)$u['id_departamento'];

        if ((int)($ticket['id_realiza_accion'] ?? 0) === $yo) { return true; }
        if ((int)($ticket['id_persona_asignada'] ?? 0) === $yo) { return true; }

        if (($u['rol'] ?? '') === 'reportador' && self::ajuste('reportador_ve_propios', true)) {
            return false;
        }

        $modo = (string)self::ajuste('visibilidad_tickets', 'grupo');
        if ($modo === 'todos') { return true; }
        if ($modo === 'propios') { return false; }

        if ($dep !== null) {
            if ((int)($ticket['id_grupo_asignado'] ?? 0) === $dep) { return true; }

        /* Y los departamentos donde además echa una mano. El listado ya
           los tenía en cuenta; esto es lo que decide si puede abrir el
           parte, y sin ello salía en la lista pero daba «no tienes
           permiso» al tocarlo. */
        $grupoT = Http::id($ticket['id_grupo_asignado'] ?? null);
        if ($grupoT !== null && in_array($grupoT, self::departamentosDe($yo), true)) {
            return true;
        }

        // Y si le han puesto de observador en este parte concreto.
        if (self::esObservador($yo, $dep, Http::id($ticket['id_ticket'] ?? null))) {
            return true;
        }
            $asignado = $ticket['id_persona_asignada'] ?? null;
            if ($asignado !== null) {
                $depAsignado = Db::value('SELECT id_departamento FROM login WHERE id_login = ?', [(int)$asignado]);
                if ($depAsignado !== null && (int)$depAsignado === $dep) { return true; }
            }
        }

        // Consulta de otros grupos, si el administrador lo permite.
        return (bool)self::ajuste('ver_otros_grupos', false);
    }

    /** ¿Puede modificarlo? Ver no implica poder tocar. */
    /**
     * ¿Puede esta persona quedarse este parte?
     *
     * Sólo si va a su departamento. Si no, cualquiera podría llevarse
     * trabajo de otro oficio y el reparto dejaría de significar nada:
     * un camarero cogiendo una avería eléctrica no la va a resolver, y
     * mientras tanto los electricistas dejan de verla sin asignar.
     */
    public static function puedeAutoasignarse(array $u, array $ticket): bool
    {
        if (!self::puedeVer($u, $ticket)) {
            return false;
        }
        // Los responsables reparten, así que pueden cogerlo igual.
        if (Auth::esResponsable($u)) {
            return true;
        }
        $mio = $u['id_departamento'] === null ? null : (int)$u['id_departamento'];
        $del = $ticket['id_grupo_asignado'] === null ? null : (int)$ticket['id_grupo_asignado'];

        // Sin grupo asignado lo puede coger cualquiera: está huérfano.
        if ($del === null) {
            return true;
        }
        return $mio !== null && $mio === $del;
    }

    public static function puedeEditar(array $u, array $ticket): bool
    {
        // Si no se puede ver, no se puede tocar.
        if (!self::puedeVer($u, $ticket)) {
            return false;
        }
        if (Auth::esResponsable($u)) { return true; }
        if (!self::puedeVer($u, $ticket)) { return false; }

        $yo  = (int)$u['id_login'];
        if ((int)($ticket['id_persona_asignada'] ?? 0) === $yo) { return true; }
        if ((int)($ticket['id_realiza_accion'] ?? 0) === $yo) { return true; }

        // Del grupo: sí puede trabajarlo, es el sentido de tener grupo.
        $dep = $u['id_departamento'] === null ? null : (int)$u['id_departamento'];
        if ($dep !== null && (int)($ticket['id_grupo_asignado'] ?? 0) === $dep) {
            return true;
        }

        /* Y los departamentos adicionales: si alguien echa una mano en
           informática, tiene que poder trabajar sus partes, no sólo
           mirarlos. Verlos y no poder tocarlos no sirve de nada. */
        $grupoT = Http::id($ticket['id_grupo_asignado'] ?? null);
        if ($grupoT !== null && in_array($grupoT, self::departamentosDe($yo), true)) {
            return true;
        }

        return false;
    }

    /* ---------------------------------------------------------------- */
    /* Avisos                                                           */
    /* ---------------------------------------------------------------- */
    /** ¿Quiere que este tipo de aviso suene? */
    public static function avisoSuena(int $idLogin, string $evento): bool
    {
        $pref = Db::one('SELECT sonido FROM avisos_pref WHERE id_login = ? AND evento = ?', [$idLogin, $evento]);
        return $pref === null ? true : (int)$pref['sonido'] === 1;
    }

    /** ¿Quiere esta persona recibir este tipo de aviso? */
    public static function quiereAviso(int $idLogin, string $evento): bool
    {
        $pref = Db::one('SELECT activo FROM avisos_pref WHERE id_login = ? AND evento = ?', [$idLogin, $evento]);
        if ($pref !== null) {
            return (int)$pref['activo'] === 1;
        }
        return (bool)self::ajuste('aviso_' . $evento, true);
    }

    /** Preferencias de una persona, con los valores por defecto rellenos. */
    public static function avisosDe(int $idLogin): array
    {
        $eventos = [
            'asignado'        => 'Me asignan un parte',
            'asignado_grupo'  => 'Asignan un parte a mi grupo',
            'cambio_estado'   => 'Cambia el estado de un parte mío',
            'nota'            => 'Alguien escribe una nota',
            'urgente_nuevo'   => 'Entra un parte urgente',
            'vencido'         => 'Un parte mío se pasa de plazo',
        ];
        $propias = [];
        foreach (Db::all('SELECT evento, activo, sonido FROM avisos_pref WHERE id_login = ?', [$idLogin]) as $f) {
            $propias[$f['evento']] = $f;
        }
        $out = [];
        foreach ($eventos as $clave => $texto) {
            $porDefecto = (bool)self::ajuste('aviso_' . $clave, true);
            $out[] = [
                'evento'      => $clave,
                'titulo'      => $texto,
                'activo'      => isset($propias[$clave]) ? (int)$propias[$clave]['activo'] === 1 : $porDefecto,
                'sonido'      => isset($propias[$clave]) ? (int)$propias[$clave]['sonido'] === 1 : true,
                'por_defecto' => $porDefecto,
                'personalizado' => isset($propias[$clave]),
            ];
        }
        return $out;
    }
}
