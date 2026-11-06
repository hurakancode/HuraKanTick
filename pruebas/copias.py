#!/usr/bin/env python3
"""
La copia de seguridad, y sobre todo la restauración.

Una copia que se crea sin quejarse no es una copia: es un fichero. Lo
único que importa es si el día malo se puede devolver, y eso no se sabe
hasta que se prueba a devolverla. Es la prueba que no existía, y la que
más caro sale no tener.

Así que aquí se hace de verdad:

  1. se apunta cómo está todo —cuántos partes, cuánta gente, qué dice un
     ajuste concreto, el título de un parte concreto—;
  2. se crea la copia por la aplicación, como la crearía un administrador;
  3. **se destruye**: se borra un parte, se borra a una persona, se cambia
     un ajuste y se desactiva un departamento;
  4. se restaura con `tools/restaurar.php`, que es lo que se ejecutaría
     de verdad;
  5. y se comprueba que ha vuelto TODO: los números y el contenido.

Además se comprueban tres cosas que se olvidan:

  · que la copia incluya **todas** las tablas, no las que alguien
    apuntó a mano en una lista hace dos años;
  · que un fichero que no es una copia se rechace con un error claro, y
    no deje la base a medias;
  · y que la copia **no lleve dentro las contraseñas en claro** ni los
    secretos del servidor.

Si algo sale mal la base se queda tocada, así que antes de empezar se
guarda una copia del fichero de la base, y se avisa de dónde está.

   python3 pruebas/copias.py
"""

import glob
import gzip
import json
import os
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

RAIZ = entorno.DIR
BASE = os.environ.get(
    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
RED = '/tmp/claude-0/copias-red-de-seguridad.sqlite'


def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=120) as r:
            return r.status, r.read(4000000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(4000000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


def retrato(token):
    """Cómo está todo ahora: números y un dato concreto de cada cosa."""
    r = {}
    for clave, ruta, bolsa in (('partes', 'tickets&limite=500', 'tickets'),
                               ('gente', 'usuarios&todos=1', 'usuarios'),
                               ('deptos', 'catalogo', 'departamentos')):
        _, cuerpo = llamar('GET', ruta, None, token)
        try:
            d = json.loads(cuerpo)
            r[clave] = len(d.get(bolsa) or [])
        except Exception:                        # noqa: BLE001
            r[clave] = -1

    # Un dato concreto, no sólo el recuento: un recuento puede cuadrar con
    # el contenido cambiado.
    _, cuerpo = llamar('GET', 'tickets&limite=1', None, token)
    try:
        t = json.loads(cuerpo)['tickets'][0]
        r['un_parte'] = (t.get('id_ticket'), (t.get('titulo_ticket') or '')[:40])
    except Exception:                            # noqa: BLE001
        r['un_parte'] = None

    # Un ajuste de verdad, elegido por tener valor.
    #
    # La primera versión miraba «dias_vencimiento_alta», que en esta
    # instalación está vacío: comparaba None con None y daba por buena la
    # restauración de los ajustes sin haber mirado nada. Una comprobación
    # que no puede fallar es peor que no tenerla, porque tranquiliza.
    _, cuerpo = llamar('GET', 'ajustes', None, token)
    r['un_ajuste'] = None
    r['clave_ajuste'] = None
    try:
        aj = json.loads(cuerpo).get('ajustes') or {}
        for lista in aj.values():
            for x in lista:
                if x.get('tipo') == 'numero' and x.get('valor') not in (None, ''):
                    r['clave_ajuste'] = x.get('clave')
                    r['un_ajuste'] = x.get('valor')
                    break
            if r['clave_ajuste']:
                break
    except Exception:                            # noqa: BLE001
        pass
    return r


def main() -> int:
    fallos = []
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print('No se ha podido entrar como administrador.')
        return 1
    ficha = json.loads(cuerpo)['token']

    # Red de seguridad: si la restauración no funciona, la base se queda
    # tocada. Mejor poder volver.
    fichero_base = os.path.join(RAIZ, 'db', 'hurakantick.sqlite')
    hay_fichero = os.path.isfile(fichero_base)
    if hay_fichero:
        os.makedirs(os.path.dirname(RED), exist_ok=True)
        shutil.copy2(fichero_base, RED)

    antes = retrato(ficha)
    print('Antes: ' + ', '.join(f'{k}={v}' for k, v in antes.items()
                                if k in ('partes', 'gente', 'deptos')))

    # ---- 1. Crear la copia --------------------------------------------
    c, cuerpo = llamar('POST', 'copias', {'con_fotos': False}, ficha)
    if not (200 <= c < 300):
        print(f'No se ha podido crear la copia ({c}): {cuerpo[:200]}')
        return 1
    try:
        copia = json.loads(cuerpo)['copia']
    except Exception:                            # noqa: BLE001
        print('La copia se ha creado pero no se entiende la respuesta.')
        return 1
    print(f'Copia creada: {copia.get("archivo")} · '
          f'{copia.get("registros")} registros')

    carpeta = None
    _, cu = llamar('GET', 'copias', None, ficha)
    try:
        carpeta = json.loads(cu).get('destino')
    except Exception:                            # noqa: BLE001
        pass
    ruta_copia = None
    for f in glob.glob(os.path.join(carpeta or '', '*.json*')):
        if os.path.basename(f) == copia.get('archivo'):
            ruta_copia = f
    if not ruta_copia:
        fallos.append('La copia dice haberse creado pero el fichero no está '
                      f'en {carpeta}.')
        print('\n'.join('  · ' + f for f in fallos))
        return 1

    # ---- 2. ¿Lleva dentro lo que no debe? -----------------------------
    bruto = open(ruta_copia, 'rb').read()
    texto = gzip.decompress(bruto) if ruta_copia.endswith('.gz') else bruto
    datos = json.loads(texto.decode('utf-8', 'replace'))
    tablas = datos.get('tablas') or {}

    if CLAVE in texto.decode('utf-8', 'replace'):
        fallos.append('LA COPIA LLEVA UNA CONTRASEÑA EN CLARO DENTRO. '
                      'Quien coja el fichero entra como cualquiera.')
    for sospechoso in ('api_tokens', 'push_destinos', 'recuperaciones'):
        if sospechoso in tablas:
            fallos.append(f'La copia incluye «{sospechoso}», que son datos de '
                          'esta máquina y de esta sesión: no valen en otro '
                          'servidor y alargan la copia.')

    # Que no se haya quedado corta: se compara con las tablas que hay.
    import sqlite3
    if hay_fichero:
        con = sqlite3.connect(fichero_base)
        hay = {r[0] for r in con.execute(
            "SELECT name FROM sqlite_master WHERE type='table' "
            "AND name NOT LIKE 'sqlite_%'")}
        con.close()
        FUERA = {'api_tokens', 'intentos_acceso', 'recuperaciones',
                 'push_destinos', 'copias'}
        faltan = sorted(hay - set(tablas) - FUERA)
        if faltan:
            fallos.append('La copia NO incluye estas tablas, así que al '
                          'restaurarla faltaría eso: ' + ', '.join(faltan[:10]))

    # ---- 3. Destruir de verdad ----------------------------------------
    print('Destruyendo: un parte, una persona, un ajuste y un departamento…')
    idp = (antes.get('un_parte') or (None,))[0]
    if idp:
        llamar('DELETE', f'tickets/{idp}&definitivo=1', None, ficha)
    _, cu = llamar('GET', 'usuarios&todos=1', None, ficha)
    try:
        gente = json.loads(cu)['usuarios']
        victima = next((x for x in gente
                        if (x.get('usuario') or '') not in ('admin',)), None)
        if victima:
            llamar('DELETE', f'usuarios/{victima["id_login"]}&definitivo=1',
                   None, ficha)
    except Exception:                            # noqa: BLE001
        victima = None
    clave_aj = antes.get('clave_ajuste')
    if clave_aj:
        try:
            otro = int(float(antes.get('un_ajuste'))) + 1
        except Exception:                        # noqa: BLE001
            otro = 7
        llamar('PATCH', 'ajustes', {'cambios': {clave_aj: otro}}, ficha)
    _, cu = llamar('GET', 'catalogo', None, ficha)
    try:
        dep = (json.loads(cu).get('departamentos') or [])[-1]
        llamar('DELETE', f'departamentos/{dep["id_departamento"]}', None, ficha)
    except Exception:                            # noqa: BLE001
        pass

    roto = retrato(ficha)
    if roto == antes:
        fallos.append('Se ha intentado destruir cosas y nada ha cambiado: '
                      'esta prueba no está probando la restauración.')
        print('  (no se ha podido destruir nada; no se restaura)')
    else:
        print('Roto:  ' + ', '.join(f'{k}={v}' for k, v in roto.items()
                                    if k in ('partes', 'gente', 'deptos')))

        # ---- 4. Restaurar, como se haría de verdad --------------------
        r = subprocess.run(
            ['php', os.path.join(RAIZ, 'tools', 'restaurar.php'), ruta_copia,
             '--si'],
            capture_output=True, text=True, timeout=600)
        salida = (r.stdout or '') + (r.stderr or '')
        if r.returncode != 0:
            fallos.append('LA RESTAURACIÓN FALLA: ' + salida.strip()[-300:])
        else:
            despues = retrato(ficha)
            for clave in ('partes', 'gente', 'deptos'):
                if despues.get(clave) != antes.get(clave):
                    fallos.append(
                        f'Después de restaurar, {clave}: había '
                        f'{antes.get(clave)} y hay {despues.get(clave)}.')
            if despues.get('un_parte') != antes.get('un_parte'):
                fallos.append(
                    'El parte que había antes no ha vuelto igual: era '
                    f'{antes.get("un_parte")} y es {despues.get("un_parte")}.')
            if not clave_aj:
                fallos.append('No se ha encontrado ningún ajuste con valor que '
                              'probar, así que la restauración de los ajustes '
                              'NO se ha comprobado.')
            elif str(roto.get('un_ajuste')) == str(antes.get('un_ajuste')):
                fallos.append(f'El ajuste «{clave_aj}» no ha cambiado al '
                              'destrozarlo, así que su restauración tampoco se '
                              'ha comprobado.')
            elif str(despues.get('un_ajuste')) != str(antes.get('un_ajuste')):
                fallos.append(
                    f'Los ajustes no se restauran: «{clave_aj}» era '
                    f'{antes.get("un_ajuste")} y ha quedado en '
                    f'{despues.get("un_ajuste")}.')
            print('Tras restaurar: ' + ', '.join(
                f'{k}={v}' for k, v in despues.items()
                if k in ('partes', 'gente', 'deptos')))

    # ---- 5. Un fichero que no es una copia ----------------------------
    basura = '/tmp/claude-0/no-es-una-copia.json'
    with open(basura, 'w', encoding='utf-8') as fh:
        fh.write('{"esto": "no es una copia"}')
    r = subprocess.run(['php', os.path.join(RAIZ, 'tools', 'restaurar.php'),
                        basura, '--si'],
                       capture_output=True, text=True, timeout=120)
    salida = (r.stdout or '') + (r.stderr or '')
    if r.returncode == 0:
        fallos.append('Restaurar un fichero que NO es una copia termina bien. '
                      'Eso vacía la base sin avisar.')
    elif 'no es una copia' not in salida.lower() and 'válida' not in salida.lower():
        fallos.append('Un fichero que no es una copia se rechaza, pero sin '
                      f'decir por qué: «{salida.strip()[-120:]}»')

    final = retrato(ficha)
    if final.get('partes') != antes.get('partes'):
        fallos.append('Después de intentar restaurar un fichero inválido, los '
                      'datos han cambiado: no se ha quedado como estaba.')

    print(f'\nCopia y restauración probadas de punta a punta.')
    print(f'  que salen mal: {len(fallos)}')
    if fallos:
        print('\nLA COPIA DE SEGURIDAD:')
        for f in fallos:
            print('  ·', f)
        if hay_fichero:
            print(f'\n  (hay una copia del fichero de la base en {RED})')
        return 1
    print('\nLa copia se crea, se restaura y todo vuelve a su sitio.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
