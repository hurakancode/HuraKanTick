<?php
/**
 * Leer hojas de cálculo: CSV y XLSX.
 *
 * Sin librerías de fuera. Un .xlsx es un ZIP con XML dentro, y PHP trae
 * de serie lo necesario para abrirlo (ZipArchive y SimpleXML), así que no
 * hace falta instalar nada en el servidor del hotel.
 *
 * Devuelve siempre lo mismo: una lista de filas, y cada fila un array
 * asociativo con las cabeceras como claves, en minúsculas y sin tildes.
 * De esa forma da igual que alguien escriba «Planta» o «PLANTA».
 */
declare(strict_types=1);

final class Hoja
{
    /**
     * Lee un fichero y devuelve sus filas.
     *
     * @throws ApiError si el fichero no se puede leer o está vacío
     */
    public static function leer(string $ruta): array
    {
        if (!is_file($ruta)) {
            throw new ApiError('No encuentro el fichero: ' . basename($ruta), 404);
        }
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        $filas = match ($ext) {
            'xlsx'        => self::deXlsx($ruta),
            'csv', 'txt'  => self::deCsv($ruta),
            default       => throw new ApiError(
                'Sólo puedo leer .csv y .xlsx; esto es un .' . $ext . '.', 422),
        };

        if (!$filas) {
            throw new ApiError('El fichero no tiene filas con datos.', 422);
        }
        return $filas;
    }

    /* ---------------------------------------------------------------- */

    /** CSV, aceptando coma o punto y coma, que es lo que usa Excel en España. */
    private static function deCsv(string $ruta): array
    {
        $texto = (string)file_get_contents($ruta);

        // Excel guarda en Windows-1252 si no se le dice otra cosa.
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);   // marca de UTF-8

        $primera = strtok($texto, "\n") ?: '';
        $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';

        $tmp = fopen('php://temp', 'r+');
        fwrite($tmp, $texto);
        rewind($tmp);

        $cabeceras = null;
        $filas = [];
        while (($cols = fgetcsv($tmp, 0, $sep)) !== false) {
            if ($cols === [null] || $cols === false) { continue; }
            if ($cabeceras === null) {
                $cabeceras = array_map([self::class, 'clave'], $cols);
                continue;
            }
            $fila = self::combinar($cabeceras, $cols);
            if ($fila !== null) { $filas[] = $fila; }
        }
        fclose($tmp);
        return $filas;
    }

    /** XLSX: el ZIP trae las cadenas por un lado y las celdas por otro. */
    private static function deXlsx(string $ruta): array
    {
        if (!class_exists('ZipArchive')) {
            throw new ApiError('Este servidor no puede abrir .xlsx; guarda la hoja como .csv.', 422);
        }
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new ApiError('El .xlsx está dañado o no es un Excel.', 422);
        }

        $xmlCadenas = $zip->getFromName('xl/sharedStrings.xml');
        $hoja       = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($hoja === false) {
            throw new ApiError('No encuentro la primera hoja del Excel.', 422);
        }

        $cadenas = self::cadenasCompartidas($xmlCadenas === false ? '' : $xmlCadenas);
        return self::filasDeHoja($hoja, $cadenas);
    }

    /**
     * La tabla de textos del Excel.
     *
     * Se lee con SimpleXML si el servidor la tiene, y si no, a mano. La
     * extensión XML viene de serie en XAMPP, pero no en todos los PHP de
     * Linux, y quedarse sin poder leer un Excel por eso sería absurdo.
     */
    private static function cadenasCompartidas(string $xml): array
    {
        if ($xml === '') { return []; }

        if (function_exists('simplexml_load_string')) {
            $x = @simplexml_load_string($xml);
            if ($x !== false) {
                $fuera = [];
                foreach ($x->si ?? [] as $si) {
                    $fuera[] = isset($si->t) ? (string)$si->t
                        : implode('', array_map(static fn($r) => (string)$r->t,
                                                iterator_to_array($si->r ?? [])));
                }
                return $fuera;
            }
        }

        /* A mano: cada <si> es un texto, y puede venir partido en varios
           <t> si dentro cambia el formato. */
        $fuera = [];
        if (preg_match_all('#<si[^>]*>(.*?)</si>#s', $xml, $bloques)) {
            foreach ($bloques[1] as $bloque) {
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $bloque, $trozos);
                $fuera[] = html_entity_decode(implode('', $trozos[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        return $fuera;
    }

    /** Las filas de la hoja, con el mismo doble camino. */
    private static function filasDeHoja(string $xml, array $cadenas): array
    {
        $cabeceras = null;
        $filas = [];

        $usarFila = static function (array $cols) use (&$cabeceras, &$filas): void {
            if (!$cols) { return; }
            $cols = self::rellenarHuecos($cols);
            if ($cabeceras === null) {
                $cabeceras = array_map([self::class, 'clave'], $cols);
                return;
            }
            $f = self::combinar($cabeceras, $cols);
            if ($f !== null) { $filas[] = $f; }
        };

        if (function_exists('simplexml_load_string')) {
            $x = @simplexml_load_string($xml);
            if ($x !== false) {
                foreach ($x->sheetData->row ?? [] as $fila) {
                    $cols = [];
                    foreach ($fila->c ?? [] as $celda) {
                        $col = self::numeroDeColumna(preg_replace('/\d+/', '', (string)$celda['r']));
                        $valor = (string)($celda->v ?? '');
                        if ((string)$celda['t'] === 's') { $valor = $cadenas[(int)$valor] ?? ''; }
                        elseif (isset($celda->is->t))    { $valor = (string)$celda->is->t; }
                        $cols[$col] = trim($valor);
                    }
                    $usarFila($cols);
                }
                return $filas;
            }
        }

        // Sin la extensión XML: se leen las filas y sus celdas a mano.
        if (preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml, $filasXml)) {
            foreach ($filasXml[1] as $filaXml) {
                $cols = [];
                if (preg_match_all('#<c\s([^>]*)>(.*?)</c>#s', $filaXml, $celdas, PREG_SET_ORDER)) {
                    foreach ($celdas as $celda) {
                        preg_match('/r="([A-Z]+)\d+"/', $celda[1], $ref);
                        $col = self::numeroDeColumna($ref[1] ?? 'A');
                        $tipo = preg_match('/t="([^"]+)"/', $celda[1], $t) ? $t[1] : '';
                        preg_match('#<v>(.*?)</v>#s', $celda[2], $v);
                        $valor = $v[1] ?? '';
                        if ($tipo === 's') {
                            $valor = $cadenas[(int)$valor] ?? '';
                        } elseif ($tipo === 'inlineStr') {
                            preg_match('#<t[^>]*>(.*?)</t>#s', $celda[2], $t2);
                            $valor = $t2[1] ?? '';
                        }
                        $cols[$col] = trim(html_entity_decode((string)$valor, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                }
                $usarFila($cols);
            }
        }
        return $filas;
    }

    /* ---------------------------------------------------------------- */

    /** «Planta 01» → «planta 01»: minúsculas, sin tildes ni espacios de sobra. */
    public static function clave(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return preg_replace('/[^a-z0-9_]+/', '_', $t) ?: '';
    }

    /** Une cabeceras y valores; descarta las filas vacías del final. */
    private static function combinar(array $cabeceras, array $valores): ?array
    {
        $fila = [];
        foreach ($cabeceras as $i => $clave) {
            if ($clave === '') { continue; }
            $fila[$clave] = isset($valores[$i]) ? trim((string)$valores[$i]) : '';
        }
        foreach ($fila as $v) {
            if ($v !== '') { return $fila; }
        }
        return null;   // fila entera en blanco
    }

    /** Las celdas vacías no aparecen en el XML: aquí se reponen. */
    private static function rellenarHuecos(array $cols): array
    {
        $max = max(array_keys($cols));
        $salida = [];
        for ($i = 0; $i <= $max; $i++) {
            $salida[$i] = $cols[$i] ?? '';
        }
        return $salida;
    }

    /** «A» → 0, «B» → 1, «AA» → 26. */
    private static function numeroDeColumna(string $letras): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n - 1;
    }
}
