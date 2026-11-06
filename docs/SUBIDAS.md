# Fotos y vídeos: el tope que manda es el de PHP

HuraKan Tick pide 12 MB por foto y 40 MB por clip de vídeo. **Eso es lo que
pide, no lo que se puede.** Si PHP está por debajo, manda PHP y la
subida falla antes de llegar al código de la aplicación.

Y falla mal: cuando el cuerpo de la petición supera `post_max_size`, PHP
**vacía los campos sin avisar**, así que el servidor recibe una petición
sin fichero y sin explicación. Es un error que no habla de ningún
límite.

Comprueba cómo estás:

```bash
php tools/diagnostico.php
```

Si sale en rojo **«Las subidas están limitadas a X MB, no a 12 MB»**, es
esto.

---

## Qué hay que cambiar

Tres valores en `php.ini`. Los dos primeros son los que mandan:

```ini
upload_max_filesize = 50M
post_max_size       = 60M
max_execution_time  = 120
```

Dos cosas que se hacen mal a menudo:

- **`post_max_size` tiene que ser mayor que `upload_max_filesize`.** La
  petición lleva el fichero más el resto del formulario. Si son iguales,
  un fichero justo del tamaño del tope se pasa por los pelos y falla.
- **`max_execution_time`**: subir 40 MB por la wifi del hotel lleva su
  tiempo. Con 30 segundos se corta a medias.

¿Dónde está el `php.ini` que cuenta? Pregúntaselo a PHP, porque suele
haber varios:

```bash
php --ini
```

Y ojo: **el `php.ini` de la consola no es el del servidor web.** Lo que
importa es el de Apache o el de PHP-FPM. Para verlo como lo ve la
aplicación:

```bash
php -r 'phpinfo();' | grep -E "upload_max|post_max"   # el de la consola
```

Después de cambiarlo hay que **reiniciar** Apache o PHP-FPM:

```bash
systemctl restart apache2        # o
systemctl restart php8.2-fpm
```

### Si no tienes acceso al php.ini

Con `AllowOverride` abierto, un `.htaccess` en la raíz de HuraKan Tick sirve —
pero **sólo si PHP va como módulo de Apache**. Con PHP-FPM estas
directivas dan un error 500:

```apache
<IfModule mod_php.c>
    php_value upload_max_filesize 50M
    php_value post_max_size 60M
</IfModule>
```

Fíjate en el `<IfModule>`: sin él, en un servidor con FPM se lleva por
delante la aplicación entera.

Con PHP-FPM lo que vale es un fichero en el pool:

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 60M
```

---

## Los topes de la aplicación

En `api/config.php`, dentro de `uploads`:

| Ajuste | De fábrica | Para qué |
|---|---|---|
| `max_bytes` | 12 MB | Fotos y PDF de un parte |
| `mimes` | jpeg, png, webp, gif, heic, pdf | Lo que se admite en un parte |
| `video_max_bytes` | 40 MB | Clip de vídeo de un manual |
| `video_mimes` | mp4, webm, quicktime | Lo que se admite como vídeo |

El vídeo va aparte a propósito: las fotos de un parte las hace cualquiera
cien veces al día, y un vídeo lo sube un responsable al escribir un
manual. Con un tope único habría que subirlo para el vídeo y quedaría
abierto para todo lo demás.

**Baja estos números, no los subas por encima de lo que aguante el
servidor.** El tope real siempre es el más bajo de los tres, y la
aplicación lo calcula y lo enseña.

---

## Consejos para el vídeo de un manual

- **Grábalo corto.** Un minuto bien enfocado enseña más que cinco de
  paseo, y pesa cinco veces menos.
- **Bájale la resolución a 720p** antes de subirlo. En el móvil no se
  nota y el fichero se queda en la tercera parte.
- **Para lo largo, usa el bloque «Vídeo de fuera»** con un enlace. No
  ocupa disco; a cambio, el móvil necesita internet para verlo, así que
  no sirve en un sótano.
- Los clips viven en `api/uploads/instrucciones/`. Entran en las copias
  de seguridad, así que un manual con veinte vídeos engorda la copia.

---

## Que lo subido no se pueda ejecutar

Es la parte que no se ve y la única que puede costar el servidor entero.
Hay tres cerrojos, en este orden de importancia.

### 1. El nombre en disco no lo decide nadie de fuera

Un archivo subido **nunca** se guarda con el nombre que manda el
navegador. Se guarda como `<sha256>.<extensión del tipo real>`, y la
extensión sale de una tabla cerrada: `jpg png webp gif heic pdf mp4 webm
mov mp3 m4a ogg`. Lo que no esté en esa tabla se guarda como `.bin`.

Esto arregla un agujero real que había: antes, para los tipos que no
estaban en la tabla —todos los vídeos—, se usaba la extensión del nombre
original. Un `.mp4` de verdad llamado `manual.php` se guardaba como
`<hash>.php`. Comprobado y corregido: hoy ese mismo archivo se guarda
como `<hash>.mp4`.

### 2. El tipo se mira por dentro, no por el nombre

Se lee el contenido con `finfo` y se compara con la lista de tipos
admitidos. Un PHP llamado `foto.png` se rechaza con «Tipo de archivo no
admitido (text/x-php)», sin llegar a guardarse.

### 3. El `.htaccess` de la carpeta — y su letra pequeña

`api/uploads/` y `api/uploads/instrucciones/` llevan un `.htaccess` que
quita el manejador de PHP, deniega los `.php`, apaga el listado de la
carpeta y añade `X-Content-Type-Options: nosniff` (sin eso, una foto cuyo
contenido parezca HTML puede interpretarse como página, en el mismo
dominio que la sesión de quien la abre).

**Aviso importante:** un `.htaccess` sólo lo lee Apache **si la
configuración lo permite**. Con `AllowOverride None` —el valor por
defecto en Debian y Ubuntu— ese fichero no lo lee nadie, y con nginx no
existe el concepto. Si el servidor está así, el cerrojo 3 no está
puesto, aunque el fichero esté ahí; por eso los cerrojos 1 y 2 son los
que importan.

Para que el 3 cuente, quien lleve el servidor tiene que hacer una de
estas dos cosas.

En **Apache**, en el `<Directory>` del sitio:

```apache
<Directory /ruta/al/hurakantick>
    AllowOverride All
</Directory>
```

En **nginx**, en el bloque del servidor:

```nginx
location ^~ /api/uploads/ {
    location ~ \.(php|phtml|phar|pht)$ { return 403; }
    add_header X-Content-Type-Options nosniff always;
    autoindex off;
}
```

El diagnóstico (`tools/diagnostico.php`) avisa si el candado falta o es
de una versión anterior, y avisa aparte si el servidor no es Apache. Lo
que no puede comprobar desde dentro es si el servidor lee los
`.htaccess`: eso hay que mirarlo en su configuración.

### El candado se renueva solo

El texto del candado vive en un único sitio del código
(`Uploads::textoCandado()`) y lleva su versión dentro, en un comentario.
`tools/instalar.php` lo reescribe cuando la versión no coincide. Antes la
comprobación era «si el fichero existe, ya está», y eso significaba que
una instalación en marcha no recibía nunca una corrección: el hotel que
ya tenía fotos se quedaba para siempre con el candado del día que las
subió. En el de aquel día había un `php_flag engine off` suelto, fuera de
su `<IfModule>`, y en un Apache sin mod_php —o con PHP-FPM— eso es
«Invalid command» y un **error 500 en todas las fotos del hotel**.
