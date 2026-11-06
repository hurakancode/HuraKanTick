#!/usr/bin/env python3
"""
Lo que se hace sin cobertura tiene que llegar, y llegar UNA vez.

En un hotel hay sótanos, cuartos de máquinas y cámaras frigoríficas. Un
técnico abre un parte ahí abajo, escribe una nota y cambia el estado, y
todo eso se guarda en el móvil hasta que vuelve a haber cobertura. Los
dos fallos posibles son muy distintos de gravedad:

  · **Se pierde**: el técnico dio el parte y no existe. Nadie se entera
    hasta que el cliente vuelve a quejarse.
  · **Se duplica**: el mismo parte entra dos o tres veces. Ensucia la
    lista, descuadra los informes, y alguien hace el trabajo dos veces.

Lo segundo pasa cuando la cola reintenta algo que de verdad llegó. Por eso
cada cosa que sale del móvil lleva su propio identificador y el servidor
lo usa para no apuntarla dos veces.

La prueba corta la red de verdad —no simula nada—, trabaja un rato sin
ella, la devuelve y mira qué ha llegado.

   python3 pruebas/sincronizacion.py [--ver]
"""

import os
import asyncio
import json
import random
import sys
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

RAIZ = os.environ.get('HURAKANTICK_RAIZ', 'http://127.0.0.1/hurakantick')
BASE = RAIZ + '/www/index.html'
API = RAIZ + '/api/index.php?_ruta='
CLAVE = entorno.CLAVE
def api(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            return r.status, r.read(2000000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


async def principal(ver: bool) -> int:
    from playwright.async_api import async_playwright

    marca = f'zzz sin cobertura {random.randint(10000, 99999)}'
    fallos = []

    _, c = api('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    ficha = json.loads(c)['token']

    async with async_playwright() as p:
        nav = await p.chromium.launch(headless=not ver)
        ctx = await nav.new_context(viewport={'width': 390, 'height': 844},
                                    locale='es-ES', is_mobile=True, has_touch=True)
        pag = await ctx.new_page()
        pag.set_default_timeout(25000)
        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:150]))

        await pag.goto(BASE)
        await pag.wait_for_timeout(2000)
        await pag.fill('#correo', entorno.usuario('luis'))
        await pag.fill('#password', CLAVE)
        await pag.click('#b-entrar')
        await pag.wait_for_timeout(9000)

        # Que el trabajador de fondo haya terminado de guardarse: sin él no
        # hay modo sin cobertura que valga.
        for _ in range(25):
            if await pag.evaluate(
                    "async()=>{const r=await navigator.serviceWorker.getRegistration();"
                    " return !!(r && r.active);}"):
                break
            await pag.wait_for_timeout(1000)
        await pag.wait_for_timeout(6000)

        # ---- El caso de verdad -----------------------------------------
        # El técnico abre el parte arriba, con cobertura, y pierde la señal
        # al bajar al sótano. Por eso se rellena con red y se pulsa sin
        # ella: es lo que pasa en el hotel, y además evita pelearse con el
        # selector de ubicación, que necesita el catálogo cargado.
        creados = 0
        for i in range(3):
            await ctx.set_offline(False)
            await pag.goto(BASE + '#/nuevo')
            await pag.wait_for_timeout(2600)

            # Tipo de avería: uno de los accesos rápidos.
            await pag.evaluate(
                "()=>{const b=document.querySelectorAll('#contenido button')[1];"
                " b && b.click();}")
            await pag.wait_for_timeout(1000)

            # Ubicación, con cobertura y por el buscador del selector.
            # Hay que ESCRIBIR de verdad: el buscador escucha el teclado, y
            # meterle el valor por código no dispara nada.
            if await pag.locator('#b-zona').count():
                await pag.evaluate("()=>document.querySelector('#b-zona').click()")
                await pag.wait_for_timeout(1500)
                await pag.evaluate("()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                                   " const b=h.querySelector('[data-buscar]'); if(b) b.click();}")
                await pag.wait_for_timeout(1400)
                if await pag.locator('#b-filtro').count():
                    await pag.locator('#b-filtro').click()
                    await pag.locator('#b-filtro').type('1001', delay=70)
                    await pag.wait_for_timeout(2200)
                    await pag.evaluate("""()=>{
                      const h=[...document.querySelectorAll('.hoja')].pop();
                      const o=[...h.querySelectorAll('button,.fila')]
                        .find(b=>/›/.test(b.innerText||''));
                      if(o) o.click();
                    }""")
                    await pag.wait_for_timeout(1800)
                # Cerrar lo que quede abierto del selector.
                for _ in range(3):
                    if await pag.locator('.hoja').count() == 0:
                        break
                    await pag.evaluate("""()=>{const h=[...document.querySelectorAll('.hoja')].pop();
                        const b=h.querySelector('[data-ok]')||h.querySelector('[data-cerrar]');
                        if(b) b.click();}""")
                    await pag.wait_for_timeout(900)

            await pag.fill('#titulo', f'{marca} número {i + 1}')
            if await pag.locator('#detalle').count():
                await pag.fill('#detalle', 'dado desde el sótano')

            # Y AHORA se va la cobertura, con el parte a medio dar.
            await ctx.set_offline(True)
            await pag.wait_for_timeout(600)

            pulsado = await pag.evaluate(
                "()=>{const b=[...document.querySelectorAll('button')]"
                ".find(x=>/Crear parte/i.test(x.innerText)); if(!b) return 0;"
                " b.click(); return 1;}")
            if pulsado:
                await pag.wait_for_timeout(2600)
                queja = await pag.evaluate(
                    "()=>[...document.querySelectorAll('#mensajes .mensaje--error')]"
                    ".map(e=>e.innerText.trim()).join(' / ')")
                if queja:
                    fallos.append(f'Sin cobertura, el alta se queja: «{queja[:70]}».')
                else:
                    creados += 1

        guardados = await pag.evaluate(
            "async()=>{try{const m=await import('./assets/js/db.js');"
            " const c=await m.colaPendiente(); return (c||[]).length;}catch(e){return -1;}}")

        # ---- Vuelve la cobertura ---------------------------------------
        await ctx.set_offline(False)
        await pag.goto(BASE + '#/panel')
        await pag.wait_for_timeout(4000)
        # Empujar la sincronización por si no salta sola.
        await pag.evaluate(
            "async()=>{try{const s=await import('./assets/js/sync.js');"
            " if(s.sincronizar) await s.sincronizar();}catch(e){}}")
        await pag.wait_for_timeout(9000)

        print(f'  partes dados sin cobertura: {creados}'
              + (f' · en la cola del móvil: {guardados}' if guardados >= 0 else ''))

        # ---- ¿Qué ha llegado al servidor? ------------------------------
        _, cuerpo = api('GET', 'tickets&limite=500', None, ficha)
        try:
            lista = json.loads(cuerpo).get('tickets', [])
        except Exception:                        # noqa: BLE001
            lista = []
        def titulo_de(t):
            # La API lo llama «titulo_ticket»; el móvil, «titulo». Mirar
            # sólo uno daba «se pierde trabajo» cuando el parte estaba ahí.
            return str(t.get('titulo_ticket') or t.get('titulo') or '')

        mios = [t for t in lista if marca in titulo_de(t)]
        titulos = [titulo_de(t) for t in mios]

        if len(mios) < creados:
            fallos.append(f'Se han dado {creados} partes sin cobertura y sólo han '
                          f'llegado {len(mios)}: se pierde trabajo.')
        if len(titulos) != len(set(titulos)):
            repes = len(titulos) - len(set(titulos))
            fallos.append(f'{repes} parte(s) han llegado por duplicado.')

        # ---- Y una nota sin cobertura sobre un parte que ya existe ------
        if mios:
            idt = mios[0]['id_ticket']
            await pag.goto(BASE + f'#/ticket/{idt}')
            await pag.wait_for_timeout(3000)
            await ctx.set_offline(True)
            await pag.wait_for_timeout(500)
            texto = f'{marca} nota desde el sótano'
            # La nota NO es un cuadro de la ficha: se escribe en una ventana
            # que abre el botón «Añadir nota». La primera versión de esta
            # prueba escribía en la caja de la conversación y luego decía
            # que la nota no había llegado.
            abierto = await pag.evaluate(
                "()=>{const b=document.querySelector('[data-accion=\"nota\"]');"
                " if(!b) return 0; b.click(); return 1;}")
            if abierto:
                await pag.wait_for_timeout(1800)
                if await pag.locator('#nota').count():
                    await pag.fill('#nota', texto)
                    await pag.evaluate(
                        "()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                        " const b=h && h.querySelector('[data-si]'); if(b) b.click();}")
                    await pag.wait_for_timeout(2200)
                else:
                    fallos.append('Sin cobertura no se puede abrir la ventana de nota.')
            else:
                fallos.append('No aparece el botón de añadir nota en la ficha del parte.')
            await ctx.set_offline(False)
            await pag.goto(BASE + '#/panel')
            await pag.wait_for_timeout(3000)
            await pag.evaluate(
                "async()=>{try{const s=await import('./assets/js/sync.js');"
                " if(s.sincronizar) await s.sincronizar();}catch(e){}}")
            await pag.wait_for_timeout(7000)

            _, cn = api('GET', f'tickets/{idt}', None, ficha)
            cuantas = cn.count(texto)
            if cuantas == 0:
                fallos.append('Una nota escrita sin cobertura no ha llegado.')
            elif cuantas > 1:
                fallos.append(f'Una nota escrita sin cobertura ha llegado {cuantas} veces.')

        if errores_js:
            fallos.append(f'La aplicación da errores sin cobertura: {errores_js[0]}')

        await nav.close()

    # ---- Barrer lo creado ----------------------------------------------
    _, cuerpo = api('GET', 'tickets&limite=500', None, ficha)
    try:
        for t in json.loads(cuerpo).get('tickets', []):
            if marca in str(t.get('titulo_ticket') or t.get('titulo') or ''):
                api('DELETE', f'tickets/{t["id_ticket"]}&definitivo=1', None, ficha)
    except Exception:                            # noqa: BLE001
        pass

    return informe(fallos, 1)


def informe(fallos, _):
    if fallos:
        print('\nLO QUE FALLA SIN COBERTURA:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nTodo lo hecho sin cobertura llega, y llega una sola vez.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(principal('--ver' in sys.argv)))
