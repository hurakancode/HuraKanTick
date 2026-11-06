<?php
/**
 * Ficha del módulo SAP.
 *
 * Lo que el sistema necesita saber de él: cómo se llama, qué opciones
 * tiene, qué rutas atiende y qué enseñar en Ajustes.
 *
 * Todo viene apagado. Encenderlo es una decisión, y hasta que alguien
 * la tome el sistema se comporta como si esta carpeta no existiera.
 */
declare(strict_types=1);

return [
    'id'          => 'sap',
    'nombre'      => 'Conector con SAP',
    'version'     => '1.0',
    'descripcion' => 'Lee del PMS del hotel la ocupación de las habitaciones: '
                   . 'si está ocupada, quién duerme allí, de qué país, en qué '
                   . 'estado está y entre qué fechas. Sólo lee; no escribe nada en SAP.',

    /* Las rutas que atiende. El núcleo las consulta cuando ninguna suya
       ha respondido, así que un plugin no puede pisar nada del sistema. */
    'rutas' => [
        'sap' => 'rutas.php',
    ],

    /**
     * Sus opciones.
     *
     * Cada cosa que se puede consultar lleva su propio alcance: hay datos
     * —el nombre de un cliente— que no tiene por qué ver todo el mundo, y
     * otros —si la habitación está ocupada— que le hacen falta al técnico
     * que está delante de la puerta.
     */
    'ajustes' => static function (): array {
        $alcance = json_encode(Plugins::opcionesAlcance(), JSON_UNESCAPED_UNICODE);
        $tipos = json_encode([
            ['v' => 'demo',    't' => 'Datos de prueba (sin conectar a nada)'],
            ['v' => 'rest',    't' => 'Servicio REST con JSON'],
            ['v' => 'odata',   't' => 'SAP Gateway / OData'],
            ['v' => 'soap',    't' => 'Servicio web SOAP (RFC/BAPI)'],
            ['v' => 'fichero', 't' => 'Una carpeta con ficheros CSV o JSON'],
        ], JSON_UNESCAPED_UNICODE);

        $f = static fn($clave, $valor, $tipo, $titulo, $desc, $opc = null, $orden = 0) => [
            'clave' => $clave, 'valor' => $valor, 'tipo' => $tipo,
            'categoria' => 'sap', 'titulo' => $titulo, 'descripcion' => $desc,
            'opciones' => $opc, 'orden' => $orden,
        ];

        return [
            /* --- Cómo se conecta ---------------------------------------- */
            $f('sap_tipo', 'demo', 'lista', 'Tipo de conexión',
               'Mientras no tengas los datos del hotel, «datos de prueba» deja ver cómo queda todo sin conectar con nada.',
               $tipos, 1),
            $f('sap_url', '', 'texto', 'Dirección del servicio',
               'La URL del servicio REST, del OData o del WSDL. Por ejemplo: https://sap.hotel.com/sap/opu/odata/sap/ZHOTEL_SRV/RoomSet',
               null, 2),
            $f('sap_usuario', '', 'texto', 'Usuario',
               'El usuario técnico que da SAP para esta lectura. Que tenga sólo lectura: aquí no se escribe nada.',
               null, 3),
            $f('sap_clave', '', 'clave', 'Contraseña o testigo',
               'Si no pones usuario, esto se manda como testigo (Bearer).', null, 4),
            $f('sap_cliente', '', 'texto', 'Mandante (sap-client)',
               'El número de mandante, si el servicio lo pide. Suele ser 100, 200, 300…', null, 5),
            $f('sap_codigo_hotel', '', 'texto', 'Código del hotel en SAP',
               'Si el mismo servicio sirve a varios hoteles, cuál es éste.', null, 6),
            $f('sap_carpeta', '', 'texto', 'Carpeta de ficheros',
               'Para el tipo «carpeta»: dónde deja el PMS los CSV o JSON. Ruta del servidor, no del ordenador de nadie.',
               null, 7),
            $f('sap_funcion', 'RoomStatus', 'texto', 'Función SOAP',
               'Sólo para SOAP: el nombre de la función o BAPI a llamar.', null, 8),
            $f('sap_espera', '8', 'numero', 'Segundos de espera',
               'Cuánto esperar a que SAP conteste antes de rendirse. Si tarda más, mejor que el parte siga sin ese dato que dejar la pantalla colgada.',
               null, 9),
            $f('sap_cache_minutos', '5', 'numero', 'Minutos que se recuerda una respuesta',
               'Evita preguntar cincuenta veces por la misma habitación. Con 0 se pregunta siempre.',
               null, 10),
            $f('sap_habitacion_prueba', '101', 'texto', 'Habitación para la prueba',
               'Cuál se consulta al probar la conexión.', null, 11),

            /* --- Qué se puede consultar, y quién --------------------------
               Todo a «nadie» de fábrica: encender el módulo no debe abrir
               de golpe los datos de los clientes a todo el equipo. */
            $f('sap_ver_ocupacion', 'nadie', 'lista', 'Ver si la habitación está ocupada',
               'Lo más básico y lo menos delicado: si hay alguien dentro.', $alcance, 20),
            $f('sap_ver_estado', 'nadie', 'lista', 'Ver el estado de la habitación',
               'Limpia, sucia, en limpieza, bloqueada, no molestar.', $alcance, 21),
            $f('sap_ver_huespedes', 'nadie', 'lista', 'Ver el nombre de los huéspedes',
               'Datos de clientes: piensa a quién se lo abres.', $alcance, 22),
            $f('sap_ver_pais', 'nadie', 'lista', 'Ver el país y el idioma',
               'Para saber en qué idioma hablarle a quien abra la puerta.', $alcance, 23),
            $f('sap_ver_fechas', 'nadie', 'lista', 'Ver las fechas de la estancia',
               'Desde cuándo y hasta cuándo. Sirve para saber si conviene entrar hoy o esperar a la salida.',
               $alcance, 24),

            /* --- Dónde aparece ------------------------------------------ */
            $f('sap_en_parte', '0', 'interruptor', 'Enseñarlo al dar un parte',
               'Al elegir la habitación, el alta enseña si está ocupada y quién hay. Sólo ve cada uno lo que le permita su alcance.',
               null, 30),
            $f('sap_en_ficha', '0', 'interruptor', 'Enseñarlo en la ficha del parte',
               'Lo mismo, dentro de un parte ya dado.', null, 31),
            $f('sap_avisar_ocupada', '0', 'interruptor', 'Avisar si se va a entrar en una ocupada',
               'Al dar un parte de una habitación ocupada, un aviso: conviene avisar a recepción antes de subir.',
               null, 32),
        ];
    },

    /**
     * Qué enseñar en Ajustes.
     *
     * No aparece hasta que el módulo está encendido Y configurado: un
     * menú que lleva a una pantalla vacía es peor que no tener menú.
     */
    'menu' => static function (array $u): array {
        require_once __DIR__ . '/Sap.php';
        if (!Sap::configurado()) {
            return [];
        }

        $puede = static fn(string $clave) =>
            Plugins::alcanza((string)Permisos::ajuste($clave, 'nadie'), $u);

        $entradas = [];
        if ($puede('sap_ver_ocupacion') || $puede('sap_ver_estado')) {
            $entradas[] = ['ruta' => '/sap/habitacion', 'icono' => 'cama',
                           'titulo' => 'Consultar una habitación',
                           'detalle' => 'Ocupación, estado y estancia'];
        }
        if ($puede('sap_ver_estado')) {
            $entradas[] = ['ruta' => '/sap/estados', 'icono' => 'lista',
                           'titulo' => 'Estado de las habitaciones',
                           'detalle' => 'Qué está libre y qué está ocupado'];
        }
        if (Auth::esAdmin($u)) {
            $entradas[] = ['ruta' => '/sap/conexion', 'icono' => 'ajustes',
                           'titulo' => 'Conexión y prueba',
                           'detalle' => 'Comprobar que SAP responde'];
        }

        if (!$entradas) { return []; }

        return [
            'titulo'  => 'SAP',
            'detalle' => 'Ocupación de las habitaciones',
            'icono'   => 'enlace',
            'entradas' => $entradas,
        ];
    },
];
