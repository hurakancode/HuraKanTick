#!/usr/bin/env python3
"""
¿Aguanta con miles de partes y cincuenta personas dentro?

Con los 28 partes de la demostración todo va instantáneo. Los problemas
de rendimiento no aparecen el primer día: aparecen al segundo año, con
veinte mil partes, y entonces ya hay cincuenta personas esperando a que
cargue una lista. Y no avisan: no hay error, sólo lentitud.

Esta prueba los adelanta. Monta una base **aparte** con veinte mil
partes —la de verdad no se toca—, levanta la aplicación apuntando a
ella y cronometra lo que la gente hace todo el día:

  · entrar;
  · abrir la lista de partes, que es la primera pantalla;
  · buscar;
  · abrir un parte con su historial;
  · el panel con sus cuentas;
  · bajarse el catálogo, que es lo que hace cada móvil al arrancar;
  · y la sincronización, que cada móvil repite sola cada pocos minutos.

Los topes son los de la paciencia de una persona, no los de un gráfico:
hasta medio segundo no se nota, un segundo se nota, y de tres segundos
en adelante se piensa que está colgado. Como esto corre con el servidor
de pruebas de PHP, que es de un solo hilo y más lento que Apache, los
tiempos reales serán MEJORES que estos.

   python3 pruebas/rendimiento.py            20 000 partes
   python3 pruebas/rendimiento.py 60000       los que se digan
"""

import json
import os
import shutil
import socket
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
# «cuentas» y no «entorno»: aquí «entorno» ya era el diccionario de
# variables con que se lanza PHP, y el nombre del módulo lo tapaba.
import entorno as cuentas                            # noqa: E402

RAIZ = '/var/www/html/hurakantick'
PUERTO = 8097
BASE = f'http://127.0.0.1:{PUERTO}/api/index.php?_ruta='
CLAVE = cuentas.CLAVE
COPIA = '/tmp/claude-0/rendimiento.sqlite'
COPIA_INSTALACION = '/tmp/claude-0/hurakantick-rendimiento'

# Qué se mide, y a partir de cuándo se nota. En segundos.
#   aviso  = se empieza a notar
#   falla  = la gente piensa que está roto
LIMITES = [
    ('entrar',              'POST', 'auth/login',                 1.5, 3.0),
    ('la lista de partes',  'GET',  'tickets&limite=50',          0.6, 2.0),
    # «desde» es un filtro de FECHA, no un salto de página: para pasar
    # página es «offset». Con «desde=450» la lista salía vacía y el tiempo
    # medido era el de no devolver nada —0,02 s y 0 K, que parecía
    # buenísimo—. Es el error más fácil de cometer en una prueba de
    # rendimiento: cronometrar una consulta que no hace nada.
    ('la lista, página 10', 'GET',  'tickets&limite=50&offset=450', 0.8, 2.5),
    ('la lista, página 200', 'GET', 'tickets&limite=50&offset=9950', 1.0, 3.0),
    ('buscar «agua»',       'GET',  'tickets&limite=50&q=agua',   1.0, 3.0),
    ('buscar por código',   'GET',  'tickets&limite=50&q={CODIGO}', 1.0, 3.0),
    ('el catálogo',         'GET',  'catalogo',                   1.0, 3.0),
    ('el panel',            'GET',  'panel',                      1.2, 3.0),
    ('mis partes',          'GET',  'tickets&limite=50&mios=1',   0.6, 2.0),
    ('bajar para sin red',  'GET',  'sync/pull',                  2.0, 5.0),
]


def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    t = time.time()
    try:
        with urllib.request.urlopen(pet, datos, timeout=120) as r:
            cuerpo = r.read(20000000)
            return time.time() - t, r.status, len(cuerpo)
    except urllib.error.HTTPError as e:
        return time.time() - t, e.code, len(e.read(2000000))
    except Exception:                            # noqa: BLE001
        return time.time() - t, 0, 0


def preparar_mysql(cuantos):
    """Una base MySQL aparte, recién instalada y llena.

    Hasta ahora esto sólo medía SQLite, y con cincuenta personas lo
    normal es MySQL: justo el motor del que no había ni una medida.
    Cambian las dos cosas que más pesan —el planificador y que cada
    consulta sale por la red en vez de leer un fichero— así que los
    números de uno no valen para el otro.
    """
    datos = {
        'driver': 'mysql', 'host': os.environ.get('HURAKANTICK_MY_HOST', '127.0.0.1'),
        'base': os.environ.get('HURAKANTICK_MY_NAME', 'sstt_carga'),
        'usuario': os.environ.get('HURAKANTICK_MY_USER', 'sstt'),
        'clave': os.environ.get('HURAKANTICK_MY_PASS', 'sstt_pruebas'),
    }
    entorno = dict(os.environ,
                   HURAKANTICK_DB_DRIVER='mysql', HURAKANTICK_DB_HOST=datos['host'],
                   HURAKANTICK_DB_NAME=datos['base'], HURAKANTICK_DB_USER=datos['usuario'],
                   HURAKANTICK_DB_PASS=datos['clave'])

    # Sobre una COPIA de la instalación, nunca sobre la de verdad.
    #
    # El instalador deja un api/config.local.php con los datos de la base
    # que se le ha dicho, y ese fichero manda sobre todo lo demás. Al
    # lanzarlo dentro de la instalación real, ésta se quedó apuntando a
    # la base de pruebas: el panel pasó a enseñar sesenta y tres mil
    # partes de mentira y la base de verdad dejó de usarse. No se
    # perdieron datos, pero la instalación quedó mirando a otro sitio, y
    # eso en un servidor del hotel es una tarde perdida.
    copia = COPIA_INSTALACION
    if os.path.isdir(copia):
        shutil.rmtree(copia)
    # Se excluyen los zips por peso, NO la carpeta «paquetes»: dentro
    # están las ubicaciones y el catálogo del hotel, y sin ellos el
    # instalador se planta con «No hay ubicaciones».
    shutil.copytree(RAIZ, copia, ignore=shutil.ignore_patterns(
        '*.sqlite', '*.sqlite-wal', '*.sqlite-shm', '*.zip',
        'uploads', 'copias', '__pycache__'))
    try:
        os.remove(os.path.join(copia, 'api', 'config.local.php'))
    except Exception:                            # noqa: BLE001
        pass

    print(f'Instalando en MySQL ({datos["base"]})…', flush=True)
    r = subprocess.run(['php', os.path.join(copia, 'tools', 'instalar.php'),
                        '--demo', '--forzar'],
                       capture_output=True, text=True, env=entorno,
                       cwd=copia, timeout=1800)
    if r.returncode != 0:
        print('No se ha podido instalar en MySQL:')
        print(((r.stdout or '') + (r.stderr or ''))[-400:])
        return None
    return entorno


def main() -> int:
    argumentos = [a for a in sys.argv[1:] if not a.startswith('-')]
    cuantos = int(argumentos[0]) if argumentos else 20000
    motor = 'mysql' if '--mysql' in sys.argv else 'sqlite'

    if motor == 'mysql':
        entorno = preparar_mysql(cuantos)
        if entorno is None:
            return 2
    else:
        if not os.path.isfile(os.path.join(RAIZ, 'db', 'hurakantick.sqlite')):
            print('No hay instalación SQLite. Para medir MySQL: --mysql')
            return 2
        # Una base aparte: la de verdad no se toca.
        os.makedirs(os.path.dirname(COPIA), exist_ok=True)
        shutil.copy2(os.path.join(RAIZ, 'db', 'hurakantick.sqlite'), COPIA)
        entorno = dict(os.environ, HURAKANTICK_DB_SQLITE=COPIA)

        # Y SOBRE UNA COPIA DEL PROGRAMA, no sobre la instalación.
        #
        # No porque la instalación estorbe —comprobado: en config.php
        # las variables de entorno se aplican DESPUÉS de
        # config.local.php, así que HURAKANTICK_DB_SQLITE manda igual—, sino
        # por higiene: esta prueba levanta un servidor y lo machaca
        # durante minutos, y no tiene por qué hacerlo sobre la carpeta
        # de la que vive el hotel. La vía de MySQL ya lo hacía así.
        if os.path.isdir(COPIA_INSTALACION):
            shutil.rmtree(COPIA_INSTALACION)
        shutil.copytree(RAIZ, COPIA_INSTALACION,
                        ignore=shutil.ignore_patterns(
                            '*.sqlite', '*.sqlite-wal', '*.sqlite-shm',
                            '*.zip', 'uploads', 'copias', '__pycache__'))
        try:
            os.remove(os.path.join(COPIA_INSTALACION, 'api',
                                   'config.local.php'))
        except Exception:                        # noqa: BLE001
            pass

    print(f'Llenando una base aparte con {cuantos} partes…', flush=True)
    donde = COPIA_INSTALACION
    r = subprocess.run(['php', os.path.join(donde, 'pruebas', 'carga.php'),
                        str(cuantos)],
                       capture_output=True, text=True, env=entorno,
                       cwd=donde, timeout=3000)
    if r.returncode != 0:
        print('No se ha podido llenar la base:')
        print((r.stdout or '') + (r.stderr or ''))
        return 1
    for ln in (r.stdout or '').strip().split('\n')[-3:]:
        print('  ' + ln.strip())

    # ¿Está el puerto libre?
    #
    # Si no lo está, `php -S` no arranca y se queda en silencio: la
    # prueba sigue, mide CONTRA LO QUE YA HUBIERA AHÍ, y da unos números
    # estupendos que no son de este programa. Me pasó: tenía un servidor
    # mío de otra prueba en el 8097, y esto estuvo diciendo que la
    # página 10 del listado venía vacía «con veinte mil partes». No
    # venía vacía: estaba preguntándole a otra instalación con cuatro
    # partes. Una hora detrás de un fallo que no existía.
    #
    # Un puerto ocupado no es un detalle del entorno: es la diferencia
    # entre medir y hacer como que se mide.
    with socket.socket() as s0:
        s0.settimeout(2)
        if s0.connect_ex(('127.0.0.1', PUERTO)) == 0:
            print(f'El puerto {PUERTO} ya está ocupado por otro programa.')
            print('Esta prueba mediría contra él y los números no serían de '
                  'HuraKan Tick. Cierra lo que haya ahí y vuelve a lanzarla.')
            return 2

    servidor = subprocess.Popen(
        ['php', '-S', f'127.0.0.1:{PUERTO}', '-t', donde],
        env=entorno, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        preexec_fn=os.setsid)
    try:
        for _ in range(40):
            time.sleep(0.5)
            if llamar('GET', 'catalogo')[1] in (200, 401):
                break
        else:
            print('El servidor de pruebas no arranca.')
            return 1

        t, c, _ = llamar('POST', 'auth/login',
                         {'correo': cuentas.usuario('admin'), 'password': CLAVE})
        if c != 200:
            print(f'No se puede entrar en la base cargada ({c}).')
            return 1
        _, cuerpo, _tam = llamar('POST', 'auth/login',
                                 {'correo': cuentas.usuario('admin'), 'password': CLAVE})
        # El testigo hay que leerlo del cuerpo, así que se repite la llamada.
        pet = urllib.request.Request(BASE + 'auth/login', method='POST')
        pet.add_header('Content-Type', 'application/json')
        with urllib.request.urlopen(
                pet, json.dumps({'correo': cuentas.usuario('admin'),
                                 'password': CLAVE}).encode(), timeout=60) as x:
            ficha = json.loads(x.read(200000))['token']

        # El código a buscar se coge de un parte QUE EXISTE.
        #
        # Lo tenía escrito a mano («T-00099») y en estos datos ese parte no
        # está: la búsqueda devolvía cero, 0 K, en 0,38 s, y pasaba por
        # «bien». Cronometrar una búsqueda que no encuentra nada no mide
        # nada. Además, así se comprueba de paso algo que importa: que
        # buscar el código de un parte encuentre ESE parte, que es lo
        # primero que hace cualquiera con un parte en la mano.
        codigo = None
        pet = urllib.request.Request(BASE + 'tickets&limite=1')
        pet.add_header('Authorization', 'Bearer ' + ficha)
        try:
            with urllib.request.urlopen(pet, None, timeout=60) as x:
                codigo = json.loads(x.read(400000))['tickets'][0]['codigo']
        except Exception:                        # noqa: BLE001
            pass
        if not codigo:
            print('No se ha podido coger el código de un parte: la búsqueda '
                  'por código no se prueba.')

        print(f'\n{"":<22}{"tiempo":>9}  {"tamaño":>9}')
        lentos, rotos = [], []
        for nombre, metodo, ruta, aviso, falla in LIMITES:
            if '{CODIGO}' in ruta:
                if not codigo:
                    continue
                ruta = ruta.replace('{CODIGO}', codigo)
            cuerpo = ({'correo': 'admin', 'password': CLAVE}
                      if ruta == 'auth/login' else None)
            # Dos veces: la primera paga el arranque de PHP y de los
            # índices, y no es la que vive la gente.
            llamar(metodo, ruta, cuerpo, ficha)
            mejor = min(llamar(metodo, ruta, cuerpo, ficha) for _ in range(3))
            t, cod, tam = mejor

            # Una consulta que no devuelve nada no se ha medido: puede ir
            # rapidísima porque no hace nada.
            if cod == 200 and tam < 400 and ('lista' in nombre or 'código' in nombre):
                rotos.append(f'{nombre}: la respuesta viene casi vacía '
                             f'({tam} bytes), así que ese tiempo no mide nada.')
            if cod == 0 or cod >= 500:
                marca = f'ERROR {cod}'
                rotos.append(f'{nombre}: el servidor devuelve {cod} con '
                             f'{cuantos} partes.')
            elif t > falla:
                marca = 'MUY LENTO'
                lentos.append(f'{nombre}: {t:.2f} s. Por encima de {falla} s la '
                              'gente piensa que está colgado.')
            elif t > aviso:
                marca = 'se nota'
                lentos.append(f'{nombre}: {t:.2f} s, y a partir de {aviso} s se '
                              'nota la espera.')
            else:
                marca = 'bien'
            print(f'{nombre:<22}{t:>8.2f}s  {tam / 1024:>8.0f}K  {marca}')

        # Lo que se baja cada móvil importa tanto como el tiempo: con
        # datos de móvil, dos megas por arranque son dinero de alguien.
        t, cod, tam = llamar('GET', 'catalogo', None, ficha)
        if tam > 3 * 1024 * 1024:
            lentos.append(f'El catálogo que se baja cada móvil al arrancar pesa '
                          f'{tam / 1048576:.1f} MB. Con datos de móvil eso se nota '
                          'en la factura, y se baja muchas veces al día.')

        print(f'\nMedido con {cuantos} partes '
              '(y con el servidor de pruebas de PHP, más lento que Apache).')
        print(f'  por encima del tope: {len(lentos)}')
        print(f'  que fallan:          {len(rotos)}')
        for titulo, lista in (('SE NOTA LA ESPERA', lentos),
                              ('NO AGUANTA', rotos)):
            if lista:
                print(f'\n{titulo}:')
                for x in lista:
                    print('  ·', x)
        if not lentos and not rotos:
            print('\nCon veinte mil partes sigue respondiendo como el primer día.')
        return 1 if (lentos or rotos) else 0
    finally:
        try:
            os.killpg(os.getpgid(servidor.pid), signal.SIGTERM)
        except Exception:                        # noqa: BLE001
            pass
        for x in (COPIA, COPIA + '-wal', COPIA + '-shm'):
            try:
                os.remove(x)
            except Exception:                    # noqa: BLE001
                pass


if __name__ == '__main__':
    sys.exit(main())
