<?php
/**
 * Alta y salida de personas por ficheros de texto.
 *
 * Para cuando hay que dar de alta a cuarenta personas y hacerlo una por
 * una en la pantalla es media mañana. Se rellena un fichero por persona,
 * se dejan todos en «bak_config/upload/user/» y la aplicación los
 * recorre.
 *
 * ──────────────────────────────────────────────────────────────────────
 * LA PLANTILLA NO SE ESCRIBE A MANO, SE GENERA.
 *
 * Es la decisión que sostiene todo lo demás. Una plantilla fija obligaría
 * a saber cómo se llaman los departamentos de ESTE hotel y a escribirlos
 * sin una falta. Así que la plantilla sale de la base de datos con todos
 * los valores ya puestos, y quien la rellena sólo marca:
 *
 *     Departamento:
 *       [ ] Servicios Técnicos
 *       [x] Pisos / Limpieza
 *       [ ] Recepción
 *
 * No hay que saber nada ni escribir nada que pueda estar mal. Y si mañana
 * se añade un departamento, la plantilla lo trae sola.
 *
 * ──────────────────────────────────────────────────────────────────────
 * EL FORMATO, en tres reglas:
 *
 *   · una etiqueta es una línea pegada al margen que acaba en «:»
 *   · su valor son las líneas de debajo, con sangría
 *   · las que empiezan por «#» son notas y no se leen
 *
 * Un texto largo son varias líneas con sangría, sin más ceremonia. No
 * hay comillas, ni comas, ni llaves: un fichero así lo rellena cualquiera
 * desde el móvil, y eso era el objetivo.
 *
 * ──────────────────────────────────────────────────────────────────────
 * SEGURIDAD
 *
 * El fichero de alta lleva dentro la contraseña inicial, así que en
 * cuanto la persona está creada SE BORRA. Si falla, no: se queda con la
 * extensión cambiada a «.error» y un «.motivo.txt» al lado diciendo qué
 * corregir, porque un fichero que desaparece sin explicación es peor que
 * uno que se queda.
 *
 * Lo que sale por la exportación NUNCA lleva contraseña: pone «****».
 */
declare(strict_types=1);

/* Tickets no se carga para todos —la pide cada ruta que la necesita—, y
   aquí se usa para escribir las fechas en la hora del hotel. Se pide
   desde dentro a propósito: una clase que depende de quién la incluya es
   justo lo que rompió «exportar fichas» con «Class "Tickets" not found».
   Con require_once no estorba aunque ya esté cargada. */
require_once __DIR__ . '/Tickets.php';

final class Personal
{
    /** Los papeles, tal y como se llaman en la pantalla. */
    public const ROLES = [
        'admin'       => 'Administrador',
        'responsable' => 'Responsable',
        'tecnico'     => 'Técnico',
        'reportador'  => 'Sólo avisar',
    ];

    /** Las capacidades sueltas, con el texto que ve una persona. */
    public const PERMISOS = [
        'offline_apagar'            => 'Puede desactivar el modo sin cobertura',
        'asignar_companero'         => 'Puede asignar un parte a un compañero',
        'asignar_responsable'       => 'Puede asignar un parte a un responsable',
        'asignar_otro_departamento' => 'Puede asignar a otro departamento',
    ];

    /** Lo que se pone en lugar de la contraseña al exportar. */
    public const SIN_CLAVE = '****';

    /* ================================================================ */
    /* Las carpetas                                                     */
    /* ================================================================ */

    public static function carpeta(string $cual): string
    {
        $base = dirname(__DIR__, 2) . '/bak_config';
        $ruta = match ($cual) {
            'entrada' => $base . '/upload/user',
            'salida'  => $base . '/download/user',
            default   => $base,
        };
        if (!is_dir($ruta)) {
            @mkdir($ruta, 0750, true);
            self::protegerCarpeta($base);
        }
        return $ruta;
    }

    /**
     * Dejar el .htaccess puesto si falta.
     *
     * Las carpetas vienen en el paquete, pero un despliegue por FTP se
     * puede dejar los ficheros que empiezan por punto —hay clientes que
     * los ocultan— y entonces la carpeta quedaría abierta por web con
     * contraseñas dentro. Se vuelve a escribir y se acabó.
     */
    public static function protegerCarpeta(string $base): void
    {
        $contenido = <<<'HT'
# Nada de aquí se sirve por web: hay contraseñas iniciales dentro.
# Cada directiva va en su <IfModule> a propósito: una que el Apache de
# turno no entienda devuelve un 500 y se lleva lo que haya debajo.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    <IfModule mod_authz_host.c>
        Order allow,deny
        Deny from all
    </IfModule>
</IfModule>
Options -Indexes
<IfModule mod_mime.c>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .pht .phar
    AddType text/plain .php .phtml .phar .pht
</IfModule>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
HT;
        foreach (['', '/upload', '/download', '/upload/user', '/download/user'] as $sub) {
            $d = $base . $sub;
            if (!is_dir($d)) { @mkdir($d, 0750, true); }
            $f = $d . '/.htaccess';
            if (!is_file($f)) { @file_put_contents($f, $contenido . "\n"); }
        }
    }

    /** ¿Se puede trabajar con estas carpetas? */
    public static function revisarCarpetas(): array
    {
        $base = dirname(__DIR__, 2) . '/bak_config';
        self::protegerCarpeta($base);

        $out = [];
        foreach (['entrada' => 'upload/user', 'salida' => 'download/user'] as $cual => $rel) {
            $d = $base . '/' . $rel;
            $out[$cual] = [
                'ruta'     => 'bak_config/' . $rel,
                'existe'   => is_dir($d),
                'escribe'  => is_dir($d) && is_writable($d),
                'protegida'=> is_file($d . '/.htaccess'),
            ];
        }
        return $out;
    }

    /* ================================================================ */
    /* La plantilla                                                     */
    /* ================================================================ */

    /**
     * La plantilla de alta, con los valores de este hotel ya puestos.
     *
     * @param array|null $de si se pasa una persona, viene rellenada con
     *                       sus datos: eso es lo que usa la exportación.
     */
    public static function plantilla(?array $de = null): string
    {
        $exportando = $de !== null;

        $hoteles = Db::tablaExiste('hoteles')
            ? Db::all('SELECT id_hotel, nombre FROM hoteles ORDER BY nombre') : [];
        $deptos = Db::tablaExiste('departamentos')
            ? Db::all('SELECT id_departamento, nombre FROM departamentos
                        WHERE COALESCE(activo,1) = 1 ORDER BY orden, nombre') : [];

        $extra = $exportando && Db::tablaExiste('login_departamento')
            ? Permisos::departamentosDe((int)$de['id_login']) : [];
        $permisos = $exportando && Db::tablaExiste('login_permisos')
            ? array_column(Db::all('SELECT permiso FROM login_permisos WHERE id_login = ?',
                                   [(int)$de['id_login']]), 'permiso')
            : [];

        $L = [];   // las líneas que se devuelven

        /* ---- la cabecera, que es media ayuda ---- */
        $L[] = '# ══════════════════════════════════════════════════════════════';
        $L[] = $exportando
            ? '#  HuraKan Tick · FICHA DE UNA PERSONA'
            : '#  HuraKan Tick · ALTA DE UNA PERSONA';
        $L[] = '#';
        if ($exportando) {
            $L[] = '#  Exportado el ' . Tickets::enHoraLocal(Db::now()) . '.';
            $L[] = '#  La contraseña NO sale nunca: donde iba, pone ' . self::SIN_CLAVE;
            $L[] = '#';
            $L[] = '#  Este fichero se puede corregir y volver a meter por';
            $L[] = '#  bak_config/upload/user/ para actualizar a esta persona.';
            $L[] = '#  Si dejas la contraseña en ' . self::SIN_CLAVE . ', no se le cambia.';
        } else {
            $L[] = '#  CÓMO SE RELLENA — tres reglas y ya:';
            $L[] = '#';
            $L[] = '#    1. Escribe debajo de cada etiqueta, con la sangría que';
            $L[] = '#       ya está puesta.';
            $L[] = '#    2. En las listas, marca con  [x]  y deja  [ ]  el resto.';
            $L[] = '#    3. Las líneas que empiezan por # son notas: no se leen.';
            $L[] = '#';
            $L[] = '#  No borres las etiquetas ni cambies su texto.';
            $L[] = '#  Lo que dejes en blanco se queda sin poner.';
            $L[] = '#';
            $L[] = '#  Guarda una copia por persona en  bak_config/upload/user/';
            $L[] = '#  con cualquier nombre que acabe en .txt  (por ejemplo,';
            $L[] = '#  marta.txt). Al darla de alta, el fichero se borra: lleva';
            $L[] = '#  su contraseña dentro.';
        }
        $L[] = '# ══════════════════════════════════════════════════════════════';
        $L[] = '';

        /* ---- quién es ---- */
        $L[] = '# ── Quién es ──────────────────────────────────────────────────';
        $L[] = '';

        if ($hoteles) {
            $L[] = 'Hotel:';

            /* Con varios hoteles NO se marca el primero de la lista.
             *
             * Venía marcado el primero por orden, que no es el de nadie:
             * quien rellena cincuenta fichas seguidas no se fija en una
             * casilla que ya está puesta, y las cincuenta personas acaban
             * en el hotel equivocado. Darse cuenta es un día de trabajo
             * para deshacerlo. Así que se marca el hotel en el que está
             * quien se baja la plantilla, y se dice que es eso. */
            $suyo = (Db::tablaExiste('hoteles') && class_exists('Hoteles'))
                ? (int)(Hoteles::actual() ?? 0) : 0;
            $actual = $exportando
                ? (int)($de['id_hotel'] ?? 0)
                : (count($hoteles) === 1 ? (int)($hoteles[0]['id_hotel'] ?? 0) : $suyo);

            if (count($hoteles) === 1) {
                $L[] = '# Sólo hay uno: déjalo marcado.';
            } elseif ($actual > 0) {
                $nombreSuyo = '';
                foreach ($hoteles as $h) {
                    if ((int)$h['id_hotel'] === $actual) { $nombreSuyo = (string)$h['nombre']; }
                }
                $L[] = '# Viene marcado «' . $nombreSuyo . '», que es en el que estás.';
                $L[] = '# Cámbialo si esta ficha es de otro hotel.';
            } else {
                $L[] = '# Marca uno. Sin hotel no se puede dar de alta.';
            }
            foreach ($hoteles as $h) {
                $L[] = self::casilla((int)$h['id_hotel'] === $actual, (string)$h['nombre']);
            }
            $L[] = '';
        }

        $L[] = 'Nombre de usuario:';
        $L[] = '# Con el que entra. Sin espacios ni acentos. Al menos 3 letras.';
        $L[] = self::valor($exportando ? (string)($de['usuario'] ?? '') : '');
        $L[] = '';

        $L[] = 'Correo:';
        $L[] = '# Hace falta para los avisos y para la copia a Teams.';
        $L[] = self::valor($exportando ? (string)($de['correo'] ?? '') : '');
        $L[] = '';

        $L[] = 'Contraseña inicial:';
        $L[] = $exportando
            ? '# No se exporta. Déjalo así y no se le cambia.'
            : '# Al menos 8 caracteres. Que la cambie ella al entrar.';
        $L[] = self::valor($exportando ? self::SIN_CLAVE : '');
        $L[] = '';

        $L[] = 'Nombre:';
        $L[] = self::valor($exportando ? (string)($de['nombre'] ?? '') : '');
        $L[] = '';

        $L[] = 'Primer apellido:';
        $L[] = self::valor($exportando ? (string)($de['apellido1'] ?? '') : '');
        $L[] = '';

        $L[] = 'Segundo apellido:';
        $L[] = '# Opcional. En el hotel hay dos Garcías y tres Martín.';
        $L[] = self::valor($exportando ? (string)($de['apellido2'] ?? '') : '');
        $L[] = '';

        $L[] = 'Teléfono:';
        $L[] = '# Opcional. Sirve para que un compañero pueda llamarle.';
        $L[] = self::valor($exportando ? (string)($de['telefono'] ?? '') : '');
        $L[] = '';

        /* ---- qué puede hacer ---- */
        $L[] = '# ── Qué puede hacer ──────────────────────────────────────────';
        $L[] = '';

        $L[] = 'Permisos:';
        $L[] = '# Marca UNO. Es el papel principal.';
        $L[] = '#   Administrador  lo cambia todo, incluida la configuración';
        $L[] = '#   Responsable    reparte trabajo y ve todos los partes';
        $L[] = '#   Técnico        atiende los partes que le toquen';
        $L[] = '#   Sólo avisar    abre partes y los sigue, pero no los trabaja';
        $rolActual = $exportando ? (string)($de['rol'] ?? 'tecnico') : 'tecnico';
        foreach (self::ROLES as $clave => $texto) {
            $L[] = self::casilla($clave === $rolActual, $texto);
        }
        $L[] = '';

        if ($deptos) {
            $L[] = 'Departamento:';
            $L[] = '# Marca uno: el suyo, donde trabaja.';
            $suyo = $exportando ? (int)($de['id_departamento'] ?? 0) : 0;
            foreach ($deptos as $d) {
                $L[] = self::casilla((int)$d['id_departamento'] === $suyo, (string)$d['nombre']);
            }
            $L[] = '';

            $L[] = 'Departamentos adicionales:';
            $L[] = '# Marca los que hagan falta, o ninguno. Para quien echa una';
            $L[] = '# mano en otro sitio: verá y podrá trabajar también esos partes.';
            foreach ($deptos as $d) {
                $L[] = self::casilla(in_array((int)$d['id_departamento'], $extra, true),
                                     (string)$d['nombre']);
            }
            $L[] = '';
        }

        foreach (self::PERMISOS as $clave => $texto) {
            $L[] = $texto . ':';
            $L[] = '# Si no marcas nada, se aplica lo que diga la configuración';
            $L[] = '# del hotel. Marcar «Sí» se lo da a esta persona aunque allí';
            $L[] = '# esté apagado.';
            $tiene = in_array($clave, $permisos, true);
            $L[] = self::casilla($exportando && $tiene, 'Sí');
            $L[] = self::casilla($exportando && !$tiene, 'No');
            $L[] = '';
        }

        /* ---- lo que se le enseña ---- */
        $L[] = '# ── Mensajes para esta persona ───────────────────────────────';
        $L[] = '#';
        $L[] = '# Esto es opcional. Son los textos que verá al entrar.';
        $L[] = '';

        $L[] = 'Mensaje de bienvenida:';
        $L[] = '# Se enseña UNA SOLA VEZ, la primera que entra. Puede ocupar';
        $L[] = '# varias líneas: todas las que tengan sangría.';
        $L[] = self::valor($exportando ? (string)($de['mensaje_bienvenida'] ?? '') : '', true);
        $L[] = '';

        $L[] = 'Aviso para el próximo inicio:';
        $L[] = '# Se enseña la próxima vez que entre, y se borra solo.';
        $L[] = '# Para un recado puntual: «pásate por el almacén».';
        $L[] = self::valor($exportando ? (string)($de['mensaje_proximo'] ?? '') : '', true);
        $L[] = '';

        $L[] = 'Frase del día:';
        $L[] = '# Si se le enseña una frase al entrar.';
        $frases = $exportando && (int)($de['frases_activas'] ?? 0) === 1;
        $L[] = self::casilla($exportando && $frases, 'Sí');
        $L[] = self::casilla($exportando && !$frases, 'No');
        $L[] = '';

        $L[] = '# ── Fin ──────────────────────────────────────────────────────';

        return implode("\n", $L) . "\n";
    }

    /** Una casilla, marcada o no. */
    private static function casilla(bool $marcada, string $texto): string
    {
        return '  [' . ($marcada ? 'x' : ' ') . '] ' . $texto;
    }

    /** Un valor escrito, con su sangría. Vacío deja la línea preparada. */
    private static function valor(string $v, bool $largo = false): string
    {
        $v = trim($v);
        if ($v === '') {
            return '  ';
        }
        // Un texto de varias líneas lleva la sangría en todas.
        $lineas = preg_split('/\r\n|\r|\n/', $v) ?: [];
        return implode("\n", array_map(static fn($l) => '  ' . $l, $lineas));
    }

    /* ================================================================ */
    /* Leer una plantilla rellenada                                     */
    /* ================================================================ */

    /**
     * De texto a datos.
     *
     * Devuelve ['campos' => [etiqueta => valor], 'marcados' => [etiqueta
     * => [textos]], 'errores' => [...]]. Lo que no entiende lo dice, en
     * vez de dar por bueno un fichero a medias.
     */
    public static function analizar(string $texto): array
    {
        // Un BOM al principio hace que la primera etiqueta no case.
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;

        $campos = [];
        $marcados = [];
        $etiqueta = null;

        foreach (preg_split('/\r\n|\r|\n/', $texto) ?: [] as $linea) {
            $limpia = rtrim($linea);

            // Notas y líneas en blanco.
            if (ltrim($limpia) === '' || str_starts_with(ltrim($limpia), '#')) {
                continue;
            }

            /* ¿Etiqueta? La que acaba en «:» y coincide con una de las
               que la plantilla pone.
               Antes exigía además que estuviera pegada al margen, y la
               sangría de las demás líneas decidía si eran valor o no. Eso
               es frágil de más: hay editores que quitan los espacios al
               guardar, y entonces el valor pasaba a parecer otra cosa y
               se perdía en silencio. Ahora la sangría es decoración: lo
               que manda es si la línea es una etiqueta conocida. */
            $puede = trim($limpia);
            if (str_ends_with($puede, ':')) {
                $nombre = trim(rtrim($puede, ':'));
                if (self::esEtiqueta($nombre)) {
                    $etiqueta = $nombre;
                    $campos[$etiqueta] ??= '';
                    $marcados[$etiqueta] ??= [];
                    continue;
                }
            }

            if ($etiqueta === null) { continue; }

            $cuerpo = trim($limpia);

            // ¿Una casilla?
            if (preg_match('/^\[([ xX·*\.]?)\]\s*(.*)$/u', $cuerpo, $m)) {
                $puesta = trim($m[1]) !== '';
                if ($puesta && trim($m[2]) !== '') {
                    $marcados[$etiqueta][] = trim($m[2]);
                }
                continue;
            }

            // Texto normal: se va juntando por líneas.
            $campos[$etiqueta] = $campos[$etiqueta] === ''
                ? $cuerpo
                : $campos[$etiqueta] . "\n" . $cuerpo;
        }

        return ['campos' => $campos, 'marcados' => $marcados, 'errores' => []];
    }

    /**
     * Las etiquetas que la plantilla usa.
     *
     * Se compara contra esta lista en vez de aceptar cualquier línea que
     * acabe en «:». Si no, una frase del mensaje de bienvenida que
     * terminase en dos puntos —«Te dejo esto:»— cortaría el texto ahí y
     * se tragaría el resto.
     */
    private static function esEtiqueta(string $nombre): bool
    {
        static $lista = null;
        if ($lista === null) {
            $lista = array_merge(
                ['Hotel', 'Nombre de usuario', 'Correo', 'Contraseña inicial',
                 'Nombre', 'Primer apellido', 'Segundo apellido', 'Teléfono',
                 'Permisos', 'Departamento', 'Departamentos adicionales',
                 'Mensaje de bienvenida', 'Aviso para el próximo inicio',
                 'Frase del día'],
                array_values(self::PERMISOS));
            $lista = array_map(static fn($s) => mb_strtolower($s), $lista);
        }
        return in_array(mb_strtolower(trim($nombre)), $lista, true);
    }

    /** El sí/no de una etiqueta: casilla, o «true/false» escrito a mano. */
    private static function siNo(array $leido, string $etiqueta): ?bool
    {
        foreach ($leido['marcados'][$etiqueta] ?? [] as $m) {
            $t = mb_strtolower(trim($m));
            if (in_array($t, ['sí', 'si', 'true', '1', 'x'], true))  { return true; }
            if (in_array($t, ['no', 'false', '0'], true))            { return false; }
        }
        /* Y si alguien escribió «true» en vez de marcar: se acepta. Es
           lo que pondría quien viene de una hoja de cálculo, y rechazarlo
           por la forma sería absurdo. */
        $t = mb_strtolower(trim($leido['campos'][$etiqueta] ?? ''));
        if ($t === '') { return null; }
        if (in_array($t, ['sí', 'si', 'true', '1'], true))  { return true; }
        if (in_array($t, ['no', 'false', '0'], true))       { return false; }
        return null;
    }

    /** Lo único marcado de una lista, o null si no hay nada o hay de más. */
    private static function unico(array $leido, string $etiqueta, array &$errores): ?string
    {
        $m = $leido['marcados'][$etiqueta] ?? [];
        if (count($m) > 1) {
            $errores[] = "En «{$etiqueta}» hay " . count($m) . ' cosas marcadas y sólo puede haber una.';
            return null;
        }
        if ($m) { return trim($m[0]); }
        // Escrito a mano también vale.
        $t = trim($leido['campos'][$etiqueta] ?? '');
        return $t !== '' ? $t : null;
    }

    /** Busca una fila por su nombre, sin fijarse en mayúsculas ni acentos. */
    private static function porNombre(array $filas, string $campoId, string $campoNombre,
                                      ?string $buscado): ?int
    {
        if ($buscado === null || trim($buscado) === '') { return null; }
        $plano = static fn(string $s): string => mb_strtolower(trim(
            strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u'])));
        foreach ($filas as $f) {
            if ($plano((string)$f[$campoNombre]) === $plano($buscado)) {
                return (int)$f[$campoId];
            }
        }
        return null;
    }

    /**
     * De un fichero rellenado a los datos de una persona, ya validados.
     *
     * @return array{datos:array, extra:array, permisos:array, errores:array}
     */
    public static function interpretar(string $texto): array
    {
        $leido = self::analizar($texto);
        $errores = [];

        $hoteles = Db::tablaExiste('hoteles')
            ? Db::all('SELECT id_hotel, nombre FROM hoteles') : [];
        $deptos = Db::tablaExiste('departamentos')
            ? Db::all('SELECT id_departamento, nombre FROM departamentos') : [];

        $campo = static fn(string $e): string => trim($leido['campos'][$e] ?? '');

        /* ---- lo imprescindible ---- */
        $usuario = $campo('Nombre de usuario');
        if ($usuario === '') {
            $errores[] = 'Falta el nombre de usuario: sin él no puede entrar.';
        } elseif (mb_strlen($usuario) < 3) {
            $errores[] = 'El nombre de usuario necesita al menos 3 caracteres.';
        }

        $correo = mb_strtolower($campo('Correo'));
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "El correo «{$correo}» no tiene un formato válido.";
        }

        $clave = $campo('Contraseña inicial');
        $mantener = ($clave === self::SIN_CLAVE || $clave === '');
        if (!$mantener && mb_strlen($clave) < 8) {
            $errores[] = 'La contraseña inicial debe tener al menos 8 caracteres.';
        }

        /* ---- el papel ---- */
        $rolTexto = self::unico($leido, 'Permisos', $errores);
        $rol = null;
        foreach (self::ROLES as $c => $t) {
            if ($rolTexto !== null && mb_strtolower($t) === mb_strtolower($rolTexto)) { $rol = $c; }
        }
        if ($rolTexto !== null && $rol === null) {
            $errores[] = "En «Permisos» pone «{$rolTexto}», que no es ninguno de los que hay: "
                       . implode(', ', self::ROLES) . '.';
        }

        /* ---- hotel y departamentos ---- */
        $idHotel = null;
        if ($hoteles) {
            $nombreHotel = self::unico($leido, 'Hotel', $errores);
            $idHotel = self::porNombre($hoteles, 'id_hotel', 'nombre', $nombreHotel);
            if ($nombreHotel !== null && $idHotel === null) {
                $errores[] = "El hotel «{$nombreHotel}» no existe. Los que hay: "
                           . implode(', ', array_column($hoteles, 'nombre')) . '.';
            }
        }

        $idDepto = null;
        if ($deptos) {
            $nombreDep = self::unico($leido, 'Departamento', $errores);
            $idDepto = self::porNombre($deptos, 'id_departamento', 'nombre', $nombreDep);
            if ($nombreDep !== null && $idDepto === null) {
                $errores[] = "El departamento «{$nombreDep}» no existe. Los que hay: "
                           . implode(', ', array_column($deptos, 'nombre')) . '.';
            }
        }

        $extra = [];
        foreach ($leido['marcados']['Departamentos adicionales'] ?? [] as $n) {
            $i = self::porNombre($deptos, 'id_departamento', 'nombre', $n);
            if ($i === null) {
                $errores[] = "En «Departamentos adicionales» pone «{$n}», que no existe.";
            } elseif ($i !== $idDepto) {
                // El suyo propio no cuenta como adicional.
                $extra[] = $i;
            }
        }

        /* ---- las capacidades sueltas ---- */
        $permisos = [];
        /* OJO con el nombre de esta variable.
           Estaba escrito «$clave», que es como se llama la contraseña
           veinte líneas más arriba: el bucle se la machacaba y TODA
           persona importada acababa con «asignar_otro_departamento» de
           contraseña. La misma para todas, y una cadena que está en el
           código. Lo cazó la prueba de que se puede entrar después. */
        foreach (self::PERMISOS as $permiso => $etiqueta) {
            if (self::siNo($leido, $etiqueta) === true) { $permisos[] = $permiso; }
        }

        /* ---- los mensajes ---- */
        $bienvenida = $campo('Mensaje de bienvenida');
        $proximo    = $campo('Aviso para el próximo inicio');
        $frases     = self::siNo($leido, 'Frase del día');

        /* Si el fichero no trae NINGUNA etiqueta conocida, es que no es
           una plantilla: mejor decirlo que dar de alta a «» con todo
           vacío. Me pasó probando con un fichero de notas. */
        if (!array_intersect(array_keys($leido['campos']),
                             ['Nombre de usuario', 'Correo', 'Nombre'])) {
            $errores[] = 'Esto no parece una plantilla de HuraKan Tick: no tiene ninguna de '
                       . 'sus etiquetas. Descarga la plantilla desde la pantalla de '
                       . 'Importar/Exportar y rellena esa.';
        }

        return [
            'leido' => $leido,
            'datos' => [
                'usuario'   => $usuario,
                'correo'    => $correo,
                'clave'     => $mantener ? null : $clave,
                'nombre'    => $campo('Nombre'),
                'apellido1' => $campo('Primer apellido'),
                'apellido2' => $campo('Segundo apellido'),
                'telefono'  => $campo('Teléfono'),
                'rol'       => $rol,
                'id_hotel'  => $idHotel,
                'id_departamento' => $idDepto,
                'mensaje_bienvenida' => $bienvenida,
                'mensaje_proximo'    => $proximo,
                'frases_activas'     => $frases,
            ],
            'extra'    => $extra,
            'permisos' => $permisos,
            'errores'  => $errores,
        ];
    }

    /* ================================================================ */
    /* Dar de alta —o actualizar— a partir de un fichero                */
    /* ================================================================ */

    /**
     * Crear o actualizar a una persona con lo que diga el fichero.
     *
     * Si ya existe alguien con ese nombre de usuario, se ACTUALIZA en vez
     * de fallar. Es lo que hace útil el viaje de ida y vuelta: exportas,
     * corriges cuarenta fichas en un editor, las vuelves a meter.
     *
     * @return array{creado:bool, id:int, nombre:string}
     */
    public static function altaDesde(array $interpretado, int $idAutor): array
    {
        $d = $interpretado['datos'];

        if ($interpretado['errores']) {
            throw new RuntimeException(implode(' ', $interpretado['errores']));
        }

        $plano = mb_strtolower(trim($d['usuario']));
        $ya = Db::one('SELECT id_login, usuario, admin, superadmin FROM login
                        WHERE usuario_plano = ? OR LOWER(usuario) = ?', [$plano, $plano]);

        /* El correo no puede estar en dos cuentas. Se comprueba aquí y no
           sólo en la base: el mensaje de la base no le dice nada a nadie. */
        if ($d['correo'] !== '') {
            $otro = Db::one('SELECT id_login FROM login WHERE LOWER(correo) = ?', [$d['correo']]);
            if ($otro !== null && (int)$otro['id_login'] !== (int)($ya['id_login'] ?? 0)) {
                throw new RuntimeException(
                    'Ya hay otra cuenta con el correo ' . $d['correo'] . '.');
            }
        }

        $cols = Db::columnas('login');
        $fila = ['updated_at' => Db::now()];

        $poner = static function (string $col, $v) use (&$fila, $cols): void {
            if (in_array($col, $cols, true) && $v !== null && $v !== '') { $fila[$col] = $v; }
        };

        $poner('nombre',    mb_substr($d['nombre'], 0, 80));
        $poner('apellido1', mb_substr($d['apellido1'], 0, 80));
        $poner('apellido2', mb_substr($d['apellido2'] ?? '', 0, 80));
        $poner('telefono',  mb_substr($d['telefono'], 0, 32));
        $poner('correo',    $d['correo']);
        if ($d['rol'] !== null) {
            $fila['rol']   = $d['rol'];
            $fila['admin'] = $d['rol'] === 'admin' ? 1 : 0;
        }
        if ($d['id_hotel'] !== null && in_array('id_hotel', $cols, true)) {
            $fila['id_hotel'] = $d['id_hotel'];
        }
        if ($d['id_departamento'] !== null) {
            $fila['id_departamento'] = $d['id_departamento'];
        }
        if ($d['clave'] !== null) {
            $fila['pwd_php'] = Auth::hashPassword($d['clave']);
        }

        /* Los mensajes. El de bienvenida se enciende solo si se ha puesto
           texto: una bienvenida guardada y apagada no se vería y nadie
           entendería por qué. */
        if (in_array('mensaje_bienvenida', $cols, true) && $d['mensaje_bienvenida'] !== '') {
            $fila['mensaje_bienvenida'] = $d['mensaje_bienvenida'];
            $fila['bienvenida_activa']  = 1;
            $fila['bienvenida_vista']   = null;
        }
        if (in_array('mensaje_proximo', $cols, true) && $d['mensaje_proximo'] !== '') {
            $fila['mensaje_proximo']       = $d['mensaje_proximo'];
            $fila['mensaje_proximo_visto'] = null;
        }
        if (in_array('frases_activas', $cols, true) && $d['frases_activas'] !== null) {
            $fila['frases_activas'] = $d['frases_activas'] ? 1 : 0;
        }

        Db::begin();
        try {
            if ($ya !== null) {
                $id = (int)$ya['id_login'];
                /* A un superadministrador no se le toca el papel desde un
                   fichero: sería la forma más fácil de quitarle el mando a
                   quien manda, y desde fuera de la aplicación. */
                if ((int)($ya['superadmin'] ?? 0) === 1) {
                    unset($fila['rol'], $fila['admin']);
                }
                Db::update('login', $fila, 'id_login = :id', ['id' => $id]);
                $creado = false;
            } else {
                if ($d['clave'] === null) {
                    throw new RuntimeException(
                        'Es una persona nueva y no lleva contraseña inicial. '
                        . 'Pon una de al menos 8 caracteres.');
                }
                $fila['usuario']       = $d['usuario'];
                $fila['usuario_plano'] = $plano;
                $fila['rol']       ??= 'tecnico';
                $fila['admin']     ??= 0;
                $fila['bloqueo']     = 0;
                $fila['intentos']    = 0;
                $fila['fecha_alta']  = Db::now();
                if (in_array('id_hotel', $cols, true) && !isset($fila['id_hotel'])) {
                    $fila['id_hotel'] = Db::tablaExiste('hoteles') ? Hoteles::actual() : null;
                }
                $id = (int)Db::insert('login', $fila);
                $creado = true;
            }

            /* Departamentos adicionales y capacidades sueltas: se dejan
               exactamente como diga el fichero, no se suman a lo que
               hubiera. Si no, corregir una ficha no podría quitar nada. */
            if (Db::tablaExiste('login_departamento')) {
                Db::run('DELETE FROM login_departamento WHERE id_login = ?', [$id]);
                foreach ($interpretado['extra'] as $idDep) {
                    Db::insertarSiFalta('login_departamento', [
                        'id_login' => $id, 'id_departamento' => (int)$idDep,
                        'creado' => Db::now(),
                    ]);
                }
            }
            if (Db::tablaExiste('login_permisos')) {
                Db::run('DELETE FROM login_permisos WHERE id_login = ?', [$id]);
                foreach ($interpretado['permisos'] as $p) {
                    if (!array_key_exists($p, self::PERMISOS)) { continue; }
                    Db::insertarSiFalta('login_permisos', [
                        'id_login' => $id, 'permiso' => $p, 'creado' => Db::now(),
                    ]);
                }
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $comoSeLlama = trim(($fila['nombre'] ?? '') . ' ' . ($fila['apellido1'] ?? ''))
                       ?: $d['usuario'];

        /* Queda anotado: un alta por fichero puede crear un administrador,
           y eso tiene que poder rastrearse. */
        Registro::anotar($creado ? 'alta' : 'cambio', $idAutor,
            ($creado ? 'Alta' : 'Actualización') . " por fichero de «{$d['usuario']}»"
            . ($d['rol'] !== null ? ' como ' . (self::ROLES[$d['rol']] ?? $d['rol']) : ''));

        return ['creado' => $creado, 'id' => $id, 'nombre' => $comoSeLlama];
    }

    /* ================================================================ */
    /* Recorrer la carpeta de entrada                                   */
    /* ================================================================ */

    /** Los .txt que hay esperando. */
    public static function pendientes(): array
    {
        $d = self::carpeta('entrada');
        $out = [];
        foreach (glob($d . '/*.txt') ?: [] as $f) {
            if (str_ends_with($f, '.motivo.txt')) { continue; }
            $out[] = ['fichero' => basename($f), 'bytes' => (int)filesize($f),
                      'fecha' => gmdate('Y-m-d H:i:s', (int)filemtime($f))];
        }
        return $out;
    }

    /**
     * Darlos de alta todos.
     *
     * Cada fichero va por su cuenta: si uno está mal, los demás siguen.
     * Hacerlo todo o nada sería peor —cuarenta altas detenidas por una
     * falta de ortografía en la quinta—.
     *
     * @param bool $simular dice qué haría, sin tocar nada ni borrar
     */
    public static function importar(int $idAutor, bool $simular = false): array
    {
        $dir = self::carpeta('entrada');
        $hechas = [];
        $malas  = [];

        foreach (glob($dir . '/*.txt') ?: [] as $ruta) {
            if (str_ends_with($ruta, '.motivo.txt')) { continue; }
            $nombre = basename($ruta);

            try {
                $texto = (string)file_get_contents($ruta);
                $leido = self::interpretar($texto);

                if ($simular) {
                    $hechas[] = ['fichero' => $nombre,
                                 'usuario' => $leido['datos']['usuario'],
                                 'errores' => $leido['errores'],
                                 'estado'  => $leido['errores'] ? 'daria error' : 'se daria de alta'];
                    continue;
                }

                $r = self::altaDesde($leido, $idAutor);

                /* Y se borra. Lleva la contraseña dentro: dejarlo ahí es
                   dejar una contraseña en un fichero de texto. */
                if (!@unlink($ruta)) {
                    $hechas[] = ['fichero' => $nombre, 'usuario' => $r['nombre'],
                                 'estado' => $r['creado'] ? 'creada' : 'actualizada',
                                 'aviso' => 'No se ha podido borrar el fichero: '
                                          . 'bórralo a mano, lleva la contraseña dentro.'];
                    continue;
                }

                $hechas[] = ['fichero' => $nombre, 'usuario' => $r['nombre'],
                             'estado' => $r['creado'] ? 'creada' : 'actualizada'];

            } catch (Throwable $e) {
                /* El fichero NO se borra: se aparta y se deja el motivo al
                   lado. Un fichero que desaparece sin explicación deja a
                   quien lo rellenó sin saber qué corregir. */
                $motivo = $e->getMessage();
                if (!$simular) {
                    @rename($ruta, $ruta . '.error');
                    @file_put_contents($ruta . '.motivo.txt',
                        "No se ha podido dar de alta a esta persona.\n\n"
                        . $motivo . "\n\n"
                        . "Corrige el fichero «" . $nombre . ".error», quítale el «.error»\n"
                        . "del final y vuelve a importar.\n");
                }
                $malas[] = ['fichero' => $nombre, 'motivo' => $motivo];
            }
        }

        return [
            'creadas'      => count(array_filter($hechas, static fn($h) => ($h['estado'] ?? '') === 'creada')),
            'actualizadas' => count(array_filter($hechas, static fn($h) => ($h['estado'] ?? '') === 'actualizada')),
            'hechas'       => $hechas,
            'malas'        => $malas,
            'simulado'     => $simular,
        ];
    }

    /* ================================================================ */
    /* Exportar                                                         */
    /* ================================================================ */

    /**
     * Escribir las fichas de esas personas en la carpeta de salida.
     *
     * Sin contraseña: donde iba, pone ****.
     */
    public static function exportar(array $ids): array
    {
        $dir = self::carpeta('salida');
        if (!is_writable($dir)) {
            throw new RuntimeException(
                'No se puede escribir en bak_config/download/user. '
                . 'Dale permiso de escritura a esa carpeta.');
        }

        $hechos = [];
        $sello = gmdate('Ymd-Hi');

        foreach ($ids as $id) {
            $id = (int)$id;
            $p = Db::one('SELECT * FROM login WHERE id_login = ?', [$id]);
            if ($p === null) { continue; }

            /* El nombre del fichero sale del usuario, limpio: un correo o
               un nombre con acentos y espacios da ficheros que después no
               hay manera de manejar por FTP. */
            $base = preg_replace('/[^a-z0-9._-]+/', '-',
                        mb_strtolower((string)($p['usuario'] ?: ('persona-' . $id))));
            $base = trim((string)$base, '-') ?: ('persona-' . $id);
            $fichero = $base . '.' . $sello . '.txt';

            $ok = @file_put_contents($dir . '/' . $fichero, self::plantilla($p));
            if ($ok === false) {
                throw new RuntimeException("No se ha podido escribir «{$fichero}».");
            }
            @chmod($dir . '/' . $fichero, 0640);

            $hechos[] = ['fichero' => $fichero, 'bytes' => (int)$ok,
                         'usuario' => (string)$p['usuario']];
        }

        return ['ficheros' => $hechos, 'cuantos' => count($hechos),
                'carpeta' => 'bak_config/download/user'];
    }

    /** Lo que hay en la carpeta de salida, para poder descargarlo. */
    public static function exportados(): array
    {
        $dir = self::carpeta('salida');
        $out = [];
        foreach (glob($dir . '/*.txt') ?: [] as $f) {
            $out[] = ['fichero' => basename($f), 'bytes' => (int)filesize($f),
                      'fecha' => gmdate('Y-m-d H:i:s', (int)filemtime($f))];
        }
        usort($out, static fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
        return $out;
    }

    /**
     * El contenido de un fichero de la carpeta de salida.
     *
     * El nombre se limpia a conciencia: con «../../api/config.php» se
     * leería la configuración del servidor, con la contraseña de la base
     * dentro.
     */
    public static function leerExportado(string $fichero): string
    {
        $limpio = basename(trim($fichero));
        if ($limpio === '' || !str_ends_with($limpio, '.txt')
            || !preg_match('/^[A-Za-z0-9._-]+$/', $limpio)) {
            throw new RuntimeException('Ese nombre de fichero no vale.');
        }
        $ruta = self::carpeta('salida') . '/' . $limpio;
        if (!is_file($ruta)) {
            throw new RuntimeException('Ese fichero ya no está.');
        }
        return (string)file_get_contents($ruta);
    }

    /** Quitar ficheros de la carpeta de salida. */
    public static function borrarExportados(array $ficheros): int
    {
        $n = 0;
        foreach ($ficheros as $f) {
            $limpio = basename(trim((string)$f));
            if (!preg_match('/^[A-Za-z0-9._-]+\.txt$/', $limpio)) { continue; }
            $ruta = self::carpeta('salida') . '/' . $limpio;
            if (is_file($ruta) && @unlink($ruta)) { $n++; }
        }
        return $n;
    }
}
