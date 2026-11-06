# Instalación de HuraKan Tick

Web oficial: https://hurakancode.com/HuraKanTick

Tres escenarios: prueba rápida, instalación nueva sobre MySQL, y migración
desde la versión antigua conservando los datos.

---

## 1. Prueba rápida (SQLite, sin configurar nada)

```bash
php tools/instalar.php --demo
php -S 0.0.0.0:8080 -t .
```

`http://localhost:8080/www/index.html` · `daniel.melgar@hotelbenalma.com` / `Selenta2026+`

La base de datos es un único fichero en `db/hurakantick.sqlite`. Para empezar de cero:
`php tools/instalar.php --forzar`.

---

## 2. Instalación nueva sobre MySQL

### 2.1 Base de datos

```sql
CREATE DATABASE hurakantick CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hurakantick'@'localhost' IDENTIFIED BY 'una-contraseña-larga';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON hurakantick.* TO 'hurakantick'@'localhost';
FLUSH PRIVILEGES;
```

`DROP` hace falta para `--forzar` —empezar de cero borra las tablas— y
`REFERENCES` para las claves foráneas en algunas versiones de MySQL. Sin
`DROP`, `--forzar` se para y lo dice; sin `REFERENCES`, fallan las
ampliaciones. Si prefieres no dar `DROP` en producción, quítalo después de
instalar: sólo se necesita para empezar de cero.

### 2.2 Configuración

Crea `api/config.local.php` (no toques `api/config.php`):

```php
<?php
return [
    'db' => [
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'database' => 'hurakantick',
        'user'     => 'hurakantick',
        'password' => 'una-contraseña-larga',
    ],
    'auth' => [
        // Genera una nueva con: php -r "echo bin2hex(random_bytes(32));"
        'app_key' => 'PEGA-AQUÍ-LA-CLAVE-GENERADA',
    ],
    'app' => [
        'organizacion' => 'Nombre de tu empresa',
        'zona_horaria' => 'Europe/Madrid',
        // Cómo se llaman tus cuatro niveles de ubicación.
        // Estos son los del esquema original.
        'niveles_zona' => ['Planta', 'Zona', 'Lugar', 'SubLugar'],
    ],
    'cors_origins' => [
        'https://localhost',        // necesario para la app de Android
        'capacitor://localhost',
    ],
];
```

### 2.3 Esquema y primer usuario

```bash
mysql -u hurakantick -p hurakantick < db/schema.mysql.sql
mysql -u hurakantick -p hurakantick < db/catalogos.mysql.sql   # 313 tipos, 1.285 ubicaciones
php tools/instalar.php
```

El segundo fichero trae el catálogo real del hotel extraído del volcado
original. Si vas a cargar el tuyo propio, sáltatelo.

El instalador muestra la contraseña del administrador **una sola vez**. Apúntala.

### 2.4 Permisos y limpieza

```bash
chmod 775 api/uploads
chown -R www-data:www-data api/uploads
rm tools/instalar.php tools/demo.php     # cuando ya no los necesites
```

### 2.5 Servidor web

Con **Apache**, los `.htaccess` incluidos ya lo dejan listo, pero
**`AllowOverride All` no es un detalle: sin él no se lee ninguno**, y la
base de datos entera se descarga escribiendo la dirección en el
navegador. Apache en Debian y en Ubuntu viene con `AllowOverride None`,
así que casi siempre hay que ponerlo a mano:

```apache
<Directory /var/www/html/hurakantick>
    AllowOverride All
</Directory>
```

y recargar con `apachectl -k graceful`.

Hacen falta además dos módulos, y **ninguno de los dos avisa si falta**:

```bash
a2enmod rewrite headers && apachectl -k graceful
```

Sin `mod_headers` no salen las cabeceras de seguridad ni las de caché, y
no pasa nada visible: Apache no se queja y la aplicación funciona. Pero
cualquier página podría meter HuraKan Tick dentro de un marco con la sesión del
técnico puesta, y el trabajador de servicio se quedaría cacheado —que es
justo lo que trae las actualizaciones a los móviles—. En esta misma
máquina de pruebas `mod_headers` no estaba activado.

No lo des por hecho: `php tools/diagnostico.php` lo **pide por HTTP** y
canta en rojo si el servidor sirve algo que no debe. Es la única forma
de saberlo, porque con el candado puesto y la carpeta abierta de par en
par todo tiene el mismo aspecto desde dentro.

Sin consola, lo mismo está en la aplicación: **Administración →
Integridad** repasa los candados, los datos, los ficheros, la última copia
y la versión, y pone arriba lo que hay que mirar. Ábrelo al terminar de
instalar y de vez en cuando: sólo mira, no cambia nada.

Con **nginx**, añade:


```nginx
location /hurakantick/api/ {
    try_files $uri $uri/ /hurakantick/api/index.php?_ruta=$uri&$args;
}
location ~ ^/hurakantick/(db|tools|paquetes)/ { deny all; }
location ~ ^/hurakantick/api/copias/ { deny all; }
location ~ ^/hurakantick/api/uploads/.*\.php$ { deny all; }
location ~ ^/hurakantick/api/config\.local\.php$ { deny all; }
```

### 2.6 Comprobación

Abre `https://tu-servidor/hurakantick/api/index.php?_ruta=estado`. Debe responder algo así:

```json
{"ok":true,"servicio":"HuraKan Tick API","motor":"mysql","instalada":true,"gd":true}
```

Si `gd` es `false`, las fotos se guardan sin redimensionar en el servidor
(el móvil ya las comprime igualmente, así que no es grave).

---

## 3. Migración desde la versión antigua

**Haz copia de seguridad antes.** El script no borra nada, pero conviene.

`sstt_app` es el nombre de la base del sistema antiguo del hotel, tal
como está en su servidor; si la tuya se llama de otra forma, pon la tuya.
(Las instalaciones nuevas usan `hurakantick`.)

```bash
mysqldump -u USUARIO -p sstt_app > copia_antes_de_migrar.sql
mysql -u USUARIO -p sstt_app < db/migracion.legacy.mysql.sql
```

Qué hace:

- Crea las tablas nuevas (`prioridades`, `elementos`, `departamentos`,
  `adjuntos`, `api_tokens`, `notificaciones`, `preferencias`).
- Añade columnas a `login`, `estados`, `rel_zonas`, `zona_1..4`,
  `ticket_incidencias` y `ticket_incidencias_historial`.
- Genera un `uuid` para cada parte y movimiento existente.
- Convierte los administradores antiguos (`admin = 1`) al rol `admin`.
- Rellena el catálogo de prioridades, departamentos y tipos de avería.
  El tipo 5 sigue siendo *Aire acondicionado*, como en la versión anterior.
- Calcula la ruta legible de cada ubicación (`rel_zonas.ruta`).

Si alguna columna ya existía verás el error 1060 «Duplicate column name»:
es esperado, continúa.

### Contraseñas

Los usuarios antiguos conservan su `pwd_php`, así que **entran con la misma
contraseña de siempre**. Las cuentas que sólo tuvieran `pwd_sha2` (sin hash
bcrypt) no podrán entrar: dales una contraseña nueva desde *Ajustes › Equipo*.

### Charset

Si tu base venía en ISO-8859-1, revisa los acentos después de migrar. Al final
de `migracion.legacy.mysql.sql` hay las órdenes de conversión a utf8mb4
comentadas; descoméntalas sólo tras comprobar el resultado en una copia.

### Fotos antiguas

Las de la versión anterior están en `sstt/ms/uploads/<id_ticket>/` y no figuran
en ninguna tabla. Para que aparezcan en la ficha de cada parte:

```bash
# Primero en seco: dice lo que haría, sin tocar nada
php tools/importar_fotos.php --origen=/var/www/sstt-antiguo/ms/uploads --simular

# Y cuando el resumen cuadre
php tools/importar_fotos.php --origen=/var/www/sstt-antiguo/ms/uploads
```

Qué hace: copia cada imagen a `api/uploads/<id_ticket>/` con el hash SHA-256 como
nombre y la registra en `adjuntos` conservando la fecha del archivo original.

- Es **seguro repetirlo**: lo ya registrado se detecta por hash y se omite.
- Dos copias del mismo archivo con distinto nombre cuentan como una sola.
- Las carpetas cuyo parte ya no exista en la base de datos se dejan intactas y
  se listan al final para que las revises a mano.
- Los archivos que no sean imagen o PDF se descartan con aviso.
- Los originales se conservan; añade `--mover` sólo cuando hayas comprobado que
  todo está bien.

Opciones: `--autor=ID` atribuye todas las fotos a una persona concreta (por
defecto se atribuyen a quien abrió cada parte).

---

## Tareas programadas

Sólo hay una, y es opcional: el aviso de partes fuera de plazo.

```cron
0 * * * * php /ruta/hurakantick/tools/vencidos.php >> /var/log/hurakantick-vencidos.log 2>&1
```

Sin ella todo funciona igual, pero nadie recibe aviso cuando un parte se
pasa del plazo comprometido.

```cron
30 6 * * * php /ruta/hurakantick/tools/mantenimiento.php >> /var/log/hurakantick-mant.log 2>&1
```

La segunda genera los partes del mantenimiento programado. Sin ella hay que
lanzarlo a mano desde la aplicación, que también vale.

Las dos, llamadas desde la línea de órdenes como aquí, no piden clave. Si
en vez de eso las llamas por web (`?clave=...`), la clave es la de tu
instalación: `app_key` en `api/config.local.php`. **El instalador la
genera, una distinta por casa.** Antes venía escrita en el código y era
la misma para todo el mundo; si vienes de una versión anterior, pasa
`php tools/instalar.php` una vez y te la crea.

## Qué se descarga a cada móvil

Importa para la privacidad y para el tamaño de la sincronización:

- Cada aparato recibe **sólo los partes que su dueño puede ver** según la
  regla de visibilidad configurada. No es un filtro de pantalla: los
  demás no llegan al aparato.
- Si un parte deja de ser visible —lo reasignan a otro grupo— el móvil
  **borra su copia local** en la siguiente sincronización.
- Se descargan los partes abiertos y los cerrados dentro de los días de
  histórico configurados (Administración → Comportamiento). Bajarlo
  acelera la primera puesta en marcha y reduce lo que hay en un aparato
  que se puede perder.

## Actualizaciones

Sustituye `api/`, `www/` y `db/` conservando `api/config.local.php` y
`api/uploads/`. Los usuarios reciben la versión nueva al abrir la aplicación
(el trabajador de servicio compara la versión y se refresca solo).

---

## Si la aplicación no arranca

Abre **`www/diagnostico.html`** en el navegador. Esa página no usa módulos ni
nada moderno, así que funciona aunque la aplicación falle, y comprueba archivo
por archivo qué se sirve y con qué tipo MIME, además del estado de la API.

Las tres causas habituales:

**1. El servidor envía los `.js` con el tipo equivocado.** Es la más frecuente.
Los módulos de JavaScript se bloquean si llegan como `text/plain`. El fichero
`www/.htaccess` incluido lo corrige en Apache, pero necesita `AllowOverride All`
en la configuración del sitio. En nginx hace falta `include mime.types;`; en IIS,
añadir el tipo MIME `.js → text/javascript`.

**2. Faltan archivos al subirlos.** Sobre todo `www/assets/js/views/`, que tiene
once ficheros. Si falta uno, no arranca nada: los módulos se cargan en bloque.

**3. Almacenamiento bloqueado en el navegador.** Navegación privada o políticas
de empresa que impiden IndexedDB.

## HTTPS y el modo sin conexión

Esto conviene tenerlo claro antes de repartir la aplicación.

El trabajador de servicio —lo que permite **abrir** la aplicación sin cobertura—
sólo funciona en «contexto seguro»: HTTPS, o `localhost`. Servida por HTTP en una
IP de red local, por ejemplo `http://10.0.0.5/hurakantick/www/`, el navegador no lo
registra.

Qué implica en la práctica:

| | HTTP en IP local | HTTPS | APK (Capacitor) |
|---|---|---|---|
| Entrar y trabajar con red | sí | sí | sí |
| Guardar cambios sin cobertura y sincronizar después | sí | sí | sí |
| **Abrir la app estando ya sin cobertura** | **no** | sí | sí |
| Instalarla en la pantalla de inicio | no | sí | — |
| Avisos del sistema | no | sí | sí |

Es decir: por HTTP la aplicación sigue guardando y sincronizando bien, pero si
alguien la cierra y se queda sin datos, no podrá volver a abrirla hasta
recuperar la red. Para trabajo de campo, o pones HTTPS o repartes el APK.

Poner HTTPS en una intranet es sencillo con un certificado propio; si el
servidor es accesible desde fuera, Let's Encrypt es gratuito.

## Problemas frecuentes

**«Sesión no válida» nada más entrar.**
La cabecera `Authorization` no llega a PHP. En Apache con CGI/FastCGI hace falta
la línea `SetEnvIf Authorization` del `.htaccess` (ya incluida) o
`CGIPassAuth On`.

**La aplicación abre pero no carga datos.**
Mira *Ajustes › Comprobar conexión*. Si falla, revisa la dirección en
*Ajustes › Servidor*: debe apuntar a la carpeta `api`.

**«No se ha podido guardar el archivo».**
Permisos de `api/uploads`, o `upload_max_filesize` / `post_max_size` de PHP por
debajo de 12 MB.

**Los acentos salen mal.**
La base no está en utf8mb4. Convierte con las órdenes del final del script de
migración, sobre una copia primero.

**Al abrir `www/index.html` con doble clic no funciona.**
Es correcto: los módulos y el trabajador de servicio necesitan http o https.
Sírvelo con un servidor web.


## Actualizar una instalación que ya funciona

```
php tools/instalar.php --actualizar
```

Crea las tablas, columnas y ajustes que falten, **sin tocar un solo dato**.
Probado partiendo de una base de la 5.1 con partes de hace meses, notas y
personas dadas de alta: al terminar seguían los 29 partes, las 8 personas y los
59 apuntes de historial, con la nota antigua intacta y las tablas nuevas
creadas.

Se puede repetir sin miedo: ejecutarlo tres veces seguidas deja lo mismo, sin
duplicar asignaciones ni ajustes, y **sin crear un segundo administrador**.

**Comprobado en los dos motores**, partiendo de una base con 33 tablas y datos
dentro. En ambos quedan las 34 tablas, las columnas nuevas y todo lo que había.

Lo que ya estaba anotado en el registro de seguridad **no se toca**: los apuntes
viejos se quedan sin autor en vez de inventarle uno. Un registro que rellena
huecos con suposiciones deja de servir como registro.

**Nunca uses `--forzar` en un sistema en marcha**: eso borra y vuelve a crear.
`--forzar` es para empezar de cero.

## Cambiar de motor sin perder nada

La copia de seguridad guarda los datos, no el SQL, así que **sirve para pasar de
un motor a otro**: se hace la copia en SQLite, se configura MySQL, se instala
vacío y se restaura. O al revés.

Comprobado en las dos direcciones con 28 partes, 1.285 ubicaciones, 7 personas y
los mantenimientos: todo llega, los acentos se conservan, las contraseñas siguen
valiendo y no queda ninguna clave rota.

Es lo que hace falta cuando un hotel empieza con SQLite —que no necesita
servidor— y años después crece hasta querer MySQL.

## MySQL, comprobado

El esquema MySQL va en paralelo al de SQLite: **34 tablas y las mismas
columnas** en los dos, y cada ampliación tiene su versión para cada motor.

Los índices se crean con `ALTER TABLE ... ADD KEY`, no con
`CREATE INDEX IF NOT EXISTS`: MySQL no entiende esa forma, y el instalador
se tragaba el error como un aviso. El resultado era una base **sin siete
índices**; con veinte partes da igual, con veinte mil el listado tarda
segundos.

Para cambiar de motor, en `api/config.php`:

```php
'driver'   => 'mysql',
'host'     => '127.0.0.1',
'database' => 'hurakantick',
'user'     => 'hurakantick',
'password' => '...',
```

Y después `tools/instalar.php`, que aplica el esquema y las ampliaciones que
falten.

### Un marcador por sitio

Las consultas usan un marcador distinto en cada sitio donde comparan, aunque
el valor sea el mismo. En SQLite da igual repetirlo; en MySQL con
`EMULATE_PREPARES` desactivado **un nombre sólo puede usarse una vez**, y si se
repite la consulta no falla: simplemente no encuentra nada nunca.

Así estaba la búsqueda de partes: funcionaba en SQLite y devolvía cero
resultados en MySQL, siempre, sin un solo error en el registro.

### Lo demás que se escribe distinto

- **Restar días a una fecha**: `date(f, '-N day')` en SQLite,
  `DATE_SUB(f, INTERVAL N DAY)` en MySQL. Con la forma de SQLite el cron de
  mantenimiento moría antes de generar un solo parte.
- **«Mete esto si no está»**: `INSERT OR IGNORE` frente a `INSERT IGNORE`.
  Hay `Db::insertarSiFalta()` para no tener que acordarse.
- **Entrecomillar un nombre de tabla**: `"tabla"` frente a `` `tabla` ``.

Los tres daban un «syntax error» seco que apunta a la línea de PDO, no a la
consulta, así que cuestan de encontrar. Todos están resueltos, pero conviene
saberlo antes de escribir SQL nuevo.
