#!/usr/bin/env python3
"""
Una jornada de trabajo de cada persona, por la pantalla de verdad.

Las demás pruebas miran piezas: una ruta, un permiso, un formulario.
Ésta hace lo que hace la gente. Cada papel del equipo de demostración
entra con su usuario y su contraseña por la pantalla de entrada, en un
móvil simulado, y trabaja:

  · recepción da un parte y escribe en su conversación;
  · el técnico de Servicios Técnicos se lo queda, anota, lo pone en curso
    y contesta;
  · la técnica de Pisos trabaja lo suyo y, si escribe a mano la dirección
    de un parte que no es de su departamento, ve una explicación y no una
    pantalla en blanco;
  · el responsable lo reasigna, mira el equipo, saca un informe y lee la
    conversación;
  · el administrador recorre TODA la administración y cierra el parte.

Durante toda la jornada se recoge, y cualquiera de estas cosas es un
fallo:

  · un error de JavaScript, o un error en la consola;
  · una respuesta 5xx del servidor;
  · un 403 provocado por la propia aplicación: si una pantalla le ofrece
    a alguien algo que no puede hacer, el fallo es de la pantalla;
  · una pantalla que se queda vacía;
  · que se EJECUTE algo de lo que la gente escribe.

Lo último es el ataque de siempre a una aplicación como ésta (XSS): lo
que escribe recepción lo abren después un técnico y el administrador, y
si se pintara como HTML, quien escribiera en un parte podría actuar con
la sesión del administrador. Por eso los textos de esta jornada llevan
HTML hostil, que si llegara a ejecutarse dejaría una marca en la
página. Y se comprueba además que se VE como texto: que desaparezca en
silencio también sería un fallo, sólo que distinto.

Al terminar se borra lo que la jornada ha creado.

   python3 pruebas/jornada.py
"""

import asyncio
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

from playwright.async_api import async_playwright

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get('HURAKANTICK_BASE', 'http://127.0.0.1/hurakantick/www/index.html')
API = entorno.API
MARCA = 'zz-jornada'

# Lo que, si se pintara como HTML, ejecutaría código. Cada una sube un
# contador en la página; tiene que quedarse en cero.
HOSTIL = ('<img src=x onerror="window.__xss=(window.__xss||0)+1">'
          '"><svg/onload="window.__xss=(window.__xss||0)+1">'
          '</textarea><script>window.__xss=(window.__xss||0)+1</script>')
VISIBLE = '<img src=x'      # lo que debe verse, como texto, en la pantalla

# Lo que puede fallar sin que sea culpa de la aplicación: el logo, que
# el cliente todavía no ha mandado (docs/PENDIENTE.md). Nada más.
RUIDO = ('HuraKan_Tick_Logo.png',)


def api(metodo, ruta, token=None, cuerpo=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
        datos = json.dumps(cuerpo).encode()
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b'{}')
        except Exception:                              # noqa: BLE001
            return e.code, {}


def token_de(puesto):
    _, r = api('POST', 'auth/login', cuerpo={'correo': entorno.usuario(puesto),
                                              'password': entorno.CLAVE})
    return r.get('token')


class Jornada:
    """Una persona, un móvil, y todo lo que pasa mientras trabaja."""

    def __init__(self, nav, papel, puesto):
        self.nav, self.papel, self.puesto = nav, papel, puesto
        self.fallos = []
        self.permitidos_403 = set()   # rutas de API donde un 403 es lo correcto
        self.pasos = 0

    async def abrir(self):
        self.ctx = await self.nav.new_context(
            viewport={'width': 390, 'height': 844}, locale='es-ES',
            is_mobile=True, has_touch=True)
        await self.ctx.add_init_script("""
            window.__xss = 0; window.__err = [];
            addEventListener('error', e => { if (e.message) window.__err.push(String(e.message)); });
            addEventListener('unhandledrejection', e => window.__err.push('promesa: ' + (e.reason && e.reason.message || e.reason)));
        """)
        self.pag = await self.ctx.new_page()
        self.pag.set_default_timeout(8000)
        self.pag.on('pageerror', lambda e: self.fallo(f'error de JavaScript: {e}'))
        self.pag.on('console', self._consola)
        self.pag.on('response', self._respuesta)

    def fallo(self, que):
        donde = (self.pag.url.split('#')[-1] if getattr(self, 'pag', None) else '?') or '/'
        texto = f'[{self.papel} · {donde}] {que}'
        if texto not in self.fallos:
            self.fallos.append(texto)

    def _consola(self, m):
        if m.type != 'error':
            return
        t = m.text
        if any(r in t for r in RUIDO) or any(r in (m.location or {}).get('url', '') for r in RUIDO):
            return
        # El navegador avisa de cada 4xx como «Failed to load resource»;
        # ésos se juzgan en _respuesta, con su ruta.
        if 'Failed to load resource' in t:
            return
        # La aplicación anota en consola el «no tienes permiso» que ella
        # misma explica en pantalla; si ese 403 era el esperado, es eso.
        if self.permitidos_403 and 'No tienes permiso' in t:
            return
        self.fallo(f'consola: {t[:160]}')

    def _respuesta(self, r):
        url = r.url
        if any(x in url for x in RUIDO):
            return
        if r.status >= 500:
            self.fallo(f'el servidor contesta {r.status}: {url.split("_ruta=")[-1][:80]}')
        elif r.status == 403 and '_ruta=' in url:
            ruta = urllib.parse.unquote(url.split('_ruta=')[-1].split('&')[0])
            if not any(ruta.startswith(p) for p in self.permitidos_403):
                self.fallo(f'la propia aplicación pide algo que no puede (403): {ruta}')

    async def entrar(self):
        await self.pag.goto(BASE)
        await self.pag.wait_for_selector('#correo', timeout=20000)
        await self.pag.fill('#correo', entorno.usuario(self.puesto))
        await self.pag.fill('#password', entorno.CLAVE)
        await self.pag.click('#b-entrar')
        await self.pag.wait_for_timeout(3500)
        if await self.pag.locator('#correo').count():
            self.fallo('no consigue entrar por la pantalla de entrada')
            return False
        return True

    async def ir(self, ruta, espera=2200):
        await self.pag.goto(BASE + '#' + ruta)
        await self.pag.wait_for_timeout(espera)
        await self.revisar()

    async def revisar(self):
        """Después de cada paso: ¿se ha ejecutado algo? ¿hay pantalla?"""
        self.pasos += 1
        xss, err = await self.pag.evaluate(
            '() => { const n = window.__xss || 0; window.__xss = 0; return [n, window.__err.splice(0)]; }')
        if xss:
            self.fallo(f'¡SE HA EJECUTADO HTML ESCRITO POR UN USUARIO! ({xss} veces)')
        for e in err:
            if not any(r in e for r in RUIDO):
                self.fallo(f'error en la página: {e[:160]}')
        largo = await self.pag.evaluate(
            "() => (document.querySelector('#contenido')?.innerText || '').trim().length")
        if largo < 3:
            self.fallo('la pantalla se queda vacía')

    async def pulsar(self, selector, espera=1500):
        if not await self.pag.locator(selector).count():
            return False
        await self.pag.evaluate(f"() => document.querySelector({json.dumps(selector)}).click()")
        await self.pag.wait_for_timeout(espera)
        return True

    async def pulsar_texto(self, patron, dentro='.hoja', espera=1600):
        """Pulsa, en la última hoja abierta (o donde se diga), el botón cuyo
        texto encaje. Devuelve si lo encontró."""
        ok = await self.pag.evaluate("""([patron, dentro]) => {
            const zonas = [...document.querySelectorAll(dentro)];
            const z = zonas.length ? zonas[zonas.length - 1] : document;
            const re = new RegExp(patron, 'i');
            const b = [...z.querySelectorAll('button,.fila,[role=button]')]
              .find(x => re.test((x.innerText || '').trim()) && x.offsetParent !== null);
            if (b) { b.click(); return true; }
            return [...z.querySelectorAll('button,.fila,[role=button]')]
              .map(x => (x.innerText || '').trim().replace(/\\s+/g, ' ').slice(0, 30)).filter(Boolean);
        }""", [patron, dentro])
        await self.pag.wait_for_timeout(espera)
        if ok is True:
            return True
        self.ultimas_opciones = ok
        return False

    async def cerrar_hojas(self):
        for _ in range(4):
            if not await self.pag.locator('.hoja').count():
                return
            await self.pag.evaluate("""() => {
                const h = [...document.querySelectorAll('.hoja')].pop();
                const b = h.querySelector('[data-no],[data-cerrar],[data-ok]');
                if (b) b.click(); else h.remove();
            }""")
            await self.pag.wait_for_timeout(700)

    async def se_ve_como_texto(self, que):
        txt = await self.pag.evaluate("() => document.body.innerText")
        if VISIBLE not in txt:
            self.fallo(f'{que}: el texto con etiquetas no se ve (se ha comido o se ha pintado)')

    async def escribir_chat(self, id_ticket, texto):
        await self.ir(f'/ticket/{id_ticket}/chat')
        if not await self.pag.locator('#c-texto').count():
            self.fallo('la conversación no tiene dónde escribir')
            return
        await self.pag.fill('#c-texto', texto)
        await self.pulsar('#c-enviar', 2200)
        await self.revisar()
        await self.se_ve_como_texto('la conversación')

    async def nota(self, texto):
        if not await self.pulsar('[data-accion="nota"]'):
            self.fallo('la ficha no ofrece «Nota»')
            return
        if not await self.pag.locator('.hoja #nota').count():
            self.fallo('«Nota» no abre dónde escribir')
            await self.cerrar_hojas()
            return
        await self.pag.fill('.hoja #nota', texto)
        await self.pulsar('.hoja [data-si]', 2400)
        await self.revisar()

    async def cerrar(self):
        await self.ctx.close()


# ---------------------------------------------------------------------
# Las jornadas
# ---------------------------------------------------------------------

async def control_del_detector(j):
    """Un detector que nunca salta no demuestra nada. Se le mete a mano
    el mismo HTML hostil SIN escapar y tiene que cazarlo; después se deja
    el contador a cero para la jornada de verdad."""
    n = await j.pag.evaluate("""(h) => { const d = document.createElement('div');
        document.body.appendChild(d); d.innerHTML = h;
        return new Promise(r => setTimeout(() => { const n = window.__xss || 0;
            d.remove(); window.__xss = 0; r(n); }, 400)); }""", HOSTIL)
    if not n:
        j.fallo('el detector de HTML ejecutado no caza ni lo que se le mete a propósito: '
                'esta prueba no sirve hasta arreglarlo')
    return n


async def jornada_recepcion(j, comun):
    """Da un parte con lo que ha visto, y pregunta por él."""
    comun['detector'] = await control_del_detector(j)
    await j.ir('/panel')
    await j.ir('/nuevo', 2800)
    # Tipo de avería: lo busca escribiendo, como haría ella. «Grifo
    # lavabo» es de Servicios Técnicos, que es a quien le toca trabajarlo.
    await j.pulsar('#b-elemento', 1600)
    # El selector abre por familias; «Buscar entre los N tipos» abre el buscador.
    await j.pulsar('.hoja [data-buscar-todo]', 1400)
    if await j.pag.locator('.hoja #b-filtro').count():
        await j.pag.locator('.hoja #b-filtro').click()
        await j.pag.locator('.hoja #b-filtro').type('grifo lavabo', delay=50)
        await j.pag.wait_for_timeout(1400)
        await j.pulsar_texto(r'^\W*Grifo lavabo', '.hoja #b-lista', 1400)
    await j.cerrar_hojas()
    # Elegido de verdad: el texto del botón tiene que decir cuál. (En una
    # instalación nueva cada tipo lleva su icono como carácter delante del
    # nombre; la prueba lo buscaba anclado al principio y no lo encontraba,
    # y como sólo miraba si seguía diciendo «Elegir tipo» —que ahí dice
    # «Elegir qué falla»— seguía adelante sin tipo.)
    if 'Grifo lavabo' not in (await j.pag.inner_text('#txt-elemento')):
        j.fallo(f'no consigue elegir el tipo de avería buscándolo: {getattr(j, "ultimas_opciones", None)}')
    # Ubicación, escribiendo en el buscador como lo haría ella.
    if await j.pulsar('#b-zona', 1500):
        await j.pag.evaluate("() => { const h=[...document.querySelectorAll('.hoja')].pop(); const b=h && h.querySelector('[data-buscar]'); if (b) b.click(); }")
        await j.pag.wait_for_timeout(1200)
        if await j.pag.locator('#b-filtro').count():
            await j.pag.locator('#b-filtro').click()
            await j.pag.locator('#b-filtro').type('10', delay=60)
            await j.pag.wait_for_timeout(2000)
            await j.pag.evaluate("""() => { const h=[...document.querySelectorAll('.hoja')].pop();
                const o=[...h.querySelectorAll('button,.fila')].find(b=>/›/.test(b.innerText||''));
                if (o) o.click(); }""")
            await j.pag.wait_for_timeout(1500)
        await j.cerrar_hojas()
    await j.pag.fill('#titulo', f'{MARCA} grifo {HOSTIL}'[:120])
    await j.pag.fill('#detalle', f'{MARCA} gotea desde esta mañana {HOSTIL}')
    creado = await j.pag.evaluate("""() => { const b=[...document.querySelectorAll('button')]
        .find(x=>/Crear parte/i.test(x.innerText)); if (!b) return false; b.click(); return true; }""")
    if not creado:
        j.fallo('no encuentra el botón «Crear parte»')
        return
    # Los avisos de error se van solos a los pocos segundos: se leen ya.
    await j.pag.wait_for_timeout(700)
    queja_pronta = await j.pag.evaluate("() => [...document.querySelectorAll('.mensaje--error, .campo__error:not(.oculto)')].filter(e=>e.offsetParent!==null).map(e=>e.innerText.trim()).join(' / ')")
    await j.pag.wait_for_timeout(3300)
    m = re.search(r'#/ticket/(\d+)', j.pag.url)
    if not m:
        queja = await j.pag.evaluate("() => [...document.querySelectorAll('.mensaje--error, .campo__error:not(.oculto)')].filter(e=>e.offsetParent!==null).map(e=>e.innerText.trim()).join(' / ')")
        j.fallo(f'el parte no se crea: {(queja or queja_pronta)[:140] or "sin explicación en pantalla"}')
        return
    comun['parte'] = int(m.group(1))
    _, ficha = api('GET', f'tickets/{comun["parte"]}', token_de('admin'))
    grupo = (ficha.get('ticket') or ficha).get('id_grupo_asignado')
    if grupo != 1:
        j.fallo(f'un «Grifo lavabo» ha ido al departamento {grupo}, no a Servicios Técnicos')
    await j.revisar()
    await j.se_ve_como_texto('la ficha del parte recién dado')
    await j.escribir_chat(comun['parte'], f'{MARCA} ¿vendrá alguien hoy? {HOSTIL}')
    for r in ('/mensajes', '/avisos', '/mios', '/lista', '/cuenta'):
        await j.ir(r)


async def jornada_tecnico(j, comun):
    """Se queda el parte de recepción, lo trabaja y contesta."""
    await j.ir('/panel')
    await j.ir('/lista')
    p = comun.get('parte')
    if not p:
        j.fallo('no hay parte de recepción con el que trabajar')
        return
    await j.ir(f'/ticket/{p}', 2800)
    await j.se_ve_como_texto('la ficha que abre el técnico')
    if await j.pulsar('[data-accion="cogerlo"]', 2600):
        await j.revisar()
    await j.nota(f'{MARCA} cambiada la junta {HOSTIL}')
    if not await j.pulsar('[data-accion="estado"]', 1500):
        j.fallo('la ficha no ofrece «Cambiar estado» al técnico que lo tiene')
    elif not await j.pulsar_texto(r'^\W*En Curso'):
        j.fallo(f'el selector de estado no ofrece «En Curso»: {getattr(j, "ultimas_opciones", None)}')
        await j.cerrar_hojas()
    else:
        # Tras elegir el estado se abre la nota (opcional) con «Guardar».
        await j.pag.wait_for_timeout(1000)
        if await j.pag.locator('.hoja [data-si]').count():
            if await j.pag.locator('.hoja textarea').count():
                await j.pag.fill('.hoja textarea', f'{MARCA} empiezo {HOSTIL}')
            await j.pulsar('.hoja [data-si]', 2400)
        await j.cerrar_hojas()
        txt = await j.pag.evaluate("() => document.querySelector('#contenido')?.innerText || ''")
        if 'En Curso' not in txt:
            j.fallo('tras guardar «En Curso», la ficha no lo dice')
    await j.revisar()
    await j.escribir_chat(p, f'{MARCA} voy para allá {HOSTIL}')
    await leer_lo_sembrado(j, comun)
    for r in ('/mios', '/mensajes', '/unidades', '/cuenta', '/avisos'):
        await j.ir(r)


async def jornada_pisos(j, comun):
    """Lo suyo, y un intento de abrir lo que no es suyo."""
    await j.ir('/panel')
    await j.ir('/lista')
    # Un parte suyo: uno abierto de los que ella ve. Si no hubiera
    # ninguno la jornada no probaría nada, así que eso también es fallo.
    _, lista = api('GET', 'tickets&limite=50&abiertos=1', token_de(j.puesto))
    suyos = [t['id_ticket'] for t in (lista.get('tickets') or []) if t.get('id_ticket', 0) > 0]
    if not suyos:
        j.fallo('no tiene ningún parte abierto con el que trabajar')
    else:
        suyo = suyos[0]
        comun['parte_pisos'] = suyo
        await j.ir(f'/ticket/{suyo}', 2600)
        await j.nota(f'{MARCA} repasado {HOSTIL}')
        await j.escribir_chat(suyo, f'{MARCA} hecho {HOSTIL}')
    # Escribe a mano la dirección de un parte de otro departamento: el
    # servidor dice que no (403, correcto) y la pantalla lo explica.
    p = comun.get('parte')
    if p:
        j.permitidos_403.add(f'tickets/{p}')
        await j.ir(f'/ticket/{p}', 2600)
        txt = await j.pag.evaluate("() => document.querySelector('#contenido')?.innerText || ''")
        if not re.search(r'permiso|no puedes|no es tuyo|no tienes', txt, re.I):
            j.fallo('al abrir un parte ajeno no explica que no tiene permiso')
        if HOSTIL[:12] in txt or VISIBLE in txt:
            j.fallo('¡enseña el contenido de un parte que no puede ver!')
    for r in ('/mios', '/mensajes', '/avisos', '/cuenta'):
        await j.ir(r)


async def jornada_responsable(j, comun):
    """Reparte, vigila el equipo, saca un informe y lee la conversación."""
    await j.ir('/panel')
    await j.ir('/lista')
    p = comun.get('parte')
    if p:
        await j.ir(f'/ticket/{p}', 2800)
        await j.se_ve_como_texto('la ficha que abre el responsable')
        # Reasignar: primero «qué se cambia», luego a quién. Se le pasa a
        # sí mismo («A mí»): es lo que hace un responsable cuando lo atiende él.
        if not await j.pulsar('[data-accion="asignar"]', 1800):
            j.fallo('la ficha no ofrece «Asignar» al responsable')
        elif not await j.pulsar_texto(r'^\W*Quién lo hace'):
            j.fallo(f'«Asignar» no ofrece «Quién lo hace»: {getattr(j, "ultimas_opciones", None)}')
            await j.cerrar_hojas()
        else:
            await j.pag.wait_for_timeout(800)
            if not await j.pulsar_texto(r'^\W*A mí$'):
                j.fallo(f'no encuentra a quién reasignarlo: {getattr(j, "ultimas_opciones", None)}')
            await j.pag.wait_for_timeout(1500)
            await j.cerrar_hojas()
        await j.revisar()
        await j.ir(f'/ticket/{p}/chat')
        await j.se_ve_como_texto('la conversación que lee el responsable')
    for r in ('/equipo', '/informes', '/mantenimiento', '/instrucciones', '/mensajes', '/avisos', '/cuenta'):
        await j.ir(r, 2600)


async def jornada_admin(j, comun):
    """Toda la administración, y cierra el parte."""
    await j.ir('/panel')
    secciones = await secciones_admin()
    for s in secciones:
        await j.ir(f'/admin/{s}', 2400)
    for r in ('/equipo', '/departamentos', '/personal', '/hoteles', '/papelera', '/seguridad',
              '/informes', '/mantenimiento', '/instrucciones', '/unidades', '/gestion-partes',
              '/frases', '/iconografia', '/apariencia', '/avisos-ajustes', '/errores', '/guia-api'):
        await j.ir(r, 2400)
    p = comun.get('parte')
    if p:
        await j.ir(f'/ticket/{p}', 2800)
        await j.se_ve_como_texto('la ficha que abre el administrador')
        await j.ir(f'/ticket/{p}/chat')
        await j.se_ve_como_texto('la conversación que abre el administrador')
        await j.ir(f'/ticket/{p}', 2600)
        if not await j.pulsar('[data-accion="estado"]', 1500):
            j.fallo('la ficha no ofrece «Cambiar estado» al administrador')
        else:
            if not await j.pulsar_texto(r'^\W*(Resuelta|Cerrado)'):
                j.fallo(f'no ofrece cerrar: {getattr(j, "ultimas_opciones", None)}')
                await j.cerrar_hojas()
            await j.pag.wait_for_timeout(1500)
            # Si pide una nota de cierre, se le da.
            if await j.pag.locator('.hoja textarea').count():
                await j.pag.fill('.hoja textarea', f'{MARCA} cerrado {HOSTIL}')
                await j.pulsar('.hoja [data-si]', 2200)
            await j.cerrar_hojas()
            await j.revisar()
    for r in ('/mensajes', '/avisos', '/cuenta'):
        await j.ir(r)


async def secciones_admin():
    """Las secciones que pinta Administración, leídas de su código."""
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    t = open(os.path.join(raiz, 'www/assets/js/views/admin.js'), encoding='utf-8').read()
    bloque = t.split('const secciones = {', 1)[1].split('\n  };', 1)[0]
    return [m.group(1) or m.group(2) for m in
            re.finditer(r"^\s*(?:'([^']+)'|(\w+))\s*:\s*\{", bloque, re.M)]


ENLACE_MALO = '<a href="javascript:window.__xss=(window.__xss||0)+1">pulsa aquí</a>'


def sembrar_hostil(comun):
    """Lo que escriben unos y leen otros, fuera de los partes.

    · Una instrucción, escrita por el RESPONSABLE con el editor de
      bloques, que leen todos los técnicos. Lleva HTML hostil en cada
      tipo de bloque, enlaces «javascript:» y vídeos de dominios falsos.
    · Una ubicación nueva, creada por el responsable desde un parte.
    · El apellido de una persona, puesto por el administrador: sale en
      el equipo, en los selectores de asignar y en la conversación.
    """
    resp, ad = token_de('ana'), token_de('admin')
    h = f'{MARCA} {HOSTIL}'
    bloques = {'time': 1, 'version': '2', 'blocks': [
        {'type': 'header', 'data': {'level': 2, 'text': h}},
        {'type': 'paragraph', 'data': {'text': f'{h} {ENLACE_MALO}'}},
        {'type': 'list', 'data': {'style': 'unordered', 'items': [h, {'content': h, 'items': [h]}]}},
        {'type': 'checklist', 'data': {'items': [{'text': h, 'checked': False}]}},
        {'type': 'paso', 'data': {'numero': 1, 'text': h}},
        {'type': 'aviso', 'data': {'nivel': 'peligro', 'titulo': h, 'text': h}},
        {'type': 'table', 'data': {'withHeadings': True, 'content': [[h, h], [h, ENLACE_MALO]]}},
        {'type': 'embed', 'data': {'source': 'javascript:window.__xss=(window.__xss||0)+1', 'caption': h}},
        {'type': 'embed', 'data': {'source': 'https://youtube.com.atacante.example/x', 'caption': h}},
        {'type': 'image', 'data': {'file': {'url': 'javascript:window.__xss=1'}, 'caption': h}},
    ]}
    st, r = api('POST', 'instrucciones', resp, {
        'titulo': h[:170], 'resumen': h[:290], 'formato': 'bloques',
        'contenido': json.dumps(bloques), 'activo': 1})
    comun['instruccion'] = r.get('id_instruccion') or (r.get('instruccion') or {}).get('id_instruccion')
    if st not in (200, 201) or not comun['instruccion']:
        comun.setdefault('siembra', []).append(f'el responsable no pudo crear la instrucción: {st} {str(r)[:120]}')

    st, r = api('POST', 'zonas', resp, {'nombre1': f'{MARCA}<b>P</b>', 'nombre2': h[:60], 'nombre3': h[:60]})
    if st not in (200, 201):
        comun.setdefault('siembra', []).append(f'el responsable no pudo crear la ubicación: {st} {str(r)[:120]}')

    # El apellido de «test1», que se devuelve como estaba al terminar.
    _, us = api('GET', 'usuarios', ad)
    t1 = next((u for u in (us.get('usuarios') or []) if (u.get('correo') or '').startswith('test1@')), None)
    if t1:
        comun['persona'] = (t1['id_login'], t1.get('apellido2') or '')
        st, r = api('PATCH', f'usuarios/{t1["id_login"]}', ad, {'apellido2': h[:60]})
        if st != 200:
            comun.setdefault('siembra', []).append(f'el administrador no pudo cambiar el apellido: {st}')


async def leer_lo_sembrado(j, comun):
    """El técnico abre la instrucción, pulsa sus enlaces y pasa por las
    listas donde salen la ubicación y la persona."""
    i = comun.get('instruccion')
    if i:
        await j.ir('/instrucciones', 2400)
        abierta = await j.pag.evaluate(f"""() => {{ const b = document.querySelector('[data-i="{i}"]');
            if (b) {{ b.click(); return true; }} return false; }}""")
        if not abierta:
            j.fallo('la instrucción del responsable no sale en la lista del técnico')
        else:
            await j.pag.wait_for_timeout(2200)
            await j.revisar()
            await j.se_ve_como_texto('la instrucción')
            # Pulsar todos sus enlaces: un «javascript:» ejecutaría ahora.
            await j.pag.evaluate("""() => document.querySelectorAll('.hoja a, #contenido a')
                .forEach(a => { if (/pulsa aquí/.test(a.innerText)) a.click(); })""")
            await j.pag.wait_for_timeout(800)
            await j.revisar()
            await j.cerrar_hojas()
    await j.ir('/nuevo', 2400)
    if await j.pulsar('#b-zona', 1500):
        await j.pag.evaluate("() => { const h=[...document.querySelectorAll('.hoja')].pop(); const b=h && h.querySelector('[data-buscar]'); if (b) b.click(); }")
        await j.pag.wait_for_timeout(1200)
        if await j.pag.locator('#b-filtro').count():
            await j.pag.locator('#b-filtro').type(MARCA, delay=40)
            await j.pag.wait_for_timeout(1800)
            await j.revisar()
            txt = await j.pag.evaluate("() => document.body.innerText")
            if MARCA not in txt:
                j.fallo('la ubicación que creó el responsable no sale en el buscador')
            else:
                await j.se_ve_como_texto('la ubicación en el buscador')
        await j.cerrar_hojas()


def deshacer_siembra(comun):
    ad = token_de('admin')
    if comun.get('instruccion'):
        api('DELETE', f'instrucciones/{comun["instruccion"]}&definitivo=1', ad)
    if comun.get('persona'):
        idp, antes = comun['persona']
        api('PATCH', f'usuarios/{idp}', ad, {'apellido2': antes})
    for n in (1, 2, 3, 4):
        entorno.consulta_cruda(
            f'foreach (Db::all("SELECT id_zona_{n} AS id FROM zona_{n} WHERE nombre_zona_{n} LIKE ?", ["{MARCA}%"]) as $z) {{'
            f' Db::run("DELETE FROM rel_zonas WHERE id_zona_{n} = ?", [$z["id"]]);'
            f' Db::run("DELETE FROM zona_{n} WHERE id_zona_{n} = ?", [$z["id"]]); }}')
    entorno.consulta_cruda(
        'Db::run("DELETE FROM instrucciones WHERE titulo LIKE ?", ["' + MARCA + '%"]);')


JORNADAS = [
    ('recepción',         'pilar', jornada_recepcion),
    ('técnico',           'luis',  jornada_tecnico),
    ('técnica de Pisos',  'marta', jornada_pisos),
    ('responsable',       'ana',   jornada_responsable),
    ('administrador',     'admin', jornada_admin),
]


def comprobar_efectos(comun):
    """Que cada jornada HIZO lo que tocaba: un «bien» con los pasos
    saltados en silencio no vale nada."""
    fallos = []
    p = comun.get('parte')
    if not p:
        return ['no llegó a crearse el parte de la jornada']
    ad = token_de('admin')
    _, d = api('GET', f'tickets/{p}', ad)
    t, h = d.get('ticket') or {}, d.get('historial') or []
    tipos = [x.get('tipo') for x in h]
    autores = {x.get('autor') for x in h}
    if HOSTIL not in (t.get('titulo_ticket') or '') and VISIBLE not in (t.get('titulo_ticket') or ''):
        fallos.append('el título no se guardó tal cual se escribió')
    if not any(x.get('tipo') == 'nota' and MARCA in (x.get('detalle_ticket') or '') for x in h):
        fallos.append('no consta la nota del técnico')
    if tipos.count('asignacion') < 2:
        fallos.append(f'constan {tipos.count("asignacion")} asignaciones; se esperaban 2 '
                      '(«me lo quedo» del técnico y la reasignación del responsable)')
    if tipos.count('estado') < 2:
        fallos.append(f'constan {tipos.count("estado")} cambios de estado; se esperaban 2 '
                      '(«En Curso» del técnico y el cierre del administrador)')
    if not t.get('es_final') and not t.get('fecha_cierre'):
        fallos.append('el administrador no consiguió cerrarlo')
    _, c = api('GET', f'tickets/{p}/chat', ad)
    msgs = c.get('mensajes') or []
    if len([m for m in msgs if MARCA in (m.get('texto') or '')]) < 2:
        fallos.append(f'en la conversación hay {len(msgs)} mensajes; se esperaban los de recepción y del técnico')
    if msgs and not all(HOSTIL in (m.get('texto') or '') for m in msgs if MARCA in (m.get('texto') or '')):
        fallos.append('algún mensaje no se guardó tal cual se escribió')
    comun['efectos'] = {'historial': tipos, 'mensajes': len(msgs), 'autores': sorted(a for a in autores if a)}
    return fallos


def limpiar(comun):
    """Lo que ha creado la jornada se va: el parte, con todo lo suyo."""
    ad = token_de('admin')
    if comun.get('parte'):
        api('DELETE', f'tickets/{comun["parte"]}&definitivo=1', ad)
    # Por si algún parte de la jornada se quedó a medias.
    _, r = api('GET', f'tickets&limite=200&q={MARCA}', ad)
    for t in (r.get('tickets') or []):
        if MARCA in (t.get('titulo_ticket') or ''):
            api('DELETE', f'tickets/{t["id_ticket"]}&definitivo=1', ad)
    # Lo que escribió la técnica de Pisos en su propio parte.
    entorno.consulta_cruda(
        'Db::run("DELETE FROM chat_lecturas WHERE id_mensaje IN (SELECT id_mensaje FROM chat_mensajes WHERE texto LIKE ?)", ["' + MARCA + '%"]);'
        'Db::run("DELETE FROM chat_mensajes WHERE texto LIKE ?", ["' + MARCA + '%"]);'
        'Db::run("DELETE FROM notificaciones WHERE cuerpo LIKE ?", ["%' + MARCA + '%"]);'
        'Db::run("DELETE FROM ticket_incidencias_historial WHERE detalle_ticket LIKE ?", ["' + MARCA + '%"]);')


async def main():
    comun = {}
    todos = []
    resumen = []
    sembrar_hostil(comun)
    todos += [f'[siembra] {f}' for f in comun.get('siembra', [])]
    async with async_playwright() as p:
        nav = await p.chromium.launch()
        try:
            for papel, puesto, hacer in JORNADAS:
                j = Jornada(nav, papel, puesto)
                await j.abrir()
                try:
                    if await j.entrar():
                        await hacer(j, comun)
                except Exception as e:                # noqa: BLE001
                    j.fallo(f'la jornada se ha roto a medias: {type(e).__name__}: {str(e)[:140]}')
                finally:
                    await j.cerrar()
                resumen.append((papel, j.pasos, len(j.fallos)))
                todos += j.fallos
        finally:
            await nav.close()
            todos += [f'[efectos] {f}' for f in comprobar_efectos(comun)]
            limpiar(comun)
            deshacer_siembra(comun)

    print('Jornada de cada papel, por la pantalla, con texto hostil en todo lo que se escribe:')
    for papel, pasos, n in resumen:
        print(f'  {papel:18} {pasos:3} pasos   {"bien" if not n else f"{n} FALLOS"}')
    if comun.get('parte'):
        e = comun.get('efectos') or {}
        print(f'  parte T-{comun["parte"]}: historial {e.get("historial")}, '
              f'{e.get("mensajes")} mensajes, por {", ".join(e.get("autores") or [])}')
        print('  (ya está borrado)')
    print(f'  detector de HTML ejecutado: {"caza lo que se le mete a propósito" if comun.get("detector") else "NO FUNCIONA"}')
    if todos:
        print('\nFALLOS:')
        for f in todos:
            print('  ·', f)
        return 1
    print('\nNinguna pantalla falla, nadie llega a lo que no le toca, '
          'y nada de lo escrito se ejecuta.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
