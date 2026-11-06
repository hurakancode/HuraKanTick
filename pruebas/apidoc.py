#!/usr/bin/env python3
"""
Que la referencia de la API diga la verdad, por los dos lados.

`docs/API.md` es un contrato: alguien lo lee para conectar otra cosa
—el PMS, un cuadro de mandos, un script de recepción— y confía en lo que
pone. Si promete una ruta que no existe, el que la use pierde la tarde
buscando su error. Y si falta la mitad de la API, se vuelve a escribir
algo que ya estaba hecho, o se entra por donde no se debe.

Dos comprobaciones, en los dos sentidos:

  · **Lo que promete, responde.** Cada ruta GET del documento se llama de
    verdad. Si contesta «ruta no encontrada», el documento miente.
    Sólo se llaman las de lectura: probar un DELETE documentado sería
    borrar algo de verdad.

  · **Lo que existe, está contado.** Cada ruta con su comentario en
    `api/routes/` tiene que aparecer en el documento. Esto es lo que
    encontró que faltaban cuarenta rutas enteras —departamentos,
    errores, mensajes, personal, las subidas— que funcionaban desde
    hacía meses y no estaban en ninguna parte.

   python3 pruebas/apidoc.py
"""

import glob
import json
import os
import re
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
BASE = os.environ.get('HURAKANTICK_API',
                      'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
# Rutas que NO se llaman aunque sean GET: descargan ficheros, hacen
# trabajo pesado o dependen de algo que aquí no hay.
NO_LLAMAR = ('descargar', 'exportar', 'copias/', 'adjuntos/', 'sap/',
             'teams/', 'push/clave')


def llamar(ruta, token):
    pet = urllib.request.Request(BASE + ruta)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(pet, None, timeout=60) as r:
            return r.status, r.read(200000).decode('utf-8', 'replace')[:300]
    except urllib.error.HTTPError as e:
        return e.code, e.read(200000).decode('utf-8', 'replace')[:300]
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:120]


def normaliza(r):
    r = r.split('?')[0].strip().strip('/')
    return re.sub(r'\{[a-z_]+\}', '{id}', r)


def main() -> int:
    doc_ruta = os.path.join(RAIZ, 'docs', 'API.md')
    if not os.path.isfile(doc_ruta):
        print('No encuentro docs/API.md.')
        return 2
    doc = open(doc_ruta, encoding='utf-8').read()

    documentadas = set()
    for m in re.finditer(r'^\|\s*(GET|POST|PUT|PATCH|DELETE)\s*\|\s*`([^`]+)`',
                         doc, re.M):
        documentadas.add((m.group(1), normaliza(m.group(2))))
    if not documentadas:
        print('El documento ya no lista rutas en tablas: esta prueba se ha '
              'quedado sin servir.')
        return 2

    fallos = []

    # ---- 1. Lo que promete el documento, ¿responde? -------------------
    c, cuerpo = None, None
    pet = urllib.request.Request(BASE + 'auth/login', method='POST')
    pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, json.dumps(
                {'correo': entorno.usuario('admin'), 'password': CLAVE}).encode(),
                timeout=60) as r:
            ficha = json.loads(r.read(200000))['token']
    except Exception as e:                       # noqa: BLE001
        print(f'No se puede entrar para comprobar las rutas: {str(e)[:90]}')
        return 2

    gets = sorted(r for m, r in documentadas
                  if m == 'GET' and '{id}' not in r
                  and not any(x in r for x in NO_LLAMAR))
    inexistentes = []
    for ruta in gets:
        cod, cuerpo = llamar(ruta, ficha)
        # El servidor distingue: una ruta que no existe lo dice con ese
        # texto. Un 404 por otra causa —un id que no está— es otra cosa.
        if cod == 404 and 'no encontrada' in cuerpo.lower():
            inexistentes.append(ruta)
        elif cod == 0:
            inexistentes.append(f'{ruta} (no contesta)')

    print(f'Rutas de lectura del documento, llamadas: {len(gets)}')
    if inexistentes:
        fallos.append('El documento promete rutas que NO existen: '
                      + ', '.join(inexistentes[:8])
                      + '. Quien las use perderá la tarde buscando su error.')

    # ---- 2. Lo que existe, ¿está contado? -----------------------------
    reales = {}
    for f in sorted(glob.glob(os.path.join(RAIZ, 'api', 'routes', '*.php'))):
        txt = open(f, encoding='utf-8').read()
        for m in re.finditer(
                r'(GET|POST|PUT|PATCH|DELETE)\s+/api/([a-z0-9_{}/.-]+)', txt):
            reales[(m.group(1), normaliza(m.group(2)))] = os.path.basename(f)

    sin_contar = sorted((m, r, f) for (m, r), f in reales.items()
                        if (m, r) not in documentadas)
    print(f'Rutas con comentario en el código: {len(reales)}')
    print(f'  de ésas, sin aparecer en el documento: {len(sin_contar)}')
    if sin_contar:
        porfichero = {}
        for m, r, f in sin_contar:
            porfichero.setdefault(f, []).append(f'{m} {r}')
        fallos.append(
            f'{len(sin_contar)} rutas existen y no están en docs/API.md. '
            'Quien lea el documento creerá que no se puede hacer, y lo hará '
            'por otro lado o lo escribirá otra vez.')
        for f, rs in sorted(porfichero.items()):
            fallos.append(f'   {f}: ' + ', '.join(sorted(rs)[:6])
                          + (f' … y {len(rs) - 6} más' if len(rs) > 6 else ''))

    print(f'\n  que sale mal: {len([x for x in fallos if not x.startswith("   ")])}')
    if fallos:
        print('\nLA REFERENCIA DE LA API:')
        for f in fallos:
            print(('  · ' if not f.startswith('   ') else '  ') + f.strip()
                  if not f.startswith('   ') else f)
        return 1
    print('\nLo que promete responde, y lo que existe está contado.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
