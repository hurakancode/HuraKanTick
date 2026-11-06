#!/usr/bin/env python3
"""
Quién puede hacer qué.

Con cincuenta personas dentro, el fallo caro no es que algo no funcione:
es que funcione para quien no debe. Un técnico que puede borrar a un
compañero, o alguien de recepción que lee los partes de otro hotel, no da
ningún error por pantalla. Simplemente pasa, y no se entera nadie.

Esto llama a cada punto de la API con los cuatro papeles —administrador,
responsable, técnico y sólo avisar— y compara lo que contesta con lo que
DEBERÍA contestar. Dos clases de fallo, y la primera es la grave:

  · DE MÁS: alguien puede algo que no le toca. Es un agujero.
  · DE MENOS: alguien NO puede algo que sí le toca. Es una pantalla que
    no funciona para media plantilla.

Las dos salen listadas por separado, porque se arreglan distinto.

   python3 pruebas/permisos.py
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
# Papel de cada cuenta de la base de demostración.
GENTE = {
    'admin':  'administrador',
    'ana':    'responsable',
    'luis':   'tecnico',
    'pilar':  'solo avisar',
}

# (método, ruta, cuerpo, quién DEBE poder)
#
# «leer» lo abre casi todo el mundo; lo que se vigila de verdad es quién
# puede CAMBIAR cosas. Donde la respuesta correcta es un error de datos
# (422) también vale: significa que pasó el control de permisos, que es
# lo que se está midiendo.
PRUEBAS = [
    # --- lectura ---------------------------------------------------
    ('GET',  'catalogo',        None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'tickets',         None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'panel',           None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'instrucciones',   None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'notificaciones',  None, {'admin', 'ana', 'luis', 'pilar'}),

    # --- lectura abierta a propósito, comprobada una por una --------
    # Se deja así porque se ha mirado QUÉ devuelve, no sólo si contesta:
    #   · /informes guarda los informes de cada cual: a un técnico le
    #     llega su lista, que está vacía. No ve los de nadie más.
    #   · /mantenimiento es el plan de revisiones. Al técnico le caen los
    #     partes que salen de ahí, así que verlo no le da nada nuevo.
    #   · /usuarios, para quien no es administrador, devuelve SÓLO nombre,
    #     papel y departamento. Ni correo, ni teléfono, ni última entrada.
    #     Hace falta para poder asignarle un parte a un compañero.
    #   · /hoteles es la lista para el selector de hotel.
    #   · /ajustes lo necesita la aplicación para saber qué enseñar, y las
    #     credenciales van tapadas (lo comprueba mirar_secretos).
    ('GET',  'informes',        None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'mantenimiento',   None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'usuarios',        None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'hoteles',         None, {'admin', 'ana', 'luis', 'pilar'}),
    ('GET',  'ajustes',         None, {'admin', 'ana', 'luis', 'pilar'}),

    # --- cosas de administrador ------------------------------------
    ('GET',  'papelera',        None, {'admin'}),
    ('GET',  'errores',         None, {'admin'}),
    ('GET',  'seguridad',       None, {'admin'}),
    ('GET',  'personal/plantilla', None, {'admin'}),

    # --- escritura: lo que de verdad importa -----------------------
    ('POST', 'usuarios',        {'usuario': 'zzz_prueba_permisos', 'password': 'Prueba2026zz'},
             {'admin'}),
    ('POST', 'usuarios/lote',   {'accion': 'bloquear', 'ids': [99999]}, {'admin'}),
    ('POST', 'departamentos',   {'nombre': 'zzz prueba permisos'}, {'admin'}),
    ('POST', 'hoteles',         {'nombre': 'zzz prueba permisos'}, {'admin'}),
    ('POST', 'mantenimiento',   {'nombre': 'zzz prueba', 'intervalo': 1, 'unidad': 'meses'},
             {'admin', 'ana'}),
    ('POST', 'instrucciones',   {'titulo': 'zzz prueba', 'contenido': 'uno'},
             {'admin', 'ana'}),
    ('POST', 'personal/importar', {'simular': True}, {'admin'}),
    ('POST', 'personal/exportar', {'ids': [1]}, {'admin'}),
    ('PATCH', 'ajustes',        {'cambios': {'zzz_inventado': '1'}}, {'admin'}),
    ('POST', 'errores/visto',   {'id_error': None}, {'admin'}),

    # --- LO QUE DE VERDAD HARÍA DAÑO -------------------------------
    # OJO: estas pruebas van contra cosas DESECHABLES que se crean al
    # empezar y se tiran al acabar ({usuario}, {hotel}, {parte}).
    #
    # La primera versión apuntaba a datos de verdad, y el administrador
    # —que SÍ tiene permiso— los borró: desactivó el Hotel Benalma de la
    # base de pruebas y estuve un rato buscando un fallo inexistente
    # porque «copiar ubicaciones» decía que el hotel de origen no existía.
    # Una prueba que rompe lo que mide no vale para nada.
    # Tocar a OTRA persona: cambiarle los datos, quitarle el acceso,
    # borrarla. Si esto se le cuela a un técnico, con cincuenta personas
    # dentro no se entera nadie hasta que alguien no puede entrar.
    ('PUT',    'usuarios/{usuario}', {'nombre': 'Secuestrado'}, {'admin'}),
    ('DELETE', 'usuarios/{usuario}', None, {'admin'}),
    ('POST',   'usuarios/{usuario}/clave', {'password': 'otracosa123'}, {'admin'}),
    ('POST',   'usuarios/{usuario}/desbloquear', None, {'admin'}),

    # Borrar un parte para siempre, y vaciar la papelera.
    ('DELETE', 'tickets/{parte}&definitivo=1', None, {'admin'}),
    ('DELETE', 'papelera/1',    None, {'admin'}),

    # Las llaves del sistema: copias, Teams, renovar claves.
    ('POST',   'sistema/renovar', None, {'admin'}),
    ('POST',   'sistema/teams',   {'probar': True}, {'admin'}),
    ('GET',    'sistema/copias',  None, {'admin'}),
    ('POST',   'sistema/copias',  None, {'admin'}),
    ('GET',    'sistema/seguridad', None, {'admin'}),

    # Catálogos comunes a toda la cadena: un técnico que los cambia se
    # lleva por delante los informes de todos los hoteles.
    ('POST',   'sistema/familias', {'nombre': 'zzz prueba'}, {'admin'}),
    ('POST',   'sistema/campos',   {'clave': 'zzz', 'etiqueta': 'zzz'}, {'admin'}),
    ('POST',   'sistema/atajos',   {'nombre': 'zzz', 'filtro': '{}'}, {'admin'}),
    ('POST',   'departamentos/{depto}', {'nombre': 'zzz renombrado'}, {'admin'}),
    ('DELETE', 'departamentos/{depto}', None, {'admin'}),
    ('DELETE', 'hoteles/{hotel}',   None, {'admin'}),
    ('POST',   'hoteles/{hotel}/restablecer', {'confirmacion': 'nada'}, {'admin'}),
]


def llamar(metodo, ruta, cuerpo=None, token=None, tope=400):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    pet.add_header('Accept', 'application/json')
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=25) as r:
            return r.status, r.read(tope).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(tope).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:120]


def entrar(quien):
    # Sin tope: recortar la respuesta parte el JSON por la mitad y el
    # token se pierde, que es justo lo que pasaba.
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario(quien), 'password': CLAVE},
                       tope=20000)
    if c != 200:
        return None
    try:
        return json.loads(cuerpo).get('token')
    except Exception:                            # noqa: BLE001
        return None


SECRETOS_DE_PRUEBA = {
    'teams_app_secreto':  'PRUEBA-SECRETO-TEAMS',
    'push_vapid_privada': 'PRUEBA-CLAVE-PRIVADA',
    'sap_clave':          'PRUEBA-CLAVE-RECEPCION',
}


def mirar_secretos(fichas):
    """¿Se le escapa a alguien una credencial del hotel?

    Esto nació de un agujero de verdad: la lista de ajustes que no podían
    salir estaba escrita a mano, se añadió después la conexión con
    recepción, y «sap_clave» —la credencial del sistema de recepción— se
    la llevaba entera cualquier técnico. No daba ningún error: salía en la
    respuesta y ya está.

    Se comprueba contra lo que de verdad hay guardado, no contra una
    lista: se leen los ajustes como administrador, se apunta cuáles
    traen valor, y se mira que a los demás no les llegue ninguno de los
    que parecen credencial.
    """
    fallos = []
    c, cuerpo = llamar('GET', 'ajustes', None, fichas['admin'], tope=400000)
    if c != 200:
        return [f'No se ha podido leer la configuración como administrador ({c}).']
    try:
        aj = json.loads(cuerpo)
        aj = aj.get('ajustes') or aj
    except Exception:                            # noqa: BLE001
        return ['La configuración no se ha podido interpretar.']

    import re
    patron = re.compile(r'secreto|privad|password|_clave$|^clave_|token|fcm_cuenta', re.I)
    secretos = set()
    for _, lista in (aj.items() if isinstance(aj, dict) else []):
        for x in lista:
            if patron.search(str(x.get('clave'))) or x.get('tipo') == 'clave':
                secretos.add(x['clave'])

    for quien, papel in GENTE.items():
        if quien == 'admin':
            continue
        c, cuerpo = llamar('GET', 'ajustes', None, fichas[quien], tope=400000)
        if c != 200:
            continue
        try:
            suyo = json.loads(cuerpo)
            suyo = suyo.get('ajustes') or suyo
        except Exception:                        # noqa: BLE001
            continue
        for _, lista in (suyo.items() if isinstance(suyo, dict) else []):
            for x in lista:
                if x['clave'] in secretos and str(x.get('valor') or '') != '':
                    fallos.append(f'«{papel}» recibe el valor de «{x["clave"]}», '
                                  'que es una credencial del hotel.')
    return fallos


def main() -> int:
    fichas = {}
    for quien in GENTE:
        t = entrar(quien)
        if not t:
            print(f'No se ha podido entrar como «{quien}». ¿Está la base de demostración?')
            return 1
        fichas[quien] = t

    # Cosas de usar y tirar para las pruebas destructivas.
    desechables = {}
    _, c1 = llamar('POST', 'usuarios',
                   {'usuario': 'zzz_desechable', 'correo': 'zzz_desechable@prueba.local',
                    'password': 'Prueba2026zz', 'rol': 'tecnico'}, fichas['admin'], tope=4000)
    _, c2 = llamar('POST', 'hoteles', {'nombre': 'zzz hotel desechable', 'codigo': 'ZZD'},
                   fichas['admin'], tope=4000)
    _, c3 = llamar('POST', 'departamentos', {'nombre': 'zzz depto desechable'},
                   fichas['admin'], tope=4000)
    def sacar(txt, *claves):
        try:
            d = json.loads(txt)
        except Exception:                        # noqa: BLE001
            return '0'
        for k in claves:
            if d.get(k):
                return str(d[k])
            if isinstance(d.get(k.split('_')[-1]), dict):
                pass
        for v in d.values():
            if isinstance(v, dict):
                for k in claves:
                    if v.get(k):
                        return str(v[k])
        return '0'
    desechables['usuario'] = sacar(c1, 'id_login')
    desechables['hotel'] = sacar(c2, 'id_hotel')
    desechables['depto'] = sacar(c3, 'id_departamento', 'id')
    # Un parte desechable, clonando el sitio y el tipo de uno que ya hay:
    # el alta exige elemento y ubicación de verdad. Antes esto cogía un
    # parte existente y el administrador lo borraba para siempre.
    desechables['parte'] = '0'
    # El molde tiene que ser un parte CON ubicación.
    #
    # Esto cogía el primero de la lista y copiaba sus campos. El día que
    # el primero fue una revisión programada —que el generador crea sin
    # ubicación—, el alta se quedó en «Indica dónde es», el parte
    # desechable no llegó a existir, y a partir de ahí todos recibían un
    # 404 al intentar borrarlo. La prueba lo leyó como que el técnico y
    # el de recepción PUEDEN borrar partes definitivamente: tres falsos
    # positivos, y de los que asustan.
    # El parte desechable se construye con el CATÁLOGO, no copiando otro.
    #
    # Copiar los campos del primer parte de la lista parecía cómodo y
    # era frágil de dos maneras seguidas:
    #
    #   · en SQLite el primero era una revisión programada —que el
    #     generador crea sin ubicación— y el alta murió con «Indica
    #     dónde es»;
    #   · arreglado eso y probando en MySQL, el primero no tenía ni tipo
    #     de avería ni familia, y murió con «Indica qué falla».
    #
    # En los dos casos el parte desechable no llegaba a existir, todos
    # recibían un 404 al intentar borrarlo, y la prueba lo leía como que
    # el técnico y el de recepción PUEDEN borrar partes definitivamente.
    # Tres falsos positivos, y de los que asustan.
    #
    # El catálogo siempre tiene un tipo de avería y una prioridad, y las
    # ubicaciones siempre tienen alguna: pidiéndolos, el molde no
    # depende de cómo esté la base ese día.
    _, cz = llamar('GET', 'ubicaciones/buscar&q=1', None, fichas['admin'],
                   tope=200000)
    _, cc = llamar('GET', 'catalogo', None, fichas['admin'], tope=2000000)
    try:
        sitio = json.loads(cz)['ubicaciones'][0]['id_rel']
        cat = json.loads(cc)
        elemento = cat['elementos'][0]
        prioridad = cat['prioridades'][0]['id_prioridad']
    except Exception:                            # noqa: BLE001
        sitio = elemento = prioridad = None

    if sitio and elemento:
        nuevo = {
            'titulo': 'zzz parte desechable de la prueba de permisos',
            'detalle': 'se crea y se tira en la misma pasada',
            'id_elemento': elemento['id_elemento'],
            'id_familia': elemento.get('id_familia'),
            'id_rel_zona': sitio,
            'id_prioridad': prioridad,
        }
        cod5, c5 = llamar('POST', 'tickets', nuevo, fichas['admin'], tope=20000)
        try:
            d = json.loads(c5)
            desechables['parte'] = str(d.get('id_ticket')
                                       or (d.get('ticket') or {}).get('id_ticket')
                                       or '0')
        except Exception:                        # noqa: BLE001
            pass
        if desechables['parte'] == '0':
            print(f'  AVISO: no se ha podido crear el parte desechable '
                  f'({cod5}): {c5[:120]}')
    else:
        print('  AVISO: sin ubicación o sin tipo de avería en el catálogo, '
              'no se puede crear el parte desechable.')

    fugas = mirar_secretos(fichas)

    de_mas, de_menos, rotos, inexistentes = [], [], [], []
    n = 0

    for metodo, ruta, cuerpo, pueden in PRUEBAS:
        ruta = ruta.format(**desechables) if '{' in ruta else ruta
        # El administrador es la vara de medir. Si a ÉL le sale un 404, la
        # ruta no se llama así y lo que mida en los demás no significa
        # nada: un 404 no es «tiene permiso», es «aquí no hay nada».
        # Contarlo como permiso llenaba el informe de falsos positivos,
        # que es la forma más rápida de que nadie vuelva a leerlo.
        # La referencia NO se pide cuando la llamada destruye algo: la
        # propia comprobación ejecutaba el borrado antes de medir a nadie,
        # y luego todos veían un 404 sobre algo que ya no existía. Para
        # esas, se mira al final: si TODOS reciben 404, es que la ruta no
        # se llama así.
        destructiva = metodo == 'DELETE'
        ref = 0
        if not destructiva:
            ref, _ = llamar(metodo, ruta, cuerpo, fichas['admin'])
            if ref == 404:
                inexistentes.append(f'{metodo} /{ruta}')
                continue

        # El administrador, el ÚLTIMO. Si prueba primero una operación
        # destructiva y la ejecuta —que para eso tiene permiso—, los demás
        # se encuentran un 404 en vez de un 403 y la prueba dice que
        # «pueden» cuando lo que pasa es que ya no hay nada que borrar.
        orden = [x for x in GENTE if x != 'admin'] + ['admin']
        codigos_vistos = []
        for quien in orden:
            papel = GENTE[quien]
            n += 1
            codigo, texto = llamar(metodo, ruta, cuerpo, fichas[quien])
            codigos_vistos.append(codigo)
            # Se le ha cerrado la puerta de dos maneras posibles:
            #   401/403 — «no puedes»;
            #   404 cuando el administrador SÍ lo ve — «ni te digo que
            #   existe», que es la forma fina de denegar y la que usa la
            #   aplicación para no filtrar qué partes hay en otro
            #   departamento. Contarlo como «puede» era un falso positivo.
            puerta_cerrada = codigo in (401, 403) or (codigo == 404 and 200 <= ref < 300)
            paso = not puerta_cerrada
            debe = quien in pueden

            if codigo >= 500 or codigo == 0:
                rotos.append(f'{metodo} /{ruta} como {papel}: el servidor devuelve '
                             f'{codigo or "nada"} — {texto[:90]}')
                continue
            # En una destructiva, al llegar al administrador ya se sabe si
            # la ruta existía: si él también recibe 404 y nadie pasó, la
            # ruta no se llama así y no hay nada que juzgar.
            if destructiva and quien == 'admin' and set(codigos_vistos) == {404}:
                inexistentes.append(f'{metodo} /{ruta}')
                n -= len(codigos_vistos)
                break

            if paso and not debe:
                de_mas.append(f'{metodo} /{ruta}: «{papel}» PUEDE y no debería '
                              f'(contesta {codigo})')
            elif not paso and debe:
                de_menos.append(f'{metodo} /{ruta}: «{papel}» NO puede y debería '
                                f'(contesta {codigo})')

    # Barrer lo que esta prueba haya creado. Una prueba que deja basura
    # detrás acaba ensuciando la base hasta que nadie se fía de ella, y
    # además la siguiente pasada se encuentra nombres repetidos.
    #
    # Se barre por nombre —todo lo que empieza por «zzz»—, no sólo lo que
    # se apuntó al crearlo: las pruebas de «¿quién puede crear?» también
    # crean cosas cuando el que llama sí puede.
    limpiados = 0
    for lista, clave, pk, borrar in (
            ('usuarios', 'usuarios', 'id_login', 'usuarios/{}&definitivo=1'),
            ('hoteles', 'hoteles', 'id_hotel', 'hoteles/{}'),
            ('departamentos', 'departamentos', 'id_departamento', 'departamentos/{}')):
        c, cuerpo = llamar('GET', lista, None, fichas['admin'], tope=400000)
        if c != 200:
            continue
        try:
            filas = json.loads(cuerpo).get(clave, [])
        except Exception:                        # noqa: BLE001
            continue
        for f in filas:
            nombre = str(f.get('usuario') or f.get('nombre') or '')
            if nombre.lower().startswith('zzz'):
                llamar('DELETE', borrar.format(f.get(pk)), None, fichas['admin'])
                limpiados += 1
    if desechables['parte'] != '0':
        llamar('DELETE', f'tickets/{desechables["parte"]}&definitivo=1', None, fichas['admin'])
        limpiados += 1

    print(f'Comprobaciones: {n}  ·  rastro limpiado: {limpiados}')
    print(f'  credenciales que se escapan: {len(fugas)}')
    print(f'  permisos de más:  {len(de_mas)}')
    print(f'  permisos de menos: {len(de_menos)}')
    print(f'  respuestas rotas:  {len(rotos)}')

    if inexistentes:
        print(f'  rutas que no existen:  {len(inexistentes)} (la prueba las nombra mal)')

    for titulo, lista in (('DE MÁS — alguien puede lo que no le toca', de_mas),
                          ('DE MENOS — alguien no puede lo que sí le toca', de_menos),
                          ('EL SERVIDOR SE CAE', rotos),
                          ('CREDENCIALES QUE SE ESCAPAN', fugas),
                          ('RUTAS MAL NOMBRADAS EN ESTA PRUEBA', inexistentes)):
        if lista:
            print(f'\n{titulo}:')
            for x in lista:
                print('  ·', x)

    return 1 if (de_mas or rotos or fugas) else 0


if __name__ == '__main__':
    sys.exit(main())
