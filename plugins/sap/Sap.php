<?php
/**
 * El conector con SAP.
 *
 * Lee del PMS del hotel: si la habitación está ocupada, quién duerme
 * allí, de qué país —que en un hotel quiere decir en qué idioma hay que
 * hablarle—, en qué estado está la habitación y entre qué fechas.
 *
 * Aquí NO se escribe nada en SAP. Un sistema de partes no tiene por qué
 * tocar la ficha de un cliente, y si algún día hiciera falta, será una
 * decisión aparte y consciente.
 *
 * Cuatro maneras de conectarse, porque cada hotel tiene la suya y
 * todavía no sabemos cuál es la de éste:
 *
 *   rest      un servicio HTTP que devuelve JSON (lo más común hoy)
 *   odata     SAP Gateway / OData v2, con su $filter y su $format=json
 *   soap      el servicio web clásico sobre RFC/BAPI
 *   fichero   una carpeta donde el PMS deja un CSV o un JSON cada X
 *   demo      datos de mentira, para ver cómo queda antes de tener nada
 *
 * Todo lo que llega de fuera se limpia antes de entrar en la aplicación:
 * lo que devuelva SAP acaba en una pantalla, y no vamos a fiarnos de
 * que venga sin sorpresas.
 */
declare(strict_types=1);

final class Sap
{
    /** Lo configurado, tal cual está en Ajustes. */
    public static function config(): array
    {
        return [
            'tipo'      => (string)Permisos::ajuste('sap_tipo', 'demo'),
            'url'       => (string)Permisos::ajuste('sap_url', ''),
            'usuario'   => (string)Permisos::ajuste('sap_usuario', ''),
            'clave'     => (string)Permisos::ajuste('sap_clave', ''),
            'cliente'   => (string)Permisos::ajuste('sap_cliente', ''),   // mandante
            'carpeta'   => (string)Permisos::ajuste('sap_carpeta', ''),
            'segundos'  => max(2, (int)Permisos::ajuste('sap_espera', 8)),
            'cache_min' => max(0, (int)Permisos::ajuste('sap_cache_minutos', 5)),
            'hotel_sap' => (string)Permisos::ajuste('sap_codigo_hotel', ''),
        ];
    }

    /** ¿Hay algo configurado, o está a medias? */
    public static function configurado(): bool
    {
        $c = self::config();
        if ($c['tipo'] === 'demo')    { return true; }
        if ($c['tipo'] === 'fichero') { return $c['carpeta'] !== ''; }
        return $c['url'] !== '';
    }

    /* ------------------------------------------------------------------ */
    /* Lo que se puede preguntar                                          */
    /* ------------------------------------------------------------------ */

    /**
     * El estado de una habitación.
     *
     * Devuelve siempre la misma forma, venga por donde venga: así la
     * pantalla no sabe —ni tiene por qué saber— si detrás hay OData, un
     * CSV o nada.
     */
    public static function habitacion(string $numero): array
    {
        $numero = self::limpiarNumero($numero);
        if ($numero === '') {
            throw new ApiError('Falta el número de habitación.', 422);
        }

        $cacheada = self::deCache('hab:' . $numero);
        if ($cacheada !== null) {
            return $cacheada + ['de_cache' => true];
        }

        $c = self::config();
        $bruto = match ($c['tipo']) {
            'rest', 'odata' => self::porHttp($c, $numero),
            'soap'          => self::porSoap($c, $numero),
            'fichero'       => self::porFichero($c, $numero),
            default         => self::deMentira($numero),
        };

        $limpio = self::normalizar($bruto, $numero);
        self::aCache('hab:' . $numero, $limpio);
        return $limpio + ['de_cache' => false];
    }

    /**
     * Prueba de conexión.
     *
     * No dice sólo «va» o «no va»: dice qué ha pasado, porque el 90% de
     * las veces el problema es una URL con una barra de más o un usuario
     * sin permisos, y eso hay que poder verlo.
     */
    public static function probar(): array
    {
        $c = self::config();
        $pasos = [];
        $inicio = microtime(true);

        $pasos[] = ['paso' => 'Configuración', 'ok' => self::configurado(),
                    'detalle' => 'Tipo: ' . $c['tipo']
                               . ($c['url'] ? ' · ' . self::sinClaves($c['url']) : '')
                               . ($c['carpeta'] ? ' · ' . $c['carpeta'] : '')];

        if (!self::configurado()) {
            return ['ok' => false, 'pasos' => $pasos,
                    'resumen' => 'Falta rellenar la dirección o la carpeta.'];
        }

        try {
            $prueba = (string)Permisos::ajuste('sap_habitacion_prueba', '101');
            $r = self::habitacion($prueba);
            $pasos[] = ['paso' => 'Responde', 'ok' => true,
                        'detalle' => 'Habitación ' . $prueba . ': '
                                   . ($r['ocupada'] ? 'ocupada' : 'libre')
                                   . ($r['huespedes'] ? ' · ' . count($r['huespedes']) . ' huésped(es)' : '')];
            $pasos[] = ['paso' => 'Datos que llegan', 'ok' => true,
                        'detalle' => implode(', ', array_keys(array_filter($r, static fn($v) => $v !== null && $v !== [])))];
            $ms = (int)round((microtime(true) - $inicio) * 1000);
            $pasos[] = ['paso' => 'Tiempo', 'ok' => $ms < 4000, 'detalle' => $ms . ' ms'];

            return ['ok' => true, 'pasos' => $pasos,
                    'resumen' => $c['tipo'] === 'demo'
                        ? 'Funciona con datos de prueba. Cuando tengas los del hotel, cambia el tipo de conexión.'
                        : 'Conexión correcta.',
                    'ejemplo' => $r];
        } catch (Throwable $e) {
            $pasos[] = ['paso' => 'Responde', 'ok' => false, 'detalle' => $e->getMessage()];
            return ['ok' => false, 'pasos' => $pasos, 'resumen' => $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Las cuatro maneras                                                 */
    /* ------------------------------------------------------------------ */

    /** REST u OData: los dos son HTTP con JSON, cambia cómo se pide. */
    private static function porHttp(array $c, string $numero): array
    {
        $url = rtrim($c['url'], '/');
        if ($c['tipo'] === 'odata') {
            // SAP Gateway: se filtra por habitación y se pide JSON.
            $url .= "?\$filter=" . rawurlencode("Room eq '{$numero}'")
                  . ($c['hotel_sap'] ? rawurlencode(" and Hotel eq '{$c['hotel_sap']}'") : '')
                  . '&$format=json';
        } else {
            $url .= '/' . rawurlencode($numero)
                  . ($c['hotel_sap'] ? '?hotel=' . rawurlencode($c['hotel_sap']) : '');
        }

        $cab = ['Accept: application/json'];
        if ($c['usuario'] !== '') {
            $cab[] = 'Authorization: Basic ' . base64_encode($c['usuario'] . ':' . $c['clave']);
        } elseif ($c['clave'] !== '') {
            // Sin usuario, la clave se trata como testigo.
            $cab[] = 'Authorization: Bearer ' . $c['clave'];
        }
        if ($c['cliente'] !== '') { $cab[] = 'sap-client: ' . $c['cliente']; }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $cab,
            CURLOPT_TIMEOUT        => $c['segundos'],
            CURLOPT_CONNECTTIMEOUT => min(5, $c['segundos']),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo  = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false) {
            throw new ApiError('No se ha podido conectar: ' . ($fallo ?: 'sin respuesta'), 502);
        }
        if ($codigo === 401 || $codigo === 403) {
            throw new ApiError('SAP ha rechazado el usuario o la contraseña (' . $codigo . ').', 502);
        }
        if ($codigo >= 400) {
            throw new ApiError('SAP ha respondido ' . $codigo . '.', 502);
        }

        $datos = json_decode((string)$cuerpo, true);
        if (!is_array($datos)) {
            throw new ApiError('SAP ha respondido algo que no es JSON.', 502);
        }

        // OData envuelve el resultado; REST suele devolverlo pelado.
        if (isset($datos['d']['results'][0])) { return $datos['d']['results'][0]; }
        if (isset($datos['d']))                { return (array)$datos['d']; }
        if (isset($datos['value'][0]))         { return (array)$datos['value'][0]; }
        if (isset($datos[0]) && is_array($datos[0])) { return $datos[0]; }
        return $datos;
    }

    /** SOAP sobre RFC/BAPI. */
    private static function porSoap(array $c, string $numero): array
    {
        if (!class_exists('SoapClient')) {
            throw new ApiError('Este servidor no tiene SOAP; usa REST, OData o fichero.', 500);
        }
        try {
            $cliente = new SoapClient($c['url'], [
                'login'          => $c['usuario'] !== '' ? $c['usuario'] : null,
                'password'       => $c['clave'] !== '' ? $c['clave'] : null,
                'connection_timeout' => $c['segundos'],
                'exceptions'     => true,
                'cache_wsdl'     => WSDL_CACHE_MEMORY,
            ]);
            $funcion = (string)Permisos::ajuste('sap_funcion', 'RoomStatus');
            $r = $cliente->__soapCall($funcion, [[
                'Room'  => $numero,
                'Hotel' => $c['hotel_sap'],
            ]]);
            return json_decode(json_encode($r), true) ?: [];
        } catch (Throwable $e) {
            throw new ApiError('SOAP: ' . $e->getMessage(), 502);
        }
    }

    /**
     * Una carpeta donde el PMS deja los datos.
     *
     * La opción más tonta y la que más veces acaba siendo la buena: no
     * hace falta abrir puertos ni pedir permisos, el PMS escribe un
     * fichero cada diez minutos y aquí se lee.
     */
    private static function porFichero(array $c, string $numero): array
    {
        $dir = rtrim($c['carpeta'], '/\\');
        if (!is_dir($dir)) {
            throw new ApiError('No existe la carpeta ' . $dir, 502);
        }

        // Primero un fichero por habitación; si no, uno con todas.
        foreach ([$numero . '.json', $numero . '.JSON'] as $suelto) {
            if (is_file($dir . '/' . $suelto)) {
                $d = json_decode((string)file_get_contents($dir . '/' . $suelto), true);
                if (is_array($d)) { return $d; }
            }
        }

        /* Se miran TODOS los ficheros de la carpeta, del más reciente al
           más viejo. Antes sólo se leía el más nuevo, y bastaba con que
           el PMS dejara un JSON al lado de su CSV para que la mitad de
           las habitaciones «no existieran». */
        $candidatos = glob($dir . '/*.{json,csv,CSV,JSON}', GLOB_BRACE) ?: [];
        if (!$candidatos) {
            throw new ApiError('La carpeta no tiene ningún .json ni .csv.', 502);
        }
        usort($candidatos, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        require_once dirname(__DIR__, 2) . '/api/lib/Hoja.php';
        $claves = ['habitacion', 'room', 'numero', 'hab'];

        foreach ($candidatos as $fichero) {
            if (str_ends_with(strtolower($fichero), '.json')) {
                $todo = json_decode((string)file_get_contents($fichero), true);
                foreach (is_array($todo) ? $todo : [] as $fila) {
                    if (!is_array($fila)) { continue; }
                    foreach (['habitacion', 'Room', 'room', 'numero'] as $k) {
                        if (isset($fila[$k]) && self::limpiarNumero((string)$fila[$k]) === $numero) {
                            return $fila;
                        }
                    }
                }
                continue;
            }

            /* CSV: lo lee el mismo lector de hojas que el importador, que
               ya aguanta el punto y coma y la codificación de Excel. */
            try {
                foreach (Hoja::leer($fichero) as $fila) {
                    foreach ($claves as $k) {
                        if (isset($fila[$k]) && self::limpiarNumero((string)$fila[$k]) === $numero) {
                            return $fila;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Un fichero ilegible no impide mirar los demás.
                continue;
            }
        }

        throw new ApiError('La habitación ' . $numero . ' no sale en ningún fichero de la carpeta.', 404);
    }

    /** Datos de mentira, para montar la pantalla antes de tener SAP. */
    private static function deMentira(string $numero): array
    {
        $n = (int)preg_replace('/\D/', '', $numero) ?: 101;
        $ocupada = $n % 3 !== 0;
        $paises = ['ES' => 'Ana López', 'GB' => 'John Smith', 'DE' => 'Klaus Weber',
                   'FR' => 'Marie Dubois', 'NL' => 'Jan de Vries'];
        $pais = array_keys($paises)[$n % 5];
        $desde = gmdate('Y-m-d', strtotime('-' . ($n % 4 + 1) . ' days'));
        $hasta = gmdate('Y-m-d', strtotime('+' . ($n % 3 + 1) . ' days'));

        return [
            'habitacion'  => $numero,
            'ocupada'     => $ocupada,
            'estado'      => $ocupada ? 'ocupada' : (($n % 2) ? 'limpia' : 'sucia'),
            'huespedes'   => $ocupada ? [['nombre' => $paises[$pais], 'pais' => $pais]] : [],
            'desde'       => $ocupada ? $desde : null,
            'hasta'       => $ocupada ? $hasta : null,
            'adultos'     => $ocupada ? 1 + ($n % 2) : 0,
            'ninos'       => $ocupada ? ($n % 3 === 1 ? 1 : 0) : 0,
            'no_molestar' => $ocupada && $n % 7 === 0,
            'salida_hoy'  => $ocupada && $n % 5 === 0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Poner orden en lo que llegue                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Cada PMS llama a las cosas de una manera. Aquí se traduce todo a
     * los mismos nombres, y se limpia: lo que venga de fuera va a acabar
     * pintado en una pantalla.
     */
    private static function normalizar(array $d, string $numero): array
    {
        $coger = static function (array $d, array $claves, $porDefecto = null) {
            foreach ($claves as $k) {
                foreach ([$k, strtolower($k), strtoupper($k), ucfirst($k)] as $v) {
                    if (isset($d[$v]) && $d[$v] !== '') { return $d[$v]; }
                }
            }
            return $porDefecto;
        };
        $texto = static fn($v, $max = 80) => $v === null ? null
            : mb_substr(strip_tags(trim((string)$v)), 0, $max);
        $fecha = static function ($v) {
            if (!$v) { return null; }
            // SAP a veces manda /Date(1690000000000)/
            if (preg_match('#/Date\((\d+)#', (string)$v, $m)) {
                return gmdate('Y-m-d', (int)((int)$m[1] / 1000));
            }
            $t = strtotime((string)$v);
            return $t ? gmdate('Y-m-d', $t) : null;
        };
        $si = static function ($v): bool {
            $s = strtolower(trim((string)$v));
            return in_array($s, ['1', 'true', 'x', 'si', 'sí', 'yes', 'y', 'ocupada', 'occupied'], true);
        };

        /* Los huéspedes pueden venir como lista, como uno suelto o como
           un nombre pelado. Las tres formas acaban igual. */
        $huespedes = [];
        $bruto = $coger($d, ['huespedes', 'guests', 'Guests', 'GuestList'], null);
        if (is_array($bruto)) {
            foreach ($bruto as $g) {
                if (is_array($g)) {
                    $nombre = $texto($coger($g, ['nombre', 'name', 'GuestName', 'LastName']));
                    $pais   = $texto($coger($g, ['pais', 'country', 'CountryCode', 'Nationality']), 2);
                } else {
                    $nombre = $texto($g);
                    $pais   = null;
                }
                if ($nombre) {
                    $huespedes[] = ['nombre' => $nombre, 'pais' => $pais ? strtoupper($pais) : null,
                                    'idioma' => self::idiomaDe($pais)];
                }
            }
        } else {
            $nombre = $texto($coger($d, ['huesped', 'guest', 'GuestName', 'nombre', 'name']));
            $pais   = $texto($coger($d, ['pais', 'country', 'CountryCode', 'Nationality']), 2);
            if ($nombre) {
                $huespedes[] = ['nombre' => $nombre, 'pais' => $pais ? strtoupper($pais) : null,
                                'idioma' => self::idiomaDe($pais)];
            }
        }

        $estado = strtolower((string)$texto($coger($d, ['estado', 'status', 'RoomStatus', 'HousekeepingStatus'], ''), 24));

        /* Cada PMS dice el estado en su idioma y con sus siglas. Se
           traduce a las cuatro palabras que entiende quien va a subir a
           la habitación; lo que no se reconozca se deja tal cual, que es
           mejor que inventarse una traducción. */
        $estado = [
            'occupied' => 'ocupada', 'oc' => 'ocupada', 'inhouse' => 'ocupada',
            'in_house' => 'ocupada', 'belegt' => 'ocupada',
            'vacant'   => 'libre',   'vac' => 'libre',    'free' => 'libre',
            'clean'    => 'limpia',  'cl'  => 'limpia',   'ready' => 'limpia',
            'dirty'    => 'sucia',   'di'  => 'sucia',
            'cleaning' => 'limpieza', 'pickup' => 'limpieza',
            'ooo'      => 'bloqueada', 'out_of_order' => 'bloqueada',
            'oos'      => 'bloqueada', 'blocked' => 'bloqueada',
        ][$estado] ?? $estado;
        $ocupada = $coger($d, ['ocupada', 'occupied', 'Occupied', 'IsOccupied'], null);
        $ocupada = $ocupada !== null ? $si($ocupada)
                 : ($estado !== '' ? in_array($estado, ['ocupada', 'occupied', 'inhouse', 'in_house'], true)
                                   : $huespedes !== []);

        return [
            'habitacion'  => $numero,
            'ocupada'     => $ocupada,
            'estado'      => $estado ?: ($ocupada ? 'ocupada' : 'libre'),
            'huespedes'   => $huespedes,
            'desde'       => $fecha($coger($d, ['desde', 'from', 'ArrivalDate', 'CheckIn', 'checkin'])),
            'hasta'       => $fecha($coger($d, ['hasta', 'to', 'DepartureDate', 'CheckOut', 'checkout'])),
            'adultos'     => (int)$coger($d, ['adultos', 'adults', 'Adults'], 0),
            'ninos'       => (int)$coger($d, ['ninos', 'children', 'Children'], 0),
            'no_molestar' => $si($coger($d, ['no_molestar', 'dnd', 'DoNotDisturb'], '')),
            'salida_hoy'  => $si($coger($d, ['salida_hoy', 'departure_today', 'DepartureToday'], '')),
        ];
    }

    /** Del país al idioma, que es para lo que se pregunta el país. */
    public static function idiomaDe(?string $pais): ?string
    {
        if (!$pais) { return null; }
        return [
            'ES' => 'español', 'MX' => 'español', 'AR' => 'español', 'CO' => 'español',
            'GB' => 'inglés', 'US' => 'inglés', 'IE' => 'inglés', 'CA' => 'inglés',
            'AU' => 'inglés', 'DE' => 'alemán', 'AT' => 'alemán', 'CH' => 'alemán',
            'FR' => 'francés', 'BE' => 'francés', 'IT' => 'italiano', 'PT' => 'portugués',
            'BR' => 'portugués', 'NL' => 'neerlandés', 'SE' => 'sueco', 'NO' => 'noruego',
            'DK' => 'danés', 'FI' => 'finés', 'PL' => 'polaco', 'RU' => 'ruso',
            'JP' => 'japonés', 'CN' => 'chino', 'MA' => 'árabe',
        ][strtoupper($pais)] ?? null;
    }

    /* ------------------------------------------------------------------ */

    private static function limpiarNumero(string $n): string
    {
        return preg_replace('/[^A-Za-z0-9.\-]/', '', trim($n)) ?? '';
    }

    /** No enseñar contraseñas metidas en una URL. */
    private static function sinClaves(string $url): string
    {
        return preg_replace('#://[^/@]*@#', '://…@', $url) ?? $url;
    }

    /* Un poco de memoria: preguntar a SAP por la misma habitación
       cincuenta veces mientras se escribe un parte no ayuda a nadie. */
    private static function deCache(string $clave): ?array
    {
        $min = self::config()['cache_min'];
        if ($min <= 0) { return null; }
        $f = sys_get_temp_dir() . '/hurakantick-sap-' . md5($clave) . '.json';
        if (!is_file($f) || (time() - filemtime($f)) > $min * 60) { return null; }
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    private static function aCache(string $clave, array $datos): void
    {
        if (self::config()['cache_min'] <= 0) { return; }
        @file_put_contents(sys_get_temp_dir() . '/hurakantick-sap-' . md5($clave) . '.json',
                           json_encode($datos, JSON_UNESCAPED_UNICODE));
    }
}
