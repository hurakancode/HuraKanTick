<?php

/**
 * ¿Puede ESTA petición escribir desde el navegador?
 *
 * Las herramientas de mantenimiento (importar, ubicaciones, clasificar)
 * se dejan abrir por web a propósito: en un XAMPP recién montado no todo
 * el mundo tiene consola, y hace falta poder MIRAR qué harían. Su única
 * llave era «?clave=instalar», una palabra que va escrita en el paquete
 * y está en el manual: la conoce cualquiera.
 *
 * Mirar (ensayo) con esa llave, bien. Pero las tres también ESCRIBEN
 * —crear ubicaciones, personas y departamentos en la base, volcar un CSV
 * a una ruta— y eso no puede hacerlo un desconocido de la red del hotel
 * con sólo la dirección. Para escribir desde el navegador se exige lo
 * mismo que para reinstalar: el fichero db/REINSTALAR, que demuestra
 * acceso a los ficheros del servidor (un intruso con la URL no lo tiene).
 *
 * Desde la consola del servidor, siempre: quien tiene consola ya manda.
 *
 * No consume el fichero (a diferencia del borrado de instalar.php): estas
 * operaciones no son destructivas y encadenar importar + ubicaciones es
 * lo normal. Caduca solo en una hora.
 */
declare(strict_types=1);

/** [bool $puede, string $motivo] — $motivo explica el «no» cuando lo hay. */
function permiso_navegador_escritura(): array
{
    if (PHP_SAPI === 'cli') {
        return [true, ''];
    }
    foreach (['REINSTALAR', 'REINSTALAR.txt'] as $nombre) {
        $f = __DIR__ . '/../db/' . $nombre;
        if (is_file($f)) {
            if (time() - (int)filemtime($f) < 3600) {
                return [true, ''];
            }
            @unlink($f);
            return [false, "El fichero db/$nombre tenía más de una hora y se ha borrado."];
        }
    }
    return [false, ''];
}

/**
 * Corta la petición si no se puede escribir por web. Pensado para
 * llamarse justo antes de aplicar cambios.
 */
function exigir_permiso_navegador_escritura(string $queIbaAHacer): void
{
    [$puede, $motivo] = permiso_navegador_escritura();
    if ($puede) {
        return;
    }
    http_response_code(403);
    echo "Desde el navegador, con «?clave=instalar» sólo se puede ENSAYAR "
       . "(ver qué haría).\n";
    echo "Para $queIbaAHacer de verdad hacen falta una de estas dos cosas:\n\n";
    if ($motivo !== '') {
        echo "  (" . $motivo . ")\n\n";
    }
    echo "  1. La consola del servidor:\n";
    echo "       php tools/<la-herramienta>.php ... --aplicar\n\n";
    echo "  2. O, si no tienes consola, crear un fichero vacío llamado\n";
    echo "     REINSTALAR en la carpeta db/ del servidor (demuestra que\n";
    echo "     tienes acceso a los ficheros) y repetir. Caduca en una hora.\n";
    exit;
}

/**
 * Una ruta de fichero que llega por el navegador no puede apuntar a
 * cualquier sitio del servidor. Desde la consola, libre. Desde el
 * navegador, sólo dentro de db/ (donde el instalador deja las plantillas
 * y donde se repasan los CSV antes de cargarlos).
 */
function ruta_fichero_navegador(string $ruta, string $para): string
{
    if (PHP_SAPI === 'cli') {
        return $ruta;
    }
    $baseDb = realpath(__DIR__ . '/../db');
    // Para una ESCRITURA el fichero aún no existe: se resuelve su carpeta.
    $dir = realpath(dirname($ruta)) ?: realpath(__DIR__ . '/../' . dirname($ruta));
    $completa = $dir === false ? false : $dir . '/' . basename($ruta);
    if ($baseDb === false || $completa === false
        || !str_starts_with($completa . '/', $baseDb . '/')) {
        http_response_code(403);
        echo "Desde el navegador, $para tiene que estar dentro de la carpeta db/.\n";
        echo "Deja ahí el fichero (p. ej. db/hotel.csv) o hazlo desde la consola.\n";
        exit;
    }
    return $completa;
}
