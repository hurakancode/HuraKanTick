# Guía para probarlo

Esto no es documentación: es una lista de qué mirar y en qué orden, con las
cuentas que hacen falta para cada cosa.

## Antes de mirar nada a mano

Hay una batería que lo comprueba sola:

```bash
php tools/diagnostico.php      # la instalación: permisos, certificado, copias
python3 pruebas/todas.py       # las 36 pruebas, algo más de una hora
python3 pruebas/todas.py --mysql   # las mismas, contra MySQL
```

Para montar un paquete: `./empaquetar.sh`, y después instalarlo desde el
zip antes de darlo por bueno.

**Pásalas con los dos motores.** Hasta hace poco sólo se pasaban contra
SQLite, y la primera vez que se pasaron contra MySQL apareció a la
primera un fallo que SQLite no enseña: buscar una ubicación devolvía un
error de base de datos. Si el hotel va a funcionar con MySQL —con
cincuenta personas, lo normal—, lo que importa es la segunda orden.

Las dos órdenes de arriba dan por hecho que las instalaciones están en
`/var/www/html/hurakantick` (SQLite) y `/var/www/html/hurakantick-my` (MySQL). Si las
tuyas están en otro sitio, se dice con tres variables y la batería entera
va contra ellas:

```bash
HURAKANTICK_DIR=/ruta/de/la/instalacion \
HURAKANTICK_RAIZ=https://tu-servidor/hurakantick \
HURAKANTICK_API="https://tu-servidor/hurakantick/api/index.php?_ruta=" \
python3 pruebas/todas.py
```

El diagnóstico tarda un segundo y dice si la instalación está en pie. La
batería tarda cuarenta minutos y da **tres** respuestas por prueba, no dos:

- **pasa** — ha mirado y no ha encontrado nada.
- **FALLA** — ha encontrado algo. Está en el programa.
- **ROTA** o **no probada** — la prueba no ha podido terminar o le falta
  algo para correr. **No dice nada del programa**: contarla como que pasa
  sería decir que está bien sin haber mirado.

Al terminar avisa si la tanda ha dejado restos en los datos.

Para una comprobación rápida, las que no abren navegador tardan un par de
minutos:

```bash
python3 pruebas/todas.py rapidas
python3 pruebas/todas.py acceso copias listados     # o las que se digan
```

Dos avisos: **no se lanzan dos tandas a la vez** —se pisan los datos y dan
fallos que no existen; hay un cerrojo que lo impide— y las pruebas
**crean cosas de verdad** en la base, así que se lanzan sobre la
instalación de pruebas, no sobre la del hotel.

Tres de esas pruebas hablan del **servidor**, no del programa, y conviene
mirarlas en el vuestro antes que nada:

- `expuesto` pide por HTTP lo que no debería servirse —`db/`, las copias,
  los paquetes, el asistente de instalación— y comprueba las cabeceras.
  No mira si los candados están puestos: los candados pueden estar
  puestos y el servidor no leerlos.
- `vales` comprueba que la sesión no viaje escrita en ninguna dirección.
- `matriz` pide **cada llamada de la aplicación** (134) como cada papel y sin sesión, y compara con lo esperado: si alguien gana acceso es un agujero; si lo pierde, una pantalla vacía. Además: secretos de la configuración con valor puesto, el alias de departamento (permiso campo a campo), partes, fotos y conversaciones ajenas con números reales, y 221 peticiones con SQL hostil.
- `jornada` es un día de trabajo de cada papel **por la pantalla**: recepción da un parte, el técnico se lo queda, anota, lo pone en curso y contesta, la técnica de Pisos intenta abrir lo que no es suyo, el responsable lo reasigna y el administrador recorre toda la administración y lo cierra. Todo lo que escriben lleva HTML hostil, y falla si se ejecuta, si no se ve como texto, si hay un error de página o de consola, un 5xx o un 403 que provoque la propia aplicación. Comprueba en el historial que cada paso ocurrió, y que su detector caza lo que se le mete a propósito.
- `subidas` ataca la subida de fotos: .php, .php disfrazado, SVG con código, HTML, imagen con PHP dentro, nombres con «../» y subir a un parte ajeno, por fichero y por base64.
- `recuperar` es la vida de una cuenta de punta a punta: restablecer con el código del administrador, un solo uso, freno a quien lo adivina, cambiar la propia, salir, y la auto-entrada (y que el registro de seguridad diga quién repartió cada llave).
- Lo que sólo falla **a la vez**: `a_la_vez` lanza ocho rondas de «me lo quedo» simultáneo con la versión que manda la app (uno gana, el otro 409); `recuperar` manda treinta códigos y veinte veces el bueno a la vez (no más de cinco comparados, vale una vez); `acceso`, treinta contraseñas contra una cuenta y ciento veinte desde un equipo (no pasan del tope).
- `integridad` comprueba el panel de integridad: sólo para administradores, no cambia nada, y **detecta** cuatro desarreglos sembrados a propósito (y vuelve a «bien» al quitarlos).
- `mensajes` comprueba que leer una conversación apaga sus avisos (y sólo los suyos) y que la chapa de «Mensajes» no cuenta cada mensaje dos veces.
- `apidoc` comprueba que `docs/API.md` no prometa rutas que no existen ni
  se calle las que sí.

Lo de abajo es para mirar con los ojos lo que ninguna prueba puede juzgar:
si encaja con vuestra forma de trabajar.

## Arrancar

```bash
php tools/instalar.php --demo
php tools/clasificar.php --aplicar
php -S 0.0.0.0:8080 -t .
```

Y abre `http://localhost:8080/www/index.html`

Si algo no carga, abre **`www/diagnostico.html`**: comprueba archivo por archivo
y dice qué falla.

## Las cuentas

Contraseña para todas: `Selenta2026+`

| Cuenta | Puesto | Para probar |
|---|---|---|
| `daniel.melgar@hotelbenalma.com` | Administrador | Todo, incluida la administración |
| `carlos.duarte@hotelbenalma.com` | Responsable · Servicios Técnicos | Panel completo, reparto |
| `david.sstt@hotelbenalma.com` | Técnico · Servicios Técnicos | El día a día de un técnico |
| `fany.prieto@hotelbenalma.com` | Técnica · Pisos | Panel de limpieza |
| `recepcion.costadelsol@hotelbenalma.com` | Recepción | Lo mínimo: avisar y seguir |

Se puede entrar con el correo entero o sólo con lo de antes de la arroba:
`carlos.duarte` vale igual que `carlos.duarte@hotelbenalma.com`.

**Esa contraseña va escrita aquí, así que no sirve para trabajar.** Si
instalas con `--demo` en el servidor del hotel, cámbiala en cuanto
entres: `php tools/diagnostico.php` sale en rojo mientras algún
administrador la siga teniendo.

**Entra con cada una.** Verás cuatro aplicaciones distintas sobre los mismos
datos: es a propósito, y es lo que más conviene comprobar que encaja con vuestra
forma de trabajar.

---

## Por dónde empezar

### 1. Dar un parte como lo daría recepción

Entra como **pilar**. La pantalla es sólo dos cifras y un botón grande.

- Toca «Avisar de algo»
- «¿Qué falla?» → elige **Fontanería** → «Sin concretar más»
- «¿Dónde?» → busca **101** y elige una
- Crear

Son cuatro toques. Fíjate en que **no le hemos pedido saber** si era la cisterna
o el fluxor, y en que el parte se ha repartido solo a Servicios Técnicos.

### 2. Cerrarlo como lo cerraría un técnico

Entra como **luis**. En el listado, cada tarjeta tiene una flecha a la derecha.

- Tócala sin abrir el parte
- «En Curso» o «Resuelta», escribe una línea, elige el tiempo
- Listo

Dos toques por parte. Es lo que más tiempo ahorra en una ronda.

### 3. Sin cobertura

Con cualquier cuenta, **pon el móvil en modo avión** (o desconecta el wifi).

- Debe salir una franja: «Sin conexión · puedes seguir trabajando»
- Crea un parte: se puede, con tipo y ubicación del catálogo guardado
- La franja pasa a decir «1 en cola»
- **Cierra la aplicación del todo y vuelve a abrirla**: debe arrancar igual
- Reactiva los datos: la franja desaparece y la cola se vacía sola

> Por HTTP en una IP local, el paso de «cerrar y volver a abrir» no funciona:
> el navegador no registra el trabajador de servicio fuera de HTTPS. Con el APK
> sí. Está explicado en `docs/INSTALACION.md`.

### 4. Dos personas a la vez sobre el mismo parte

Esto es lo que más cuesta hacer bien y conviene que lo veas:

- Como **luis**, en modo avión, pasa un parte a «En Curso» con una nota
- Desde otro navegador, como **ana**, ciérralo
- Vuelve a darle red a luis

Debe sonar un aviso distinto y aparecer «Revisar cambios», con **las dos
versiones y tu nota intacta**. Prueba las dos salidas: quedarte con el suyo, o
imponer el tuyo.

### 5. La habitación 1008

Toca el icono de ubicación en la cabecera y busca **1008**.

Verás el historial del sitio y, debajo, **los aparatos con número de serie**:

> **Tv — 5 en total**
> 1 en servicio · 1 por obsolescencia, 3 por rotura

Toca cualquiera para ver su historia completa.

### 6. Informes

Entra como **admin** → Ajustes → Informes.

Prueba a responder: «cuántas averías por familia», «cuánto se tarda en cerrar
según la prioridad», «qué habitaciones dan más guerra». Se guardan con nombre.

---

## Dónde está el borrado

**Gestión de partes** — al final del inicio y en Ajustes → Administración.
Busca, marca varios y elimínalos. Es la pantalla pensada para limpiar.

### Los otros caminos

**Un parte** — ábrelo y toca los tres puntos de la cabecera. Ahí están
«Mandar a la papelera» (recuperable) y «Eliminar definitivamente» (no).

**Varios a la vez** — en Partes, el botón del visto en la cabecera entra en
modo selección.

**Una persona** — Equipo → tócala → despliega «Borrar esta persona
definitivamente». Sus partes no se pierden: pasan a tu nombre y queda anotado
en el registro de seguridad.

## Lo que conviene revisar con tu criterio

Estas tres cosas las he decidido yo leyendo nombres, y tú sabes cómo trabajáis:

### Las familias

**Administración → Familias**. He agrupado los 313 elementos en 16 áreas y les
he puesto una clase: avería, petición o tarea. Cuatro quedaron en «Otros».

Mira sobre todo si «Peticiones de cliente» y «Limpieza y repasos» contienen lo
que vosotros consideráis peticiones y tareas. De eso depende que los informes
no mezclen camas extra con averías.

Se pueden mover varios tipos de una vez.

### Los números de serie

38 de los 313 están marcados como «lleva número de serie». Revisa si sobra o
falta alguno — por ejemplo, si vuestras cafeteras de habitación se identifican
o se cambian sin más.

### Las instrucciones

Los cinco textos de ejemplo son **plausibles, no verificados**. Sirven para ver
la forma. Antes de usarlos que los revise quien conoce vuestras instalaciones,
sobre todo los avisos de seguridad.

---

## Lo que no está probado en un aparato real

**El APK.** El proyecto Android está generado y configurado, pero no se ha
compilado: hace falta el SDK en tu máquina. `./compilar-apk.sh` y está en
`docs/APK.md`.

Cuando lo compiles, lo primero que conviene comprobar: que la dirección del
servidor está puesta en `www/index.html` y que `https://localhost` está en
`cors_origins`. Sin eso arranca pero no habla con el servidor.

---

## Una prueba que recorre la aplicación sola

```bash
python3 pruebas/rastreo.py                    # como administrador
python3 pruebas/rastreo.py david.sstt@hotelbenalma.com    # como técnico
```

Recorre la aplicación **tocando lo que hay**, como una persona, en vez de
comprobar funciones sueltas. Dice a qué pantallas no se llega desde ningún
sitio, qué botones no hacen nada y qué errores salen por el camino.

La escribí después de varias rondas en las que tú encontrabas cosas que mis
pruebas no. Casi siempre eran caminos, no funciones: la pantalla estaba, pero
no había forma de llegar, o llegaba abierta por el final.

Resultado actual: **24 de 25 pantallas se alcanzan tocando**. La que falta es
la de conflictos, y es correcto — sólo aparece cuando hay uno.

## Cómo contarme lo que falle

Con esto me sobra para localizarlo:

- **Con qué cuenta** estabas
- **Qué pantalla** (la dirección después de la almohadilla vale)
- **Qué esperabas y qué pasó**
- Si sale algo en rojo, el texto tal cual

Si es algo visual, una captura. Si la aplicación se queda en blanco,
`www/diagnostico.html` dice por qué.
