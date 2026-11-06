#!/usr/bin/env python3
"""
Dar de alta gente por ficheros de texto.

Es la vía que se va a usar de verdad el primer día: cincuenta personas no
se dan de alta a mano. Se baja una plantilla, se rellena una copia por
persona, se dejan en bak_config/upload/user y se pulsa el botón.

Y es, justamente, el único rincón que la prueba de formularios no podía
mirar: los botones «Probar sin crear nada» y «Dar de alta» sólo existen
cuando hay ficheros esperando. Sin ficheros no hay botones, y sin botones
nadie los había pulsado nunca.

Lo que se comprueba, por este orden:

  1. la plantilla se baja y trae los departamentos y hoteles de ESTE
     hotel (si no, quien la rellena marca cosas que no existen);
  2. «Probar sin crear nada» no crea NADA —es lo que promete el botón, y
     si mintiera, el ensayo sería un alta de verdad—;
  3. el alta de verdad crea a la persona, con su departamento y su
     permiso, y la persona puede entrar con la contraseña del fichero;
  4. el fichero se borra después (lleva la contraseña dentro);
  5. un fichero mal rellenado NO se traga: se queda, con su explicación
     al lado, y no detiene a los demás.

   python3 pruebas/altas.py
"""

import json
import os
import sys
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get(

    'HURAKANTICK_API', 'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
ENTRADA = entorno.DIR + '/bak_config/upload/user'
N = os.getpid() % 10000
CLAVE_NUEVA = 'alta-de-prueba-2026'


def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, r.read(600000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read(600000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


def rellenar(plantilla, valores, usuario):
    """Escribe debajo de cada etiqueta, como lo haría una persona.

    La plantilla es texto con sangría, no un formato con comillas: se
    rellena tal cual, respetando los dos espacios. Marcar una opción es
    cambiar «[ ]» por «[x]» en la línea que toca.
    """
    lineas = plantilla.split('\n')
    salida, etiqueta = [], None
    for ln in lineas:
        crudo = ln.strip()

        if crudo.endswith(':') and not crudo.startswith('#'):
            etiqueta = crudo[:-1]
            salida.append(ln)
            continue

        # Una casilla: se marca si es la que pide este alta.
        if crudo.startswith('[') and etiqueta in valores:
            texto = crudo[3:].strip()
            quiere = valores[etiqueta]
            if isinstance(quiere, str) and texto == quiere:
                salida.append('  [x] ' + texto)
                continue
            if isinstance(quiere, str):
                salida.append('  [ ] ' + texto)
                continue

        # Un hueco en blanco justo debajo de una etiqueta de texto.
        if crudo == '' and etiqueta in valores and isinstance(valores[etiqueta], str) \
                and ln.startswith('  '):
            salida.append('  ' + valores[etiqueta])
            valores = {k: v for k, v in valores.items() if k != etiqueta}
            continue

        salida.append(ln)

    texto = '\n'.join(salida)
    return texto.replace('__USUARIO__', usuario)


def main() -> int:
    fallos = []
    c, cuerpo = llamar('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    if c != 200:
        print('No se ha podido entrar como administrador.')
        return 1
    ficha = json.loads(cuerpo)['token']

    # ---- 1. La plantilla ----------------------------------------------
    c, cuerpo = llamar('GET', 'personal/plantilla', None, ficha)
    if c != 200:
        print('No se puede bajar la plantilla de alta.')
        return 1
    plantilla = json.loads(cuerpo)['texto']

    # ---- El hotel que viene marcado ------------------------------------
    #
    # Con varios hoteles, la plantilla venía con el PRIMERO marcado, que no
    # es el de nadie. Quien rellena cincuenta fichas seguidas no se fija en
    # una casilla que ya está puesta: las cincuenta personas acabarían en
    # el hotel equivocado, y deshacerlo es un día de trabajo. Lo descubrió
    # esta misma prueba dando de alta a su persona en el otro hotel —y por
    # eso luego no la encontraba—.
    _, cuerpo = llamar('GET', 'hoteles', None, ficha)
    try:
        hoteles = [h for h in json.loads(cuerpo).get('hoteles', [])
                   if h.get('activo', 1)]
    except Exception:                            # noqa: BLE001
        hoteles = []
    if len(hoteles) > 1:
        marcados = [ln.split(']', 1)[1].strip()
                    for ln in plantilla.split('\n')
                    if ln.strip().startswith('[x]')]
        nombres = [h['nombre'] for h in hoteles]
        hotel_marcado = next((m for m in marcados if m in nombres), None)
        _, mio = llamar('GET', 'catalogo', None, ficha)
        try:
            el_mio = (json.loads(mio).get('hotel') or {}).get('nombre')
        except Exception:                        # noqa: BLE001
            el_mio = None
        if hotel_marcado and el_mio and hotel_marcado != el_mio:
            fallos.append(
                f'La plantilla viene con «{hotel_marcado}» marcado, y quien se '
                f'la baja está en «{el_mio}». Quien rellene cincuenta fichas sin '
                'mirar esa casilla dará de alta a cincuenta personas en el hotel '
                'que no es.')

    _, cat = llamar('GET', 'catalogo', None, ficha)
    deptos = [d['nombre'] for d in (json.loads(cat).get('departamentos') or [])]
    if not deptos:
        print('Este hotel no tiene departamentos: no se puede probar el alta.')
        return 1
    for d in deptos:
        if d not in plantilla:
            fallos.append(f'La plantilla no ofrece el departamento «{d}», '
                          'así que quien la rellene no puede elegirlo.')

    # ---- Dos ficheros: uno bien y uno mal ------------------------------
    usuario = f'zzzalta{N}'
    bueno = rellenar(plantilla, {
        'Nombre de usuario': usuario,
        'Correo': f'{usuario}@prueba.local',
        'Contraseña inicial': CLAVE_NUEVA,
        'Nombre': 'Alta',
        'Primer apellido': f'Deprueba{N}',
        'Permisos': 'Técnico',
        'Departamento': deptos[0],
    }, usuario)

    # Mal a propósito: sin nombre de usuario no hay con qué entrar.
    malo = rellenar(plantilla, {
        'Correo': f'roto{N}@prueba.local',
        'Contraseña inicial': CLAVE_NUEVA,
        'Nombre': 'Roto',
        'Permisos': 'Técnico',
        'Departamento': deptos[0],
    }, usuario)

    os.makedirs(ENTRADA, exist_ok=True)
    f_bueno = os.path.join(ENTRADA, f'zzz-bueno-{N}.txt')
    f_malo = os.path.join(ENTRADA, f'zzz-malo-{N}.txt')
    for ruta, texto in ((f_bueno, bueno), (f_malo, malo)):
        with open(ruta, 'w', encoding='utf-8') as fh:
            fh.write(texto)
    try:
        os.chown(f_bueno, 33, 33)
        os.chown(f_malo, 33, 33)
    except Exception:                            # noqa: BLE001
        pass                                     # ya corre como quien toca

    def existe_persona():
        _, cu = llamar('GET', 'usuarios', None, ficha)
        try:
            return next((x for x in json.loads(cu).get('usuarios', [])
                         if x.get('usuario') == usuario), None)
        except Exception:                        # noqa: BLE001
            return None

    # ---- 2. El ensayo no puede crear nada ------------------------------
    c, cuerpo = llamar('POST', 'personal/importar', {'simular': True}, ficha)
    if not (200 <= c < 300):
        fallos.append(f'«Probar sin crear nada» no funciona ({c}).')
    elif existe_persona():
        fallos.append('«Probar sin crear nada» HA CREADO a la persona. '
                      'El botón miente: el ensayo es un alta de verdad.')
    if not os.path.exists(f_bueno):
        fallos.append('El ensayo ha borrado el fichero, y todavía no se ha '
                      'dado de alta a nadie: el trabajo de rellenarlo se pierde.')

    # El ensayo tiene que CONTAR lo que haría, o no sirve de nada.
    if 'Deprueba' not in cuerpo and usuario not in cuerpo:
        fallos.append('El ensayo no dice a quién daría de alta: no hay nada '
                      'que revisar antes de pulsar el de verdad.')

    # ---- 3. El alta de verdad -----------------------------------------
    c, cuerpo = llamar('POST', 'personal/importar', {'simular': False}, ficha)
    if not (200 <= c < 300):
        fallos.append(f'El alta por fichero no funciona ({c}) — {cuerpo[:120]}')

    persona = existe_persona()
    if not persona:
        fallos.append('El alta dice que sí, pero la persona no aparece en el equipo.')
    else:
        if (persona.get('nombre') or '') != 'Alta':
            fallos.append(f'El nombre del fichero no ha llegado: «{persona.get("nombre")}».')
        if not persona.get('id_departamento'):
            fallos.append('La persona se ha creado sin departamento, '
                          'aunque el fichero marcaba uno.')

        # Lo que importa de verdad: que pueda entrar.
        cc, _ = llamar('POST', 'auth/login',
                       {'correo': entorno.usuario(usuario), 'password': CLAVE_NUEVA})
        if cc != 200:
            fallos.append('La persona está dada de alta pero NO puede entrar '
                          'con la contraseña que llevaba su fichero.')

    # ---- 4. El fichero bueno desaparece; el malo se queda explicado ----
    if os.path.exists(f_bueno):
        fallos.append('El fichero del alta sigue en la carpeta, y lleva la '
                      'contraseña dentro en texto claro.')
    # El que está mal no se borra: se le pone «.error» detrás —así deja de
    # entrar en la siguiente importación— y se le deja al lado un fichero
    # con el motivo. (Buscaba yo el motivo con otro nombre, y la prueba
    # cantaba que el fichero desaparecía sin rastro. Desaparecer es lo
    # único que no hace.)
    apartado = f_malo + '.error'
    motivo = f_malo + '.motivo.txt'
    if not os.path.exists(apartado):
        if os.path.exists(f_malo):
            fallos.append('El fichero mal rellenado se queda tal cual, así que '
                          'la siguiente importación volverá a tropezar con él.')
        else:
            fallos.append('El fichero mal rellenado ha desaparecido sin dejar '
                          'rastro: nadie va a saber que esa alta no se hizo.')
    if not os.path.exists(motivo):
        fallos.append('El fichero mal rellenado se aparta, pero sin decir qué '
                      'le pasa: no hay forma de corregirlo.')
    elif 'usuario' not in open(motivo, encoding='utf-8').read().lower():
        fallos.append('El motivo no menciona el campo que falta, así que no '
                      'dice qué hay que corregir.')

    # ---- Barrer --------------------------------------------------------
    for x in (f_bueno, f_malo, f_malo + '.error', f_malo + '.motivo.txt'):
        try:
            os.remove(x)
        except Exception:                        # noqa: BLE001
            pass
    # Se busca por nombre de usuario en TODOS los hoteles: si el alta se ha
    # ido a otro, «existe_persona» no la ve y la prueba dejaría la persona
    # dentro para siempre. Pasó, y por eso se limpia así.
    _, cuerpo = llamar('GET', 'usuarios&todos=1', None, ficha)
    try:
        for x in json.loads(cuerpo).get('usuarios', []):
            if (x.get('usuario') or '').startswith('zzzalta'):
                llamar('DELETE', f'usuarios/{x["id_login"]}&definitivo=1', None, ficha)
    except Exception:                            # noqa: BLE001
        pass
    if persona:
        llamar('DELETE', f'usuarios/{persona.get("id_login")}&definitivo=1', None, ficha)

    # ---- Borrar a una persona de verdad --------------------------------
    #
    # No es parte del alta, pero es su reverso y nadie lo probaba: el
    # borrado definitivo fallaba con «FOREIGN KEY constraint failed»
    # —el error de la base de datos tal cual, en la pantalla— porque la
    # lista de tablas a limpiar se había quedado corta en nueve. Y no se
    # notaba porque lo normal es quitar el acceso, no borrar.
    #
    # Se prueba con una persona que ha DEJADO COSAS: si se borra una
    # recién creada, que no ha tocado nada, no se entera de nada.
    c, cuerpo = llamar('POST', 'usuarios', {
        'usuario': f'zzzborra{N}', 'correo': f'zzzborra{N}@prueba.local',
        'password': CLAVE_NUEVA, 'nombre': 'Para', 'apellido1': 'Borrar',
        'rol': 'tecnico', 'id_departamento': None,
    }, ficha)
    try:
        d = json.loads(cuerpo)
        victima = d.get('id_login') or (d.get('usuario') or {}).get('id_login')
    except Exception:                            # noqa: BLE001
        victima = None

    if not victima:
        fallos.append(f'No se ha podido crear a quien iba a borrarse ({c}).')
    else:
        # Que deje rastro en todo lo que pueda: entra, escribe en un parte
        # y se apunta a seguirlo.
        cc, cu = llamar('POST', 'auth/login',
                        {'correo': f'zzzborra{N}', 'password': CLAVE_NUEVA})
        suyo = json.loads(cu)['token'] if cc == 200 else None
        _, cu = llamar('GET', 'tickets&limite=1', None, ficha)
        try:
            parte = json.loads(cu)['tickets'][0]['id_ticket']
        except Exception:                        # noqa: BLE001
            parte = None
        if suyo and parte:
            llamar('POST', f'tickets/{parte}/movimientos',
                   {'nota': 'zzz estuve aquí'}, suyo)
            llamar('POST', f'tickets/{parte}/observadores',
                   {'id_login': victima}, ficha)

        cod, resp = llamar('DELETE', f'usuarios/{victima}&definitivo=1', None, ficha)
        if not (200 <= cod < 300) or '"ok":false' in resp.replace(' ', ''):
            fallos.append('Borrar a una persona del todo no funciona: '
                          + resp[:140])
        else:
            _, cu = llamar('GET', 'usuarios&todos=1', None, ficha)
            if f'zzzborra{N}' in cu:
                fallos.append('El borrado dice que sí y la persona sigue ahí.')

        llamar('DELETE', f'usuarios/{victima}&definitivo=1', None, ficha)

    print('Probado: plantilla, ensayo, alta, entrada, borrado del fichero,')
    print('         fichero mal rellenado y borrado definitivo de una persona.')
    print(f'  que salen mal: {len(fallos)}')
    if fallos:
        print('\nEL ALTA POR FICHEROS:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nEl ensayo no crea nada, el alta crea y la persona entra.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
