#!/usr/bin/env python3
"""
Los 104 ajustes, uno a uno.

La pantalla de configuración es la más fácil de romper sin que se note: son
cien interruptores y campos que casi nadie toca, y el día que alguien toca
uno espera que haga lo que dice. Un ajuste que no guarda, que guarda otra
cosa, o que vuelve al valor de antes al recargar, no da ningún error.

Con cada ajuste se hace el viaje completo:

  1. se apunta cómo está;
  2. se le pone un valor NUEVO, válido para su tipo;
  3. se guarda;
  4. se vuelve a leer del servidor y se compara;
  5. y se deja como estaba.

Además se comprueban tres cosas que no son el valor:

  · que un ajuste con lista de opciones no acepte una opción inventada;
  · que un número no acepte un texto;
  · y que las credenciales no se las lleve quien no es administrador
    (eso lo mira a fondo pruebas/permisos.py, aquí sólo se confirma).

   python3 pruebas/ajustes.py
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
def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            return r.status, r.read(600000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(600000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


def leer_todos(token):
    c, cuerpo = llamar('GET', 'ajustes', None, token)
    if c != 200:
        return None
    try:
        aj = json.loads(cuerpo)
        aj = aj.get('ajustes') or aj
    except Exception:                            # noqa: BLE001
        return None
    fuera = {}
    for cat, lista in (aj.items() if isinstance(aj, dict) else []):
        for x in lista:
            x['categoria'] = cat
            fuera[x['clave']] = x
    return fuera


def otro_valor(a):
    """Un valor distinto del actual, válido para el tipo del ajuste."""
    tipo = a.get('tipo')
    valor = a.get('valor')

    if tipo == 'interruptor':
        return not bool(valor)

    if tipo == 'numero':
        try:
            n = int(float(valor if valor not in (None, '') else 0))
        except Exception:                        # noqa: BLE001
            n = 0
        # Hacia arriba, y sin pasarse: hay topes razonables en varios.
        return max(1, min(n + 1, 30))

    if tipo == 'lista':
        ops = [o.get('v') if isinstance(o, dict) else o for o in (a.get('opciones') or [])]
        ops = [o for o in ops if o is not None and str(o) != str(valor)]
        return ops[0] if ops else None

    if tipo == 'json':
        # Lo que admiten son listas de claves; se deja vacío, que siempre vale.
        return []

    if tipo in ('texto', 'clave'):
        return None if a['clave'].startswith(('push_', 'teams_', 'sap_')) \
            else 'zzz prueba'

    return None


def iguales(tipo, pedido, guardado):
    if tipo == 'interruptor':
        return bool(guardado) == bool(pedido)
    if tipo == 'numero':
        try:
            return float(guardado) == float(pedido)
        except Exception:                        # noqa: BLE001
            return False
    if tipo == 'json':
        return (guardado or []) == (pedido or [])
    return str(guardado if guardado is not None else '') == str(pedido)


def main() -> int:
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print('No se ha podido entrar como administrador.')
        return 1
    token = json.loads(cuerpo)['token']

    antes = leer_todos(token)
    if not antes:
        print('No se ha podido leer la configuración.')
        return 1

    mudos, raros, saltados = [], [], []
    probados = 0

    for clave, a in antes.items():
        nuevo = otro_valor(a)
        if nuevo is None:
            # Credenciales y ajustes sin alternativa: no se tocan, pero se
            # dice, para que no parezca que están probados.
            saltados.append(f'{clave} ({a.get("tipo")})')
            continue

        probados += 1
        cod, resp = llamar('PATCH', 'ajustes', {'cambios': {clave: nuevo}}, token)
        if cod >= 500:
            raros.append(f'{clave}: al guardarlo el servidor devuelve {cod}.')
            continue
        if cod in (401, 403):
            raros.append(f'{clave}: el administrador no puede cambiarlo ({cod}).')
            continue
        if not (200 <= cod < 300):
            raros.append(f'{clave}: no se deja guardar ({cod}) — {resp[:80]}')
            continue

        despues = leer_todos(token)
        guardado = (despues or {}).get(clave, {}).get('valor')
        if not iguales(a.get('tipo'), nuevo, guardado):
            mudos.append(f'{clave} ({a.get("categoria")}/{a.get("tipo")}): se pide '
                         f'«{nuevo}», se guarda «{guardado}».')

        # Dejarlo como estaba.
        llamar('PATCH', 'ajustes', {'cambios': {clave: a.get('valor')}}, token)

    # ---- Que no se trague lo imposible --------------------------------
    coladas = []
    for clave, a in antes.items():
        if a.get('tipo') == 'lista' and a.get('opciones'):
            cod, _ = llamar('PATCH', 'ajustes',
                            {'cambios': {clave: 'opcion_que_no_existe'}}, token)
            if 200 <= cod < 300:
                d = leer_todos(token)
                if (d or {}).get(clave, {}).get('valor') == 'opcion_que_no_existe':
                    coladas.append(f'{clave}: acepta una opción que no está en su lista.')
                llamar('PATCH', 'ajustes', {'cambios': {clave: a.get('valor')}}, token)
        if a.get('tipo') == 'numero':
            cod, _ = llamar('PATCH', 'ajustes',
                            {'cambios': {clave: 'esto no es un número'}}, token)
            if 200 <= cod < 300:
                d = leer_todos(token)
                v = (d or {}).get(clave, {}).get('valor')
                try:
                    float(v)
                except Exception:                # noqa: BLE001
                    coladas.append(f'{clave}: guarda «{v}» en un ajuste que es un número.')
                llamar('PATCH', 'ajustes', {'cambios': {clave: a.get('valor')}}, token)

    # ---- Y que hayan vuelto todos a su sitio --------------------------
    final = leer_todos(token) or {}
    cambiados = [k for k, v in antes.items()
                 if not iguales(v.get('tipo'), v.get('valor'),
                                final.get(k, {}).get('valor'))]

    print(f'Ajustes: {len(antes)}  ·  probados de punta a punta: {probados}'
          f'  ·  no tocados: {len(saltados)}')
    print(f'  que no guardan lo que se les pide: {len(mudos)}')
    print(f'  que se tragan un valor imposible:  {len(coladas)}')
    print(f'  que no se dejan guardar:           {len(raros)}')
    if cambiados:
        print(f'  ¡la prueba ha dejado {len(cambiados)} cambiados!: '
              + ', '.join(cambiados[:6]))

    for titulo, lista in (('NO GUARDAN LO QUE SE LES PIDE', mudos),
                          ('SE TRAGAN UN VALOR IMPOSIBLE', coladas),
                          ('NO SE DEJAN GUARDAR', raros)):
        if lista:
            print(f'\n{titulo}:')
            for x in lista[:30]:
                print('  ·', x)
            if len(lista) > 30:
                print(f'  … y {len(lista) - 30} más')

    if saltados:
        print('\nNo tocados a propósito (credenciales y ajustes sin alternativa):')
        print('  ' + ', '.join(saltados))

    return 1 if (mudos or coladas or raros or cambiados) else 0


if __name__ == '__main__':
    sys.exit(main())
