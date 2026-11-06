<?php

declare(strict_types=1);

/**
 * ¿Hay una versión de HuraKan Tick más nueva que ésta?
 *
 * La referencia es el repositorio público de la versión publicada,
 * https://github.com/hurakancode/HuraKan-Tick. Cuando se escribió esto,
 * ese repositorio estaba vacío —sin ramas ni etiquetas—, así que no se
 * sabía cómo se iba a publicar cada versión. Se entienden las tres
 * formas habituales en GitHub, por este orden:
 *
 *   1. una «Release» con su etiqueta (v7.91.0): lo normal para binarios;
 *   2. si no hay Releases, la etiqueta más alta;
 *   3. si tampoco hay etiquetas, un version.json en la rama principal,
 *      igual que el que trae la aplicación.
 *
 * Lo que NO hace, a propósito:
 *
 *   · No descarga ni instala nada. Dice que hay una versión nueva y
 *     dónde está; actualizar sigue siendo cosa de quien administra.
 *   · No manda nada del hotel. La única petición es una lectura
 *     anónima a GitHub; lo que GitHub ve es que hay un servidor
 *     preguntando, nada más. Se puede apagar en la configuración
 *     («actualizaciones» → «comprobar» => false).
 *   · No hace esperar a nadie. Se pregunta como mucho una vez cada doce
 *     horas, con seis segundos de plazo, y lo que se sabe se guarda en
 *     db/actualizacion.json. Sin red, contesta «no se ha podido saber»,
 *     nunca un error.
 */
final class Actualizaciones
{
    private const CADA = 12 * 3600;
    private const PLAZO = 6;

    public static function actual(): string
    {
        $f = __DIR__ . '/../../www/version.json';
        $v = is_file($f) ? (json_decode((string)@file_get_contents($f), true)['version'] ?? '') : '';
        return (string)$v;
    }

    /** Compara «7.90.0» con «v7.91.0»: negativo, cero o positivo. */
    public static function comparar(string $a, string $b): int
    {
        $limpia = static function (string $v): array {
            $v = ltrim(trim($v), 'vV');
            $p = array_map('intval', explode('.', preg_replace('/[^0-9.].*$/', '', $v)));
            return array_pad(array_slice($p, 0, 3), 3, 0);
        };
        return $limpia($a) <=> $limpia($b);
    }

    /**
     * Lo que se sabe. Nunca lanza: si algo falla, lo dice en «motivo».
     *
     * @return array{actual:string, publicada:?string, hay_nueva:bool,
     *               donde:string, motivo:string, comprobado:string, como:string}
     */
    public static function estado(array $config, bool $forzar = false): array
    {
        $cfg = $config['actualizaciones'] ?? [];
        $repo = (string)($cfg['repositorio'] ?? 'hurakancode/HuraKan-Tick');
        $base = [
            'actual'     => self::actual(),
            'publicada'  => null,
            'hay_nueva'  => false,
            'donde'      => 'https://github.com/' . $repo,
            'motivo'     => '',
            'comprobado' => '',
            'como'       => '',
        ];

        if (!($cfg['comprobar'] ?? true)) {
            return ['motivo' => 'La comprobación está apagada en la configuración.'] + $base;
        }

        $cache = __DIR__ . '/../../db/actualizacion.json';
        if (!$forzar && is_file($cache) && time() - (int)@filemtime($cache) < self::CADA) {
            $guardado = json_decode((string)@file_get_contents($cache), true);
            if (is_array($guardado) && ($guardado['repositorio'] ?? '') === $repo) {
                $r = ($guardado['resultado'] ?? []) + $base;
                $r['actual'] = $base['actual'];      // por si se ha actualizado desde entonces
                $r['hay_nueva'] = $r['publicada'] !== null
                               && self::comparar((string)$r['publicada'], $r['actual']) > 0;
                return $r;
            }
        }

        $r = self::preguntar($cfg, $repo) + $base;
        $r['comprobado'] = gmdate('Y-m-d H:i:s');
        $r['hay_nueva'] = $r['publicada'] !== null
                       && self::comparar((string)$r['publicada'], $r['actual']) > 0;

        /* Se guarda lo que se ha llegado a SABER —una versión, o que no
           hay ninguna publicada—, y no se guarda lo que ha fallado: un
           fallo de red o un corte por límite taparían doce horas una
           versión nueva.

           Al principio sólo se guardaban las versiones encontradas, y
           «no hay nada publicado», que es la respuesta de hoy, se volvía
           a preguntar en cada visita: tres consultas a GitHub cada vez que
           alguien abre Administración, y GitHub corta a las sesenta por
           hora. */
        if ($r['publicada'] !== null || !empty($r['definitivo'])) {
            @file_put_contents($cache, json_encode(['repositorio' => $repo, 'resultado' => $r]));
        }
        return $r;
    }

    private static function preguntar(array $cfg, string $repo): array
    {
        $api = rtrim((string)($cfg['api'] ?? 'https://api.github.com'), '/');
        $raw = rtrim((string)($cfg['raw'] ?? 'https://raw.githubusercontent.com'), '/');

        // 1. La última Release.
        [$cod, $d] = self::pedir("$api/repos/$repo/releases/latest");
        if ($cod === 0) {
            /* El aviso de PHP trae la dirección entera delante; a quien lo
               lee le sirve lo del final («Connection refused», «Network is
               unreachable»…), que es lo que le dirá a su informático. */
            $porque = trim((string)preg_replace('/^.*:\s*/', '', (string)($d['_error'] ?? '')));
            return ['motivo' => 'No se ha podido llegar a GitHub desde este servidor'
                              . ($porque !== '' ? " ($porque)" : '') . '.'];
        }
        if ($cod === 200 && !empty($d['tag_name'])) {
            return ['publicada' => ltrim((string)$d['tag_name'], 'vV'),
                    'donde' => (string)($d['html_url'] ?? "https://github.com/$repo/releases"),
                    'como' => 'release'];
        }

        // 2. La etiqueta más alta.
        [$cod, $d] = self::pedir("$api/repos/$repo/tags?per_page=100");
        if ($cod === 200 && is_array($d) && $d) {
            $mejor = null;
            foreach ($d as $t) {
                $n = (string)($t['name'] ?? '');
                if (!preg_match('/^[vV]?\d+(\.\d+){0,2}$/', $n)) { continue; }
                if ($mejor === null || self::comparar($n, $mejor) > 0) { $mejor = $n; }
            }
            if ($mejor !== null) {
                return ['publicada' => ltrim($mejor, 'vV'),
                        'donde' => "https://github.com/$repo/tags", 'como' => 'etiqueta'];
            }
        }

        // 3. Un version.json en la rama principal.
        [$cod, $d] = self::pedir("$raw/$repo/HEAD/version.json");
        if ($cod === 200 && !empty($d['version'])) {
            return ['publicada' => ltrim((string)$d['version'], 'vV'),
                    'donde' => "https://github.com/$repo", 'como' => 'version.json'];
        }

        if ($cod === 403 || $cod === 429) {
            return ['motivo' => 'GitHub no contesta ahora (límite de consultas). Se volverá a probar más tarde.'];
        }
        return ['motivo' => 'El repositorio todavía no tiene ninguna versión publicada.',
                'definitivo' => true];
    }

    /** @return array{0:int, 1:array} código HTTP (0 = no se pudo) y cuerpo leído. */
    private static function pedir(string $url): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => self::PLAZO,
            'ignore_errors' => true,
            /* GitHub rechaza las peticiones sin User-Agent. Un nombre
               genérico del programa: nada que identifique al hotel. */
            'header'        => "User-Agent: HuraKan-Tick/" . self::actual() . "\r\n"
                             . "Accept: application/vnd.github+json\r\n",
        ]]);
        $cuerpo = @file_get_contents($url, false, $ctx);
        $cab = $http_response_header ?? [];
        if ($cuerpo === false && !$cab) {
            $e = error_get_last();
            return [0, ['_error' => trim((string)($e['message'] ?? ''))]];
        }
        $cod = 0;
        if (preg_match('#\s(\d{3})\s?#', (string)($cab[0] ?? ''), $m)) { $cod = (int)$m[1]; }
        $d = json_decode((string)$cuerpo, true);
        return [$cod, is_array($d) ? $d : []];
    }
}
