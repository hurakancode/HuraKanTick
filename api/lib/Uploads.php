<?php
/**
 * Adjuntos. Acepta dos vías:
 *   1. multipart/form-data  — subida normal desde el navegador.
 *   2. base64 en JSON       — cola offline: la foto se guardó en el móvil
 *      y se envía cuando vuelve la conexión.
 *
 * El nombre en disco es el SHA-256 del contenido, de modo que la misma
 * foto reenviada dos veces no ocupa el doble ni genera duplicados.
 */
declare(strict_types=1);

final class Uploads
{
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
        if (!is_dir($cfg['dir'])) {
            mkdir($cfg['dir'], 0775, true);
        }
        /* La tercera copia de esto, con el mismo «php_flag» suelto que
           devuelve 500 con PHP-FPM. Ahora lo escribe protegerDir, que es
           el único sitio donde vive. */
        try { self::protegerDir($cfg['dir']); }
        catch (Throwable $e) {
            /* En el arranque no se puede tirar la aplicación por esto: si
               la carpeta no es escribible, la primera subida lo dirá con
               un mensaje claro. */
            error_log('HuraKan Tick · no se pudo proteger api/uploads: ' . $e->getMessage());
        }
    }

    private static function extension(string $mime, string $nombre): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif'  => 'gif', 'image/heic' => 'heic', 'application/pdf' => 'pdf',
            'video/mp4'  => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }

        /* Aquí había una vuelta a la extensión del nombre original, y eso
           era un agujero de los de verdad: un vídeo mp4 llamado
           «manual.php» se guardaba como «<hash>.php». El candado de la
           carpeta lo pararía en un Apache con mod_php, pero con PHP-FPM
           manda el SetHandler del servidor y el AddType de abajo no llega
           a tiempo. Así que el nombre que manda el navegador no decide
           nunca la extensión del fichero en disco: lo que no reconozcamos
           se queda en «.bin», que no ejecuta nada en ninguna parte.

           $nombre se conserva como parámetro porque la fila de la base de
           datos guarda el nombre original para enseñarlo, pero ya no toca
           el disco. */
        unset($nombre);
        return 'bin';
    }

    /** Guarda bytes ya validados y devuelve la fila de adjunto creada. */
    public static function guardarBytes(int $idTicket, string $bytes, string $nombreOriginal, int $autor, ?string $uuid = null): array
    {
        $max = (int)self::$cfg['max_bytes'];
        if (strlen($bytes) === 0) {
            throw new ApiError('El archivo está vacío.', 422);
        }
        if (strlen($bytes) > $max) {
            throw new ApiError('El archivo supera el máximo de ' . round($max / 1048576) . ' MB.', 413);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->buffer($bytes);
        if (!in_array($mime, self::$cfg['mimes'], true)) {
            throw new ApiError('Tipo de archivo no admitido (' . $mime . ').', 415);
        }

        [$bytes, $ancho, $alto] = self::redimensionar($bytes, $mime);

        $hash = hash('sha256', $bytes);
        $ext  = self::extension($mime, $nombreOriginal);
        $dir  = self::$cfg['dir'] . '/' . $idTicket;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        /* Cada carpeta lleva su propio candado.
         *
         * El de la carpeta raíz hereda en Apache, pero eso depende de
         * cómo esté montado el servidor: con AllowOverride cerrado, o
         * sirviendo por nginx, no hereda nada. Un fichero subido con
         * nombre .php se ejecutaría, y por ahí se entra al servidor
         * entero. Cuesta una línea asegurarlo aquí. */
        /* Un solo sitio escribe el candado, y está debajo.
         *
         * Aquí había una copia con «php_flag engine off» SUELTO, fuera de
         * su <IfModule>. Con PHP como módulo de Apache funciona; con
         * PHP-FPM —cada vez más habitual— esa directiva no existe y
         * Apache devuelve 500 en esa carpeta. Resultado: la primera foto
         * de un parte creaba el candado y a partir de ahí NINGUNA foto de
         * ese parte se veía. Y nadie lo relacionaría con un .htaccess. */
        self::protegerDir($dir);

        $archivo = $hash . '.' . $ext;
        $destino = $dir . '/' . $archivo;

        if (!is_file($destino) && file_put_contents($destino, $bytes) === false) {
            throw new ApiError('No se ha podido guardar el archivo en el servidor.', 500);
        }
        @chmod($destino, 0664);

        // Mismo contenido ya registrado en este ticket: se reutiliza.
        $ya = Db::one('SELECT * FROM adjuntos WHERE id_ticket = ? AND hash = ? AND borrado = 0', [$idTicket, $hash]);
        if ($ya) {
            $ya['id_adjunto'] = (int)$ya['id_adjunto'];
            $ya['url'] = 'uploads/' . $idTicket . '/' . $ya['archivo'];
            $ya['duplicado'] = true;
            return $ya;
        }

        $uuid = Http::esUuid($uuid) ? $uuid : Http::uuid();
        $id = Db::insert('adjuntos', [
            'uuid'            => $uuid,
            'id_ticket'       => $idTicket,
            'archivo'         => $archivo,
            'nombre_original' => mb_substr($nombreOriginal, 0, 250),
            'mime'            => $mime,
            'tamano'          => strlen($bytes),
            'hash'            => $hash,
            'ancho'           => $ancho,
            'alto'            => $alto,
            'id_realiza_accion' => $autor,
            'borrado'         => 0,
            'fecha_creado'    => Db::now(),
            'updated_at'      => Db::now(),
        ]);

        return [
            'id_adjunto' => $id, 'uuid' => $uuid, 'id_ticket' => $idTicket,
            'archivo' => $archivo, 'nombre_original' => $nombreOriginal,
            'mime' => $mime, 'tamano' => strlen($bytes), 'ancho' => $ancho, 'alto' => $alto,
            'url' => 'uploads/' . $idTicket . '/' . $archivo,
        ];
    }

    /**
     * Cuántos bytes se pueden subir DE VERDAD.
     *
     * Esto faltaba, y era una mentira silenciosa: la configuración decía
     * 12 MB por archivo y PHP, con `upload_max_filesize = 2M`, rechazaba
     * cualquier foto de móvil normal. El error que salía no hablaba de
     * ningún límite, porque PHP vacía la petición antes de que llegue
     * aquí.
     *
     * Manda el más bajo de los tres. Y no se puede «arreglar» desde la
     * aplicación: upload_max_filesize y post_max_size sólo se cambian en
     * php.ini o en la configuración del servidor.
     *
     * @param int|null $pedido el tope que quiere la aplicación
     * @return array{bytes:int, mb:float, manda:string, config:int,
     *               php_archivo:int, php_peticion:int}
     */
    public static function topeReal(?int $pedido = null): array
    {
        $aBytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '' || $v === '-1') { return PHP_INT_MAX; }   // sin límite
            $n = (int)$v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 * 1024 * 1024,
                'm' => $n * 1024 * 1024,
                'k' => $n * 1024,
                default => $n,
            };
        };

        $config = $pedido ?? (int)(self::$cfg['max_bytes'] ?? 12 * 1024 * 1024);
        $archivo = $aBytes((string)ini_get('upload_max_filesize'));
        $peticion = $aBytes((string)ini_get('post_max_size'));

        /* La petición lleva el fichero MÁS los otros campos del
           formulario. Se deja un margen para eso: si no, un fichero
           justo del tamaño de post_max_size se pasa por los pelos. */
        $peticionUtil = $peticion === PHP_INT_MAX ? $peticion : max(0, $peticion - 32768);

        $bytes = min($config, $archivo, $peticionUtil);

        $manda = 'la configuración de HuraKan Tick';
        if ($archivo <= $config && $archivo <= $peticionUtil) { $manda = 'upload_max_filesize de PHP'; }
        elseif ($peticionUtil < $config) { $manda = 'post_max_size de PHP'; }

        return [
            'bytes' => $bytes,
            'mb'    => round($bytes / 1048576, 1),
            'manda' => $manda,
            'config' => $config,
            'php_archivo'  => $archivo === PHP_INT_MAX ? 0 : $archivo,
            'php_peticion' => $peticion === PHP_INT_MAX ? 0 : $peticion,
        ];
    }

    /**
     * Guardar un archivo que NO cuelga de un parte.
     *
     * Lo usan las instrucciones: sus fotos y vídeos no pertenecen a
     * ninguna avería, pertenecen al manual. Se reutiliza esto en vez de
     * copiar el guardado en otro sitio porque aquí vive lo delicado: la
     * comprobación del tipo real del fichero —no la extensión que diga su
     * nombre— y el candado que impide que algo subido con nombre .php se
     * ejecute. Duplicar eso es duplicar un agujero.
     *
     * @param string[]|null $mimes para admitir vídeo donde haga falta sin
     *                             abrirlo en las fotos de los partes
     * @return array{archivo:string, url:string, mime:string, tamano:int,
     *               ancho:?int, alto:?int, hash:string}
     */
    public static function guardarSuelto(
        string $subcarpeta,
        string $bytes,
        string $nombreOriginal,
        ?array $mimes = null,
        ?int $maxBytes = null
    ): array {
        $sub = trim($subcarpeta, '/');
        if ($sub === '' || !preg_match('/^[a-z0-9_\/-]+$/i', $sub)) {
            throw new ApiError('Carpeta de destino no válida.', 500);
        }

        $max = $maxBytes ?? (int)self::$cfg['max_bytes'];
        if (strlen($bytes) === 0) {
            throw new ApiError('El archivo está vacío.', 422);
        }
        if (strlen($bytes) > $max) {
            throw new ApiError('El archivo supera el máximo de '
                . round($max / 1048576) . ' MB.', 413);
        }

        /* El tipo se mira por el contenido, nunca por el nombre. Un .jpg
           que dentro es PHP sigue siendo PHP. */
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string)$finfo->buffer($bytes);
        $admitidos = $mimes ?? self::$cfg['mimes'];
        if (!in_array($mime, $admitidos, true)) {
            throw new ApiError('Tipo de archivo no admitido (' . $mime . ').', 415);
        }

        // Sólo se redimensionan las imágenes; un vídeo se guarda tal cual.
        $ancho = null;
        $alto  = null;
        if (str_starts_with($mime, 'image/')) {
            [$bytes, $ancho, $alto] = self::redimensionar($bytes, $mime);
        }

        $dir = self::$cfg['dir'] . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiError('No se ha podido crear la carpeta de destino.', 500);
        }

        self::protegerDir($dir);

        $hash    = hash('sha256', $bytes);
        $archivo = $hash . '.' . self::extension($mime, $nombreOriginal);
        $destino = $dir . '/' . $archivo;

        if (!is_file($destino) && file_put_contents($destino, $bytes) === false) {
            throw new ApiError('No se ha podido guardar el archivo en el servidor.', 500);
        }
        @chmod($destino, 0664);

        return [
            'archivo' => $archivo,
            'url'     => 'uploads/' . $sub . '/' . $archivo,
            'mime'    => $mime,
            'tamano'  => strlen($bytes),
            'ancho'   => $ancho,
            'alto'    => $alto,
            'hash'    => $hash,
        ];
    }

    /** La versión del candado. Se sube cuando cambia el texto de abajo. */
    public const CANDADO = 'hurakantick-candado-4';

    /**
     * El texto del candado de una carpeta de subidas.
     *
     * Una sola copia, a propósito: es lo único que impide que un fichero
     * subido se ejecute como código, y tener dos copias de eso es tener
     * una que se queda atrás. El fichero que viene en el paquete se
     * genera desde aquí, no se escribe a mano.
     *
     * Cada directiva va dentro de su <IfModule>: una que el Apache de
     * turno no entienda devuelve un 500 y se lleva consigo todas las
     * fotos del hotel.
     */
    public static function textoCandado(): string
    {
        return "# " . self::CANDADO . " \u{2014} lo escribe HuraKan Tick; no editar a mano.\n"
            . "Options -Indexes\n"
            . "<IfModule mod_mime.c>\n"
            . "  RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .pht\n"
            . "  AddType text/plain .php .phtml .php5 .php7 .php8 .phar .pht\n"
            . "</IfModule>\n"
            /* Con PHP-FPM manda el SetHandler del servidor y el AddType de
               arriba no llega a tiempo. Esto sí lo entiende FPM. */
            . "<FilesMatch \"\\.(php|phtml|php[3-8]|phar|pht)$\">\n"
            . "  SetHandler none\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n"
            . "    <IfModule mod_authz_host.c>\n      Deny from all\n    </IfModule>\n"
            . "  </IfModule>\n"
            . "</FilesMatch>\n"
            /* Sin esto, un fichero cuyo contenido parezca HTML puede acabar
               interpretado como página, y una foto subida se convierte en un
               ataque contra quien la abre: va en el mismo dominio que su
               sesión. */
            . "<IfModule mod_headers.c>\n"
            . "  Header set X-Content-Type-Options nosniff\n"
            . "  Header set Content-Security-Policy \"default-src 'none'; sandbox\"\n"
            . "</IfModule>\n"
            /* php_flag sólo existe con mod_php. Suelto, fuera de su
               <IfModule>, es «Invalid command» y un 500 en todo lo de
               debajo: ya pasó una vez. */
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n";
    }

    /**
     * Pone (o repara) el candado de una carpeta de subidas.
     *
     * Lleva la versión dentro, en un comentario. Antes la comprobación era
     * «si el fichero existe, ya está», y así ninguna instalación en marcha
     * habría recibido nunca una corrección: el hotel que ya tenía fotos se
     * quedaba con el candado del día que las subió.
     */
    public static function protegerDir(string $dir): void
    {
        $candado = $dir . '/.htaccess';
        if (is_file($candado)
            && str_contains((string)@file_get_contents($candado), self::CANDADO)) {
            return;
        }

        if (@file_put_contents($candado, self::textoCandado()) === false) {
            throw new ApiError(
                'No se ha podido proteger la carpeta de subidas, así que la subida se '
                . 'ha cancelado. Revisa los permisos de escritura en api/uploads.', 500);
        }
    }

    /** Reduce la imagen si GD está disponible; si no, la deja tal cual. */
    private static function redimensionar(string $bytes, string $mime): array
    {
        $lado = (int)self::$cfg['max_lado'];
        if (!function_exists('imagecreatefromstring') || !str_starts_with($mime, 'image/') || $mime === 'image/gif') {
            $info = @getimagesizefromstring($bytes);
            return [$bytes, $info[0] ?? null, $info[1] ?? null];
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return [$bytes, null, null];
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= $lado && $h <= $lado) {
            imagedestroy($img);
            return [$bytes, $w, $h];
        }
        $escala = $lado / max($w, $h);
        $nw = (int)round($w * $escala);
        $nh = (int)round($h * $escala);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        if ($mime === 'image/png')       { imagepng($dst, null, 6); }
        elseif ($mime === 'image/webp')  { imagewebp($dst, null, (int)self::$cfg['calidad']); }
        else                             { imagejpeg($dst, null, (int)self::$cfg['calidad']); }
        $out = (string)ob_get_clean();
        imagedestroy($img);
        imagedestroy($dst);
        return [$out !== '' ? $out : $bytes, $nw, $nh];
    }

    public static function desdeFiles(int $idTicket, array $file, int $autor, ?string $uuid = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiError('Error al recibir el archivo (código ' . ($file['error'] ?? '?') . ').', 400);
        }
        $bytes = file_get_contents($file['tmp_name']);
        if ($bytes === false) {
            throw new ApiError('No se ha podido leer el archivo recibido.', 400);
        }
        return self::guardarBytes($idTicket, $bytes, (string)($file['name'] ?? 'foto.jpg'), $autor, $uuid);
    }

    public static function desdeBase64(int $idTicket, string $dataUrl, string $nombre, int $autor, ?string $uuid = null): array
    {
        if (preg_match('#^data:([-\w./+]+);base64,#', $dataUrl, $m)) {
            $dataUrl = substr($dataUrl, strlen($m[0]));
        }
        $bytes = base64_decode(strtr($dataUrl, ' ', '+'), true);
        if ($bytes === false) {
            throw new ApiError('El contenido en base64 no es válido.', 422);
        }
        return self::guardarBytes($idTicket, $bytes, $nombre, $autor, $uuid);
    }

    public static function borrar(int $idAdjunto, array $usuario): void
    {
        $a = Db::one('SELECT * FROM adjuntos WHERE id_adjunto = ?', [$idAdjunto]);
        if ($a === null) {
            throw new ApiError('El adjunto ya no existe.', 404);
        }
        if (!Auth::esResponsable($usuario) && (int)$a['id_realiza_accion'] !== (int)$usuario['id_login']) {
            throw new ApiError('Sólo puedes borrar tus propias fotos.', 403);
        }
        Db::update('adjuntos', ['borrado' => 1, 'updated_at' => Db::now()], 'id_adjunto = :id', ['id' => $idAdjunto]);

        // Si ningún registro activo apunta al fichero, se elimina del disco.
        $quedan = (int)Db::value('SELECT COUNT(*) FROM adjuntos WHERE hash = ? AND borrado = 0', [$a['hash']]);
        if ($quedan === 0) {
            $ruta = self::$cfg['dir'] . '/' . (int)$a['id_ticket'] . '/' . $a['archivo'];
            if (is_file($ruta)) { @unlink($ruta); }
        }
    }
}
