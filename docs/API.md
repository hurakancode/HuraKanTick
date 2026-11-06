# Referencia de la API

Todas las rutas cuelgan de `api/index.php`. Se puede llamar de dos formas:

```
GET /hurakantick/api/index.php?_ruta=tickets&abiertos=1     siempre funciona
GET /hurakantick/api/tickets?abiertos=1                     si hay mod_rewrite
```

La aplicación usa siempre la primera para no depender de la configuración
del servidor.

## Autenticación

Token portador en cabecera. Se obtiene al entrar y caduca a los 30 días.

```
Authorization: Bearer <token>
```

En la base de datos sólo se guarda el SHA-256 del token, nunca el token.

## Respuestas

Éxito: `{"ok": true, ...}`
Error: `{"ok": false, "error": "Texto para la persona", "campo": "correo"}`

Los mensajes de error están escritos para mostrarse tal cual al usuario.

| Código | Significado |
|---|---|
| 400 | Petición mal formada |
| 401 | Sin sesión o caducada |
| 403 | Sin permisos para esta acción |
| 404 | No existe |
| 409 | Ya existe (por ejemplo, correo repetido) |
| 413 | Archivo o lote demasiado grande |
| 415 | Tipo de archivo no admitido |
| 422 | Falta un dato o no es válido |
| 429 | Demasiados intentos |
| 500 | Error del servidor |

---

## Sesión

| Método | Ruta | Descripción |
|---|---|---|
| POST | `auth/login` | `{correo, password}` → `{token, expira, usuario}` |
| POST | `auth/logout` | Revoca el token actual |
| GET | `auth/yo` | Perfil de quien llama |
| POST | `auth/password` | `{actual, nueva}`. Cierra las demás sesiones |
| GET | `auth/sesiones` | Dispositivos con sesión abierta |
| DELETE | `auth/sesiones` | Cierra el resto de sesiones |
| POST | `auth/enlace` | Entrar con un enlace de auto-entrada. No lleva token |

El enlace de auto-entrada tiene su propio freno por origen, separado del de la
contraseña: una tablet con un marcador caducado no deja a ese aparato sin poder
entrar también con usuario y contraseña.

## Catálogo

| Método | Ruta | Descripción |
|---|---|---|
| GET | `catalogo` | Estados, prioridades, tipos, departamentos, personas y zonas |

Una sola llamada: la app la guarda entera para funcionar sin conexión. Son 313
tipos de avería y 1.285 ubicaciones, así que llega en un único bloque y se
consulta desde IndexedDB.

Los tipos vienen ordenados por `usos` (cuántos partes los han usado), de modo
que los seis primeros son los que de verdad se repiten y la aplicación los
ofrece como atajo.

## Partes

| Método | Ruta | Descripción |
|---|---|---|
| GET | `tickets` | Listado. Ver filtros abajo |
| POST | `tickets` | Alta. Admite `fotos: [{datos, nombre}]` en base64 |
| GET | `tickets/{id}` | Ficha + historial + adjuntos |
| PATCH | `tickets/{id}` | Modificar |
| PUT | `tickets/{id}` | Lo mismo que `PATCH`, para clientes que no lo usen |
| POST | `tickets/lote` | La misma acción sobre varios a la vez |
| DELETE | `tickets/{id}` | Borrado lógico (sólo administradores) |
| POST | `tickets/{id}/movimientos` | Nota, cambio de estado o asignación |
| GET | `tickets/{id}/historial` | Sólo el historial |
| POST | `tickets/{id}/adjuntos` | Foto por multipart o base64 |
| GET | `tickets/{id}/adjuntos` | Fotos del parte |
| GET | `adjuntos/{id}` | Descargar una foto, con control de acceso |
| DELETE | `adjuntos/{id}` | Borrar una foto |

**Cuerpo de `POST tickets/lote`**: `{ids: [...], accion}`, hasta 200 partes.
`accion` vale `estado` (con `id_estado`), `asignar` (con `id_persona_asignada`),
`borrar` o `eliminar`. Cada parte va en su propia transacción: si uno falla, los
demás siguen, y la respuesta dice cuántos se hicieron y cuántos se saltaron.

**Filtros de `GET tickets`**: `q`, `estado` (lista separada por comas),
`prioridad`, `elemento`, `departamento`, `asignado`, `zona`, `zona1`, `zona2`,
`mios`, `creados_por_mi`, `sin_asignar`, `abiertos`, `vencidos`, `desde`,
`hasta`, `orden` (`reciente|antiguo|prioridad|limite|estado`), `limite`, `offset`.

**Campos de `POST tickets`**: `uuid` (lo genera el cliente), `titulo`,
`detalle`, `id_elemento`, `id_prioridad`, `zona1..zona4` o `id_rel_zona`,
`id_persona_asignada`, `fecha_creado`, `origen`.

Si no mandas `titulo`, se compone con el tipo de avería y la ubicación.
Si no mandas `fecha_limite`, se calcula con el SLA de la prioridad.

**Campos de `PATCH tickets/{id}`**: `id_estado`, `id_persona_asignada`,
`id_grupo_asignado`, `id_prioridad`, `titulo`, `detalle`, `fecha_limite`,
`nota`, `minutos`, `coste`, `uuid_movimiento`.

Cada cambio deja un movimiento en el historial con quién, cuándo y qué. El
campo `tipo` del movimiento vale `alta`, `estado`, `asignacion`, `edicion`,
`adjunto` o `nota`, y el detalle describe el cambio en lenguaje llano
(«Prioridad: Normal → Urgente · Resumen corregido»).

## Panel y avisos

| Método | Ruta | Descripción |
|---|---|---|
| GET | `panel` | Indicadores del inicio, adaptados al puesto de quien pregunta |
| GET | `notificaciones` | Avisos. `?nuevas=1` para sólo los no leídos |
| POST | `notificaciones/leidas` | `{ids}` o vacío para marcar todos |

La respuesta de `panel` trae `perfil` (`responsable`, `tecnico`, `limpieza` o
`reporta`) y un bloque `extra` con lo propio de cada uno: `mios_abiertos`,
`cola_grupo`, `lugares_pendientes`… Los bloques pesados —serie de 14 días,
carga del equipo, tiempo medio— sólo se calculan para el perfil de
responsable, así que el panel de un técnico también es más rápido.

Cada aviso lleva `evento`: `chat` en los que deja un mensaje de la
conversación de un parte, y el tipo de cambio en los demás.

## Conversaciones

| Método | Ruta | Descripción |
|---|---|---|
| GET | `conversaciones` | Una línea por parte con conversación: lo último que se dijo, quién, y `sin_leer` |
| GET | `tickets/{id}/chat` | Los mensajes del parte, quién ha leído cada uno, participantes y `sin_leer` |
| POST | `tickets/{id}/chat` | Escribir: `{texto, importante}`. Admite `uuid` para no duplicar al reintentar sin red |
| POST | `tickets/{id}/chat/leido` | Marcar como leído lo pendiente. Devuelve `leidos` y `no_leidas` |

Participan quien abrió el parte, quien lo tiene asignado, quien ya haya
escrito y quien lo siga como observador. Cada mensaje deja además un aviso
(`evento: chat`) a los demás participantes. **Marcar la conversación como
leída apaga también esos avisos**, sólo los de chat de ese parte: por eso
`chat/leido` devuelve `no_leidas`, los avisos que le quedan, para que la
campana se ponga al día sin esperar.

En `catalogo`, `sin_leer` = `{chats, avisos, total}`: `chats` cuenta
conversaciones (una con seis mensajes nuevos cuenta una) y `avisos` cuenta los
avisos sin leer **que no son de chat**, porque esos ya están en `chats`.

## Sincronización

| Método | Ruta | Descripción |
|---|---|---|
| GET | `sync/pull?desde=` | Cambios desde esa marca temporal |
| POST | `sync/push` | `{operaciones: [...]}` |

`pull` devuelve `hasta` (marca para la siguiente llamada) y `completo`. Si
`completo` es `false`, vuelve a llamar con el `hasta` recibido: hay más páginas.

**Detección de conflictos.** En `ticket.actualizar` el cliente envía lo que
creía saber del parte:

```json
{"version_base": 3, "estado_base": 2, "asignado_base": 5,
 "id_estado": 3, "nota": "…", "uuid_movimiento": "…"}
```

Si el parte cambió mientras tanto **y** el cambio choca, la respuesta es 409 y
la operación no se aplica:

```json
{"id_local": 7, "ok": false, "codigo": 409, "reintentar": false,
 "conflicto": {
   "tipo": "estado",
   "valor_servidor": "Cerrado",
   "valor_local": "En Curso",
   "valor_base": "Pendiente Asignar",
   "quien": "Carlos Duarte",
   "cuando": "2026-07-24 11:27:00",
   "mensaje": "Mientras estabas sin conexión, este parte pasó a «Cerrado»…"
 },
 "ticket": { … estado actual completo … }}
```

Tipos de conflicto: `estado`, `asignacion` y `cerrado` (reabrir algo que otro
dio por cerrado). Las notas, el tiempo y las fotos nunca generan conflicto:
sólo añaden.

Para imponer el cambio local se reenvía la misma operación con `"forzar": true`
y sin `version_base`.

Vale también para dos peticiones que llegan **a la vez** (dos técnicos
pulsando «Me lo quedo» en el mismo segundo): la versión sólo avanza si sigue
siendo la que se leyó, así que una gana y la otra recibe el 409 con su
conflicto. Si un parte cambia tantas veces seguidas que tres intentos chocan,
la respuesta es un 503 (`reintentar: true`) y el móvil lo vuelve a mandar.

Operaciones admitidas en `push`:

```json
{
  "operaciones": [
    {"id_local": 1, "tipo": "ticket.crear",
     "datos": {"uuid": "…", "titulo": "…", "id_elemento": 5, "zona3": 12}},

    {"id_local": 2, "tipo": "ticket.actualizar",
     "datos": {"uuid_ticket": "…", "uuid_movimiento": "…",
               "id_estado": 3, "nota": "Reparado", "minutos": 45}},

    {"id_local": 3, "tipo": "adjunto.subir",
     "datos": {"uuid": "…", "uuid_ticket": "…", "nombre": "foto.jpg",
               "datos": "data:image/jpeg;base64,…"}}
  ]
}
```

Respuesta por operación:

```json
{"id_local": 1, "ok": true, "id_ticket": 42, "duplicado": false, "ticket": {…}}
{"id_local": 4, "ok": false, "error": "…", "codigo": 422, "reintentar": false}
```

`reintentar: true` (errores 5xx) significa que el cliente debe volver a
intentarlo. `false` (errores 4xx) significa que el envío nunca funcionará tal
cual y hay que apartarlo.

Máximo 200 operaciones por tanda. La app envía de 25 en 25 porque las fotos
pesan.

## Informes

| Método | Ruta | Descripción |
|---|---|---|
| GET | `informes/definiciones` | Campos, operadores, medidas y periodos disponibles |
| POST | `informes/ejecutar` | Ejecuta una consulta y devuelve el resultado |
| GET | `informes` | Los guardados (propios y compartidos) |
| POST | `informes` | Guardar uno |
| PATCH | `informes/{id}` | Modificar |
| DELETE | `informes/{id}` | Borrar uno guardado |

Cuerpo de `informes/ejecutar`:

```json
{
  "condiciones": [
    {"campo": "elemento", "operador": "contiene", "valor": "televis"},
    {"campo": "lugar",    "operador": "es", "valor": "317", "por": "nombre"}
  ],
  "union": "y",
  "agrupar": "elemento",
  "periodo": "",
  "medida": "cuenta",
  "detalle": false,
  "limite": 50
}
```

`por` sólo aplica a campos de catálogo y vale `id` o `nombre`. Hace falta
porque los nombres de habitación son números: sin él, «317» es ambiguo.

Medidas: `cuenta`, `minutos`, `coste`, `media_horas`, `porcentaje_plazo`.
Periodos: `dia`, `semana`, `mes`, `trimestre`, `anio`.

La visibilidad se aplica igual que en el listado: un informe nunca enseña
partes que la persona no podría ver.

## Estados y clases

Cada estado del catálogo declara en `clases` a qué clases de parte se aplica
(`averia`, `peticion`, `tarea`), separadas por comas. Vacío significa todas.
`rapido = 1` marca los que se ofrecen como acción rápida desde el listado.

Si se intenta poner un estado que no corresponde a la clase del parte, la API
responde 422. No es sólo cosa de la interfaz.

## Números de serie

| Método | Ruta | Descripción |
|---|---|---|
| GET | `unidades?q=` | Buscar por número de serie |
| GET | `unidades?zona=N&lugar=1` | Todo lo que hay y ha habido en un sitio, con resumen por tipo |
| GET | `unidades?zona=N&elemento=M` | Filtrado por tipo de aparato |
| GET | `unidades/{id}` | Ficha, movimientos y partes de ese aparato |
| POST | `unidades` | Alta. Con `id_sustituye` da de baja al anterior |
| PATCH | `unidades/{id}` | Cambiar estado o ubicación, dejando rastro |

Estados: `activa`, `reparacion`, `almacen`, `baja`.
Motivos de baja: `rotura`, `obsoleto`, `robo`, `fin_vida`, `garantia`, `otro`.

`elemento.usa_serie` marca qué tipos lo piden. Los partes llevan `id_unidad`
opcional para referirse a un aparato concreto.

## Instrucciones

| Método | Ruta | Descripción |
|---|---|---|
| GET | `instrucciones?q=&familia=&elemento=` | Buscar |
| GET | `instrucciones/{id}` | Ficha, y cuenta la consulta |
| POST | `instrucciones/{id}/util` | Marcar que sirvió |
| POST | `instrucciones` | Escribir una (responsables) |
| PATCH/DELETE | `instrucciones/{id}` | Modificar o archivar |
| POST | `instrucciones/medios` | Subir una foto o un vídeo del manual |

Van también **completas dentro de `catalogo`**, para poder leerlas sin
cobertura. Es la única parte del sistema que se duplica así a propósito: el
tamaño es pequeño y el valor de tenerlas en un sótano es alto.

## Papelera

| Método | Ruta | Descripción |
|---|---|---|
| GET | `papelera` | Lo borrado. Filtros: `tabla`, `hotel`, `q`, `limite` |
| GET | `papelera/{id}` | La fila entera, tal cual estaba al borrarse |
| POST | `papelera/{id}/restaurar` | Devolverlo a la base viva |
| DELETE | `papelera/{id}` | Tirarlo de verdad, ya sin vuelta |

Sólo superadministradores. Vive en `db/hurakantick-papelera.sqlite`, con la misma
estructura que la base viva más seis columnas (`pap_fecha`, `pap_autor`,
`pap_motivo`…). Las columnas de contraseña no se clonan.

## Hoteles

| Método | Ruta | Descripción |
|---|---|---|
| POST | `hoteles/{id}/restablecer` | `{partes, usuarios, confirmacion}` — vacía el hotel |

`confirmacion` debe ser el nombre exacto del hotel. Los administradores se
conservan siempre.


| Método | Ruta | Descripción |
|---|---|---|
| GET | `hoteles` | Los que puede ver quien pregunta |
| POST | `hoteles` | Crear (superadministradores). `copiar_de` trae las ubicaciones |
| PATCH | `hoteles/{id}` | Modificar |
| POST | `hoteles/{id}/copiar` | `{desde, instrucciones}` — copia ubicaciones |
| DELETE | `hoteles/{id}` | Con datos detrás se desactiva en vez de borrarse |

Un superadministrador añade `?hotel=N` a cualquier ruta para trabajar sobre ese
hotel. El resto de la gente no puede: su hotel sale de su ficha y no se puede
cambiar desde fuera.

## Seguridad y cuenta

| Método | Ruta | Descripción |
|---|---|---|
| POST | `auth/correo` | Cambiar el propio correo. Pide `{correo, password}` |
| POST | `auth/recuperar` | `{correo}`. Responde igual exista o no la cuenta |
| POST | `auth/codigo` | `{correo, codigo, nueva}`. Revoca todas las sesiones |
| GET | `usuarios/recuperaciones` | Peticiones pendientes (administradores) |
| POST | `usuarios/{id}/codigo` | Emitir un código de seis dígitos, mostrado una vez |
| GET | `seguridad` | Registro filtrable por `tipo`, `quien`, `ip`, `fallos`, `desde` |
| DELETE | `seguridad` | Purgar lo que ha pasado del plazo de conservación |

Con el **modo mantenimiento** activo, todas las rutas responden 503 con
`{"mantenimiento": true}` salvo `estado`, `auth/*` y `sync/push`. Esta última
se deja pasar a propósito: bloquearla haría perder a la gente lo que tenga
guardado en el móvil sin enviar.

## Mantenimiento programado

| Método | Ruta | Descripción |
|---|---|---|
| GET | `mantenimiento` | Calendario con estado de cada revisión |
| POST | `mantenimiento` | Crear (responsables) |
| PATCH/DELETE | `mantenimiento/{id}` | Modificar o desactivar |
| GET | `mantenimiento/{id}/historial` | Todos los partes que ha generado |
| POST | `mantenimiento/generar` | `{simular: true}` dice qué haría sin hacerlo |

Cada registro lleva `intervalo` + `unidad` (`dias`, `semanas`, `meses`,
`anios`), la fecha `proximo` y `aviso_dias` de antelación. Los partes
generados llevan `id_mantenimiento`, así que se puede reconstruir el
historial completo de una revisión.

## Ubicaciones

| Método | Ruta | Descripción |
|---|---|---|
| GET | `ubicaciones/buscar?q=214` | Sitios que coinciden, con cuántos partes tienen |
| GET | `ubicaciones/{id_rel}?lugar=1` | Ficha completa: resumen, reparto por familia e historial |
| GET | `ubicaciones/abiertos?lugar=N` | Partes abiertos en un lugar, para avisar de duplicados |

Y para mantenerlas (sólo administradores):

| Método | Ruta | Descripción |
|---|---|---|
| POST | `ubicaciones-admin/nivel` | Crear un valor de un nivel (un edificio, una planta…) |
| PATCH | `ubicaciones-admin/nivel` | Renombrarlo |
| DELETE | `ubicaciones-admin/nivel?nivel=N&id=M` | Quitarlo, si no se usa |
| POST | `ubicaciones-admin/rango` | Dar de alta habitaciones de golpe (de la 201 a la 240) |
| DELETE | `ubicaciones-admin/{id_rel}` | Quitar una combinación concreta |

`lugar=1` amplía la consulta a todo el lugar en vez del punto exacto: quien
pregunta por la 214 quiere saber también lo del baño de la 214.

Todas aplican la visibilidad: nadie ve partes que no le corresponden.

## Familias

| Método | Ruta | Descripción |
|---|---|---|
| GET | `familias` | Todas, con su clase, grupo y número de tipos |
| GET | `familias/{id}/elementos` | Los tipos que contiene |
| POST | `familias` | Crear |
| PATCH/DELETE | `familias/{id}` | Modificar o eliminar (sólo si está vacía) |
| POST | `familias/mover` | `{elementos: [ids], id_familia: N}` |

La clase vale `averia`, `peticion` o `tarea`, y separa lo que en un hotel no
se cuenta junto: una cama extra no es una incidencia.

Al crear un parte se puede mandar `id_familia` sin `id_elemento`: quedarse en
la familia es una respuesta válida, y muchas veces la única que quien avisa
puede dar. Si llega el elemento sin familia, se deduce.

En los informes hay dos campos nuevos: `familia` y `clase`.

## Configuración y extras

| Método | Ruta | Descripción |
|---|---|---|
| GET | `ajustes` | Configuración por categorías |
| PATCH | `ajustes` | `{cambios: {clave: valor}}` (sólo administradores) |
| GET | `campos?elemento=N` | Campos propios de un tipo de avería |
| POST/PATCH/DELETE | `campos[/{id}]` | Gestionarlos (sólo administradores) |
| GET | `atajos` | Los visibles para quien pregunta |
| POST/PATCH/DELETE | `atajos[/{id}]` | Gestionarlos |
| GET | `copias` | Copias de seguridad existentes |
| POST | `copias` | Crear una. `{con_fotos: true}` incluye las imágenes |
| POST | `copias/{id}/vale` | Un vale de 60 segundos para poder descargarla |
| GET | `copias/{id}?vale=...` | Descargarla. Única ruta que no lleva cabecera |
| GET | `notificaciones/preferencias` | Qué avisos quiere esta persona |
| POST | `notificaciones/preferencias` | `{evento, activo, sonido}` |
| DELETE | `notificaciones/preferencias` | Volver a los valores del administrador |
| DELETE | `notificaciones/{id}` | Borrar un aviso recibido |
| DELETE | `notificaciones` | Vaciar los avisos de quien llama |
| POST | `sistema/renovar` | Obliga a todos a descargar la versión nueva |
| GET | `actualizacion` | ¿Hay una versión nueva publicada? Con `&forzar=1` vuelve a preguntar |

En el alta y la modificación de partes, `campos` lleva los valores propios:
`{"campos": {"aa_modo": "Frío", "aa_temp_real": "28"}}`.

## Versión nueva

`GET actualizacion` (sólo administradores) dice si en
https://github.com/hurakancode/HuraKan-Tick hay una versión más nueva que
la instalada. Entiende una *Release* con su etiqueta, la etiqueta más
alta o un `version.json` en la rama principal, por ese orden. Pregunta
como mucho cada doce horas; con `&forzar=1`, en el momento. No descarga
ni instala nada, y no manda nada del hotel.

```json
{"actualizacion": {"actual": "7.90.0", "publicada": "7.91.0", "hay_nueva": true,
  "donde": "https://github.com/hurakancode/HuraKan-Tick/releases/tag/v7.91.0",
  "como": "release", "motivo": "", "comprobado": "2026-11-04 12:00:00"}}
```

Se apaga en `api/config.php` con `'actualizaciones' => ['comprobar' => false]`.

## Integridad

| Método | Ruta | Descripción |
|---|---|---|
| GET | `integridad` | Sólo administradores. Repasa datos, ficheros, seguridad y sistema. **Sólo lee** |

Devuelve `comprobaciones` —cada una con `grupo`, `id`, `estado` (`bien`,
`aviso`, `falla` o `nd` = no se ha podido comprobar), `titulo`, `detalle`
(qué hacer si no está bien) y `n` cuando cuenta algo—, un `resumen` con
cuántas hay de cada estado y la `fecha`. Para saber si el servidor web sirve
lo que no debe, le pregunta por HTTP a la propia dirección de la aplicación;
con el servidor integrado de PHP (`php -S`) esa comprobación sale como `nd`,
porque no atiende dos peticiones a la vez. Lo mismo, desde la consola:
`php tools/diagnostico.php`.

## Administración

| Método | Ruta | Descripción |
|---|---|---|
| GET | `usuarios` | Personas y su carga de trabajo |
| POST | `usuarios` | Alta (sólo administradores) |
| PATCH | `usuarios/{id}` | Editar. Cada cual puede editar su propia ficha |
| DELETE | `usuarios/{id}` | Baja. Con `?definitivo=1` se borra de verdad |
| POST | `usuarios/lote` | La misma acción sobre varias personas |
| POST | `usuarios/{id}/codigo` | Emitir un código de un solo uso |
| POST | `usuarios/{id}/enlace` | Crear el enlace de auto-entrada |
| PATCH | `usuarios/{id}/enlace` | Renovarlo |
| DELETE | `usuarios/{id}/enlace` | Anularlo |
| GET | `zonas` | Árbol de ubicaciones |
| POST | `zonas` | Alta de una ubicación. Admite ids o nombres por nivel |
| GET | `estado` | Diagnóstico. No requiere sesión |

`usuarios/lote` lleva `{ids: [...], accion}`, con `accion` igual a `rol`,
`departamento`, `bloquear` o `teams`. Cada persona va en su propia transacción
y se comprueban las mismas reglas que al editarla una por una: un atajo en masa
que se salte las del camino individual es la forma más fácil de abrir un
agujero.

Los enlaces de auto-entrada sólo los maneja el administrador de la cadena. Un
enlace es una llave, y repartir llaves de otros hoteles no es cosa de un
administrador local.

## Departamentos

| Método | Ruta | Descripción |
|---|---|---|
| GET | `departamentos` | El catálogo común, con quién usa cada uno |
| POST | `departamentos` | Crear uno |
| PATCH | `departamentos/{id}` | Editar |
| DELETE | `departamentos/{id}` | Quitarlo. Con gente detrás se desactiva |
| PUT | `departamentos/{id}/hoteles` | En qué hoteles se usa |

El catálogo es común a toda la cadena, y cada hotel enciende los que necesita.
Así «Mantenimiento» significa lo mismo en los informes de todos.

## Personal

| Método | Ruta | Descripción |
|---|---|---|
| GET | `personal` | El estado de las dos carpetas y lo que hay |
| GET | `personal/plantilla` | La plantilla para rellenar, en texto plano |
| GET | `personal/plantilla/{id}` | La ficha de una persona, en el mismo formato |
| POST | `personal/importar` | Dar de alta lo que haya en la carpeta de entrada |
| POST | `personal/exportar` | Escribir fichas en la carpeta de salida |
| GET | `personal/descargar?f=...` | El contenido de un exportado |
| DELETE | `personal/exportados` | Limpiar la carpeta de salida |

Es el puente con SAP y con las altas en papel: ficheros de texto en dos
carpetas, sin formato propietario. La plantilla viene con el hotel de quien la
descarga ya marcado.

## Avisos en el móvil

| Método | Ruta | Descripción |
|---|---|---|
| GET | `push/clave` | La clave pública que el navegador necesita para suscribirse |
| POST | `push` | Dar de alta este aparato |
| DELETE | `push` | Darlo de baja |
| POST | `push/probar` | Mandarse un aviso a uno mismo |

`push/probar` distingue las dos cosas que pueden salir mal: que no haya ningún
aparato apuntado, y que el envío falle. Devuelve `apuntados`, y si el envío
falla, el motivo queda anotado en `errores`.

## Mensajes y fallos

| Método | Ruta | Descripción |
|---|---|---|
| GET | `mensajes` | Los avisos que toca enseñar ahora |
| POST | `mensajes/visto` | Se ha cerrado uno |
| POST | `errores` | El móvil manda un fallo suyo |
| GET | `errores` | La lista, para quien administra |
| POST | `errores/visto` | Darlos por vistos (todos o uno) |

## Permisos

| Rol | Puede |
|---|---|
| `admin` | Todo, incluida la gestión de personas |
| `responsable` | Asignar y cerrar partes de cualquiera |
| `tecnico` | Trabajar en los partes que tenga asignados o haya abierto |
| `reportador` | Abrir partes y seguirlos |
