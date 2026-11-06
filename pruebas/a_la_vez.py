#!/usr/bin/env python3
"""
Dos personas tocando lo mismo a la vez.

Pasa todos los días: el responsable asigna un parte desde la oficina justo
cuando el técnico, en el pasillo, lo está cerrando. O dos técnicos se
quedan el mismo parte con dos segundos de diferencia. Lo que NO puede
pasar es que el trabajo de uno desaparezca sin que nadie se entere.

Hay dos maneras de comportarse bien, y las dos valen:

  · **Avisar del choque** y dejar que una persona decida. Es lo que hace
    SSTT con los cambios que vienen de un móvil sin cobertura.
  · **Sumar**, cuando las dos cosas caben juntas: dos notas distintas en
    el mismo parte no se contradicen, se escriben las dos.

Lo que no vale es que el segundo cambio pise al primero en silencio, ni
que un contador acabe descuadrado porque dos peticiones llegaron a la vez.

   python3 pruebas/a_la_vez.py
"""

import os
import json
import sys
import threading
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get(

    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
MARCA = 'zzz dos a la vez'



def molde_de_parte(llamar, token):
    """Los datos mínimos para crear un parte que el servidor acepte.

    Antes esto se clonaba del primer parte de la lista, y eso depende de
    los datos que haya: en la instalación MySQL ni uno de los cuarenta
    partes visibles tenía tipo de avería, así que el alta se rechazaba
    con «Indica qué falla» y la prueba lo contaba como que no se podían
    crear partes. No era eso.

    Ahora sale del CATÁLOGO, que siempre tiene tipos de avería, y la
    ubicación se coge de un parte que ya tenga una (es opcional).
    """
    base = {'id_prioridad': 2}

    # El catálogo pesa cientos de kilobytes y algunas pruebas recortan la
    # respuesta por defecto: cortado, no es JSON válido, el «except» se lo
    # tragaba en silencio y este molde salía sin tipo de avería. Entonces
    # el alta se rechazaba y la prueba acusaba al servidor. Así que se
    # pide entero cuando la función lo permite, y si algo va mal se DICE.
    try:
        _, cuerpo = llamar('GET', 'catalogo', None, token, tope=8000000)
    except TypeError:
        _, cuerpo = llamar('GET', 'catalogo', None, token)
    try:
        import json as _json
        cat = _json.loads(cuerpo)
    except Exception as e:                       # noqa: BLE001
        print(f'  AVISO: no se ha podido leer el catálogo ({e}): el parte de '
              'prueba saldrá incompleto.')
        cat = {}

    el = next((x for x in (cat.get('elementos') or []) if x.get('id_elemento')), None)
    if el:
        base['id_elemento'] = el.get('id_elemento')
        base['id_familia'] = el.get('id_familia')
    pr = (cat.get('prioridades') or [None])[0]
    if pr:
        base['id_prioridad'] = pr.get('id_prioridad') or 2

    try:
        _, cuerpo = llamar('GET', 'tickets&limite=30', None, token, tope=4000000)
    except TypeError:
        _, cuerpo = llamar('GET', 'tickets&limite=30', None, token)
    try:
        import json as _json
        for t in _json.loads(cuerpo).get('tickets', []):
            if t.get('id_rel_zona'):
                base['id_rel_zona'] = t['id_rel_zona']
                break
    except Exception:                            # noqa: BLE001
        pass

    # La ubicación es OBLIGATORIA («Indica dónde es»), así que si ningún
    # parte de los visibles tiene una —pasa en la instalación MySQL— se
    # coge del catálogo de ubicaciones.
    if not base.get('id_rel_zona'):
        combis = ((cat.get('zonas') or {}).get('combinaciones')
                  if isinstance(cat.get('zonas'), dict) else None) or []
        if combis:
            base['id_rel_zona'] = combis[0].get('id_rel')
    return base


def llamar(metodo, ruta, cuerpo=None, token=None, tope=4000000):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            # 200 000 no llegaba: el catálogo pesa casi 300 KB y
            # llegaba cortado, con lo que no era JSON válido.
            return r.status, r.read(tope).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(tope).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:140]


def entrar(quien):
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario(quien), 'password': CLAVE})
    return json.loads(cuerpo)['token'] if c == 200 else None


def main() -> int:
    fallos = []
    jefe = entrar('admin')
    if not jefe:
        print('No se ha podido entrar.')
        return 1

    # Un parte de usar y tirar, clonado de uno que ya existe.
    molde = molde_de_parte(llamar, jefe)
    if not molde.get('id_elemento'):
        print('El catálogo no tiene ningún tipo de avería: no se puede '
              'crear un parte para probar.')
        return 1
    # IMPORTANTE: el parte se crea en el departamento de quienes van a
    # tocarlo. Sin esto, el reparto automático lo mandaba al departamento
    # del tipo de avería del parte copiado —que cambia de una instalación a
    # otra— y el técnico recibía un 403 legítimo. La prueba lo cantaba como
    # «la nota no llega», cuando lo que pasaba es que ese parte no era suyo.
    def crear_parte(titulo):
        _, cc = llamar('POST', 'tickets', {
            **molde,
            'titulo': titulo, 'detalle': 'para probar dos cambios a la vez',
            'id_grupo_asignado': grupo,
        }, jefe)
        try:
            dd = json.loads(cc)
            return dd.get('id_ticket') or (dd.get('ticket') or {}).get('id_ticket')
        except Exception:                        # noqa: BLE001
            return None

    c = ''
    ana, luis = entrar('ana'), entrar('luis')

    # El número de cada uno sale del propio acceso: hace falta para
    # «me lo quedo», que es asignarse a uno mismo.
    ids, deptos = {}, {}
    for nombre, quien in (('ana', 'ana'), ('luis', 'luis')):
        c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario(quien), 'password': CLAVE})
        try:
            u = json.loads(cuerpo)['usuario']
            ids[nombre] = u['id_login']
            deptos[nombre] = u.get('id_departamento')
        except Exception:                        # noqa: BLE001
            ids[nombre] = deptos[nombre] = None

    grupo = deptos.get('luis') or deptos.get('ana')
    idt = crear_parte(MARCA)
    if not idt:
        print('No se ha podido crear el parte de prueba.')
        return 1

    # ---- 1. Dos notas a la vez: tienen que estar las dos --------------
    respuestas = {}

    def nota(nombre, ficha, texto):
        respuestas[nombre] = llamar('POST', f'tickets/{idt}/movimientos',
                                    {'nota': texto}, ficha)

    hilos = [threading.Thread(target=nota, args=('ana', ana, f'{MARCA} nota de ana')),
             threading.Thread(target=nota, args=('luis', luis, f'{MARCA} nota de luis'))]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()

    _, cuerpo = llamar('GET', f'tickets/{idt}/historial', None, jefe)
    for quien in ('ana', 'luis'):
        if f'{MARCA} nota de {quien}' not in cuerpo:
            cod = respuestas.get(quien, (0, ''))[0]
            fallos.append(f'La nota de «{quien}», escrita a la vez que la otra, '
                          f'no está en el historial (el servidor contestó {cod}).')

    # ---- 2. Dos cambios de estado a la vez ----------------------------
    # Aquí sí hay un único valor en juego: uno gana. Lo que se mira es que
    # el perdedor se entere, y que el parte quede en un estado coherente.
    estados = {}

    def mover(nombre, ficha, estado):
        estados[nombre] = llamar('POST', f'tickets/{idt}/movimientos',
                                 {'id_estado': estado, 'nota': f'{MARCA} {nombre}'}, ficha)

    hilos = [threading.Thread(target=mover, args=('ana', ana, 3)),
             threading.Thread(target=mover, args=('luis', luis, 4))]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()

    _, cuerpo = llamar('GET', f'tickets/{idt}', None, jefe)
    try:
        t = json.loads(cuerpo).get('ticket') or json.loads(cuerpo)
        estado_final = t.get('id_estado')
    except Exception:                            # noqa: BLE001
        estado_final = None

    if estado_final not in (3, 4):
        fallos.append(f'Tras dos cambios de estado a la vez, el parte se ha quedado '
                      f'en un estado que no pidió nadie ({estado_final}).')

    codigos = [v[0] for v in estados.values()]
    if all(200 <= c < 300 for c in codigos):
        # Las dos dicen que sí: no es un fallo por sí mismo —la segunda
        # pisa a la primera y queda anotado en el historial— pero sí lo
        # sería que el historial no lo contara.
        _, hist = llamar('GET', f'tickets/{idt}/historial', None, jefe)
        if hist.count(MARCA) < 2:
            fallos.append('Dos cambios de estado a la vez y el historial sólo '
                          'recuerda uno: el trabajo del otro desaparece sin rastro.')

    # ---- 3. Quedárselo dos a la vez ----------------------------------
    idt2 = crear_parte(MARCA + ' para coger')

    if idt2:
        cogidas = {}

        # «Me lo quedo» es asignarse uno mismo: no hay ningún campo
        # «coger». La primera versión mandaba un campo inventado, las dos
        # llamadas no hacían nada, y la prueba cantaba que el parte se
        # quedaba sin dueño cuando lo que pasaba es que nadie lo cogía.
        def coger(nombre, ficha, yo):
            cogidas[nombre] = llamar('POST', f'tickets/{idt2}/movimientos',
                                     {'id_persona_asignada': yo}, ficha)

        hilos = [threading.Thread(target=coger, args=('ana', ana, ids['ana'])),
                 threading.Thread(target=coger, args=('luis', luis, ids['luis']))]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()

        _, cuerpo = llamar('GET', f'tickets/{idt2}', None, jefe)
        try:
            t = json.loads(cuerpo).get('ticket') or json.loads(cuerpo)
            duenyo = t.get('id_persona_asignada')
        except Exception:                        # noqa: BLE001
            duenyo = 'ilegible'
        if duenyo in (None, 'ilegible'):
            fallos.append('Dos personas se quedan el parte a la vez y acaba '
                          f'sin dueño ({duenyo}).')

    # ---- 3b. Quedárselo dos a la vez, COMO LO MANDA LA APP -------------
    #
    # La app no manda sólo «asígnamelo»: manda también lo que creía saber
    # (version_base, asignado_base). Con eso el servidor tiene que dejar
    # pasar a UNO y contestar 409 al otro. Si los dos reciben un sí, los
    # dos técnicos creen que el parte es suyo y van los dos a la
    # habitación. Se repite varias veces porque un choque de verdad
    # depende de milésimas: una sola ronda puede salir bien por suerte.
    rondas_dobles, rondas, extra = 0, 8, []
    for ronda in range(rondas):
        idr = crear_parte(f'{MARCA} carrera {ronda}')
        if not idr:
            extra.append(idr)
            continue
        extra.append(idr)
        _, cc = llamar('GET', f'tickets/{idr}', None, jefe)
        try:
            t0 = json.loads(cc).get('ticket') or json.loads(cc)
        except Exception:                        # noqa: BLE001
            continue
        base = {'version_base': t0.get('id_version') or 1,
                'asignado_base': t0.get('id_persona_asignada'),
                'estado_base': t0.get('id_estado')}
        salida = threading.Barrier(2)
        resp = {}

        def coge(nombre, ficha, yo):
            salida.wait()
            resp[nombre] = llamar('PUT', f'tickets/{idr}',
                                  {**base, 'id_persona_asignada': yo}, ficha)

        hilos = [threading.Thread(target=coge, args=('ana', ana, ids['ana'])),
                 threading.Thread(target=coge, args=('luis', luis, ids['luis']))]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()
        cods = sorted(v[0] for v in resp.values())
        if all(200 <= x < 300 for x in cods):
            rondas_dobles += 1
        elif cods != [200, 409]:
            fallos.append(f'Ronda {ronda} de «me lo quedo» a la vez: respuestas '
                          f'{cods}, se esperaba un 200 y un 409.')
            continue
        else:
            ganador = 'ana' if resp['ana'][0] == 200 else 'luis'
            _, cc = llamar('GET', f'tickets/{idr}', None, jefe)
            try:
                fin = (json.loads(cc).get('ticket') or json.loads(cc)).get('id_persona_asignada')
            except Exception:                    # noqa: BLE001
                fin = None
            if fin != ids[ganador]:
                fallos.append(f'Ronda {ronda}: el servidor dio el parte a {ganador} '
                              f'pero ha quedado asignado a {fin}.')
    if rondas_dobles:
        fallos.append(f'«Me lo quedo» a la vez con la versión que manda la app: en '
                      f'{rondas_dobles} de {rondas} rondas el servidor dijo que sí a '
                      'LOS DOS. Cada técnico cree que el parte es suyo.')

    # ---- Barrer -------------------------------------------------------
    for x in [idt, idt2] + extra:
        if x:
            llamar('DELETE', f'tickets/{x}&definitivo=1', None, jefe)

    # ---- 4. Veinte mensajes a la vez en la conversación ----------------
    #
    # Dos personas a la vez es lo corriente; veinte es la avería de las
    # ocho de la mañana con media plantilla escribiendo en el mismo
    # parte. Lo que no puede pasar es que se pierda ninguno: un mensaje
    # que no aparece no se echa en falta —nadie sabe que existió— y la
    # persona que lo escribió cree que lo ha dicho.
    idt3 = crear_parte(MARCA + ' conversacion')
    if idt3:
        import threading as _th
        puestos, respuestas3 = [], {}

        def escribe(n):
            texto = f'{MARCA} mensaje numero {n}'
            quien = ana if n % 2 else luis
            respuestas3[n] = llamar('POST', f'tickets/{idt3}/movimientos',
                                    {'nota': texto}, quien)
            puestos.append(texto)

        hilos = [_th.Thread(target=escribe, args=(n,)) for n in range(1, 21)]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()

        _, hist = llamar('GET', f'tickets/{idt3}/historial', None, jefe)
        perdidos = [t for t in puestos if t not in hist]
        rechazados = [n for n, v in respuestas3.items() if not (200 <= v[0] < 300)]
        if perdidos:
            fallos.append(
                f'Con veinte personas escribiendo a la vez se han perdido '
                f'{len(perdidos)} mensajes de veinte. Quien los escribió cree '
                'que los ha dicho, y nadie los echa en falta.')
        if rechazados:
            codigos = sorted({respuestas3[n][0] for n in rechazados})
            fallos.append(f'{len(rechazados)} de veinte mensajes a la vez se '
                          f'rechazan (códigos {codigos}).')
        llamar('DELETE', f'tickets/{idt3}&definitivo=1', None, jefe)

    print('Choques probados: 4 (dos notas, dos estados, dos cogiéndolo,')
    print('                     veinte escribiendo a la vez)')
    print(f'  que salen mal:   {len(fallos)}')
    if fallos:
        print('\nLO QUE PASA CUANDO DOS TOCAN LO MISMO:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nNada se pierde en silencio cuando dos tocan el mismo parte.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
