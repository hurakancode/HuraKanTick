#!/usr/bin/env python3
"""
Quién puede llamar a qué: cada ruta de la API contra cada papel.

Las demás pruebas de permisos miran casos concretos («un técnico no
puede borrar a una persona»). Ésta no elige: coge TODAS las llamadas que
hace la aplicación (sacadas de www/assets/js/api.js) y las pide como
cada persona del equipo de demostración y como alguien sin sesión. Una
ruta nueva que se olvide de pedir permiso sale aquí aunque nadie haya
pensado en ella.

Cómo no rompe nada: el permiso se comprueba ANTES que los datos. Las
llamadas que escriben van con el cuerpo vacío y, si llevan número, con
uno que no existe (999999). Quien no tiene permiso recibe 401/403; quien
lo tiene recibe 400/404/422 —«te falta algo»— y no se escribe nada.
Las pocas que actúan aunque el cuerpo vaya vacío (cerrar sesión, vaciar
el registro, forzar a todos los móviles a descargar…) no se le piden al
administrador: de ésas sólo importa que a los demás les diga que no.

Qué se espera de cada una está escrito abajo, en ESPERADO, y se repasó
a mano a partir de lo que contestaba. Una diferencia en cualquier
sentido es un fallo: si alguien gana acceso, es un agujero; si alguien
lo pierde, es una pantalla que se le queda vacía.

   python3 pruebas/matriz.py            comprobar
   python3 pruebas/matriz.py --tabla    sólo enseñar lo que contesta
"""

import json
import os
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

API = entorno.API
NADA = 999999

# Los papeles. «pisos» es un técnico de Pisos: otro inicio, otro reparto.
PAPELES = [
    ('anonimo',    None),
    ('recepcion',  'pilar'),   # reportador: sólo avisa
    ('tecnico',    'luis'),    # técnico de Servicios Técnicos
    ('pisos',      'marta'),   # técnica de Pisos
    ('responsable', 'ana'),
    ('admin',      'admin'),
]

# Las que actúan aunque el cuerpo vaya vacío: al administrador no se le
# piden (se comprueba que a los demás les dicen que no).
NO_AL_ADMIN = {
    ('POST', 'auth/logout'), ('DELETE', 'auth/sesiones'), ('DELETE', 'seguridad'),
    ('POST', 'renovar'), ('POST', 'copias'), ('POST', 'mantenimiento/generar'),
    ('POST', 'push/probar'), ('POST', 'teams'), ('POST', 'sap/probar'),
    ('DELETE', 'notificaciones/preferencias'), ('POST', 'notificaciones/leidas'),
    ('GET', 'personal/exportar'), ('DELETE', 'push'),
}
# Y éstas tampoco a nadie: cerrarían la sesión con que se está probando.
NO_A_NADIE_CON_SESION = {('POST', 'auth/logout'), ('DELETE', 'auth/sesiones')}

# (método, ruta). Las de la aplicación, con los números cambiados por uno
# que no existe.
LLAMADAS = [
    ('GET', 'actualizacion'), ('GET', 'ajustes'), ('PATCH', 'ajustes'),
    ('GET', 'atajos'), ('POST', 'atajos'), ('PATCH', f'atajos/{NADA}'), ('DELETE', f'atajos/{NADA}'),
    ('POST', 'auth/password'), ('GET', 'auth/sesiones'), ('DELETE', f'auth/sesiones/{NADA}'),
    ('GET', 'auth/yo'), ('POST', 'auth/logout'), ('DELETE', 'auth/sesiones'),
    ('GET', 'campos'), ('POST', 'campos'), ('PATCH', f'campos/{NADA}'), ('DELETE', f'campos/{NADA}'),
    ('GET', 'catalogo'), ('GET', 'conversaciones'),
    ('GET', 'copias'), ('POST', 'copias'), ('POST', f'copias/{NADA}/vale'), ('DELETE', f'copias/{NADA}'),
    ('GET', 'departamentos'), ('POST', 'departamentos'), ('GET', f'departamentos/{NADA}/hoteles'),
    ('PATCH', f'departamentos/{NADA}'), ('DELETE', f'departamentos/{NADA}'),
    ('GET', 'elementos'), ('POST', 'elementos'), ('GET', f'elementos/{NADA}'), ('PATCH', f'elementos/{NADA}'),
    ('GET', 'errores'), ('POST', 'errores/visto'),
    ('GET', 'familias'), ('POST', 'familias'), ('POST', 'familias/mover'),
    ('GET', f'familias/{NADA}/elementos'), ('PATCH', f'familias/{NADA}'), ('DELETE', f'familias/{NADA}'),
    ('GET', 'frases'), ('POST', 'frases'), ('POST', 'frases/categoria'),
    ('PATCH', f'frases/{NADA}'), ('DELETE', f'frases/{NADA}'),
    ('PATCH', f'frases/categoria/{NADA}'), ('DELETE', f'frases/categoria/{NADA}'),
    ('GET', 'hoteles'), ('POST', 'hoteles'), ('PATCH', f'hoteles/{NADA}'),
    ('POST', f'hoteles/{NADA}/copiar'), ('POST', f'hoteles/{NADA}/restablecer'),
    ('GET', 'informes'), ('POST', 'informes'), ('GET', 'informes/definiciones'),
    ('POST', 'informes/ejecutar'), ('DELETE', f'informes/{NADA}'),
    ('POST', 'instrucciones'), ('PATCH', f'instrucciones/{NADA}'), ('DELETE', f'instrucciones/{NADA}'),
    ('POST', f'instrucciones/{NADA}/util'),
    ('GET', 'mantenimiento'), ('POST', 'mantenimiento'), ('POST', 'mantenimiento/generar'),
    ('GET', f'mantenimiento/{NADA}/historial'), ('PATCH', f'mantenimiento/{NADA}'),
    ('DELETE', f'mantenimiento/{NADA}'),
    ('GET', 'mensajes'), ('POST', 'mensajes/visto'),
    ('GET', 'notificaciones'), ('POST', 'notificaciones/leidas'), ('DELETE', f'notificaciones/{NADA}'),
    ('GET', 'notificaciones/preferencias'), ('POST', 'notificaciones/preferencias'),
    ('DELETE', 'notificaciones/preferencias'),
    ('GET', 'panel'),
    ('GET', 'papelera'), ('GET', f'papelera/{NADA}'), ('POST', f'papelera/{NADA}/restaurar'),
    ('DELETE', f'papelera/{NADA}'),
    ('GET', 'personal'), ('GET', 'personal/exportados'), ('GET', 'personal/exportar'),
    ('POST', 'personal/importar'), ('GET', 'personal/plantilla'),
    ('GET', 'push/clave'), ('POST', 'push'), ('DELETE', 'push'), ('POST', 'push/probar'),
    ('POST', 'renovar'),
    ('GET', 'sap/estado'), ('GET', 'sap/habitacion'), ('POST', 'sap/probar'),
    ('GET', 'seguridad'), ('DELETE', 'seguridad'),
    ('GET', 'sync/pull'), ('POST', 'sync/push'),
    ('POST', 'teams'),
    ('GET', 'tickets'), ('POST', 'tickets'), ('POST', 'tickets/lote'),
    ('GET', f'tickets/{NADA}'), ('PATCH', f'tickets/{NADA}'), ('DELETE', f'tickets/{NADA}'),
    ('POST', f'tickets/{NADA}/movimientos'), ('POST', f'tickets/{NADA}/adjuntos'),
    ('GET', f'tickets/{NADA}/chat'), ('POST', f'tickets/{NADA}/chat'),
    ('GET', f'tickets/{NADA}/observadores'), ('POST', f'tickets/{NADA}/observadores'),
    ('GET', f'tickets/{NADA}/repetidos'), ('DELETE', f'adjuntos/{NADA}'),
    ('GET', 'ubicaciones-admin'), ('GET', 'ubicaciones-admin/nivel'), ('POST', 'ubicaciones-admin/rango'),
    ('GET', 'ubicaciones/abiertos'), ('GET', 'ubicaciones/buscar'), ('GET', f'ubicaciones/{NADA}'),
    ('GET', 'unidades'), ('POST', 'unidades'), ('GET', f'unidades/{NADA}'), ('PATCH', f'unidades/{NADA}'),
    ('GET', 'usuarios'), ('POST', 'usuarios'), ('POST', 'usuarios/lote'),
    ('GET', 'usuarios/recuperaciones'), ('GET', f'usuarios/{NADA}'), ('PATCH', f'usuarios/{NADA}'),
    ('POST', f'usuarios/{NADA}/codigo'), ('POST', f'usuarios/{NADA}/enlace'),
    ('PATCH', f'usuarios/{NADA}/enlace'), ('DELETE', f'usuarios/{NADA}/enlace'),
    ('GET', 'zonas'), ('POST', 'zonas'),
]


def llamar(metodo, ruta, token=None):
    sep = '&' if '?' in ruta else ''
    pet = urllib.request.Request(API + ruta + sep, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if metodo in ('POST', 'PATCH', 'PUT', 'DELETE'):
        pet.add_header('Content-Type', 'application/json')
        datos = b'{}'
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:                                 # noqa: BLE001
        return 0


def entrar(puesto):
    pet = urllib.request.Request(API + 'auth/login', method='POST')
    pet.add_header('Content-Type', 'application/json')
    cuerpo = json.dumps({'correo': entorno.usuario(puesto), 'password': entorno.CLAVE}).encode()
    with urllib.request.urlopen(pet, cuerpo, timeout=60) as r:
        return json.loads(r.read())['token']


def observar():
    tokens = {p: (entrar(u) if u else None) for p, u in PAPELES}
    tabla = {}
    for metodo, ruta in LLAMADAS:
        fila = {}
        for papel, _ in PAPELES:
            clave = (metodo, ruta.split('/' + str(NADA))[0] if False else ruta)
            if tokens[papel] and (metodo, ruta) in NO_A_NADIE_CON_SESION:
                fila[papel] = '-'
                continue
            if papel == 'admin' and (metodo, ruta) in NO_AL_ADMIN:
                fila[papel] = '-'
                continue
            fila[papel] = llamar(metodo, ruta, tokens[papel])
        tabla[(metodo, ruta)] = fila
    return tabla


def pintar(tabla):
    cab = f'{"":7}{"ruta":38}' + ''.join(f'{p[:6]:>7}' for p, _ in PAPELES)
    print(cab)
    for (m, r), fila in tabla.items():
        print(f'{m:7}{r[:38]:38}' + ''.join(f'{str(fila[p]):>7}' for p, _ in PAPELES))


# ---------------------------------------------------------------------
# Lo que se espera. Una letra por papel que PUEDE: a sin sesión,
# r recepción, t técnico, p Pisos, s responsable, d administrador.
# «·» = nadie. Repasado fila a fila; ver el porqué de las raras en
# README («Cada ruta contra cada papel»).
#
# Que pueda no quiere decir que haga nada con un cuerpo vacío: quiere
# decir que NO le contestan 401/403. Sin sesión, un 404 también cuenta
# como «no le dan nada».
# ---------------------------------------------------------------------
LETRA = {'anonimo': 'a', 'recepcion': 'r', 'tecnico': 't', 'pisos': 'p',
         'responsable': 's', 'admin': 'd'}
ESPERADO_TEXTO = """
GET    actualizacion                          d
GET    ajustes                                rtpsd
PATCH  ajustes                                d
GET    atajos                                 rtpsd
POST   atajos                                 rtpsd
PATCH  atajos/999999                          rtpsd
DELETE atajos/999999                          rtpsd
POST   auth/password                          rtpsd
GET    auth/sesiones                          rtpsd
DELETE auth/sesiones/999999                   rtpsd
GET    auth/yo                                rtpsd
POST   auth/logout                            ad
DELETE auth/sesiones                          d
GET    campos                                 rtpsd
POST   campos                                 d
PATCH  campos/999999                          d
DELETE campos/999999                          d
GET    catalogo                               rtpsd
GET    conversaciones                         rtpsd
GET    copias                                 d
POST   copias                                 d
POST   copias/999999/vale                     d
DELETE copias/999999                          d
GET    departamentos                          rtpsd
POST   departamentos                          d
GET    departamentos/999999/hoteles           rtpsd
PATCH  departamentos/999999                   rtpsd
DELETE departamentos/999999                   d
GET    elementos                              rtpsd
POST   elementos                              d
GET    elementos/999999                       rtpsd
PATCH  elementos/999999                       d
GET    errores                                d
POST   errores/visto                          d
GET    familias                               rtpsd
POST   familias                               d
POST   familias/mover                         d
GET    familias/999999/elementos              rtpsd
PATCH  familias/999999                        d
DELETE familias/999999                        d
GET    frases                                 rtpsd
POST   frases                                 d
POST   frases/categoria                       d
PATCH  frases/999999                          d
DELETE frases/999999                          d
PATCH  frases/categoria/999999                d
DELETE frases/categoria/999999                d
GET    hoteles                                rtpsd
POST   hoteles                                d
PATCH  hoteles/999999                         d
POST   hoteles/999999/copiar                  d
POST   hoteles/999999/restablecer             d
GET    informes                               rtpsd
POST   informes                               rtpsd
GET    informes/definiciones                  rtpsd
POST   informes/ejecutar                      rtpsd
DELETE informes/999999                        rtpsd
POST   instrucciones                          sd
PATCH  instrucciones/999999                   sd
DELETE instrucciones/999999                   sd
POST   instrucciones/999999/util              rtpsd
GET    mantenimiento                          rtpsd
POST   mantenimiento                          sd
POST   mantenimiento/generar                  d
GET    mantenimiento/999999/historial         rtpsd
PATCH  mantenimiento/999999                   sd
DELETE mantenimiento/999999                   sd
GET    mensajes                               rtpsd
POST   mensajes/visto                         rtpsd
GET    notificaciones                         rtpsd
POST   notificaciones/leidas                  rtpsd
DELETE notificaciones/999999                  ·
GET    notificaciones/preferencias            rtpsd
POST   notificaciones/preferencias            rtpsd
DELETE notificaciones/preferencias            rtpsd
GET    panel                                  rtpsd
GET    papelera                               d
GET    papelera/999999                        d
POST   papelera/999999/restaurar              d
DELETE papelera/999999                        d
GET    personal                               d
GET    personal/exportados                    d
GET    personal/exportar                      d
POST   personal/importar                      d
GET    personal/plantilla                     d
GET    push/clave                             rtpsd
POST   push                                   rtpsd
DELETE push                                   rtpsd
POST   push/probar                            rtpsd
POST   renovar                                d
GET    sap/estado                             rtpsd
GET    sap/habitacion                         rtpsd
POST   sap/probar                             rtpsd
GET    seguridad                              d
DELETE seguridad                              d
GET    sync/pull                              rtpsd
POST   sync/push                              rtpsd
POST   teams                                  d
GET    tickets                                rtpsd
POST   tickets                                rtpsd
POST   tickets/lote                           rtpsd
GET    tickets/999999                         rtpsd
PATCH  tickets/999999                         rtpsd
DELETE tickets/999999                         rtpsd
POST   tickets/999999/movimientos             rtpsd
POST   tickets/999999/adjuntos                rtpsd
GET    tickets/999999/chat                    rtpsd
POST   tickets/999999/chat                    rtpsd
GET    tickets/999999/observadores            rtpsd
POST   tickets/999999/observadores            rtpsd
GET    tickets/999999/repetidos               rtpsd
DELETE adjuntos/999999                        rtpsd
GET    ubicaciones-admin                      d
GET    ubicaciones-admin/nivel                d
POST   ubicaciones-admin/rango                d
GET    ubicaciones/abiertos                   rtpsd
GET    ubicaciones/buscar                     rtpsd
GET    ubicaciones/999999                     rtpsd
GET    unidades                               rtpsd
POST   unidades                               tpsd
GET    unidades/999999                        rtpsd
PATCH  unidades/999999                        tpsd
GET    usuarios                               rtpsd
POST   usuarios                               d
POST   usuarios/lote                          d
GET    usuarios/recuperaciones                d
GET    usuarios/999999                        rtpsd
PATCH  usuarios/999999                        d
POST   usuarios/999999/codigo                 d
POST   usuarios/999999/enlace                 d
PATCH  usuarios/999999/enlace                 d
DELETE usuarios/999999/enlace                 d
GET    zonas                                  rtpsd
POST   zonas                                  sd
"""
ESPERADO = {}
for _l in ESPERADO_TEXTO.strip().splitlines():
    _m, _r, _q = _l.split()
    ESPERADO[(_m, _r)] = '' if _q == '·' else _q


def puede(papel, codigo):
    if papel == 'anonimo':
        return codigo not in (401, 403, 404)
    return codigo not in (401, 403)


def comprobar_tabla(tabla):
    fallos = []
    for (m, r), fila in tabla.items():
        if (m, r) not in ESPERADO:
            fallos.append(f'{m} {r}: es una llamada nueva de la aplicación y no tiene '
                          'escrito quién puede; añádela a ESPERADO')
            continue
        quien = ESPERADO[(m, r)]
        for papel, _ in PAPELES:
            c = fila[papel]
            if c == '-' or c == 0:
                continue
            debe = LETRA[papel] in quien
            if puede(papel, c) and not debe:
                fallos.append(f'AGUJERO: {papel} puede {m} {r} (contesta {c})')
            elif not puede(papel, c) and debe:
                fallos.append(f'{papel} ya no puede {m} {r} (contesta {c}): una pantalla se le quedará sin datos')
    return fallos


# ---------------------------------------------------------------------
# Lo que una tabla de códigos no ve
# ---------------------------------------------------------------------

def peticion(metodo, ruta, token=None, cuerpo=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
        datos = json.dumps(cuerpo).encode()
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        b = e.read()
        try:
            return e.code, json.loads(b or b'{}')
        except Exception:                              # noqa: BLE001
            return e.code, {'crudo': b[:300].decode('utf-8', 'replace')}


def comprobar_secretos(tokens):
    """Los secretos de la configuración no viajan a quien no administra.
    Con valores PUESTOS: vacíos, la prueba no demostraría nada."""
    fallos = []
    claves = ['sap_clave', 'teams_app_secreto', 'push_fcm_cuenta']
    antes = {c: entorno.valor('SELECT valor FROM ajustes_sistema WHERE clave = ?', [c]) for c in claves}
    try:
        for c in claves:
            entorno.consulta_cruda(
                f'Db::run("UPDATE ajustes_sistema SET valor = ? WHERE clave = ?", ["secreto-de-prueba-{c}", "{c}"]);')

        def valores(tok):
            _, d = peticion('GET', 'ajustes', tok)
            vistos = {}
            def recorrer(x):
                if isinstance(x, dict):
                    if x.get('clave') in claves:
                        vistos[x['clave']] = x.get('valor')
                    for v in x.values():
                        recorrer(v)
                elif isinstance(x, list):
                    for v in x:
                        recorrer(v)
            recorrer(d)
            return vistos
        for papel, _ in PAPELES:
            if papel == 'anonimo':
                continue
            v = valores(tokens[papel])
            for c in claves:
                ve = 'secreto-de-prueba' in str(v.get(c) or '')
                if papel == 'admin' and not ve:
                    fallos.append(f'el administrador no ve {c}: no podría comprobar lo que tiene puesto')
                if papel != 'admin' and ve:
                    fallos.append(f'AGUJERO: {papel} recibe el valor de {c}')
    finally:
        for c, v in antes.items():
            entorno.consulta_cruda(
                'Db::run("UPDATE ajustes_sistema SET valor = ? WHERE clave = ?", ['
                + json.dumps(v if v is not None else '') + ', "' + c + '"]);')
    return fallos


def comprobar_alias(tokens):
    """El nombre propio de un departamento sólo lo pone el administrador.
    Aquí el permiso va campo a campo, así que la tabla no lo ve."""
    fallos = []
    for papel in ('recepcion', 'tecnico', 'pisos', 'responsable'):
        st, _ = peticion('PATCH', 'departamentos/1', tokens[papel], {'alias': 'zz-matriz'})
        if st not in (401, 403):
            fallos.append(f'AGUJERO: {papel} le cambia el nombre a un departamento (contesta {st})')
    entorno.consulta_cruda('Db::run("UPDATE hotel_departamento SET alias = NULL WHERE alias = ?", ["zz-matriz"]);')
    return fallos


def comprobar_ajeno(tokens):
    """Con números REALES: cada papel contra un parte que no puede ver,
    y todo lo que cuelga de él."""
    fallos = []
    _, d = peticion('GET', 'tickets&limite=500', tokens['admin'])
    todos = [t['id_ticket'] for t in (d.get('tickets') or [])]
    for papel in ('recepcion', 'tecnico', 'pisos'):
        _, v = peticion('GET', 'tickets&limite=500', tokens[papel])
        vis = {t['id_ticket'] for t in (v.get('tickets') or [])}
        ocultos = [i for i in todos if i not in vis]
        if not ocultos:
            continue
        o, foto = ocultos[0], None
        for i in ocultos[:80]:
            st, fa = peticion('GET', f'tickets/{i}/adjuntos', tokens['admin'])
            adj = fa.get('adjuntos') or []
            if adj:
                o, foto = i, adj[0].get('id_adjunto') or adj[0].get('id')
                break
        pruebas = [('GET', f'tickets/{o}', None), ('GET', f'tickets/{o}/historial', None),
                   ('GET', f'tickets/{o}/chat', None), ('POST', f'tickets/{o}/chat', {'texto': 'zz-matriz'}),
                   ('POST', f'tickets/{o}/chat/leido', {}),
                   ('GET', f'tickets/{o}/adjuntos', None), ('GET', f'tickets/{o}/observadores', None),
                   ('GET', f'tickets/{o}/repetidos', None), ('PATCH', f'tickets/{o}', {'detalle': 'zz-matriz'}),
                   ('POST', f'tickets/{o}/movimientos', {'tipo': 'nota', 'nota': 'zz-matriz'}),
                   ('DELETE', f'tickets/{o}', None)]
        if foto:
            pruebas += [('GET', f'adjuntos/{foto}', None), ('DELETE', f'adjuntos/{foto}', None)]
        for m, r, b in pruebas:
            st, _ = peticion(m, r, tokens[papel], b)
            if st in (200, 201):
                fallos.append(f'AGUJERO: {papel} llega a {m} {r}, que no puede ver')
        for accion in ({'accion': 'estado', 'id_estado': 1}, {'accion': 'borrar'}):
            st, res = peticion('POST', 'tickets/lote', tokens[papel], {'ids': [o], **accion})
            if (res.get('hechos') or 0) > 0:
                fallos.append(f'AGUJERO: {papel} cambia en bloque un parte que no ve ({accion["accion"]})')
        # Los informes cuentan sólo lo que ve.
        st, inf = peticion('POST', 'informes/ejecutar', tokens[papel],
                           {'definicion': {'filas': 'estado', 'medida': 'cuenta'}})
        total = sum(int(f.get('valor') or 0) for f in (inf.get('filas') or []))
        if st == 200 and total > len(vis):
            fallos.append(f'AGUJERO: el informe de {papel} cuenta {total} partes y sólo ve {len(vis)}')
    entorno.consulta_cruda('Db::run("DELETE FROM chat_mensajes WHERE texto = ?", ["zz-matriz"]);')
    return fallos


CARGAS = ["'", '"', "' OR '1'='1", '1 OR 1=1', '1) OR (1=1', "') OR ('1'='1",
          '1; DROP TABLE login; --', "' UNION SELECT pwd_php FROM login --", "%' OR 1=1 --",
          '\\', "1' AND SLEEP(3)--", '(SELECT 1)', '0x27']
DELATORES = ('sqlstate', 'syntax error', 'pdoexception', 'near "', 'you have an error',
             'pwd_php', '$2y$', 'stack trace')


def comprobar_inyeccion(tokens):
    """SQL hostil en cada filtro y buscador. Bien = lo mismo o menos que
    una búsqueda normal, sin errores de base y sin tardar de más."""
    import time as _t
    import urllib.parse as _u
    fallos = []
    tok = tokens['recepcion']
    _, base = peticion('GET', 'tickets&limite=500', tok)
    n0 = len(base.get('tickets') or [])
    params = ['q', 'estado', 'prioridad', 'elemento', 'departamento', 'asignado', 'zona',
              'zona1', 'orden', 'desde', 'hasta', 'limite', 'offset']
    rutas = [f'tickets&{p}=' for p in params] + [
        'ubicaciones/buscar&q=', 'usuarios&q=', 'unidades&q=', 'mantenimiento&q=']
    for ruta in rutas:
        for c in CARGAS:
            t0 = _t.time()
            st, r = peticion('GET', ruta + _u.quote(c), tok)
            dura = _t.time() - t0
            texto = json.dumps(r).lower()
            n = len(r.get('tickets') or []) if ruta.startswith('tickets') else 0
            if st >= 500:
                fallos.append(f'{ruta}{c!r}: error {st} del servidor')
            elif any(x in texto for x in DELATORES):
                fallos.append(f'{ruta}{c!r}: la respuesta enseña tripas de la base')
            elif n > n0:
                fallos.append(f'AGUJERO: {ruta}{c!r} devuelve {n} partes y sólo ve {n0}')
            elif 'SLEEP' in c and dura > 2.5:
                fallos.append(f'AGUJERO: {ruta}{c!r} hace esperar {dura:.1f} s: el SQL se ejecuta')
    return fallos, len(rutas) * len(CARGAS)


def main():
    solo_tabla = '--tabla' in sys.argv
    tabla = observar()
    if solo_tabla:
        pintar(tabla)
        return 0
    tokens = {p: (entrar(u) if u else None) for p, u in PAPELES}
    fallos = comprobar_tabla(tabla)
    n_celdas = sum(1 for f in tabla.values() for c in f.values() if c not in ('-', 0))
    fallos += comprobar_secretos(tokens)
    fallos += comprobar_alias(tokens)
    fallos += comprobar_ajeno(tokens)
    iny, n_iny = comprobar_inyeccion(tokens)
    fallos += iny

    print(f'{len(LLAMADAS)} llamadas de la aplicación × {len(PAPELES)} papeles = {n_celdas} respuestas comparadas con lo esperado.')
    print('Secretos con valor puesto, alias de departamento, partes ajenos con números reales,')
    print(f'y {n_iny} peticiones con SQL hostil ({entorno.motor() or "?"}).')
    if fallos:
        print('\nFALLOS:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nCada papel puede lo que le toca y nada más.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
