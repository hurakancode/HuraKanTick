#!/usr/bin/env python3
"""
Seguir las instrucciones de instalación al pie de la letra.

`docs/INSTALACION.md` es lo primero que alguien va a leer, y lo va a
copiar y pegar tal cual. Si una orden está mal, o si falta un permiso,
el primer día se va en eso —y además se va con la impresión de que el
programa está roto, no el manual—.

Esta prueba hace exactamente lo que dice el documento, en una carpeta
nueva:

  1. saca del documento las órdenes de la «prueba rápida» y las ejecuta;
  2. comprueba que después se puede entrar con el usuario y la
     contraseña que el documento promete;
  3. y mira que los permisos de MySQL que el documento manda dar sean
     los que el instalador necesita de verdad.

El tercero salió de un fallo real: el documento daba SELECT, INSERT,
UPDATE, DELETE, CREATE, ALTER e INDEX, pero `--forzar` necesita DROP para
vaciar la base. Con esos permisos, empezar de cero fallaba… y el
instalador se lo tragaba como «aviso» y terminaba diciendo «Listo». Quien
lo hiciera se iba convencido de tener una instalación limpia y tenía una
mezcla.

   python3 pruebas/instalacion.py
"""

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
DOC = os.path.join(RAIZ, 'docs', 'INSTALACION.md')
PUERTO = 8093


def main() -> int:
    if not os.path.isfile(DOC):
        print('No encuentro docs/INSTALACION.md.')
        return 2
    doc = open(DOC, encoding='utf-8').read()
    fallos = []

    # ---- 1. La prueba rápida, tal como está escrita --------------------
    #
    # Se sacan del documento, no se escriben aquí: si alguien cambia el
    # manual, esto prueba lo nuevo. Si lo cambia por algo que no
    # funciona, salta.
    bloques = re.findall(r'```bash\n(.*?)```', doc, re.S)
    ordenes = []
    for b in bloques:
        for linea in b.strip().split('\n'):
            linea = linea.strip()
            if linea.startswith('php tools/instalar.php'):
                ordenes.append(linea)
        if ordenes:
            break
    if not ordenes:
        print('El documento ya no trae la orden de instalar en un bloque '
              '```bash```: esta prueba se ha quedado sin servir.')
        return 2
    print('Lo que manda hacer el documento: ' + ' · '.join(ordenes))

    tmp = tempfile.mkdtemp(prefix='hurakantick-instalacion-')
    destino = os.path.join(tmp, 'hurakantick')
    try:
        shutil.copytree(RAIZ, destino, ignore=shutil.ignore_patterns(
            '*.sqlite', '*.sqlite-wal', '*.sqlite-shm', '*.zip',
            'uploads', 'copias', '__pycache__', 'config.local.php'))

        for orden in ordenes:
            r = subprocess.run(orden, shell=True, cwd=destino,
                               capture_output=True, text=True, timeout=1800)
            salida = (r.stdout or '') + (r.stderr or '')
            if r.returncode != 0:
                fallos.append(f'«{orden}» termina con error: '
                              + salida.strip()[-220:])
            for mala in ('Fatal error', 'Uncaught', 'aviso:'):
                if mala in salida:
                    trozo = salida[salida.index(mala):][:160].replace('\n', ' ')
                    fallos.append(f'«{orden}» suelta «{mala}»: {trozo}')

        # ---- 2. ¿Se entra con lo que promete el documento? -------------
        #
        # El documento da un usuario y una contraseña concretos. Si no
        # valen, el primer día se va en adivinar.
        m = re.search(r'`([^`]+@[^`]+)`\s*/\s*`([^`]+)`', doc)
        if not m:
            fallos.append('El documento ya no dice con qué usuario y '
                          'contraseña se entra la primera vez.')
        else:
            quien, clave = m.group(1), m.group(2)
            servidor = subprocess.Popen(
                ['php', '-S', f'127.0.0.1:{PUERTO}', '-t', destino],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                start_new_session=True)
            try:
                base = f'http://127.0.0.1:{PUERTO}/api/index.php?_ruta='
                entrado = False
                for _ in range(30):
                    time.sleep(0.5)
                    pet = urllib.request.Request(base + 'auth/login',
                                                 method='POST')
                    pet.add_header('Content-Type', 'application/json')
                    try:
                        with urllib.request.urlopen(pet, json.dumps(
                                {'correo': quien, 'password': clave}).encode(),
                                timeout=30) as r:
                            if r.status == 200:
                                entrado = True
                                break
                    except urllib.error.HTTPError as e:
                        if e.code in (401, 403):
                            break               # el servidor está, la clave no
                    except Exception:            # noqa: BLE001
                        continue
                if entrado:
                    print(f'Se entra con «{quien}», como dice el documento.')
                else:
                    fallos.append(
                        f'Siguiendo el documento al pie de la letra NO se '
                        f'puede entrar con «{quien}» / «{clave}».')
            finally:
                try:
                    os.killpg(os.getpgid(servidor.pid), 15)
                except Exception:                # noqa: BLE001
                    servidor.kill()
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    # ---- 3. Los permisos de MySQL que manda dar ------------------------
    #
    # No se puede probar sin un MySQL a mano, pero sí se puede comparar
    # lo que el documento manda dar con lo que el instalador hace.
    m = re.search(r'GRANT\s+(.*?)\s+ON\s', doc, re.S | re.I)
    if not m:
        print('(el documento no trae un GRANT: no se comprueban permisos)')
    else:
        dados = {p.strip().upper() for p in m.group(1).replace('\n', ' ').split(',')}
        instalador = open(os.path.join(RAIZ, 'tools', 'instalar.php'),
                          encoding='utf-8').read()
        esquema = ''
        for f in sorted(os.listdir(os.path.join(RAIZ, 'db'))):
            if f.endswith('.mysql.sql'):
                esquema += open(os.path.join(RAIZ, 'db', f),
                                encoding='utf-8').read()

        necesarios = {'SELECT': 'leer', 'INSERT': 'guardar',
                      'UPDATE': 'cambiar', 'DELETE': 'borrar filas',
                      'CREATE': 'crear las tablas', 'ALTER': 'las ampliaciones',
                      'INDEX': 'los índices'}
        if 'DROP TABLE' in instalador.upper():
            necesarios['DROP'] = 'vaciar la base con --forzar'
        if 'FOREIGN KEY' in esquema.upper():
            necesarios['REFERENCES'] = 'las claves foráneas'

        faltan = [f'{p} ({para})' for p, para in necesarios.items()
                  if p not in dados]
        if faltan:
            fallos.append('El GRANT del documento se queda corto, falta: '
                          + ', '.join(faltan)
                          + '. Quien lo copie se encontrará con que algo no '
                            'funciona y no sabrá por qué.')
        else:
            print(f'El GRANT del documento da los {len(necesarios)} permisos '
                  'que hacen falta.')

    print(f'\n  que sale mal: {len(fallos)}')
    if fallos:
        print('\nSIGUIENDO EL MANUAL AL PIE DE LA LETRA:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nLas instrucciones funcionan tal como están escritas.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
