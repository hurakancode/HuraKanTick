<?php
/**
 * Respaldos para alojamientos sin la extensión mbstring.
 * Se definen sólo si faltan; con mbstring instalado no hacen nada.
 */
declare(strict_types=1);

if (!function_exists('mb_internal_encoding')) {
    function mb_internal_encoding(?string $e = null) { return $e === null ? 'UTF-8' : true; }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $e = null): int {
        return strlen(preg_replace('/[\x80-\xBF]/', '', $s));
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $inicio, ?int $largo = null, ?string $e = null): string {
        $partes = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $trozo  = $largo === null ? array_slice($partes, $inicio) : array_slice($partes, $inicio, $largo);
        return implode('', $trozo);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $e = null): string {
        return strtr(strtolower($s), [
            'Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ñ'=>'ñ','Ü'=>'ü','Ç'=>'ç',
        ]);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s, ?string $e = null): string {
        return strtr(strtoupper($s), [
            'á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ñ'=>'Ñ','ü'=>'Ü','ç'=>'Ç',
        ]);
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
