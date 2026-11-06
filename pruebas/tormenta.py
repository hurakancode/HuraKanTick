#!/usr/bin/env python3
"""
Cincuenta móviles volviendo de la zona sin cobertura a la vez.

Es el peor momento del día y pasa todos los días: el sótano, la cocina y
la azotea no tienen cobertura, así que la gente trabaja con la cola
llena y, al volver al pasillo, cincuenta móviles vacían su cola al mismo
tiempo. Es cuando más se escribe de golpe —varios partes y varias notas
por teléfono, todo a la vez— y es exactamente donde vivía el fallo del
cerrojo de SQLite.

`pruebas/sincronizacion.py` prueba UN móvil que se queda sin red y
vuelve. Esta prueba es la otra mitad: cincuenta a la vez.

Lo que se comprueba:

  · que no se cae ni una tanda;
  · que **llega todo**: cada parte y cada nota que el móvil tenía
    guardada tiene que estar en el servidor. Lo que no llegue, la persona
    lo da por hecho;
  · que no llega **dos veces**: si el móvil reintenta porque la respuesta
    se perdió, el parte no puede duplicarse. Se manda la misma tanda dos
    veces a propósito para verlo;
  · y que cada operación recibe su respuesta, para que el móvil sepa qué
    puede borrar de su cola.

   python3 pruebas/tormenta.py              50 móviles, 6 cosas cada uno
   python3 pruebas/tormenta.py 20 3
"""

import json
import os
import random
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid as uuidlib
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get('HURAKANTICK_API',
                      'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
# Una marca distinta en cada pasada.
#
# Con una marca fija, los restos de una pasada anterior salían en la
# búsqueda de la siguiente y se contaban como duplicados: una pasada mía
# que no pudo barrer del todo hizo que la siguiente dijera «100 partes
# han llegado dos veces», que es justo la clase de acusación que hace
# perder una tarde. Cada pasada mira sólo lo suyo.
MARCA = 'zzz tormenta ' + str(os.getpid() % 100000)


def llamar(metodo, ruta, cuerpo=None, token=None, tope=3000000):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    t = time.time()
    try:
        with urllib.request.urlopen(pet, datos, timeout=180) as r:
            return r.status, r.read(tope).decode('utf-8', 'replace'), time.time() - t
    except urllib.error.HTTPError as e:
        return e.code, e.read(tope).decode('utf-8', 'replace'), time.time() - t
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160], time.time() - t


def main() -> int:
    moviles = int(sys.argv[1]) if len(sys.argv) > 1 else 50
    por_movil = int(sys.argv[2]) if len(sys.argv) > 2 else 6

    c, cuerpo, _ = llamar('POST', 'auth/login',
                          {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print(f'No se ha podido entrar ({c}).')
        return 1
    jefe = json.loads(cuerpo)['token']

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
        print('El catálogo no da para crear partes.')
        return 2
    molde = {'id_elemento': el['id_elemento'], 'id_familia': el.get('id_familia'),
             'id_prioridad': 2, 'id_rel_zona': combis[0].get('id_rel')}

    _, cuerpo, _ = llamar('GET', 'usuarios&todos=1', None, jefe)
    try:
        gente = [u.get('usuario') for u in json.loads(cuerpo).get('usuarios', [])
                 if u.get('usuario')]
    except Exception:                            # noqa: BLE001
        gente = ['admin']

    print('Comprobando con quién se puede entrar…', flush=True)
    sirven = []
    for quien in gente:
        c, _, _ = llamar('POST', 'auth/login', {'correo': entorno.usuario(quien), 'password': CLAVE})
        if c == 200:
            sirven.append(quien)
    if not sirven:
        print('No se puede entrar como nadie.')
        return 2

    print(f'Abriendo {moviles} móviles…', flush=True)
    fichas = []
    for i in range(moviles):
        quien = sirven[i % len(sirven)]
        c, cu, _ = llamar('POST', 'auth/login', {'correo': entorno.usuario(quien), 'password': CLAVE})
        if c == 200:
            try:
                fichas.append((quien, json.loads(cu)['token']))
            except Exception:                    # noqa: BLE001
                pass
    if not fichas:
        print('Ningún móvil ha podido entrar.')
        return 1

    # Cada móvil prepara su cola, como si la hubiera llenado sin cobertura.
    colas = {}
    for n, (quien, _) in enumerate(fichas):
        ops = []
        for k in range(por_movil):
            ops.append({
                'id_local': f'local-{n}-{k}',
                'tipo': 'ticket.crear',
                'datos': {**molde,
                          'uuid': str(uuidlib.uuid4()),
                          'titulo': f'{MARCA} movil {n} cosa {k}',
                          'detalle': f'escrito sin cobertura por {quien}'},
            })
        colas[n] = ops

    resultados = {}
    candado = threading.Lock()
    cuenta = {'tandas': 0, 'se_cae': 0, 'rechazadas': 0}
    tiempos = []

    def vacia_cola(n, ficha, segunda_vez=False):
        cod, respuesta, tardo = llamar('POST', 'sync/push',
                                       {'operaciones': colas[n]}, ficha)
        with candado:
            cuenta['tandas'] += 1
            tiempos.append(tardo)
            if cod >= 500 or cod == 0:
                cuenta['se_cae'] += 1
            elif not (200 <= cod < 300):
                cuenta['rechazadas'] += 1
        try:
            d = json.loads(respuesta)
            with candado:
                resultados.setdefault(n, []).append(
                    ('segunda' if segunda_vez else 'primera',
                     d.get('resultados') or []))
        except Exception:                        # noqa: BLE001
            pass

    # ---- Todos a la vez ------------------------------------------------
    print('Todos vaciando su cola al mismo tiempo…', flush=True)
    hilos = [threading.Thread(target=vacia_cola, args=(n, f))
             for n, (_, f) in enumerate(fichas)]
    empezo = time.time()
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    duro = time.time() - empezo

    # ---- Y otra vez la MISMA cola: no puede duplicar -------------------
    #
    # Pasa de verdad: el móvil manda la tanda, la respuesta se pierde por
    # el camino, y al recuperar cobertura la manda otra vez porque cree
    # que no llegó. Si el servidor no reconoce el uuid, el hotel acaba
    # con cada parte dos veces.
    print('Y otra vez la misma cola, como un móvil que reintenta…', flush=True)
    hilos = [threading.Thread(target=vacia_cola, args=(n, f, True))
             for n, (_, f) in enumerate(fichas)]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()

    fallos = []
    tiempos.sort()

    # ---- ¿Ha llegado todo, y una sola vez? -----------------------------
    esperados = {f'{MARCA} movil {n} cosa {k}'
                 for n in range(len(fichas)) for k in range(por_movil)}
    # Pasando páginas: la API no deja pedir más de 200 de golpe, y aquí
    # se esperan trescientos. Pidiendo «limite=200» salían «200 de 300» y
    # la prueba acusaba al servidor de perder cien partes que estaban
    # todos ahí. El tope era mío.
    hay, pagina = [], 0
    while pagina < 2000:
        _, cuerpo, _ = llamar(
            'GET', f'tickets&limite=200&offset={pagina}&q={MARCA.split()[-1]}',
            None, jefe, tope=9000000)
        try:
            trozo = json.loads(cuerpo).get('tickets') or []
        except Exception:                        # noqa: BLE001
            trozo = []
        if not trozo:
            break
        hay += trozo
        pagina += 200
    titulos = [t.get('titulo_ticket') for t in hay]
    llegados = {t for t in titulos if t in esperados}
    faltan = esperados - llegados
    repetidos = {t for t in llegados if titulos.count(t) > 1}

    print(f'\n{len(fichas)} móviles · {por_movil} cosas cada uno '
          f'= {len(esperados)} por mandar')
    print(f'  tandas enviadas:   {cuenta["tandas"]} (cada cola, dos veces)')
    print(f'  tiempo de la tanda: normal {tiempos[len(tiempos)//2]:.2f}s  ·  '
          f'el peor {tiempos[-1]:.2f}s  ·  todos en {duro:.1f}s')
    print(f'  partes que han llegado: {len(llegados)} de {len(esperados)}')
    print(f'  partes duplicados:      {len(repetidos)}')

    if cuenta['se_cae']:
        fallos.append(f'{cuenta["se_cae"]} tandas se caen con error del '
                      'servidor. Esos móviles se quedan con la cola llena.')
    if cuenta['rechazadas']:
        fallos.append(f'{cuenta["rechazadas"]} tandas se rechazan.')
    if faltan:
        fallos.append(f'{len(faltan)} partes escritos sin cobertura NO han '
                      'llegado. Quien los escribió los da por hechos y nadie '
                      'los echa en falta.')
    if repetidos:
        fallos.append(f'{len(repetidos)} partes han llegado DOS veces: el '
                      'móvil que reintenta duplica el trabajo del hotel.')

    # Cada operación tiene que tener su respuesta, o el móvil no sabe qué
    # borrar de su cola y la reintentará para siempre.
    sin_respuesta = 0
    for n, tandas in resultados.items():
        for cual, res in tandas:
            if len(res) != por_movil:
                sin_respuesta += 1
    if sin_respuesta:
        fallos.append(f'{sin_respuesta} tandas no devuelven una respuesta por '
                      'operación: el móvil no sabe qué puede borrar de su cola.')

    print(f'  que sale mal:           {len(fallos)}')
    if fallos:
        print('\nCUANDO CINCUENTA MÓVILES VUELVEN A LA VEZ:')
        for f in fallos:
            print('  ·', f)

    # ---- Barrer --------------------------------------------------------
    print('\nBarriendo…', flush=True)
    ids = sorted({t.get('id_ticket') for t in hay
                  if t.get('titulo_ticket') in esperados and t.get('id_ticket')})
    quitados = 0
    for idt in ids:
        cod, _, _ = llamar('DELETE', f'tickets/{idt}&definitivo=1', None, jefe)
        if 200 <= cod < 300:
            quitados += 1
    print(f'  quitados {quitados} de {len(ids)} partes')

    if fallos:
        return 1
    print('\nLlega todo, una sola vez, y ninguna tanda se cae.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
