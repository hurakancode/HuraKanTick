#!/usr/bin/env python3
"""
Que la sesión no viaje nunca escrita en una dirección.

Una descarga de verdad —la que abre el navegador al pulsar un enlace— no
puede llevar la cabecera `Authorization`: un enlace manda lo que pone en
la dirección y nada más. Así que el cliente metía el token de sesión ahí:

    ...?_ruta=copias/14&token=c504d77f726e42...

Y una dirección no es un sitio discreto. Esa línea apareció **134 veces
en el registro de Apache**, con tokens de administrador completos y en
claro, que valen treinta días. Quien pueda leer los registros —o una
copia suya, o el servicio que los recoge— entra como administrador. Y
además queda en el historial del navegador y viaja en el `Referer`.

Ahora esa descarga va con un vale: 60 segundos, atado a esa copia y a
quien lo pidió, firmado con la clave de la casa. En el registro queda
igual, pero al minuto siguiente no sirve para nada.

Lo que se comprueba, todo pidiéndolo de verdad:

  1. el token en la dirección ya NO abre nada;
  2. el vale baja el fichero sin sesión;
  3. un vale con la firma tocada no vale;
  4. un vale de una copia no sirve para otra;
  5. un vale caducado no vale;
  6. sin vale y sin sesión, nada;
  7. y que no quede ningún `token=` en el código del cliente.

El 7 es el que evita que esto vuelva: lo de antes no era un fallo del
servidor, era una pantalla que construía una dirección.

   python3 pruebas/vales.py
"""

import glob
import hashlib
import hmac
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
BASE = os.environ.get('HURAKANTICK_API',
                      'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
def llamar(metodo, ruta, cuerpo=None, token=None, crudo=False):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    try:
        with urllib.request.urlopen(pet, datos, timeout=120) as r:
            b = r.read(20000000)
            return r.status, (b if crudo else b.decode('utf-8', 'replace'))
    except urllib.error.HTTPError as e:
        return e.code, e.read(20000).decode('utf-8', 'replace')
    except Exception as ex:                           # noqa: BLE001
        return 0, str(ex)[:150]


def app_key():
    """La clave de la casa, para poder fabricar un vale caducado."""
    orden = ("$c = require 'api/config.php'; "
             "echo $c['auth']['app_key'] ?? '';")
    r = subprocess.run(['php', '-r', orden], cwd=RAIZ,
                       capture_output=True, text=True, timeout=60)
    return (r.stdout or '').strip()


def main() -> int:
    fallos = []

    s, c = llamar('POST', 'auth/login',
                  {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if s != 200:
        print(f'No se puede entrar para probar los vales ({s}).')
        return 2
    token = json.loads(c)['token']

    # ---- 1. El token en la dirección ya no abre nada ------------------
    s, _ = llamar('GET', 'copias&token=' + token)
    if s == 200:
        fallos.append('El token de sesión sigue valiendo escrito en la '
                      'dirección. Cada enlace así queda en el registro del '
                      'servidor, en el historial y en el Referer, y vale 30 '
                      'días.')
    else:
        print(f'El token en la dirección no abre nada ({s}).')

    # ---- Hace falta una copia para lo demás ---------------------------
    s, c = llamar('GET', 'copias', None, token)
    if s != 200:
        print(f'No se puede listar copias ({s}).')
        return 2
    ids = re.findall(r'"id_copia":\s*(\d+)', c)
    if not ids:
        s, c = llamar('POST', 'copias', {}, token)
        if s not in (200, 201):
            print(f'No se puede crear una copia para probar ({s}).')
            return 2
        s, c = llamar('GET', 'copias', None, token)
        ids = re.findall(r'"id_copia":\s*(\d+)', c)
    if not ids:
        print('No hay ninguna copia con la que probar.')
        return 2
    idc = ids[0]

    # ---- 2. El vale baja el fichero sin sesión ------------------------
    s, c = llamar('POST', f'copias/{idc}/vale', {}, token)
    if s != 200:
        fallos.append(f'No se puede pedir un vale de descarga ({s}): sin eso '
                      'no hay forma de bajar una copia desde el navegador.')
        vale = ''
    else:
        vale = json.loads(c)['vale']
        s, b = llamar('GET', f'copias/{idc}&vale={vale}', None, None, crudo=True)
        if s != 200 or len(b) < 100:
            fallos.append(f'El vale no sirve para bajar la copia ({s}, '
                          f'{len(b)} bytes): el botón de descargar no '
                          'funcionaría.')
        else:
            print(f'El vale baja la copia sin sesión ({len(b)} bytes).')

    if vale:
        # ---- 3. Firma tocada ------------------------------------------
        s, _ = llamar('GET', f'copias/{idc}&vale={vale[:-4]}0000')
        if s == 200:
            fallos.append('Un vale con la firma cambiada sigue bajando el '
                          'fichero: entonces cualquiera se fabrica uno.')

        # ---- 4. Vale de una copia usado en otra -----------------------
        otro = str(int(idc) + 1)
        s, _ = llamar('GET', f'copias/{otro}&vale={vale}')
        if s == 200:
            fallos.append('El vale de una copia sirve para otra: tenía que '
                          'valer sólo para la suya.')

    # ---- 5. Vale caducado ---------------------------------------------
    k = app_key()
    if not k:
        print('(no se ha podido leer la clave: no se prueba la caducidad)')
    else:
        antes = int(time.time()) - 600
        cuerpo = f'{antes}.1.copias/{idc}'
        sello = hmac.new(k.encode(), cuerpo.encode(),
                         hashlib.sha256).hexdigest()[:32]
        caducado = f'{antes}.1.{sello}'
        s, _ = llamar('GET', f'copias/{idc}&vale={caducado}')
        if s == 200:
            fallos.append('Un vale de hace diez minutos, bien firmado, sigue '
                          'valiendo: entonces no caduca, y el que quede en el '
                          'registro vale para siempre.')
        else:
            print(f'Un vale caducado no vale ({s}).')

    # ---- 6. Sin vale y sin sesión --------------------------------------
    s, _ = llamar('GET', f'copias/{idc}')
    if s == 200:
        fallos.append('La copia se baja sin sesión y sin vale: cualquiera que '
                      'acierte el número se lleva la base entera.')

    # ---- 7. Que no quede ningún token= en el cliente -------------------
    #
    # Esto es lo que evita que vuelva. El fallo no estaba en el servidor:
    # estaba en una pantalla que se construía la dirección a mano.
    sospechosas = []
    for f in glob.glob(os.path.join(RAIZ, 'www', 'assets', 'js', '**', '*.js'),
                       recursive=True):
        if os.sep + 'vendor' + os.sep in f:
            continue
        for n, linea in enumerate(open(f, encoding='utf-8'), 1):
            if re.search(r'[?&]token=', linea) and '*' not in linea.strip()[:2]:
                sospechosas.append(f'{os.path.relpath(f, RAIZ)}:{n}')
    if sospechosas:
        fallos.append('Hay pantallas que meten el token en la dirección: '
                      + ', '.join(sospechosas[:5])
                      + '. Cada una vuelve a dejar sesiones escritas en el '
                        'registro del servidor.')
    else:
        print('Ninguna pantalla mete el token en una dirección.')

    print(f'\n  que sale mal: {len(fallos)}')
    if fallos:
        print('\nLA SESIÓN VIAJA DONDE NO DEBE:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nLa sesión sólo viaja por cabecera, y las descargas con un vale '
          'que caduca.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
