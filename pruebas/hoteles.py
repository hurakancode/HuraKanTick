#!/usr/bin/env python3
"""
Que un hotel no vea lo del otro.

En cuanto SSTT deja de ser un hotel y pasa a ser una cadena, el fallo más
caro deja de ser «esto no funciona» y pasa a ser «el hotel de al lado ve
mis partes». No da ningún error, no se nota por pantalla, y cuando se
descubre ya lleva meses pasando.

La prueba monta un segundo hotel de verdad —con su gente y su parte
dentro—, y luego intenta llegar a él desde el primero por todos los
caminos que se me ocurren: la lista, la ficha directa, la conversación,
las fotos, el historial y el cambio de hotel por la dirección.

Al terminar lo borra todo. Si se queda a medias, lo que deje se llama
«zzz» para poder encontrarlo.

   python3 pruebas/hoteles.py
"""

import os
import json
import random
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get(

    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
MARCA = 'zzz SECRETO DEL OTRO HOTEL zzz'



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


def llamar(metodo, ruta, cuerpo=None, token=None, tope=200000):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            return r.status, r.read(tope).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(tope).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:140]


def entrar(correo, clave=CLAVE):
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario(correo), 'password': clave})
    if c != 200:
        return None
    try:
        return json.loads(cuerpo)['token']
    except Exception:                            # noqa: BLE001
        return None


def main() -> int:
    fallos = []
    hechas = 0

    jefe = entrar('admin')
    if not jefe:
        print('No se ha podido entrar como administrador.')
        return 1

    # ---- Montar el segundo hotel ---------------------------------------
    _, c = llamar('POST', 'hoteles', {'nombre': 'zzz Hotel del Otro Lado',
                                      'codigo': 'ZZO'}, jefe)
    try:
        hotel_b = json.loads(c).get('id_hotel') or json.loads(c)['hotel']['id_hotel']
    except Exception:                            # noqa: BLE001
        print('No se ha podido crear el segundo hotel:', c[:160])
        return 1

    # Sus ubicaciones, copiadas del primero: sin ellas no se puede abrir
    # un parte, porque el alta exige un sitio de verdad.
    llamar('POST', f'hoteles/{hotel_b}/copiar', {'desde': 1}, jefe)

    gente_b = {}
    for usuario, rol in (('zzz_jefe_b', 'admin'), ('zzz_tecnico_b', 'tecnico')):
        _, r = llamar('POST', 'usuarios', {
            'usuario': usuario, 'correo': usuario + '@otrolado.local',
            'password': 'Prueba2026zz', 'rol': rol, 'id_hotel': hotel_b,
            'nombre': usuario, 'apellido1': 'Prueba'}, jefe)
        try:
            gente_b[usuario] = json.loads(r)['id_login']
        except Exception:                        # noqa: BLE001
            pass

    ficha_b = entrar('zzz_jefe_b', 'Prueba2026zz')
    if not ficha_b:
        print('No se ha podido entrar en el segundo hotel.')
        limpiar(jefe, hotel_b)
        return 1

    # Un parte dentro del segundo hotel, clonando sitio y tipo de uno del
    # primero para que pase las validaciones del alta.
    molde = molde_de_parte(llamar, jefe)
    # La ubicación tiene que ser DEL SEGUNDO hotel: pasarle la del primero
    # lo rechaza —«Esa ubicación es de otro hotel»—, que ya es una buena
    # señal, pero aquí hace falta crear el parte para seguir probando.
    _, cz = llamar('GET', 'catalogo', None, ficha_b, tope=4000000)
    rel_b = None
    try:
        combis = (json.loads(cz).get('zonas') or {}).get('combinaciones') or []
        if combis:
            rel_b = combis[0]['id_rel']
    except Exception:                            # noqa: BLE001
        pass
    if rel_b is None:
        print('El segundo hotel se ha quedado sin ubicaciones: no se puede seguir.')
        limpiar(jefe, hotel_b, gente_b)
        return 1

    _, c = llamar('POST', 'tickets', {
        **molde,
        'titulo': MARCA, 'detalle': 'esto no puede verlo el otro hotel',
        'id_rel_zona': rel_b,                    # la del SEGUNDO hotel
    }, ficha_b)
    try:
        d = json.loads(c)
        parte_b = d.get('id_ticket') or (d.get('ticket') or {}).get('id_ticket')
    except Exception:                            # noqa: BLE001
        parte_b = None

    if not parte_b:
        print('No se ha podido crear el parte del segundo hotel:', c[:200])
        limpiar(jefe, hotel_b, gente_b)
        return 1

    # ---- Intentar llegar desde el primer hotel -------------------------
    # «admin» es superadministrador de la cadena y SÍ puede: se deja fuera
    # a propósito. Los que no deben poder son los del hotel uno.
    for quien in ('ana', 'luis', 'pilar'):
        t = entrar(quien)
        if not t:
            continue

        pruebas = [
            ('la lista de partes',      'GET', 'tickets&limite=500'),
            ('la búsqueda',             'GET', 'tickets&q=SECRETO'),
            ('la ficha directa',        'GET', f'tickets/{parte_b}'),
            ('el historial',            'GET', f'tickets/{parte_b}/historial'),
            ('la conversación',         'GET', f'tickets/{parte_b}/chat'),
            ('las fotos',               'GET', f'tickets/{parte_b}/adjuntos'),
            ('cambiarse de hotel',      'GET', f'tickets&hotel={hotel_b}'),
            ('el panel con otro hotel', 'GET', f'panel&hotel={hotel_b}'),
            ('la lista de personas',    'GET', f'usuarios&hotel={hotel_b}'),
        ]
        for nombre, metodo, ruta in pruebas:
            hechas += 1
            codigo, cuerpo = llamar(metodo, ruta, None, t)
            if MARCA in cuerpo:
                fallos.append(f'«{quien}» ve el parte del otro hotel por {nombre} '
                              f'({codigo}).')
            if 'zzz_jefe_b' in cuerpo or 'otrolado.local' in cuerpo:
                fallos.append(f'«{quien}» ve a la gente del otro hotel por {nombre} '
                              f'({codigo}).')

        # Y que no pueda TOCARLO, no sólo verlo.
        for nombre, metodo, ruta, cuerpo_env in [
                ('cambiarle el estado', 'POST', f'tickets/{parte_b}/movimientos',
                 {'id_estado': 2}),
                ('escribirle',          'POST', f'tickets/{parte_b}/chat',
                 {'texto': 'hola desde el otro hotel'}),
                ('borrarlo',            'DELETE', f'tickets/{parte_b}&definitivo=1', None)]:
            hechas += 1
            codigo, _ = llamar(metodo, ruta, cuerpo_env, t)
            if 200 <= codigo < 300:
                fallos.append(f'«{quien}» PUEDE {nombre} en el parte del otro hotel '
                              f'({codigo}).')

    # ---- Y al revés: que el hotel nuevo no vea el primero --------------
    tec_b = entrar('zzz_tecnico_b', 'Prueba2026zz')
    if tec_b:
        hechas += 1
        codigo, cuerpo = llamar('GET', 'tickets&limite=500', None, tec_b)
        try:
            ajenos = [t for t in json.loads(cuerpo).get('tickets', [])
                      if t.get('id_ticket') != parte_b]
        except Exception:                        # noqa: BLE001
            ajenos = []
        if ajenos:
            fallos.append(f'El técnico del hotel nuevo ve {len(ajenos)} partes '
                          'del hotel de al lado.')

    limpiar(jefe, hotel_b, gente_b, parte_b)

    # ---- Partes que no ve NADIE ---------------------------------------
    #
    # El reverso de todo lo de arriba. Tanto cuidado en que un hotel no
    # vea lo del otro tiene un lado flaco: un parte SIN hotel no lo ve
    # ninguno. Se crea bien —el móvil dice «parte T-00484 creado»— y a
    # partir de ahí no sale en ninguna lista de ninguna persona, nadie
    # recibe el aviso y no se encuentra ni buscando su código. Y no se
    # puede ver desde la aplicación, por definición: sólo contándolos.
    #
    # Pasaba cuando quien lo abría no tenía hotel asignado. En esta base
    # había 338, uno de ellos de la propia demostración.
    hechas += 1
    if molde.get('id_elemento'):
        # Una persona sin hotel, que es quien los creaba invisibles.
        _, cu = llamar('POST', 'usuarios', {
            'usuario': f'zzzsinhotel{random.randint(1000, 9999)}',
            'password': 'Prueba2026xx', 'nombre': 'Sin', 'apellido1': 'Hotel',
            'rol': 'reportador',
        }, jefe)
        try:
            d = json.loads(cu)
            huerfano = d.get('id_login') or (d.get('usuario') or {}).get('id_login')
        except Exception:                        # noqa: BLE001
            huerfano = None

        if huerfano:
            llamar('PATCH', f'usuarios/{huerfano}', {'id_hotel': None}, jefe)
            cod, cu = llamar('POST', 'tickets', {
                **molde,
                'titulo': 'zzz parte sin hotel', 'detalle': 'x',
            }, jefe)

            if 200 <= cod < 300:
                try:
                    dd = json.loads(cu)
                    idn = dd.get('id_ticket') or (dd.get('ticket') or {}).get('id_ticket')
                except Exception:                # noqa: BLE001
                    idn = None
                # Si se ha creado, tiene que poder encontrarse por su
                # código. Si no se encuentra, es invisible.
                if idn:
                    codigo = 'T-' + str(idn).rjust(5, '0')
                    _, cu = llamar('GET', f'tickets&limite=3&q={codigo}', None, jefe)
                    try:
                        cuantos = json.loads(cu).get('total')
                    except Exception:            # noqa: BLE001
                        cuantos = 0
                    if not cuantos:
                        fallos.append(
                            f'El parte {codigo} se ha creado (el servidor dice que '
                            'sí) y NO LO VE NADIE: no sale ni buscando su propio '
                            'código. Se ha guardado sin hotel.')
                    llamar('DELETE', f'tickets/{idn}&definitivo=1', None, jefe)

            llamar('DELETE', f'usuarios/{huerfano}&definitivo=1', None, jefe)

    # ---- Y las revisiones programadas, que es el caso peor ------------
    #
    # Las crea el servidor solo, cada mes, sin que nadie las pida. No
    # llevaban hotel: nacían invisibles TODAS. Y entre ellas van las de
    # obligado cumplimiento —extintores, bocas de incendio—, que son
    # justo las que hay que poder demostrar que se hicieron. Nadie veía
    # el parte, nadie recibía el aviso y el historial se quedaba vacío.
    hechas += 1
    _, cu = llamar('POST', 'mantenimiento', {
        'nombre': f'zzzrevision{random.randint(1000, 9999)}',
        'descripcion': 'mirar', 'intervalo': 1, 'unidad': 'meses',
        'aviso_dias': 5, 'proximo': '2026-01-01',   # vencida: toca ya
    }, jefe)
    try:
        idm = json.loads(cu).get('id_mantenimiento')
    except Exception:                            # noqa: BLE001
        idm = None

    if idm:
        import subprocess
        # El generador DE ESA instalación, no el de la de al lado.
        #
        # Esto llevaba escrito /var/www/html/hurakantick. Al apuntar la prueba a
        # la instalación de MySQL, creaba la revisión en MySQL, lanzaba
        # el generador sobre la carpeta de SQLite y luego buscaba el
        # parte en MySQL. No estaba, claro, y lo cantaba como «las
        # revisiones de obligado cumplimiento nacen invisibles».
        subprocess.run(['php', entorno.DIR + '/tools/mantenimiento.php'],
                       capture_output=True, text=True, timeout=300)
        # Sin espacios en la búsqueda: un espacio sin codificar corta la
        # dirección y la consulta llega partida. La primera versión de
        # esta comprobación buscaba «zzz revision» y daba por invisible
        # un parte que se veía perfectamente.
        _, cu = llamar('GET', 'tickets&limite=5&q=zzzrevision', None, jefe)
        try:
            encontrados = json.loads(cu).get('total') or 0
            partes = json.loads(cu).get('tickets') or []
        except Exception:                        # noqa: BLE001
            encontrados, partes = 0, []
        if not encontrados:
            fallos.append('El servidor ha creado el parte de una revisión '
                          'programada y NO LO VE NADIE: se guarda sin hotel. '
                          'Las revisiones de obligado cumplimiento nacen '
                          'invisibles.')
        # Si el fallo existe, el parte generado es invisible —ése es el
        # fallo— y entonces esta limpieza tampoco lo encuentra: queda una
        # fila suelta que sólo se ve mirando la base a mano. Pasó al
        # probar el fallo a propósito. No se puede barrer lo que no se
        # ve, así que para eso está ahora
        # `php tools/diagnostico.php --arreglar-huerfanos`, que los
        # cuenta y les pone su hotel. La revisión sí se quita siempre,
        # para que no siga fabricando.
        for t in partes:
            llamar('DELETE', f'tickets/{t["id_ticket"]}&definitivo=1', None, jefe)

        # Y comprobar que la revisión se ha ido DE VERDAD.
        #
        # El servidor sólo la borra si no tiene ningún parte detrás; si
        # lo tiene, la desactiva y la conserva, que para los datos de
        # verdad es lo correcto. Pero esta prueba crea revisiones de
        # mentira: si el parte generado no se encontró —que es
        # justamente lo que esta comprobación busca—, la revisión se
        # quedaba desactivada para siempre. Se habían acumulado 44.
        _, cb = llamar('DELETE', f'mantenimiento/{idm}', None, jefe)
        if 'desactivad' in (cb or '').lower():
            # Queda algún parte suyo: se busca por su vínculo y se tira.
            _, cl = llamar('GET', f'mantenimiento/{idm}/historial', None, jefe,
                           tope=200000)
            try:
                suyos = json.loads(cl).get('tickets') or []
            except Exception:                    # noqa: BLE001
                suyos = []
            for t in suyos:
                llamar('DELETE', f'tickets/{t["id_ticket"]}&definitivo=1',
                       None, jefe)
            _, cb = llamar('DELETE', f'mantenimiento/{idm}', None, jefe)
            if 'desactivad' in (cb or '').lower():
                print(f'  AVISO: la revisión de mentira {idm} no se ha podido '
                      'borrar; queda desactivada en la base.')

    # ---- El nombre propio de cada hotel sobrevive al reparto ----------
    #
    # «Cómo se llama este departamento en este hotel» vive en la MISMA
    # fila que dice que el departamento se usa aquí. Y repartir un
    # departamento borraba todas esas filas para volver a crearlas sin el
    # alias: cambiar en qué hoteles se usa renombraba el departamento en
    # todos ellos a la vez.
    #
    # Ni hacía falta tocar el reparto a mano: la pantalla guarda las dos
    # cosas de una pasada —el alias con PATCH, el reparto con PUT—, así
    # que escribir un alias y pulsar «Guardar» lo escribía y lo borraba
    # en la misma pulsación.
    #
    # Lo cantó pruebas/campos.py por el navegador, pero sólo cuando la
    # ventana llegaba a mandar el PUT; repitiéndola, no volvió a salir.
    # Un fallo que se caza una vez de cada tantas no está cazado, así que
    # aquí se comprueba por API, que es siempre igual.
    hechas += 1
    _, cd = llamar('GET', 'departamentos', None, jefe, tope=200000)
    try:
        deps = json.loads(cd).get('departamentos') or []
    except Exception:                            # noqa: BLE001
        deps = []
    if deps:
        dep = deps[0]['id_departamento']
        antes = deps[0].get('alias')
        marca = f'zzz alias {random.randint(1000, 9999)}'
        llamar('PATCH', f'departamentos/{dep}', {'alias': marca}, jefe)
        _, ch = llamar('GET', 'hoteles', None, jefe, tope=100000)
        try:
            suyos = [h['id_hotel'] for h in json.loads(ch).get('hoteles') or []
                     if h.get('usa')]
        except Exception:                        # noqa: BLE001
            suyos = []
        llamar('PUT', f'departamentos/{dep}/hoteles',
               {'hoteles': suyos or [1]}, jefe)
        _, cd2 = llamar('GET', 'departamentos', None, jefe, tope=200000)
        try:
            ahora = [x for x in json.loads(cd2)['departamentos']
                     if x['id_departamento'] == dep][0].get('alias')
        except Exception:                        # noqa: BLE001
            ahora = None
        if ahora != marca:
            fallos.append(
                'Repartir un departamento por hoteles BORRA el nombre que '
                f'tiene en cada hotel (se puso «{marca}» y quedó '
                f'«{ahora if ahora is not None else ""}»). La pantalla de '
                'departamentos guarda el alias y el reparto en la misma '
                'pulsación, así que escribir el nombre propio lo borra en el '
                'acto, y en una cadena lo borra en todos los hoteles.')
        # Dejarlo como estaba.
        llamar('PATCH', f'departamentos/{dep}', {'alias': antes or ''}, jefe)

    print(f'Intentos de colarse: {hechas}')
    print(f'  que lo consiguen:  {len(fallos)}')
    if fallos:
        print('\nSE PASA DE UN HOTEL A OTRO:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nNinguno pasa de un hotel al otro.')
    return 0


def limpiar(jefe, hotel_b, gente_b=None, parte_b=None):
    if parte_b:
        llamar('DELETE', f'tickets/{parte_b}&definitivo=1', None, jefe)
    for idl in (gente_b or {}).values():
        llamar('DELETE', f'usuarios/{idl}&definitivo=1', None, jefe)

    # Y la gente que se le haya quedado dentro por el camino.
    #
    # Un hotel con gente no se borra: se desactiva —y hace bien—. Pero la
    # prueba sólo quitaba a la que ella misma había creado, así que si
    # alguna comprobación dejaba una persona más, el hotel se quedaba ahí
    # para siempre, desactivado y creciendo uno por pasada. Además la
    # plantilla de alta empezaba a ofrecer un hotel que no existe de
    # verdad, y el primero de la lista venía marcado.
    _, cuerpo = llamar('GET', f'usuarios&id_hotel={hotel_b}', None, jefe)
    try:
        for x in json.loads(cuerpo).get('usuarios', []):
            if int(x.get('id_hotel') or 0) == int(hotel_b):
                llamar('DELETE', f'usuarios/{x["id_login"]}&definitivo=1', None, jefe)
    except Exception:                            # noqa: BLE001
        pass

    llamar('DELETE', f'hoteles/{hotel_b}', None, jefe)

    # Y se comprueba que se ha ido de verdad. Una limpieza que no limpia
    # es peor que ninguna: se confía en ella.
    _, cuerpo = llamar('GET', 'hoteles', None, jefe)
    if f'"id_hotel":{hotel_b}' in cuerpo.replace(' ', ''):
        print(f'  AVISO: el hotel de prueba {hotel_b} no se ha podido borrar '
              'y se queda en los datos.')


if __name__ == '__main__':
    sys.exit(main())
