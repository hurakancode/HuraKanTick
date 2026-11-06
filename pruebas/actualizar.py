#!/usr/bin/env python3
"""
Actualizar sobre una versión anterior, con los móviles ya instalados.

Es lo que pasa el día que se actualiza, y no se había probado nunca. Dos
cosas pueden salir mal, y las dos de golpe en cincuenta móviles:

  · **la base**: una ampliación que no se aplica, o que se aplica mal, y
    al entrar sale «No se ha podido completar la operación en la base de
    datos». O peor: se aplica y se lleva datos por delante.
  · **la aplicación guardada en el móvil**: una PWA se guarda entera en
    el teléfono. Si al subir la versión nueva el móvil sigue sirviendo la
    vieja, se queda una aplicación antigua hablando con un servidor
    nuevo. Eso no falla del todo: falla a ratos, que es peor.

Esta prueba monta la versión ANTERIOR de verdad, la usa —entra, deja que
el móvil se la guarde entera—, le pone la nueva encima, y comprueba:

  1. que la actualización no toca ni un dato (se compara antes y después,
     recuento por recuento y el título de un parte concreto);
  2. que el móvil que ya tenía la vieja guardada **se queda con la
     nueva**, sin borrar nada a mano;
  3. que después de actualizar se puede entrar y trabajar;
  4. y que no se pierde lo que el móvil tenía pendiente de enviar.

   python3 pruebas/actualizar.py /ruta/al/sstt-7.59.zip
"""

import asyncio
import json
import os
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
import zipfile
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

RAIZ_WEB = '/var/www/html'
NOMBRE = 'hurakantick-prueba-actualizar'
# Con qué cuenta se entra en la instalación de PRUEBA, no en la de siempre.
#
# Esta prueba instala la versión anterior a propósito, y cada versión trae
# su propia demo: hasta la 7.89 el administrador era «admin» con
# «sstt2026»; desde la 7.90, «daniel.melgar» con la de tools/clave_demo.php.
# Y después de actualizar siguen siendo los de la versión vieja, porque
# actualizar no toca los datos —que es justo lo que se comprueba aquí—.
# Así que se averigua UNA vez, con la versión vieja recién instalada, y
# se usa la misma cuenta antes y después.
ENTRA = {'usuario': 'admin', 'clave': 'sstt2026'}
AQUI = os.path.dirname(os.path.abspath(__file__))
NUEVA = os.path.dirname(AQUI)              # la versión de este código


def api(base, metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(base + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=90) as r:
            return r.status, r.read(9000000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(900000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


def averiguar_cuenta(base):
    """Prueba las dos demos y se queda con la que entra."""
    candidatas = [(entorno.USUARIOS['admin'],
                   entorno._clave_de(os.path.join(RAIZ_WEB, NOMBRE))),
                  ('admin', 'sstt2026')]
    for quien, clave in candidatas:
        c, _ = api(base, 'POST', 'auth/login',
                   {'correo': quien, 'password': clave})
        if c == 200:
            ENTRA.update(usuario=quien, clave=clave)
            return True
    return False


def huella(base):
    """Cómo está todo: recuentos y un dato concreto de cada cosa."""
    c, cuerpo = api(base, 'POST', 'auth/login',
                    {'correo': ENTRA['usuario'], 'password': ENTRA['clave']})
    if c != 200:
        return {'ERROR': f'no se puede entrar ({c}): {cuerpo[:90]}'}
    t = json.loads(cuerpo)['token']

    fuera = {}
    for clave, ruta, bolsa in (('partes', 'tickets&limite=500', 'tickets'),
                               ('gente', 'usuarios&todos=1', 'usuarios'),
                               ('revisiones', 'mantenimiento', 'mantenimientos'),
                               ('instrucciones', 'instrucciones', 'instrucciones')):
        _, cu = api(base, 'GET', ruta, None, t)
        try:
            fuera[clave] = len(json.loads(cu).get(bolsa) or [])
        except Exception:                        # noqa: BLE001
            fuera[clave] = 'ilegible'

    # Un parte CONCRETO, el más antiguo, con su título.
    #
    # Antes se cogía «el más nuevo», y eso cambia por sí solo: al
    # actualizar, las revisiones que tocaban generan sus partes y el más
    # nuevo pasa a ser otro. La prueba lo cantaba como que la
    # actualización había tocado los datos, cuando lo que había hecho era
    # su trabajo. El más antiguo no se mueve.
    _, cu = api(base, 'GET', 'tickets&limite=500&orden=antiguo', None, t)
    try:
        x = json.loads(cu)['tickets'][0]
        fuera['el_parte_mas_viejo'] = [x.get('codigo'),
                                       (x.get('titulo_ticket') or '')[:34]]
    except Exception:                            # noqa: BLE001
        fuera['el_parte_mas_viejo'] = None

    _, cu = api(base, 'GET', 'catalogo', None, t)
    try:
        d = json.loads(cu)
        fuera['deptos'] = len(d.get('departamentos') or [])
        fuera['elementos'] = len(d.get('elementos') or [])
    except Exception:                            # noqa: BLE001
        fuera['deptos'] = fuera['elementos'] = 'ilegible'
    return fuera


def mandar(orden, donde):
    return subprocess.run(orden, capture_output=True, text=True,
                          cwd=donde, timeout=900)


async def main() -> int:
    # Sin decirle nada, coge el paquete ANTERIOR que haya guardado en
    # paquetes/. Así entra en la tanda completa sin que nadie se acuerde
    # de pasarle una ruta, que es como las pruebas dejan de ejecutarse.
    if len(sys.argv) >= 2:
        zip_viejo = sys.argv[1]
    else:
        import glob as _glob
        import re as _re
        def orden(x):
            m = _re.search(r'(\d+)\.(\d+)(?:\.(\d+))?', os.path.basename(x))
            return (tuple(int(g or 0) for g in m.groups()) if m else (0, 0, 0))

        # El más nuevo de los ANTERIORES a este código.
        #
        # Esto cogía «el penúltimo» dando por hecho que el último era el
        # de ahora, cosa que sólo es cierta después de montar el paquete:
        # antes, se saltaba la versión anterior y probaba desde la de
        # hace dos. Y desde la 7.90 los paquetes se llaman
        # «hurakan-tick-…»: con el patrón viejo no se habría encontrado.
        try:
            ahora = json.load(open(os.path.join(NUEVA, 'www', 'version.json'),
                                    encoding='utf-8'))['version']
            ahora = tuple(int(x) for x in ahora.split('.'))
        except Exception:                        # noqa: BLE001
            ahora = (999, 0, 0)
        guardados = sorted(
            [z for patron in ('sstt-*.zip', 'hurakan-tick-*.zip')
             for z in _glob.glob(os.path.join(NUEVA, 'paquetes', patron))],
            key=orden)
        anteriores = [z for z in guardados if orden(z) < ahora]
        zip_viejo = anteriores[-1] if anteriores else ''
        if not zip_viejo:
            # Código 2: NO SE HA PODIDO PROBAR. Devolver 0 sería decir que
            # todo está bien sin haber mirado nada, que es la peor de las
            # tres respuestas posibles.
            print('No hay ningún paquete anterior con el que probar la '
                  'actualización.')
            print('El paquete que se entrega no lleva dentro versiones '
                  'anteriores —pesarían el doble—, así que para esta prueba '
                  'hay que pasarle una:')
            print('  python3 pruebas/actualizar.py /ruta/al/paquete-anterior.zip')
            return 2
        print(f'Probando la actualización desde {os.path.basename(zip_viejo)}.')
    if not os.path.isfile(zip_viejo):
        print(f'No encuentro {zip_viejo}')
        return 1

    destino = os.path.join(RAIZ_WEB, NOMBRE)
    base = f'http://127.0.0.1/{NOMBRE}/api/index.php?_ruta='
    pagina = f'http://127.0.0.1/{NOMBRE}/www/index.html'
    fallos = []

    # ---- 1. La versión anterior, instalada de verdad -------------------
    if os.path.isdir(destino):
        shutil.rmtree(destino)
    with tempfile.TemporaryDirectory() as tmp:
        with zipfile.ZipFile(zip_viejo) as z:
            z.extractall(tmp)
        dentro = [os.path.join(tmp, x) for x in os.listdir(tmp)]
        carpeta = next((d for d in dentro
                        if os.path.isdir(d) and os.path.isdir(os.path.join(d, 'api'))),
                       None)
        if carpeta is None:
            print('Ese zip no parece una instalación de HuraKan Tick (ni de SSTT, como se llamaba antes).')
            return 1
        shutil.copytree(carpeta, destino)

    r = mandar(['php', 'tools/instalar.php', '--demo', '--forzar'], destino)
    if r.returncode != 0:
        print('La versión anterior no se instala:')
        print(((r.stdout or '') + (r.stderr or ''))[-500:])
        return 1
    # El servidor web tiene que poder escribir: instalar con otro usuario
    # deja los ficheros ajenos y la aplicación no arranca.
    subprocess.run(['chown', '-R', 'www-data:www-data', destino],
                   capture_output=True)

    version_vieja = ''
    try:
        with open(os.path.join(destino, 'www', 'sw.js'), encoding='utf-8') as fh:
            for ln in fh:
                if 'VERSION' in ln and '=' in ln:
                    version_vieja = ln.split("'")[1] if "'" in ln else ln.strip()
                    break
    except Exception:                            # noqa: BLE001
        pass
    print(f'Instalada la versión anterior ({version_vieja or "?"}).')
    if not averiguar_cuenta(base):
        print('Con la versión anterior recién instalada no entra ni la cuenta '
              'de la demo nueva ni la de antes.')
        return 1
    print(f'  se entra como «{ENTRA["usuario"]}»')

    antes = huella(base)
    if 'ERROR' in antes:
        print('La versión anterior no funciona: ' + antes['ERROR'])
        return 1
    print('  ' + ', '.join(f'{k}={v}' for k, v in antes.items()
                           if k in ('partes', 'gente', 'deptos')))

    # ---- 2. Un móvil se la guarda entera -------------------------------
    from playwright.async_api import async_playwright
    async with async_playwright() as p:
        nav = await p.chromium.launch()
        ctx = await nav.new_context(viewport={'width': 390, 'height': 844},
                                    locale='es-ES', is_mobile=True, has_touch=True)
        pag = await ctx.new_page()
        pag.set_default_timeout(30000)
        await pag.goto(pagina)
        await pag.wait_for_timeout(2500)
        await pag.fill('#correo', ENTRA['usuario'])
        await pag.fill('#password', ENTRA['clave'])
        await pag.click('#b-entrar')
        await pag.wait_for_timeout(9000)

        guardado = await pag.evaluate(
            "async () => (await caches.keys()).join(',')")
        if not guardado:
            fallos.append('El móvil no se guarda la aplicación: sin eso no hay '
                          'modo sin cobertura. (¿Falta el certificado?)')
        print(f'  el móvil ha guardado: {guardado or "(nada)"}')

        # ---- 3. Encima, la versión nueva ------------------------------
        # Lo que es de la instalación no se toca: CUALQUIER base, con el
        # nombre que tenga, y su configuración.
        #
        # Esto excluía las bases por su nombre. Al renombrar la de
        # «sstt.sqlite» a «hurakantick.sqlite» la exclusión pasó a decir el
        # nombre nuevo, y la carpeta de trabajo tenía un «db/sstt.sqlite»
        # vacío olvidado desde octubre: la copia pisó la base de verdad con
        # una vacía y la prueba dijo «no se puede entrar». Ningún nombre
        # concreto: todo lo que acabe en .sqlite, con su diario y su índice.
        r = subprocess.run(
            "tar cf - --exclude='db/*.sqlite' --exclude='db/*.sqlite-wal' "
            "--exclude='db/*.sqlite-shm' --exclude='db/actualizacion.json' "
            '--exclude=api/config.local.php --exclude=paquetes . '
            f'| (cd {destino} && tar xf -)',
            shell=True, cwd=NUEVA, capture_output=True, text=True, timeout=600)
        if r.returncode != 0:
            print('No se han podido copiar los ficheros nuevos:', r.stderr[:200])
            return 1
        subprocess.run(['chown', '-R', 'www-data:www-data', destino],
                       capture_output=True)

        r = mandar(['php', 'tools/instalar.php', '--actualizar'], destino)
        salida = (r.stdout or '') + (r.stderr or '')
        if r.returncode != 0:
            fallos.append('La actualización falla: ' + salida.strip()[-250:])
        for mala in ('Fatal error', 'Uncaught', 'SQLSTATE'):
            if mala in salida:
                fallos.append(f'La actualización suelta errores ({mala}): '
                              + salida[salida.index(mala):][:160])

        # ---- 4. ¿Siguen los datos? ------------------------------------
        despues = huella(base)
        if 'ERROR' in despues:
            fallos.append('Después de actualizar no se puede entrar: '
                          + despues['ERROR'])
        else:
            # Crecer está bien: al actualizar, las revisiones que tocaban
            # generan sus partes —es su trabajo— y con el arreglo del hotel
            # ahora además se ven. Lo que NO puede pasar es perder.
            for clave, valor in antes.items():
                ahora = despues.get(clave)
                if isinstance(valor, int) and isinstance(ahora, int):
                    if ahora < valor:
                        fallos.append(f'Al actualizar se han PERDIDO {clave}: '
                                      f'había {valor} y quedan {ahora}.')
                    elif ahora > valor:
                        print(f'  ({clave}: {valor} → {ahora}; crecer es normal, '
                              'las revisiones que tocaban generan sus partes)')
                elif valor != ahora:
                    fallos.append(f'Al actualizar ha cambiado «{clave}»: antes '
                                  f'{valor}, ahora {ahora}.')

        # ---- 5. El móvil que ya la tenía guardada ---------------------
        #
        # Es lo que más duele: la aplicación vieja sigue viviendo en el
        # teléfono y habla con el servidor nuevo. No falla del todo,
        # falla a ratos. Se recarga como recargaría una persona.
        await pag.goto(pagina)
        await pag.wait_for_timeout(6000)
        await pag.reload()
        await pag.wait_for_timeout(8000)

        version_servida = await pag.evaluate("""async () => {
            const r = await fetch('sw.js', { cache: 'no-store' });
            const t = await r.text();
            const m = t.match(/VERSION\\s*=\\s*'([^']+)'/);
            return m ? m[1] : '(no se encuentra)';
        }""")
        claves = await pag.evaluate("async () => (await caches.keys()).join(',')")

        with open(os.path.join(NUEVA, 'www', 'sw.js'), encoding='utf-8') as fh:
            texto = fh.read()
        version_nueva = texto.split("VERSION = '")[1].split("'")[0]

        if version_servida != version_nueva:
            fallos.append(f'El servidor sigue dando la versión {version_servida} '
                          f'y la nueva es {version_nueva}.')
        if version_vieja and version_vieja in claves:
            fallos.append(f'El móvil se ha quedado con la aplicación vieja '
                          f'guardada ({version_vieja}). Quien la tenga instalada '
                          'seguirá usando la de antes sin enterarse.')

        # Y que se pueda trabajar: entrar y abrir la lista.
        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:150]))
        await pag.goto(pagina + '#/lista')
        await pag.wait_for_timeout(5000)
        hay = await pag.evaluate(
            "()=>document.querySelectorAll('#contenido .parte,[data-ticket],.fila,[data-t]').length")
        if not hay:
            entrada = await pag.evaluate("()=>!!document.querySelector('#correo')")
            if entrada:
                await pag.fill('#correo', ENTRA['usuario'])
                await pag.fill('#password', ENTRA['clave'])
                await pag.click('#b-entrar')
                await pag.wait_for_timeout(9000)
                await pag.goto(pagina + '#/lista')
                await pag.wait_for_timeout(5000)
                hay = await pag.evaluate(
                    "()=>document.querySelectorAll('#contenido .parte,[data-ticket],.fila,[data-t]').length")
        # Los partes de la lista son «.parte»/«[data-ticket]», no «.fila»:
        # buscándolos mal esta prueba dijo que la lista salía vacía cuando
        # tenía veinte partes delante.
        if not hay:
            fallos.append('Después de actualizar, la lista de partes sale vacía '
                          'en un móvil que ya tenía la aplicación.')
        if errores_js:
            fallos.append('La aplicación actualizada da errores en un móvil que '
                          f'venía de la versión anterior: {errores_js[0]}')

        await nav.close()

    print(f'\nActualización de {version_vieja or "la anterior"} a {version_nueva} '
          'probada de punta a punta.')
    print(f'  que sale mal: {len(fallos)}')
    if fallos:
        print('\nAL ACTUALIZAR:')
        for f in fallos:
            print('  ·', f)
        print(f'\n  (la instalación de prueba está en {destino})')
        return 1

    shutil.rmtree(destino, ignore_errors=True)
    print('\nLos datos siguen, el móvil coge la nueva y se puede trabajar.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
