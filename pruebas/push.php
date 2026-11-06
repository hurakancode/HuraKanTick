<?php

/**
 * Comprueba que los avisos push salen bien formados.
 *
 * Esto existe porque el cifrado del Web Push es la clase de cosa que
 * falla en silencio: el servidor cifra, el servicio responde 201, y el
 * navegador descarta el mensaje sin decir nada porque un byte no
 * cuadra. No se nota hasta que alguien se queja de que no le llegan
 * avisos, y para entonces nadie relaciona una cosa con la otra.
 *
 * La prueba hace de navegador: genera un par de claves como el suyo,
 * pide al servidor que cifre, y descifra por el otro lado siguiendo el
 * RFC 8291. Si sale el texto original, cualquier navegador podrá
 * leerlo.
 *
 *     php pruebas/push.php
 *
 * Conviene pasarla después de tocar api/lib/Push.php.
 */
declare(strict_types=1);

/* Sólo desde la consola del servidor. Abierto desde el navegador, este
   script lo podía ejecutar cualquiera de la red del hotel. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script sólo se ejecuta desde la consola del servidor.\n");
}

$raiz = dirname(__DIR__);
require $raiz . '/api/config.php';
foreach (['Db', 'Http', 'Auth', 'Permisos', 'Registro', 'Push'] as $c) {
    $f = $raiz . "/api/lib/$c.php";
    if (is_file($f)) { require $f; }
}
Db::init($config['db']);

/* Sin base de datos no hay nada que probar: las claves VAPID viven en
   ajustes_sistema. Se dice con claridad en vez de reventar con un error
   de SQL que no explica nada. */
if (!Db::tablaExiste('ajustes_sistema')) {
    echo "No hay base de datos todavía.\n";
    echo "Instálala primero:  php tools/instalar.php --demo\n";
    exit(2);
}

$fallos = 0;

/* Un error inesperado también es un fallo.
   Sin esto, romper el cifrado hacía que la prueba reventara a mitad y
   devolviera código 0: parecía que había pasado. */
set_error_handler(static function (int $n, string $m) {
    throw new ErrorException($m, 0, $n);
});

$ok = static function (bool $bien, string $texto, string $detalle = '') use (&$fallos): void {
    echo '  ', $bien ? 'ok    ' : 'FALLA ', $texto;
    if (!$bien && $detalle !== '') { echo ' — ', $detalle; }
    echo "\n";
    if (!$bien) { $fallos++; }
};

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64ud(string $s): string {
    $s = strtr($s, '-_', '+/');
    $r = strlen($s) % 4;
    if ($r) { $s .= str_repeat('=', 4 - $r); }
    return (string)base64_decode($s);
}

echo "CLAVES VAPID\n";
$pubB64 = Push::clavePublica();
$pub = b64ud($pubB64);
$ok(strlen($pub) === 65, 'la clave pública mide 65 bytes', strlen($pub) . ' bytes');
$ok($pub[0] === "\x04", 'va sin comprimir (empieza por 0x04)');
$ok(Push::clavePublica() === $pubB64, 'no se regenera en cada llamada');

echo "\nFIRMA DEL JWT\n";
$r = new ReflectionMethod('Push', 'cabeceraVapid');
$r->setAccessible(true);
$cab = $r->invoke(null, 'https://fcm.googleapis.com/fcm/send/abc');

preg_match('/t=([^,]+)/', $cab, $m);
$ok(!empty($m[1]), 'la cabecera lleva el testigo');
[$c1, $c2, $c3] = explode('.', $m[1]);
$cabJ = json_decode(b64ud($c1), true);
$cueJ = json_decode(b64ud($c2), true);
$ok(($cabJ['alg'] ?? '') === 'ES256', 'firmado con ES256', $cabJ['alg'] ?? '?');
$ok(($cueJ['aud'] ?? '') === 'https://fcm.googleapis.com', 'el destinatario es el origen del servicio');
$ok(($cueJ['exp'] ?? 0) - time() <= 86400, 'caduca antes de 24 h');

$firma = b64ud($c3);
$ok(strlen($firma) === 64, 'la firma mide 64 bytes', strlen($firma) . ' bytes');

// Se verifica con la pública, como haría el servicio de push.
$ent = static function (string $b): string {
    $b = ltrim($b, "\0");
    if (ord($b[0]) > 0x7f) { $b = "\0" . $b; }
    return "\x02" . chr(strlen($b)) . $b;
};
$der = $ent(substr($firma, 0, 32)) . $ent(substr($firma, 32, 32));
$der = "\x30" . chr(strlen($der)) . $der;
$spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pub;
$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
$ok(openssl_verify("$c1.$c2", $der, openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256) === 1,
    'la firma se verifica con la clave pública');

echo "\nCIFRADO (RFC 8291)\n";

// Se hace el papel del navegador.
$nav = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($nav);
$pubNav = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                 . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
$auth = random_bytes(16);

$original = json_encode([
    'titulo' => 'Parte urgente T-00042',
    'cuerpo' => 'Fuga en Planta 03 · Habitaciones · 305',
    'evento' => 'urgente',
], JSON_UNESCAPED_UNICODE);

$rc = new ReflectionMethod('Push', 'cifrar');
$rc->setAccessible(true);
$cifrado = $rc->invoke(null, $original, b64u($pubNav), b64u($auth));

$ok(strlen($cifrado) > 100, 'el mensaje cifrado tiene cuerpo', strlen($cifrado) . ' bytes');

$sal     = substr($cifrado, 0, 16);
$rs      = unpack('N', substr($cifrado, 16, 4))[1];
$largo   = ord($cifrado[20]);
$pubServ = substr($cifrado, 21, $largo);
$resto   = substr($cifrado, 21 + $largo);

$ok(strlen($sal) === 16, 'la sal mide 16 bytes');
$ok($rs === 4096, 'el tamaño de registro es 4096', (string)$rs);
$ok($largo === 65, 'la clave efímera mide 65 bytes', (string)$largo);
$ok($pubServ !== $pub, 'la clave efímera no es la VAPID (una por mensaje)');

/* Y se descifra por el lado del navegador.
   Va entero dentro de un try: si la cabecera viene mal, lo que falla es
   el descifrado, y eso es exactamente lo que hay que reportar — no una
   excepción de PHP a mitad de camino. */
$claro = false;
try {
    $der2 = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pubServ;
    $pem2 = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der2), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $clavePub = openssl_pkey_get_public($pem2);
    if ($clavePub === false) {
        throw new RuntimeException('la clave efímera del mensaje no es válida');
    }
    $compartido = openssl_pkey_derive($clavePub, $nav, 32);
    if ($compartido === false) {
        throw new RuntimeException('no se pudo acordar el secreto');
    }
    $ikm   = hash_hkdf('sha256', $compartido, 32, "WebPush: info\x00" . $pubNav . $pubServ, $auth);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sal);
    $claro = openssl_decrypt(substr($resto, 0, -16), 'aes-128-gcm', $cek,
                             OPENSSL_RAW_DATA, $nonce, substr($resto, -16));
    $ok($claro !== false, 'el navegador puede descifrarlo');
} catch (Throwable $e) {
    $ok(false, 'el navegador puede descifrarlo', $e->getMessage());
}
if ($claro !== false) {
    $ok(rtrim($claro, "\x02") === $original, 'el texto descifrado es el original');
} else {
    $ok(false, 'el texto descifrado es el original', 'no se llegó a descifrar');
}

// Dos mensajes seguidos no pueden salir iguales: cada uno lleva su sal
// y su par efímero, y repetirlos permitiría relacionar avisos.
$otro = $rc->invoke(null, $original, b64u($pubNav), b64u($auth));
$ok($otro !== $cifrado, 'dos envíos del mismo texto salen distintos');

echo "\nSECRETOS\n";
$vapid = new ReflectionMethod('Push', 'vapid');
$vapid->setAccessible(true);
$v = $vapid->invoke(null);
$ok(!str_contains($cifrado, b64ud($v['privada'])), 'la clave privada no viaja en el mensaje');

echo "\n", str_repeat('=', 46), "\n";
echo $fallos === 0 ? "Todo correcto: los avisos push saldrán legibles.\n"
                   : "FALLOS: $fallos — revisa api/lib/Push.php\n";
exit($fallos === 0 ? 0 : 1);
