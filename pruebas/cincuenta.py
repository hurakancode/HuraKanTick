#!/usr/bin/env python3
"""
Cincuenta personas trabajando a la vez.

Es la pregunta que decide cómo se instala esto, y no estaba contestada.
SQLite es un fichero: mientras alguien escribe, los demás esperan. Con
cuatro personas no se nota; con cincuenta a las ocho de la mañana puede
aparecer «database is locked» y perderse un parte. MySQL no tiene ese
problema, pero hay que montar un servidor.

Así que aquí se simula el turno de mañana de verdad: cincuenta sesiones
a la vez, cada una haciendo lo que hace una persona —mirar su lista,
abrir un parte, escribir una nota, cambiar un estado, dar un parte
nuevo— durante un rato, y se cuenta lo que sale mal:

  · peticiones que fallan con error del servidor (500);
  · peticiones que fallan por bloqueo de la base;
  · notas o partes que se dan por escritos y luego no están —lo peor:
    la persona cree que lo ha dicho—;
  · y cuánto se tarda cuando hay cincuenta a la vez, que es lo que de
    verdad se nota.

   python3 pruebas/cincuenta.py                 contra la instalación de siempre
   HURAKANTICK_API=... python3 pruebas/cincuenta.py    contra otra
   python3 pruebas/cincuenta.py 20 30           20 personas, 30 segundos
"""

import json
import os
import random
import sys
import threading
import time
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get('HURAKANTICK_API',
                      'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
MARCA = 'zzz turno de manana'


def llamar(metodo, ruta, cuerpo=None, token=None, tope=400000):
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
            return r.status, r.read(tope).decode('utf-8', 'replace'), time.time() - t
    except urllib.error.HTTPError as e:
        return e.code, e.read(tope).decode('utf-8', 'replace'), time.time() - t
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160], time.time() - t


def main() -> int:
    cuantas = int(sys.argv[1]) if len(sys.argv) > 1 else 50
    segundos = int(sys.argv[2]) if len(sys.argv) > 2 else 45

    c, cuerpo, _ = llamar('POST', 'auth/login',
                          {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print(f'No se ha podido entrar ({c}).')
        return 1
    jefe = json.loads(cuerpo)['token']

    # El molde para dar partes, del catálogo (no de un parte existente:
    # eso depende de los datos que haya).
    _, cuerpo, _ = llamar('GET', 'catalogo', None, jefe, tope=9000000)
    try:
        cat = json.loads(cuerpo)
        el = next((x for x in (cat.get('elementos') or [])
                   if x.get('id_elemento')), None)
        combis = ((cat.get('zonas') or {}).get('combinaciones')
                  if isinstance(cat.get('zonas'), dict) else None) or []
    except Exception:                            # noqa: BLE001
        el, combis = None, []
    if not el or not combis:
        print('El catálogo no da para crear partes: no se puede simular el turno.')
        return 2
    molde = {'id_elemento': el['id_elemento'], 'id_familia': el.get('id_familia'),
             'id_prioridad': 2, 'id_rel_zona': combis[0].get('id_rel')}

    # Cincuenta sesiones. La gente de demostración es poca, así que se
    # reparte: varias sesiones de la misma persona es exactamente lo que
    # pasa cuando alguien tiene el móvil y el ordenador abiertos.
    _, cuerpo, _ = llamar('GET', 'usuarios&todos=1', None, jefe)
    try:
        gente = [u.get('usuario') for u in json.loads(cuerpo).get('usuarios', [])
                 if u.get('usuario') and not (u.get('bloqueo') or 0)]
    except Exception:                            # noqa: BLE001
        gente = ['admin']
    gente = gente or ['admin']

    # Primero se comprueba con QUIÉN se puede entrar, una vez por
    # persona. Antes se abrían las cincuenta sesiones a ciegas repartidas
    # entre toda la gente, y si una tenía otra contraseña —un resto de
    # otra prueba— fallaban todas sus sesiones: salía «sólo han entrado
    # 43 de 50», que asusta y no es del programa. Y de paso se le
    # gastaban intentos a esa cuenta hasta bloquearla.
    print('Comprobando con quién se puede entrar…', flush=True)
    sirven = []
    for quien in gente:
        c, _, _ = llamar('POST', 'auth/login',
                         {'correo': entorno.usuario(quien), 'password': CLAVE})
        if c == 200:
            sirven.append(quien)
    fuera = [q for q in gente if q not in sirven]
    if fuera:
        print('  (no se entra como: ' + ', '.join(fuera[:5])
              + ' — otra contraseña; no se usan)')
    if not sirven:
        print('No se puede entrar como nadie con la contraseña de la '
              'demostración.')
        return 2
    gente = sirven

    print(f'Abriendo {cuantas} sesiones…', flush=True)
    fichas = []
    for i in range(cuantas):
        quien = gente[i % len(gente)]
        c, cu, _ = llamar('POST', 'auth/login',
                          {'correo': entorno.usuario(quien), 'password': CLAVE})
        if c == 200:
            try:
                fichas.append((quien, json.loads(cu)['token']))
            except Exception:                    # noqa: BLE001
                pass
    if len(fichas) < cuantas:
        print(f'  sólo han entrado {len(fichas)} de {cuantas}')
    if not fichas:
        print('Nadie ha podido entrar.')
        return 1

    cuenta = {'peticiones': 0, 'se_cae': 0, 'bloqueada': 0, 'otros_fallos': 0}
    tiempos = []
    escritos = []
    candado = threading.Lock()
    hasta = time.time() + segundos

    def persona(quien, ficha, n):
        mios = []
        while time.time() < hasta:
            # Lo que hace alguien en un turno, con sus proporciones: casi
            # todo es mirar, de vez en cuando se escribe.
            dado = random.random()
            if dado < 0.35:
                paso = ('GET', 'tickets&limite=20', None)
            elif dado < 0.50:
                paso = ('GET', 'panel', None)
            elif dado < 0.60:
                paso = ('GET', 'tickets&limite=20&mios=1', None)
            elif dado < 0.70:
                paso = ('GET', 'sync/pull', None)
            elif dado < 0.85 and mios:
                texto = f'{MARCA} nota de {quien} numero {len(mios)}'
                paso = ('POST', f'tickets/{mios[0]}/movimientos', {'nota': texto})
            else:
                paso = ('POST', 'tickets',
                        {**molde, 'titulo': f'{MARCA} {quien} {n}',
                         'detalle': 'parte del turno de prueba'})

            metodo, ruta, cuerpo = paso
            cod, respuesta, tardo = llamar(metodo, ruta, cuerpo, ficha,
                                           tope=3000000)

            with candado:
                cuenta['peticiones'] += 1
                tiempos.append(tardo)
                if cod >= 500 or cod == 0:
                    cuenta['se_cae'] += 1
                elif not (200 <= cod < 300):
                    cuenta['otros_fallos'] += 1

            if metodo == 'POST' and 200 <= cod < 300:
                # Un parte nuevo sólo cuando se ha pedido un parte nuevo.
                #
                # Antes se sacaba el id_ticket de CUALQUIER respuesta, y
                # la de una nota también lo trae: el mismo parte se
                # apuntaba veinte veces, y al barrer se intentaba borrar
                # veinte veces. El primero sí, los otros no, y la prueba
                # decía «quitados 1563 de 2988» habiéndolos quitado
                # todos. Un recuento que asusta sin motivo se acaba
                # ignorando igual que uno que calla.
                if ruta == 'tickets':
                    try:
                        d = json.loads(respuesta)
                        idt = d.get('id_ticket') or (d.get('ticket') or {}).get('id_ticket')
                        if idt:
                            mios.insert(0, idt)
                            with candado:
                                escritos.append(('parte', idt))
                    except Exception:            # noqa: BLE001
                        pass
                elif 'movimientos' in ruta and cuerpo:
                    with candado:
                        escritos.append(('nota', cuerpo.get('nota')))

            time.sleep(random.uniform(0.05, 0.3))

    hilos = [threading.Thread(target=persona, args=(q, f, i))
             for i, (q, f) in enumerate(fichas)]
    empezo = time.time()
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    duro = time.time() - empezo

    # ---- ¿Está de verdad lo que se dio por escrito? -------------------
    partes = sorted({x for t, x in escritos if t == 'parte'})
    notas = [x for t, x in escritos if t == 'nota' and x]
    perdidos = []
    for idt in partes[:40]:
        cod, _, _ = llamar('GET', f'tickets/{idt}', None, jefe)
        if not (200 <= cod < 300):
            perdidos.append(idt)

    # ---- Y POR QUÉ se han caído -----------------------------------
    #
    # La respuesta al móvil dice «No se ha podido completar la operación
    # en la base de datos» y nada más —y hace bien: a un técnico en un
    # pasillo no se le enseña un volcado de SQL—. El motivo está en el
    # registro de errores del propio programa, que es donde hay que
    # mirarlo. La primera versión de esta prueba buscaba la palabra
    # «locked» en la respuesta, no la encontraba nunca, y daba 269
    # caídas «sin motivo conocido» teniéndolo apuntado al lado.
    motivos = []
    cod, cuerpo, _ = llamar('GET', 'errores&limite=10', None, jefe)
    if 200 <= cod < 300:
        try:
            for e in (json.loads(cuerpo).get('errores') or [])[:6]:
                texto = str(e.get('mensaje') or '')
                veces = e.get('veces') or 1
                if texto:
                    motivos.append((int(veces), texto[:90]))
        except Exception:                        # noqa: BLE001
            pass
    bloqueos = sum(v for v, t in motivos if 'locked' in t.lower()
                   or 'bloquea' in t.lower())

    tiempos.sort()
    def pct(p):
        return tiempos[min(int(len(tiempos) * p), len(tiempos) - 1)] if tiempos else 0

    print(f'\n{len(fichas)} sesiones a la vez durante {duro:.0f}s')
    print(f'  peticiones:            {cuenta["peticiones"]}'
          f'  ({cuenta["peticiones"] / max(duro, 1):.0f} por segundo)')
    print(f'  partes dados de alta:  {len(partes)}')
    print(f'  notas escritas:        {len(notas)}')
    print(f'  tiempo normal:         {pct(0.5):.2f}s  ·  '
          f'el 95%: {pct(0.95):.2f}s  ·  el peor: {pct(1.0):.2f}s')

    fallos = []
    if cuenta['se_cae']:
        fallos.append(f'{cuenta["se_cae"]} peticiones se caen con error del '
                      f'servidor de {cuenta["peticiones"]}.')
    if bloqueos:
        fallos.append(f'La base se queda bloqueada ({bloqueos} veces apuntadas). '
                      'Con SQLite pasa cuando varios escriben a la vez: cada una '
                      'es una nota o un parte que la persona da por escrito.')
    if perdidos:
        fallos.append(f'{len(perdidos)} partes se dieron por creados y no '
                      'están. Quien los dio cree que los ha dado.')
    if pct(0.95) > 3.0:
        fallos.append(f'Una de cada veinte peticiones tarda más de 3 s '
                      f'({pct(0.95):.1f}s): con cincuenta personas se nota.')

    print(f'  que sale mal:          {len(fallos)}')
    if cuenta['otros_fallos']:
        print(f'  (y {cuenta["otros_fallos"]} rechazos con error limpio, que '
              'es lo correcto cuando algo no se permite)')

    if motivos:
        print('\n  por qué se caen, según el registro del propio programa:')
        for veces, texto in motivos[:4]:
            print(f'    [{veces}] {texto}')

    if fallos:
        print('\nCON CINCUENTA A LA VEZ:')
        for f in fallos:
            print('  ·', f)

    # ---- Barrer -------------------------------------------------------
    print('\nBarriendo lo que ha creado la prueba…', flush=True)
    quitados = 0
    for idt in partes:
        cod, _, _ = llamar('DELETE', f'tickets/{idt}&definitivo=1', None, jefe)
        if 200 <= cod < 300:
            quitados += 1
    print(f'  quitados {quitados} de {len(partes)} partes')
    if quitados < len(partes):
        print('  AVISO: no se han podido quitar todos; míralos en la papelera.')

    return 1 if fallos else 0


if __name__ == '__main__':
    sys.exit(main())
