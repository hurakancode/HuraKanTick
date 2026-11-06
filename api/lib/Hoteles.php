<?php
/**
 * Aislamiento por hotel.
 *
 * Cada hotel es una instalación distinta que comparte servidor. Un parte
 * de uno no puede verse desde otro bajo ningún concepto: ni listándolo,
 * ni buscándolo, ni escribiendo su número en la dirección.
 *
 * El aislamiento se aplica en un solo sitio —esta clase— y todas las
 * consultas pasan por aquí. Repartirlo por cada ruta sería garantizar
 * que tarde o temprano se olvida en alguna, y una fuga entre hoteles no
 * es un fallo cosmético.
 */
declare(strict_types=1);

final class Hoteles
{
    /** Tablas que llevan id_hotel y por tanto se filtran. */
    public const PROPIAS = [
        'login', 'ticket_incidencias', 'rel_zonas',
        'zona_1', 'zona_2', 'zona_3', 'zona_4',
        'unidades', 'mantenimientos', 'instrucciones', 'informes', 'atajos',
    ];

    /** Tablas compartidas por todos: el catálogo genérico. */
    public const COMPARTIDAS = [
        'elemento', 'familias', 'estados', 'prioridades', 'departamentos',
        'campos_elemento', 'ajustes_sistema',
    ];

    private static ?int $forzado = null;

    /* ---------------------------------------------------------------- */
    /* Quién ve qué                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * Administrador y superadministrador son lo mismo.
     *
     * Existían por separado, pero la pantalla de Equipo sólo deja elegir
     * «Administrador» —nunca marcaba a nadie como superadministrador—, así
     * que cualquier administrador dado de alta desde la aplicación se
     * encontraba con candados en la vista smartphone, los enlaces de
     * auto-entrada, la papelera o los departamentos, sin forma de
     * quitárselos.
     *
     * Ahora todo administrador tiene acceso total. Se deja el nombre del
     * método y la columna «superadmin» para no tocar los cuarenta sitios
     * que preguntan por ella: basta con que la respuesta sea la misma.
     */
    public static function esSuperadmin(?array $u = null): bool
    {
        $u = $u ?? Auth::usuario();
        return $u !== null
            && ((int)($u['superadmin'] ?? 0) === 1 || Auth::esAdmin($u));
    }

    /**
     * El hotel con el que se está trabajando.
     *
     * Para casi todo el mundo es el suyo y no hay más. Un
     * superadministrador puede mirar otro pasando `?hotel=N`, pero tiene
     * que pedirlo expresamente: si no, ve el suyo como los demás.
     */
    public static function actual(?array $u = null): ?int
    {
        if (self::$forzado !== null) {
            return self::$forzado;
        }
        $u = $u ?? Auth::usuario();
        if ($u === null) {
            return null;
        }

        if (self::esSuperadmin($u)) {
            $pedido = Http::queryInt('hotel') ?? Http::id(Http::campo('id_hotel'));
            if ($pedido !== null && self::existe($pedido)) {
                return $pedido;
            }
        }
        if ($u['id_hotel'] !== null) {
            return (int)$u['id_hotel'];
        }

        /* Sin hotel asignado.
         *
         * Si sólo hay uno, es ese: no tiene sentido dejar un parte
         * colgando por una ficha a medio rellenar. Con varios no se
         * adivina, y quien lo intente se lleva un aviso claro en vez de
         * crear un parte huérfano que no aparece en ninguna lista. */
        if (!Db::tablaExiste('hoteles')) {
            return null;
        }
        $cuantos = (int)(Db::value('SELECT COUNT(*) FROM hoteles') ?? 0);
        if ($cuantos === 1) {
            return Http::id(Db::value('SELECT id_hotel FROM hoteles LIMIT 1'));
        }
        return null;
    }

    /** Fija el hotel a la fuerza (herramientas de línea de órdenes). */
    public static function forzar(?int $id): void
    {
        self::$forzado = $id;
    }

    /**
     * ¿Puede esta persona tocar cosas de este hotel?
     *
     * Un superadministrador sí, cualquiera. El resto sólo el suyo, sea
     * cual sea su rol dentro de él.
     */
    public static function puede(int $idHotel, ?array $u = null): bool
    {
        $u = $u ?? Auth::usuario();
        if ($u === null) {
            return false;
        }

        /* Ver OTRO hotel es cosa de la cadena, no de ser administrador.
         *
         * Cuando sólo había un hotel se unificaron administrador y
         * superadministrador, y con ello cualquier administrador pasó a
         * ver todos los hoteles. Con uno daba igual; con cinco, la
         * administradora del Moxy leía los partes del Benalma. Sus
         * poderes de administrador siguen intactos: lo que se le acota es
         * el hotel. */
        if ((int)($u['superadmin'] ?? 0) === 1) {
            return true;
        }

        $suyo = $u['id_hotel'] === null ? null : (int)$u['id_hotel'];
        if ($suyo !== null) {
            return $suyo === $idHotel;
        }

        /* Sin hotel asignado: si sólo hay uno, es ese. En una cadena, no
           se le deja entrar en ninguno hasta que se le asigne. */
        return (int)(Db::value('SELECT COUNT(*) FROM hoteles') ?? 1) <= 1;
    }

    /** Corta la petición si el hotel no es suyo. */
    public static function exigir(int $idHotel, ?array $u = null): void
    {
        if (!self::puede($idHotel, $u)) {
            // Se responde «no existe» y no «no puedes»: decir que existe
            // ya sería contar algo del otro hotel.
            throw new ApiError('No se ha encontrado.', 404);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Filtro para las consultas                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Trozo de WHERE que limita a un hotel.
     *
     * @param string $alias alias de la tabla en la consulta
     * @return array{0:string,1:array} condición y parámetros
     */
    public static function filtro(string $alias = 't', ?array $u = null): array
    {
        $id = self::actual($u);
        if ($id === null) {
            // Sin hotel asignado no se ve nada. Es más seguro que
            // enseñarlo todo, y obliga a arreglar el alta de la persona.
            return ['1 = 0', []];
        }
        return ["$alias.id_hotel = :hot_filtro", ['hot_filtro' => $id]];
    }

    public static function existe(int $id): bool
    {
        if (!Db::tablaExiste('hoteles')) {
            return $id === 1;
        }
        return (bool)Db::value('SELECT 1 FROM hoteles WHERE id_hotel = ? AND activo = 1', [$id]);
    }

    public static function uno(int $id): ?array
    {
        if (!Db::tablaExiste('hoteles')) {
            return null;
        }
        $h = Db::one('SELECT * FROM hoteles WHERE id_hotel = ?', [$id]);
        return $h === null ? null : self::normaliza($h);
    }

    /** Los hoteles que esta persona puede ver. */
    public static function listar(?array $u = null): array
    {
        if (!Db::tablaExiste('hoteles')) {
            return [];
        }
        $u = $u ?? Auth::usuario();
        $filas = self::esSuperadmin($u)
            ? Db::all('SELECT * FROM hoteles ORDER BY activo DESC, nombre')
            : Db::all('SELECT * FROM hoteles WHERE id_hotel = ?', [(int)($u['id_hotel'] ?? 0)]);

        return array_map([self::class, 'normaliza'], $filas);
    }

    private static function normaliza(array $h): array
    {
        $h['id_hotel'] = (int)$h['id_hotel'];
        $h['activo'] = (int)$h['activo'];
        $h['personas'] = (int)Db::value('SELECT COUNT(*) FROM login WHERE id_hotel = ?', [$h['id_hotel']]);
        $h['partes'] = (int)Db::value(
            'SELECT COUNT(*) FROM ticket_incidencias WHERE id_hotel = ? AND borrado = 0', [$h['id_hotel']]);
        $h['ubicaciones'] = (int)Db::value('SELECT COUNT(*) FROM rel_zonas WHERE id_hotel = ?', [$h['id_hotel']]);
        return $h;
    }

    /* ---------------------------------------------------------------- */
    /* Alta y copia                                                     */
    /* ---------------------------------------------------------------- */

    public static function crear(array $d, int $idAutor): int
    {
        $nombreBruto = $d['nombre'] ?? '';
        $nombre = is_scalar($nombreBruto) ? trim((string)$nombreBruto) : '';
        if ($nombre === '') {
            throw new ApiError('Ponle nombre al hotel.', 422, ['campo' => 'nombre']);
        }
        if (Db::value('SELECT 1 FROM hoteles WHERE LOWER(nombre) = ?', [mb_strtolower($nombre)])) {
            throw new ApiError('Ya hay un hotel con ese nombre.', 409, ['campo' => 'nombre']);
        }

        $id = Db::insert('hoteles', [
            'nombre'     => mb_substr($nombre, 0, 150),
            'codigo'     => mb_substr((string)($d['codigo'] ?? ''), 0, 12) ?: null,
            'direccion'  => mb_substr((string)($d['direccion'] ?? ''), 0, 200) ?: null,
            'ciudad'     => mb_substr((string)($d['ciudad'] ?? ''), 0, 80) ?: null,
            'telefono'   => mb_substr((string)($d['telefono'] ?? ''), 0, 40) ?: null,
            'color'      => mb_substr((string)($d['color'] ?? '#0B5563'), 0, 9),
            'activo'     => 1,
            'creado'     => Db::now(),
            'updated_at' => Db::now(),
        ]);

        Registro::anotar('alta_usuario', $idAutor, 'Hotel creado: ' . $nombre . ' (#' . $id . ')');

        // Copiar las ubicaciones de otro, si se ha pedido
        $desde = Http::id($d['copiar_de'] ?? null);
        if ($desde !== null && self::existe($desde)) {
            $n = self::copiarUbicaciones($desde, $id);
            Registro::anotar('ajuste', $idAutor,
                "Copiadas $n ubicaciones del hotel #$desde al #$id");
        }

        /* Los departamentos que usará.
         *
         * Se heredan del hotel del que se copia, o se le dan todos los
         * activos si empieza de cero. Sin esto el hotel nacía sin
         * ninguno: no se podía dar un parte, porque el departamento es
         * obligatorio y no había ninguno que elegir. */
        if (Db::tablaExiste('hotel_departamento')) {
            if ($desde !== null) {
                // «OR IGNORE» es de SQLite; MySQL usa «IGNORE» a secas.
                $ins = Db::driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
                Db::run($ins . ' INTO hotel_departamento (id_hotel, id_departamento, orden)
                         SELECT ?, id_departamento, orden FROM hotel_departamento WHERE id_hotel = ?',
                        [$id, $desde]);
            }
            // Si no había de dónde copiar, o el origen no tenía ninguno.
            $n = (int)Db::value('SELECT COUNT(*) FROM hotel_departamento WHERE id_hotel = ?', [$id]);
            if ($n === 0) {
                $ins = Db::driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
                Db::run($ins . ' INTO hotel_departamento (id_hotel, id_departamento, orden)
                         SELECT ?, id_departamento, id_departamento FROM departamentos
                          WHERE COALESCE(activo, 1) = 1', [$id]);
            }
        }

        return $id;
    }

    /**
     * Copia la estructura de ubicaciones de un hotel a otro.
     *
     * Se copian los cuatro niveles y sus combinaciones, que es lo que
     * cuesta montar a mano. **No se copian los números de serie**: un
     * aparato está donde está y duplicarlo sería inventarse inventario.
     *
     * Tampoco se copian los partes, ni la gente, ni el historial. Sólo
     * el esqueleto, para poder editarlo desde algo en vez de desde nada.
     */
    public static function copiarUbicaciones(int $origen, int $destino): int
    {
        if ($origen === $destino) {
            return 0;
        }

        Db::begin();
        try {
            $mapa = [];               // nivel => [id viejo => id nuevo]

            for ($n = 1; $n <= 4; $n++) {
                $mapa[$n] = [];
                $filas = Db::all(
                    "SELECT id_zona_$n AS id, nombre_zona_$n AS nombre FROM zona_$n WHERE id_hotel = ?",
                    [$origen]
                );
                foreach ($filas as $f) {
                    // Si el destino ya tiene uno con ese nombre se reutiliza,
                    // para poder copiar de varios sitios sin duplicar.
                    $ya = Db::value(
                        "SELECT id_zona_$n FROM zona_$n WHERE id_hotel = ? AND LOWER(nombre_zona_$n) = ?",
                        [$destino, mb_strtolower((string)$f['nombre'])]
                    );
                    $mapa[$n][(int)$f['id']] = $ya !== null
                        ? (int)$ya
                        : Db::insert("zona_$n", [
                            "nombre_zona_$n" => $f['nombre'],
                            'id_hotel'       => $destino,
                        ]);
                }
            }

            $n = 0;
            foreach (Db::all('SELECT * FROM rel_zonas WHERE id_hotel = ?', [$origen]) as $r) {
                $nuevos = [];
                foreach ([1, 2, 3, 4] as $k) {
                    $v = $r["id_zona_$k"];
                    $nuevos["id_zona_$k"] = $v === null ? null : ($mapa[$k][(int)$v] ?? null);
                }

                $ya = Db::value(
                    'SELECT id_rel FROM rel_zonas
                      WHERE id_hotel = ?
                        AND COALESCE(id_zona_1,0) = COALESCE(?,0)
                        AND COALESCE(id_zona_2,0) = COALESCE(?,0)
                        AND COALESCE(id_zona_3,0) = COALESCE(?,0)
                        AND COALESCE(id_zona_4,0) = COALESCE(?,0)',
                    [$destino, $nuevos['id_zona_1'], $nuevos['id_zona_2'],
                     $nuevos['id_zona_3'], $nuevos['id_zona_4']]
                );
                if ($ya !== null) {
                    continue;
                }

                Db::insert('rel_zonas', array_merge($nuevos, [
                    'ruta'     => $r['ruta'],
                    'id_hotel' => $destino,
                ]));
                $n++;
            }

            Db::commit();
            return $n;
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** Copia las instrucciones, que también sirven de un hotel a otro. */
    public static function copiarInstrucciones(int $origen, int $destino, int $idAutor): int
    {
        if ($origen === $destino || !Db::tablaExiste('instrucciones')) {
            return 0;
        }
        $n = 0;
        foreach (Db::all('SELECT * FROM instrucciones WHERE id_hotel = ? AND activo = 1', [$origen]) as $i) {
            $ya = Db::value(
                'SELECT 1 FROM instrucciones WHERE id_hotel = ? AND LOWER(titulo) = ?',
                [$destino, mb_strtolower((string)$i['titulo'])]
            );
            if ($ya) {
                continue;
            }
            Db::insert('instrucciones', [
                'titulo'     => $i['titulo'],
                'resumen'    => $i['resumen'],
                'contenido'  => $i['contenido'],
                'aviso'      => $i['aviso'],
                'id_familia' => $i['id_familia'],
                'id_elemento'=> $i['id_elemento'],
                'etiquetas'  => $i['etiquetas'],
                'id_autor'   => $idAutor,
                'id_hotel'   => $destino,
                'activo'     => 1,
                'creado'     => Db::now(),
                'updated_at' => Db::now(),
            ]);
            $n++;
        }
        return $n;
    }
}
