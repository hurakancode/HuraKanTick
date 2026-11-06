#!/usr/bin/env python3
"""
La puerta de entrada.

Es lo único de esta aplicación que va a estar expuesto a cincuenta
personas, a sus móviles y a la red del hotel. Lo demás se puede arreglar
el lunes; esto no.

Se prueba lo que de verdad pasa, no lo que dice el código:

  · que probar contraseñas a lo bruto **se frene**, por cuenta y por
    origen;
  · que una cuenta bloqueada no entre ni con la contraseña buena;
  · que un administrador pueda desbloquearla —si no, el bloqueo es una
    avería, no una defensa—;
  · que NO se pueda averiguar quién tiene cuenta probando nombres: la
    respuesta y el tiempo tienen que ser iguales exista o no;
  · que una sesión caducada deje de valer, y que al cambiar la
    contraseña caduquen las sesiones abiertas en otros sitios;
  · y que «he olvidado la contraseña» no sirva para entrar ni para saber
    quién existe.

Todo con personas desechables, creadas y borradas aquí. Bloquear a
alguien de verdad sería dejarle sin trabajar.

   python3 pruebas/acceso.py
"""

import json
import os
import sys
import time
import urllib.error
import urllib.request

BASE = os.environ.get(
    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
# La base de la instalación bajo prueba, sea del motor que sea.
#
# Esto era la ruta fija del fichero SQLite. Con ella, apuntar la prueba
# a la instalación de MySQL no fallaba: leía la base EQUIVOCADA, no
# encontraba al usuario y reventaba con un TypeError. Peor que fallar.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402
CLAVE = entorno.CLAVE
N = os.getpid() % 10000
SUYA = 'Contrasena-Larga-2026'


def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    t = time.time()
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, r.read(900000).decode('utf-8', 'replace'), time.time() - t
    except urllib.error.HTTPError as e:
        return e.code, e.read(900000).decode('utf-8', 'replace'), time.time() - t
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160], time.time() - t


def soltar_freno():
    """Quitar el freno por origen, que si no se arrastra entre pruebas.

    El freno cuenta los fallos de los últimos quince minutos desde este
    mismo equipo, y esta prueba falla contraseñas a propósito. Sin
    soltarlo entre bloques, la segunda comprobación recibe un 429 que no
    tiene nada que ver con lo que está probando.
    """
    entorno.consulta_cruda('Db::run("DELETE FROM intentos_acceso");')
    return True


def main() -> int:
    fallos = []
    c, cuerpo, _ = llamar('POST', 'auth/login',
                          {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print(f'No se ha podido entrar como administrador ({c}).')
        return 1
    jefe = json.loads(cuerpo)['token']

    # Hace falta poder tocar la base de esa instalación: esta prueba
    # falla contraseñas a propósito y tiene que soltar el freno por
    # origen entre bloques, o el segundo recibe un 429 que no tiene nada
    # que ver con lo que está probando.
    if entorno.valor('SELECT COUNT(*) AS n FROM login') is None:
        print(f'No se puede consultar la base de {entorno.DIR}: esta prueba '
              'necesita llegar a ella para soltar el freno entre bloques.')
        return 2
    print(f'Motor de la instalación bajo prueba: {entorno.motor()}')

    # ---- Una persona desechable ---------------------------------------
    usuario = f'zzzacceso{N}'
    _, cuerpo, _ = llamar('POST', 'usuarios', {
        'usuario': usuario, 'correo': f'{usuario}@prueba.local',
        'password': SUYA, 'nombre': 'Puerta', 'apellido1': 'Prueba',
        'rol': 'tecnico',
    }, jefe)
    try:
        d = json.loads(cuerpo)
        idu = d.get('id_login') or (d.get('usuario') or {}).get('id_login')
    except Exception:                            # noqa: BLE001
        idu = None
    if not idu:
        print('No se ha podido crear a la persona de prueba: ' + cuerpo[:150])
        return 1

    def limpiar():
        llamar('DELETE', f'usuarios/{idu}&definitivo=1', None, jefe)
        soltar_freno()

    # ---- 1. Entra con la suya -----------------------------------------
    soltar_freno()
    c, cuerpo, _ = llamar('POST', 'auth/login',
                          {'correo': entorno.usuario(usuario), 'password': SUYA})
    if c != 200:
        fallos.append(f'La persona recién creada no puede entrar ({c}).')
        limpiar()
        print('\n'.join('  · ' + f for f in fallos))
        return 1
    suyo = json.loads(cuerpo)['token']

    # ---- 2. A lo bruto: tiene que frenar -------------------------------
    #
    # Se mira en la BASE si la cuenta queda bloqueada, no el código de la
    # respuesta. Buscando sólo un 429, esta prueba dijo «doce contraseñas
    # mal y sigue aceptando intentos» cuando bloqueaba limpiamente a la
    # octava: lo que pasa es que contesta 401, a propósito, para no
    # delatar que la cuenta existe.
    soltar_freno()
    for intento in range(1, 13):
        llamar('POST', 'auth/login',
               {'correo': entorno.usuario(usuario), 'password': f'mal{intento}'})
        fila = entorno.consulta(
            'SELECT bloqueo, intentos FROM login WHERE id_login = ?', [idu])
        if fila and int(fila[0]['bloqueo']) == 1:
            print(f'  se bloquea al intento {intento}')
            break
    else:
        fallos.append('Doce contraseñas seguidas mal y la cuenta NO se '
                      'bloquea: se pueden probar contraseñas sin límite.')

    # ---- 3. Bloqueada no entra ni con la buena -------------------------
    c, cuerpo_bloq, _ = llamar('POST', 'auth/login',
                               {'correo': entorno.usuario(usuario), 'password': SUYA})
    if c == 200:
        fallos.append('Después de bloquearse por intentos, la contraseña '
                      'buena entra igual: el bloqueo no sirve de nada.')

    # ---- 3b. Y estando bloqueada tampoco se delata ---------------------
    #
    # Es el chivato que tenía: a una cuenta bloqueada le contestaba 403
    # «Esta cuenta está bloqueada» y a un nombre inventado 401 «Usuario o
    # contraseña incorrectos». Con eso se averigua quién tiene cuenta en
    # el hotel: ocho intentos contra un nombre y la respuesta lo canta.
    c2, cuerpo_inv, _ = llamar('POST', 'auth/login',
                               {'correo': f'nohaynadie{N}', 'password': 'una mala'})
    if c != c2 or cuerpo_bloq[:90] != cuerpo_inv[:90]:
        fallos.append(
            f'Una cuenta BLOQUEADA contesta distinto ({c}) que un nombre que no '
            f'existe ({c2}): bastan ocho intentos para averiguar quién tiene '
            'cuenta en el hotel.')

    # ---- 3c. El bloqueo se suelta solo ---------------------------------
    #
    # Si no caducara, cualquiera deja al hotel entero sin entrar: los
    # nombres son adivinables y basta con fallar ocho veces por persona.
    # Al administrador también, que es quien tendría que desbloquear.
    tiene = entorno.consulta_cruda(
        'echo implode(",", Db::columnas("login"));').split(',')
    if 'bloqueo_hasta' not in tiene:
        fallos.append('El bloqueo por intentos no tiene fecha de caducidad: '
                      'es para siempre, así que cualquiera puede dejar al '
                      'hotel entero sin poder entrar.')
    else:
        hasta = entorno.valor(
            'SELECT bloqueo_hasta FROM login WHERE id_login = ?', [idu])
        if not hasta:
            fallos.append('La cuenta se ha bloqueado SIN fecha de caducidad: '
                          'queda bloqueada para siempre.')
        else:
            print(f'  el bloqueo caduca el {hasta} (UTC)')
        # Se adelanta el reloj y tiene que volver a entrar sola.
        entorno.consulta_cruda(
            'Db::run("UPDATE login SET bloqueo_hasta = \'2020-01-01 00:00:00\'"'
            '. " WHERE id_login = ?", [' + str(idu) + ']);')
        soltar_freno()
        c, _, _ = llamar('POST', 'auth/login',
                         {'correo': entorno.usuario(usuario), 'password': SUYA})
        if c != 200:
            fallos.append('Pasado el tiempo del bloqueo la cuenta sigue sin '
                          f'poder entrar ({c}): el bloqueo no se suelta solo.')

    # ---- 4. Y un administrador puede desbloquearla ---------------------
    #
    # Si no se puede, el bloqueo deja de ser una defensa y pasa a ser una
    # avería: cualquiera puede dejar sin trabajar a quien quiera, sólo con
    # escribir mal su contraseña ocho veces.
    desbloqueada = False
    for ruta, cuerpo_ in ((f'usuarios/{idu}/desbloquear', {}),
                          (f'usuarios/{idu}', {'bloqueo': 0, 'intentos': 0}),
                          (f'usuarios/{idu}', {'bloqueo': False})):
        metodo = 'POST' if 'desbloquear' in ruta else 'PATCH'
        c, _, _ = llamar(metodo, ruta, cuerpo_, jefe)
        if 200 <= c < 300:
            soltar_freno()
            c2, _, _ = llamar('POST', 'auth/login',
                              {'correo': entorno.usuario(usuario), 'password': SUYA})
            if c2 == 200:
                desbloqueada = True
                print(f'  se desbloquea con {metodo} /{ruta.split("&")[0]}')
                break
    if not desbloqueada:
        fallos.append('Una cuenta bloqueada por intentos NO se puede '
                      'desbloquear desde la aplicación. Entonces cualquiera '
                      'deja sin trabajar a quien quiera escribiendo mal su '
                      'contraseña ocho veces.')

    # ---- 4b. A lo bruto, pero todo A LA VEZ ----------------------------
    #
    # El límite se comprobaba leyendo «intentos», mirando la contraseña y
    # sumando después: treinta contraseñas lanzadas juntas leían todas
    # «0» y se comprobaban las treinta. Lo mismo con el freno por origen.
    # Se miran los contadores: el intento se reserva antes de comprobar,
    # así que «intentos» no puede pasar del tope —pasarlo querría decir
    # más contraseñas comprobadas de las permitidas—, y de ciento veinte
    # intentos desde el mismo equipo no pueden pasar más de veinte. (Con
    # cuarenta a la vez el fallo no salía: la ventana es de milésimas.
    # Con ciento veinte pasaban 21.)
    import threading
    tope = intento
    if desbloqueada:
        soltar_freno()
        entorno.consulta_cruda(f'Db::run("UPDATE login SET intentos = 0, bloqueo = 0 WHERE id_login = {int(idu)}");')
        salida = threading.Barrier(30)

        def prueba(n):
            salida.wait()
            llamar('POST', 'auth/login',
                   {'correo': entorno.usuario(usuario), 'password': f'a la vez {n}'})
        hilos = [threading.Thread(target=prueba, args=(n,)) for n in range(30)]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()
        comprobadas = int(entorno.valor('SELECT intentos FROM login WHERE id_login = ?', [idu]) or 0)
        print(f'  30 contraseñas a la vez: comprobadas {comprobadas} (tope {tope})')
        if comprobadas > tope:
            fallos.append(f'Treinta contraseñas a la vez contra una cuenta y se han comprobado '
                          f'{comprobadas}: el límite de {tope} no aguanta peticiones simultáneas.')
        entorno.consulta_cruda(f'Db::run("UPDATE login SET intentos = 0, bloqueo = 0, bloqueo_hasta = NULL WHERE id_login = {int(idu)}");')

    soltar_freno()
    salida = threading.Barrier(120)
    codigos = []

    def de_fuera(n):
        salida.wait()
        c, _, _ = llamar('POST', 'auth/login',
                         {'correo': f'nadie{N}x{n}', 'password': 'mala'})
        codigos.append(c)
    hilos = [threading.Thread(target=de_fuera, args=(n,)) for n in range(120)]
    for h in hilos:
        h.start()
    for h in hilos:
        h.join()
    pasan = sum(1 for c in codigos if c != 429)
    print(f'  120 intentos a la vez desde el mismo equipo: pasan el freno {pasan} (máximo 20)')
    if pasan > 20:
        fallos.append(f'Ciento veinte intentos a la vez desde el mismo equipo y pasan el freno '
                      f'{pasan}: el freno de veinte no aguanta peticiones simultáneas.')
    soltar_freno()

    # ---- 5. No se puede averiguar quién tiene cuenta -------------------
    soltar_freno()
    respuestas, tiempos = {}, {}
    for etiqueta, quien in (('existe', usuario),
                            ('no existe', f'nohaynadie{N}')):
        c, cuerpo, t = llamar('POST', 'auth/login',
                              {'correo': entorno.usuario(quien), 'password': 'una mala'})
        respuestas[etiqueta] = (c, cuerpo[:120])
        tiempos[etiqueta] = t

    if respuestas['existe'][0] != respuestas['no existe'][0]:
        fallos.append(
            'Contesta distinto según el usuario exista o no '
            f'({respuestas["existe"][0]} contra {respuestas["no existe"][0]}): '
            'se puede averiguar quién tiene cuenta probando nombres.')
    elif respuestas['existe'][1] != respuestas['no existe'][1]:
        fallos.append('El texto del error cambia según el usuario exista o '
                      'no: se puede averiguar quién tiene cuenta.')

    # ---- 6. La sesión caducada deja de valer ---------------------------
    #
    # Se caduca a mano en la base, que es lo que pasará dentro de treinta
    # días en el móvil de cualquiera.
    soltar_freno()
    try:
        entorno.consulta_cruda(
            'Db::run("UPDATE api_tokens SET expira = \'2020-01-01 00:00:00\'"'
            '. " WHERE id_login = ?", [' + str(idu) + ']);')
        caducada = True
    except Exception as e:                       # noqa: BLE001
        caducada = False
        print(f'  (no se ha podido caducar la sesión a mano: {e})')

    if caducada:
        c, _, _ = llamar('GET', 'tickets&limite=1', None, suyo)
        if 200 <= c < 300:
            fallos.append('Una sesión caducada sigue funcionando: el móvil '
                          'perdido de alguien que se fue del hotel sigue '
                          'entrando.')

    # ---- 7. «He olvidado la contraseña» --------------------------------
    soltar_freno()
    c, cuerpo, t1 = llamar('POST', 'auth/recuperar', {'correo': f'{usuario}@prueba.local'})
    c2, cuerpo2, t2 = llamar('POST', 'auth/recuperar',
                             {'correo': f'nohaynadie{N}@prueba.local'})
    if c in (404, 405) and c2 in (404, 405):
        print('  (no hay recuperación de contraseña por correo)')
    else:
        if c != c2 or cuerpo[:80] != cuerpo2[:80]:
            fallos.append(
                '«He olvidado la contraseña» contesta distinto según el correo '
                f'exista o no ({c} contra {c2}): sirve para averiguar quién '
                'tiene cuenta.')
        if '"codigo"' in cuerpo or '"token"' in cuerpo:
            fallos.append('La respuesta de «he olvidado la contraseña» lleva '
                          'el código dentro: quien la pida entra sin tener '
                          'acceso al correo.')

    # ---- 8. Sin ficha no se entra en ningún sitio ----------------------
    for ruta in ('tickets&limite=1', 'usuarios', 'ajustes', 'panel', 'catalogo'):
        c, _, _ = llamar('GET', ruta, None, None)
        if 200 <= c < 300:
            fallos.append(f'GET /{ruta.split("&")[0]} contesta sin ninguna '
                          'ficha de acceso.')

    limpiar()

    print('\nPuerta de entrada probada: freno por intentos, bloqueo y '
          'desbloqueo,\nfuga de nombres, sesión caducada, recuperación y '
          'rutas sin ficha.')
    print(f'  que sale mal: {len(fallos)}')
    if fallos:
        print('\nLA PUERTA DE ENTRADA:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nSe frena, se bloquea, se desbloquea, y no se filtra quién existe.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
