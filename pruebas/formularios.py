#!/usr/bin/env python3
"""
Que ningún formulario se quede muerto.

EL FALLO QUE ESTO EVITA
-----------------------
Una ventana («hoja») se construye en dos tiempos: primero el HTML, con sus
campos y sus botones, y después `alAbrir`, que es donde se enganchan los
manejadores del pie —«Crear», «Guardar», «Borrar»—.

Si algo revienta a mitad de `alAbrir`, lo de arriba ya quedó enganchado y
lo de abajo no. El resultado es un formulario con una pinta impecable
cuyo botón «Crear» NO HACE NADA: no manda, no avisa, no se queja. La
persona rellena el formulario, pulsa, y se queda mirando la pantalla.

Pasó de verdad en el alta de personas: una variable que no existía en esa
función. El recorrido de pantallas no lo vio porque las pantallas se
pintaban bien; lo que estaba roto era lo que pasa DESPUÉS de pulsar.

LO QUE COMPRUEBA
----------------
Para cada formulario que encuentra:

  1. Que abrirlo no lanza ningún error.
  2. Que TODOS los botones de acción del pie tienen manejador. Ésta es la
     comprobación que caza el fallo de arriba, y no hace falta rellenar
     nada para hacerla.
  3. Que el formulario trae campos (una ventana de edición sin campos es
     otra forma de haberse quedado a medias).

Y además, para los formularios de los que se puede responder «¿funciona
de verdad?», los envía: con el formulario vacío —tiene que quejarse, no
callarse— y relleno —tiene que mandar la petición—.

   python3 pruebas/formularios.py            todo
   python3 pruebas/formularios.py --ver      con el navegador a la vista
"""

import os
import asyncio
import json
import random
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

# La de la instalación que se prueba. Estaba FIJA en la de SQLite: en la
# tanda de MySQL, el navegador probaba SQLite y el barrido final limpiaba
# MySQL, y en SQLite se quedaba una persona de prueba («prb…») sin
# departamento. Lo encontró el panel de integridad.
BASE = os.environ.get('HURAKANTICK_BASE', 'http://127.0.0.1/hurakantick/www/index.html')
API = os.environ.get(
    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
# Pantalla, con quién se entra, y cómo se abre cada formulario de esa
# pantalla. «abridor» es lo que hay que pulsar; None significa la primera
# fila de la lista.
PANTALLAS = [
    ('/equipo',          'admin', ['[data-nuevo]', 'fila:[data-u]']),
    ('/departamentos',   'admin', ['[data-nuevo]', 'fila:[data-d]']),
    ('/hoteles',         'admin', ['[data-nuevo]', '[data-editar]', '[data-copiar]',
                                   '[data-restablecer]']),
    ('/mantenimiento',   'admin', ['[data-nuevo]', 'fila:[data-m]']),
    ('/instrucciones',   'admin', ['[data-crear="texto"]', '[data-crear="bloques"]',
                                   'fila:[data-i]']),
    ('/frases',          'admin', ['[data-nueva]', '[data-nueva-cat]', 'fila:[data-frase]']),
    ('/unidades',        'admin', ['[data-nueva]']),
    ('/informes',        'admin', ['[data-anadir]', '[data-guardados]', '[data-elige]']),
    ('/personal',        'admin', ['[data-plantilla]']),
    ('/errores',         'admin', ['fila:[data-error]']),
    ('/seguridad',       'admin', []),
    ('/papelera',        'admin', ['fila:[data-t]']),
    ('/gestion-partes',  'admin', ['fila:[data-t]']),
    ('/avisos-ajustes',  'admin', ['pantalla']),
    ('/apariencia',      'admin', ['pantalla']),
    ('/admin',           'admin', []),
    ('/nuevo',           'ana',   ['pantalla']),
    ('/cuenta',          'ana',   []),
    ('/lista',           'admin', ['fila:[data-ticket]', '[data-seleccionar]', '[data-exportar]']),
    ('/mios',            'luis',  ['fila:[data-ticket]']),
    ('/mensajes',        'luis',  []),
    ('/avisos',          'luis',  []),
    ('/conflictos',      'luis',  []),
    ('/panel',           'pilar', []),
]


async def principal(ver: bool) -> int:
    from playwright.async_api import async_playwright

    fallos = []
    nohay = []
    abiertas = []
    revisados = 0

    async with async_playwright() as p:
        nav = await p.chromium.launch(headless=not ver)
        ctx = await nav.new_context(viewport={'width': 390, 'height': 844},
                                    locale='es-ES', is_mobile=True, has_touch=True)
        pag = await ctx.new_page()
        pag.set_default_timeout(20000)

        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:160]))

        quien_hay = None
        peticiones = []
        codigos = []
        pag.on('request', lambda r: peticiones.append(r.method) if '/api/' in r.url else None)
        pag.on('response', lambda r: codigos.append(r.status)
               if '/api/' in r.url and r.request.method in ('POST', 'PUT', 'PATCH') else None)

        async def entrar(quien):
            nonlocal quien_hay
            if quien_hay == quien:
                return
            await pag.goto(BASE)
            await pag.wait_for_timeout(1200)
            await pag.evaluate("()=>{try{localStorage.clear()}catch{}}")
            await pag.goto(BASE)
            await pag.wait_for_timeout(1500)
            if await pag.locator('#correo').count() == 0:
                await pag.evaluate("()=>{const b=document.querySelector('[data-salir]');b&&b.click();}")
                await pag.wait_for_timeout(2000)
            await pag.fill('#correo', entorno.usuario(quien))
            await pag.fill('#password', CLAVE)
            await pag.click('#b-entrar')
            await pag.wait_for_timeout(6500)
            quien_hay = quien

        async def mirar_la_hoja(donde, como):
            """Las tres comprobaciones sobre la ventana que esté abierta."""
            nonlocal revisados
            datos = await pag.evaluate("""() => {
              const h = [...document.querySelectorAll('.hoja')].pop();
              if (!h) return null;
              const pie = h.querySelector('.hoja__pie');
              const botones = pie ? [...pie.querySelectorAll('button')] : [];
              return {
                titulo: (h.querySelector('.hoja__titulo,h2,h3')||{}).innerText || '',
                // Media aplicación se ajusta con botones de opción y no
                // con un <input>: la barra de abajo se elige pulsando
                // las cuatro pestañas. Contar sólo campos daba la alarma
                // en ventanas que están perfectas.
                campos: h.querySelectorAll('input,select,textarea,.opcion,[role=switch],.interruptor,[data-bloque],[data-p]').length,
                roto: !!h.querySelector('[data-hoja-rota]'),
                botones: botones.map(b => ({
                  texto: (b.innerText||'').trim().slice(0, 24),
                  cierra: b.dataset.no !== undefined || b.dataset.cerrar !== undefined,
                  manejador: typeof b.onclick === 'function',
                  apagado: b.disabled,
                })),
              };
            }""")
            if datos is None:
                return False
            revisados += 1
            abiertas.append(datos['titulo'][:46] or '(sin título)')
            nombre = f'{donde} · {como} · «{datos["titulo"][:26] or "sin título"}»'

            if datos['roto']:
                fallos.append(f'{nombre}: la ventana dice que no se pudo preparar.')

            muertos = [b['texto'] for b in datos['botones']
                       if not b['cierra'] and not b['manejador'] and not b['apagado']]
            if muertos:
                fallos.append(f'{nombre}: botón sin manejador -> {", ".join(muertos)}. '
                              'Se pulsa y no pasa nada.')

            # Una ventana de sólo lectura (el detalle de un fallo, una
            # instrucción) no tiene campos y está bien así. Sólo preocupa
            # cuando hay un botón que dice que va a guardar algo.
            guarda = [b['texto'] for b in datos['botones']
                      if not b['cierra'] and any(x in b['texto'].lower() for x in
                         ('guardar', 'crear', 'añadir', 'aplicar', 'publicar'))]
            if datos['campos'] == 0 and guarda:
                fallos.append(f'{nombre}: dice «{guarda[0]}» pero no tiene ni un campo.')

            return True

        async def cerrar_todo():
            for _ in range(4):
                if await pag.locator('.hoja').count() == 0:
                    break
                await pag.evaluate("""()=>{const h=[...document.querySelectorAll('.hoja')].pop();
                    const b=h&&(h.querySelector('[data-no]')||h.querySelector('[data-cerrar]'));
                    if(b) b.click(); else document.querySelector('.velo')?.click();}""")
                await pag.wait_for_timeout(700)
            if await pag.locator('.hoja').count():
                await pag.keyboard.press('Escape')
                await pag.wait_for_timeout(500)

        for ruta, quien, abridores in PANTALLAS:
            await entrar(quien)
            errores_js.clear()
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2400)

            if errores_js:
                fallos.append(f'{ruta}: al abrir la pantalla -> {errores_js[0]}')

            for ab in abridores:
                errores_js.clear()
                if ab == 'pantalla':
                    # El formulario ES la pantalla: no hay ventana que abrir.
                    # Cuentan también los botones de opción y los
                    # interruptores: media aplicación se ajusta con ésos y
                    # no con un <input>, y contarlos mal daba la alarma en
                    # pantallas que están perfectas.
                    n = await pag.eval_on_selector_all(
                        'input,select,textarea,.opcion,[role=switch],.interruptor', 'e=>e.length')
                    bot = await pag.evaluate("""()=>[...document.querySelectorAll('button')]
                        .filter(b=>/crear|guardar|enviar|mandar/i.test(b.innerText))
                        .map(b=>({t:b.innerText.trim().slice(0,20),
                                  m: typeof b.onclick==='function' || b.form!==null || b.type==='submit'}))""")
                    revisados += 1
                    muertos = [b['t'] for b in bot if not b['m']]
                    if n == 0:
                        fallos.append(f'{ruta}: la pantalla no tiene campos.')
                    if muertos:
                        fallos.append(f'{ruta}: botón sin manejador -> {", ".join(muertos)}.')
                    continue

                sel = ab[5:] if ab.startswith('fila:') else ab
                if await pag.locator(sel).count() == 0:
                    # La cabecera se pinta aparte y a veces llega tarde:
                    # antes de decir que un botón no está, se le espera.
                    await pag.wait_for_timeout(2000)
                if await pag.locator(sel).count() == 0:
                    # Saltárselo en silencio sería lo peor que puede hacer
                    # esta prueba: daría «todo bien» sin haber mirado nada.
                    donde = await pag.evaluate('location.hash')
                    nohay.append(f'{ruta} · {ab} (la pantalla estaba en {donde})')
                    continue
                try:
                    await pag.locator(sel).first.click()
                except Exception as e:
                    fallos.append(f'{ruta} · {ab}: no se pudo pulsar ({str(e)[:50]}).')
                    continue
                await pag.wait_for_timeout(1700)

                if errores_js:
                    fallos.append(f'{ruta} · {ab}: al abrir -> {errores_js[0]}')

                await mirar_la_hoja(ruta, ab)
                await cerrar_todo()
                # Una fila no siempre abre una ventana: muchas veces lleva a
                # otra pantalla. Si no se vuelve, los botones que quedan por
                # revisar se buscan donde no están y se dan por ausentes.
                if await pag.evaluate('location.hash') != '#' + ruta:
                    await pag.goto(BASE + '#' + ruta)
                    await pag.wait_for_timeout(1700)

        # ---- «Mi cuenta»: doce ventanas detrás de una lista de filas ----
        # Aquí viven los datos propios de cada persona y el cambio de
        # contraseña. Es lo que más usa la gente de a pie y lo que menos
        # mira un administrador, así que un formulario muerto aquí puede
        # tardar meses en salir a la luz.
        CUENTA = ['perfil', 'correo', 'password', 'tema', 'menu', 'sesiones',
                  'cola', 'servidor', 'diagnostico']
        for quien in ('luis', 'admin'):
            await entrar(quien)
            for accion in CUENTA:
                await pag.goto(BASE + '#/cuenta')
                await pag.wait_for_timeout(2000)
                sel = f'[data-a="{accion}"]'
                if await pag.locator(sel).count() == 0:
                    continue          # esa fila no le toca a este papel
                errores_js.clear()
                await pag.locator(sel).first.click()
                await pag.wait_for_timeout(1700)
                if errores_js:
                    fallos.append(f'/cuenta · {accion} ({quien}): al abrir -> {errores_js[0]}')
                await mirar_la_hoja(f'/cuenta ({quien})', accion)
                await cerrar_todo()

        # ---- Administración: once ventanas de configuración -------------
        await entrar('admin')
        ADMIN = ['[data-probar-teams]', '[data-obligatorios]', '[data-rango]',
                 '[data-cod]', '[data-nueva]', '[data-nuevo]', '[data-crear]',
                 '[data-rapidos]', '[data-ver-tipos]', '[data-renovar]',
                 '[data-crear-fotos]']
        for sec in ('/admin', '/admin/general', '/admin/partes', '/admin/avisos',
                    '/admin/catalogos', '/admin/seguridad'):
            await pag.goto(BASE + '#' + sec)
            await pag.wait_for_timeout(2300)
            for ab in ADMIN:
                if await pag.locator(ab).count() == 0:
                    continue
                errores_js.clear()
                try:
                    await pag.locator(ab).first.click()
                except Exception:
                    continue
                await pag.wait_for_timeout(1700)
                if errores_js:
                    fallos.append(f'{sec} · {ab}: al abrir -> {errores_js[0]}')
                await mirar_la_hoja(sec, ab)
                await cerrar_todo()
                if await pag.evaluate('location.hash') != '#' + sec:
                    await pag.goto(BASE + '#' + sec)
                    await pag.wait_for_timeout(2000)

        # ---- El resto de ventanas sueltas -------------------------------
        await entrar('admin')
        SUELTAS = [
            ('/papelera',     ['[data-e]', 'fila:[data-t]']),
            ('/unidades',     ['[data-nueva]']),
            ('/personal',     ['[data-plantilla]', '[data-probar]', '[data-importar]',
                               '?[data-exportar]', '?fila:[data-persona]']),
            ('/iconografia',  ['?[data-familia]', '?[data-icono]', '?[data-estado]']),
            ('/avisos-ajustes', ['?[data-probar]']),
            ('/gestion-partes', ['?[data-estado]', '?[data-a]']),
        ]
        for ruta, abridores in SUELTAS:
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2300)
            for ab in abridores:
                opcional = ab.startswith('?')
                ab2 = ab[1:] if opcional else ab
                sel = ab2[5:] if ab2.startswith('fila:') else ab2
                if await pag.locator(sel).count() == 0:
                    if not opcional:
                        nohay.append(f'{ruta} · {ab2}')
                    continue
                errores_js.clear()
                try:
                    await pag.locator(sel).first.click()
                except Exception:
                    continue
                await pag.wait_for_timeout(1700)
                if errores_js:
                    fallos.append(f'{ruta} · {ab2}: al abrir -> {errores_js[0]}')
                await mirar_la_hoja(ruta, ab2)
                await cerrar_todo()
                if await pag.evaluate('location.hash') != '#' + ruta:
                    await pag.goto(BASE + '#' + ruta)
                    await pag.wait_for_timeout(2000)

        # ---- La pantalla de entrar, que no tiene sesión -----------------
        # «Contraseña olvidada» la usa quien NO puede entrar: si está rota,
        # la persona no tiene forma de avisar de que está rota.
        await pag.goto(BASE)
        await pag.wait_for_timeout(1200)
        await pag.evaluate("()=>{try{localStorage.clear()}catch{}}")
        await pag.goto(BASE)
        await pag.wait_for_timeout(2000)
        quien_hay = None
        # «Contraseña olvidada» se abre desde el enlace de la pantalla de
        # entrar. Quien la necesita es quien NO puede entrar: si está rota,
        # esa persona no tiene ni manera de avisar de que está rota.
        for ab in ('#b-olvide', '#b-servidor', '[data-servidor]'):
            if await pag.locator(ab).count() == 0:
                continue
            errores_js.clear()
            await pag.locator(ab).first.click()
            await pag.wait_for_timeout(1800)
            if errores_js:
                fallos.append(f'/entrar · {ab}: al abrir -> {errores_js[0]}')
            await mirar_la_hoja('/entrar', ab)
            await cerrar_todo()

        for ab in ('[data-pedir]', '[data-usar]', '[data-probar]'):
            if await pag.locator(ab).count() == 0:
                continue
            errores_js.clear()
            await pag.locator(ab).first.click()
            await pag.wait_for_timeout(2000)
            if errores_js:
                fallos.append(f'/entrar · {ab}: al abrir -> {errores_js[0]}')
            if not await mirar_la_hoja('/entrar', ab):
                # No abre ventana: será un formulario de la propia pantalla.
                bot = await pag.evaluate(
                    "()=>[...document.querySelectorAll('button')].filter(b=>!b.disabled)"
                    ".map(b=>typeof b.onclick==='function'||b.type==='submit').filter(Boolean).length")
                revisados += 1
                if bot == 0:
                    fallos.append('/entrar: ningún botón responde.')
            await cerrar_todo()

        # ---- Los selectores del alta de un parte ------------------------
        # «¿Qué falla?» y «Ubicación» son dos ventanas por las que pasa
        # TODO el mundo cada vez que abre un parte.
        await entrar('ana')
        await pag.goto(BASE + '#/nuevo')
        await pag.wait_for_timeout(2300)
        for ab in ('#b-elemento', '#b-zona', '#b-grupo', '#b-persona'):
            if await pag.locator(ab).count() == 0:
                nohay.append(f'/nuevo · {ab}')
                continue
            errores_js.clear()
            await pag.locator(ab).first.click()
            await pag.wait_for_timeout(1700)
            if errores_js:
                fallos.append(f'/nuevo · {ab}: al abrir -> {errores_js[0]}')
            await mirar_la_hoja('/nuevo', ab)
            await cerrar_todo()

        # ---- Las pantallas que llevan un número en la dirección ---------
        # La ficha de un parte es la pantalla más usada de la aplicación y
        # la que más formularios tiene dentro (nota, foto, estado, asignar).
        # Como su dirección lleva el número del parte, no cabía en la tabla
        # de arriba: hay que preguntar primero cuál existe.
        await entrar('admin')
        await pag.goto(BASE + '#/lista')
        await pag.wait_for_timeout(2400)
        idt = await pag.evaluate("()=>document.querySelector('[data-ticket]')?.dataset.ticket||''")
        conNumero = []
        if idt:
            conNumero.append((f'/ticket/{idt}', [
                '[data-accion]', '[data-editar]', '[data-mas]', '[data-compartir]',
                # Con «?» delante: sólo salen si ese parte los tiene (notas,
                # campos propios del tipo). No aparecer no es un fallo.
                '?[data-editar-campos]', '?[data-ver-notas]', '?[data-chat]']))
        conNumero.append(('/admin/general', ['pantalla']))
        conNumero.append(('/admin/partes', ['pantalla']))

        for ruta, abridores in conNumero:
            errores_js.clear()
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2400)
            if errores_js:
                fallos.append(f'{ruta}: al abrir la pantalla -> {errores_js[0]}')
            for ab in abridores:
                errores_js.clear()
                if ab == 'pantalla':
                    n = await pag.eval_on_selector_all(
                        'input,select,textarea,.opcion,[role=switch],.interruptor', 'e=>e.length')
                    revisados += 1
                    if n == 0:
                        fallos.append(f'{ruta}: la pantalla no tiene con qué ajustar nada.')
                    continue
                opcional = ab.startswith('?')
                ab = ab[1:] if opcional else ab
                if await pag.locator(ab).count() == 0:
                    if not opcional:
                        nohay.append(f'{ruta} · {ab}')
                    continue
                try:
                    await pag.locator(ab).first.click()
                except Exception:
                    continue
                await pag.wait_for_timeout(2000)
                if errores_js:
                    fallos.append(f'{ruta} · {ab}: al abrir -> {errores_js[0]}')
                await mirar_la_hoja(ruta, ab)
                await cerrar_todo()
                if await pag.evaluate('location.hash') != '#' + ruta:
                    await pag.goto(BASE + '#' + ruta)
                    await pag.wait_for_timeout(1700)

        # ---- Recorrido a ciegas: pulsar TODO lo que se pueda pulsar -----
        #
        # La lista de arriba nombra los formularios que conozco. El problema
        # de una lista es justo ése: sólo cubre lo que a uno se le ocurre
        # poner en ella, y el alta de personas llevaba semanas rota en una
        # pantalla que yo daba por revisada.
        #
        # Esto no sabe nada: entra en cada pantalla, pulsa cada botón y cada
        # fila, mira si se ha abierto una ventana y la revisa igual que las
        # demás. Es lento y encuentra cosas que una lista no encuentra.
        #
        # Lo único que evita son los botones que destruyen o que cierran la
        # sesión: abrir su ventana de confirmación es seguro —se cierra sin
        # confirmar—, pero los que actúan de golpe se dejan en paz.
        # «desactivar» faltaba, y la pasada a ciegas desactivó la
        # «Revisión de extintores y BIE» de los datos de demostración: la
        # obligatoria, la que hay que poder demostrar que se hace. Una
        # revisión desactivada deja de generar partes y no avisa de nada.
        # Es la tercera vez que una prueba destructiva muerde datos
        # reales, así que la lista va por lo que HACE el botón, no por
        # cómo se llame la pantalla.
        PELIGROSOS = ('salir', 'cerrar sesión', 'desconectar', 'purgar',
                      'tirar', 'vaciar', 'restablecer de fábrica',
                      'desactivar', 'retirar', 'dar de baja', 'archivar',
                      'bloquear')
        A_CIEGAS = [
            # Las secciones de administración son las de verdad, sacadas
            # del menú: antes probaba «/admin/partes» y «/admin/catalogos»,
            # que NO EXISTEN —el router manda lo desconocido al inicio sin
            # decir nada—, así que creía estar revisando siete pantallas y
            # estaba mirando siete veces la misma.
            ('admin',  ['/admin', '/admin/ajustes', '/admin/elementos',
                        '/admin/familias', '/admin/zonas', '/admin/campos',
                        '/admin/atajos', '/admin/claves', '/admin/vista',
                        '/admin/seguridad', '/admin/copias',
                        '/informes', '/unidades', '/papelera', '/cuenta',
                        '/avisos-ajustes', '/ubicacion/1',
                        # El parte 1 es de un tipo CON campos propios: es el
                        # único camino a la ventana «Anotar los datos».
                        '/ticket/1', '/equipo', '/panel', '/lista',
                        # Las acciones rápidas viven aquí dentro, no en una
                        # pantalla propia.
                        '/familia/1']),
            ('luis',   ['/cuenta', '/mios', '/conflictos']),
        ]
        for quien, rutas in A_CIEGAS:
            await entrar(quien)
            for ruta in rutas:
                await pag.goto(BASE + '#' + ruta)
                await pag.wait_for_timeout(2300)

                # Pantallas que no enseñan nada hasta que se busca algo.
                # Sin esto, «no había nada que pulsar» se confundía con
                # «está todo revisado».
                if ruta == '/unidades':
                    caja = pag.locator('input[type=search], #u-busca, .buscador input').first
                    if await caja.count():
                        await caja.fill('SNAA')
                        await pag.wait_for_timeout(2000)
                        await pag.keyboard.press('Enter')
                        await pag.wait_for_timeout(2500)

                cuantos = await pag.evaluate(
                    "()=>document.querySelectorAll('#contenido button, #contenido .fila,"
                    " header button, .cabecera button').length")
                for i in range(min(cuantos, 26)):
                    # Se vuelve a la pantalla en cada vuelta: pulsar algo
                    # puede haber navegado a otro sitio.
                    if await pag.evaluate('location.hash') != '#' + ruta:
                        await pag.goto(BASE + '#' + ruta)
                        await pag.wait_for_timeout(2000)
                    info = await pag.evaluate("""(i)=>{
                      const b=[...document.querySelectorAll('#contenido button, #contenido .fila,'
                        +' header button, .cabecera button')][i];
                      if(!b) return null;
                      return {t:(b.innerText||b.getAttribute('aria-label')||'').trim().slice(0,30)};
                    }""", i)
                    if info is None:
                        break
                    if any(x in info['t'].lower() for x in PELIGROSOS):
                        continue
                    errores_js.clear()
                    try:
                        await pag.evaluate("""(i)=>{
                          const b=[...document.querySelectorAll('#contenido button, #contenido .fila,'
                            +' header button, .cabecera button')][i];
                          b && b.click();
                        }""", i)
                    except Exception:
                        continue
                    await pag.wait_for_timeout(1300)
                    if errores_js:
                        fallos.append(f'{ruta} · «{info["t"]}»: al pulsar -> {errores_js[0]}')
                    abrio = await mirar_la_hoja(ruta, f'«{info["t"]}»')

                    # SEGUNDO NIVEL: muchas ventanas se abren DESDE otra
                    # ventana —el «Valor» de un filtro de informe, «Nueva
                    # ubicación» dentro del selector de sitio, el código de
                    # un solo uso dentro de la ficha de una persona—. Si
                    # sólo se mira el primer nivel, ésas no las ve nadie.
                    if abrio:
                        dentro = await pag.evaluate("""()=>{
                          const h=[...document.querySelectorAll('.hoja')].pop();
                          if(!h) return 0;
                          return h.querySelectorAll('.hoja__cuerpo button, .hoja__cuerpo .fila').length;
                        }""")
                        for j in range(min(dentro, 10)):
                            sub = await pag.evaluate("""(j)=>{
                              const h=[...document.querySelectorAll('.hoja')].pop();
                              if(!h) return null;
                              const b=[...h.querySelectorAll('.hoja__cuerpo button, .hoja__cuerpo .fila')][j];
                              if(!b) return null;
                              return {t:(b.innerText||b.getAttribute('aria-label')||'').trim().slice(0,28)};
                            }""", j)
                            if sub is None:
                                break
                            if any(x in sub['t'].lower() for x in PELIGROSOS + ('borrar', 'eliminar')):
                                continue
                            antes = await pag.locator('.hoja').count()
                            errores_js.clear()
                            try:
                                await pag.evaluate("""(j)=>{
                                  const h=[...document.querySelectorAll('.hoja')].pop();
                                  const b=[...h.querySelectorAll('.hoja__cuerpo button, .hoja__cuerpo .fila')][j];
                                  b && b.click();
                                }""", j)
                            except Exception:
                                continue
                            await pag.wait_for_timeout(1100)
                            if errores_js:
                                fallos.append(f'{ruta} · «{info["t"]}» › «{sub["t"]}»: '
                                              f'al abrir -> {errores_js[0]}')
                            if await pag.locator('.hoja').count() > antes:
                                await mirar_la_hoja(ruta, f'«{info["t"]}» › «{sub["t"]}»')
                                # cerrar sólo la de encima, para seguir dentro
                                await pag.evaluate("""()=>{const h=[...document.querySelectorAll('.hoja')].pop();
                                    const b=h&&(h.querySelector('[data-no]')||h.querySelector('[data-cerrar]'));
                                    if(b) b.click();}""")
                                await pag.wait_for_timeout(800)
                            if await pag.locator('.hoja').count() == 0:
                                break      # se cerró todo: no hay dónde seguir

                    await cerrar_todo()

        # ---- Caminos de varios pasos -----------------------------------
        #
        # Estas ventanas no se alcanzan pulsando a lo bruto: hay que hacer
        # tres o cuatro cosas en orden. Buscar un número de serie para que
        # aparezca el aparato; entrar en la ficha de una persona para pedir
        # su código; abrir el selector de sitio y bajar hasta el botón de
        # crear uno nuevo. Son las últimas que quedaban sin mirar, y van
        # escritas una a una a propósito: lo genérico ya no llegaba.
        CAMINOS = [
            ('Ficha del aparato y «Cambiar»', 'admin', '/unidades', [
                ('escribir', 'input[type=search], .buscador input', 'SNAA'),
                ('esperar', 2500, None),
                ('pulsar', '[data-unidad]', None),
                ('dentro', '[data-cambiar]', None),
            ]),
            ('Anotar los datos propios del parte', 'admin', '/ticket/1', [
                ('pulsar', '[data-editar-campos]', None),
            ]),
            # Necesita que alguien haya pedido recuperar su contraseña.
            ('Código de un solo uso', 'admin', '/admin/claves', [
                ('pulsar', '[data-cod]', None),
            ]),
            ('Campos obligatorios al crear partes', 'admin', '/admin/ajustes', [
                ('pulsar', '[data-obligatorios]', None),
            ]),
            ('Habitaciones por rango', 'admin', '/admin/zonas', [
                ('pulsar', '[data-rango]', None),
            ]),
            ('Dar de alta una ubicación nueva', 'ana', '/nuevo', [
                ('pulsar', '#b-zona', None),
                ('dentro', '[data-nueva]', None),
            ]),
            # El informe dibuja el resultado en la propia pantalla, no en
            # una ventana: aquí sólo se comprueba que el botón responde y no
            # revienta. La ventana con la lista de partes cuelga de pulsar
            # luego una de las barras del resultado.
            ('Ver el resultado de un informe', 'admin', '/informes', [
                ('pulsar', '[data-ver]', None),
                ('sin-ventana', None, None),
            ]),
        ]

        for nombre, quien, ruta, pasos in CAMINOS:
            await entrar(quien)
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2600)
            errores_js.clear()
            llego = True
            sinVentana = any(t == 'sin-ventana' for t, _, _ in pasos)
            for tipo, que, valor in pasos:
                if tipo == 'sin-ventana':
                    continue
                if tipo == 'esperar':
                    await pag.wait_for_timeout(que)
                    continue
                if tipo == 'escribir':
                    caja = pag.locator(que).first
                    if await caja.count() == 0:
                        nohay.append(f'{nombre}: no encuentro dónde escribir ({que})')
                        llego = False
                        break
                    await caja.fill(valor)
                    await pag.keyboard.press('Enter')
                    await pag.wait_for_timeout(2200)
                    continue
                # pulsar / dentro: lo segundo busca dentro de la ventana abierta
                ambito = pag.locator('.hoja').last.locator(que) if tipo == 'dentro' \
                    else pag.locator(que)
                if await ambito.count() == 0:
                    nohay.append(f'{nombre}: no aparece {que}')
                    llego = False
                    break
                try:
                    await ambito.first.click()
                except Exception as e:
                    fallos.append(f'{nombre}: no se pudo pulsar {que} ({str(e)[:50]})')
                    llego = False
                    break
                await pag.wait_for_timeout(2000)

            if errores_js:
                fallos.append(f'{nombre}: al abrir -> {errores_js[0]}')
            if llego and not sinVentana:
                if not await mirar_la_hoja(ruta, nombre):
                    nohay.append(f'{nombre}: no llegó a abrirse ninguna ventana')
            elif llego:
                revisados += 1
            await cerrar_todo()

        # ---- Enviar de verdad, uno por uno ------------------------------
        # Que el botón tenga manejador no quiere decir que haga su trabajo.
        # Estos se rellenan y se envían, y se mira que el servidor conteste.
        #
        # Con el formulario VACÍO la regla es: o se queja, o manda. Lo que
        # no vale es quedarse callado, que es lo que vio la persona que usa
        # esto y lo que hay que impedir que vuelva a pasar.
        ENVIOS = [
            ('Crear departamento', 'admin', '/departamentos', '[data-nuevo]',
             {'.hoja input': 'Pruebas {n}'}, '[data-si]'),
            ('Crear frase', 'admin', '/frases', '[data-nueva]',
             {'.hoja textarea': 'Frase de prueba {n}'}, '[data-si]'),
            ('Crear revisión', 'admin', '/mantenimiento', '[data-nuevo]',
             {'#m-nombre': 'Revisión {n}'}, '[data-si]'),
            ('Crear instrucción', 'admin', '/instrucciones', '[data-crear="texto"]',
             {'#n-titulo': 'Instrucción {n}', '#n-contenido': '1. Un paso'}, '[data-si]'),
            ('Crear hotel', 'admin', '/hoteles', '[data-nuevo]',
             {'.hoja input': 'Hotel {n}'}, '[data-si]'),
        ]

        for nombre, quien, ruta, abridor, campos, enviar in ENVIOS:
            await entrar(quien)
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2300)
            if await pag.locator(abridor).count() == 0:
                nohay.append(f'{nombre}: no aparece {abridor}')
                continue
            await pag.locator(abridor).first.click()
            await pag.wait_for_timeout(1700)
            if await pag.locator('.hoja').count() == 0:
                fallos.append(f'{nombre}: el botón no abre nada.')
                continue

            # Si la ventana vino rota, el botón está apagado a propósito.
            # Hay que decirlo con claridad, no quedarse esperando a que un
            # botón muerto se deje pulsar.
            estado = await pag.evaluate(
                "(s)=>{const b=document.querySelector('.hoja '+s);"
                " return !b ? 'no está' : (b.disabled ? 'apagado' : 'bien');}", enviar)
            if estado != 'bien':
                fallos.append(f'{nombre}: el botón de guardar está {estado}. '
                              'La ventana no se preparó bien.')
                await cerrar_todo()
                continue

            # 1) vacío
            peticiones.clear()
            await pag.evaluate("()=>{document.querySelectorAll('.hoja input,.hoja textarea')"
                               ".forEach(e=>{ if(e.type!=='checkbox') e.value=''; });}")
            if await pag.locator(f'.hoja {enviar}').count():
                await pag.locator(f'.hoja {enviar}').first.click()
                await pag.wait_for_timeout(1500)
            dijo = await pag.evaluate(
                "()=>[...document.querySelectorAll('#mensajes .mensaje, .hoja .aviso--error,"
                " .campo__error')].map(e=>e.innerText.trim()).join(' / ')")
            mando = [x for x in peticiones if x in ('POST', 'PUT', 'PATCH')]
            if not mando and not dijo.strip():
                fallos.append(f'{nombre}: con el formulario vacío ni manda ni avisa. '
                              'Se pulsa y no pasa nada.')

            # 2) relleno, SIEMPRE desde un formulario recién abierto.
            # Reaprovechar el del paso anterior dejaba vaciados campos que
            # venían con un valor puesto —la fecha de la próxima revisión—
            # y entonces el formulario se negaba con razón, pero la prueba
            # lo contaba como que «no manda». Una prueba que acusa en falso
            # se acaba ignorando, y entonces ya no sirve para nada.
            await cerrar_todo()
            await pag.goto(BASE + '#' + ruta)
            await pag.wait_for_timeout(2000)
            await pag.locator(abridor).first.click()
            await pag.wait_for_timeout(1700)
            n_ = random.randint(10000, 99999)
            for sel, val in campos.items():
                if await pag.locator(sel).count():
                    await pag.locator(sel).first.fill(val.format(n=n_))
            peticiones.clear()
            codigos.clear()
            await pag.locator(f'.hoja {enviar}').first.click()
            await pag.wait_for_timeout(2600)
            mando = [x for x in peticiones if x in ('POST', 'PUT', 'PATCH')]
            if not mando:
                fallos.append(f'{nombre}: relleno y pulsado, NO manda nada al servidor.')
            elif codigos and max(codigos) >= 400:
                fallos.append(f'{nombre}: el servidor lo rechaza ({max(codigos)}).')
            revisados += 1
            await cerrar_todo()

        # ---- Y de verdad: enviar el alta de una persona -----------------
        await entrar('admin')
        await pag.goto(BASE + '#/equipo')
        await pag.wait_for_timeout(2300)
        if await pag.locator('[data-nuevo]').count():
            await pag.click('[data-nuevo]')
            await pag.wait_for_timeout(2000)
            vivo = await pag.evaluate(
                "()=>{const b=document.querySelector('.hoja [data-si]');"
                " return !b ? 'no está' : b.disabled ? 'apagado'"
                " : typeof b.onclick==='function' ? 'bien' : 'sin manejador';}")
            if vivo != 'bien':
                fallos.append(f'ALTA DE PERSONA: el botón «Crear» está {vivo}. '
                              'Se rellena el formulario, se pulsa y no pasa nada.')
                revisados += 1
                await nav.close()
                return _informe(revisados, nohay, fallos)
            peticiones.clear()
            await pag.evaluate("()=>{const c=document.querySelector('#u-usuario'); if(c) c.value='';}")
            await pag.click('.hoja [data-si]')
            await pag.wait_for_timeout(1200)
            queja = await pag.evaluate("()=>document.querySelector('#err-u')?.innerText.trim()||''")
            if peticiones:
                fallos.append('Alta de persona: manda al servidor con el formulario vacío.')
            if not queja:
                fallos.append('Alta de persona: con el formulario vacío no manda NI avisa. '
                              'Ése es justo el fallo invisible.')
            n = random.randint(100000, 999999)
            await pag.fill('#u-usuario', f'prb{n}')
            await pag.fill('#u-pwd', 'Prueba2026xx')
            peticiones.clear()
            await pag.click('.hoja [data-si]')
            await pag.wait_for_timeout(2600)
            if 'POST' not in peticiones:
                fallos.append('Alta de persona: relleno y pulsado, no manda nada al servidor.')
            revisados += 1

        await nav.close()

    print('VENTANAS ABIERTAS:', ' | '.join(sorted(set(abiertas))))
    barrer()
    return _informe(revisados, nohay, fallos)



def _api(metodo, ruta, token, cuerpo=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, r.read(2000000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(2000000).decode('utf-8', 'replace')
    except Exception:                            # noqa: BLE001
        return 0, ''


def barrer():
    """Quitar lo que esta prueba ha creado de verdad.

    Esta prueba no simula: rellena formularios y los envía, así que crea
    departamentos, hoteles, frases, revisiones, instrucciones y personas
    REALES en la base. Y no borraba ninguno.

    El resultado, descubierto más tarde: el hotel acabó con departamentos
    de prueba sueltos y con un segundo hotel fantasma, y ese hotel
    fantasma hizo que la plantilla de alta de personal viniera marcada
    con el hotel equivocado. Una prueba que deja basura no sólo ensucia:
    cambia el comportamiento de lo que se prueba después.
    """
    c, cuerpo = _api('POST', 'auth/login', '',
                     {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print('  (no se ha podido barrer: no se entra)')
        return
    t = json.loads(cuerpo)['token']

    quitados = 0
    # Cada cosa con su ruta, su bolsa y cómo se llama lo que crea.
    for ruta, bolsa, campo, idc, prefijos in (
            ('departamentos', 'departamentos', 'nombre', 'id_departamento', ('Pruebas ',)),
            ('hoteles',       'hoteles',       'nombre', 'id_hotel',        ('Hotel ',)),
            ('mantenimiento', 'mantenimientos', 'nombre', 'id_mantenimiento', ('Revisión ',)),
            ('instrucciones', 'instrucciones', 'titulo', 'id_instruccion',  ('Instrucción ',)),
            ('frases',        'frases',        'texto',  'id_frase',        ('Frase de prueba ',)),
            ('usuarios&todos=1', 'usuarios',   'usuario', 'id_login',       ('prb',)),
    ):
        _, cu = _api('GET', ruta, t)
        try:
            cosas = json.loads(cu).get(bolsa) or []
        except Exception:                        # noqa: BLE001
            continue
        base = ruta.split('&')[0]
        for x in cosas:
            nombre = str(x.get(campo) or '')
            # «Hotel Benalma» empieza por «Hotel » y NO se toca: sólo lo
            # que esta prueba crea, que lleva un número detrás.
            if not any(nombre.startswith(p) for p in prefijos):
                continue
            resto = nombre
            for p in prefijos:
                if nombre.startswith(p):
                    resto = nombre[len(p):]
            if not resto.strip().isdigit():
                continue
            ident = x.get(idc)
            if ident:
                cod, cu2 = _api('DELETE', f'{base}/{ident}&definitivo=1', t)
                if not (200 <= cod < 300):
                    cod, cu2 = _api('DELETE', f'{base}/{ident}', t)

                # Que se haya ido DE VERDAD, no sólo desactivado.
                #
                # El servidor conserva —desactivada— una revisión que ya
                # ha generado algún parte, y eso para los datos de
                # verdad es lo correcto. Pero esto barre revisiones de
                # mentira: se contaban como barridas y se quedaban
                # puestas. Quedaron dos, «Revisión 47200» y «Revisión
                # 54572», que la red de seguridad de la tanda cantó al
                # final. El mismo descuido estaba en pruebas/hoteles.py.
                if 'desactivad' in (cu2 or '').lower():
                    _, ch = _api('GET', f'{base}/{ident}/historial', t)
                    try:
                        suyos = json.loads(ch).get('tickets') or []
                    except Exception:            # noqa: BLE001
                        suyos = []
                    for tk in suyos:
                        _api('DELETE', f'tickets/{tk["id_ticket"]}&definitivo=1', t)
                    cod, cu2 = _api('DELETE', f'{base}/{ident}', t)
                    if 'desactivad' in (cu2 or '').lower():
                        print(f'  AVISO: «{nombre}» no se ha podido borrar; '
                              'queda desactivada en la base.')
                quitados += 1
    if quitados:
        print(f'  (barridos {quitados} elementos creados por esta prueba)')


def _informe(revisados, nohay, fallos):
    print(f'Formularios revisados: {revisados}')
    if nohay:
        print(f'Botones que no aparecieron (no se han podido revisar): {len(nohay)}')
        for n_ in nohay:
            print('    -', n_)
    if fallos:
        print()
        for f in fallos:
            print('  ·', f)
        return 1
    print('Todos abren sin error y todos sus botones tienen manejador.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(principal('--ver' in sys.argv)))
