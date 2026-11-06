<?php
/**
 * Variables que se leen sin haberse asignado nunca en su ámbito.
 *
 * Dos fallos de esta clase se escaparon a todas las pruebas porque
 * PHP no se queja hasta que se ejecuta esa línea, y entonces sólo deja
 * un aviso en el registro: «$quien» en auth.php (restablecer con código
 * daba 500 siempre) y «$u» en admin.php (las llaves de auto-entrada se
 * apuntaban a nombre de nadie). Esto los busca leyendo el código, se
 * ejecute o no esa línea.
 *
 * Es deliberadamente sencillo —el analizador de PHP, sin dependencias—:
 * mira cada función y cada fichero de rutas como un ámbito, y una
 * variable es sospechosa si se lee y en ese ámbito no se asigna de
 * ninguna forma (=, foreach, list, catch, parámetro, use, static,
 * global, o como argumento de salida de preg_match y compañía).
 *
 *   php pruebas/variables.php            (sale 1 si encuentra algo)
 */
declare(strict_types=1);

$raiz = dirname(__DIR__);
$ficheros = array_merge(
    glob("$raiz/api/*.php"), glob("$raiz/api/lib/*.php"), glob("$raiz/api/routes/*.php"),
    glob("$raiz/tools/*.php"), glob("$raiz/plugins/*/*.php") ?: []);

// Lo que llega a los ficheros de rutas desde api/index.php.
$DE_INDEX = ['config', 'partes', 'metodo', 'seccion', 'ruta', 'ctx'];
// Lo que llega a las herramientas cuando las incluye el instalador.
$INCLUIDAS = ['CLASIFICAR_INCLUIDO', 'EQUIPO_INCLUIDO', 'INSTALADOR_INCLUIDO', 'argv', 'argc',
              'log'];   // el instalador se la pasa a lo que incluye
$SUPER = ['this', 'GLOBALS', '_GET', '_POST', '_SERVER', '_FILES', '_COOKIE', '_ENV', '_REQUEST',
          '_SESSION', 'http_response_header'];
// Funciones que ESCRIBEN en un argumento.
$SALIDA = ['preg_match' => [2], 'preg_match_all' => [2], 'parse_str' => [1], 'exec' => [1, 2],
           'similar_text' => [2], 'openssl_sign' => [1], 'openssl_seal' => [1, 2], 'getimagesize' => [1],
           'str_replace' => [3], 'preg_replace' => [4], 'preg_replace_callback' => [4],
           'array_walk' => [], 'stream_select' => [0, 1, 2], 'proc_open' => [2], 'fsockopen' => [2, 3],
           'flock' => [2], 'getimagesizefromstring' => [1], 'settype' => [0], 'sort' => [0],
           'stream_socket_client' => [1, 2], 'stream_socket_server' => [1, 2], 'fscanf' => [2, 3, 4]];

$hallazgos = [];

foreach ($ficheros as $f) {
    $codigo = file_get_contents($f);
    $t = token_get_all($codigo);
    $n = count($t);
    $esRutas = str_contains($f, '/api/routes/') || str_ends_with($f, '/api/index.php')
        || (bool)preg_match('#/plugins/[^/]+/rutas\.php$#', $f);
    $esTool = str_contains($f, '/tools/');

    // Ámbitos: cada function/fn/closure con sus llaves; el resto, el fichero.
    $ambitos = [];      // [inicio, fin, params[], esFn]
    $cabeceras = [];    // [desde, hasta]: la declaración (parámetros, use)
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || !in_array($t[$i][0], [T_FUNCTION, T_FN], true)) { continue; }
        $esFn = $t[$i][0] === T_FN;
        // parámetros
        $j = $i;
        while ($j < $n && $t[$j] !== '(') { $j++; }
        $prof = 0; $params = [];
        for (; $j < $n; $j++) {
            if ($t[$j] === '(') { $prof++; }
            elseif ($t[$j] === ')') { $prof--; if ($prof === 0) { break; } }
            elseif (is_array($t[$j]) && $t[$j][0] === T_VARIABLE) { $params[] = substr($t[$j][1], 1); }
        }
        $desdeCab = $i;
        // use (...)
        $k = $j + 1;
        while ($k < $n && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE) { $k++; }
        if (is_array($t[$k] ?? null) && $t[$k][0] === T_USE) {
            while ($k < $n && $t[$k] !== ')') {
                if (is_array($t[$k]) && $t[$k][0] === T_VARIABLE) { $params[] = substr($t[$k][1], 1); }
                $k++;
            }
        }
        $cabeceras[] = [$desdeCab, $k];
        if ($esFn) {
            // fn(...) => expr  — hasta el ; o , o ) del nivel
            $ini = $k; $p = 0;
            for ($m = $k; $m < $n; $m++) {
                $c = $t[$m];
                if ($c === '(' || $c === '[' || $c === '{') { $p++; }
                elseif ($c === ')' || $c === ']' || $c === '}') { if ($p === 0) { break; } $p--; }
                elseif (($c === ';' || $c === ',') && $p === 0) { break; }
            }
            $ambitos[] = [$ini, $m, $params, true];
            continue;
        }
        // cuerpo { ... } (o ; en abstractas/interfaces)
        $m = $k;
        while ($m < $n && $t[$m] !== '{' && $t[$m] !== ';') { $m++; }
        if (($t[$m] ?? null) !== '{') { continue; }
        $ini = $m; $p = 0;
        for (; $m < $n; $m++) {
            if ($t[$m] === '{' || (is_array($t[$m]) && in_array($t[$m][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $p++; }
            elseif ($t[$m] === '}') { $p--; if ($p === 0) { break; } }
        }
        $ambitos[] = [$ini, $m, $params, false];
    }

    // A qué ámbito pertenece cada token (el más interior), -1 = fichero.
    $de = array_fill(0, $n, -1);
    foreach ($ambitos as $idx => [$a, $b]) {
        for ($x = $a; $x <= $b; $x++) {
            if ($de[$x] === -1 || ($ambitos[$de[$x]][0] < $a)) { $de[$x] = $idx; }
        }
    }

    // En el ámbito de fichero (rutas, herramientas), cada bloque { … } de
    // primer nivel —el if de cada ruta— es su propio mundo: ve lo asignado
    // ANTES fuera de cualquier bloque, y lo suyo, pero no lo de los bloques
    // vecinos. Así se escaparon «$quien» y «$u»: se asignaban en otra ruta
    // del mismo fichero.
    // Bloque más interior de cada token en el ámbito de fichero, y quién
    // es el padre de cada bloque.
    $bloque = array_fill(0, $n, -1);
    $padre = [];
    $pila = [];
    for ($i = 0; $i < $n; $i++) {
        if ($de[$i] !== -1) { continue; }
        $c = $t[$i];
        $abre = $c === '{' || (is_array($c) && in_array($c[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
        if ($abre) { $padre[$i] = $pila ? end($pila) : -1; $pila[] = $i; }
        $bloque[$i] = $pila ? end($pila) : -1;
        if ($c === '}') { array_pop($pila); }
    }
    $antecesor = static function (int $a, int $b) use (&$padre): bool {   // ¿a contiene a b (o es b)?
        for ($x = $b; $x !== -1; $x = $padre[$x] ?? -1) { if ($x === $a) { return true; } }
        return $a === -1;
    };
    $asigPos = [];       // [var] = [[pos, bloque], …] en el ámbito de fichero
    $porBloque = [];     // [bloque][var] = true
    $arriba = [];        // [var] = primera posición en que se asigna fuera de bloques
    $cualquiera = [];    // [var] = primera posición en que se asigna, dentro o fuera

    $asignadas = []; $leidas = [];
    $sig = static function (int $i) use ($t, $n): int {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!is_array($t[$j]) || !in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { return $j; }
        }
        return $n;
    };
    $ant = static function (int $i) use ($t): int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!is_array($t[$j]) || !in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { return $j; }
        }
        return -1;
    };

    $esClase = (bool)preg_match('/^\s*(final\s+|abstract\s+)?class\s+\w+/m', $codigo);
    $enCabecera = static function (int $i) use (&$cabeceras): bool {
        foreach ($cabeceras as [$x, $y]) { if ($i >= $x && $i <= $y) { return true; } }
        return false;
    };
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_VARIABLE) { continue; }
        $v = substr($t[$i][1], 1);
        $a = $de[$i];
        $pp0 = $ant($i);
        if (is_array($t[$pp0] ?? null) && $t[$pp0][0] === T_DOUBLE_COLON) { continue; }   // self::$x
        if ($enCabecera($i)) { continue; }
        if ($a === -1 && $esClase) { continue; }        // propiedades de la clase
        $s = $sig($i); $p = $ant($i);
        $tokS = $t[$s] ?? null; $tokP = $t[$p] ?? null;
        $txtS = is_array($tokS) ? $tokS[1] : $tokS;
        $txtP = is_array($tokP) ? $tokP[1] : $tokP;

        $asigna = false;
        // $v = …, $v .= …, $v[] = …, $v['x'] = …, $v ??= …
        if (in_array($txtS, ['=', '.=', '+=', '-=', '*=', '/=', '??=', '|=', '&='], true)) { $asigna = true; }
        if ($txtS === '[') {
            // $v[...] = …  (crea el array)
            $p2 = 0;
            for ($j = $s; $j < $n; $j++) {
                if ($t[$j] === '[') { $p2++; } elseif ($t[$j] === ']') { $p2--; if ($p2 === 0) { break; } }
            }
            $d = $sig($j);
            if (($t[$d] ?? null) === '=') { $asigna = true; }
        }
        // foreach (… as $k => $v), catch (X $e), static $v, global $v, &$v en list
        if (in_array($txtP, ['as', '=>'], true) || (is_array($tokP) && in_array($tokP[0], [T_AS, T_DOUBLE_ARROW, T_STATIC, T_GLOBAL], true))) { $asigna = true; }
        if (is_array($tokP) && $tokP[0] === T_STRING) {
            $pp = $ant($p);
            if (($t[$pp] ?? null) === '(' ) { $q = $ant($pp); if (is_array($t[$q] ?? null) && $t[$q][0] === T_CATCH) { $asigna = true; } }
            if (($t[$pp] ?? null) === '|') { $asigna = true; }   // catch (A | B $e)
        }
        if ($txtP === '&') { $asigna = true; }                // por referencia
        // list(...) = / [...] = : variables dentro de un [] o list() seguido de =
        // y argumentos de salida de preg_match & cía.
        $prof = 0; $arg = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $c = $t[$j];
            if ($c === ')' || $c === ']') { $prof++; continue; }
            if ($c === '(' || $c === '[') {
                if ($prof > 0) { $prof--; continue; }
                $q = $ant($j);
                $nom = is_array($t[$q] ?? null) ? strtolower($t[$q][1]) : '';
                if ($c === '(' && isset($SALIDA[$nom]) && in_array($arg, $SALIDA[$nom], true)) { $asigna = true; }
                if ($c === '(' && $nom === 'list') { $asigna = true; }
                if ($c === '[') {
                    // ¿se cierra este [ y le sigue un = ?  ([$a, $b] = …) o foreach ... as [$a,$b]
                    $p3 = 0;
                    for ($z = $j; $z < $n; $z++) {
                        if ($t[$z] === '[') { $p3++; } elseif ($t[$z] === ']') { $p3--; if ($p3 === 0) { break; } }
                    }
                    $d = $sig($z);
                    $tq = is_array($t[$q] ?? null) ? $t[$q][0] : ($t[$q] ?? '');
                    if (($t[$d] ?? null) === '=' || $tq === T_AS) { $asigna = true; }
                    // foreach (… as $k => [$a, $b]): el => de un foreach
                    if ($tq === T_DOUBLE_ARROW) {
                        for ($w = $q - 1; $w >= 0; $w--) {
                            if (is_array($t[$w]) && $t[$w][0] === T_AS) { $asigna = true; break; }
                            if ($t[$w] === '(' || $t[$w] === ';' || $t[$w] === '{') { break; }
                        }
                    }
                }
                break;
            }
            if ($c === ',' && $prof === 0) { $arg++; }
            if ($c === ';' || $c === '{' || $c === '}') { break; }
        }

        // isset($v) / empty($v) / $v ?? …: preguntar no es leer.
        $esPregunta = $txtS === '??';
        $q0 = $ant($i);
        if (($t[$q0] ?? null) === '(') {
            $q1 = $ant($q0);
            if (is_array($t[$q1] ?? null) && in_array($t[$q1][0], [T_ISSET, T_EMPTY], true)) { $esPregunta = true; }
        }
        if ($asigna) {
            $asignadas[$a][$v] = true;
            if ($a === -1) {
                $cualquiera[$v] = min($cualquiera[$v] ?? PHP_INT_MAX, $i);
                $asigPos[$v][] = [$i, $bloque[$i]];
                if ($bloque[$i] === -1) { $arriba[$v] = min($arriba[$v] ?? PHP_INT_MAX, $i); }
                else { $porBloque[$bloque[$i]][$v] = true; }
            }
        } elseif (!$esPregunta) {
            $leidas[$a][$v][] = [$t[$i][2], $i];
        }
    }
    foreach ($ambitos as $idx => [, , $params]) {
        foreach ($params as $pv) { $asignadas[$idx][$pv] = true; }
    }

    foreach ($leidas as $a => $vars) {
        foreach ($vars as $v => $lecturas) {
            if (in_array($v, $SUPER, true)) { continue; }
            if ($a === -1) {
                // Cada lectura por su cuenta: ¿asignada antes arriba, o en su bloque?
                $malas = array_filter($lecturas, static function ($l) use ($v, $asigPos, $bloque, $antecesor) {
                    [, $pos] = $l;
                    $br = $bloque[$pos];
                    foreach ($asigPos[$v] ?? [] as [$pa, $ba]) {
                        if ($pa >= $pos) { continue; }
                        // En un bloque que contiene a la lectura, o dentro
                        // de uno contenido en el suyo (el if/else de antes).
                        if ($antecesor($ba, $br) || ($ba !== -1 && $antecesor($br, $ba))) { return false; }
                    }
                    return true;
                });
                if (!$malas) { continue; }
                $lecturas = array_values($malas);
            } elseif (!empty($asignadas[$a][$v])) { continue; }
            $lineas = array_map(static fn($l) => $l[0], $lecturas);
            // Las closures ven lo que les pasan por use; las fn ven el ámbito de fuera.
            if ($a !== -1 && ($ambitos[$a][3] ?? false)) {
                // fn: buscar en el ámbito que la contiene
                $fuera = $de[$ambitos[$a][0] - 1] ?? -1;
                if (!empty($asignadas[$fuera][$v])) { continue; }
                if ($fuera === -1 && ($esRutas && in_array($v, $DE_INDEX, true))) { continue; }
            }
            if ($a === -1 && $esRutas && in_array($v, $DE_INDEX, true)) { continue; }
            if ($a === -1 && $esTool && in_array($v, $INCLUIDAS, true)) { continue; }
            $hallazgos[] = sprintf('%s:%d  $%s', substr($f, strlen($raiz) + 1), $lineas[0], $v);
        }
    }
}

if ($hallazgos) {
    echo "Variables que se leen sin asignarse en su ámbito:\n";
    foreach ($hallazgos as $h) { echo "  · $h\n"; }
    exit(1);
}
echo "Ninguna variable se lee sin haberse asignado en su ámbito (" . count($ficheros) . " ficheros).\n";
exit(0);
