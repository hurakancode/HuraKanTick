#!/usr/bin/env python3
"""
Lo que sólo funciona con certificado: sin red, y los avisos al móvil.

Media aplicación cuelga de esto y nunca se había podido probar, porque
sin https el navegador ni siquiera entrega el service worker. Por eso
estaba todo el rato el mismo FALLA en el diagnóstico y nadie sabía si,
puesto el certificado, las dos cosas iban a funcionar de verdad.

Con un certificado —vale uno hecho a mano para probar— se puede
comprobar entero:

  1. que el navegador registra el service worker y guarda la aplicación;
  2. que **se abre sin red**: se corta la red del navegador y la
     aplicación tiene que arrancar igual, que es lo que promete;
  3. que lo que se hace sin cobertura se queda en la cola y no se pierde;
  4. que el móvil se puede suscribir a los avisos y la suscripción
     **llega al servidor**;
  5. y que el servidor, al mandar un aviso, lo intenta de verdad y
     apunta el resultado en vez de callarse.

Para montarlo en este equipo:

    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \\
      -keyout /etc/ssl/sstt/hurakantick.key -out /etc/ssl/sstt/hurakantick.crt \\
      -subj "/CN=localhost"

y un VirtualHost en el 443 apuntando a /var/www/html.

   python3 pruebas/sincertificado.py
"""

import asyncio
import json
import os
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

SEGURA = os.environ.get('HURAKANTICK_HTTPS', 'https://127.0.0.1/hurakantick/www/index.html')
API = os.environ.get('HURAKANTICK_API_HTTPS',
                     'https://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
def api(metodo, ruta, cuerpo=None, token=None):
    import ssl
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=60, context=ctx) as r:
            return r.status, r.read(2000000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(900000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


async def main() -> int:
    c, cuerpo = api('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print(f'No se llega a la aplicación por https ({c}).')
        print('Esta prueba necesita un certificado. Mira la cabecera del '
              'fichero para montarlo.')
        return 2
    ficha = json.loads(cuerpo)['token']

    fallos, sin_probar = [], []
    from playwright.async_api import async_playwright
    async with async_playwright() as p:
        # Con un certificado hecho a mano, Chromium NO entrega el service
        # worker aunque se le diga que ignore el aviso de la página: para
        # eso hace falta --ignore-certificate-errors. Sin esta bandera la
        # prueba decía «el service worker no se registra» y parecía un
        # fallo de la aplicación; con un certificado de verdad —el que va
        # a tener el hotel— no hace falta nada de esto.
        nav = await p.chromium.launch(args=[
            '--ignore-certificate-errors',
            '--allow-insecure-localhost',
            '--unsafely-treat-insecure-origin-as-secure=https://127.0.0.1',
        ])
        ctx = await nav.new_context(
            ignore_https_errors=True, viewport={'width': 390, 'height': 844},
            locale='es-ES', is_mobile=True, has_touch=True,
            permissions=['notifications'])
        pag = await ctx.new_page()
        pag.set_default_timeout(30000)
        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:150]))

        await pag.goto(SEGURA)
        await pag.wait_for_timeout(2500)
        await pag.fill('#correo', entorno.usuario('admin'))
        await pag.fill('#password', CLAVE)
        await pag.click('#b-entrar')
        await pag.wait_for_timeout(10000)

        # ---- 1. ¿Se registra el service worker? ------------------------
        estado = await pag.evaluate("""async () => {
            if (!('serviceWorker' in navigator)) { return 'no existe'; }
            const r = await navigator.serviceWorker.getRegistration();
            if (!r) { return 'no registrado'; }
            return r.active ? 'activo' : (r.installing ? 'instalando' : 'esperando');
        }""")
        if estado != 'activo':
            fallos.append(f'El service worker no llega a activarse ({estado}): '
                          'sin él no hay avisos ni modo sin cobertura.')
        print(f'  service worker: {estado}', flush=True)

        guardado = await pag.evaluate("async () => (await caches.keys()).join(',')")
        if not guardado:
            fallos.append('El navegador no ha guardado nada: la aplicación no '
                          'podrá abrirse sin red.')
        print(f'  guardado en el móvil: {guardado or "(nada)"}', flush=True)

        cuantos = await pag.evaluate("""async () => {
            const k = (await caches.keys())[0];
            if (!k) { return 0; }
            return (await (await caches.open(k)).keys()).length;
        }""")
        print(f'  ficheros guardados: {cuantos}', flush=True)
        if cuantos < 5:
            fallos.append(f'Sólo se han guardado {cuantos} ficheros: con eso la '
                          'aplicación no arranca sin red.')

        # ---- 2. Sin red: tiene que abrirse igual -----------------------
        #
        # ¿Controla el service worker esta página? Registrado no es lo
        # mismo que al mando: la primera vez que se abre, la página ya
        # venía de la red y el service worker no la gobierna hasta la
        # siguiente carga. Si no controla, no hay quien sirva nada sin red.
        manda = await pag.evaluate(
            "()=>!!(navigator.serviceWorker && navigator.serviceWorker.controller)")
        print(f'  el service worker manda en la página: {"sí" if manda else "no"}', flush=True)
        if not manda:
            fallos.append('El service worker está registrado pero no controla la '
                          'página, así que sin red no hay quien sirva la '
                          'aplicación.')

        await ctx.set_offline(True)
        try:
            await pag.goto(SEGURA)
        except Exception as e:                   # noqa: BLE001
            # Que la navegación ni siquiera llegue es el peor caso: el
            # service worker no ha interceptado nada.
            print(f'  sin red, al abrir: {str(e)[:70]}')
        await pag.wait_for_timeout(6000)
        abierta = await pag.evaluate("""() => {
            const t = document.body.innerText || '';
            if (/sin conexión|no se puede acceder|ERR_INTERNET|not available/i.test(t)
                && t.length < 400) { return 'pantalla de error del navegador'; }
            return document.querySelector('#contenido') || document.querySelector('#correo')
                 ? 'la aplicación' : 'nada';
        }""")
        print(f'  sin red se abre: {abierta}', flush=True)
        if abierta != 'la aplicación':
            fallos.append('Sin red la aplicación NO se abre: sale la pantalla de '
                          'error del navegador. El modo sin cobertura no sirve.')

        # ---- 3. Lo hecho sin red se queda en la cola -------------------
        if abierta == 'la aplicación':
            en_cola = await pag.evaluate("""async () => {
                try {
                    const bases = await indexedDB.databases();
                    return bases.map((b) => b.name).join(',');
                } catch (e) { return 'no se puede mirar'; }
            }""")
            print(f'  guarda sus datos en: {en_cola or "(nada)"}', flush=True)
            if not en_cola:
                fallos.append('Sin red no hay ninguna base de datos en el móvil: '
                              'lo que se haga no se va a poder guardar.')

        await ctx.set_offline(False)
        await pag.goto(SEGURA)
        await pag.wait_for_timeout(8000)

        # ---- 4. Activar los avisos, por donde se activan ---------------
        #
        # No se suscribe solo, y hace bien: el navegador exige que lo pida
        # una persona. El interruptor está en Ajustes → Avisos, así que se
        # pulsa ése, que es lo que hará quien lo use.
        await pag.goto(SEGURA + '#/avisos-ajustes')
        await pag.reload()
        await pag.wait_for_timeout(6000)

        hay = await pag.evaluate("()=>!!document.querySelector('#push-int')")
        if not hay:
            fallos.append('No aparece el interruptor de avisos en Ajustes → '
                          'Avisos, así que no hay forma de activarlos.')
        else:
            await pag.click('#push-int')
            await pag.wait_for_timeout(9000)
            dijo = await pag.evaluate(
                "()=>(document.querySelector('#push-msg')||{}).innerText||''")
            print(f'  al activar los avisos dice: {dijo.strip()[:110]}', flush=True)

            suscrito = await pag.evaluate("""async () => {
                const r = await navigator.serviceWorker.ready;
                const s = await r.pushManager.getSubscription();
                return s ? s.endpoint.slice(0, 48) : '';
            }""")
            if not suscrito:
                # Un navegador de pruebas sin escritorio detrás NO puede
                # darse de alta en el servicio de Google: contesta
                # «permission denied» aunque el permiso esté concedido.
                # Eso es del navegador, no de la aplicación, y decir que
                # los avisos no funcionan sería mentir.
                del_navegador = any(x in dijo.lower() for x in
                                    ('permission denied', 'registration failed',
                                     'not supported', 'abort'))
                if del_navegador:
                    sin_probar.append(
                        'La suscripción a los avisos no se puede probar en este '
                        f'navegador de pruebas («{dijo.strip()[:60]}»). Hay que '
                        'probarla desde un móvil de verdad.')
                else:
                    fallos.append('Se pulsa el interruptor de avisos y el '
                                  'navegador no queda suscrito. Dijo: '
                                  f'«{dijo.strip()[:90]}»')
            else:
                print(f'  suscrito en: {suscrito}…', flush=True)

            # Y lo que importa: que la suscripción haya LLEGADO al servidor.
            # Si se queda en el móvil, el servidor no tiene a dónde avisar.
            c, cuerpo = api('GET', 'push/clave', None, ficha)
            if not (200 <= c < 300):
                fallos.append(f'La aplicación no puede pedir la clave de avisos '
                              f'({c}): nadie podrá suscribirse.')

            destinos = -1
            import sqlite3 as _sq
            bd = entorno.DIR + '/db/hurakantick.sqlite'
            if os.path.isfile(bd):
                try:
                    con = _sq.connect(bd)
                    destinos = con.execute(
                        'SELECT COUNT(*) FROM push_destinos').fetchone()[0]
                    con.close()
                except Exception:                # noqa: BLE001
                    destinos = -1
            print(f'  aparatos apuntados en el servidor: {destinos}', flush=True)
            if suscrito and destinos == 0:
                fallos.append('El móvil se ha suscrito pero el servidor no ha '
                              'apuntado el aparato: los avisos no saldrán de aquí.')

        # ---- 5. Mandar un aviso de verdad ------------------------------
        #
        # Llegar al teléfono depende del servicio de Google o de Apple, que
        # desde este equipo puede no alcanzarse. Lo que se mira es que el
        # servidor lo INTENTE y diga qué ha pasado, en vez de contestar que
        # sí sin haber hecho nada —que es lo que no se podría distinguir
        # luego, cuando los avisos no lleguen y nadie sepa por qué.
        c, cuerpo = api('POST', 'push/probar', {}, ficha)
        print(f'  mandar un aviso de prueba: {c} · {cuerpo[:160]}', flush=True)
        if c == 0:
            fallos.append('Mandar un aviso de prueba no contesta nada.')
        elif 200 <= c < 300:
            try:
                d = json.loads(cuerpo)
            except Exception:                    # noqa: BLE001
                d = {}
            # Un «ok» a secas, sin decir a cuántos aparatos ha salido ni
            # qué ha contestado el servicio, no sirve para diagnosticar.
            if not any(k in cuerpo for k in ('enviados', 'destinos', 'fallos',
                                             'resultado', 'detalle', 'aparatos')):
                fallos.append('El aviso de prueba contesta «ok» sin decir a '
                              'cuántos aparatos ha salido ni qué ha contestado '
                              'el servicio: el día que no lleguen, no habrá por '
                              'dónde empezar a mirar.')

        if errores_js:
            fallos.append(f'La aplicación da errores por https: {errores_js[0]}')

        await nav.close()

    print('\nProbado con certificado: service worker, arranque sin red, '
          'datos en el móvil\ny avisos.')
    print(f'  que sale mal: {len(fallos)}')
    if sin_probar:
        print('\nLO QUE NO SE HA PODIDO PROBAR AQUÍ:')
        for f in sin_probar:
            print('  ·', f)
    if fallos:
        print('\nLO QUE NO FUNCIONA NI CON CERTIFICADO:')
        for f in fallos:
            print('  ·', f)
        return 1
    if sin_probar:
        print('\nCon certificado, la aplicación se guarda y abre sin red. Los '
              'avisos\nhay que probarlos desde un móvil de verdad.')
    else:
        print('\nCon certificado, la aplicación se guarda, abre sin red y '
              'avisa.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
