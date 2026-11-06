#!/usr/bin/env python3
"""
Botones cuyo efecto no se ve en la pantalla.

Son los que se escapan de todas las demás pruebas, porque el rastreo mira
si la pantalla cambia y estos no la cambian: bajan un fichero, o abren el
cuadro de elegir foto del teléfono. Parecen muertos y no lo están —o
están muertos y nadie se enteraría—.

Aquí se cogen por el efecto de verdad:

  · «Exportar» de los informes tiene que bajar un CSV con datos dentro
    (y no un fichero vacío, que es lo que se abre en Excel y no dice nada);
  · «Añadir foto», en el parte nuevo y en un parte abierto, tiene que
    abrir el cuadro de elegir fichero, y sólo aceptar imágenes;
  · y una foto elegida tiene que quedarse puesta, no desaparecer.

   python3 pruebas/descargas.py
"""

import asyncio
import os
import sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

from playwright.async_api import async_playwright

BASE = os.environ.get('HURAKANTICK_BASE', 'http://127.0.0.1/hurakantick/www/index.html')
CLAVE = entorno.CLAVE
UNA_IMAGEN = (
    b'\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01'
    b'\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01'
    b'\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82')


async def main() -> int:
    fallos = []
    tmp = '/tmp/claude-0/descargas'
    os.makedirs(tmp, exist_ok=True)
    foto = os.path.join(tmp, 'una-foto.png')
    with open(foto, 'wb') as fh:
        fh.write(UNA_IMAGEN)

    async with async_playwright() as p:
        nav = await p.chromium.launch()
        ctx = await nav.new_context(service_workers='block', accept_downloads=True,
                                    viewport={'width': 390, 'height': 844},
                                    locale='es-ES', is_mobile=True, has_touch=True)
        pag = await ctx.new_page()
        pag.set_default_timeout(25000)
        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:160]))

        await pag.goto(BASE)
        await pag.wait_for_timeout(1800)
        await pag.fill('#correo', entorno.usuario('admin'))
        await pag.fill('#password', CLAVE)
        await pag.click('#b-entrar')
        await pag.wait_for_timeout(9000)

        # ---- 1. El CSV de los informes --------------------------------
        await pag.goto(BASE + '#/informes')
        await pag.reload()
        await pag.wait_for_timeout(4000)

        # El botón sólo existe cuando ya hay un informe hecho: se lanza el
        # que venga puesto por defecto.
        await pag.evaluate("()=>document.querySelector('[data-ver]')?.click()")
        await pag.wait_for_timeout(5000)

        hay = await pag.evaluate("()=>!!document.querySelector('[data-csv]')")
        if not hay:
            fallos.append('En los informes no aparece el botón «Exportar», '
                          'así que no hay forma de sacar los datos.')
        else:
            try:
                async with pag.expect_download(timeout=20000) as esperando:
                    await pag.evaluate("()=>document.querySelector('[data-csv]').click()")
                bajado = await esperando.value
                destino = os.path.join(tmp, bajado.suggested_filename or 'informe.csv')
                await bajado.save_as(destino)
                texto = open(destino, encoding='utf-8', errors='replace').read()
                if len(texto.strip()) < 10 or '\n' not in texto.strip():
                    fallos.append('«Exportar» baja un CSV sin datos: una cabecera '
                                  'y nada más. En Excel no dice nada.')
                elif ';' not in texto and ',' not in texto:
                    fallos.append('El CSV no tiene columnas separadas: Excel lo '
                                  'abrirá todo en una sola columna.')
            except Exception as e:                   # noqa: BLE001
                fallos.append(f'«Exportar» no baja ningún fichero ({str(e)[:70]}).')

        # ---- 2. Los botones de foto -----------------------------------
        #
        # El campo de fichero NO está en la pantalla: se crea al pulsar, se
        # usa y se quita. Es lo correcto —el selector de ficheros del móvil
        # da problemas si se deja puesto— pero significa que buscarlo en el
        # HTML no sirve de nada. Mi primera versión lo buscaba así y
        # concluía que «no hay por dónde subir una foto», habiendo.
        for ruta, etiqueta, texto in (('#/nuevo', 'parte nuevo', 'Añadir foto'),
                                      ('#/ticket/1', 'un parte abierto', 'Añadir fotograf')):
            await pag.goto(BASE + ruta)
            await pag.reload()
            await pag.wait_for_timeout(4500)

            pulsado = await pag.evaluate(
                "(t)=>{const b=[...document.querySelectorAll('#contenido button')]"
                " .find((x)=>(((x.innerText||'').trim())||x.getAttribute('aria-label')||'').startsWith(t));"
                " if(!b) return 0; return 1;}", texto)
            if not pulsado:
                fallos.append(f'En {etiqueta} no hay botón para añadir una foto.')
                continue

            try:
                async with pag.expect_file_chooser(timeout=12000) as esperando:
                    await pag.evaluate(
                        "(t)=>{const b=[...document.querySelectorAll('#contenido button')]"
                        " .find((x)=>(((x.innerText||'').trim())||x.getAttribute('aria-label')||'').startsWith(t)); b.click();}",
                        texto)
                cuadro = await esperando.value
                elemento = cuadro.element
                acepta = await elemento.get_attribute('accept')
                if 'image' not in (acepta or ''):
                    fallos.append(f'En {etiqueta} el cuadro de foto acepta cualquier '
                                  f'fichero, no sólo imágenes (accept="{acepta}").')
                await cuadro.set_files(foto)
                await pag.wait_for_timeout(4000)

                puesta = await pag.evaluate("""() => {
                    const c = document.querySelector('#contenido');
                    return c.querySelectorAll('img[src^="blob:"], img[src^="data:"], '
                      + 'img[src*="/foto"], .foto, [data-foto], .miniatura').length;
                }""")
                if not puesta:
                    fallos.append(f'En {etiqueta} se elige una foto y no se queda '
                                  'puesta: no hay señal de que se haya cogido.')
            except Exception as e:                   # noqa: BLE001
                fallos.append(f'En {etiqueta} el botón de foto no abre el cuadro de '
                              f'elegir fichero ({str(e)[:70]}).')

        if errores_js:
            fallos.append(f'La pantalla da errores: {errores_js[0]}')

        await nav.close()

    print('Botones sin efecto visible probados: Exportar (CSV) y foto en dos pantallas.')
    print(f'  que salen mal: {len(fallos)}')
    if fallos:
        print('\nLO QUE NO PASA CUANDO SE PULSAN:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nEl CSV baja con datos y las fotos se quedan puestas.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
