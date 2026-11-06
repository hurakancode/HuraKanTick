<?php

declare(strict_types=1);

/**
 * Vales de un solo rato para descargar ficheros.
 *
 * El problema que resuelve: una descarga de verdad —la que abre el
 * navegador al pulsar un enlace— no puede llevar la cabecera
 * `Authorization`. Un `<a href>` manda lo que pone en la dirección y
 * nada más. Así que el cliente metía el token de sesión en la propia
 * dirección:
 *
 *     ...?_ruta=copias/14&token=c504d77f726e42...
 *
 * Y una dirección no es un sitio discreto. Esa misma línea se encontró
 * **ciento treinta y cuatro veces en el registro de Apache**, con tokens
 * de administrador completos y en claro. Quien pueda leer los registros
 * —o una copia de los registros, o el servicio que los recoge— se lleva
 * una sesión de administrador abierta durante treinta días. Y además
 * queda en el historial del navegador y viaja en la cabecera `Referer`
 * de lo siguiente que se pida.
 *
 * El vale cambia eso por algo que, en el registro, no vale nada al minuto
 * siguiente:
 *
 *   · dura 60 segundos;
 *   · sirve para UNA ruta concreta, no para la API entera;
 *   · va atado a la persona que lo pidió;
 *   · va firmado con la clave de la casa, así que no se fabrica fuera.
 *
 * No se guarda en la base a propósito: no hay nada que limpiar ni una
 * tabla que crezca, y la firma se comprueba sola. A cambio, dentro de
 * esos 60 segundos el vale se podría usar dos veces. Para una descarga
 * de un fichero que esa persona ya tiene derecho a bajar, es un cambio
 * que sale a cuenta.
 */
final class Descargas
{
    /** Lo que dura un vale, en segundos. */
    public const SEGUNDOS = 60;

    private static function clave(): string
    {
        static $k = null;
        if ($k === null) {
            $cfg = require __DIR__ . '/../config.php';
            $k = (string)($cfg['auth']['app_key'] ?? '');
        }
        return $k;
    }

    private static function firma(string $datos): string
    {
        return hash_hmac('sha256', $datos, self::clave());
    }

    /**
     * Un vale para que esta persona baje esta ruta, ahora.
     *
     * @param string $ruta tal cual va en _ruta, por ejemplo «copias/14»
     */
    public static function vale(int $idLogin, string $ruta): string
    {
        $caduca = time() + self::SEGUNDOS;
        $cuerpo = $caduca . '.' . $idLogin . '.' . $ruta;
        return $caduca . '.' . $idLogin . '.' . substr(self::firma($cuerpo), 0, 32);
    }

    /**
     * ¿Vale este vale para esta ruta? Devuelve el id de la persona o null.
     *
     * Se comprueba con hash_equals para que el tiempo que tarda no diga
     * por dónde se acerca quien lo esté probando a ciegas.
     */
    public static function dueno(?string $vale, string $ruta): ?int
    {
        if (!is_string($vale) || $vale === '') { return null; }
        $t = explode('.', $vale);
        if (count($t) !== 3) { return null; }

        [$caduca, $idLogin, $sello] = $t;
        if (!ctype_digit($caduca) || !ctype_digit($idLogin)) { return null; }
        if ((int)$caduca < time()) { return null; }

        /* Y tampoco se acepta un vale con una caducidad absurda en el
           futuro: si alguna vez se colara una firma vieja, que no sirva
           para siempre. */
        if ((int)$caduca > time() + self::SEGUNDOS + 5) { return null; }

        $esperado = substr(self::firma($caduca . '.' . $idLogin . '.' . $ruta), 0, 32);
        return hash_equals($esperado, $sello) ? (int)$idLogin : null;
    }
}
