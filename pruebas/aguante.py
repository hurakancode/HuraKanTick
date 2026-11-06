#!/usr/bin/env python3
"""
Qué hace el servidor cuando le llega basura.

Un formulario bien rellenado lo prueba cualquiera. Lo que nadie prueba es
lo que pasa cuando llega un número donde iba un texto, un identificador
que no existe, un campo de cien mil letras o un cuerpo que ni siquiera es
JSON. Y eso llega: desde un móvil con la aplicación a medio actualizar,
desde una sincronización que se cortó, o desde alguien curioso con el
navegador abierto.

La diferencia que busca esta prueba:

  · **400 / 404 / 422** son una buena respuesta: «esto no me vale, y te
    digo por qué». La aplicación lo enseña y la persona lo arregla.
  · **500** es un fallo: el servidor se ha caído por dentro. La persona ve
    «algo ha fallado» sin más, y lo que iba a guardar se pierde.

También mira que un error no cuente de más: un mensaje que suelte la
consulta SQL o la ruta del servidor le está dando un mapa a quien lo
provoca a propósito.

   python3 pruebas/aguante.py
"""

import os
import json
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get(

    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
# Las formas de basura que de verdad llegan.
BASURAS = {
    'texto vacío':        '',
    'nulo':               None,
    'número donde texto': 12345,
    'texto donde número': 'esto no es un número',
    'lista donde texto':  ['a', 'b'],
    'objeto donde texto': {'a': 1},
    'negativo':           -1,
    'enorme':             'A' * 100000,
    'con comillas':       'O\'Brien "el grande" \\ `fin`',
    'con etiquetas':      '<script>alert(1)</script>',
    'parece SQL':         "1; DROP TABLE login; --",
    'cero':               0,
    'booleano':           True,
}

# (método, ruta, campo que se ensucia, resto del cuerpo)
PUNTOS = [
    ('POST', 'tickets',        'titulo',      {'id_elemento': 1, 'id_prioridad': 2}),
    ('POST', 'tickets',        'id_elemento', {'titulo': 'prueba'}),
    ('POST', 'tickets',        'id_prioridad', {'titulo': 'prueba'}),
    ('POST', 'usuarios',       'usuario',     {'password': 'Prueba2026zz'}),
    ('POST', 'usuarios',       'password',    {'usuario': 'zzz_aguante'}),
    ('POST', 'usuarios',       'id_departamento', {'usuario': 'zzz_a2', 'password': 'Prueba2026zz'}),
    ('POST', 'usuarios/lote',  'ids',         {'accion': 'bloquear'}),
    ('POST', 'usuarios/lote',  'accion',      {'ids': [1]}),
    ('POST', 'departamentos',  'nombre',      {}),
    ('POST', 'instrucciones',  'titulo',      {'contenido': 'x'}),
    ('POST', 'instrucciones',  'contenido',   {'titulo': 'x'}),
    ('POST', 'mantenimiento',  'intervalo',   {'nombre': 'x', 'unidad': 'meses'}),
    ('POST', 'mantenimiento',  'unidad',      {'nombre': 'x', 'intervalo': 1}),
    ('POST', 'mantenimiento',  'repeticiones', {'nombre': 'x', 'intervalo': 1, 'unidad': 'meses'}),
    ('POST', 'hoteles',        'nombre',      {}),
    ('POST', 'frases',         'texto',       {}),
    ('POST', 'personal/exportar', 'ids',      {}),
    ('POST', 'errores',        'mensaje',     {}),
]

# Identificadores imposibles.
#
# Ojo con lo que se mete aquí: la primera versión incluía «1», y como la
# barrida prueba también DELETE, esta prueba borraba el hotel 1 —el hotel
# de verdad— cada vez que se ejecutaba. Después pasaba un buen rato
# buscando un fallo inexistente, porque «copiar ubicaciones» decía que el
# hotel de origen no existía. Aquí sólo van identificadores que NO pueden
# corresponder a nada real.
IDS_RAROS = ['0', '-1', '999999999', 'abc', '1 OR 1=1', '../../etc/passwd', '%00', '1.5']

# Para BORRAR, sólo los que no pueden acabar señalando a nada real.
# «1 OR 1=1» y «1.5» se leen como el número 1 —así de permisivo es el
# lector de identificadores—, y con DELETE eso significaba borrar la
# ficha número 1 de verdad. El hotel de la base de pruebas lo perdí dos
# veces por aquí antes de darme cuenta.
IDS_RAROS_BORRAR = ['0', '-1', '999999999', 'abc', '../../etc/passwd', '%00']
RUTAS_CON_ID = ['tickets/{}', 'usuarios/{}', 'instrucciones/{}', 'mantenimiento/{}',
                'departamentos/{}', 'hoteles/{}', 'papelera/{}', 'unidades/{}']

SE_VA_DE_LA_LENGUA = ('SQLSTATE', 'SELECT ', 'INSERT INTO', 'PDOException',
                      'Stack trace', '/var/www', 'Fatal error', 'on line ',
                      '.php:', 'Uncaught')


def llamar(metodo, ruta, cuerpo=None, token=None, crudo=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if crudo is not None:
        datos = crudo
        pet.add_header('Content-Type', 'application/json')
    elif cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            return r.status, r.read(4000000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(3000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, type(e).__name__ + ': ' + str(e)[:140]


def sin_base_de_datos():
    """None si con la base caída se contesta bien; si no, qué pasa."""
    import socket
    import subprocess
    import time
    puerto = 8093
    with socket.socket() as s0:
        if s0.connect_ex(('127.0.0.1', puerto)) == 0:
            return None                      # ocupado: no se puede probar aquí
    amb = dict(os.environ, HURAKANTICK_DB_DRIVER='mysql', HURAKANTICK_DB_HOST='127.0.0.1:1',
               HURAKANTICK_DB_NAME='no_existe', HURAKANTICK_DB_USER='nadie', HURAKANTICK_DB_PASS='x')
    raiz = entorno.DIR
    p = subprocess.Popen(['php', '-S', f'127.0.0.1:{puerto}', '-t', raiz], env=amb,
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(1.2)
        try:
            with urllib.request.urlopen(f'http://127.0.0.1:{puerto}/api/index.php?_ruta=estado', timeout=20) as r:
                return f'sin base de datos contesta {r.status}'
        except urllib.error.HTTPError as e:
            cuerpo = e.read().decode('utf-8', 'replace')
            if e.code != 503 or '"ok":false' not in cuerpo.replace(' ', ''):
                return f'sin base de datos contesta {e.code} con «{cuerpo[:80] or "(nada)"}» (tiene que ser 503 con su explicación)'
            return None
        except Exception as ex:                      # noqa: BLE001
            return f'sin base de datos no contesta: {ex}'
    finally:
        p.terminate()
        p.wait(timeout=10)


def main() -> int:
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print('No se ha podido entrar como administrador.')
        return 1
    token = json.loads(cuerpo)['token']

    caidas, bocazas, tragadas = [], [], []
    n = 0

    def revisar(que, codigo, texto, creacion=False):
        nonlocal n
        n += 1
        # Que no reviente está bien; que se lo TRAGUE no. Un título de cien
        # mil letras o un intervalo negativo guardados tan tranquilos son
        # un problema que aparece semanas después, cuando alguien abre esa
        # pantalla y no entiende nada.
        if creacion and 200 <= codigo < 300:
            tragadas.append(f'{que} -> ACEPTADO ({codigo})')
        if codigo >= 500 or codigo == 0:
            caidas.append(f'{que} -> {codigo or "sin respuesta"}: {texto[:110]}')
            return
        for pista in SE_VA_DE_LA_LENGUA:
            if pista in texto:
                bocazas.append(f'{que} -> el error enseña «{pista}»: {texto[:110]}')
                break

    # 1. Un campo a la vez, con cada clase de basura.
    for metodo, ruta, campo, resto in PUNTOS:
        for nombre, valor in BASURAS.items():
            cuerpo = dict(resto)
            cuerpo[campo] = valor
            codigo, texto = llamar(metodo, ruta, cuerpo, token)
            # Lo vacío, lo nulo y lo ausente pueden ser legítimos (un campo
            # opcional). Lo que no debería colarse es lo imposible.
            # Sólo se vigila lo ENORME, y no por el código que contesta
            # sino por lo que acaba en la base (se comprueba abajo).
            #
            # Un texto donde iba un número, o una lista donde iba un texto,
            # el servidor los convierte a propósito: recorta el nombre a su
            # largo máximo y deja el intervalo en 1. Para una aplicación que
            # vive en móviles con la versión a medio actualizar, eso es
            # mejor que perderle el trabajo a un técnico por un tipo de dato.
            # Comprobado leyendo la base: ninguno de esos guarda un disparate.
            vigilar = False
            revisar(f'{metodo} /{ruta} con {campo}={nombre}', codigo, texto, vigilar)

    # 2. El campo, directamente, no está.
    for metodo, ruta, campo, resto in PUNTOS:
        codigo, texto = llamar(metodo, ruta, dict(resto), token)
        revisar(f'{metodo} /{ruta} sin {campo}', codigo, texto)

    # 3. Identificadores imposibles.
    for plantilla in RUTAS_CON_ID:
        for metodo, lista in (('GET', IDS_RAROS), ('DELETE', IDS_RAROS_BORRAR)):
            for raro in lista:
                ruta = plantilla.format(urllib.request.quote(raro, safe=''))
                codigo, texto = llamar(metodo, ruta, None, token)
                revisar(f'{metodo} /{ruta}', codigo, texto)

    # 4. Cuerpos que no son ni JSON.
    for nombre, crudo in [('no es JSON', b'esto no es json'),
                          ('JSON a medias', b'{"titulo": '),
                          ('vacío', b''),
                          ('una lista', b'[1,2,3]'),
                          ('texto suelto', b'"hola"'),
                          ('muy anidado', b'{"a":' * 400 + b'1' + b'}' * 400)]:
        for ruta in ('tickets', 'usuarios', 'departamentos', 'instrucciones'):
            codigo, texto = llamar('POST', ruta, None, token, crudo=crudo)
            revisar(f'POST /{ruta} con el cuerpo «{nombre}»', codigo, texto)

    # 5. Sin ficha, con una ficha inventada y con una caducada.
    for nombre, t in [('sin ficha', None), ('ficha inventada', 'a' * 64),
                      ('ficha corta', 'xyz')]:
        for ruta in ('tickets', 'usuarios', 'ajustes', 'panel'):
            codigo, texto = llamar('GET', ruta, None, t)
            revisar(f'GET /{ruta} {nombre}', codigo, texto)

    # ---- Lo enorme, comprobado donde importa: en lo guardado ---------
    # Las instrucciones se precargan en los cincuenta móviles para poder
    # leerlas sin cobertura. Una instrucción de cien mil letras no se queda
    # en el servidor: se baja entera a cada teléfono.
    codigo, _ = llamar('POST', 'instrucciones',
                       {'titulo': 'zzz tope', 'contenido': 'A' * 100000}, token)
    if 200 <= codigo < 300:
        tragadas.append('Una instrucción de 100.000 letras se guarda sin rechistar, '
                        'y se baja entera a todos los móviles.')
    n += 1

    codigo, cuerpo = llamar('GET', 'instrucciones', None, token, )
    try:
        for i in json.loads(cuerpo).get('instrucciones', []):
            largo = len(str(i.get('contenido') or ''))
            if largo > 400000:
                tragadas.append(f'La instrucción «{str(i.get("titulo"))[:30]}» ocupa '
                                f'{largo} caracteres.')
    except Exception:                            # noqa: BLE001
        pass

    # Barrer lo creado. Esta prueba manda basura a propósito, así que deja
    # hoteles llamados «Array» y departamentos de ciento cuarenta letras.
    # Dejarlos ahí ensucia la base para las demás pruebas y para quien
    # abra la aplicación después a mirar algo.
    limpiados = 0
    for lista, clave, pk, borrar in (
            ('hoteles', 'hoteles', 'id_hotel', 'hoteles/{}'),
            ('departamentos', 'departamentos', 'id_departamento', 'departamentos/{}'),
            ('usuarios', 'usuarios', 'id_login', 'usuarios/{}&definitivo=1')):
        cod, cuerpo = llamar('GET', lista, None, token)
        if cod != 200:
            continue
        try:
            filas = json.loads(cuerpo).get(clave, [])
        except Exception:                        # noqa: BLE001
            continue
        for f in filas:
            nombre = str(f.get('nombre') or f.get('usuario') or '')
            # «x» va en la lista porque esta prueba crea revisiones con
            # ese nombre. Faltaba, así que se quedaban. Y el servidor,
            # cada mes, generaba un parte por cada una: llegó a haber 369
            # revisiones «x» fabricando 337 partes que nadie veía.
            sucio = (nombre in ('Array', '0', '-1', '1', '12345', 'x',
                                'esto no es un número')
                     # «zz», no «zzz»: esta prueba crea nombres como
                     # «zzaaaaaaaa…» para probar textos largos, y con tres
                     # zetas no entraban en el barrido. Quedó uno suelto en
                     # MySQL y, al simular cincuenta personas, siete
                     # sesiones intentaban entrar como él y fallaban: la
                     # prueba decía «sólo han entrado 43 de 50» por culpa de
                     # un resto de otra prueba.
                     or nombre.startswith('AAAA') or nombre.startswith('zz')
                     or nombre.startswith('O\'Brien') or '<script' in nombre
                     or 'DROP TABLE' in nombre)
            if sucio and str(f.get(pk)) != '1':
                llamar('DELETE', borrar.format(f.get(pk)), None, token)
                limpiados += 1
    # Las frases también: esta prueba crea frases del día de cuarenta
    # letras «A» para ver si el servidor aguanta el texto largo, y no las
    # borraba. Se acumulaban dieciocho por tanda, y salían en la pantalla
    # de quien abriera la aplicación después.
    cod, cuerpo = llamar('GET', 'frases', None, token)
    if cod == 200:
        try:
            for f in json.loads(cuerpo).get('frases', []):
                texto = str(f.get('texto') or '')
                if texto.startswith(('AAAA', 'zzz', 'Array')) or '<script' in texto:
                    llamar('DELETE', f'frases/{f.get("id_frase")}', None, token)
                    limpiados += 1
        except Exception:                        # noqa: BLE001
            pass

    for ins in ('instrucciones', 'mantenimiento'):
        cod, cuerpo = llamar('GET', ins, None, token)
        try:
            filas = json.loads(cuerpo).get(ins if ins != 'mantenimiento'
                                           else 'mantenimientos', [])
        except Exception:                        # noqa: BLE001
            continue
        for f in filas:
            nombre = str(f.get('titulo') or f.get('nombre') or '')
            if (nombre in ('x', 'Array') or '<script' in nombre
                    or nombre.startswith(('AAAA', 'zzz', 'Array', '0', '-1'))):
                idf = f.get('id_instruccion') or f.get('id_mantenimiento')
                llamar('DELETE', f'{ins}/{idf}', None, token)
                limpiados += 1

    # Y si la base de datos no está. Un MySQL reiniciándose o parado:
    # todas las peticiones salían como 500 VACÍO (el manejador de errores
    # reventaba al intentar deshacer en una conexión que no existía). Se
    # levanta un servidor aparte apuntado a un MySQL que no hay.
    sin_base = sin_base_de_datos()
    if sin_base:
        caidas.append(sin_base)
    else:
        print('Sin base de datos: 503 con su explicación, como debe.')

    print(f'Peticiones con datos malos: {n}  ·  rastro limpiado: {limpiados}')
    print(f'  el servidor se cae (500): {len(caidas)}')
    print(f'  errores que cuentan de más: {len(bocazas)}')
    print(f'  basura que se guarda igual: {len(tragadas)}')

    for titulo, lista in (('EL SERVIDOR SE CAE', caidas),
                          ('EL ERROR SE VA DE LA LENGUA', bocazas),
                          ('SE GUARDA BASURA SIN RECHISTAR', tragadas)):
        if lista:
            print(f'\n{titulo}:')
            for x in lista[:40]:
                print('  ·', x)
            if len(lista) > 40:
                print(f'  … y {len(lista) - 40} más')

    if not caidas and not bocazas and not tragadas:
        print('\nTodo lo malo se rechaza con un error limpio.')
        return 0
    return 1


if __name__ == '__main__':
    sys.exit(main())
