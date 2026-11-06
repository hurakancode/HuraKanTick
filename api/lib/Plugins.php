<?php
/**
 * Plugins: módulos opcionales que no tocan el corazón.
 *
 * Un plugin es una carpeta en plugins/ con un plugin.php que devuelve su
 * ficha. Nada más. Si la carpeta no está, si el fichero falla o si el
 * plugin está apagado, el sistema funciona exactamente igual: por eso
 * cada llamada va envuelta y un plugin roto nunca tumba la API.
 *
 * Tres enganches, y a propósito no más:
 *
 *   ajustes()   sus opciones, que salen en Configuración como las demás
 *   rutas()     qué secciones de /api atiende
 *   menu()      qué enseñar en Ajustes cuando está configurado
 *
 * La idea no es hacer WordPress. Es que integrar el SAP de un hotel no
 * obligue a meter mano en el alta de partes ni en el catálogo, y que
 * mañana se pueda quitar entero borrando una carpeta.
 */
declare(strict_types=1);

final class Plugins
{
    /** @var array<string,array>|null fichas ya leídas */
    private static ?array $fichas = null;

    /** La carpeta donde viven. */
    public static function carpeta(): string
    {
        return dirname(__DIR__, 2) . '/plugins';
    }

    /**
     * Todas las fichas, activas o no.
     *
     * @return array<string,array> por id de plugin
     */
    public static function todos(): array
    {
        if (self::$fichas !== null) {
            return self::$fichas;
        }
        self::$fichas = [];

        $dir = self::carpeta();
        if (!is_dir($dir)) {
            return self::$fichas;
        }

        foreach (scandir($dir) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..') { continue; }
            $ficha = $dir . '/' . $nombre . '/plugin.php';
            if (!is_file($ficha)) { continue; }

            try {
                $datos = require $ficha;
                if (!is_array($datos) || empty($datos['id'])) { continue; }
                $datos['carpeta'] = $dir . '/' . $nombre;
                self::$fichas[(string)$datos['id']] = $datos;
            } catch (Throwable $e) {
                // Un plugin mal escrito se ignora; el sistema sigue.
                error_log('Plugin ' . $nombre . ' no se ha podido leer: ' . $e->getMessage());
            }
        }
        return self::$fichas;
    }

    /** ¿Está encendido? De fábrica, todos apagados. */
    public static function activo(string $id): bool
    {
        if (!isset(self::todos()[$id])) {
            return false;
        }
        return Permisos::ajuste('plugin_' . $id . '_activo', false) === true
            || (string)Permisos::ajuste('plugin_' . $id . '_activo', '0') === '1';
    }

    /** Sólo los encendidos. */
    public static function activos(): array
    {
        return array_filter(self::todos(), static fn($f, $id) => self::activo((string)$id),
                            ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Las opciones de todos los plugins, para que salgan en Configuración.
     *
     * El interruptor de encendido lo pone el sistema: así no hay forma de
     * escribir un plugin que no se pueda apagar.
     */
    public static function ajustes(): array
    {
        $out = [];
        foreach (self::todos() as $id => $ficha) {
            $out[] = [
                'clave' => 'plugin_' . $id . '_activo',
                'valor' => '0',
                'tipo'  => 'interruptor',
                'categoria' => 'modulos',
                'titulo' => 'Módulo: ' . ($ficha['nombre'] ?? $id),
                'descripcion' => ($ficha['descripcion'] ?? '')
                    . ' Apagado, el sistema se comporta como si no existiera.',
                'opciones' => null,
                'orden' => 200,
            ];

            if (!is_callable($ficha['ajustes'] ?? null)) { continue; }
            try {
                foreach (($ficha['ajustes'])() as $aj) {
                    if (!empty($aj['clave'])) { $out[] = $aj; }
                }
            } catch (Throwable $e) {
                error_log('Ajustes del plugin ' . $id . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * ¿Atiende algún plugin activo esta sección de la API?
     *
     * Devuelve el fichero a cargar, o null. El despachador del núcleo lo
     * consulta al final, cuando ninguna ruta suya ha respondido.
     */
    public static function ruta(string $seccion): ?string
    {
        foreach (self::activos() as $id => $ficha) {
            $rutas = $ficha['rutas'] ?? [];
            if (!is_array($rutas)) { continue; }
            foreach ($rutas as $prefijo => $fichero) {
                if ($seccion !== $prefijo) { continue; }
                $ruta = $ficha['carpeta'] . '/' . $fichero;
                if (is_file($ruta)) { return $ruta; }
            }
        }
        return null;
    }

    /**
     * Lo que se enseña en Ajustes.
     *
     * Cada plugin decide si tiene algo que enseñar —el de SAP, por
     * ejemplo, no aparece hasta que alguien lo configura— y a quién.
     */
    public static function menu(array $usuario): array
    {
        $out = [];
        foreach (self::activos() as $id => $ficha) {
            if (!is_callable($ficha['menu'] ?? null)) { continue; }
            try {
                $m = ($ficha['menu'])($usuario);
                if (is_array($m) && $m) { $out[] = $m + ['id' => $id]; }
            } catch (Throwable $e) {
                error_log('Menú del plugin ' . $id . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * ¿Alcanza a esta persona una opción con este alcance?
     *
     * Las opciones de un plugin se pueden abrir a distinta gente: hay
     * datos que sólo debe ver quien lleva el hotel y otros que le sirven
     * al técnico que está delante de la habitación.
     */
    public static function alcanza(string $alcance, array $u): bool
    {
        return match ($alcance) {
            'nadie'         => false,
            'superadmin'    => (int)($u['superadmin'] ?? 0) === 1,
            'admin'         => Auth::esAdmin($u),
            'responsable'   => Auth::esResponsable($u),
            'tecnico'       => Auth::esResponsable($u) || ($u['rol'] ?? '') === 'tecnico',
            'todos'         => true,
            default         => false,
        };
    }

    /** Las opciones de alcance, para pintarlas en Configuración. */
    public static function opcionesAlcance(): array
    {
        return [
            ['v' => 'nadie',       't' => 'Nadie (desactivado)'],
            ['v' => 'superadmin',  't' => 'Sólo el superadministrador'],
            ['v' => 'admin',       't' => 'Administradores'],
            ['v' => 'responsable', 't' => 'Responsables y administradores'],
            ['v' => 'tecnico',     't' => 'Técnicos, responsables y administradores'],
            ['v' => 'todos',       't' => 'Todo el equipo'],
        ];
    }
}
