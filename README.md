# HuraKan Tick 7.90.0 - Ticketing

Herramienta de incidencias para servicios técnicos. Reescritura completa de la
versión anterior: **API REST en PHP + aplicación web que funciona sin conexión**
y se puede empaquetar como APK de Android sin tocar una línea de código.

| | |
|---|---|
| Nombre oficial | **HuraKan Tick** |
| Web | https://hurakancode.com/HuraKanTick |
| Desarrollo (privado) | https://github.com/hurakancode/HuraKanTick |
| Versión publicada (binarios) | https://github.com/hurakancode/HuraKan-Tick — la referencia para saber si hay una versión nueva |

Hasta la 7.89 se llamó **SSTT**. Desde la 7.90 no queda ese nombre ni
en lo que no se ve: la carpeta de instalación es `/hurakantick`, la base
`db/hurakantick.sqlite` (en MySQL, `hurakantick` por defecto), las
variables de entorno `HURAKANTICK_*` y la aplicación de Android
`com.hurakancode.hurakantick`. Se cambió antes de que nadie la usara
porque después cada uno de esos nombres queda fijo: la carpeta es la
dirección guardada en cada móvil, las claves del navegador son la sesión
de cada persona y el identificador de Android no se puede cambiar una vez
publicada la aplicación.

---


## Para probarlo

```bash
php tools/instalar.php --demo
php tools/clasificar.php --aplicar
php -S 0.0.0.0:8080 -t .
```

`http://localhost:8080/www/index.html` · `daniel.melgar@hotelbenalma.com` / `Selenta2026+`

Al subir una versión nueva de los ficheros:

```bash
php tools/instalar.php --actualizar
```

Crea las tablas y los ajustes que trae la versión, sin tocar ningún dato. Si se
omite, las funciones nuevas parecen no existir y no dan ningún error.

**[docs/PRUEBAS.md](docs/PRUEBAS.md)** dice qué mirar y en qué orden, con las
cinco cuentas de los distintos puestos. Entra con cada una: verás cuatro
aplicaciones distintas sobre los mismos datos.

Si algo no carga, abre **`www/diagnostico.html`**.

Y antes de ponerlo en marcha de verdad, dos órdenes que no son opcionales:

```bash
php tools/diagnostico.php          # qué sirve TU servidor, pidiéndolo por HTTP
python3 pruebas/todas.py --mysql   # las 36 pruebas contra el motor de producción
```

El diagnóstico es el que avisa si el servidor está sirviendo la base de
datos entera a quien escriba la dirección; y la segunda orden es la que
encontró que buscar una ubicación no funcionaba en MySQL, cosa que en
SQLite iba perfecta.

## Qué hace

- **Alta de partes en tres toques**: tipo de avería, ubicación y foto.
- **Funciona sin cobertura.** Todo se guarda en el móvil y se envía solo cuando
  vuelve la red. En un sótano o en una cámara frigorífica se sigue trabajando igual.
- **Seguimiento completo**: estados, asignación, notas, tiempo dedicado, fotos
  y un historial que no se puede alterar.
- **Plazos automáticos** según la prioridad (4 h en urgente, 24 h en alta…), con
  aviso visible de los partes fuera de plazo.
- **Panel de mando**: qué tengo asignado, qué está sin asignar, qué se ha salido
  de plazo, reparto por estado, actividad de dos semanas y carga por persona.
- **Gestión de personas** con cuatro niveles de permiso.
- **Corrección de partes** desde la propia ficha (resumen, detalle, tipo y
  ubicación), dejando constancia de qué cambió en el historial.
- **Avisos sonoros y vibración**: cada situación suena distinto, así que se
  distingue sin sacar el móvil del bolsillo. Los tonos se sintetizan en el
  dispositivo, no hay ficheros que descargar y funcionan sin cobertura.
- **Control de conflictos**: si alguien mueve un parte mientras tú estabas sin
  red, no se pisa el trabajo de nadie. Se muestran las dos versiones y decides.
- **Informes con condiciones**: cuántas averías de televisión hubo el mes
  pasado, cuántas veces se ha tocado el grifo de la 214, quién tarda más en
  cerrar. Se guardan y se comparten.
- **Campos propios por tipo de avería**: el aire acondicionado pregunta modo,
  temperatura marcada y temperatura real; el resto de partes no se llenan de
  casillas que no les hacen falta.
- **Atajos en el inicio**: filtros guardados con nombre y color, definidos por
  el administrador para todos o por cada cual para sí.
- **Grupos**: un parte se asigna a una persona o a un grupo entero (SSTT,
  Limpieza, Informática…), y el administrador decide si los miembros de un
  grupo ven lo de sus compañeros.
- **Todo se puede apagar**: cada función tiene su interruptor. Un hotel que
  sólo quiera partes con foto no ve nada más.
- **Copias de seguridad** descargables, en un formato que sirve igual para
  MySQL o SQLite.
- **Exportación a CSV** del listado con los filtros aplicados. Funciona sin
  conexión, porque los datos ya están en el móvil.
- **Modo oscuro**, tipografía grande y zonas de toque de 48 px: se maneja con
  una mano y con guantes.

---

## Puesta en marcha rápida

Para verlo funcionando en dos minutos, sin base de datos ni configuración:

```bash
php tools/instalar.php --demo     # crea SQLite con datos de ejemplo
php -S 0.0.0.0:8080 -t .          # servidor de pruebas
```

Abre `http://localhost:8080/www/index.html` y entra con:

| Correo | Contraseña | Papel |
|---|---|---|
| `daniel.melgar@hotelbenalma.com` | `Selenta2026+` | Administrador |
| `carlos.duarte@hotelbenalma.com` | `Selenta2026+` | Responsable |
| `david.sstt@hotelbenalma.com` | `Selenta2026+` | Técnico |

Para la instalación real sobre MySQL, lee **[docs/INSTALACION.md](docs/INSTALACION.md)**.
Para generar el APK, **[docs/APK.md](docs/APK.md)**.

---

## Estructura

```
sstt/
├── api/                  API REST en PHP 8 (sin dependencias externas)
│   ├── index.php         enrutador
│   ├── config.php        configuración (usa config.local.php para tus datos)
│   ├── lib/              Db · Auth · Http · Repo · Tickets · Uploads · compat
│   └── routes/           auth · catalogo · tickets · adjuntos · panel
│                         sync · notificaciones · admin · estado
├── db/                   esquemas SQL, catálogos reales y migración
├── www/                  la aplicación (esto es lo que se empaqueta en el APK)
│   ├── index.html        armazón
│   ├── sw.js             trabajador de servicio: hace que abra sin conexión
│   ├── manifest.webmanifest
│   └── assets/
│       ├── css/app.css   sistema visual completo
│       └── js/           app · api · db · sync · ui · sonido · piezas
│                         vistas/ (acceso, panel, lista, ticket, nuevo,
│                         cuenta, equipo, avisos, conflictos, selectores, fotos)
├── tools/                instalador, datos de ejemplo, importador de fotos,
│                         restauración de copias y aviso de retrasos
├── docs/                 instalación, APK, referencia de la API
└── capacitor.config.json empaquetado Android
```

---

## Cómo funciona el modo sin conexión

Es la parte que más condiciona el diseño, así que conviene entenderla.

**La pantalla nunca espera a la red.** Cada acción se escribe primero en
IndexedDB y se pinta al momento; después se manda al servidor. Si no hay
cobertura, queda en una cola de salida.

**Nada se duplica.** El identificador único de cada parte y de cada movimiento
lo genera el móvil, no el servidor. Reenviar la misma operación diez veces
produce el mismo resultado que enviarla una: el servidor la reconoce y la ignora.

**Los conflictos no se resuelven a la brava.** El historial es de sólo añadir,
así que dos técnicos anotando a la vez no se estorban. Pero un cambio de estado
o una reasignación sí pueden chocar, y ahí el servidor no decide por su cuenta:

- Cada parte lleva un contador `id_version` que sube en cada cambio.
- El móvil envía lo que creía saber: `version_base`, `estado_base`, `asignado_base`.
- Si el parte cambió mientras tanto **y** el cambio choca de verdad, el servidor
  devuelve un 409 con las dos versiones y **no aplica nada**.
- La operación no se pierde: queda apartada en el móvil, con su nota intacta,
  y aparece en «Revisar cambios» con lo que hay en el servidor, lo que hiciste
  tú, de qué partías, quién lo cambió y cuándo.
- Eliges: quedarte con el del servidor, o aplicar el tuyo encima (se reenvía
  forzado y queda registrado en el historial junto al de la otra persona).

Reabrir un parte que otro ya había cerrado también pide confirmación, aunque
técnicamente no haya choque de versiones.

**Las fotos se comprimen en el móvil** antes de encolarse: una foto de 4 MB pasa
a unos 200 KB. La cola no llena el almacenamiento y la subida por datos es rápida.

**Cuándo se sincroniza:** al recuperar la red, al volver a la aplicación, cada
dos minutos si queda cola, y al pulsar el botón de sincronizar.

**Qué se descarga a cada móvil.** Sólo los partes que su dueño puede ver. No es
un filtro de pantalla: los demás no llegan al aparato. Si un parte deja de ser
visible —lo reasignan a otro grupo— el móvil borra su copia local en la
siguiente sincronización. El histórico descargado se limita a los días
configurados, lo que acelera la primera puesta en marcha y reduce lo que queda
guardado en un aparato que se puede perder.

**Los avisos sonoros** distinguen la situación sin mirar la pantalla: parte
asignado (dos golpes), urgente (cuatro notas agudas), sincronizado (tres notas
subiendo), guardado sin red (una nota discreta), conflicto (tres descendentes) y
error (dos descendentes). Se generan con Web Audio en el propio móvil. Sonido y
vibración se activan por separado en Ajustes; los avisos del sistema requieren
permiso del navegador y sólo saltan con la aplicación en segundo plano.

Si el servidor rechaza algo de forma definitiva (por ejemplo un parte sin
título), la operación se aparta y aparece en *Ajustes › Envíos con problemas*,
donde se puede reintentar o descartar. Nunca se pierde en silencio.

---

## Qué cambió respecto a la versión anterior

**Seguridad**

- **La visibilidad se aplica en las cuatro vías por igual**: listado, ficha,
  informes y sincronización comparten la misma función. Sin eso, un técnico
  veía en el panel un total de partes que no podía abrir, y —lo grave— su móvil
  se descargaba los datos completos de partes ajenos.
- Las credenciales salían en `config.php` y en `config.php.txt`. Ahora van en
  `config.local.php` (fuera del control de versiones) o en variables de entorno,
  y el `.htaccess` impide servir ambos.
- `aa.php` y `lista.zonas.json.php` concatenaban valores del usuario dentro del
  SQL. Todas las consultas van preparadas y con parámetros vinculados.
- `subir_imagen.php` y `borrar_imagen.php` no comprobaban la sesión: cualquiera
  podía subir o borrar archivos. Además `borrar_imagen.php` aceptaba la ruta por
  POST sin sanear. Ahora todo pasa por autenticación, se valida el tipo MIME real
  del contenido y el nombre en disco es el hash del archivo.
- Bloqueo por intentos fallidos **por cuenta y por origen**: sin lo segundo se
  pueden probar contraseñas contra muchas cuentas sin llegar al límite de ninguna.
- Cabeceras de seguridad en todas las respuestas y caducidad de sesión.

**Problemas del esquema original que habrían roto la instalación**

- `login.admin` y `login.bloqueo` eran `bit(1)`. PDO devuelve ese tipo como un
  byte binario, no como número: `(int)"\x01"` da **0**, así que los dos
  administradores existentes habrían dejado de serlo. Convertidos a `tinyint(1)`.
- `estados.class` es NULL en «Pte. Terceros», «Pte. Usuario» y «Programado»;
  declarar la columna NOT NULL hacía desaparecer esos tres estados en silencio.
- Cuatro filas de `rel_zonas` apuntaban a un usuario inexistente. Como un INSERT
  masivo es todo o nada, se perdían las 1.285 ubicaciones enteras. El cargador
  ahora reintenta fila a fila si un bloque falla.
- `detalle_ticket`, `id_rel_zona` e `id_elemento` eran NOT NULL, lo que impide
  dar de alta un parte incompleto desde el móvil. La migración los relaja.
- `rel_zonas.id_zona_1` era obligatorio: no se podía registrar una avería de la
  que se conoce la zona pero no la planta.

**Datos**

- Todo en UTF-8 (antes ISO-8859-1).
- Se conservan los nombres reales: `elemento` en singular, con
  `nombre_elemento`, `abreviatura_elemento` y `baja`.
- Tablas nuevas: `prioridades`, `departamentos`, `adjuntos`, `api_tokens`,
  `notificaciones`, `preferencias`, `intentos_acceso`.
- Columnas nuevas: `uuid` y `updated_at` (imprescindibles para sincronizar),
  plazos, tiempo dedicado, coste, borrado lógico, rol e iconos por familia.
- Se conserva `id_version`, tu control de doble asignación, y ahora se usa de
  verdad: es la base de la detección de conflictos.
- `db/migracion.legacy.mysql.sql` está escrita contra el volcado real y no borra
  ni renombra nada.

**Funciones que estaban pendientes en tu lista de `panel.php`**

| Pendiente | Estado |
|---|---|
| Cambiar contraseña | Hecho (Ajustes › Cambiar la contraseña) |
| Menús para cambiar fácilmente | Hecho (barra inferior + hojas de elección) |
| Definir estética | Hecho (sistema visual completo, claro y oscuro) |
| Forzar el borrado de imágenes en servidor | Hecho (borrado lógico + del disco si no queda referencia) |
| Integrar departamentos | Hecho (tabla, asignación por persona y por tipo de avería) |
| Historial en detalles | Hecho (línea de tiempo en la ficha) |
| Corregir la X de cerrar avisos | Hecho (hojas con cierre por botón, velo y tecla Esc) |
| Editar desde la lista de incidencias | Hecho (la ficha se abre de un toque y permite actuar) |
| Calendario y límite de días | Hecho (plazos por prioridad y filtro de fuera de plazo) |
| Lista de elementos | Hecho (los 313 reales, con buscador e icono por familia) |

Quedan fuera, por necesitar servicios externos: la lectura de correo para crear
partes automáticamente y el envío de notificaciones push. La base está puesta:
`origen = 'email'` en los partes y el manejador `push` en `sw.js`.

**Fotos de la versión antigua**

En la 1.x las imágenes estaban sueltas en `ms/uploads/<id_ticket>/`, sin ningún
registro en la base de datos. `tools/importar_fotos.php` las recorre, las copia a
`api/uploads/` y las da de alta en la tabla `adjuntos`:

```bash
php tools/importar_fotos.php --origen=/ruta/vieja/ms/uploads --simular
php tools/importar_fotos.php --origen=/ruta/vieja/ms/uploads
```

Empieza siempre con `--simular`: informa de lo que haría sin escribir nada.
Es seguro repetirlo — las fotos ya registradas se detectan por su hash y se
omiten, así que dos copias del mismo archivo con distinto nombre no se duplican.
Los originales se conservan salvo que añadas `--mover`.

---

## Informes

La pregunta que se hace en un hotel no es «dame un listado»: es *«¿esta
habitación nos está dando guerra?»* o *«¿cuántas veces hemos ido a lo mismo?»*.
El informe se monta con tres decisiones —qué mides, cómo lo agrupas y qué
condiciones pones— y se puede guardar con nombre.

- **22 campos** para filtrar y agrupar: estado, prioridad, tipo, grupo, persona,
  las cuatro capas de ubicación, fechas, tiempo, coste, origen…
- **12 operadores**: es, no es, es uno de, contiene, empieza por, mayor, menor,
  entre, está vacío, en los últimos N días…
- **Condiciones combinables** con «se cumplen todas» o «basta con una».
- **5 medidas**: número de partes, minutos dedicados, coste, horas medias hasta
  el cierre y porcentaje resuelto en plazo.
- **Agrupación por campo o por periodo** (día, semana, mes, trimestre, año).
- Se puede ver el desglose, la lista de partes que hay detrás, y exportar a CSV.

Un detalle que importa: los nombres de habitación *son* números, así que «317»
podría ser un identificador o un nombre. Por eso las condiciones llevan un
indicador explícito de contra qué se compara, en vez de adivinarlo y devolver
cero sin explicación.

## Qué se puede encender y apagar

Todo lo que se ha añadido tiene interruptor en **Administración →
Configuración**, y lo que se apaga desaparece de la interfaz para todos:

| Grupo | Qué controla |
|---|---|
| Funciones activas | Informes, atajos, campos propios, grupos, tiempo, coste, prioridades, fotos, exportación, copias |
| Quién ve qué | Todos los partes, los del grupo o sólo los propios; consulta de otros grupos; qué ve quien sólo reporta |
| Avisos por defecto | Valor de partida de cada tipo de aviso; cada persona lo ajusta después |
| Comportamiento | Exigir nota al cerrar, autoasignarse al empezar, permitir crear ubicaciones, días de histórico |

Nada se borra al apagar: al volver a encenderlo, los datos siguen ahí.

## Números de serie

La mayoría de los 313 elementos no lo llevan: una cerradura, un zócalo o un
grifo se cambian y nadie los identifica. Por eso es **opcional y por tipo**: de
los 313, sólo 38 lo piden, y el resto de la aplicación no se entera.

Donde sí tiene sentido —televisores, climatización, maquinaria de cocina,
ascensores— se sigue la pista al aparato concreto, no al tipo. Eso permite
responder algo que el historial de partes no puede: **el parte habla de la
habitación, no del aparato que había puesto entonces**.

### Lo que se puede preguntar

| Pregunta | Dónde |
|---|---|
| Cuántas incidencias de TV ha tenido la 1008 | Informes: elemento + lugar |
| Qué aparatos con número de serie hay en la 1008 | Ficha de la ubicación |
| Los televisores de la 1008 con su estado | Ficha de la ubicación, por tipo |

La tercera es la que da la respuesta que se busca:

> **Tv — 5 en total**
> 1 en servicio · 1 por obsolescencia, 3 por rotura

Y debajo, cada uno con su número, su marca, desde cuándo estuvo y por qué se
fue. Encadenados: cada aparato sabe a cuál sustituyó.

### Cómo se mantiene solo

Al dar de alta un aparato indicando que sustituye a otro, **el anterior queda
de baja automáticamente**. Dos televisores no pueden estar puestos en la misma
pared, y olvidarse de dar de baja el viejo es lo que ensucia un inventario con
el tiempo.

Los movimientos quedan registrados: si un aparato se mueve de habitación, la
anterior sigue sabiendo que lo tuvo. Sin eso, la pregunta «qué ha tenido la
1008» quedaría incompleta en cuanto algo cambiara de sitio.

Un número repetido avisa pero no bloquea: dos fabricantes distintos pueden
coincidir, y obligar a inventarse un número sería peor.

### Qué tipos lo llevan

Se marca por elemento desde el catálogo. La instalación parte de 38 marcados
por su nombre —televisores, climatización, cocina, informática, bombas,
ascensores—. Conviene revisarlo: yo he clasificado leyendo nombres.

## Instrucciones

En un hotel la plantilla rota cada temporada. Lo que sabe hacer el técnico que
lleva ocho años se pierde cuando se va, y el siguiente vuelve a aprenderlo a
base de equivocarse.

Una instrucción es eso escrito una vez: cómo se purga el circuito del aire, en
qué orden se arranca la cocina, qué valores debe dar la analítica de la piscina.

**Lo importante: se leen sin cobertura.** Viajan con el catálogo al móvil, no se
piden al abrirlas. Quien las necesita está en un sótano, en la cubierta o en un
cuarto de máquinas — que es justo donde una base de conocimiento que exige
conexión no sirve de nada. Es la diferencia con la de GLPI.

Cada una puede llevar un **aviso** de lo que no se debe hacer nunca, y sale
destacado en rojo antes que el resto del texto. Si sólo se lee una línea, que
sea esa.

Aparecen solas en la ficha del parte cuando corresponden a su tipo o a su área:
quien abre «no enfría el aire» ve ahí mismo cómo se purga. Y se buscan por
cualquier palabra del cuerpo, no sólo del título — quien busca «purgar» no se
acuerda de si estaba en el título.

Formato deliberadamente simple: numera los pasos o usa viñetas con «·». Se
escriben en el móvil, entre dos averías, y pedir sintaxis complicada garantiza
que nadie escriba ninguna.

## Seguridad

**Registro de accesos y cambios.** Queda anotado quién entra, desde qué IP, con
qué navegador, y qué toca: contraseñas, correos, permisos, configuración,
copias, modo mantenimiento. Sirve para reconstruir un acceso indebido y para
demostrar que algo se hizo cuando alguien dice que no.

La pantalla destaca las **direcciones con muchos fallos** en la última semana,
que es lo que delata a alguien probando contraseñas.

Las IP son un dato personal, así que se borran solas pasado el plazo
configurado (90 días por defecto) y la pantalla lo dice.

**Contraseña olvidada.** Se pide desde la pantalla de acceso y la petición
aparece en Administración → Contraseñas olvidadas, con la IP desde la que se
pidió. El administrador emite un código de seis dígitos y lo entrega **en
persona o por teléfono**.

Se hizo así a propósito: montar un servidor de correo sólo para esto no compensa
en un hotel, donde todo el mundo está en el mismo edificio. El código caduca en
una hora, admite cinco intentos, sirve una sola vez, y al usarlo **caen todas
las sesiones anteriores**.

**Cambio de correo.** Cada persona puede cambiar el suyo, confirmando con su
contraseña. Se pide porque el correo es con lo que se entra: cambiarlo sin
confirmar sería regalar la cuenta a quien pille el móvil desbloqueado.

## Modo mantenimiento

Cierra el acceso a todo el mundo menos a los administradores, para actualizar o
restaurar una copia sin que nadie escriba a la vez. Se activa desde
Administración con un interruptor y un mensaje personalizable.

Lo que sí sigue pasando: **la cola de subida de los móviles**. Si alguien tiene
partes guardados sin enviar, se envían igual. Bloquear eso significaría hacerle
perder trabajo por una actualización, que es justo lo contrario de lo que se
busca.

## Avisos

Todo lo que suena, vibra o aparece está en una sola pantalla, separada de los
ajustes generales porque es lo que más se toca.

- **En el aparato**: sonido, vibración y permiso de avisos del sistema.
- **Horario de silencio**: los avisos siguen llegando, pero callados. Con la
  excepción de los urgentes, que suenan igual — una avería urgente a las tres
  de la mañana sigue siendo urgente.
- **Por tipo de aviso**: cada uno se activa o desactiva por separado, y por
  separado se decide si suena o llega callado. Con un botón para escuchar cómo
  suena antes de decidir.

Los tonos se generan en el móvil, así que funcionan sin cobertura y no ocupan
nada. Cada uno suena distinto para poder distinguirlo sin sacar el móvil del
bolsillo.

## Mantenimiento programado

Un hotel no sólo repara lo que se rompe. Tiene trabajos que toca hacer aunque
nadie avise, y algunos los exige la normativa: extintores, legionela, alumbrado
de emergencia, campanas de cocina, ascensores.

Sin esto hay que acordarse a mano, y lo que se lleva a mano se olvida justo el
mes que viene la inspección.

Cada revisión tiene su periodicidad y su fecha. Cuando le toca —con la
antelación que se configure— **genera un parte normal**: con su historial, sus
fotos y la firma de quien lo hizo. Se eligió eso en vez de un registro aparte
porque es exactamente lo que hace falta enseñar en una inspección.

Detalles que importan en el uso real:

- Si el parte anterior sigue abierto, **no se crea otro**. Acumular revisiones
  sin hacer sólo produce ruido, y quien las tiene pendientes ya lo sabe.
- Si la fecha se quedó muy atrasada, el calendario **salta hasta la próxima**
  en vez de generar un parte por cada ciclo perdido.
- Las obligatorias se marcan, y su aviso lo dice.
- El grupo responsable se hereda del área: una revisión de cocina va a Cocina.

Una revisión puede **terminar**, en vez de repetirse para siempre: se le pone un
número de veces (las seis del contrato), una fecha final (hasta que cierre la
piscina), o las dos —acaba con la primera que llegue—. Al agotarse se desactiva
sola y se queda con su historial, que es la prueba de que se hizo. En blanco se
repite indefinidamente, como antes.

**Y en la lista dice por qué terminó**: «terminada: ya se hizo las 4 veces
previstas» o «terminada: pasó su fecha final (31/12/2026)». Antes el motivo sólo
quedaba en el registro de «Comprobar ahora», que no lee nadie: en la lista
aparecía «terminado» a secas —o nada, cuando el tope era una fecha—, y no había
manera de distinguir una revisión que se agotó sola de una que alguien apagó a
mano.

**La generación no necesita cron.** La lanza la primera persona que abre la
aplicación cada día, con un candado para que treinta personas entrando a la vez
no generen treinta veces lo mismo. Antes dependía de una tarea programada, y en
una instalación donde nadie la había puesto —lo normal en un hotel— no se
generaba nunca un solo parte de mantenimiento: el calendario se veía bien y no
pasaba nada.

Si se prefiere que lo lance el servidor a una hora fija, el cron sigue valiendo
y comparte el mismo candado, así que no duplica nada:

```cron
30 6 * * * php /ruta/sstt/tools/mantenimiento.php >> /var/log/sstt-mant.log 2>&1
```

También se puede lanzar a mano desde la aplicación, y siempre pregunta antes de
crear nada.

```bash
php tools/mantenimiento.php --listar    ver el calendario
php tools/mantenimiento.php --simular   qué haría, sin hacerlo
```

## Dos editores para las instrucciones

Una instrucción de cuatro pasos se escribe antes a mano que con cualquier
editor, así que **el editor de siempre se queda**. Al lado hay un
conmutador: **Sencillo** / **Manual con fotos y pasos**.

**Dónde está.** En `/instrucciones`, arriba de la lista, hay dos botones
con su nombre: **«Nota rápida»** y **«Manual con fotos, pasos y vídeo»**.
El segundo abre ya en el editor avanzado. Antes de esto sólo había un
«+» sin texto en la cabecera, y el editor avanzado quedaba escondido
detrás: estaba puesto y funcionando, pero no había manera de encontrarlo.
Un botón sin nombre es un botón que no existe.

El avanzado es [Editor.js](https://editorjs.io), empaquetado en
`www/assets/vendor/editorjs` porque SSTT no carga nada de ningún CDN: los
móviles del hotel pueden no tener internet. Trae pasos numerados, fotos,
recuadros de aviso, listas, tareas, tablas y enlaces a vídeo.

**Sólo se descarga al escribir.** Son 400 KB, y quien lee un manual no
tiene por qué bajárselos: se traen con `import()` cuando alguien pulsa
«Manual con fotos y pasos». Comprobado: abrir la lista de instrucciones y
hasta abrir el panel de edición piden **cero** ficheros del editor.

**Lo que se guarda es la estructura, nunca HTML.** La columna `formato`
dice si `contenido` es texto plano —como siempre— o el JSON de bloques, y
el HTML lo construye `www/assets/js/bloques.js` escapando todo. Así no
hay nada que sanear en PHP: lo que no se guarda no puede ejecutarse. Ese
fichero es lo único que se carga para leer, y pesa nada.

Lo ya escrito **no se convierte**: se queda en `texto` y se lee igual.
Cambiar de editor conserva lo escrito —las líneas numeradas o con viñeta
se convierten en pasos—, y al volver al sencillo avisa de lo que se
pierde antes de hacerlo.

Las fotos y los vídeos de los manuales van a `instrucciones_medios` y no
a `adjuntos`, porque allí cada fila cuelga de un parte y la foto de un
manual no pertenece a ninguna avería.

### El vídeo

Dos bloques distintos, porque resuelven casos distintos:

- **Vídeo**: un clip subido al servidor, hasta 40 MB de fábrica. Funciona
  sin internet y sin certificado, y se ve en el sótano. Se puede bajar el
  tope desde **Configuración → «Tope del vídeo de los manuales (MB)»**.
- **Vídeo de fuera**: un enlace a YouTube o Vimeo. No ocupa disco; a
  cambio el móvil necesita internet, así que en el sótano no se verá. Se
  enseña como enlace y **no** como `iframe`: meter una página ajena
  dentro de la aplicación dejaría un hueco negro sin explicación cuando
  no hay red.

El tamaño se comprueba **antes** de subir: mandar 40 MB por la wifi del
hotel para que el servidor diga que no es perder tres minutos de alguien.

**Y el tope real no es el que pide la configuración.** Manda el más bajo
entre `max_bytes`, `upload_max_filesize` y `post_max_size`. Eso estaba
sin comprobar y era una mentira silenciosa: la configuración pedía 12 MB
y con `upload_max_filesize = 2M` cualquier foto de móvil fallaba con un
error que no hablaba de ningún límite, porque PHP descarta la petición
antes de llegar al código. Ahora lo calcula `Uploads::topeReal()`, lo
enseña el editor y lo avisa el diagnóstico. Cómo subirlo, en
`docs/SUBIDAS.md`.


## Buscar, filtrar y actuar en masa

Seis pantallas de la aplicación son listas que crecen con el uso, y
ninguna tenía buscador: con cincuenta personas dadas de alta, encontrar a
Marta era bajar rodando. Ahora lo tienen todas, con la **misma pieza**
(`www/assets/js/listas.js`): un buscador, filtros en pastillas y
selección múltiple.

| Pantalla | Filtros | En masa |
|---|---|---|
| Equipo | acceso, papel, departamento, si han entrado | dar o quitar acceso, cambiar departamento, cambiar papel |
| Importar / Exportar personas | — | sacar las fichas marcadas |
| Mantenimiento programado | fuera de plazo, toca pronto, desactivadas, obligatorias | activar, desactivar |
| Frases del día | activas, categoría | activar, desactivar, borrar |
| Errores | en el móvil o en el servidor, repetidos | dar por vistos |
| Registro de seguridad | los que ya tenía, más búsqueda de texto | exportar lo filtrado |

Se hace con una sola pieza y no seis a propósito: seis implementaciones
de lo mismo son seis comportamientos distintos, y cinco sitios donde el
fallo que se arregla en uno sigue vivo.

**Dos decisiones que no son adorno:**

- **Se filtran los datos, no el DOM.** El buscador de filas que ya había
  esconde filas con una clase, y para seleccionar en masa eso no sirve:
  «marcar todos» tiene que marcar lo que se ve, no lo que hay escondido.
- **Una acción en masa sólo alcanza lo que está a la vista.** Si alguien
  marca diez personas, cambia el filtro y pulsa «quitar acceso», se lo
  quita a las que sigue viendo —no a las diez de antes—. Lo contrario es
  dejar sin acceso a gente que ya no estaba en pantalla.

Las acciones sobre personas van por **un solo viaje al servidor**
(`POST /api/usuarios/lote`), no cincuenta peticiones sueltas que se
quedan a medias con mala cobertura. Cada persona va en su propia
transacción: si una falla, las demás siguen y al final se dice **con
nombre y motivo** qué se quedó como estaba.

Y con las mismas protecciones que al editar de una en una, comprobadas
otra vez en el servidor: no se puede uno quitar el acceso a sí mismo, un
superadministrador sólo lo toca otro, y **no se puede dejar un hotel sin
ningún administrador con acceso** —ni bloqueándolo ni bajándole el
papel—. Un atajo en masa que se salte las reglas del camino individual es
la forma más fácil de abrir un agujero.

## Dar de alta a mucha gente de una vez

Hacerlo uno por uno en la pantalla está bien para tres personas. Para
cuarenta, no: **Administración → Importar / Exportar personas**.

Se descarga una plantilla, se rellena una copia por persona y se dejan
todas en `bak_config/upload/user/`. La aplicación las recorre.

**La plantilla se descarga, no se escribe a mano.** Sale de la base de
datos con los departamentos y los papeles de ESE hotel ya puestos, y sólo
hay que marcar:

```
Departamento:
  [ ] Servicios Técnicos
  [x] Pisos / Limpieza
  [ ] Recepción
```

Así nadie tiene que saber cómo se llaman las cosas ni escribirlas sin una
falta. Y si mañana se añade un departamento, la plantilla lo trae sola.

Lleva la ficha entera: hotel, usuario, correo, contraseña inicial, nombre,
**primer y segundo apellido**, teléfono, papel, departamento y departamentos
adicionales, los cuatro permisos sueltos, el mensaje de bienvenida, el aviso
para el próximo inicio y la frase del día.

El formato son tres reglas: una etiqueta es una línea que acaba en `:`,
su valor va debajo, y las líneas que empiezan por `#` son notas. Un texto
largo son varias líneas. No hay comillas ni comas: se rellena desde el
móvil si hace falta.

Hay un botón **«Probar sin crear nada»** que dice qué pasaría con cada
fichero antes de tocar nada. Con cuarenta ficheros, úsalo.

**Lo que pasa después:**

- Si la persona ya existe —mismo nombre de usuario—, **se actualiza** en
  vez de fallar. Eso es lo que hace útil exportar, corregir en un editor
  y volver a importar.
- Al darla de alta, **el fichero se borra**: lleva su contraseña inicial
  dentro y no puede quedarse en un .txt.
- Si uno falla, no se borra: se queda con `.error` al final y un
  `.motivo.txt` al lado diciendo qué corregir. Los demás siguen: cuarenta
  altas no se paran por una falta en la quinta.

**Exportar** escribe una ficha por persona en `bak_config/download/user/`,
y se pueden marcar todas de golpe. **Nunca llevan contraseña**: donde iba,
pone `****`. Si al reimportar se deja ese `****`, no se le cambia.

Las dos carpetas van con `.htaccess` que niega el acceso web, porque
llevan nombres, correos y contraseñas dentro. Si tocas la configuración
de Apache, comprueba que siguen sin verse desde el navegador.

## Los avisos

Tres caminos, y conviene saber qué hace falta para cada uno.

**Dentro de la aplicación.** Siempre funcionan: cada aviso se guarda y se
ve al abrirla, en «Mensajes».

**El número de la barra cuenta lo mismo en los dos sitios.** Son
conversaciones con algo sin leer más avisos sin leer, y lo calculan por
separado el servidor (al arrancar) y la pantalla (al abrirla). Contaban
cosas distintas: el servidor sumaba mensajes sueltos y la pantalla
conversaciones, así que la chapa cambiaba de número sólo con entrar a
mirarla —y dentro de la propia pantalla la pestaña ponía
«Conversaciones · 6» encima de un encabezado que ponía «Sin leer 1»—. Un
número que cambia solo al mirarlo hace dudar de todos los demás números de
la aplicación. Cuántos mensajes hay dentro de cada conversación se ve en su
propia chapa, que es donde esa cifra significa algo.

**Al móvil, con la aplicación cerrada.** Necesita **HTTPS**. Sin
certificado el navegador no entrega el service worker y nadie puede ni
suscribirse —y de paso tampoco funciona el modo sin cobertura—. No es un
ajuste: es el navegador. Mira `docs/HTTPS.md`.

```bash
php tools/avisos.php              cómo está todo
php tools/avisos.php --claves     deja las claves listas
php tools/avisos.php --probar 3   manda uno de prueba a esa persona
```

**Copia al chat de Microsoft Teams.** Para una empresa que ya vive en
Teams. Llega al chat privado de cada persona, con un botón que abre el
parte. Es de ida: lo que contesten en Teams no entra en el parte, y la
tarjeta lo dice.

Funciona **sin HTTPS**, porque lo manda el servidor a Microsoft y no el
navegador: es el único canal que puede estar en marcha antes de tener
certificado. A cambio hace falta trabajo en el Microsoft 365 de la
empresa —registro en Azure, un bot y una directiva de instalación—, que
está explicado paso a paso en `docs/TEAMS.md`.

Los avisos se apuntan en una bandeja de salida y se mandan después, en
tandas, con cuatro reintentos. Guardar un parte nunca espera a Microsoft.
Para ver dónde se rompe: **Administración → Comprobar la copia a Teams**.

## Se puede usar sin ratón y sin ver

Cada pantalla tiene su **encabezado principal**: un lector de pantalla anuncia
dónde se está al cambiar de sitio. Antes el título era un `<strong>` y no se
anunciaba.

Las **hojas atrapan el foco**. Al abrirse, el foco entra dentro; el tabulador da
la vuelta sin escaparse a lo que hay detrás del velo; y al cerrarse vuelve al
botón que la abrió. Sin esto, quien navega con teclado abría una hoja y seguía
recorriendo la pantalla de atrás, sin manera de llegar a las opciones que
acababa de pedir.

Todos los botones tienen nombre —ninguno queda mudo para un lector— y el foco
se ve siempre.

## Legible en los dos temas

El color de marca cambia entre claro y oscuro: en claro es oscuro, en oscuro se
aclara para destacar sobre el fondo. Lo que se escribe encima tiene que cambiar
con él, y por eso va en una variable —`--sobre-marca`— en vez de un blanco fijo.

Con blanco fijo, las pastillas activas y los botones principales se quedaban en
3,6:1 sobre el fondo oscuro: por debajo de lo legible para texto pequeño.
Ahora 5,1:1 en oscuro y 8,4:1 en claro.

## Los valores por defecto valen para todos los caminos

La prioridad y el departamento por defecto salen de **Configuración**, y los
aplica el servidor. Antes estaban escritos a mano en el código y sólo los ponía
la pantalla de alta: un parte creado desde la cola offline o desde fuera se
saltaba el ajuste, y se quedaba sin departamento — que es obligatorio y sin él
no se puede asignar a nadie.

Lo que venga indicado en la petición manda siempre sobre el valor por defecto.

## Quién ve los datos de quién

Correos, teléfonos y fecha del último acceso **sólo los ven responsables y
administradores**. El resto ve nombres, departamento y puesto: lo justo para
poder asignar un parte.

Importa porque el catálogo de la aplicación se descarga entero a cada móvil y
se queda guardado ahí. Mandarle el listín del hotel a un aparato que se pierde
en un pasillo no compensa para lo poco que aporta; quien necesite llamar a
alguien lo tiene en **Equipo**, que sí exige ser responsable.

## Copias de seguridad

**Administración → Copias.** Se guarda todo en un JSON comprimido, con las
contraseñas cifradas incluidas: sin ellas nadie podría volver a entrar después
de restaurar.

**Las tablas se descubren solas.** Antes había una lista escrita a mano, y cada
tabla añadida después —hoteles, mantenimientos, números de serie, qué
departamentos usa cada hotel— se quedaba fuera sin que nadie lo notara. La copia
se creaba, decía «ok», y al restaurarla faltaba media aplicación. Eso no se
descubre hasta el día del desastre.

Quedan fuera a propósito las sesiones abiertas, los intentos de acceso, los
códigos de recuperación y las direcciones de push: son de este servidor y de
este momento, no valen en otro sitio.

**Si el servidor no puede empaquetar las fotos, lo dice.** Hace falta la
extensión ZipArchive de PHP; sin ella la copia salía «ok» llevando la fila que
dice «este parte tiene una foto» pero no la foto. Al restaurar quedaban partes
señalando imágenes que ya no existen, y eso no se descubre hasta que alguien
abre un parte viejo buscando justo esa foto. Ahora avisa y recuerda copiar
`api/uploads` aparte.

Al restaurar se desactivan las claves foráneas mientras dura el proceso. Hace
falta: se vacían y rellenan todas las tablas, y en algún momento la base queda
incoherente por fuerza —al borrar los departamentos antes de rellenar las
personas, éstas apuntan a algo que ya no está—. Al terminar se reactivan y se
comprueba que todo cuadra.

Todo va en una transacción: si algo falla, la base se queda como estaba.

## Cerrar el acceso para trabajar tranquilo

**Configuración → Cerrar el acceso** deja fuera a todo el mundo salvo a los
administradores. Se usa para restaurar una copia o actualizar sin que nadie
escriba a la vez.

Con el acceso cerrado, cada móvil **puede entrar y subir lo que tenga
pendiente**, pero no baja nada: así nadie pierde el trabajo que hizo sin
cobertura mientras se restauraba. Eso ya estaba pensado; lo que faltaba era que
la aplicación no reventara al llegar a ese punto —soltaba una excepción justo
después de haber salvado el trabajo, y la persona no sabía si su parte se había
enviado—.

## Administrador y superadministrador son lo mismo

Todo **Administrador** tiene acceso total: vista smartphone, enlaces de
auto-entrada, papelera, hoteles y departamentos. Antes había un
«superadministrador» aparte, pero la pantalla de Equipo nunca marcaba a nadie
como tal, así que los administradores dados de alta desde la aplicación se
encontraban candados sin forma de quitárselos.

Los **responsables** no entran en Administración: ven el equipo y los informes,
reparten el trabajo de su departamento y poco más.

Si no se pasa el actualizador, los ajustes nuevos se crean solos la primera vez
que se abre Configuración.

## Vista smartphone

En un ordenador o una tableta, la aplicación puede verse **dentro de un teléfono
centrado en la pantalla**, con proporción 9:16, 9:19,5 o 9:20. Sirve para ver
desde el despacho lo mismo que ve el técnico en su móvil.

Lo decide un administrador en **Ajustes → Administración → Vista smartphone**:

| Modo | Qué pasa |
|---|---|
| No *(por defecto)* | Como siempre |
| Sí, para todos | Todo el mundo en teléfono, sin poder quitarlo |
| Habilitada y activada | Sale puesta; cada uno puede quitársela |
| Habilitada sin activar | Sale quitada; cada uno puede ponérsela |

Cuando se deja elegir, cada persona lo cambia en **Ajustes → Apariencia**, junto
con la forma del teléfono. Se guarda en su aparato, como el tamaño de la letra.

**Cómo funciona:** la aplicación se carga entera dentro de un `iframe` en
`marco.html`. Para ella es un móvil de verdad: su responsive, la barra de abajo
y las hojas que suben se comportan igual, porque la pantalla que ve es la del
iframe. Hacerlo con CSS no sirve: las reglas `@media` seguirían viendo el
monitor, y lo que va fijo a la pantalla se saldría de la caja.

En los móviles —también tumbados— y en la aplicación de Android no se enmarca
nunca. Si en el ordenador se estrecha la ventana hasta tamaño de móvil, el marco
desaparece y la aplicación ocupa todo.

**Es reversible por completo.** En «No», `marco.html` no se carga nunca y
`index.html` arranca exactamente igual que antes. Para quitarlo del todo basta
con borrar `marco.html`, `assets/js/vista-movil.js` y el bloque marcado como
«VISTA SMARTPHONE» al principio de `index.html`.

## Entrar con nombre de usuario

Se entra con **el nombre de usuario o con el correo**, lo que se tenga a mano.
El usuario es obligatorio y único; el correo es opcional, porque en un hotel hay
cuentas de puesto —«recepcion», «pisos»— que usa quien esté de turno y que no
son de nadie. Obligar a poner un correo ahí sólo consigue que se inventen
direcciones falsas, y entonces el día que haga falta avisar por correo no llega.

No distingue mayúsculas ni tildes: «recepción» y «recepcion» son la misma
cuenta.

## Enlaces de auto-entrada

**Equipo → una persona → Enlace de auto-entrada.** Un enlace que entra sin
escribir nada, pensado para la tablet fija de recepción.

Es una llave, no un atajo: quien lo tenga **es** esa persona. Por eso:

- Se guarda **cifrado**, no en claro. Quien se lleve la base no se lleva las llaves.
- Hay un **interruptor general** que los tumba todos a la vez, y sale apagado.
- Y uno **por persona**, para dárselo sólo a quien toca.
- **Regenerar mata el anterior al instante** — es lo que se hace al perder una tablet.
- Cada uso queda anotado aparte de una entrada normal, con su dirección.

La dirección lleva un identificador público y una llave de 64 caracteres, más el
hotel, la versión y el momento. La llave **desaparece de la barra de direcciones**
nada más usarse, para que no se quede a la vista ni viaje al siguiente sitio.

## Que cada campo sirva para algo

Las pruebas comprobaban que un formulario abre y que al pulsar «Guardar»
sale una petición. Eso deja fuera el fallo más corriente: **un campo que
se rellena, se guarda sin quejarse y no cambia nada**. No da ningún error;
semanas después alguien descubre que el teléfono de medio equipo está en
blanco.

`pruebas/campos.py` hace el viaje entero con cada campo: lo cambia a un
valor reconocible, guarda, y comprueba que está **en el servidor** —no
sólo en la pantalla, que puede estar enseñando lo que tiene el móvil— y
que vuelve a salir al reabrir la ficha.

Encontró dos cosas en la ficha de una persona, las dos del tipo
«funciona hasta que no».

**Un departamento retirado le borraba el departamento a su gente.** Si el
hotel retira un departamento, quien estuviera en él veía su ficha con
«Sin departamento» —esa opción ya no estaba en la lista— y **al guardar se
le borraba el suyo de verdad**. Nadie tocaba ese campo: bastaba con abrir
la ficha a cambiar un teléfono. Y un técnico sin departamento deja de ver
los partes de su equipo, sin que nada avise. Ahora la lista incluye
siempre el suyo, marcado como «(retirado)», y se avisa de que se mantiene
si no se cambia. Demostrado a las dos caras: con la versión anterior el
departamento desaparece, con ésta sobrevive.

**Marcar el departamento propio como «adicional» no hacía nada.** El
servidor lo descarta —con razón: ya lo tiene— pero la pantalla dejaba
marcarlo y luego no aparecía. Quien lo intentaba concluía, con razón, que
esto no funciona. Ahora sale aparte, sin poder pulsarse, con la etiqueta
«el suyo».

### Y una que me metí yo arreglando la anterior

Al sacar el departamento propio aparte, la lista de «Departamentos
adicionales» pasó a dibujar **un solo botón**: el suyo, apagado. Los otros
seis desaparecieron. La causa es tonta y vale apuntarla: comillas
invertidas **dentro** de otra plantilla con comillas invertidas. La de
dentro corta la de fuera por la mitad, y lo que sale es un botón y
escombros. Ahora cada botón lo construye una función aparte
(`botonDepartamentoExtra`), sin nada que anidar.

### La prueba se estaba comiendo los datos

Buscando ese fallo salió uno peor, y no estaba en el programa. La prueba
del «departamento retirado» retiraba **el departamento de verdad** de la
persona de prueba y no lo devolvía. Siete pasadas, siete departamentos
desactivados: el hotel se quedó **sin ninguno activo**, y entonces la
ficha, con razón, no ofrecía ninguno para elegir. Estuve un rato buscando
en el código un fallo que había metido la propia prueba en los datos.

Ahora se crea un departamento **desechable** para ese caso y se borra al
final, cuando ya no queda nadie dentro —mientras haya alguien, el
servidor lo desactiva en vez de borrarlo, y hace bien: borrarlo dejaría
partes sin saber de quién eran—. Es la segunda vez que una prueba
destructiva muerde los datos de demostración; la regla, ya sin
excepciones, es que **nada destructivo apunta a algo real**.

## Los botones cuyo efecto no se ve

El rastreo a ciegas da por muerto todo botón que no cambia la pantalla, y
señalaba veintidós. No lo estaban:

- **Dieciséis eran el filtro que ya estaba puesto.** Pulsar «Todas»
  estando ya en todas no tiene que hacer nada. Lo que sería un fallo es
  lo contrario.
- **Otros medían mal.** El detector comparaba el **largo** del HTML, y un
  filtro puede cambiar la lista entera sin cambiar el número de
  caracteres. «De mi grupo» funcionaba. Ahora compara qué filas se ven y
  qué está marcado, no cuánto pesa.
- **«Ver las 8 notas» baja al seguimiento**, donde están todas. No
  cambiaba nada del HTML: cambiaba el desplazamiento, que el detector no
  miraba.

Quedaban los que de verdad no se pueden ver desde ahí: los que bajan un
fichero o abren el cuadro de elegir foto del teléfono. Esos tienen ahora
prueba propia, por su efecto real —**`pruebas/descargas.py`**—: que
«Exportar» baja un CSV **con datos dentro** y columnas separadas, y que el
botón de foto abre el cuadro, sólo acepta imágenes, y la foto elegida se
queda puesta. En el parte nuevo ese botón es sólo un icono: su texto está
en `aria-label`, y buscándolo por el texto de dentro la prueba decía que
no había botón de foto habiéndolo.

## El alta de cincuenta personas

Era el único rincón que ninguna prueba podía mirar, y justo el que se va a
usar el primer día: los botones «Probar sin crear nada» y «Dar de alta»
**sólo existen cuando hay ficheros esperando**. Sin ficheros no hay
botones, así que nadie los había pulsado nunca.

**`pruebas/altas.py`** deja dos ficheros en `bak_config/upload/user` —uno
bien rellenado y uno roto a propósito— y comprueba el camino entero: que
la plantilla ofrece los departamentos de **este** hotel, que el ensayo
**no crea nada** y aun así dice a quién daría de alta, que el alta de
verdad crea a la persona con su departamento **y que esa persona puede
entrar** con la contraseña que llevaba el fichero, que el fichero se borra
después —lleva la contraseña dentro en texto claro— y que el roto no se
borra: se aparta con «.error» y se le deja al lado un fichero diciendo qué
corregir.

### La plantilla mandaba a la gente al hotel que no era

Con varios hoteles, la plantilla de alta venía con **el primero de la
lista** marcado. No es el de nadie: es el primero por orden. Quien rellena
cincuenta fichas seguidas no se fija en una casilla que ya está puesta, y
las cincuenta personas acaban en el hotel equivocado —un día de trabajo
deshacerlo, y mientras tanto no ven sus partes—.

Ahora viene marcado el hotel **en el que está quien se baja la
plantilla**, y lo dice: «Viene marcado «Hotel Benalma», que es en el que
estás. Cámbialo si esta ficha es de otro hotel.»

Lo encontró la propia prueba de altas: daba de alta a su persona y luego
no la encontraba en el equipo, porque estaba en el otro hotel.

## Borrar a una persona del todo no funcionaba

Quitar el acceso sí —que es lo que se hace casi siempre—. Pero el
**borrado definitivo** fallaba con `FOREIGN KEY constraint failed`: el
error de la base de datos, en crudo, en la pantalla de un administrador.

El borrado lleva una lista escrita a mano de qué pasa con cada tabla que
apunta a una persona: unas se borran, otras pasan a quien borra, otras se
quedan sin dueño. Esa decisión hay que tomarla una por una y la lista
estaba bien pensada. Lo que pasa es que se había quedado corta en **nueve
tablas**: los móviles donde recibía avisos, sus mensajes de chat, los
partes que seguía, sus departamentos de apoyo, sus permisos sueltos… Cada
una se añadió en su momento sin pasar por aquí.

Qué pasa ahora con cada una: los mensajes de chat **no se borran** —la
conversación quedaría con huecos y con respuestas a nada— y pasan a quien
borra, como los partes. Lo que era sólo suyo y no le sirve a nadie (los
móviles, las marcas de leído, los permisos) se borra. Y de los partes que
seguía deja de ser seguidor, pero quien él hubiera puesto a seguir sigue
estando.

**Que no vuelva a quedarse corta:** `pruebas/esquemas.py` lee el esquema,
saca **las 25 tablas que apuntan a `login`** y comprueba que todas están
en la lista del borrado. Una tabla nueva salta ahí, el día que se añade,
que es cuando hay que decidir qué pasa con ella —y no meses después, en
la pantalla de alguien—. Además `pruebas/altas.py` borra de verdad a una
persona que ha dejado rastro: ha entrado, ha escrito en un parte y lo
seguía.

Se descubrió por casualidad, y vale la pena decir cómo: las pruebas
dejaban detrás a toda su gente de mentira. Creían estar borrándola.

## Seguir el manual de instalación al pie de la letra

`docs/INSTALACION.md` es lo primero que alguien lee y lo va a copiar y
pegar tal cual. Nunca lo había seguido literalmente: lo instalaba a mi
manera, que no es la suya. Haciéndolo como está escrito salieron dos
cosas.

**`--forzar` no funcionaba en MySQL con los permisos que da el manual.**
El `GRANT` daba SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER e INDEX,
pero vaciar la base necesita **DROP**. Y lo peor no es eso:

**el instalador se lo tragaba como «aviso» y terminaba diciendo
«Listo».** Con `--forzar` se pide empezar de cero; si la base no se puede
vaciar, lo que queda es una mezcla —las tablas viejas con sus datos y las
nuevas encima— y el instalador despedía con un «Listo» que no era verdad.
Ahora se para, dice qué contestó la base de datos y, cuando es el permiso,
escribe el `GRANT` que falta con el nombre de la base y del usuario ya
puestos. Y sale con error, para que no pase desapercibido en un script.

El manual ya pide `DROP` y `REFERENCES`, con una nota de para qué sirve
cada uno y de que `DROP` se puede quitar después de instalar.

### Y un diagnóstico que avisaba en falso, culpa mía

La comprobación de permisos que añadí ayer miraba si podría escribir el
usuario del servidor web siempre que se ejecutara como root. Eso está
bien en `/var/www`, pero la **prueba rápida del propio manual** es
descomprimir en una carpeta y lanzar `php -S`: ahí los ficheros son de
root, los escribe root, y el diagnóstico decía «NO se puede escribir en
db» en una instalación que funcionaba perfectamente. Un diagnóstico que
avisa en falso se deja de leer, y entonces tampoco avisa de lo de verdad.

Ahora mira primero dónde está: bajo la carpeta de un servidor web, el que
tiene que poder escribir es el servidor; fuera de ahí, basta con quien lo
ejecuta. Comprobadas las dos: la instalación de prueba sale limpia, y la
de `/var/www` con los ficheros de root sigue cantando el fallo con la
orden para arreglarlo.

### El manual se prueba solo

`pruebas/instalacion.py` **saca las órdenes del propio documento** —no
las copia— las ejecuta en una carpeta nueva, comprueba que después se
entra con el usuario y la contraseña que el documento promete, y compara
los permisos del `GRANT` con los que el instalador necesita de verdad.
Si alguien cambia el manual por algo que no funciona, salta. Lo verifiqué
devolviendo el `GRANT` a como estaba: lo cantó nombrando los dos permisos
que faltaban y para qué sirve cada uno.

## La base de datos entera se descargaba escribiendo la dirección

Esto es lo más serio que ha salido en toda la revisión, y no lo habría
encontrado ninguna prueba de las que había: no pasa por el programa.

En la instalación de pruebas, sin sesión, sin token, sin tocar nada:

```
http://servidor/sstt/db/sstt.sqlite   →  200, nueve megas
```

La base entera. Los partes, las personas, `pwd_php`, `pwd_sha2` y
`autologin_hash` —que es una llave de entrada directa, no un resumen de
contraseña—. Y al lado `sstt-papelera.sqlite`, que guarda copia completa
de todo lo borrado. También estaban abiertas las copias de seguridad, las
fotos de todos los partes y la carpeta de paquetes.

Lo descargué y lo abrí para no quedarme en la sospecha: 48 tablas, los 7
usuarios, los 35 partes.

**El candado del proyecto estaba puesto y era correcto.** `db/.htaccess`
dice `Require all denied` y hasta explica por qué. Lo que pasa es que
**Apache en Debian y en Ubuntu viene de fábrica con `AllowOverride
None`**, y con eso no lee ningún `.htaccess` de nadie. El candado es un
papel pegado en una puerta que nadie mira. En nginx es peor: allí los
`.htaccess` ni existen.

Y desde dentro las dos situaciones son idénticas. Cualquier comprobación
que mire «¿está el fichero de candado?» dice que sí en los dos casos. Por
eso el diagnóstico lo miraba y daba OK.

Tres cosas:

- **`tools/diagnostico.php` ahora lo pide por HTTP**, no mira el candado.
  Si el servidor sirve algo que no debe, sale en rojo con la lista de lo
  que se lleva quien lo pida y la orden exacta para cerrarlo.
- **`pruebas/expuesto.py`** hace lo mismo desde fuera, y entra en la tanda.
- **El manual** ya no menciona `AllowOverride All` de pasada entre
  paréntesis: lleva el bloque `<Directory>` entero y dice qué se pierde
  si falta. El bloque de nginx incluía `db` y `tools`, pero no las copias
  ni los paquetes; ya los incluye.

Verificado en las dos direcciones: con `AllowOverride None` la prueba
canta las seis carpetas abiertas y el diagnóstico sale en rojo; poniendo
`AllowOverride All` y recargando, las seis se cierran y los dos pasan a
verde. Es decir: la orden que pone el manual es la que arregla esto, y
está comprobado que lo arregla.

Mientras esto siguiera así, cambiar las contraseñas no habría servido de
nada: la base se descarga con ellas dentro.

## 7.90.0 — HuraKan Tick, ya desde el código

Hasta la 7.89, cada versión salía de aquí como «SSTT · Partes de
trabajo» y se convertía en HuraKan Tick después, a mano, con siete
scripts de reemplazo: los correos y la contraseña de la demo, los dos
logos, los títulos, la versión y el nombre de la API. Ahora viene así de
fábrica y esos scripts sobran.

Aplicarlos tal cual habría roto tres cosas, y una de ellas ya estaba
rota en cada versión publicada sin que se notara:

**La versión salía como «?».** `version.py` renombraba la etiqueta
`<meta name="sstt-version">`, pero cuatro sitios la buscan por ese
nombre: la pantalla de entrada, «Mi cuenta», el aviso de arranque de
`index.html` y `diagnostico.html`, que la compara con `version.json` para
saber si quedaron ficheros sin subir. Con el nombre cambiado, los cuatro
se quedaban sin versión. Ahora se llama `hurakan-tick-version` y la leen
los cuatro por su nombre nuevo; comprobado en la pantalla de entrada:
«Versión 7.90.0».

**El diagnóstico habría dado un OK falso en lo de la cuenta de
fábrica.** Miraba si seguía activo un usuario *llamado* `admin`. Con los
correos nuevos el administrador se llama `daniel.melgar`, así que habría
dicho «no queda ninguna cuenta de fábrica» mientras ese usuario entraba
con la contraseña escrita en el manual. Comprobado sobre la misma base:
la comprobación vieja decía OK. Ahora prueba **la contraseña** contra
cada administrador activo; sale en rojo con `daniel.melgar` recién
instalado y en verde al cambiársela.

**La batería entera se habría quedado en la puerta.** El nombre de
usuario sale del correo, y las pruebas entraban como `admin`, `ana`,
`luis`… casi doscientas veces. Ahora hablan de puestos y sólo al entrar
se traducen a la persona que lo ocupa (`pruebas/entorno.py`). Y deciden
qué demo hay mirando la base, no el código: una instalación de antes
actualizada con el código nuevo sigue teniendo dentro a la gente de
antes, con su contraseña de antes.

### Lo que se ha cambiado y lo que no

- **La contraseña de la demo está en un solo sitio**,
  `tools/clave_demo.php`. La leen la demo, el instalador, el diagnóstico
  y las pruebas.
- **Una sola versión.** Había cinco a la vez: 3.0.1 en la etiqueta y en
  `version.json`, 7.89 en el trabajador de servicio, 2.0.0 en
  `package.json` y otra 2.0.0 en lo que contestaba la API. Ahora todas
  dicen 7.90.0, la API la **lee** de `version.json` en vez de llevar la
  suya, y `pruebas/clases.py` falla si alguna se desvía.
- **Ningún «SSTT» a la vista.** Además de lo que tocaban los scripts:
  el aviso de prueba de las notificaciones, los mensajes de Teams, la
  cabecera de las fichas de personal, el asistente de instalación, la
  guía, el diagnóstico, «Entrando…», el nombre corto bajo el icono del
  móvil y los manuales. También lo vigila `clases.py`.
- **Y también los nombres internos.** Se pensaron dejar como estaban,
  porque cambiarlos con gente usando la aplicación cierra la sesión de
  todos y rompe las direcciones guardadas. Como todavía no la usa nadie,
  era el único momento barato: carpeta `/hurakantick`, base
  `hurakantick.sqlite`, claves del navegador `hurakantick_*`, variables
  `HURAKANTICK_*`, caché del trabajador de servicio y aplicación de
  Android `com.hurakancode.hurakantick` (ese identificador queda fijo
  para siempre en cuanto se publica en una tienda). Una instalación de
  antes se actualiza sin perder nada: el instalador renombra la base, y
  mientras no se pase, la aplicación sigue usando la de antes en vez de
  presentarse como sin instalar —con el asistente abierto a cualquiera—.
  Comprobado sobre una 7.89 de verdad: 28 partes antes y 28 después.
- **Lo que no se ha tocado, a propósito**: `david.sstt@hotelbenalma.com`
  (es el correo de una persona) y `/var/www/sstt-antiguo` (la carpeta
  del sistema viejo, de la que se importan las fotos).
- **El logo, con respaldo.** El fichero `HuraKan_Tick_Logo.png` no está
  en el proyecto. Mientras falte se ve el icono de siempre en su caja de
  color, no una imagen rota; con el fichero puesto, sale el logo.
  Comprobadas las dos cosas en la pantalla de entrada. El trabajador de
  servicio lo guarda si existe, aparte de la lista obligatoria: si
  estuviera en ella y faltara, no se guardaría nada.
- **Tres retoques sobre los scripts**: el subtítulo «Ticketing» conserva
  su `<p>` (sin ella perdía el gris y el tamaño); el título lleva
  espacio antes del punto, «HuraKan Tick · Ticketing», como el resto; y
  en comentarios de código no se ha puesto número de versión, porque un
  número en un comentario se queda viejo en la siguiente.
- **José y Rosa** no estaban en la lista de correos y siguen siendo
  cuentas de la demo: en los manuales pasan a `test1@` y `test2@`, que
  son las cuentas que ocupan su sitio.

### Lo que salió por el camino

**El respaldo del logo fallaba por https.** Lo puse para que, sin el
fichero del logo, se viera el icono de siempre en vez de una imagen rota.
Por http funcionaba; por https, `pruebas/sincertificado.py` cazó
«Cannot read properties of null (reading 'innerHTML')». La plantilla
con el icono iba después de la imagen, y el trabajador de servicio
contesta el 404 del logo tan deprisa que el error saltaba antes de que
el navegador la hubiera leído. Ahora va delante, y si aun así no está,
la imagen se quita sin más.

**`tools/equipo_benalma.php` no retiraba a nadie, y en una base usada
fallaba.** Es el que cambia la gente de ejemplo por el equipo real.
Reconocía a la gente de ejemplo por `@sstt.local`, que con la demo nueva
ya no existe: «0 cuentas de ejemplo retiradas», y «Test 1», «Test 2» y
la recepción de ejemplo se quedaban activos junto al equipo de verdad,
con la contraseña del manual. Ahora sabe quién es de ejemplo por la
propia lista de la demo.

Y al retirarlas, llevaba **su propia lista a mano** de las tablas que
apuntan a una persona, a la que le faltaban siete —el mismo fallo que ya
tuvo el borrado de personas de Administración—. Comprobado con la 7.89:
en cuanto una cuenta de ejemplo había usado el chat, el script fallaba
con «FOREIGN KEY constraint failed» y deshacía todo. Ahora las dos usan
la misma lista, en `api/lib/Bajas.php`. Con actividad en siete tablas,
retira las tres cuentas sin problema.

Además ponía `benalma2026` a todo el equipo: una **segunda** contraseña
conocida que el diagnóstico no sabía que existía. Ahora es la misma que
la de la demo, del mismo fichero, y el diagnóstico la caza.

**Saber si hay una versión nueva.** Administración → «¿Hay una versión
nueva?» y `php tools/diagnostico.php` preguntan a
`hurakancode/HuraKan-Tick`. Ese repositorio estaba vacío, así que se
entienden las tres formas de publicar —una *Release*, la etiqueta más
alta, o un `version.json`— y `pruebas/actualizaciones.py` las prueba
con un GitHub de mentira en la máquina, junto con el repositorio vacío y
la falta de red. No descarga ni instala nada, no manda nada del hotel,
pregunta como mucho cada doce horas, y un fallo de red no se guarda
(taparía doce horas una versión nueva). Las dos últimas cosas,
verificadas rompiéndolas a propósito.

**Los datos de una instalación se perdían al actualizar… en la prueba.**
`pruebas/actualizar.py` simula descomprimir la versión nueva encima
copiando la carpeta de trabajo sin la base. La excepción nombraba la base
por su nombre; el renombrado le cambió el nombre, y la carpeta de trabajo
tenía un `db/sstt.sqlite` vacío olvidado desde octubre: la copia pisó la
base de verdad. El paquete no lleva bases, así que un hotel no lo habría
sufrido, pero se cierra por tres lados: fuera la base suelta, la prueba
excluye cualquier `.sqlite` y no un nombre, y `pruebas/clases.py` falla si
aparece una base dentro del código.

**Y destapó uno que sí era del producto:** el instalador creaba su
administrador «si no existe este correo». Con el correo nuevo, cada pasada
del instalador en una instalación cuyo administrador se llama de otra
forma —todas las anteriores, y la de cualquier hotel que no sea el
Benalma— añadía un administrador más con una contraseña al azar. Ahora se
crea sólo si no hay ninguno.

**El paquete se monta con `./empaquetar.sh`.** Se montaba a mano, y el de
esta versión salió **sin los 35 scripts de SQLite**: para quitar las bases
usé `db/*.sqlite*`, que también casa con `schema.sqlite.sql`. Se vio al
instalarlo desde el zip, que es lo que se hace siempre antes de darlo por
bueno. El script comprueba lo que mete —esquemas, candados, que no haya
ninguna base ni configuración de nadie— y, si algo falla, no deja
paquete. A la primera ya cazó otra cosa: el candado de
`api/uploads/instrucciones/` no estaba en el código, y en las versiones
anteriores lo había ido añadiendo yo a mano al montar.

### Segunda vuelta: lo que aún decía otra cosa

**La aplicación Android seguía llamándose «Partes».** Di por renombrada
la aplicación y no lo estaba: el nombre bajo el icono del móvil sale de
`capacitor.config.json` y de `strings.xml`, que no había mirado. Ahora
dice «HuraKan Tick», y `pruebas/clases.py` falla si vuelve a decir otra
cosa (comprobado poniéndole «Partes» otra vez).

**Un aviso al móvil sin título salía como «SSTT».** Era el título por
defecto del trabajador de servicio. Y el icono de respaldo tenía
`aria-label="SSTT"`: lo que lee en voz alta un lector de pantalla.

**«Daniel Melgar Sistema».** `tools/equipo_benalma.php` actualiza al
administrador que crea el instalador («Administrador / del / Sistema»),
pero sólo ponía nombre y primer apellido: el segundo se quedaba, en las
listas, en los historiales y en cada parte. Ahora lo vacía.

**Seis capturas de la guía con gente que ya no existe**: la entrada con
`admin / sstt2026`, el alta de una persona con el formulario de antes, el
equipo con «Ana Ruiz Molina» y los mensajes entre Rosa, Luis y el
«Administrador del Sistema». Rehechas con el equipo y la contraseña
actuales.

### Y rehaciendo las de Mensajes, dos fallos del chat

Para las capturas monté cuatro conversaciones y las miré como Carlos
Duarte. Había leído la de Daniel, y su aviso seguía ahí.

**Leer una conversación no apagaba sus avisos.** Cada mensaje deja el
mensaje sin leer en la conversación **y** un aviso «Mensaje · T-…» en la
campana. Leer la conversación marcaba lo primero y no lo segundo: con los
seis mensajes de Fany leídos, seis avisos sin leer y la campana en rojo.
Ahora `Chat::marcarLeido` apaga también los avisos de chat de ese parte y
de esa persona; un cambio de estado del mismo parte, no.

**Y la chapa de «Mensajes» contaba cada mensaje dos veces.** Sumaba las
conversaciones sin leer más todos los avisos sin leer, y esos avisos
incluían los de los mismos mensajes: seis mensajes de Fany contaban 7. La
guía —y el comentario del propio código— prometen que una conversación
cuenta una. En la captura: la chapa ponía 28 donde ahora pone 17
(3 conversaciones + 14 avisos de verdad). La pestaña Avisos de esa
pantalla deja de repetir los mensajes, que ya están en Conversaciones; la
campana de arriba los sigue teniendo.

`pruebas/mensajes.py` (nueva: no había ninguna prueba del chat, sólo de
permisos entre hoteles) comprueba las dos cosas por la API, y que no se
apaguen avisos de otra persona ni de otro tipo. Contra el código de
antes falla en las dos; con el arreglo pasa en SQLite y en MySQL. Una
vez me dio fallo con el arreglo ya puesto: era la caché de PHP sirviendo
el fichero viejo los dos segundos que tarda en revalidar.

### Tres herramientas dejaban escribir desde el navegador sin sesión

Revisando `tools/` encontré que `importar.php`, `ubicaciones.php` y
`clasificar.php` se abren por web con `?clave=instalar` —una palabra que
está en el manual, no un secreto— para poder **ensayar** sin consola. El
problema: también **escribían**. Con la dirección y nada más, un
desconocido de la red del hotel podía crear personas, departamentos y
ubicaciones en la base (`&aplicar`), volcar un CSV a **cualquier ruta**
del servidor (`ubicaciones.php?...&csv=/lo/que/sea`) o leer **cualquier
fichero** (`importar.php?...&archivo=/etc/...`). Es de la misma familia
que el instalador abierto, pero peor: aquel sólo aplica ampliaciones de
esquema; éstas meten datos y tocan el disco.

Ahora, desde el navegador, esas tres sólo ensayan. Para escribir hace
falta lo mismo que para reinstalar: la consola del servidor, o el fichero
`db/REINSTALAR` (que demuestra acceso a los ficheros, cosa que un intruso
con la URL no tiene). Y las rutas de fichero que llegan por el navegador
quedan confinadas a `db/` —con `../` incluido—, así que ni se lee ni se
escribe fuera. El asistente de instalación sigue pudiendo hacer todo esto
como parte de una instalación: su primer paso ya pasa ese mismo control.
La comprobación vive en un solo sitio, `tools/permiso_navegador.php`, para
que las tres no se separen con el tiempo.

`pruebas/expuesto.py` —la que mira qué se lleva un desconocido— ahora pide
esas escrituras sin sesión y falla si alguna contesta en vez de cortar.
Comprobado quitando el candado de una: lo caza.

### Cada papel por separado, como si lo usaran de verdad

Se pidió una confirmación al 100 % de que no hay errores ni fallos de
seguridad. Eso no lo puede dar ninguna prueba: lo que sí se puede es cubrir
cada papel, cada pantalla y cada ruta, y dejar escrito lo que no se ha
podido mirar. Tres pruebas nuevas y un panel:

**`pruebas/matriz.py` — cada ruta contra cada papel.** Las 134 llamadas que
hace la aplicación, pedidas como recepción, técnico, técnica de Pisos,
responsable, administrador y sin sesión, comparadas con lo esperado (783
respuestas). Sin escribir nada: el permiso va antes que los datos, así que
con el cuerpo vacío quien no puede recibe 401/403 y quien puede, 422/404.
Encontró:

- **Recepción o cualquier técnico podía rebautizar un departamento** para
  todo el hotel. El alias sólo miraba que fueras de ese hotel, no que lo
  administraras. Probado de verdad («Lo ha cambiado recepción») y deshecho.
- **Un responsable podía crear y editar tipos de avería, y montar
  ubicaciones en bloque**, con una petición a mano: ninguna pantalla se lo
  ofrecía, y la guía y `docs/PENDIENTE.md` lo dejan en manos del
  administrador. Ahora la API dice lo mismo que la pantalla. *Si se quería
  que el responsable pudiera, es una línea en
  `api/routes/catalogos_admin.php`, y habría que darle la pantalla.*

Y comprueba aparte lo que una tabla de códigos no ve: los secretos de la
configuración con valor puesto (no viajan a quien no administra), partes,
fotos, chat e informes ajenos con números reales (todo cerrado), y 221
peticiones con SQL hostil en cada filtro, en los dos motores (nada).

**`pruebas/jornada.py` — un día de trabajo por la pantalla.** Recepción da
un parte y pregunta; el técnico se lo queda, anota, lo pone en curso y
contesta; la técnica de Pisos trabaja lo suyo e intenta abrir lo que no es
suyo; el responsable lo reasigna, mira el equipo y saca un informe; el
administrador recorre toda la administración y lo cierra. Todo lo que
escriben —partes, notas, mensajes, una instrucción con cada tipo de bloque,
una ubicación, el apellido de una persona— lleva HTML hostil. Falla si algo
se ejecuta, si no se ve como texto, ante cualquier error de página o de
consola, un 5xx o un 403 que provoque la propia aplicación, y comprueba en
el historial que cada paso **ocurrió**. Encontró:

- **Una ubicación creada desde un parte se guardaba sin hotel**, y el
  buscador —que filtra por hotel— no la volvía a encontrar nunca. Ahora se
  guarda con el suyo, y `instalar.php --actualizar` arregla las que ya
  hubiera tomando el hotel de sus propios niveles (no «el primero», que con
  varios hoteles mandaría alguna al que no es).

Me costó varias vueltas que la prueba fuera de fiar, y lo dejo dicho: la
primera versión daba «bien» con pasos que no se habían hecho (pulsaba
«Cancelar» en vez de «Guardar», buscaba «En Curso» sin el punto de color
que lleva delante, y la técnica de Pisos se saltaba su parte en silencio).
Ahora cada paso que no se puede dar es un fallo con lo que había en
pantalla, el historial tiene que tener las seis anotaciones, y el detector
de HTML ejecutado se prueba a sí mismo al empezar. Comprobado rompiendo a
propósito el escapado de la conversación: lo cazan los cinco papeles.

**Administración → Integridad (`api/lib/Integridad.php`).** Treinta y
cinco comprobaciones de **sólo lectura** sobre TUS datos y TU servidor, que
ninguna prueba puede mirar: partes sin hotel, con estado, departamento,
persona o ubicación que no existen; historial, fotos, mensajes y avisos
sueltos; personas sin hotel o departamento; ubicaciones sin hotel; fotos
que faltan en el disco; espacio libre; la clave secreta; https; qué sirve
el servidor web, pidiéndolo; la última copia; la versión. Lo que falla o
avisa sale arriba, con lo que hay que hacer. `php tools/diagnostico.php`
usa la misma biblioteca. Al estrenarlo encontró:

- **El borrado definitivo de un parte, en MySQL, dejaba su historial y sus
  fotos huérfanos** (en SQLite no). La limpieza se fiaba de las claves
  foráneas del esquema, y el de MySQL no las declara para esas dos tablas:
  **18.947 líneas** sueltas en la base de pruebas de MySQL, que es el motor
  de producción. Ahora se nombran a mano, y `instalar.php --actualizar`
  quita las que ya hubiera (la papelera conserva su copia).
- **Dos pruebas probaban SQLite en la tanda de MySQL.** `formularios.py`
  llevaba la dirección del navegador escrita a pelo, y `sincertificado.py`
  leía una variable que `todas.py` no ponía. Los «31 de 31 en MySQL» de la
  ronda anterior incluían esas dos mirando la otra base. Arreglado, y
  `pruebas/clases.py` falla ahora si una prueba lleva una dirección fija.
  De paso dejaban en SQLite una persona de prueba sin departamento, que es
  como apareció.

Mirando la pantalla del panel corregí tres cosas mías: el icono de estado
no salía (los iconos sólo se dimensionan siendo hijos directos de la
fila), «1 avisos», y una línea con su grupo repetido delante por el índice
que `map` pasa como segundo argumento. Y una de diseño: con el servidor
integrado de PHP, que atiende una petición cada vez, el panel se
preguntaba a sí mismo por HTTP y esperaba ~15 s; ahí ya no lo hace y lo dice.

**Y al instalarlo desde el zip, dos fallos más de mis pruebas.** En una
instalación recién hecha cada tipo de avería lleva su icono como carácter
delante del nombre, y la jornada lo buscaba anclado al principio: recepción
no elegía tipo y no daba el parte. La aplicación sí lo explicaba («Indica qué
falla.»), pero la prueba lo leía cuatro segundos después, cuando el aviso ya
se había ido. Y la prueba del panel sembraba una foto suelta copiando otra,
y una instalación nueva no tiene ninguna: «no detecta», cuando no había
sembrado nada. Las dos instalaciones de pruebas no lo enseñaban porque
venían de versiones viejas. Corregido, y ahora la prueba avisa si una siembra
no ha hecho nada. Es la razón de instalar siempre desde el zip.

### Las puertas que ninguna prueba atacaba de frente

**Restablecer la contraseña con el código del administrador daba error 500,
siempre.** Es el camino de Administración → Contraseñas olvidadas: quien no
puede entrar no recuperaba nunca su cuenta. El código leía el campo en
`$correo` y buscaba a la persona con `$quien`, que no existía. Había pruebas
de que el código no se filtra y de que no se revela quién tiene cuenta, y
ninguna lo usaba. `pruebas/recuperar.py` lo hace de punta a punta: con
correo y con nombre de usuario, un solo uso, cinco intentos y fuera, las
sesiones viejas caen, cambiar la propia cierra las demás, salir invalida, y
la auto-entrada abre, con la llave cambiada no, y retirada deja de abrir.

**El registro de errores de Apache tenía más de esta clase.** Lo repasé
entero, con días de baterías dentro:

- **`$u` sin definir en los enlaces de auto-entrada** (216 avisos). La puerta
  —sólo un administrador reparte llaves— aguantaba de casualidad, pero el
  registro de seguridad apuntaba cada llave generada, encendida y retirada
  **a nombre de nadie**. Ahora dice quién; la prueba lo comprueba.
- **Con la base de datos caída, todas las respuestas salían como un 500
  vacío.** El manejador de errores intentaba deshacer una transacción en una
  conexión que no existía y reventaba. Ahora es un 503 con «La base de datos
  no responde ahora mismo… lo que tengas sin enviar se queda guardado en el
  móvil» (la cola del móvil no descarta nada cuando la petición falla
  entera). `pruebas/aguante.py` levanta un servidor apuntado a un MySQL que
  no hay y lo comprueba.

**Y la tabla de errores de la propia aplicación tenía uno que no había
cantado ninguna prueba: en MySQL, «Ver los partes» de un informe daba 500.**
`IN (SELECT … LIMIT 300)` lo admite SQLite, no MySQL ni MariaDB. Las pruebas
de informes no pedían el detalle en MySQL. Arreglado con una tabla derivada,
y `pruebas/listados.py` comprueba que el total del informe y sus partes
coinciden, en los dos motores.

**Generar un enlace de auto-entrada con la función apagada** daba una llave
que en la puerta sólo decía «desactivados en este hotel». Ahora no se genera
y se dice dónde se enciende.

**Las subidas, atacadas** (`pruebas/subidas.py`): un .php, un .php
disfrazado de .jpg, un SVG con código y una página HTML se rechazan por los
dos caminos (fichero y base64); una imagen con PHP dentro se guarda como
imagen y no se ejecuta; un nombre con «../» no sale de su carpeta; nadie
sube a un parte que no ve. Las fotos se sirven directamente a propósito —un
`<img>` no puede mandar la sesión— y lo que las protege es que su dirección
es una llave: 64 caracteres, carpeta sin listar, «nosniff» y «sandbox». No
se encontró nada, y la prueba caza el fallo si alguien admite SVG o HTML.

### Lo que sólo falla cuando dos cosas llegan a la vez

Las pruebas anteriores hacían las cosas de una en una. Haciéndolas a la vez
salieron cuatro fallos del mismo tipo —«leer, decidir y escribir» en tres
pasos, con un hueco en medio por el que se cuela otra petición—:

- **«Me lo quedo» pulsado por dos técnicos a la vez: el servidor decía que
  sí a los dos.** Los dos móviles mandan la versión del parte que conocían,
  y el servidor la comparaba antes de abrir la transacción: las dos
  peticiones leían la misma y pasaban las dos. Cada técnico creía que el
  parte era suyo y los dos iban a la habitación. Pasaba en la mitad de las
  rondas en SQLite y en todas en MySQL. Ahora la versión sólo avanza si
  sigue siendo la que se leyó; si otra petición ha escrito entre medias, se
  vuelve a mirar el parte tal como ha quedado y el segundo recibe el aviso
  de conflicto («este parte se asignó a…»), igual que si hubiera estado sin
  cobertura. `pruebas/a_la_vez.py`, ocho rondas por pasada.
- **El código de un solo uso para restablecer la contraseña valía varias
  veces** si se mandaba varias veces a la vez: veinte envíos simultáneos, 9
  aceptados. Ahora sólo sigue quien consigue marcarlo como usado.
- **El límite de cinco intentos de ese código no limitaba los intentos
  simultáneos.** Con un solo intento libre y treinta códigos lanzados a la
  vez, el bueno entró 3 veces de 13 rondas; si el límite funcionase, la
  probabilidad sería de 1 entre 30 cada vez. Ahora el intento se reserva
  antes de comparar, y el contador no pasa de 5.
- **Lo mismo con la contraseña y con el freno por equipo**: treinta
  contraseñas a la vez contra una cuenta comprobaban 12 con un tope de 8;
  ciento veinte intentos desde el mismo equipo pasaban 21 de un freno de 20
  (la ventana ahí es de milésimas: con cuarenta no salía). Ahora los dos se
  apuntan antes de mirar la contraseña.

Una nota honrada sobre la prueba del código: el contador viejo sumaba
también los intentos ya rechazados, así que «30 en el contador» no
demostraba por sí solo 30 comparaciones. Lo demostré aparte con el código
bueno escondido entre los malos (lo de las 13 rondas, arriba); la prueba
que se queda comprueba que el contador no pasa de 5, que con el arreglo
puesto sí equivale a «no se comparan más de cinco».

Lo que no se ha tocado: una entrada correcta limpia el freno de su equipo,
así que alguien con una cuenta válida del hotel puede ir soltándolo. El
límite por cuenta sigue en pie y es el que protege a cada persona.

**Y dos fallos en las propias pruebas**, encontrados al probar el paquete:

- `pruebas/todas.py` **no hacía caso de las variables que dicen contra qué
  instalación ir**, aunque `docs/PRUEBAS.md` lo prometía. Pisaba la
  dirección con la fija. Al pasar la tanda sobre el paquete recién
  instalado salieron once «pasa» en cero segundos, y el registro de Apache
  enseñó que habían ido contra la otra instalación. Ahora manda lo que se
  le diga, y la cabecera dice la dirección de verdad. Las pruebas de
  entrega se repitieron después, y el registro confirma que fueron al
  paquete.
- `clases` daba por malo el `config.local.php` de una instalación vieja
  porque su base se llamaba `sstt_app`. Ese fichero es la configuración de
  cada casa, no código, y no viene en el paquete. Ya no lo mira.

**Y un CSV exportado podía llevar una fórmula.** Si alguien titula un parte
`=HIPERVINCULO("http://…";"pulsa")`, al abrir el CSV en Excel se convertía
en un enlace activo. Ahora lo que empieza por `=`, `+`, `-`, `@` o un
tabulador se escribe con una comilla delante (los números negativos, no);
`pruebas/js/csv.mjs` lo comprueba sobre la función de verdad, y
`clases.py` avisa si alguien la quita.

## Escribir cómo se llama un departamento en este hotel lo borraba

La pantalla de departamentos guarda dos cosas de una pasada: el nombre
propio del departamento en este hotel (`PATCH`) y en qué hoteles se usa
(`PUT`). Y la segunda llamada borraba todas las filas de ese
departamento para volver a crearlas **sin el alias**.

O sea que escribir «cómo se llama en este hotel» y pulsar «Guardar» lo
escribía y lo borraba en la misma pulsación. Quien lo hiciera vería el
campo vacío otra vez y pensaría que ese campo no sirve para nada.

Y es peor que eso: como borra todas las filas, se lleva por delante el
nombre propio de **cada** hotel. En una cadena, cambiar dónde se usa
«Mantenimiento» lo renombraría en los cinco a la vez.

Arreglado conservando los alias al rehacer el reparto.

### La prueba que lo encontró no lo cazaba siempre

Lo cantó `pruebas/campos.py`, que escribe en cada campo por el navegador
y vuelve a leerlo. Pero al devolver el fallo a su sitio para comprobar
que lo cazaba, **no volvió a salir**: por el navegador sólo se nota
cuando la ventana llega a mandar el `PUT`, y eso depende de cómo esté el
reparto ese día.

Un fallo que se caza una vez de cada tantas no está cazado. Así que la
comprobación está ahora además en `pruebas/hoteles.py`, por API, donde
la secuencia es siempre la misma: se pone un alias, se reparte, y se
vuelve a leer. Devolviendo el fallo tres veces seguidas, lo cantó las
tres; con el arreglo puesto, cero.

## Toda la batería se había pasado siempre contra el motor equivocado

Hasta hoy, las veintinueve pruebas se ejecutaban **siempre contra
SQLite**, porque cada una llevaba escrita dentro la dirección
`http://127.0.0.1/sstt/...` y varias, además, la ruta del fichero
`/var/www/html/sstt/db/sstt.sqlite`.

Y el hotel va a funcionar con **MySQL**: con cincuenta personas es lo
normal, y es el motor que lleva montado la segunda instalación de
pruebas desde hace semanas. O sea que el motor de producción no lo había
probado nunca nadie de punta a punta.

Ahora la instalación bajo prueba se dice en un sitio y sólo uno
(`pruebas/entorno.py`), y la tanda entera se lanza contra cualquiera de
las dos:

```bash
python3 pruebas/todas.py            # SQLite
python3 pruebas/todas.py --mysql    # MySQL
```

**Las 29 pasan en los dos motores, y sin dejar restos.** Con MySQL,
cuarenta y dos minutos, incluidas las que abren navegador: trabajar sin
cobertura, el recorrido a ciegas, los formularios campo por campo y la
actualización desde la versión anterior.

### Lo que apareció a la primera

**Buscar una ubicación no funcionaba en MySQL.** `GET
/api/ubicaciones/buscar` devolvía «No se ha podido completar la
operación en la base de datos». Y buscar la ubicación es como se dice en
qué habitación es el parte: sin eso no se abre un parte con sitio.

La causa: el filtro de visibilidad entra **dos veces** en esa consulta
—una por cada subconsulta que cuenta partes—, con el mismo nombre de
parámetro. SQLite lo admite. MySQL con preparación nativa
(`ATTR_EMULATE_PREPARES => false`, que es como se conecta aquí) contesta
`SQLSTATE[HY093]: Invalid parameter number` y se cae la consulta entera.

Arreglado dando un sufijo propio a cada juego de parámetros
(`Permisos::filtroVisibilidad($u, 't', 'r', '_a')`), y comprobado contra
MySQL: la búsqueda responde. Repasado además todo el código en busca del
mismo patrón; no hay más. El panel ya lo tenía en cuenta, con un
comentario que lo explicaba —el autor se lo encontró antes en el
buscador— pero nadie lo había buscado en el resto.

### Y 281 restos que eran de otra base

El informe final de la tanda dijo «LA TANDA HA DEJADO 281 RESTOS». Esa
comprobación —la red de seguridad que mira si las pruebas se han dejado
algo puesto— **también llevaba la dirección escrita dentro**: con
`--mysql`, las pruebas corrían contra MySQL y el recuento se hacía sobre
SQLite. Un recuento de otra base no es un aviso, es ruido, y del que
hace que se dejen de leer los avisos. Ya mira la instalación que se ha
probado.

Pero los restos existían, sólo que en la otra base: 44 revisiones de
mentira, 186 partes de prueba y un departamento. La causa: el servidor
sólo borra una revisión si no tiene partes detrás —correcto para los
datos de verdad—, pero `hoteles` crea revisiones de mentira y, cuando no
encontraba el parte generado, la revisión se quedaba desactivada para
siempre. Ahora la prueba **comprueba que su limpieza ha funcionado** y,
si no, busca los partes por su vínculo y lo vuelve a intentar.
Verificado: una pasada más ya no deja ninguna.

Limpiado todo por el camino del programa, no a mano — borrar a pelo por
SQL lo rechazaron las claves foráneas en los dos motores, que es justo
lo que tiene que pasar. De paso, eso ejercitó el borrado definitivo 347
veces sin un fallo.

### Y cuatro alarmas que eran mías

Al apuntar las pruebas a MySQL sin arreglar antes las rutas de disco,
cuatro de ellas **mintieron**, y una lo hizo a lo grande: `hoteles`
creaba la revisión programada en MySQL, lanzaba el generador sobre la
carpeta de SQLite y luego buscaba el parte en MySQL. No estaba, claro, y
lo cantó como *«las revisiones de obligado cumplimiento nacen
invisibles»*. Media hora detrás de un fallo que no existía.

Las cuatro apuntan ya a la instalación que se esté probando, y pasan
igual en los dos motores: copias de seguridad, alta por ficheros,
aislamiento entre hoteles y la puerta de entrada.

### Un falso positivo de los que asustan

`permisos` decía que el técnico y el de recepción **pueden borrar partes
definitivamente**. No pueden. Lo que pasaba: la prueba copiaba los
campos del primer parte de la lista para crear uno desechable, el
primero de la lista resultó ser una revisión programada —que el
generador crea sin ubicación—, el alta murió con «Indica dónde es», el
parte desechable no llegó a existir, y a partir de ahí todos recibían un
404 que la prueba leyó como «puede».

Ahora la ubicación se **pide** al catálogo en vez de copiarla. Con eso,
cero permisos de más en los dos motores.

## Un OK que el diagnóstico no se había ganado

Al instalar desde el paquete terminado en una carpeta suelta, el
diagnóstico decía **«Nada de lo sensible se sirve por web»**. No podía
saberlo: esa carpeta no estaba bajo la raíz de ningún servidor. Lo que
hacía era preguntar a `http://127.0.0.1/` —otro sitio cualquiera de esa
máquina—, aquél contestaba bien, y de ahí salía el visto bueno.

Un OK que no es de esta instalación es peor que no decir nada: el
diagnóstico está para quitar dudas, no para inventarse tranquilidad. Y
encima en la comprobación más importante de las que hace.

Ahora, si no hay raíz de servidor a la que preguntar, lo dice y se
calla. Verificado en los dos sitios: en la carpeta suelta sale el aviso;
en la instalación de verdad sigue preguntando y respondiendo.

## Un arreglo mío que dejaba el sistema en 500 al actualizar

Al generar la clave propia de cada instalación le puse también un
`chmod 0640` al `api/config.local.php`, razonando que la contraseña de
la base no tenía por qué quedar a la vista de cualquier usuario de la
máquina.

El razonamiento era bueno y el efecto, desastroso: el instalador se
ejecuta normalmente como **root** y el servidor web corre como
**www-data**, que con modo 0640 y dueño root **no puede leer el
fichero**. Resultado: después de actualizar, SSTT entero devolvía 500 y
no se podía ni entrar.

Lo cazó `pruebas/actualizar.py`, que instala la versión anterior,
actualiza encima y después **intenta entrar**. Sin ese último paso
habría salido en el paquete y el hotel se habría quedado sin sistema al
actualizar. Comprobado al revés también: devolviendo el modo a 0644, la
API responde al instante.

Quitado, con un comentario en el instalador explicando por qué no se le
toca el modo a ese fichero, para que a nadie —yo incluido— se le ocurra
volver a ponerlo. Apretarlo bien exige saber con qué usuario corre el
servidor, y eso desde la consola no se sabe; quien quiera hacerlo, lo
hace a mano sabiendo su grupo.

## Una hora detrás de un fallo que no existía

La prueba de rendimiento empezó a decir que, «con veinte mil partes», la
página 10 del listado venía vacía. Fui a buscarlo al listado, al
buscador, a la visibilidad por hotel, a los cambios de autenticación de
hoy. Nada.

No venía vacía. **Tenía un servidor de pruebas mío, de otra cosa,
ocupando el puerto 8097** — el que usa esa prueba. `php -S` no arranca
si el puerto está cogido y no dice nada: la prueba siguió adelante y
estuvo midiendo contra mi otra instalación, que tenía cuatro partes.

Lo cuento porque el fallo era mío y porque el arreglo vale para
cualquiera: ahora la prueba **mira si el puerto está libre antes de
empezar** y, si no lo está, se planta y lo dice. Un puerto ocupado no es
un detalle del entorno; es la diferencia entre medir y hacer como que se
mide. Verificado ocupándolo a propósito.

De paso, esa prueba levantaba su servidor sobre la carpeta de la que
vive el hotel. Ahora trabaja sobre una copia, como ya hacía la vía de
MySQL. (Llegué a escribir que el `config.local.php` de la instalación
pisaba la variable de entorno y por eso medía mal; fui a comprobarlo
antes de dejarlo escrito y **era falso** —en `config.php` las variables
de entorno se aplican después—, así que la explicación está corregida en
el propio fichero. El cambio se queda por higiene, no por ese motivo.)

## Tres pantallas que no se guardaban para trabajar sin cobertura

La lista del trabajador de servicio —lo que se guarda en el móvil para
que la aplicación abra sin red— se mantiene a mano, y se había quedado
corta. Faltaban `views/mensajes.js` y `views/personal.js`, que `app.js`
importa siempre, y `editor-bloques.js`, que se pide al abrir el editor
de instrucciones.

En la práctica se notaba poco, porque el `fetch` del propio trabajador
va guardando lo que se pide: después de una visita con cobertura, eso
queda. Pero una pantalla en la que nunca se haya entrado con red no
estaba, y un móvil recién instalado que se quede sin cobertura antes de
abrir nada tampoco arrancaba.

Añadidas, junto con tres iconos de la aplicación que tampoco estaban.

Y la lista la vigila ya `pruebas/clases.py`, por los dos lados, porque
las dos equivocaciones duelen y de forma distinta: un fichero prometido
que no exista hace que `cache.addAll()` **falle entero** —rechaza si una
sola petición va mal— y el `catch` sólo escribe un aviso en la consola,
así que la aplicación se queda sin **nada** guardado y nadie se entera
hasta que alguien baja a un sótano. Verificadas las dos quitando un
módulo de la lista y metiendo uno inventado: las canta por separado.

## El asistente de instalación se abría sobre el hotel funcionando

Y ésta no necesita ni saber un secreto.

`tools/instalar_gui.php` pide `?clave=instalar`. Esa palabra está
escrita en el propio fichero, que va dentro del paquete: no es una
llave, es un cartel. Y `tools/.htaccess` permite ese fichero **a
propósito**, porque el asistente tiene que poder abrirse para instalar.
Así que el candado de la carpeta tampoco tapaba esto.

Comprobado en la instalación de pruebas, con sus 7 personas y sus partes
dentro: cualquiera de la red abría

```
http://servidor/sstt/tools/instalar_gui.php?clave=instalar
```

y tenía el asistente de instalación delante.

Sin el fichero `db/REINSTALAR` no podía vaciar la base —eso sí estaba
protegido—. Pero sí podía **reescribir `api/config.php` y apuntar el
hotel a otra base de datos**, la suya: el sistema pasa a autenticar
contra una base que controla él, entra como administrador, y el hotel se
queda sin nada. Y podía marcar «demo» y crear las cuentas de ejemplo,
cuya contraseña está escrita en el manual.

Lo que protegía los datos no protegía el acceso.

Ahora la regla es una sola: **si ya hay un sistema montado, el asistente
por web no hace nada sin que alguien ponga un fichero en el disco del
servidor**. Quien puede poner un fichero ahí es, por definición, quien
manda en esa máquina. Instalar de cero no cambia: si no hay base, no hay
nada que proteger.

Probados los tres casos: sin base, 200 y el asistente se abre; con el
hotel montado, 403 con una página que explica qué hacer; y poniendo
`db/REINSTALAR`, 200 otra vez. `pruebas/expuesto.py` lo vigila, y
verificado quitando el candado: lo canta.

## Había 134 sesiones de administrador escritas en el registro de Apache

Buscando otra cosa en los registros del servidor me encontré esto:

```
GET /sstt/api/index.php?_ruta=copias/14&token=c504d77f726e42... 200
```

El token de sesión de un administrador, completo y en claro, en el
registro de Apache. **134 líneas.** Un token de esos vale treinta días.
Quien pueda leer los registros —o una copia suya, o el servicio que los
recoge— entra como administrador. Y la misma dirección queda en el
historial del navegador y viaja en la cabecera `Referer` de lo
siguiente que se pida.

Y era justamente la ruta de **descargar una copia de seguridad**.

La causa tiene su lógica: una descarga de verdad es una navegación del
navegador, y una navegación no puede llevar la cabecera
`Authorization`. Un enlace manda lo que pone en la dirección y nada más.
Así que la pantalla de administración se construía la dirección con el
token dentro. Y el servidor lo aceptaba **en cualquier ruta**, no sólo
en esa, con un comentario que decía que era «para las imágenes desde
`<img src>`» — cosa que ya no era verdad: las fotos se piden por su
ruta de fichero, sin token.

Ahora va con un vale (`api/lib/Descargas.php`): 60 segundos, atado a esa
copia y a quien lo pidió, firmado con la clave de la casa —la que, como
se cuenta aquí abajo, hasta hoy era la misma en todas—. En el registro
queda igual, pero al minuto siguiente no sirve para nada. Y la puerta de
atrás del `?token=` se ha quitado entera: la sesión sólo viaja por
cabecera.

`pruebas/vales.py` lo vigila con siete comprobaciones, todas pidiéndolo
de verdad: que el token en la dirección ya no abra nada, que el vale
baje el fichero sin sesión, que un vale con la firma tocada no valga,
que el de una copia no sirva para otra, que uno caducado no valga, que
sin vale no se baje nada, y —la que evita que vuelva— que no quede
ningún `token=` construido a mano en el código del cliente.

Verificado devolviendo el fallo: con el `?token=` de antes en el
servidor y el enlace viejo en la pantalla, la prueba canta las dos cosas
y nombra el fichero y la línea exacta.

## La clave secreta de la casa era la misma en todas las casas

`app_key` venía en `api/config.php` con el valor literal
`CAMBIA-ESTA-CLAVE-POR-UNA-ALEATORIA-DE-64-CARACTERES`, y **nada la
cambiaba**: el instalador no la generaba y el diagnóstico no la miraba.
Así que todas las instalaciones compartían el mismo secreto, y ese
secreto va escrito en el código, dentro del paquete que se descarga.

Con ella autentican dos programas que se llaman desde fuera:
`tools/mantenimiento.php` y `tools/vencidos.php`, los dos con `?clave=`.
La carpeta `tools/` está cerrada por su `.htaccess`, así que hacían falta
dos fallos a la vez —y el otro, que el servidor no lea los `.htaccess`,
resulta que es el de fábrica en Debian y Ubuntu, como se acaba de ver—.

Ahora el instalador genera 64 caracteres al azar y los guarda en
`api/config.local.php`, que no se pisa al actualizar. Comprobado:
instalando dos veces salen dos claves distintas; y en una instalación
que ya existía sin clave propia, pasar el instalador se la añade. El
diagnóstico sale en rojo mientras siga la de fábrica —en la instalación
de pruebas salió en rojo, que es como lo encontré— y en verde cuando ya
es propia.

Las tareas programadas del manual se llaman por línea de órdenes y no
piden clave, así que esto no rompe nada de lo que ya esté montado.

## El diagnóstico reventaba justo cuando más falta hace

Con la base parada, `php tools/diagnostico.php` soltaba un
`PHP Fatal error: Uncaught PDOException` con su pila de llamadas y el
nombre de un fichero del programa. Lo encontré al pasarlo por la
instalación de MySQL con MariaDB caída.

Es exactamente al revés de lo que tiene que hacer: el diagnóstico se abre
cuando algo va mal, y «la base no responde» es lo primero que va mal —se
para MySQL, se cambia una contraseña, se reinicia el servidor y arranca
antes el web que la base—. Quien llegara ahí buscando ayuda se encontraba
una pila de llamadas y pensaría que lo roto es SSTT.

Ahora dice qué motor está configurado, en qué máquina, con qué base y
qué usuario, lo que contestó el servidor de base de datos y dónde mirar.
Comprobado con la contraseña cambiada a propósito.

## Y dos cosas más de la misma familia

Tirando del mismo hilo —*mirar lo que el servidor manda, no lo que el
proyecto escribe*— salieron dos más.

**Las cabeceras no salían.** Están escritas en `www/.htaccess`, pero
dentro de `<IfModule mod_headers.c>`, y en esta máquina **`mod_headers`
no estaba activado**. El `<IfModule>` hace que eso sea invisible: Apache
no se queja, la aplicación funciona, y las cabeceras sencillamente no
existen. Faltaban `X-Frame-Options` y `X-Content-Type-Options`, y no
había ninguna defensa contra que otra página metiera SSTT en un marco con
la sesión del técnico dentro: un clic en el sitio equivocado y se cierra
o se reasigna un parte sin que él vea nada. Añadidas esas dos más
`Content-Security-Policy: frame-ancestors 'self'` y `Referrer-Policy`.
`SAMEORIGIN` y no `DENY` a propósito, por si algún día va dentro de la
intranet del hotel.

**El trabajador de servicio se cacheaba una semana.** `www/.htaccess`
tenía el bloque de `sw.js` *antes* del bloque general de `.js`, y el
general también casa con `sw.js` porque termina en `.js`. Gana el último:
`sw.js` salía con `public, max-age=604800`. Y el trabajador de servicio
es justamente lo que trae las actualizaciones, así que subir una versión
nueva podía no llegar a los móviles. Reordenado, con un comentario en el
fichero explicando que el orden no es casualidad. Comprobado pidiendo la
cabecera: ahora `no-cache, must-revalidate`.

Las dos las vigila ya `pruebas/expuesto.py`, que **pide las cabeceras**
en vez de leer el `.htaccess`. Verificadas las dos en rojo antes de
arreglarlas: apagando `mod_headers` canta las tres, y devolviendo el
orden viejo canta el `max-age` del `sw.js` con su valor.

## Cuarenta rutas que existían y no estaban contadas

`docs/API.md` es un contrato: alguien lo lee para conectar otra cosa —el
PMS, un cuadro de mandos, un script de recepción— y confía en lo que
pone. `pruebas/apidoc.py` lo comprueba por los dos lados: llama de verdad
a cada ruta de lectura que el documento promete, y exige que cada ruta
del código aparezca en el documento.

El primer lado salió limpio: no hay ninguna ruta fantasma. El segundo
encontró **cuarenta rutas enteras** que funcionaban desde hacía meses y
no estaban en ninguna parte: los departamentos completos, los errores que
manda el móvil, los mensajes, el puente de personal con SAP, los avisos
al móvil, la papelera que se restaura, las altas por lote, las
ubicaciones de administración. Quien leyera el documento creería que eso
no se puede hacer, y lo escribiría otra vez o entraría por donde no debe.

Están documentadas. Verifiqué que la prueba salta de verdad por los dos
lados: quitando una ruta del documento, y metiendo en él una ruta
inventada —la cantó nombrándola—.

## La guía contaba algo que ya no era verdad

La guía decía: «A los ocho intentos fallidos la cuenta se bloquea. Si te
pasa, Daniel la desbloquea desde Equipo en un momento.» Era verdad hasta
que el bloqueo pasó a soltarse solo a los quince minutos. Quien se quede
fuera leería que hay que ir a buscar al administrador, cuando basta con
esperar un rato.

Y falta la mitad que más confunde: mientras está bloqueada, la
aplicación le sigue diciendo **«usuario o contraseña incorrectos»**, no
«bloqueada» —a propósito, para que nadie pueda averiguar quién tiene
cuenta probando nombres—. Así que esa persona llama convencida de que su
contraseña ha dejado de valer. Ahora lo dicen los dos sitios donde
aparece: el apartado de entrar y el de administración.

Una guía que cuenta algo distinto de lo que hace el programa es peor que
no tenerla: se sigue, no funciona, y se deja de creer el resto.

### La prueba de la guía sólo miraba la forma

Comprobaba el índice, la numeración, las capturas y los enlaces —todo
cuadraba— y no podía ver que el texto hubiera envejecido. Ahora
`pruebas/guia.py` ata **los números que la guía promete a los que tiene
puestos el programa**: la sesión de 30 días, los ocho intentos, los
quince minutos del bloqueo y los 90 días que se baja el móvil. Cambiar
la configuración sin tocar la guía salta. Lo comprobé por los dos lados,
cambiando un valor de `config.php` y un ajuste de la base.

Y comprueba que cada captura **exista**: antes contaba las 75
referencias y nunca miraba si el fichero estaba, así que renombrar una
dejaba un hueco en silencio.

### Dos sustos que no lo eran

Mi primera comprobación avisó de que la guía nombraba dos estados
—«Cancelada» y «Reabierta»— que no aparecían en el código. Existen: están
en la base de datos, no escritos en el programa. Miraba donde no era.

Y abriendo la guía en el navegador salieron cinco capturas «que no
cargan». Cargan todas: son de carga diferida y las que están fuera de
pantalla figuran como no cargadas hasta que se llega a ellas.
Recorriéndola entera, las 76 cargan y no hay ni una petición con error.

## Y cincuenta móviles volviendo de la zona sin cobertura

El sótano, la cocina y la azotea no tienen cobertura, así que la gente
trabaja con la cola llena y, al volver al pasillo, cincuenta móviles la
vacían a la vez. Es el momento en que más se escribe de golpe —y donde
vivía el fallo del cerrojo—. `pruebas/sincronizacion.py` prueba UN móvil
que se queda sin red; `pruebas/tormenta.py` es la otra mitad.

Cincuenta móviles con seis cosas cada uno, todos al mismo tiempo, y
además **la misma cola mandada dos veces** —que es lo que hace un móvil
cuando la respuesta se pierde por el camino—: llegan los 300, ninguno
duplicado, ninguna tanda caída, todo en menos de un segundo. En SQLite y
en MySQL. La protección contra duplicados es el uuid que genera el móvil,
y se comprobó que sirve de verdad estropeándola a propósito: entonces
llegan todos dos veces y la prueba lo canta.

### Dos acusaciones en falso de esa misma prueba

La primera dijo que **se perdían 100 partes de 300**. No se perdía
ninguno: yo pedía la lista con «limite=200» y la API no deja más de 200
de golpe. El tope era mío.

La segunda, ya arreglada la anterior, dijo que **100 partes llegaban dos
veces**. Tampoco: eran los 100 que la pasada anterior no había podido
barrer —por el mismo tope— y que salían en la búsqueda de la siguiente.
Cada pasada usa ahora una marca propia y sólo mira lo suyo.

Las dos tardaron lo mismo en descartarse que en comprobarse: mirar la
base de datos. Pero una prueba que acusa de perder partes es exactamente
la que hace saltar a cualquiera, y por eso conviene que no mienta.

## Con cincuenta personas se perdía una nota de cada cuarenta

Era la pregunta que decide cómo se instala esto y no estaba contestada:
**¿aguanta SQLite a cincuenta personas?** SQLite es un fichero —mientras
uno escribe, los demás esperan— y el fallo clásico es «database is
locked»: la nota se da por escrita y no está.

Simulando el turno de mañana —cincuenta sesiones a la vez durante
cuarenta segundos, mirando listas, abriendo partes, escribiendo notas y
dando partes nuevos— pasaba justo eso: **269 peticiones de 10.191 se
caían**. Una de cada cuarenta, y cada una es una nota o un parte que
alguien da por dicho.

La causa no era SQLite, era cómo se le pedían las transacciones. La
conexión ya tenía lo que hay que tener —modo WAL y cinco segundos de
espera— pero las transacciones se abrían **diferidas**: la primera
consulta coge un cerrojo de lectura y, cuando toca escribir, hay que
subirlo a cerrojo de escritura. Si en ese momento otro está escribiendo,
SQLite **no espera**: falla al instante. Y no espera a propósito, porque
aguardar con un cerrojo de lectura en la mano puede dejar a dos
bloqueados para siempre. Los cinco segundos configurados no se aplican a
ese caso.

Pidiendo el cerrojo de escritura por delante —`BEGIN IMMEDIATE`— la
espera sí cuenta y el que llega segundo aguarda su turno:

| 50 sesiones, 40 segundos | Antes | Ahora |
|---|---|---|
| Peticiones caídas | **269** de 10.191 | **0** de 9.517 |
| Peticiones por segundo | 253 | 236 |
| Tiempo normal | 0,02 s | 0,02 s |
| El 95% por debajo de | 0,06 s | 0,10 s |

En MySQL también cero, con 175 peticiones por segundo. Así que **SQLite
vale para los cincuenta**, que es una respuesta que cambia la
instalación: no hace falta montar un servidor de base de datos.

`pruebas/cincuenta.py` lo deja guardado, y comprobé que sabe cazarlo
volviendo a poner la transacción diferida: 261 caídas, con el motivo.

### Y la prueba no sabía decir por qué se caían

Buscaba la palabra «locked» en la respuesta del servidor, y ahí no está
—a un técnico en un pasillo no se le enseña un volcado de SQL, se le dice
«no se ha podido completar la operación»—. Así que contaba 269 caídas
«sin motivo conocido» teniéndolo apuntado al lado, en el registro de
errores del propio programa. Ahora lo lee de ahí, que es donde está.

Dos recuentos más de la prueba que engañaban, y los apunto porque el
patrón se repite: daba «quitados 1563 de 2988 partes» habiéndolos quitado
todos —sacaba el número de parte de cualquier respuesta, y la de una nota
también lo trae, así que contaba el mismo veinte veces—; y decía «sólo
han entrado 43 de 50» por un usuario de prueba que otra prueba se había
dejado puesto con otra contraseña. Un recuento que asusta sin motivo se
acaba ignorando igual que uno que calla.

## Buscar costaba el doble de lo necesario

La búsqueda de texto libre era el último techo medido: 2,3 s con cien mil
partes. Antes de tocar nada, medí de dónde salía el tiempo, y resultó no
ser lo que parecía:

| Con 100.000 partes | |
|---|---|
| Barrer la tabla entera, pelado | 39 ms |
| Un `LIKE` normal en el título | 25 ms |
| El mismo `LIKE` **quitando acentos** | 178 ms |
| Quitando acentos en título + detalle + ubicación | 637 ms |

El coste no es buscar: es **quitar los acentos fila por fila** —trece
`REPLACE` encadenados por columna, en tres columnas—. Y eso está ahí por
una buena razón: nadie escribe acentos en el buscador de un móvil con una
mano, así que «averia» tiene que encontrar «avería».

Lo que sí sobraba: la lista hacía **dos** consultas con esa misma
condición, una para contar y otra para traer la página. Dos veces 637 ms.
Ahora, cuando se pide la primera página y vienen menos partes que el
tope, el total ya se sabe —son los que han venido— y no hay que contar
nada. Es exacto, no aproximado, y justo esa es la búsqueda que iba lenta:
la que encuentra pocas cosas.

| Con 100.000 partes | Antes | Ahora |
|---|---|---|
| Buscar «agua» (3 resultados) | 1,30 s | **0,67 s** |
| Buscar «enfria» (1 resultado) | 1,34 s | **0,65 s** |
| Buscar algo que no existe | 1,24 s | **0,65 s** |

Las búsquedas que llenan la página siguen contando, porque no hay forma
de adivinar cuántas hay detrás. Eso está bien así.

**Lo que había que comprobar no era el reloj, sino el número.** La
cabecera de la lista pone «20 PARTES» y el responsable se lo cree. Comparé
los doce casos antes y después, con cien mil partes: ni un total distinto,
ni una fila distinta. Y queda `pruebas/listados.py` para que no se pueda
volver aproximado sin que salte: comprueba que el total coincide con las
filas cuando caben todas, que pasando página se llega justo al total sin
repetidos, y que un filtro nunca encuentra más que la lista sin filtrar.
Probado en las tres instalaciones, incluida la de cien mil partes.
Falseando el total a propósito, lo canta.

## El panel hacía 35 barridos de la tabla cada vez que se abría

Es la primera pantalla que abren cincuenta personas cada mañana, y con
cien mil partes tardaba 1,3 segundos. El motivo, al mirarlo: la gráfica
de los catorce últimos días lanzaba **un COUNT(\*) por día y por
sentido** —veintiocho consultas, cada una barriendo la tabla entera— más
siete del resumen de arriba. Treinta y cinco barridos por visita.

Ahora la gráfica son **dos** consultas agrupando por día, y el resumen
**una** contando las siete cosas de pasada. Se lee la tabla una vez en
vez de treinta y cinco:

| Con 100.000 partes | Antes | Ahora |
|---|---|---|
| Panel en MySQL | 1,29 s | **0,74 s** |
| Panel en SQLite | 1,13 s | **0,44 s** |

Y con 20.000 partes —dos años de hotel— el panel en MySQL pasa de 0,30 a
**0,13 s**.

La comprobación que importa aquí no es el reloj: es que la respuesta sea
**idéntica**. Se guardó la respuesta completa del panel antes de tocar
nada y se comparó clave por clave después de cada cambio. Ninguna
diferencia, en los dos motores.

## Y el rendimiento en MySQL, que no se había medido nunca

La lista de pendientes decía, con razón, que todas las medidas eran de
SQLite. Con cincuenta personas lo normal es MySQL, y cambian las dos
cosas que más pesan: el planificador de consultas y que cada una sale por
la red en vez de leer un fichero.

Medido: con **20.000 partes** nada pasa de medio segundo. Con **100.000**
—diez años— todo sigue bien menos la búsqueda de texto libre, que sube a
2,3 s. Buscar por código es 0,01 s, gracias al atajo de la versión
anterior.

### Y esa prueba apuntó la instalación de verdad a la base de pruebas

El instalador deja un `api/config.local.php` con los datos de la base que
se le diga, y ese fichero manda sobre todo lo demás. Al lanzarlo dentro
de la instalación real para montar la base de carga, la instalación se
quedó apuntando a la base de pruebas: el panel pasó a enseñar sesenta y
tres mil partes de mentira y la base de verdad dejó de usarse.

No se perdió ni un dato, pero en un servidor del hotel eso es una tarde
perdida buscando por qué los partes no son los suyos. La prueba trabaja
ahora sobre una **copia** de la instalación. Es la cuarta vez que una
prueba muerde lo que no debía, y van tres sitios distintos: los datos de
demostración, las revisiones, y ahora la configuración.

## Lo que sólo funciona con certificado, probado por fin

Media aplicación cuelga del certificado —los avisos al móvil y poder
trabajar sin cobertura— y nunca se había podido comprobar, porque sin
https el navegador ni siquiera entrega el service worker. Era el mismo
FALLA de siempre en el diagnóstico, y nadie sabía si, puesto el
certificado, las dos cosas iban a funcionar.

Montando uno hecho a mano se ha probado entero, y **funciona**:

- el service worker se registra, se activa y **manda en la página**
  (registrado no es lo mismo que al mando: la primera carga viene de la
  red y hasta la siguiente no gobierna nada);
- guarda **152 ficheros** en el móvil;
- y **sin red la aplicación se abre igual**, con su base de datos
  dentro. Es la promesa principal del programa y es la primera vez que
  se comprueba de verdad.

`pruebas/sincertificado.py` lo deja guardado, y sabe fallar: estropeando
la precarga del service worker dijo «sólo se han guardado 1 ficheros: con
eso la aplicación no arranca sin red».

Lo que **no** se puede probar aquí es la suscripción a los avisos: un
navegador de pruebas sin escritorio detrás no puede darse de alta en el
servicio de Google y contesta «permission denied» aunque el permiso esté
concedido. Eso es del navegador, no del programa, así que la prueba lo
dice como «no se ha podido probar» en vez de darlo por bueno o por malo.
Hay que probarlo desde un móvil de verdad, y es lo único que queda.

## El diagnóstico miraba una casilla, no el certificado

Decía si estaba marcado el ajuste «forzar https», que es una intención,
no un hecho. Con un certificado puesto y funcionando seguía diciendo
FALLA —y siendo el único FALLA, el «NO arranques» se convierte en ruido
y se ignora—; y al revés, con la casilla marcada en un servidor sin
certificado habría dicho que todo bien. Las dos equivocaciones en la
misma línea.

Ahora **intenta una conexión https de verdad** contra el propio servidor.
Y de paso mira el certificado: si caducó, lo dice; si caduca en menos de
quince días, avisa; y si está hecho a mano, avisa de que en los móviles
del personal saldrá «sitio no seguro». Además, tener certificado y seguir
aceptando http pasa a ser un FALLA aparte: quien escriba la dirección sin
la «s» manda su contraseña en claro.

## Cuando los avisos no lleguen, ahora se podrá saber por qué

El botón «mandarme un aviso de prueba» contestaba, cuando no salía nada,
«No hay ningún aparato registrado, **o** el envío ha fallado». Son los
dos únicos problemas posibles y los metía en la misma frase, siendo
completamente distintos: en el primero hay que activar los avisos en el
móvil; en el segundo el móvil está bien y lo que falla es el servidor.
El día que los avisos no lleguen, ésa era justo la frase que había que
leer, y no decía nada.

Ahora dice cuál de los dos es, y cuando es el servidor apunta **el
motivo** en el registro de errores: «el servicio de avisos contestó 403»,
«el servidor no ha podido conectar (sin salida a internet, o
cortafuegos)», «ese móvil ya no tiene la aplicación instalada». Antes el
fallo sólo subía un contador y ahí moría: desde fuera, mandar un aviso y
que no llegara era idéntico a mandarlo y que llegara.

Un detalle de método, porque estuve a punto de dejarlo pasar: el mensaje
nuevo prometía «queda anotado en el registro de errores» y eso **era
mentira** —`Push.php` no apuntaba nada—. Prometer un registro que no
existe le hace perder una tarde a quien lo busque. O se quita la frase, o
se hace verdad. Se ha hecho verdad.

## Cualquiera podía dejar al hotel entero sin poder entrar

La cuenta se bloquea tras ocho contraseñas mal. Está bien. Lo que no
estaba bien es que ese bloqueo **era para siempre**: sólo lo quitaba un
administrador, uno por uno.

En un hotel los nombres de usuario son adivinables —ana, luis, el nombre
de pila, el departamento—. Así que cualquiera, desde la wifi de clientes
y sin tener cuenta, dejaba al turno entero sin poder entrar: ocho
intentos por persona, cincuenta personas, diez minutos. **Incluido el
administrador**, que es justo quien tendría que desbloquear a los demás.
Una defensa convertida en avería.

Ahora el bloqueo automático **caduca solo** —quince minutos por defecto,
`bloqueo_minutos` en `api/config.php`, y con 0 vuelve a ser para
siempre—. Quien insista consigue molestar un rato; no consigue parar el
hotel. El bloqueo que pone un administrador a mano es otra cosa y sigue
sin caducar.

### Y de paso se delataba quién tiene cuenta

A una cuenta bloqueada le contestaba `403 · Esta cuenta está bloqueada`,
y a un nombre inventado `401 · Usuario o contraseña incorrectos`. Con eso
se averigua quién tiene cuenta en el hotel: ocho intentos contra un
nombre y la respuesta lo canta. Ahora las dos respuestas son
**exactamente la misma**, letra por letra.

Eso dejaba un problema de trato: quien se equivoca de verdad ocho veces
ve «contraseña incorrecta» aunque la esté escribiendo bien. Se arregla
sin delatar a nadie, con una frase que va **siempre**, exista la cuenta o
no: «Si estás seguro de la contraseña, espera unos minutos: tras varios
fallos la cuenta se bloquea un rato y luego se suelta sola.»

`pruebas/acceso.py` lo guarda todo: que frene, que bloquee, que el
bloqueo tenga fecha y caduque, que un administrador pueda desbloquear
—si no pudiera, el bloqueo sería una avería—, que no se pueda averiguar
quién existe, que una sesión caducada deje de valer y que ninguna ruta
conteste sin ficha. Los tres guardias nuevos los comprobé volviendo a
meter cada fallo.

Una trampa de la propia prueba, por si sirve de aviso: buscaba un código
429 para dar por bueno el freno, y como la respuesta es 401 **a
propósito**, dijo «doce contraseñas mal y sigue aceptando intentos»
cuando bloqueaba limpiamente a la octava. Ahora mira la base, que es
donde está la verdad.

## Actualizar sobre una versión anterior

Es lo que pasa el día que se actualiza, y no se había probado nunca. Dos
cosas pueden salir mal, y las dos de golpe en cincuenta móviles: que la
base se quede a medias, o que los teléfonos sigan usando la aplicación
vieja. Una PWA se guarda entera en el móvil, así que lo segundo no es
hipotético: si la versión del `sw.js` no sube, el teléfono no se enterará
nunca de que hay una nueva.

`pruebas/actualizar.py` lo monta de verdad: instala el **paquete
anterior** —el que haya en `paquetes/`, sin que haya que decírselo—, lo
usa con un navegador hasta que el móvil se guarda la aplicación entera,
le pone la versión nueva encima, pasa `tools/instalar.php --actualizar`
y comprueba cuatro cosas: que no se pierde ningún dato, que el móvil que
ya tenía la vieja **se queda con la nueva** sin borrar nada a mano, que
después se puede entrar y la lista trae partes, y que la actualización no
suelta ni un error por el camino.

Probado de 7.59 a 7.78 y de 7.76 a 7.78, en SQLite y en MySQL: los datos
quedan idénticos, dato por dato, y el móvil se pasa solo a la nueva.

Y sabe fallar. Bajando a mano la versión del `sw.js` dijo: «El móvil se ha
quedado con la aplicación vieja guardada. Quien la tenga instalada
seguirá usando la de antes sin enterarse.» Y metiendo un borrado en el
instalador, cazó la actualización rota.

Una cosa que aprendí escribiéndola: comparaba «el parte más nuevo» antes
y después, y eso cambia **por sí solo** —al actualizar, las revisiones
que tocaban generan sus partes—. La prueba lo cantaba como datos tocados
cuando la aplicación estaba haciendo su trabajo. Ahora compara el más
antiguo, que no se mueve, y en los recuentos sólo se queja si BAJAN:
crecer es lo normal.

## El diagnóstico daba luz verde al fallo de instalación más corriente

Decía «OK: se puede escribir en db» mientras la aplicación contestaba «No
se ha podido completar la operación en la base de datos» al primer
intento de entrar. Por dos motivos tontos:

1. miraba la **carpeta** `db` y no el **fichero** de la base. SQLite
   necesita escribir en los dos;
2. y lo miraba **como quien lanza el diagnóstico**. Si se instala con
   sudo —que es lo que hace todo el mundo— los ficheros quedan de root, el
   servidor web no puede escribirlos, y el diagnóstico, ejecutado también
   con sudo, daba luz verde.

Esta comprobación existe justamente para cazar eso antes de abrir. Ahora,
cuando se ejecuta como root, mira si podría escribir **el usuario del
servidor web**, y si no, lo dice con la orden para arreglarlo:
`sudo chown -R www-data:www-data <carpeta>`. Me pasó a mí instalando la
versión antigua para la prueba de actualización, y por eso salió.

## Partes que no veía nadie

Es el fallo más serio que ha salido en toda la revisión, y llevaba ahí
desde que existen los hoteles.

Un parte sin hotel **no sale en la lista de nadie**: el filtro por hotel
lo deja fuera siempre, para todas las personas. Se crea bien —el móvil
dice «parte T-00484 creado»—, nadie recibe el aviso, y no se encuentra
tampoco buscando su código. Y no se puede ver desde la aplicación para
darse cuenta: por definición, no aparece.

En esta base había **338**. Venían de dos sitios:

**Las revisiones programadas, todas.** El servidor las genera solo cada
mes, y al crearlas no ponía el hotel —ni una—. Entre ellas van las de
obligado cumplimiento: extintores, bocas de incendio, legionela. Las que
hay que poder demostrar que se hicieron. Se generaban puntualmente, no
las veía nadie, nadie las hacía, y el historial —que es la prueba—
quedaba vacío. Una de las que estaban invisibles en esta base era
literalmente «Revisión de extintores y BIE».

**Y los partes de quien no tiene hotel asignado.** Una cuenta recién
creada, o traída de una instalación de un solo hotel, abría partes que
nacían huérfanos.

Qué se ha hecho:

- **Al crear un parte** se busca hotel por donde se pueda: el de la
  persona, el de la ubicación elegida, o el único que haya. Si no hay
  forma de saberlo, el alta se rechaza diciendo por qué, en vez de
  guardar algo que nadie volverá a ver.
- **Al generar una revisión** igual: el de la revisión, el de su
  ubicación, o el único hotel.
- **`php tools/diagnostico.php`** los cuenta y los saca como FALLA, con
  la orden para arreglarlos: `--arreglar-huerfanos` les pone el hotel de
  su ubicación, o el único si no hay más que uno. Era el único sitio
  posible para esto: desde la aplicación no se pueden ver.
- **`pruebas/hoteles.py`** lo guarda por los dos lados: crea un parte
  desde una cuenta sin hotel y hace que el servidor genere una revisión
  vencida, y exige que los dos se encuentren después buscando su código.
  Comprobé que los dos saltan volviendo a meter el fallo.

Lo encontró el rendimiento, de rebote: al medir «buscar por código» se
vio que un parte visible en la base no aparecía al buscarlo.

### Y cómo llevaba tanto tiempo escondido

Porque las pruebas tampoco los veían. `aguante.py` creaba revisiones de
mentira llamadas «x» y su barrido no las borraba —la lista de nombres a
limpiar no incluía «x»—. Llegó a haber **369**, y el servidor, cada mes,
fabricaba un parte por cada una. Trescientos treinta y siete partes
basura que ninguna prueba podía ver para limpiar, porque eran invisibles
por el mismo fallo que se estaba buscando.

## Buscar un código era igual de lento que buscar texto

Con cien mil partes, buscar «T-00012» tardaba **1,9 segundos**. No
debería tardar nada: es una clave.

Lo que lo frenaba es que la condición mezclaba el número con tres `LIKE`
sobre el título, el detalle y la ubicación. Unidos por `OR`, hay que
mirar las cien mil filas de todas formas, aunque el número aparezca en
la primera.

Ahora, cuando lo buscado lleva la «T» delante y ya no puede ser otra
cosa, se busca sólo por el número. Buscar «99» a secas sigue mirando
también el texto: puede ser la habitación 99.

## Cuánto aguanta, medido

`pruebas/rendimiento.py` monta una base **aparte** con veinte mil partes
—la de verdad no se toca—, levanta la aplicación apuntando a ella y
cronometra lo que la gente hace todo el día. Los topes son los de la
paciencia de una persona: medio segundo no se nota, un segundo se nota,
tres segundos parecen un cuelgue.

Con **20 000 partes** (unos dos años de hotel), nada pasa de medio
segundo: la lista 0,08 s, el panel 0,22 s, buscar 0,38 s. Con **100 000**
—diez años— todo sigue bien menos la búsqueda de texto libre, que sube a
1,9 s y se nota. Y todo eso medido con el servidor de pruebas de PHP,
que es de un solo hilo: con Apache es mejor.

Para que esto se pudiera medir sin tocar la base de verdad hacía falta
una cosa que faltaba: `SSTT_DB_SQLITE`. Se podía cambiar por el entorno
el motor, el servidor, la base, el usuario y la contraseña —todo lo de
MySQL— pero no la única cosa que hace falta en una instalación SQLite:
dónde está el fichero.

## La copia de seguridad, probada restaurándola

Una copia que se crea sin quejarse no es una copia: es un fichero. Lo
único que importa es si el día malo se puede devolver.

`pruebas/copias.py` lo hace de verdad: apunta cómo está todo, crea la
copia por la aplicación, **destruye** —borra un parte, borra a una
persona, cambia un ajuste y desactiva un departamento—, restaura con
`tools/restaurar.php`, que es lo que se ejecutaría, y comprueba que ha
vuelto todo: los números y el contenido. Comprueba además que la copia
no lleve dentro contraseñas en claro ni las sesiones abiertas, que no se
haya dejado ninguna tabla, y que un fichero que no es una copia se
rechace sin tocar nada.

Funciona. Y la comprobación sabe fallar: haciendo que la restauración se
saltara los ajustes, lo cantó.

Una trampa que tenía esta prueba y vale la pena contar: miraba si se
restauraba el ajuste «dias_vencimiento_alta», que en esta instalación
está **vacío**. Comparaba nada con nada y daba por buena la restauración
de los ajustes sin haber mirado. Ahora elige un ajuste que tenga valor, y
si no encuentra ninguno lo dice en vez de callarse.

## Una prueba colgada colgaba la tanda entera

`pruebas/todas.py` le daba a cada prueba su plazo, y el plazo no servía:
las que abren navegador dejan procesos del navegador agarrados a la misma
salida, así que matar a Python no cierra la tubería y la espera no termina
nunca. `formularios` se quedó **más de una hora** con un plazo de cuarenta
minutos, y al final el reloj de fuera se llevó la tanda sin dejar informe
de nada.

Ahora cada prueba va en su propio grupo de procesos y al vencer el plazo
se mata el grupo. Lo comprobé con una prueba de pega que se cuelga a
propósito dejando un hijo agarrado: antes esperaba para siempre, ahora
sale como ROTA al segundo exacto del plazo.

(El cuelgue de `formularios` fue un tropiezo del navegador, no un fallo
del programa: cronometrada aparte tarda 1 668 s y pasa. Pero el plazo
tiene que cumplirse para poder distinguir una cosa de la otra.)

## Una revisión borrada se quedaba en la lista para siempre

Borrar una revisión la desactivaba **siempre**, aunque no hubiera
generado ni un parte. Una creada por error y borrada acto seguido se
quedaba ahí, tachada, sin nada que conservar. Ahora, como con los
departamentos: con partes detrás se desactiva —el historial es la
prueba de que se hizo— y sin ellos se borra de verdad.

Lo notaron las pruebas, que se dejaban sus revisiones de mentira puestas
creyendo haberlas borrado.

## Dos tandas de pruebas a la vez se pisan

Me pasó: dejé una tanda corriendo y lancé otra. La segunda dio «FALLA» en
permisos y tiempos de un segundo en pruebas que tardan medio minuto.
Estuve media hora buscando un fallo que no existía. Las pruebas no
simulan —crean partes, personas y hoteles de verdad en la misma base—, así
que dos a la vez se borran los datos la una a la otra.

`pruebas/todas.py` tiene ahora un cerrojo: si hay otra tanda en marcha, lo
dice y no arranca. Y comprueba si el proceso de la otra sigue vivo, para
no bloquearse por un cerrojo que quedó de una tanda muerta.

## Las pruebas se comían los datos de demostración

Tres veces, y la tercera dolió: la pasada a ciegas pulsó «Desactivar» en
la **«Revisión de extintores y BIE»** de los datos de demostración. La
obligatoria. Una revisión desactivada deja de generar partes y no avisa
de nada. La lista de botones que no se pulsan tenía «salir», «vaciar» y
«restablecer de fábrica», pero no «desactivar».

Antes habían caído un hotel fantasma —que hizo que la plantilla de alta
marcara el hotel equivocado— y los siete departamentos del hotel, que
dejaron la ficha de una persona sin ninguno para elegir.

Ahora: la lista va por lo que **hace** el botón («desactivar», «retirar»,
«dar de baja», «archivar», «bloquear»), `formularios.py` barre lo que
crea, `aguante.py` barre también sus frases de cuarenta letras «A» —se
acumulaban dieciocho por tanda— y, al final de la tanda,
`pruebas/todas.py` **cuenta** lo que ha quedado raro y lo dice. No lo
borra: borrar a ciegas al final sería otra prueba destructiva sobre los
datos de verdad, que es justo el problema.

## Y las pruebas tampoco se creían solas

Tres cosas más que salieron, todas del mismo tipo: una prueba que mide
algo que no es lo que dice medir.

**El rastreo decía «no se llega tocando» por suerte.** Entra donde le
llevan los botones que encuentra, y eso cambia con los datos que haya:
dos pasadas seguidas dieron 23 y 21 rutas alcanzadas, y las que faltaban
salieron como fallo estando perfectamente enlazadas. Ahora, lo que no se
pisa se busca **en el código**: si algo navega a esa ruta, se deja dicho
que esta pasada no pasó por ahí; si no navega nadie, eso sí es una
pantalla huérfana y falla. Comprobé que distingue las dos cosas.

**El molde de los partes de prueba se clonaba del primero de la lista.**
En la instalación MySQL ni uno de los cuarenta partes visibles tenía tipo
de avería, así que el alta se rechazaba y dos pruebas acusaban al
servidor de no dejar crear partes. Ahora el molde sale del catálogo.

**Y una prueba se tragaba su propio error.** Pedía el catálogo con un
límite de 200 000 caracteres; el catálogo pesa casi 300 KB, llegaba
cortado, no era JSON válido, y el `except` se lo comía en silencio. Ahora
lo pide entero y, si algo falla, lo dice en voz alta.

## La prueba de rastreo llevaba tiempo sin decir nada

Se caía en su **última línea** —una variable con un nombre que ya no
existía— después de hacer los veinte minutos de trabajo. Hacía el rastreo
completo y moría justo al imprimir el resultado, así que nunca llegué a
leerlo. Vale la pena desconfiar también de las pruebas: una que no dice
nada se parece mucho a una que dice que todo está bien.

## Probado también en MySQL, no sólo en SQLite

Hasta aquí todo se había probado sobre SQLite, que es lo que trae la
instalación de pruebas. En el servidor del hotel va a haber MySQL, y las
dos bases no se comportan igual: **SQLite se traga un texto de cualquier
longitud y MySQL no**. Eso significa que un fallo puede no aparecer nunca
en pruebas y salir el primer día en producción.

Montada la misma aplicación sobre MariaDB 10.11 y pasadas las mismas
pruebas, apareció justo eso: **crear una persona con un nombre de usuario
largo devolvía un error 500**. La columna admite 60 caracteres; había un
mínimo de 3 pero no había máximo. En SQLite se guardaba tan tranquilo. Lo
que veía la persona era «no se ha podido completar la operación en la base
de datos», que no dice qué arreglar.

El arreglo no es recortar en silencio —un nombre de usuario es una
identidad, y recortarlo podría dejar dos personas con el mismo— sino
decirlo: ahora se rechaza con «no puede pasar de 60 caracteres». Igual con
el correo, que en MySQL tiene su índice limitado a 190.

De paso salieron **seis sitios que convertían una lista a texto sin
querer**: cuando un campo llegaba con otra forma —un móvil con la
aplicación a medio actualizar— PHP guardaba la palabra «Array» y dejaba un
aviso en el registro del servidor por cada intento. Ahora `Http::requerido`
lo rechaza con un mensaje claro y `Http::texto` lo deja en vacío donde da
igual.

## Lo que se hace sin cobertura

`pruebas/sincronizacion.py` corta la red de verdad —no la simula—, da tres
partes desde el «sótano», escribe una nota, devuelve la cobertura y
comprueba las dos cosas que pueden salir mal: que **no se pierda** nada y
que **no llegue dos veces**. Lo segundo pasa cuando la cola reintenta algo
que de verdad llegó, y ensucia la lista y los informes.

Para saber que la prueba sirve, se estropeó el envío a propósito de dos
maneras. La primera —tirar la mitad de las respuestas— no la detectó, y
con razón: eso no pierde nada, sólo retrasa, porque lo que no se confirma
se queda en la cola y sale en la vuelta siguiente. La segunda —vaciar la
cola sin enviar— sí la detecta y la nombra. Equivocarse al elegir la
avería enseña más que acertar: la primera no era una pérdida.

## Dos personas tocando lo mismo

`pruebas/a_la_vez.py` lanza a la vez dos notas, dos cambios de estado y
dos «me lo quedo» sobre el mismo parte. Dos notas distintas caben juntas y
tienen que estar las dos; un estado es un único valor y uno gana, pero el
historial tiene que recordar los dos intentos. Comprobado también
estropeando el servidor para que descarte una nota de cada dos: la prueba
lo canta.

## Quién puede qué, y qué pasa con la basura

Tres pruebas más, que miran lo que no se ve por pantalla.

**`pruebas/permisos.py`** llama a cada punto de la API con los cuatro
papeles —administrador, responsable, técnico y sólo avisar— y compara lo
que contesta con lo que debería. Separa dos cosas que se arreglan
distinto: que alguien PUEDA lo que no le toca (un agujero) y que NO PUEDA
lo que sí (una pantalla rota para media plantilla). Las operaciones que
hacen daño —cambiarle los datos a otro, borrar un parte para siempre,
restablecer un hotel— van contra cosas desechables que se crean al
empezar y se tiran al acabar.

Encontró **una credencial que se escapaba**. La lista de ajustes que no
podían salir del servidor estaba escrita a mano con tres claves, y el
planteamiento era el equivocado: lo que no estuviera en la lista salía con
su valor. Se añadió después la conexión con recepción, nadie se acordó de
la lista, y **`sap_clave` —la credencial del sistema de recepción— se la
llevaba entera cualquiera**: un técnico, o quien sólo da avisos. Con
cincuenta personas dentro, eso es la credencial en cincuenta móviles.

Ahora la regla está al revés: se tapa todo lo que parezca una credencial
—por su tipo en el esquema, por su nombre, y por la lista de siempre— y
lo que no lo parezca se enseña. Un ajuste nuevo nace tapado y hay que
decidir enseñarlo, en vez de nacer destapado y tener que acordarse.
Comprobado desactivando el arreglo: la prueba lo caza.

**`pruebas/aguante.py`** manda basura a propósito: un número donde iba un
texto, una lista donde iba un nombre, cien mil letras, cuerpos que no son
JSON, identificadores imposibles. Son 417 peticiones, y lo que busca es un
**500** —el servidor caído por dentro, con el trabajo de alguien
perdido— o un error que se vaya de la lengua enseñando la consulta SQL o
la ruta del servidor. No encontró ninguno de los dos.

Sí encontró que **una instrucción no tenía límite de tamaño**. El manual
por bloques sí lo tenía; el texto a secas, no. Y las instrucciones se
precargan para leerlas sin cobertura, así que una de cien mil letras se
baja entera a los cincuenta móviles del hotel. Ahora el texto sencillo
tiene tope, con un mensaje que explica que para eso está el editor de
manuales.

**`pruebas/hoteles.py`** monta un segundo hotel de verdad —con su gente y
su parte dentro— e intenta llegar a él desde el primero por nueve
caminos: la lista, la búsqueda, la ficha directa, el historial, la
conversación, las fotos, el cambio de hotel por la dirección, el panel y
la lista de personas. Y además intenta tocarlo: cambiarle el estado,
escribirle y borrarlo. Treinta y siete intentos, ninguno pasa.

Para saber que la prueba sirve de algo, se desactivó el filtro de hotel a
propósito: entonces señala nueve fugas por su nombre. Una prueba que no
sabe fallar no demuestra nada.

**Las pruebas no pueden comerse los datos que miden.** Dos veces perdí el
Hotel Benalma de la base de pruebas y estuve un rato buscando un fallo que
no existía. La primera, porque las comprobaciones destructivas apuntaban a
datos de verdad. La segunda, más fina: entre los identificadores
imposibles de `aguante.py` estaba `1 OR 1=1`, y el lector de
identificadores es permisivo —lo lee como el número 1—, así que
`DELETE /hoteles/1 OR 1=1` borraba el hotel uno. Ahora lo que borra usa
una lista aparte que no puede señalar a nada real, cada prueba barre lo
que ha creado, y la suite se ejecuta dos veces seguidas para comprobar
que la segunda encuentra la base como la dejó la primera.

## Un formulario nunca puede quedarse mudo

Una ventana se construye en dos tiempos: primero el HTML —campos y
botones— y después `alAbrir`, que es donde se enganchan los manejadores
del pie: «Crear», «Guardar», «Borrar».

Si algo revienta a mitad de `alAbrir`, lo de arriba ya quedó enganchado y
lo de abajo no. Queda un formulario de pinta impecable **cuyo botón no
hace nada**: no manda, no avisa, no se queja. La persona rellena, pulsa y
se queda mirando la pantalla.

Pasó de verdad en el alta de personas: una variable que no existía en esa
función. Durante días el alta estuvo muerta, y el recorrido de pantallas
decía «37 de 37 limpias» porque las pantallas se pintaban bien. Lo que
estaba roto era lo que pasa **después de pulsar**, y eso no lo miraba
nadie.

Tres cosas para que no se repita:

**`hoja()` no deja fallar en silencio.** Si `alAbrir` lanza un error, la
ventana lo dice en su propio cuerpo, **apaga los botones de guardar** en
vez de dejarlos aparentando que funcionan, y vuelve a lanzar el error
fuera para que el vigilante de siempre lo deje en el registro de errores.
Más vale una ventana que admite estar rota que una que finge.

**`pruebas/formularios.py`** hace **224 comprobaciones** y toca **65 de
los 66 sitios del código donde se abre una ventana** —medido
instrumentando `hoja()` y contando los que se ejecutan de verdad, no a
ojo—. En cada una comprueba tres cosas: que abrirlo no lanza nada, que **todos
los botones del pie tienen manejador** —la comprobación que caza este
fallo sin rellenar nada— y que la ventana tiene campos si dice que va a
guardar. Además envía cinco de verdad, vacíos y rellenos: con el
formulario vacío la regla es *o se queja, o manda*; callarse no vale.
Cuando un botón no aparece lo dice en voz alta, porque una prueba que se
salta cosas en silencio da «todo bien» sin haber mirado nada.

**Y además pulsa a ciegas.** Una lista de formularios sólo cubre los que
a uno se le ocurre poner en ella, y el alta de personas llevaba semanas
rota en una pantalla que yo daba por revisada. Por eso hay una segunda
pasada que no sabe nada: entra en cada pantalla, pulsa **cada botón y
cada fila**, y revisa lo que se abra. Esquiva sólo lo que destruye o
cierra la sesión —abrir su confirmación es seguro, se cierra sin
confirmar—. Es lenta (unos veinte minutos) y encuentra lo que una lista
no encuentra.

**Y hay caminos escritos a mano**, porque a algunas ventanas no se llega
pulsando a lo bruto: hay que buscar un número de serie para que aparezca
el aparato, entrar en «Contraseñas olvidadas» para pedir un código, o
abrir el selector de sitio y bajar hasta «Dar de alta una ubicación
nueva». Van escritos uno a uno a propósito.

Para que existan esos caminos, la base de demostración trae ahora lo que
hace falta: partes en la papelera, aparatos con número de serie, tipos
con campos propios y una petición de contraseña pendiente. Sin esos
datos, media docena de ventanas no se pueden abrir ni a mano.

**Lo que todavía NO cubre**: «Qué avisos quiero» y «Envíos con
problemas» de Mi cuenta, la ventana de un módulo instalado —no hay
ninguno—, y tres de la ficha del parte que dependen del estado concreto
de ese parte. Decirlo es parte de la prueba: una cobertura que se da por
completa sin serlo es exactamente cómo se coló el fallo del alta.

**Una ruta que no existe no da error**: el router manda lo desconocido al
inicio sin decir nada, así que durante un tiempo la prueba creía estar
revisando `/admin/partes` y `/admin/catalogos` —que no existen— y en
realidad miraba siete veces la pantalla de inicio. Las secciones buenas
salen del menú: `/admin/ajustes`, `/admin/elementos`, `/admin/familias`,
`/admin/zonas`, `/admin/campos`, `/admin/atajos`, `/admin/claves`,
`/admin/vista`, `/admin/seguridad` y `/admin/copias`.

**`eslint.config.mjs`.** El fallo era una variable no declarada, y eso lo
caza un analizador estático en un segundo. Comprobado: con el error
puesto, `no-undef` lo señala en la línea exacta. Hace falta Node:

```bash
npx eslint --config eslint.config.mjs 'www/assets/js/**/*.js' --quiet
```

## La guía de uso

**Ajustes → Guía de uso**, o `www/guia.html` directamente. Explica cada cosa con
capturas de las pantallas de verdad, no con dibujos: si la aplicación cambia, la
guía se nota vieja enseguida y se sabe que hay que rehacerla.

Lleva **buscador** —que tampoco distingue tildes, como el de la aplicación— y se
guarda entera para poder leerla **sin cobertura**: se consulta justo cuando algo
no va, y a veces lo que no va es la cobertura del sótano.

Arriba se elige **qué haces en el hotel**, y la guía se queda con lo que te toca:
quien sólo da avisos ve trece apartados de treinta y ninguno de administración.
Son 30 apartados y a nadie le tocan todos; enseñárselos enteros hace que no lea
ninguno. La elección se recuerda, porque quien es de recepción lo es todos los
días.

Los apartados y el índice se numeran solos en orden de documento, y hay una
comprobación de que el índice y las secciones van en el mismo orden: añadir un
apartado en medio no deja los números cruzados. Los apéndices —`14b`, `16b`,
`24b`— cuelgan del número de su apartado, así que mover uno los arrastra
consigo.

## Quién lo hizo, aparte de a quién

El registro de seguridad guarda las dos cosas por separado: **a quién** le
cambiaron el rol o la contraseña, y **quién** se lo hizo.

Parece un detalle y no lo es. Antes quien lo hacía iba escrito dentro del texto,
así que la pregunta más útil de un registro —«¿qué hizo esta persona?»— no se
podía contestar: sus cambios sobre los demás aparecían bajo el nombre del otro.
Ahora es una consulta.

Las entradas y los intentos fallidos no llevan autor, porque quien lo intenta
todavía no es nadie.

## Aparatos con la sesión abierta

**Ajustes → Aparatos con la sesión abierta.** Cada móvil donde alguien entra
abre una sesión, y ahí se ven todas con su plataforma y cuándo se usó por última
vez.

Se puede **cerrar una sola**, que es lo que hace falta cuando se pierde un
teléfono: ese aparato deja de tener acceso y los demás siguen trabajando. Antes
sólo existía «cerrar todas las demás», que obliga a volver a entrar en todos los
sitios por haber perdido uno.

Cada quien ve y cierra las suyas: el número de sesión se comprueba contra quien
pregunta, así que no se puede echar a nadie de su aparato.

## Nada falla en silencio

Un fallo que no se ve es peor que uno que rompe: el segundo se arregla el mismo
día, el primero se descubre meses después y con daño hecho.

Por eso el candado de la carpeta de fotos —lo que impide que un fichero subido
con nombre `.php` se ejecute— **cancela la subida si no se puede escribir**, en
vez de dejar la carpeta abierta y seguir como si nada. Y las entradas de la
papelera que no se pueden tirar quedan anotadas en el registro, para que alguien
pueda mirar por qué la papelera no baja.

Las capturas de error que no hacen nada están contadas y razonadas: un aviso que
no sale no puede tumbar el parte que lo provocó, y un fallo al registrar no puede
impedir la operación. Son excepciones a propósito, no descuidos.

## Las fotos, bien guardadas

Cada parte tiene su carpeta, y **cada carpeta lleva su propio candado**. El de
la raíz hereda en Apache, pero eso depende de cómo esté montado el servidor: con
`AllowOverride` cerrado, o sirviendo por nginx, no hereda nada — y un fichero
subido con nombre `.php` se ejecutaría. Por ahí se entra al servidor entero.

Las fotos grandes se reducen si el servidor tiene GD. Si no lo tiene, se guardan
tal cual en vez de fallar: es mejor una foto pesada que ninguna.

## Fechas que no cuadran

Una base antigua de MySQL guarda `0000-00-00`, un móvil con el reloj mal puesto
manda una fecha futura, y una migración puede traer un 31 de febrero. Los tres
casos llegan antes o después.

Las fechas imposibles se muestran como **—**, no como un día inventado:
`Date.UTC` no valida nada, y con mes 0 y día 0 se iba a noviembre del año
anterior. Salía «30 nov» sin que nadie entendiera de dónde.

Las fechas futuras se muestran con su fecha, no como «ahora». Antes un parte
fechado en 2099 aparecía como recién creado en lo alto del listado.

## Qué pasa cuando algo va mal

**La sesión caduca mientras trabajas.** Se vuelve al acceso con un aviso. Antes
sólo se borraba el testigo y la aplicación seguía en pie enseñando lo guardado
del móvil: parecía que todo iba bien —los partes estaban ahí— hasta que algo no
se guardaba y nadie entendía por qué.

**Una foto pesa más de lo que admite el servidor.** Se dice el tope y qué hacer.
Antes salía «Falta el campo», porque cuando el envío supera `post_max_size` PHP
vacía los campos sin avisar y la petición llega como si viniera vacía.

**Un archivo que no es lo que dice ser.** Un PHP con nombre `.jpg` se rechaza
por su contenido, no por su extensión.

## Cómo se comporta con un hotel de verdad

Probado con **20.000 partes**, que son unos dos años de un hotel con movimiento.

| | |
|---|---|
| Entrar y ver el inicio | 2,3 s |
| Listado | 3,0 s |
| Buscar entre 20.000 | 2,6 s |
| Primera sincronización | 14 vueltas · 5,3 MB |

Tres cosas hubo que acotar para llegar ahí, y las tres eran problemas reales
que sólo se ven con volumen:

**El histórico que baja al móvil son 90 días, no un año.** Con un año, un hotel
con movimiento arrastra 16.000 partes: 55 vueltas de sincronización y minutos de
espera. Con 90 días son 14 vueltas. Lo viejo sigue en el servidor y se consulta
en línea.

**Los partes abiertos también se acotan.** Antes bajaban todos sin límite de
fecha, incluidos los que nadie cerró hace tres años. Ahora se les da el doble de
margen que al histórico: uno viejo pero vivo sigue a mano, y el resto se queda
donde estaba.

**El listado pinta 60 tarjetas y ofrece «ver más».** Antes las pintaba todas —
3.221 en la prueba—: la pantalla tardaba segundos en aparecer y se gastaba
memoria en algo que nadie iba a bajar.

## Avisos con la aplicación cerrada

Sin esto, un aviso sólo llegaba cuando la aplicación preguntaba al servidor — y
una aplicación cerrada no pregunta. De madrugada, con el móvil guardado, es
cuando peor funcionaba.

**Navegador** — Web Push (RFC 8291), cifrado de extremo a extremo con claves
VAPID que el servidor genera solo la primera vez. Ni Google ni Mozilla pueden
leer el contenido. Funciona en cuanto haya `https://`.

**APK** — Firebase, que es lo único que Android admite dentro de una aplicación
empaquetada. Necesita configurarse una vez: ver `docs/APK.md`.

Por el push viaja sólo el código del parte y su título. Hay un ajuste para
**empujar sólo lo urgente**, para que el móvil no suene todo el día pero sí de
madrugada cuando hace falta.

Un destino que falla cinco veces seguidas se apaga solo: los navegadores
desinstalados dejan direcciones muertas que si no alargarían cada envío.

## La papelera: nada se pierde del todo

**Se puede devolver lo borrado.** Una papelera de la que no se saca nada es un
cajón de cosas perdidas: se borra el parte que no era —que pasa más de lo que
parece— y antes sólo quedaba mirarlo.

Vuelve con **su mismo número, su historial, sus fotos y sus campos propios**, así que quien lo tenía apuntado
en un papel sigue encontrándolo. Si alguien ha ocupado ese hueco desde
entonces, se avisa en vez de pisarlo, y no se puede devolver dos veces.

Las fotos importan aquí más de lo que parece: los ficheros nunca se borran del
disco, así que lo que se perdía era la fila que dice a qué parte pertenecen. El
parte volvía sin fotos, con las imágenes ocupando sitio sin que nadie pudiera
verlas. Y la foto de la avería es la mitad del parte.

Una persona vuelve **sin acceso**: las contraseñas no se guardan ni en la
papelera, así que hay que darle una nueva.

**Y se puede tirar de verdad.** Hay borrados que tienen que ser definitivos:
alguien pide que se quiten sus datos, o se metió por error algo que no debía
estar ahí. Tirar una entrada se lleva la fila, lo que colgaba de ella y las
fotos del disco, sin vuelta atrás.

Lo que lleva más de **180 días** ahí se tira solo con el mantenimiento diario
—el plazo se cambia en Configuración—. La papelera existe para arreglar un
borrado por error, y esos se descubren en días, no en meses; pero si alguien
vuelve de vacaciones y pregunta, todavía está.

Todo lo que se borra de verdad —partes, personas, lo que se lleve un
restablecimiento— pasa antes a **otra base de datos con la misma estructura**.
Después se borra de la viva. En ese orden y dentro de una transacción: si la
copia falla, el borrado no llega a ocurrir.

`db/sstt-papelera.sqlite`, junto a la base normal. Sobrevive a un
`instalar.php --forzar`, que borra la base viva entera.

**Por qué una base aparte y no una marca de «borrado»:**

- La base viva se queda pequeña de verdad. Diez años de partes no se arrastran
  en cada consulta.
- Un borrado es un borrado. Con marcas, tarde o temprano una consulta se olvida
  de filtrarlas y lo borrado reaparece donde no debe.
- Se puede mirar sin miedo: la papelera es de sólo lectura.

De cada fila se guarda **todo tal cual estaba**, más quién la borró, cuándo y
por qué. Las contraseñas no: ni siquiera se clona su columna. Una contraseña
archivada sigue siendo una contraseña.

### Modo papelera

**Ajustes → Papelera de reciclaje**, sólo para superadministradores: cruza
hoteles y contiene datos de todos.

La aplicación **cambia de color** mientras se está dentro —violeta apagado, con
una franja permanente arriba—. No es adorno: es una pantalla que enseña cosas
que ya no existen, y confundirla con el listado normal llevaría a dar por vivo
un parte que se borró hace un mes.

### Restablecer de fábrica

**Ajustes → Hoteles → Restablecer**, en cada hotel. Dos casillas separadas:
todos los partes, todas las personas, o ambas.

Se pone difícil a propósito: hay que marcar qué se borra, ver cuánto es, y
**escribir el nombre del hotel**. El botón está apagado hasta entonces.

- **Los administradores nunca se borran**, ni quien lanza la operación. Sin
  ellos nadie podría volver a entrar.
- **No se tocan** ubicaciones, tipos de avería, instrucciones ni aparatos con
  número de serie: eso es la estructura del hotel, y montarla otra vez costaría
  días.
- Todo va a la papelera, y queda anotado en el registro de seguridad.

## Buscar como se escribe de verdad

Nadie pone acentos en el buscador de un móvil, y menos con una mano y andando
por un pasillo. Buscar **«averia» encuentra «avería»**, y al revés.

Y el **código del parte se puede buscar**: «T-00012» es lo que se ve en
pantalla, así que es lo que la gente teclea. Antes no encontraba nada, porque el
código no está en la base —se compone al leer— y la búsqueda miraba el número
suelto.

Vale para **las seis búsquedas del sistema**: partes, ubicaciones, tipos de
avería, instrucciones, números de serie y papelera. Estaban todas escritas
igual, así que fallaban todas igual.

Y vale **también sin cobertura**, donde la búsqueda corre en el propio móvil.
Si no, la misma búsqueda daría resultados distintos según haya red o no, y eso
hace dudar de si el parte existe.

## Qué es común y qué es de cada hotel

La diferencia importa más de lo que parece:

**Común a la cadena** — departamentos, tipos de avería, familias, estados y
prioridades. «Servicios Técnicos» significa lo mismo en todos los hoteles, y un
informe conjunto sólo cuadra si es así.

**De cada hotel** — plantas, zonas, lugares, números de habitación, gente y
partes. Aquí no hay nada que compartir: el 305 de un hotel no tiene que ver con
el 305 de otro.

### Departamentos

**Administración → Departamentos.** Cada hotel elige del catálogo común los que
de verdad usa: uno de treinta habitaciones no tiene jardinería ni informática, y
le sobra verlas en cada desplegable.

Un hotel puede ponerle **el nombre que se use en la casa** —«Mantenimiento» en
vez de «Servicios Técnicos»—. Por dentro sigue siendo el mismo, así que los
informes de la cadena siguen cuadrando.

Sólo un superadministrador crea, renombra o reparte departamentos entre hoteles.
Si cada hotel se inventara los suyos, en un mes habría cuatro maneras de llamar
a lo mismo. Quitar uno de un hotel que lo está usando avisa antes de hacerlo.

Borrar un departamento con gente o partes detrás lo **desactiva** en vez de
borrarlo: un parte de hace dos años tiene que poder seguir diciendo de quién era.

### Los avisos no cruzan de hotel

Los departamentos son comunes a la cadena, así que «Servicios Técnicos» tiene
gente distinta en cada hotel. Los avisos se filtran por el hotel del parte: al
asignar a un grupo, al entrar un urgente, al pasarse un plazo y al tocar un
mantenimiento programado.

Sin ese filtro, un urgente del hotel de Málaga despertaba a los técnicos de
Benalmádena a las tres de la mañana por una avería que no pueden ni ver. Y a la
tercera vez se dejan de mirar los avisos, que es lo peor que puede pasar.

### Qué ajustes toca cada quien

Con **varios hoteles**, los ajustes que valen para toda la cadena sólo los
cambia un superadministrador: las funciones activas, la seguridad, el push, los
días de histórico que se bajan al móvil y quién puede crear ubicaciones.

Antes el administrador de un hotel podía apagar las fotos en todas las casas.
Con un solo hotel daba igual quién lo hiciera; con varios, estaba apagando
funciones en hoteles que ni sabía que existían.

Lo que sí ajusta cada hotel es **cómo trabaja su gente**: los avisos, el
formulario de alta y quién ve qué. Eso no sale de su casa.

Con un solo hotel no cambia nada: la restricción sólo se aplica cuando hay más
de uno.

### Borrar un hotel

Un hotel **con partes o personas no se borra: se desactiva**. Borrarlo se
llevaría por delante el histórico sin vuelta atrás.

Uno vacío sí desaparece, y con él sus plantas, zonas, lugares, sublugares,
instrucciones, mantenimientos y los departamentos que tenía asignados. Todo en
una transacción: si algo falla, la base se queda como estaba.

### El servidor comprueba a qué hotel pertenece cada cosa

Un parte no puede apuntar a la habitación de otro hotel ni a un departamento
que en esta casa no se usa. El formulario ya sólo ofrece los propios, pero la
petición se puede escribir a mano, y la cola de un móvil que cambió de hotel
manda lo que tenía guardado.

Sin esa comprobación el parte se quedaba muerto: nadie lo ve en su listado
porque apunta a un sitio que en su hotel no existe. Vale igual al crear y al
editar.

### Habitaciones por rango

**Administración → Ubicaciones → Crear habitaciones por rango.**

De la 101 a la 130 de una vez, con sus baños y terrazas. Dar de alta una planta
a mano son treinta habitaciones por cuatro partes: ciento veinte toques para
algo que se sabe de antemano.

Admite prefijos («Bungalow 001»), sufijos, saltar números —en muchos hoteles no
hay habitación 13— y elegir qué partes tiene cada una. Dice cuántas va a crear
antes de hacerlo, y las que ya existían se reutilizan en vez de duplicarse.

## Varios hoteles

Cada hotel es una instalación distinta que comparte servidor. **Un parte de uno
no se ve desde otro bajo ningún concepto**: ni listándolo, ni buscándolo, ni
escribiendo su número en la dirección.

| Propio de cada hotel | Compartido |
|---|---|
| Ubicaciones enteras (plantas, zonas, lugares, sublugares) | Los 313 tipos de avería y sus 16 familias |
| Partes y todo lo que cuelga de ellos | Estados, prioridades y departamentos |
| Personas | |
| Aparatos con número de serie | |
| Mantenimiento programado e instrucciones | |
| Informes guardados y atajos | |

### Los tres niveles

- **Superadministrador** — crea hoteles, entra en cualquiera y ve a todo el
  mundo. Mira un hotel a la vez, no todos mezclados: se elige con `?hotel=N`.
- **Administrador de hotel** — manda en el suyo y no existe fuera de él.
- **Todos los demás** — su hotel, con el rol que tengan dentro.

### Montar un hotel nuevo

Al crearlo se puede **copiar la estructura de otro**: los cuatro niveles de
ubicación y sus combinaciones, más las instrucciones si se quiere. Es lo que
cuesta montar a mano, y así se edita desde algo en vez de desde cero.

**Los números de serie no se copian nunca.** Un aparato está donde está;
duplicarlo sería inventarse inventario.

### En la aplicación

Un superadministrador ve una franja permanente con el hotel en el que está
trabajando; tocarla lleva a **Ajustes → Hoteles**. Para los demás no aparece:
su hotel es el único que existe y no hay decisión que tomar.

Al cambiar de hotel **se borra lo descargado en el aparato** antes de cambiar.
Si no, quedarían mezclados los partes de los dos en el mismo móvil y sin
cobertura se verían los del anterior. Si hay algo en la cola sin enviar, no
deja cambiar hasta sincronizar.

Al dar de alta a alguien, el superadministrador elige su hotel. El resto sólo
puede crear gente en el suyo, y el servidor lo comprueba además de la
interfaz.

### Cómo está hecho el aislamiento

El filtro vive en un solo sitio —`Hoteles::filtro()`— y entra en
`filtroVisibilidad()`, por donde pasan todas las consultas de partes.
Repartirlo por cada ruta sería garantizar que tarde o temprano se olvida en
alguna, y una fuga entre hoteles no es un fallo cosmético.

Al abrir un parte por su número no se pasa por el listado, así que
`puedeVer()` comprueba el hotel antes que cualquier otro permiso. Y cuando se
deniega, se responde «no se ha encontrado» y no «no puedes»: decir que existe
ya sería contar algo del otro hotel.

## Apariencia: cada uno la suya

Un pasillo a media luz, una cocina con vapor, un despacho. Y vista distinta en
cada persona. Por eso la apariencia **no la fija el administrador**: la ajusta
cada uno en su móvil, en **Ajustes → Apariencia**, y se queda ahí.

- **Tamaño de la letra** — cuatro pasos. Crece todo el conjunto, no sólo los
  párrafos, porque el resto de medidas van en relativo.
- **Espacio entre las cosas** — compacto, normal o amplio. En compacto cabe casi
  una tarjeta más por pantalla; en amplio se acierta mejor con el pulgar.
- **Detalle de cada parte** — mínima oculta ubicación y pie, completa deja el
  título a tres líneas.
- **Cifras por fila** — una, dos o tres.
- **Bloques del inicio** — cuáles se ven, en qué orden, y si cada uno ocupa
  media pantalla o entera. Dos contiguos a media pantalla se emparejan.
- **Bloques de la ficha de un parte** — cómo se ve al abrirlo: instrucciones,
  datos, campos propios, fotos y seguimiento, en el orden que quieras.

  Dos van marcados como fijos y no se pueden quitar: la **cabecera**, porque sin
  ella no se sabe qué parte se está mirando, y los **botones**, porque sin ellos
  no se puede hacer nada con él. El resto es libre.

  Un técnico que sólo cierra partes puede dejar arriba los botones y esconder el
  seguimiento; quien audita puede subir el seguimiento y bajar todo lo demás.

Se resuelve con atributos en `<html>` y variables CSS, así que vale para toda
la aplicación de una vez, incluidas las pantallas que se escriban después.

Un bloque que no toca por el puesto sigue sin salir aunque esté encendido: la
apariencia manda sobre el gusto, no sobre los permisos.

## La barra de abajo se elige

Cinco botones: cuatro a elección y el de nuevo parte, que va siempre en el
centro y no se puede quitar — es la acción que justifica la aplicación.

Cada persona elige sus cuatro entre Inicio, Partes, Míos, Avisos, Cómo (las
instrucciones) y Ajustes, con una vista previa de cómo va a quedar. De partida
lleva las que corresponden a su puesto: recepción no tiene partes asignados, así
que en vez de «Míos» ve «Avisos».

## La pantalla de inicio cambia según el puesto

Los tres grupos abren la aplicación por motivos distintos, así que enseñarles el
mismo panel obliga a los tres a filtrar mentalmente lo que no les toca.

| | Ve |
|---|---|
| **Recepción** | Sus avisos abiertos, los resueltos esta semana y un botón grande de avisar. Nada más. |
| **Housekeeping** | Lo suyo, la cola de su grupo, sus avisos, y **qué habitaciones tienen algo pendiente** antes de entregarlas. |
| **Servicios Técnicos** | Lo asignado, la cola del grupo, lo fuera de plazo y lo urgente. |
| **Responsable** | Todo lo anterior más el reparto por estado, la actividad de dos semanas y la carga del equipo. |

El perfil se deduce del rol y del grupo. En **Administración → Comportamiento**
se puede forzar a completo o sencillo para todos.

La lista de habitaciones pendientes es la consulta que housekeeping necesita de
verdad: si queda una avería viva en la habitación, no se entrega. Un toque abre
la ficha del sitio.

## Trabajar con varios partes a la vez

El botón del visto en la cabecera del listado entra en modo selección. Tocar
una tarjeta la marca en vez de abrirla, y aparece una barra con lo que se puede
hacer: **cambiar el estado**, **asignar**, **mandar a la papelera** y —sólo para
administradores— **eliminar definitivamente**.

Hay «Todos» para marcar la lista entera de una vez, respetando los filtros que
tengas puestos.

El servidor comprueba los permisos parte por parte y salta en silencio los que
no te tocan; el mensaje final dice cuántos quedaron sin tocar, sin contar que
existían.

## Quien manda puede corregirlo todo

Un administrador, un superadministrador o un responsable pueden **editar
cualquier parte en cualquier estado**, incluidos los cerrados: un dato mal
puesto sigue estándolo después de cerrar el parte, y sale torcido en los
informes.

El editor lleva diez campos: resumen, detalle, tipo de avería, ubicación,
prioridad, estado, departamento, persona, fecha límite y tiempo dedicado (más
el coste si el módulo está activo).

Un técnico sigue viendo sólo los cuatro primeros: corregir el texto de su parte
es una cosa, y mover prioridades, plazos o dueños es otra.

## Dos asignaciones, no una

Un parte lleva **departamento** y **persona**, y son cosas distintas:

- **El departamento es obligatorio.** Dice de quién es el trabajo. Sin él, el
  parte no aparece en la cola de nadie y se queda muerto. Viene puesto
  Servicios Técnicos, que es donde va la mayoría.
- **La persona es opcional** y puede venir después. La pone el responsable del
  departamento, o se la queda alguien del propio departamento.

Al elegir persona **sólo salen las de ese departamento**. Y si se cambia el
departamento de un parte que ya tenía a alguien, esa persona se suelta: dejarla
sería asignar trabajo a quien ya no le toca.

Quien sólo avisa elige el departamento y ya está: poner nombres sin saber quién
está libre no ayuda a nadie.

## Quién puede quedarse un parte

**El departamento es obligatorio al dar de alta**: un parte sin grupo no
aparece en la cola de nadie y se queda muerto. La persona concreta puede venir
después, o cogérselo alguien.

Y «Me lo quedo» **sólo aparece si el parte va a tu departamento**. Si no,
cualquiera podría llevarse trabajo de otro oficio: un camarero cogiendo una
avería eléctrica no la va a resolver, y mientras tanto los electricistas dejan
de verla sin asignar. Los responsables sí pueden, porque repartir es su trabajo.

Se comprueba en el servidor además de en la pantalla.

## Acciones rápidas

Un técnico con ocho partes asignados no debería abrir ocho fichas para
marcarlos. Cada tarjeta del listado lleva un botón que abre una hoja con lo
que se hace el 90 % de las veces: **empezar**, **resolver**, **quedárselo** o
dejar una nota. Dos toques y a la siguiente.

Al resolver algo que no tenía asignado, se asigna solo a quien lo resolvió: si
no, el parte queda cerrado sin constar quién lo hizo.

## Estados según la clase del parte

Once estados tienen sentido para una avería: pendiente, asignado, en curso,
esperando a un tercero, esperando al cliente, programado, reabierta, resuelta,
cerrado, cancelada.

**Para montar una cuna, no.** Ofrecer «Pte. Terceros» al montar una cama extra
es exactamente la clase de estorbo que hace que la gente deje de usar una
herramienta.

Por eso cada estado declara a qué clases se aplica. Una petición o una tarea
sólo ven los cinco que le sirven; una avería los ve todos. El servidor lo
valida además de la interfaz, así que tampoco se puede colar por la API.

## Paneles de área

El aire acondicionado tenía panel propio en la versión anterior, y con razón:
es lo que más partes genera en un hotel. Pero el problema no es del aire — es
de cualquier área con volumen.

Así que hay uno para **cada familia**, y el administrador elige cuáles salen
como acceso directo en el inicio. Sin configurar nada sale el de climatización.

Cada panel reúne todo lo de esa área en una pantalla: abiertos, fuera de plazo,
aparatos en servicio, revisiones que tocan, dónde da más guerra, qué falla más,
cómo se hacen las cosas, y los partes abiertos con su acción rápida. Y un botón
para abrir un parte con el área ya puesta.

## Qué se puede exigir al dar un parte

En **Administración → Al dar un parte**:

- **Qué hay que rellenar por fuerza** — cualquiera de: qué falla, dónde,
  resumen, detalle, una foto, a quién va. Los obligatorios salen con un
  asterisco rojo. Cuantos más pongas, más tarda en darse un parte y menos se
  dan: elige sólo lo que de verdad haga falta.
- **Quién puede crear ubicaciones nuevas** — desde cualquiera hasta nadie. Con
  mucha gente el catálogo se llena de duplicados («101», «Hab 101», «101 baño»)
  y luego no hay quien busque. Se valida también en el servidor.
- **A qué grupo van por defecto** — normalmente Servicios Técnicos.
- **Prioridad por defecto** — la familia puede tener la suya y manda sobre esta.

## Elegir la ubicación

Dos caminos, los dos siempre visibles:

**Buscar** — si sabes la habitación, la escribes. «101» encuentra las 33
combinaciones que la contienen.

**Bajar por niveles** — cuatro pasos numerados, y cada uno filtra a los
siguientes. El orden en que se pregunta **no es el de la base de datos**:

| Se pregunta | Es el nivel | Por qué |
|---|---|---|
| 1º Zona | `zona_2` | «Habitaciones» o «Cocinas» reduce mucho más que una planta, y es lo primero que se sabe al dar un aviso |
| 2º Planta | `zona_1` | Ya sólo las que tienen ese tipo de sitio |
| 3º Lugar | `zona_3` | Sólo los de esa planta |
| 4º SubLugar | `zona_4` | Baño, terraza, dormitorio |

Elegir «Habitaciones» baja las plantas de 16 a 11 y los lugares de 501 a 336.
Elegir después la planta los deja en 17.

El orden se cambia en `config.php`, en `orden_zona`. El primer nivel se ofrece
entero aunque algún valor no tenga todavía ninguna ubicación registrada: si no,
sería inalcanzable y no se podría ni crear la primera.

## Consultar por ubicación

La pregunta que más se hace en un hotel no es «dame el listado»: es **«¿qué pasa
en la 214?»**, y la hacen los tres grupos por motivos distintos.

- **Recepción**, cuando un cliente se queja: quiere saber si ya está avisado.
- **Housekeeping**, antes de entregar la habitación: si queda algo pendiente,
  no se entrega.
- **Servicios Técnicos**, que ya va para allá: si hay dos cosas en la misma
  habitación, se hacen en un viaje en vez de dos.

Por eso la ficha de ubicación es de primera clase: se llega desde el icono de la
cabecera, o tocando la ubicación de cualquier parte. Muestra lo pendiente, el
historial completo, cuánto se ha dedicado y **de qué han sido las averías**.

Si algo se repite tres veces o más en el mismo sitio, se dice explícitamente:
no es mala suerte, es que no se arregló bien. Ese aviso vale más que la tabla
que hay debajo.

Desde ahí se abre un parte con la ubicación ya puesta, que es el paso más lento
del formulario.

### Aviso de duplicados

Al elegir la ubicación en el alta, si ya hay partes abiertos en ese mismo lugar
se muestran. En un hotel pasa constantemente: recepción abre «la TV de la 214 no
va» cuando Servicios Técnicos ya tiene uno abierto por lo mismo.

No se impide crear el parte —a veces son cosas distintas— pero se enseña lo que
hay, y cada uno de esos partes se puede abrir de un toque para comprobarlo.

## Familias, y por qué no basta con agrupar

Al mirar los 313 elementos reales aparece algo que el esquema no dice: **el
catálogo no es de averías**. Mezcla tres cosas que en un hotel se gestionan de
forma distinta:

| Clase | Ejemplos | Quién lo hace |
|---|---|---|
| **Avería** | grifo que gotea, ascensor parado, TV sin señal | Servicios Técnicos |
| **Petición** | cama extra, cuna, juntar camas, salida tardía, VIP | Housekeeping / Recepción |
| **Tarea** | repaso de limpieza, segunda limpieza, revisión | Housekeeping |

Una cama extra no es una incidencia: no hay nada roto, la pide recepción, la
monta housekeeping y su plazo lo marca la hora de llegada del cliente. Contarlas
juntas hace que «tuvimos 400 incidencias este mes» no signifique nada.

Por eso la familia lleva dos datos: **a qué oficio pertenece** y **de qué clase
de trabajo se trata**. Las dieciséis familias del catálogo salen de los datos
reales:

Fontanería · Electricidad · Climatización · Carpintería y obra · Mobiliario ·
Cerrajería y accesos · Televisión y sonido · Informática y redes · Cocina y
office · Lencería y textil · Equipamiento de habitación · Limpieza y repasos ·
Peticiones de cliente · Seguridad y emergencias · Zonas comunes · Otros

### Qué resuelve

**Elegir rápido.** Recepción no sabe si es la cisterna o el fluxor: sabe que el
baño de la 214 pierde agua. Se elige el área en una rejilla de dieciséis
iconos, y concretar el tipo es opcional. Quien sí lo sabe puede buscar
directamente entre los 313.

**Repartir solo.** Cada familia sabe qué grupo lleva ese oficio, así que el
aviso de recepción llega a Servicios Técnicos sin que nadie lo reparta a mano.
La familia también fija la prioridad de partida: una avería de cerrajería no
espera lo mismo que un cuadro torcido.

**Informes que significan algo.** Se puede agrupar y filtrar por familia y por
clase: cuántas averías de fontanería por planta, cuántas peticiones de cama
extra al mes, cuánto tarda cada oficio en cerrar.

### Consultar los campos propios

Los datos que pide cada tipo de avería también se pueden consultar. En los
informes aparecen con su tipo de avería al lado, para que quede claro que
«Temperatura de la sala» sólo existe en los partes de aire acondicionado.

Se puede preguntar «cuántos equipos fallaron con la sala por encima de 28
grados» o «en qué modo estaban los que se averiaron». Los numéricos se comparan
como números, no como texto: si no, 9 saldría mayor que 28.

### La clasificación

`tools/clasificar.php` asigna familia a los 313 con reglas de palabras clave.
De los 313, **309 caen en una familia reconocida** y 4 quedan en «Otros»
(Habitación, Mejoras, Plancha, Varios — que son genéricos de verdad).

```bash
php tools/clasificar.php            # informa de lo que haría
php tools/clasificar.php --dudosos  # sólo lo que no encaja
php tools/clasificar.php --aplicar  # lo aplica
```

El orden de las reglas importa y está pensado. Tres ejemplos de por qué:

- **«terraza» no es una familia, es una ubicación.** «Puerta terraza» es
  carpintería y «Luz terraza» es electricidad. Meterlas en Zonas comunes por la
  palabra «terraza» habría sido un error silencioso.
- **«Pared tv desconchon»** contiene «tv», pero es obra. La regla de desconchones
  va antes que la de televisión.
- **«Abatidor» y «Campana»** son frío y extracción, pero en un hotel son de
  cocina. La regla de cocina va antes que la de climatización.

Lo que no encaje se corrige desde **Administración → Familias**, donde se pueden
mover varios tipos de una vez.

## Los datos de partida

`db/catalogos.mysql.sql` y `db/catalogos.sqlite.sql` traen el catálogo real
extraído del volcado original:

| Tabla | Filas | Contenido |
|---|---|---|
| `elemento` | 313 | Tipos de avería, de «Abatidor» a «Zócalo», con su abreviatura |
| `familias` | 16 | Agrupación por oficio, con su clase y su grupo responsable |
| `estados` | 11 | Pendiente Asignar, Asignado, En Curso, Programado, Pte. Terceros, Pte. Usuario, Reabierta, Resuelta, Cerrado, Cancelada, Eliminado |
| `zona_1` | 16 | Plantas (00 a 12, Exteriores, Entreplantas, Cubierta) |
| `zona_2` | 6 | Zonas (Habitaciones, Zonas comunes, Restaurantes, Cocinas, Playa, Zona trabajador) |
| `zona_3` | 509 | Lugares: habitaciones y dependencias |
| `zona_4` | 72 | Sublugares (Baño, Terraza, Dormitorio, Administración…) |
| `rel_zonas` | 1.285 | Combinaciones válidas, con su ruta legible |

Y la ampliación 2.2 añade: configuración del sistema, campos propios por tipo,
atajos, informes guardados, preferencias de aviso y registro de copias.

Con esos volúmenes, elegir a base de rejillas de iconos o carriles de botones no
funciona: **todo se elige buscando**. Escribe «aire» y salen los 2 tipos que
coinciden; escribe «101» y salen las 33 ubicaciones. La búsqueda ignora acentos
y admite varias palabras. Los tipos más usados suben solos, porque el servidor
lleva un contador `usos`.

El catálogo entero se guarda en el móvil, así que buscar funciona sin cobertura.

## La aplicación de Android

El proyecto viene **generado y configurado** en `android/`: permisos, iconos en
las cinco densidades, icono adaptativo, pantalla de arranque, icono monocromo de
la barra de avisos y política de red. No hace falta `npx cap add android`.

Para obtener el APK:

```bash
./compilar-apk.sh              # de pruebas
./compilar-apk.sh --release    # firmado
```

El script comprueba los requisitos y avisa de lo que falta. Hace falta el SDK de
Android en la máquina que compile; lo demás está resuelto.

Antes de compilar, dos cosas que si se olvidan hacen que la app arranque pero no
funcione: fijar `window.SSTT_API` en `www/index.html`, y añadir
`https://localhost` a `cors_origins`. Está en **[docs/APK.md](docs/APK.md)**.

## Qué cambia dentro de la aplicación de Android

El mismo código detecta si se ejecuta dentro del APK y usa lo nativo cuando
está disponible:

| | Navegador | APK |
|---|---|---|
| Botón físico de atrás | — | Cierra la hoja abierta; en el inicio pide confirmación |
| Cámara | Selector de archivos | Cámara del sistema, orientación corregida |
| Avisos | Notification del navegador | Del sistema, llegan con la app cerrada |
| Estado de la red | `navigator.onLine` | Distingue wifi sin salida a internet |
| Abrir sin cobertura | Sólo con HTTPS | Siempre |

No hay dos versiones que mantener: si un complemento no está, esa función se
queda en la vía del navegador y todo lo demás sigue igual.

## Tarea programada

Una sola, opcional: el aviso de partes fuera de plazo.

```cron
0 * * * * php /ruta/sstt/tools/vencidos.php >> /var/log/sstt-vencidos.log 2>&1
```

Avisa una vez por parte, a quien lo tiene asignado o a su grupo, y a los
responsables si no tiene dueño. La marca no toca `updated_at`, así que no
provoca una sincronización innecesaria a todos los móviles.

## Requisitos

- PHP 8.0 o superior con PDO.
- MySQL 5.7+ / MariaDB 10.3+, o SQLite para instalaciones pequeñas.
- Recomendado: extensión GD (redimensiona las fotos en el servidor) y mbstring
  (hay respaldo propio si falta).
- Navegador moderno. Para el APK: Node 18+, Android Studio y JDK 17.

---

## Licencia

Código propio del proyecto. Sin dependencias de terceros en el servidor ni en el
navegador: no hay `node_modules` que auditar salvo los de Capacitor al compilar.


## Seguridad del servidor

Lo que hay fuera de `www/` y `api/` no se sirve por web: `db/`, `tools/`,
`pruebas/`, `docs/` y `android/` tienen su `.htaccess`, y ninguna carpeta se
lista. Además, cada script de `tools/` y `pruebas/` se niega a ejecutarse si no
es desde la consola, por si el servidor no lee los `.htaccess`.

Desde el navegador, `tools/instalar.php?clave=instalar` **no puede borrar
nada**: sólo actualiza, o instala si la base está vacía. Para empezar de cero
—se borra todo lo que haya— hay dos formas:

1. **Desde el navegador**: crea un fichero vacío llamado `REINSTALAR` (o
   `REINSTALAR.txt`) en la carpeta `db/` del servidor, y abre
   `tools/instalar.php?clave=instalar&demo&forzar`. Crear ese fichero demuestra
   que tienes acceso al servidor, cosa que no tiene quien sólo conoce la
   dirección. Vale una vez —se borra al usarse— y caduca en una hora.
2. **Desde la consola**: `php tools/instalar.php --demo --forzar`
   (en XAMPP para Windows: `C:\xampp\php\php.exe tools\instalar.php --demo --forzar`).

Tampoco se puede rehacer la clasificación entera desde el navegador, y la
clasificación normal ya no pisa el departamento ni el icono que un
administrador haya elegido a mano para un tipo de avería.

### Lo que se sube: fotos, vídeos y manuales

Tres cerrojos, explicados a fondo en `docs/SUBIDAS.md`.

**El nombre en disco no lo decide nadie de fuera.** Un archivo subido se
guarda como `<sha256>.<extensión del tipo real>`, y la extensión sale de
una tabla cerrada; lo que no esté en ella se guarda como `.bin`. Esto
cerró un agujero de verdad: para los tipos que no estaban en la tabla
—todos los vídeos— se usaba la extensión del nombre original, así que un
`.mp4` de verdad llamado `manual.php` acababa en disco como `<hash>.php`.

**El tipo se mira por dentro.** Se lee el contenido, no el nombre: un PHP
llamado `foto.png` se rechaza antes de guardarse.

**El `.htaccess` de la carpeta, con su letra pequeña.** Quita el manejador
de PHP (también con PHP-FPM, que ignora el `AddType`), deniega los `.php`
y añade `nosniff`. Pero un `.htaccess` sólo se lee si el servidor lo
permite: con `AllowOverride None` —el valor por defecto en Debian y
Ubuntu— no lo lee nadie, y en nginx no existe. Por eso este es el tercer
cerrojo y no el primero; `docs/SUBIDAS.md` trae la regla para Apache y
para nginx.

Ese candado lleva su versión dentro y `tools/instalar.php` lo renueva
cuando no coincide. Antes la comprobación era «si el fichero existe, ya
está», y una instalación en marcha no se arreglaba nunca sola: el hotel
que ya tenía fotos se quedaba con el candado del día que las subió, y en
aquel había un `php_flag engine off` suelto que en un Apache sin mod_php
devuelve **error 500 en todas las fotos**.

**El manual que se lee.** El contenido de un manual se guarda como datos,
nunca como HTML, y el dibujo se hace aquí copiando sólo las etiquetas de
una lista corta: un `<script>`, un `onerror` o un `<iframe>` no
sobreviven porque no se copian. Las fotos y los vídeos de un manual sólo
pueden salir de nuestras subidas —una dirección a un servidor de fuera se
rechaza, aunque sea `https`, porque delata a quien lee y porque el
contenido lo controla otro—, y el bloque «Vídeo de fuera» sólo admite
YouTube y Vimeo, comprobado al dibujar y no sólo al escribir: un enlace
dentro de la aplicación del hotel tiene la credibilidad de la
aplicación.

**Nada se carga de internet.** Editor.js y sus bloques vienen en el
paquete, en `www/assets/vendor/editorjs/`, con sus licencias al lado. La
tipografía de los iconos también es local. Comprobado con el navegador
escuchando: cero peticiones a dominios de fuera.


## Núcleo, paquetes y clientes

El sistema se instala por piezas:

    php tools/instalar.php --limpia          sólo el núcleo: nada de negocio
    php tools/instalar.php --paquetes=hotel  + los 313 tipos y las áreas de hotel
    php tools/instalar.php --demo            todo, como hasta ahora (Benalma)

- `db/nucleo.*`               estados y lo imprescindible
- `paquetes/hotel/*`          departamentos, áreas y catálogo de un hotel
- `paquetes/benalma/*`        las ubicaciones reales del Hotel Benalma

Una instalación limpia sirve para cualquier negocio; Benalma es un cliente
con sus datos, no el producto.

## Importar estructuras desde Excel

    php tools/importar.php --plantillas                 deja las plantillas en db/plantillas/
    php tools/importar.php ubicaciones hoja.xlsx        ensaya, sin tocar nada
    php tools/importar.php ubicaciones hoja.xlsx --aplicar

Cuatro estructuras: `departamentos`, `ubicaciones`, `tipos` y `equipo`. Lee
`.csv` y `.xlsx` sin librerías externas, y también en servidores sin la
extensión XML de PHP. Aguanta el punto y coma y la codificación de Excel en
español. Siempre ensaya primero y dice qué
crearía, qué ya existe y qué no ha entendido.

## Generar ubicaciones por rangos

Para no rellenar a mano una hoja de mil líneas:

    php tools/ubicaciones.php --plantas=1-12 --habitaciones=1-20 \
        --sublugares="Baño,Dormitorio,Terraza"          ensaya
    php tools/ubicaciones.php ... --aplicar             las crea
    php tools/ubicaciones.php ... --csv=hotel.csv       las deja en una hoja

Rangos con saltos (`1-12,14-16`), formatos de nombre (`Planta {n2}`,
`{p}{n2}`), zonas comunes (`--lugares="Recepción,Bar"`) y `--hotel=N` para
elegir a cuál van. Dos hoteles pueden tener su 101 sin pisarse.

## Asistente de instalación

    tools/instalar_gui.php?clave=instalar
    tools/instalar.php?clave=instalar&gui=1     (la misma pantalla)

La segunda puerta existe por un motivo práctico: la carpeta `tools/` está
cerrada salvo para una lista de ficheros que vive en `tools/.htaccess`, y ese
fichero empieza por punto. Al actualizar desde Windows es fácil que no se copie,
y entonces `instalar_gui.php` da «Forbidden»; por `instalar.php?gui=1` se abre
igual.

Cinco pasos: motor de base de datos (con prueba de conexión y escritura de
`config.php`), administrador con su propia contraseña, qué paquetes instalar,
los sitios del negocio —por rangos o desde una hoja— y un resumen antes de
tocar nada.

**Sobre un sistema ya montado, el asistente no se abre** sin el fichero
`db/REINSTALAR` en el disco del servidor. Antes se abría con sólo la
dirección, y aunque no podía vaciar la base, sí podía apuntar el hotel a
otra base de datos: lo que protegía los datos no protegía el acceso.
Para instalar de cero no cambia nada.

No repite la lógica del instalador: ejecuta los mismos scripts con sus
parámetros, así que hereda todas sus protecciones.

## Historial del elemento en la ficha del parte

Al final de cada parte, el bloque «Ya ha pasado aquí»: qué más ha ocurrido con
ese mismo tipo de avería en ese mismo sitio, y en el resto del hotel. Desde ahí
se salta a Informes con el historial completo del elemento ya preparado.

Se apaga desde Configuración → Comportamiento («Historial del mismo elemento y
sitio»), y se coloca o se oculta como cualquier bloque desde Apariencia. Viene
activado de fábrica.

## Guía de la API

En la aplicación: Administración → **Guía de la API**. De fábrica sólo la ve el
superadministrador, porque explica cómo entrar al sistema desde otro programa.
Se abre a los demás administradores con el ajuste «Guía de la API para todos los
administradores».

## Módulos opcionales (plugins)

Una carpeta en `plugins/` con un `plugin.php` que devuelve su ficha: sus
ajustes, qué rutas de `/api` atiende y qué enseñar en Ajustes. Nada más.

Vienen **apagados**. Apagado, el sistema se comporta como si la carpeta no
existiera: sus rutas no responden, sus pantallas no aparecen y el alta de
partes no lo menciona. Si un plugin está mal escrito, se queda fuera él solo.

**`plugins/sap/`** lee del PMS del hotel: ocupación, huéspedes, país e idioma,
estado de la habitación y fechas de la estancia. Sólo lee. Cinco maneras de
conectarse —REST, OData, SOAP, una carpeta con ficheros, o datos de prueba—, y
cada dato se abre a quien se decida: desde «nadie» hasta «todo el equipo».

Se configura en Configuración → Conector con SAP, y se prueba en
Ajustes → SAP → Conexión y prueba.

## Antes de poner esto en manos de la gente

```
php tools/diagnostico.php
```

Mira la instalación y dice si está en condiciones: tablas, personas, catálogo,
permisos de carpetas, copias de seguridad y errores pendientes. Lo que salga
como FALLA hay que resolverlo antes de arrancar.

## Cuando algo falle

**Administración → Errores.** Ahí queda todo lo que ha fallado, del servidor y
de los móviles: qué fue, a quién le pasó, en qué pantalla, cuántas veces y con
qué versión. Los repetidos se agrupan, así que un fallo que sufren treinta
personas es una línea con un contador, no treinta líneas.

Cada fallo tiene un botón de **Copiar**: eso es lo que hay que mandar a quien
lleve el mantenimiento.

## Qué queda pendiente

Ver `docs/PENDIENTE.md`: lo bloqueado por terceros (APK, SAP, certificado), lo
hecho pero sin verificar, y las decisiones que conviene revisar con el tiempo.

## Si una pantalla va lenta

En `api/config.php`, `consultas_lentas_ms` anota en el registro de errores de
PHP toda consulta que pase de esos milisegundos, con su tiempo y su texto:

    'consultas_lentas_ms' => 20,

Sirve para saber *por qué* va lenta en vez de adivinar. Déjalo en `0` cuando
termines: con el valor puesto, cada consulta se cronometra.

Así se encontró que el inicio pasaba de 3 ms con 28 partes a 200 con 20.000
—no por una consulta lenta, sino por dieciséis medianas—, y los índices de la
ampliación 5.7 lo dejaron en 120 ms.
