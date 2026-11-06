# HuraKan Tick — Lo que queda pendiente

Al día de **5 de noviembre de 2026**, versión 7.90.0.

Esta lista es honesta: incluye lo que no está hecho, lo que está hecho
pero **no he podido verificar**, y lo que depende de terceros. No incluye
ideas ni mejoras que nadie ha pedido.

La anterior se quedó en la 7.24 y envejeció mal: daba por pendiente cosas
ya comprobadas. Una lista de pendientes desfasada es peor que no tenerla,
porque se deja de mirar.

---

## 1. Bloqueado por algo externo

### El logo de HuraKan Tick — **falta el fichero**
La entrada y la pantalla de arranque buscan
`www/assets/icons/HuraKan_Tick_Logo.png`, y ese fichero no está en el
proyecto. Mientras no esté, se ve el icono de siempre en su caja de
color (comprobado: no sale una imagen rota); en cuanto lo pongas, sale
el logo, y el trabajador de servicio lo guarda para usar sin cobertura.

*Qué haría falta:* el PNG, con fondo transparente y al menos 152×152
(se pinta a 76×76 y en pantallas densas al doble).

### Publicar la primera versión en HuraKan-Tick
La aplicación ya pregunta a https://github.com/hurakancode/HuraKan-Tick si
hay una versión nueva (Administración → «¿Hay una versión nueva?», y en
`php tools/diagnostico.php`). Ese repositorio estaba **vacío** al
escribir esto, así que contesta «todavía no hay ninguna versión
publicada», sin error.

Entiende las tres formas habituales de publicar, por este orden: una
*Release* con su etiqueta (`v7.90.0`), la etiqueta más alta, o un
`version.json` en la rama principal. Cualquiera vale; lo recomendable para
binarios es la primera.

*Qué haría falta:* publicar ahí la 7.90.0. Hasta entonces nadie puede
saber si va atrasado.

### APK de Android
La red de este entorno no deja descargar el SDK de Android, así que el
proyecto está preparado (`android/`) pero **nunca se ha compilado**.

Al revisarlo apareció que **el APK firmado no podía salir**: `build.gradle`
no leía `android/key.properties` aunque el manual lo pedía, y la aplicación
se declaraba versión «1.0» para siempre. Ya está arreglado
(`android/app/firma.gradle`), y esa parte sí está probada con Gradle de
verdad y un keystore real: lee la firma, para con un mensaje claro si falta
algo y saca `versionCode 79000` de `version.json`. Lo que no he podido es
compilar el APK entero.

*Qué haría falta:* una máquina con Android Studio o con el SDK instalado,
y `./compilar-apk.sh` (y `--release` para el firmado, ver `docs/APK.md`).

### Integración con SAP — **a falta de los datos**
El módulo está hecho y probado (`plugins/sap/`). Admite las cinco vías:
REST, OData de SAP Gateway, SOAP sobre RFC/BAPI, una carpeta con CSV o
JSON, y datos de prueba.

*Qué haría falta:* los datos del hotel. En concreto:

1. **Qué vía** usa su SAP (si no lo saben, la de carpeta funciona
   siempre: el PMS deja un fichero y se lee).
2. **La dirección** del servicio, o la ruta de la carpeta.
3. **Usuario y contraseña** de lectura, y el **mandante** si lo piden.
4. **Cómo llaman a los campos** en su respuesta: el módulo entiende los
   nombres habituales (`Room`, `RoomStatus`, `GuestName`, `CountryCode`,
   `ArrivalDate`…), pero si usan otros hay que añadirlos.

Con eso se rellena Configuración → Conector con SAP y se prueba desde
Ajustes → SAP → Conexión y prueba. No hace falta tocar código.

### La configuración del servidor del hotel — **lo primero de todo**
En el servidor de pruebas, con todos los candados del proyecto puestos,
la base de datos entera se descargaba escribiendo la dirección:
`http://servidor/hurakantick/db/hurakantick.sqlite`. Con las contraseñas y las llaves
de auto-entrada dentro. La causa no está en el programa: Apache en Debian
y Ubuntu viene con `AllowOverride None` y no lee los `.htaccess`.

*Qué haría falta:* en el servidor del hotel, `AllowOverride All` en el
`<Directory>` del sitio (está en `docs/INSTALACION.md`, apartado 2.5) y
recargar. **Y comprobarlo**, que es lo importante: `php
tools/diagnostico.php` lo pide por HTTP y sale en rojo si algo está
abierto. Mientras eso no se compruebe en SU servidor, no está cerrado:
lo que yo he comprobado es que la orden del manual lo arregla, no cómo
está montado el suyo.

### Las pruebas con MySQL ya se pasan, pero en TU servidor hay que repetirlas
La batería entera se pasaba siempre contra SQLite: cada prueba llevaba
escrita dentro la dirección de esa instalación. Ya se puede apuntar a
cualquiera de las dos (`python3 pruebas/todas.py --mysql`) y las 36
pasan en los dos motores.

La primera pasada contra MySQL encontró a la primera un fallo que SQLite
no enseña —buscar una ubicación devolvía un error de base de datos—, así
que el motor importa. *Qué haría falta:* cuando montes la instalación
definitiva, pasar las dos órdenes ahí. Lo que yo he probado es MariaDB
en esta máquina, no tu servidor ni tu versión.

### Certificado HTTPS
Sin él no funcionan las **notificaciones** ni el **modo sin cobertura**:
el navegador no entrega el service worker en `http://`. No es un fallo
del sistema, es una regla del navegador.

Ya no es una incógnita: montando un certificado de prueba se ha
comprobado que, con uno puesto, la aplicación se guarda entera en el
móvil y **abre sin red**. Lo que falta es el certificado del hotel.

*Qué haría falta:* certificado en el servidor y activar Configuración →
Seguridad → Forzar HTTPS. El diagnóstico (`php tools/diagnostico.php`)
comprueba ahora si hay certificado de verdad —intenta conectarse— y
avisa si está caducado, si caduca pronto o si está hecho a mano.

---

## 2. Hecho pero sin verificar

La lista anterior tenía seis puntos. Quedan dos, y los dos necesitan algo
que aquí no hay.

| Qué | Por qué no | Qué haría falta |
|---|---|---|
| Que un aviso **llegue a un móvil de verdad** | Un navegador de pruebas sin escritorio detrás no puede darse de alta en el servicio de Google: contesta «permission denied» aunque el permiso esté dado | Un teléfono, con el certificado puesto. Lo demás de la cadena sí está comprobado: el service worker se registra y manda, el aparato se apunta en el servidor, y el envío dice qué ha pasado |
| El módulo SAP contra un SAP **de verdad** | Probado con ficheros, con datos de prueba y con el servicio caído; falta uno real | Los datos del hotel (ver arriba) |

### Lo que sí se ha comprobado desde entonces

- **Rendimiento en MySQL**, que era el hueco más grande: con 20.000
  partes nada pasa de medio segundo —lista 0,04 s, panel 0,13 s, buscar
  0,42 s—. Con 100.000, que son diez años de hotel, todo sigue bien menos
  la búsqueda de texto libre (2,3 s). Medido también en SQLite, con los
  mismos topes. `pruebas/rendimiento.py`, con `--mysql`.
- **La conversación de un parte con veinte personas escribiendo a la
  vez**: no se pierde ni un mensaje, en los dos motores.
  `pruebas/a_la_vez.py`.
- **La aplicación sin red, con certificado**: se guarda entera en el
  móvil (152 ficheros), el service worker manda en la página, y **abre
  sin cobertura**. `pruebas/sincertificado.py`.
- **Las copias de seguridad, restaurándolas de verdad**: se destruye y se
  devuelve todo. `pruebas/copias.py`.
- **Actualizar sobre la versión anterior**, con los móviles ya
  instalados, en SQLite y en MySQL. `pruebas/actualizar.py`.
- **El alta de personas por ficheros**, de punta a punta, incluida la
  persona entrando con su contraseña. `pruebas/altas.py`.
- **La puerta de entrada**: freno por intentos, bloqueo que caduca, y que
  no se pueda averiguar quién tiene cuenta. `pruebas/acceso.py`.

Son 36 pruebas. Se lanzan todas con `python3 pruebas/todas.py`, que
tarda alrededor de una hora y distingue tres respuestas: pasa, falla, y
**no probada** —cuando a una prueba le falta algo para correr—. Devolver
«pasa» sin haber mirado es la peor de las tres.

## 3. Decisiones tomadas que conviene revisar con el tiempo

**Tipos de avería y ubicaciones en bloque, sólo el administrador.** La API
dejaba a un responsable crearlos y editarlos, aunque ninguna pantalla se lo
ofrecía y la guía lo pone en Administración. Lo he alineado con la guía. Si
querías que el responsable pudiera, hay que darle la pantalla y cambiar una
línea en `api/routes/catalogos_admin.php`.

**Lo que no puede confirmar ninguna prueba.** La batería (36 pruebas, en los
dos motores) cubre cada papel, cada pantalla y cada ruta de la API. No
cubre: el APK de Android (no hay SDK aquí), un móvil de verdad recibiendo un
aviso, tu servidor y tu certificado, y SAP con datos reales. Para lo de tu
servidor está **Administración → Integridad**, que mira TUS datos y TU
servidor y se puede abrir cuando se quiera.

**La configuración es global, no por hotel.** Fue decisión tuya y es
coherente con «si un hotel quiere lo suyo, que se instale aparte». Pero
si la cadena crece, el primer hotel que cambie «a qué grupo van los
partes por defecto» se lo cambia a todos.

**El catálogo de tipos y sus áreas son comunes.** Igual: un campo nuevo
en «Aire acondicionado» aparece en los cinco hoteles. Por eso su gestión
quedó reservada al superadministrador.

**Administrador ≠ superadministrador, pero sólo para ver otros hoteles.**
Dentro de su hotel, un administrador manda igual que antes.

**`tools/equipo_benalma.php` ponía «benalma2026» a todo el equipo.**
Ahora pone la misma contraseña conocida que la demo, y del mismo sitio
(`tools/clave_demo.php`), para que el diagnóstico la cace. Hay que
cambiarla al entrar.

**Las cuentas de la demostración son gente real del hotel, con una
contraseña que va escrita en el manual.** Desde la 7.90 la demo trae a
Daniel Melgar, Carlos Duarte, Fany Prieto… con `Selenta2026+`, y el
administrador de cualquier instalación nueva es
`daniel.melgar@hotelbenalma.com`, también la que no sea de demostración
(ahí con una contraseña al azar). Es lo que pediste y está hecho así. Dos
cosas a tener en cuenta para el producto genérico y para el repositorio
público: esos correos y esa contraseña irán dentro de los binarios, y un
hotel que no sea el Benalma verá a la gente del Benalma al instalar la
demo. El diagnóstico sale en rojo mientras algún administrador entre con
esa contraseña, así que en una instalación de verdad no puede pasar
desapercibido.

**`tools/instalar.php?clave=instalar&actualizar` sigue abierto desde el
navegador, sin sesión.** Es una decisión que ya estaba tomada y
razonada en el propio fichero: es la salida para quien administra el
servidor y no tiene consola —el caso del `.htaccess` que no se copia al
actualizar desde Windows—, y actualizar no borra nada. Pero sí escribe:
aplica ampliaciones de esquema y repasa iconos y reparto por tipo. O
sea que un desconocido con la dirección puede provocar esa escritura
cuando quiera.

No lo he cambiado porque romper esa salida a cambio de un riesgo
pequeño es decisión tuya, no mía. Si decides cerrarla, es una línea:
pedir también ahí el fichero `db/REINSTALAR`, como hace ya el
asistente. Si la dejas, conviene que `tools/` esté cerrada en el
servidor —que es lo que comprueba `php tools/diagnostico.php`—.

**Lo que sí he cerrado** (iba más allá de aplicar esquema): `importar.php`,
`ubicaciones.php` y `clasificar.php` se abrían por web con `?clave=instalar`
y además de ensayar, **escribían** —crear personas y sitios en la base, o
volcar un CSV a cualquier ruta del servidor—. Ahora desde el navegador sólo
ensayan; aplicar exige la consola o `db/REINSTALAR`, y las rutas de fichero
quedan confinadas a `db/`. El control está en `tools/permiso_navegador.php`
y lo vigila `pruebas/expuesto.py`. Esto no depende de la configuración del
servidor: es cosa del propio programa, y ya está hecho.

---

## 4. Trabajo pendiente del producto genérico

El núcleo ya está separado del cliente (Fases 0 a 4 hechas). Queda:

- **Paquetes de otros sectores.** Existe `paquetes/hotel/`. Una
  residencia, un colegio o un edificio de oficinas necesitarían el suyo:
  son dos ficheros SQL, no código.
- **Plantillas de importación en `.xlsx`.** Ahora se generan en CSV, que
  Excel abre sin problema. Leer `.xlsx` sí funciona.
- **Traducción.** Todo está en castellano, textos incluidos. No hay
  sistema de idiomas.

---

## 5. Lo que he ido corrigiendo estos días

Para que quede constancia de qué se tocó al final, y por dónde conviene
mirar si algo se comporta raro:

- Reparto de partes por tipo de avería (la regla estaba mal planteada)
- Aislamiento entre hoteles: lectura, escritura y **borrado**
- `--forzar` no vaciaba nada en MySQL
- La copia «con fotos» no devolvía las fotos al restaurar
- El asistente decía «instalado en MySQL» e instalaba en SQLite
- El importador de Excel reventaba con un `.xlsx` de verdad
- Guardar un informe no hacía nada (referencia huérfana en la pantalla)
- Los desplegables de los campos del aire salían vacíos
- La carpeta `paquetes/` quedó abierta al navegador
- Partes huérfanos: personas sin hotel podían crearlos
- El módulo SAP leía sólo el fichero más reciente de la carpeta
- En instalación nueva, la caja fuerte iba a Pisos en vez de a Servicios
  Técnicos (la excepción se marcaba antes de que existieran los tipos)
- Las 1.285 ubicaciones se quedaban sin su texto de ruta y sin hotel
- Al cambiar un parte de departamento, seguía asignado a quien no era de él
- Cualquiera podía darse a sí mismo departamentos adicionales y permisos
- El ajuste «permitir trabajar sin cobertura» existía pero no hacía nada
- Un observador no recibía los mensajes del parte que seguía
- Quien «sólo avisa» podía cerrar su propio parte y moverlo de departamento
- **«Sin enviar» se quedaba pegado con conexión**: al enviar bien, la copia
  del móvil no se actualizaba con lo que devolvía el servidor, y la pantalla
  no se repintaba al terminar. Hacía falta F5, y aun así el estado seguía mal
- Los paneles de búsqueda no cogían el cursor en el móvil: se abrían para
  escribir y no había dónde teclear
- **Recepción veía las notas técnicas**: las instrucciones se enseñaban a
  cualquiera que abriera el parte. Ahora cada una dice a quién va dirigida
  (el departamento que atiende, sólo quien lo trabaja, o cualquiera)
- El buscador de Ajustes llevaba a la pantalla, no al ajuste: faltaba el ancla
  y, además, la propia pantalla recogía el mismo clic y se lo llevaba a otro sitio
- **El técnico no podía anotar las temperaturas**: los datos del tipo de avería
  sólo se podían poner al dar el parte —cuando nadie los sabe— y la ficha sólo
  enseñaba los que ya tenían valor, así que después no había dónde ponerlos
- Los valores se guardaban en el campo de OTRO tipo de avería: el mismo nombre
  («temp_sala») existe para el aire y para el fancoil, y se cogía cualquiera
- Anotar datos no dejaba rastro en el seguimiento, que es la razón de anotarlos
- Un técnico no podía pasarle el parte a su responsable: sólo los responsables
  podían asignar a otra persona. Ahora hay tres permisos distintos (compañero,
  responsable, otro departamento), globales y por persona
- Una conversación ya leída no se distinguía de un parte sin conversación
- **Las horas que se escribían se guardaban con dos horas de más**: el sistema
  trabaja en hora universal y se enseña ya convertida, pero al escribir una
  fecha se mandaba la hora de aquí tal cual. Poner las 13:05 dejaba el parte a
  las 15:05. Afectaba a los partes programados y al plazo de un parte
- **«Restablecer de fábrica» no borraba nada**: la ruta de hoteles usaba la
  limpieza de partes sin cargar esa clase, así que fallaba en todos con un
  «Class "Tickets" not found» que se quedaba en el registro mientras la
  pantalla decía «0 partes a la papelera». Lo mismo le pasaba al despertador
  de programados del inicio. Nueva prueba `pruebas/clases.py` para este fallo
- **Los partes seguían sin poder borrarse**: las lecturas del chat apuntan al
  MENSAJE, no al parte, y al borrar los mensajes sin quitarlas antes la base lo
  rechazaba. Ahora la limpieza va en profundidad y la guía el esquema, no una
  lista. Además el fallo se disfrazaba de «sin tocar por permisos»
- Un parte ya en la papelera no se podía borrar definitivamente: no se
  encontraba, por estar oculto
- **El buscador de Ajustes no encontraba las acciones de dentro de las
  pantallas** («Forzar la actualización de todos» y 18 más): el índice era una
  lista a mano que se quedó atrás. Ahora hay una prueba, `pruebas/buscador.py`,
  que recorre las pantallas de verdad y avisa si algo no se puede buscar
- **Los partes con conversación u observadores no se podían borrar**: la lista
  de tablas a limpiar estaba escrita a mano en dos sitios y se quedó corta al
  añadir el chat y los observadores. En «restablecer hotel» el fallo además se
  tragaba en silencio y el hotel quedaba a medio limpiar
- Los partes aparcados salían en «Lo más urgente» y en las cifras del inicio,
  sin ningún aviso de que estaban programados, y se podían empezar sin saberlo
- La lista de programados enseñaba todos los filtros generales
- La ficha de un parte aparcado decía «15 d de retraso» en vez de «en pausa»
- **Los interruptores del alta enviaban el parte**: eran `<button>` sin
  `type="button"` dentro del formulario, así que pulsarlos lo guardaba sin
  dejar elegir nada. Afectaba a «Publicarlo más adelante» y a los campos
  propios. Corregidos los 363 botones de toda la aplicación
- **La generación de mantenimientos se caía a mitad**: el plazo se calculaba
  pegando «23:59:59» a una fecha que ya traía hora, y quedaban unos hechos y
  otros no
- Un técnico podía quitarle un parte a otro técnico y reescribir partes cerrados
- **La instalación con demo se caía** desde que «qué falla» es obligatorio: los
  partes de ejemplo nacían sin tipo cuando la plantilla no casaba con el
  catálogo instalado
- Sacar un parte de «Programado» no le quitaba la fecha de aparcado: seguía
  contando en el aviso del inicio y en la lista de lo que viene
- Un parte aparcado salía marcado «de retraso»: su plazo seguía corriendo
  mientras esperaba, y al despertar nacía ya fuera de plazo
- Asignar a alguien no sacaba el parte de «Pendiente Asignar», y dejarlo sin
  asignar no lo devolvía: quedaba un parte sin dueño diciendo «Asignado»
- La ficha de un parte no repintaba su cabecera al terminar el envío: el
  estado nuevo no se veía hasta recargar con F5
- **La pantalla se repintaba sola mientras se escribía y borraba el texto.**
  Ahora nunca se refresca con alguien escribiendo, sólo afecta a inicio y
  listas, y se puede apagar entero desde Configuración

---

## 6. Lo primero que haría yo

1. **Cerrar el servidor.** Comprobar con `php tools/diagnostico.php` en
   SU máquina que no sirve `db/`, las copias ni los paquetes. Es lo único
   de esta lista que, si falla, lo deja todo lo demás sin valor.
2. **Poner el certificado.** Desbloquea notificaciones y es lo único que
   impide dar por cerrado ese módulo.
3. **Repasar por pantalla lo que se probó por API.** Dos de los fallos
   de arriba se escondían justo ahí.
4. **Preguntar por el SAP.** La respuesta tarda más en llegar que en
   implementarse.
