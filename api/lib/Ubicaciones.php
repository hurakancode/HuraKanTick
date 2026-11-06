<?php
/**
 * Crear ubicaciones a partir de una lista de sitios.
 *
 * Una ubicación es la unión de hasta cuatro niveles —planta, zona, lugar
 * y sublugar— y vive dentro de un hotel: dos hoteles pueden tener su «101»
 * sin pisarse.
 *
 * Esto lo usan el importador de hojas de cálculo y el generador por
 * rangos. Antes cada uno tenía su copia de la misma lógica, y esa es
 * justo la forma de que una se arregle y la otra no.
 */
declare(strict_types=1);

final class Ubicaciones
{
    /**
     * Crea las que falten.
     *
     * @param array $filas   cada una con planta, zona, lugar y sublugar
     * @param int   $idHotel a qué hotel pertenecen
     * @param bool  $aplicar false = ensayo, no se escribe nada
     *
     * @return array{creadas:int, existentes:int, problemas:string[], ejemplos:string[]}
     */
    public static function crear(array $filas, int $idHotel, bool $aplicar): array
    {
        /* El hotel tiene que existir: si no, los sitios se crean colgando
           de un número que no es de nadie y no se ven desde ninguna parte. */
        if (Db::tablaExiste('hoteles')
            && Db::value('SELECT 1 FROM hoteles WHERE id_hotel = ?', [$idHotel]) === null) {
            throw new ApiError("No hay ningún hotel con el número {$idHotel}.", 422);
        }

        $creadas = 0;
        $existentes = 0;
        $problemas = [];
        $ejemplos = [];

        /* Los niveles se recuerdan mientras dura la llamada: en un hotel de
           doce plantas, «Habitaciones» se repite mil veces y no tiene
           sentido preguntar a la base cada vez. */
        $cache = [];

        $nivel = static function (int $n, string $nombre) use (&$cache, $idHotel, $aplicar): ?int {
            $nombre = trim($nombre);
            if ($nombre === '') { return null; }

            $llave = $n . '|' . mb_strtolower($nombre);
            if (array_key_exists($llave, $cache)) { return $cache[$llave]; }

            $id = Db::value(
                "SELECT id_zona_$n FROM zona_$n WHERE nombre_zona_$n = ? AND id_hotel = ?",
                [$nombre, $idHotel]);
            $id = $id === null ? null : (int)$id;

            if ($id === null && $aplicar) {
                $id = Db::insert("zona_$n", [
                    "nombre_zona_$n" => mb_substr($nombre, 0, 120),
                    'id_hotel'       => $idHotel,
                ]);
            }
            return $cache[$llave] = $id;
        };

        foreach ($filas as $i => $f) {
            $planta = trim((string)($f['planta'] ?? ''));
            $zona   = trim((string)($f['zona'] ?? ''));
            $lugar  = trim((string)($f['lugar'] ?? ''));
            $sub    = trim((string)($f['sublugar'] ?? ''));

            if ($planta === '' && $zona === '' && $lugar === '') {
                $problemas[] = 'fila ' . ($i + 2) . ': está vacía';
                continue;
            }
            if ($lugar === '') {
                $problemas[] = 'fila ' . ($i + 2) . ': falta el lugar';
                continue;
            }

            $ids = [
                $nivel(1, $planta),
                $nivel(2, $zona),
                $nivel(3, $lugar),
                $nivel(4, $sub),
            ];

            /* La ruta se arma con los nombres, no con los números: es lo que
               se lee en la aplicación y lo que hace única a una ubicación. */
            $ruta = implode(' > ', array_filter([$planta, $zona, $lugar, $sub], static fn($x) => $x !== ''));

            $ya = Db::value('SELECT id_rel FROM rel_zonas WHERE ruta = ? AND id_hotel = ?', [$ruta, $idHotel]);
            if ($ya !== null) {
                $existentes++;
                continue;
            }

            if ($aplicar) {
                Db::insert('rel_zonas', [
                    'id_zona_1'  => $ids[0], 'id_zona_2' => $ids[1],
                    'id_zona_3'  => $ids[2], 'id_zona_4' => $ids[3],
                    'ruta'       => mb_substr($ruta, 0, 250),
                    'id_hotel'   => $idHotel,
                    'updated_at' => Db::now(),
                ]);
            }
            $creadas++;
            if (count($ejemplos) < 3) { $ejemplos[] = $ruta; }
        }

        return compact('creadas', 'existentes', 'problemas', 'ejemplos');
    }

    /**
     * Desarrolla un rango escrito a mano: «1-12», «1-3,7,9-10», «101».
     *
     * Devuelve los números en orden y sin repetir. Vale para plantas y
     * para habitaciones.
     */
    public static function rango(string $texto): array
    {
        $numeros = [];
        foreach (explode(',', $texto) as $trozo) {
            $trozo = trim($trozo);
            if ($trozo === '') { continue; }
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $trozo, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                foreach (range(min($a, $b), max($a, $b)) as $n) { $numeros[] = $n; }
            } elseif (ctype_digit($trozo)) {
                $numeros[] = (int)$trozo;
            } else {
                throw new ApiError("No entiendo el rango «{$trozo}». Se escribe así: 1-12 o 1,3,5-8.", 422);
            }
        }
        return array_values(array_unique($numeros));
    }

    /**
     * Rellena una plantilla de nombre.
     *
     *   «Planta {n2}»        con n=3  → «Planta 03»
     *   «{p}{n2}»            con p=2, n=7 → «207»
     *   «Habitación {p}{n2}» con p=12, n=4 → «Habitación 1204»
     */
    public static function nombre(string $patron, int $n, ?int $planta = null): string
    {
        return strtr($patron, [
            '{n}'  => (string)$n,
            '{n2}' => str_pad((string)$n, 2, '0', STR_PAD_LEFT),
            '{n3}' => str_pad((string)$n, 3, '0', STR_PAD_LEFT),
            '{p}'  => $planta === null ? '' : (string)$planta,
            '{p2}' => $planta === null ? '' : str_pad((string)$planta, 2, '0', STR_PAD_LEFT),
        ]);
    }
}
