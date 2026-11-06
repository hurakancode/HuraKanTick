#!/usr/bin/env python3
"""
Rastreador de la aplicación.

Recorre la aplicación tocando lo que hay, como lo haría una persona, en
vez de comprobar funciones sueltas. Sirve para lo que las pruebas por
función no ven:

  · pantallas a las que no se llega desde ningún sitio
  · botones que no hacen nada
  · errores que sólo aparecen llegando por el camino real

Se escribió después de varias rondas en las que quien probaba encontraba
cosas que las pruebas no: casi siempre eran caminos, no funciones.

    python3 pruebas/rastreo.py                    como administrador
    python3 pruebas/rastreo.py david.sstt@hotelbenalma.com    como técnico

Hace falta el servidor en marcha y Playwright instalado.
"""

import asyncio, os, sys, json
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

# La misma dirección que el resto de las pruebas.
#
# Antes esto apuntaba al puerto 8099, que es el del servidor de pruebas
# de PHP, y había que acordarse de levantarlo a mano. Nadie se acuerda:
# en la tanda completa esta prueba salía como «ROTA» porque no había
# nada escuchando ahí. Con HURAKANTICK_BASE se puede apuntar a otro sitio.
# Lo que no se pulsa, por lo que HACE, no por dónde esté.
PELIGROSOS = ('salir', 'cerrar sesión', 'desconectar', 'purgar', 'tirar',
              'vaciar', 'restablecer de fábrica', 'desactivar', 'retirar',
              'dar de baja', 'archivar', 'bloquear', 'borrar', 'eliminar')

BASE = os.environ.get("HURAKANTICK_BASE", "http://127.0.0.1/hurakantick/www/index.html")

FIRMA = """() => {
  const c = document.querySelector('#contenido');
  if (!c) { return '(nada)'; }
  // Lo que de verdad distingue una pantalla de la misma pantalla filtrada:
  // qué está marcado como activo y QUÉ FILAS se ven. Medir el largo del
  // HTML no valía: un filtro puede cambiar la lista entera sin cambiar el
  // número de caracteres, y entonces un botón que funciona salía como
  // muerto. Pasó con «De mi grupo» y con las prioridades del parte nuevo.
  const activo = [...c.querySelectorAll('.activa,.opcion--activa,.chip--activo,[aria-pressed="true"],[aria-selected="true"]')]
    .map((e) => e.textContent.trim()).join('|');
  const filas = [...c.querySelectorAll('.fila,[data-t],[data-i],[data-m],[data-u]')]
    .map((e) => e.getAttribute('data-t') || e.getAttribute('data-i')
             || e.getAttribute('data-m') || e.getAttribute('data-u')
             || e.textContent.trim().slice(0, 20)).join(',');
  return 'a[' + activo + '] n=' + c.querySelectorAll('.fila,[data-t],[data-i],[data-m],[data-u]').length
       + ' ' + filas.slice(0, 400) + ' len=' + c.innerHTML.length
       // Hay botones cuyo único efecto es llevarte a otra parte de la
       // misma pantalla —«Ver las 8 notas» baja al seguimiento—. Sin
       // esto salían como muertos, y lo comprobé a mano: funcionan.
       + ' y=' + Math.round((window.scrollY || document.scrollingElement.scrollTop || 0) / 50)
       // Y los avisos, que salen FUERA de #contenido. Un botón cuyo
       // único efecto es decir «no hay nada que hacer ahora» hace su
       // trabajo; sin mirar aquí salía como muerto. Pasó con «Comprobar
       // ahora» de las revisiones.
       + ' m=' + [...document.querySelectorAll('#mensajes .mensaje, .toast, [role=alert]')]
                   .map((e) => e.textContent.trim().slice(0, 30)).join('~');
}"""



# Todas las pantallas que existen en el enrutador
def rutas_del_enrutador():
    """Las rutas se leen de app.js, no se escriben a mano.

    Una lista escrita a mano se queda vieja en cuanto se añade una
    pantalla, y entonces el rastreo dice que todo se alcanza porque ni
    siquiera sabe que la nueva existe."""
    import os, re as _re
    ruta = os.path.join(os.path.dirname(__file__), '..', 'www', 'assets', 'js', 'app.js')
    s = open(ruta, encoding='utf-8').read()
    fuera = []
    for m in _re.finditer(r"patron:\s*/\^\\?/([a-z-]+)(\\/\(\\d\+\))?", s):
        base = '/' + m.group(1)
        if m.group(2):
            continue          # las que llevan número se alcanzan desde otra
        fuera.append(base)
    # Las de administración llevan subruta
    for m in _re.finditer(r"patron:[^,]*admin[^,]*\(([a-z|]+)\)", s):
        for x in m.group(1).split('|'):
            fuera.append('/admin/' + x)
    # «/ticket» y «/familia» necesitan un número: se alcanzan desde otra
    # pantalla y no son destinos por sí mismas.
    fuera = [r for r in fuera if r not in ('/ticket', '/familia', '/ubicacion')]
    return sorted(set(fuera))

RUTAS = rutas_del_enrutador()

async def rastrear(correo, pasos=320):
    async with async_playwright() as p:
        nav = await p.chromium.launch()
        ctx = await nav.new_context(viewport={"width":390,"height":844},
                                    locale="es-ES", is_mobile=True, has_touch=True)
        await ctx.add_init_script("""
          window.__err = [];
          addEventListener('error', e => window.__err.push(String(e.message)));
          addEventListener('unhandledrejection', e => window.__err.push('promesa: ' + e.reason));
        """)
        pag = await ctx.new_page()
        pag.set_default_timeout(6000)

        await pag.goto(BASE); await pag.wait_for_timeout(900)
        await pag.fill("#correo", correo); await pag.fill("#password", entorno.CLAVE)
        await pag.click("#b-entrar"); await pag.wait_for_timeout(4200)

        visitadas = set()        # alcanzadas tocando
        forzadas = set()         # sólo escribiendo la dirección
        muertos = []          # botones que no hacen nada
        errores = []
        pendientes_forzadas = set()
        vistos_botones = set()

        for _ in range(pasos):
            ruta = (pag.url.split('#')[-1] or '/panel').split('?')[0]
            if ruta in pendientes_forzadas:
                forzadas.add(ruta)
            else:
                visitadas.add(ruta)

            # Recoger lo tocable de esta pantalla
            botones = await pag.evaluate("""() => {
                const fuera = [];
                document.querySelectorAll('#contenido button, #contenido [role=button], #cabecera button, .barra button')
                  .forEach((b, i) => {
                    const r = b.getBoundingClientRect();
                    if (!r.width || !r.height) return;
                    if (b.disabled) return;
                    const etiqueta = (b.innerText || b.getAttribute('aria-label') || '').trim().slice(0, 40);
                    fuera.push({ i, etiqueta,
                      zona: b.closest('#cabecera') ? 'cab' : (b.closest('.barra') ? 'barra' : 'cont') });
                  });
                return fuera;
            }""")

            # Elegir uno que no se haya tocado en esta pantalla.
            #
            # Y no pulsar lo que destruye. Esto pulsa a ciegas sobre los
            # DATOS DE VERDAD, así que un botón que actúa de golpe hace
            # daño de verdad: así se desactivó la «Revisión de extintores
            # y BIE» de la demostración —la obligatoria— y nadie se
            # enteró hasta contarlas. Abrir una confirmación es seguro;
            # estos no se abren, actúan.
            elegido = None
            for b in botones:
                clave = (ruta, b['etiqueta'], b['zona'])
                if any(x in b['etiqueta'].lower() for x in PELIGROSOS):
                    vistos_botones.add(clave)
                    continue
                if clave not in vistos_botones and b['etiqueta']:
                    vistos_botones.add(clave)
                    elegido = b
                    break

            if elegido is None:
                # Pantalla agotada.
                #
                # Primero se vuelve a una alcanzada por toque que aún
                # tenga botones sin probar: forzar la dirección antes de
                # agotar lo tocable haría parecer inalcanzable algo a lo
                # que sí se llega, sólo que por otro camino.
                pendiente = None
                for r in sorted(visitadas):
                    await pag.goto(BASE + "#" + r); await pag.wait_for_timeout(1100)
                    bs = await pag.evaluate("""() => {
                        const f = [];
                        document.querySelectorAll('#contenido button, #contenido [role=button], #cabecera button, .barra button')
                          .forEach(b => { const r = b.getBoundingClientRect();
                            if (r.width && r.height && !b.disabled) f.push((b.innerText||b.getAttribute('aria-label')||'').trim().slice(0,40)); });
                        return f;
                    }""")
                    if any((r, e, 'cont') not in vistos_botones and (r, e, 'cab') not in vistos_botones
                           and (r, e, 'barra') not in vistos_botones and e for e in bs):
                        pendiente = r
                        break
                if pendiente:
                    continue

                sin_ver = [r for r in RUTAS if r not in visitadas and r not in forzadas]
                if not sin_ver:
                    break
                pendientes_forzadas.add(sin_ver[0])
                await pag.goto(BASE + "#" + sin_ver[0]); await pag.wait_for_timeout(1400)
                continue

            antes_url = pag.url
            antes_html = await pag.evaluate(FIRMA)

            # ¿Era ya el filtro puesto? Entonces no tiene que pasar nada.
            #
            # Medir «no cambia nada» a secas daba veintidós botones muertos
            # que no lo estaban: eran «Todas» estando ya en todas, o
            # «Abiertos» estando ya en abiertos. Uno por uno resultaron ser
            # todos lo mismo. Un filtro que al repulsarlo cambiara la lista
            # sería el fallo, no al revés.
            ya_activo = await pag.evaluate("""(etiqueta) => {
                const bs = [...document.querySelectorAll('#contenido button, #contenido [role=button], #cabecera button, .barra button')];
                const b = bs.find(x => ((x.innerText || x.getAttribute('aria-label') || '').trim().slice(0,40)) === etiqueta);
                if (!b) return false;
                return b.matches('.activa, .opcion--activa, .chip--activo, [aria-pressed="true"], [aria-selected="true"]');
            }""", elegido['etiqueta'])
            e0 = len(await pag.evaluate("window.__err || []"))

            try:
                # Se pincha por etiqueta y no por posición: varias
                # pantallas se reordenan al pintarse, y el índice
                # apuntaba a otro botón distinto del elegido.
                await pag.evaluate("""(etiqueta) => {
                    const bs = [...document.querySelectorAll('#contenido button, #contenido [role=button], #cabecera button, .barra button')]
                      .filter(b => { const r = b.getBoundingClientRect(); return r.width && r.height && !b.disabled; });
                    const b = bs.find(x => ((x.innerText || x.getAttribute('aria-label') || '').trim().slice(0,40)) === etiqueta);
                    b?.click();
                }""", elegido['etiqueta'])
            except Exception:
                pass
            await pag.wait_for_timeout(700)

            errs = await pag.evaluate("window.__err || []")
            if len(errs) > e0:
                errores.append((ruta, elegido['etiqueta'], errs[e0][:70]))

            # Cerrar hojas para poder seguir
            if await pag.query_selector('.hoja'):
                await pag.keyboard.press("Escape"); await pag.wait_for_timeout(500)
                continue

            despues_url = pag.url
            despues_html = await pag.evaluate(FIRMA)
            cambio = (antes_url != despues_url) or (antes_html != despues_html)
            if not cambio and not ya_activo and elegido['zona'] == 'cont':
                muertos.append((ruta, elegido['etiqueta']))

        await nav.close()
        return visitadas, forzadas, muertos, errores

async def main():
    correo = sys.argv[1] if len(sys.argv) > 1 else entorno.usuario("admin")
    visitadas, forzadas, muertos, errores = await rastrear(correo)
    delRouter = [r for r in RUTAS if r in visitadas or r in forzadas]
    solo_url = [r for r in RUTAS if r not in visitadas]

    print(f"RASTREO como {correo}")
    print(f"  alcanzadas TOCANDO: {len([r for r in RUTAS if r in visitadas])} de {len(RUTAS)}")
    # Estas cinco sí se alcanzan tocando, pero no desde donde empieza el
    # rastreo: cuatro están dentro del índice de Ajustes (una lista que
    # este rastreador no despliega) y /conflictos sólo existe cuando hay
    # algún conflicto —lo prueba pruebas/sincronizacion.py—. Se comprobó
    # una por una. Si alguna deja de tener entrada, hay que quitarla de
    # aquí para que vuelva a cantar.
    # ¿Tiene entrada desde algún sitio?
    #
    # Antes esto era una lista escrita a mano, y lo que no estaba en ella
    # salía como fallo. El problema: el rastreo entra donde le llevan los
    # botones que encuentra, y eso cambia de una pasada a otra con los
    # datos que haya. Dos pasadas seguidas dieron 23 y 21 rutas
    # alcanzadas, y las dos que faltaban salieron como «no se llega»
    # cuando se llega perfectamente. Una prueba que acusa en falso se
    # acaba ignorando.
    #
    # Así que lo que no se haya pisado se mira en el CÓDIGO: si algo
    # navega a esa ruta —ir('/x'), un enlace #/x, o está en el índice de
    # ajustes— hay forma de llegar, y sólo se deja dicho que esta pasada
    # no pasó por ahí. Si no navega nadie, eso sí es una pantalla
    # huérfana, y entonces falla.
    import glob as _glob
    fuentes = ''
    for f in _glob.glob(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                     '..', 'www', 'assets', 'js', '**', '*.js'),
                        recursive=True):
        try:
            fuentes += open(f, encoding='utf-8').read()
        except Exception:                        # noqa: BLE001
            pass

    def tiene_entrada(r):
        # El índice de Ajustes es una tabla de ['Título', '/ruta', 'palabras
        # para buscar'], así que la ruta aparece ahí entre comillas y nada
        # más: sin esto, /personal y /sap salían como huérfanas estando
        # las dos en el índice. Y /sap aparece como '/sap/habitacion',
        # con algo detrás, de ahí la barra.
        return any(x in fuentes for x in (
            f"ir('{r}')", f'ir("{r}")', f"ir('{r}/", f'href="#{r}"',
            f"'#{r}'", f"'{r}'", f'"{r}"', f"'{r}/", f'"{r}/'))

    huerfanas = [r for r in solo_url if not tiene_entrada(r)]
    sin_pisar = [r for r in solo_url if r not in huerfanas]
    if huerfanas:
        print(f"  NO SE LLEGA TOCANDO ({len(huerfanas)}):")
        for r in huerfanas: print("    ·", r)
    if sin_pisar:
        print(f"  ({len(sin_pisar)} no pisadas en esta pasada, pero el código "
              f"navega a ellas: {', '.join(sin_pisar)})")
    # Botones cuyo efecto NO se ve en la pantalla. No están muertos: bajan
    # un fichero, abren el cuadro de elegir foto del teléfono, o no hacen
    # nada porque el hueco está vacío —«Enviar» sin escribir—. Se probaron
    # por su efecto de verdad y tienen prueba propia: pruebas/descargas.py.
    SIN_EFECTO_VISIBLE = ('Exportar', 'Añadir foto', 'Añadir fotograf', 'Foto', 'Enviar')
    muertos = [(r, e) for r, e in muertos
               if not any(e.strip().startswith(x) for x in SIN_EFECTO_VISIBLE)]
    if muertos:
        print(f"  BOTONES QUE NO HACEN NADA ({len(set(muertos))}):")
        for r, e in sorted(set(muertos))[:14]: print(f"    · {r}  →  «{e}»")
    if errores:
        print(f"  ERRORES ({len(errores)}):")
        for r, e, m in errores[:8]: print(f"    · {r} «{e}»: {m}")
    # Antes aquí ponía «inalcanzables», que no existe: el rastreo hacía
    # todo el trabajo y se caía en la última línea, al dar el resultado.
    # Es decir: esta prueba llevaba tiempo sin decir nada de nada.
    if not huerfanas and not muertos and not errores:
        print("  sin hallazgos")

    return 1 if (huerfanas or muertos or errores) else 0

sys.exit(asyncio.run(main()))
