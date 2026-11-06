# Generar el APK de Android

La aplicación ya está escrita para esto: `www/` es una carpeta autónoma sin
compilación, sin dependencias externas y sin rutas absolutas. Capacitor la
envuelve en una app nativa tal cual.

---

## Antes de empezar

El proyecto Android **ya viene generado y configurado** en `android/`. No hace
falta `npx cap add android`: los permisos, los iconos en todas las densidades,
la pantalla de arranque, el icono monocromo de los avisos y la política de red
están puestos.

Lo único que no puedo dejar hecho es el binario: compilar necesita el SDK de
Android, que va en tu máquina.

Necesitas:

- **Node.js 18 o superior**
- **JDK 17**
- **Android Studio** (por el SDK; no hace falta abrirlo)

## Dos pasos

## Avisos con la aplicación cerrada

Ya funcionan, pero el APK necesita **una configuración que sólo puedes hacer
tú**, porque va ligada a una cuenta de Google.

### En el navegador: no hay que hacer nada

Funciona en cuanto la aplicación se sirva por **https://**.

### Si el servidor va por http://

Android bloquea las conexiones sin cifrar, así que el APK no conectará con un
servidor de intranet en `http://10.66.5.246/`. Hay dos salidas:

1. **Ponerle un certificado**, aunque sea de una autoridad interna. Es lo
   correcto y resuelve también el acceso desde el navegador sin cobertura.
2. **Autorizar sólo esa dirección** en
   `android/app/src/main/res/xml/network_security_config.xml` —el fichero ya
   trae el bloque preparado, comentado— y volver a compilar. Nunca actives el
   tráfico sin cifrar de forma general.

Si se olvida, la aplicación **lo dice**: en vez de «sin conexión» —que hace
buscar en el router y en el cable— explica que es Android quien bloquea y qué
fichero hay que tocar. Con `http://` los
navegadores no lo permiten, y la aplicación lo dice con esas palabras en vez de
dar un error genérico.

Ajustes → Avisos → «Recibir avisos con la aplicación cerrada».

### En el APK: hace falta Firebase

Android no admite Web Push dentro de una aplicación empaquetada. Hay que pasar
por Firebase, que es gratis para esto.

**1. Crear el proyecto**

En `console.firebase.google.com`, proyecto nuevo. Añade una aplicación Android
con el identificador `com.hurakancode.hurakantick` (el que hay en `capacitor.config.json`).

**2. El fichero del móvil**

Descarga `google-services.json` y déjalo en `android/app/google-services.json`.
Sin él, el APK compila pero no recibe nada.

**3. La clave del servidor**

En Firebase → Configuración → Cuentas de servicio → «Generar nueva clave
privada». Baja un JSON.

Pega **el contenido entero** en Administración → Configuración → Avisos push →
«Cuenta de servicio de Firebase».

**4. Comprobarlo**

Ajustes → Avisos → «Mandarme un aviso de prueba». Cierra la aplicación del todo
y espera unos segundos.

### Qué se manda y qué no

Por el push viaja sólo el **código del parte y su título**. Nada más.

En el navegador el mensaje va **cifrado de extremo a extremo**: ni Google ni
Mozilla pueden leerlo, sólo el navegador que se suscribió. Por Firebase el
mensaje sí pasa por Google en claro, y por eso no se manda nada delicado.

En Administración hay un interruptor para **empujar sólo lo urgente**: útil si
no quieres que el móvil suene todo el día pero sí de madrugada cuando hace
falta.

### 1. La dirección del servidor

Hay dos formas, y las dos funcionan:

**Fijarla al compilar** — lo mejor si sólo hay una instalación. Todos los
móviles la traen puesta y nadie tiene que escribir nada.

**Dejarla vacía y que se pida al entrar** — la pantalla de acceso muestra un
aviso destacado, abre sola la configuración, y trae un botón para **probar la
conexión antes de guardar**. Sirve cuando el mismo APK vale para varios hoteles
o cuando la dirección aún no se sabe.

En cualquier caso el botón «Servidor» está siempre al pie de la pantalla de
acceso, así que se puede cambiar más adelante sin reinstalar nada.

#### Fijarla al compilar

Es lo que más se olvida. En el navegador se detecta sola; dentro del APK no
puede, porque Android sirve la aplicación desde `https://localhost`.

Edita `www/index.html`:

```html
window.HURAKANTICK_API = 'https://partes.miempresa.com/hurakantick/api';
```

Y en `api/config.local.php` permite el origen de la app:

```php
'cors_origins' => ['https://localhost', 'capacitor://localhost'],
```

Sin esto compila, instala y arranca — pero no puede hablar con el servidor.

### 2. Compilar

```bash
./compilar-apk.sh
```

El script comprueba que tienes todo, avisa si falta la dirección del servidor,
copia la web al proyecto y lanza Gradle. El APK queda en
`android/app/build/outputs/apk/debug/app-debug.apk`.

Para instalarlo con el móvil conectado por USB:

```bash
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```

### Versión firmada

Crea la firma una sola vez:

```bash
keytool -genkey -v -keystore hurakantick.keystore -alias hurakantick \
        -keyalg RSA -keysize 2048 -validity 10000
```

Crea `android/key.properties`:

```properties
storeFile=../../hurakantick.keystore
storePassword=TU_CONTRASEÑA
keyAlias=hurakantick
keyPassword=TU_CONTRASEÑA
```

El `keytool` se ejecuta en la carpeta principal (la de `empaquetar.sh`): el
`../../` de `storeFile` es relativo a `android/app` y apunta ahí. La lectura
la hace `android/app/firma.gradle`, que se para con un mensaje claro si a
`key.properties` le falta un campo o no encuentra el keystore.

Y compila con `./compilar-apk.sh --release`.

**Ni `key.properties` ni el keystore viajan en el paquete.** `empaquetar.sh`
los quita, y si aun así aparece uno dentro, no deja paquete.

**La versión sale sola de `www/version.json`**: 7.90.0 es `versionName 7.90.0`
y `versionCode 79000`. Android sólo acepta instalar encima un APK con el código
mayor, así que al subir la versión el APK nuevo actualiza al viejo sin
desinstalar. Cabe hasta X.99.99.

**Guarda el keystore y su contraseña.** Sin ellos no podrás publicar
actualizaciones de la misma aplicación: Android las rechaza.

## Qué viene ya configurado

| | Dónde |
|---|---|
| Permisos (internet, red, cámara, vibración, avisos) | `AndroidManifest.xml` |
| Iconos en 5 densidades + adaptativo | `res/mipmap-*` |
| Icono monocromo de la barra de estado | `res/drawable-*/ic_stat_icon.png` |
| Pantalla de arranque, vertical y horizontal | `res/drawable-{port,land}-*` |
| Colores de la marca | `res/values/colors.xml` |
| Política de red | `res/xml/network_security_config.xml` |

El icono monocromo importa más de lo que parece: Android exige blanco sobre
transparente para la barra de estado, y sin él los avisos salen con un cuadrado
gris. Es el fallo más común al empaquetar y ya está resuelto.

## Si tu servidor no tiene HTTPS

Android bloquea el tráfico sin cifrar desde la versión 9. Lo correcto es poner
un certificado. Si es una intranet cerrada y no hay más remedio, descomenta el
bloque `domain-config` de `res/xml/network_security_config.xml` y pon **sólo**
la dirección de tu servidor. Nunca actives `cleartextTrafficPermitted` de forma
general.

---

## Integración nativa

El código ya usa los complementos de Capacitor cuando existen, y se queda
en la vía del navegador cuando no. No hay dos versiones que mantener.

Qué cambia dentro del APK:

| | Navegador | APK |
|---|---|---|
| Botón físico de atrás | — | Cierra la hoja abierta; en el inicio pide confirmación antes de salir |
| Cámara | Selector de archivos | Cámara del sistema, con orientación corregida |
| Avisos | Notification del navegador | Avisos del sistema, llegan con la app cerrada |
| Estado de la red | `navigator.onLine` | Complemento Network, distingue wifi sin salida |
| Barra de estado | — | Del color de la aplicación |

Complementos necesarios (ya en `package.json`):

```
@capacitor/app  @capacitor/camera  @capacitor/network
@capacitor/status-bar  @capacitor/splash-screen
@capacitor/local-notifications  @capacitor/haptics
```

### Icono de los avisos

Android exige un icono monocromo para la barra de estado. Crea
`android/app/src/main/res/drawable/ic_stat_icon.png` (blanco sobre
transparente, 24×24 dp). Sin él los avisos salen con un cuadrado gris.

### Tarea de partes fuera de plazo

Los avisos de retraso los genera el servidor, no el móvil. Programa:

```cron
0 * * * * php /ruta/hurakantick/tools/vencidos.php >> /var/log/hurakantick-vencidos.log 2>&1
```

Si tu hosting no tiene cron, puedes llamarlo por web con la clave de la
aplicación: `https://tu-servidor/hurakantick/tools/vencidos.php?clave=APP_KEY`.

Avisa una sola vez por parte: la marca queda en `aviso_vencido` y no
toca `updated_at`, así que no provoca una sincronización innecesaria a
todos los móviles.
