#!/usr/bin/env python3
"""
Lanzar todas las pruebas, en orden, y dar UNA respuesta.

Son diecisiete y cada una se ejecutaba a mano. Lanzarlas de una en una
tiene dos problemas: se olvida alguna, y una que se cae por su cuenta
—por un fallo en la prueba, no en el programa— pasa desapercibida. Me ha
pasado: la de rastreo se caía en su última línea, después de veinte
minutos de trabajo, justo al imprimir el resultado. Durante días creí que
estaba pasando.

Así que aquí cada prueba tiene tres finales posibles, y los tres se
cuentan por separado:

  · PASA   — ha mirado y no ha encontrado nada.
  · FALLA  — ha encontrado algo. Está en el programa.
  · ROTA   — la prueba no ha podido terminar: se ha caído o se ha
             quedado colgada. **No dice nada del programa**, y por eso no
             puede contarse como que pasa.

Las rápidas van primero, para enterarse pronto de lo gordo. Las que
abren un navegador tardan, y las dos últimas pasan de la media hora.

   python3 pruebas/todas.py              todas
   python3 pruebas/todas.py rapidas      sólo las que no abren navegador
   python3 pruebas/todas.py --mysql      contra la instalación de MySQL
   python3 pruebas/todas.py campos ajustes    las que se digan
"""

import os
import signal
import subprocess
import sys
import time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

AQUI = os.path.dirname(os.path.abspath(__file__))

# (nombre, segundos de margen, abre navegador, para qué es)
PRUEBAS = [
    ('esquemas',      120, False, 'la base de datos es la que dice ser'),
    ('clases',        120, False, 'el código no se contradice consigo mismo'),
    ('comillas',      120, False, 'nada de lo que se pinta deja colar HTML'),
    ('rutas',         180, False, 'cada ruta de la API contesta lo suyo'),
    ('guia',          120, False, 'la guía cuadra: índice, números y capturas'),
    ('instalacion',   900, False, 'el manual de instalación funciona como está escrito'),
    ('apidoc',        300, False, 'la referencia de la API dice la verdad'),
    ('buscador',      240, False, 'el buscador encuentra lo que hay'),
    ('listados',      300, False, 'la lista y su número dicen lo mismo'),
    ('permisos',      600, False, 'quién puede qué, y qué pasa con la basura'),
    ('acceso',        400, False, 'la puerta: intentos, bloqueo y fuga de nombres'),
    ('expuesto',      180, False, 'qué se lleva un desconocido con sólo la dirección'),
    ('vales',         300, False, 'la sesión no viaja escrita en ninguna dirección'),
    ('mensajes',      120, False, 'leer una conversación apaga sus avisos'),
    ('matriz',        300, False, 'cada ruta de la API contra cada papel, y SQL hostil'),
    ('integridad',    180, False, 'el panel de integridad ve lo que tiene que ver'),
    ('subidas',       180, False, 'subir un .php, un SVG o una imagen con código dentro'),
    ('recuperar',     180, False, 'olvidar, cambiar, salir y la auto-entrada, de punta a punta'),
    ('actualizaciones', 120, False, 'se entera de una versión nueva publicada'),
    ('sincertificado', 600, True,  'con https: sin red y avisos al móvil'),
    ('aguante',       600, False, 'se le manda basura y no se cae'),
    ('ajustes',       900, False, 'los 104 ajustes guardan lo que dicen'),
    ('hoteles',       600, False, 'un hotel no ve nada del otro'),
    ('a_la_vez',      300, False, 'dos personas tocando el mismo parte'),
    ('cincuenta',     400, False, 'cincuenta personas trabajando a la vez'),
    ('tormenta',      400, False, 'cincuenta móviles vaciando su cola a la vez'),
    ('sincronizacion', 900, True , 'sin cobertura no se pierde trabajo'),
    ('altas',         400, False, 'alta por ficheros y borrado de una persona'),
    ('copias',        900, False, 'la copia se puede RESTAURAR de verdad'),
    ('rendimiento',  2000, False, 'aguanta con veinte mil partes'),
    ('actualizar',    900, True,  'actualizar sobre la versión anterior'),
    ('jornada',      1200, True,  'la jornada real de cada papel, con texto hostil'),
    ('descargas',     400, True,  'los botones cuyo efecto no se ve'),
    ('campos',        900, True,  'cada campo se guarda de verdad'),
    # Tarda unos 28 minutos de verdad; el plazo deja margen para un
    # tropiezo del navegador sin cortar una pasada buena.
    ('formularios',  3600, True,  'ninguna ventana se queda muda'),
    ('rastreo',      1800, True,  'pulsar a ciegas todo lo que haya'),
]


CERROJO = '/tmp/hurakantick-pruebas-en-marcha'


def cerrojo_puesto():
    """Que no haya dos tandas a la vez.

    Las pruebas no simulan: crean partes, personas y hoteles de verdad
    en la misma base. Dos tandas al mismo tiempo se pisan los datos: una
    borra la persona que la otra acaba de crear y las dos cantan fallos
    que no existen. Me pasó —dejé una tanda corriendo, lancé otra, y la
    segunda dio «FALLA» en permisos y tiempos de un segundo en pruebas
    que tardan medio minuto—. Media hora buscando un fallo inexistente.
    """
    if os.path.exists(CERROJO):
        try:
            with open(CERROJO, encoding='utf-8') as fh:
                quien = fh.read().strip()
            pid = int(quien.split()[0])
            os.kill(pid, 0)          # ¿vive?
        except Exception:            # noqa: BLE001
            os.remove(CERROJO)       # era de una tanda muerta
            return False
        print('YA HAY OTRA TANDA DE PRUEBAS EN MARCHA ' + f'({quien}).')
        print('Dos a la vez se pisan los datos y dan fallos que no existen.')
        print(f'Si estás seguro de que no corre nada: rm {CERROJO}')
        return True
    return False



def red_de_seguridad(api=None):
    """Comprobar que la tanda no se ha dejado nada puesto.

    Cada prueba barre lo suyo, pero eso ha fallado tres veces: un hotel
    fantasma que hizo que la plantilla de alta marcara el hotel
    equivocado, siete departamentos desactivados que dejaron la ficha de
    una persona sin ninguno que elegir, y la «Revisión de extintores y
    BIE» —la obligatoria— desactivada por la pasada a ciegas.

    Esto no borra nada: CUENTA y avisa. Borrar a ciegas al final sería
    otra prueba destructiva sobre los datos de verdad, que es justo el
    problema. Lo que hace es decir qué ha quedado raro para que se mire.
    """
    import json as _json
    import urllib.request as _ur

    # La instalación QUE SE HA PROBADO, no siempre la de SQLite.
    #
    # Esto llevaba la dirección escrita. Al pasar la tanda con --mysql,
    # las pruebas corrían contra MySQL y esta red de seguridad miraba
    # los datos de SQLite: dio «281 restos» que eran de otra base. Un
    # recuento de la base equivocada no es un aviso, es ruido —y del que
    # hace que se dejen de leer los avisos—.
    api = api or 'http://127.0.0.1/hurakantick/api/index.php?_ruta='

    def pide(ruta, token):
        p = _ur.Request(api + ruta)
        p.add_header('Authorization', 'Bearer ' + token)
        try:
            with _ur.urlopen(p, None, timeout=60) as r:
                return _json.loads(r.read(8000000))
        except Exception:                        # noqa: BLE001
            return {}

    try:
        p = _ur.Request(api + 'auth/login', method='POST')
        p.add_header('Content-Type', 'application/json')
        with _ur.urlopen(p, _json.dumps(
                {'correo': entorno.usuario('admin'), 'password': entorno.CLAVE}).encode(),
                timeout=60) as r:
            token = _json.loads(r.read(200000))['token']
    except Exception:                            # noqa: BLE001
        print('\n(no se ha podido revisar si la tanda ha dejado restos)')
        return

    restos = []
    PISTAS = ('zzz', 'prb', 'AAAA', 'Pruebas ', 'Hotel 3', 'Hotel 4',
              'Revisión 1', 'Revisión 2', 'Revisión 3', 'Revisión 4',
              'Revisión 5', 'Revisión 6', 'Revisión 7', 'Revisión 8',
              'Revisión 9')
    for ruta, bolsa, campo in (('hoteles', 'hoteles', 'nombre'),
                               ('departamentos', 'departamentos', 'nombre'),
                               ('usuarios&todos=1', 'usuarios', 'usuario'),
                               ('mantenimiento', 'mantenimientos', 'nombre'),
                               ('instrucciones', 'instrucciones', 'titulo'),
                               ('frases', 'frases', 'texto')):
        for x in (pide(ruta, token).get(bolsa) or []):
            nombre = str(x.get(campo) or '')
            if nombre.startswith(PISTAS) or nombre == 'x':
                restos.append(f'{bolsa}: {nombre[:40]}')

    # Y lo que importa de verdad: que no haya partes invisibles.
    diag = pide('tickets&limite=1&sin_hotel=1', token)

    if restos:
        print(f'\nLA TANDA HA DEJADO {len(restos)} RESTOS en los datos:')
        for x in restos[:12]:
            print('  ·', x)
        if len(restos) > 12:
            print(f'  … y {len(restos) - 12} más')
        print('  (no se borran aquí: míralos y quítalos a mano, o '
              'reinstala la demo con  php tools/instalar.php --demo --forzar)')
    else:
        print('\nLa tanda no ha dejado restos en los datos.')

    print('Comprueba además que no haya partes sin hotel: '
          'php tools/diagnostico.php')


# Contra qué instalación se pasa la tanda.
#
# Hasta hoy, siempre contra la de SQLite, porque cada prueba llevaba la
# dirección escrita dentro. Y el hotel va a funcionar con MySQL: con
# cincuenta personas es lo normal. O sea que el motor de producción no
# lo había probado nunca nadie.
#
# La primera pasada contra MySQL encontró a la primera un fallo que
# SQLite no enseña: buscar una ubicación —que es como se dice en qué
# habitación es el parte— devolvía «No se ha podido completar la
# operación en la base de datos». El filtro de visibilidad entraba dos
# veces en la misma consulta con el mismo nombre de parámetro; SQLite lo
# admite y MySQL con preparación nativa no.
INSTALACIONES = {
    'sqlite': ('/var/www/html/hurakantick',    'http://127.0.0.1/hurakantick'),
    'mysql':  ('/var/www/html/hurakantick-my', 'http://127.0.0.1/hurakantick-my'),
}


def entorno_de(cual: str) -> dict:
    carpeta, raiz = INSTALACIONES[cual]
    # Si quien la lanza dice contra qué instalación (docs/PRUEBAS.md), manda
    # eso. Antes estas dos líneas de abajo lo pisaban con las direcciones
    # fijas: la guía prometía «la batería entera va contra ellas» y la
    # batería iba contra /var/www/html/hurakantick sin decir nada. Lo vi al
    # probar el paquete recién instalado: once «pasa» que eran de la otra.
    if os.environ.get('HURAKANTICK_DIR') and os.environ.get('HURAKANTICK_RAIZ'):
        carpeta = os.environ['HURAKANTICK_DIR']
        raiz = os.environ['HURAKANTICK_RAIZ'].rstrip('/')
    return dict(os.environ,
                HURAKANTICK_DIR=carpeta,
                HURAKANTICK_RAIZ=raiz,
                HURAKANTICK_API=raiz + '/api/index.php?_ruta=',
                HURAKANTICK_BASE=raiz + '/www/index.html',
                # La misma instalación por https, para sincertificado.py.
                # Sin esto, en la tanda de MySQL esa prueba miraba la de
                # SQLite: era la dirección que trae escrita por defecto.
                HURAKANTICK_HTTPS=raiz.replace('http://', 'https://', 1) + '/www/index.html',
                HURAKANTICK_API_HTTPS=raiz.replace('http://', 'https://', 1) + '/api/index.php?_ruta=')


def main() -> int:
    pedidas = [a for a in sys.argv[1:] if not a.startswith('-')]
    cual = 'mysql' if '--mysql' in sys.argv else 'sqlite'
    carpeta = INSTALACIONES[cual][0]
    if not os.path.isdir(carpeta):
        print(f'No encuentro la instalación de {cual} en {carpeta}.')
        return 2
    ambiente = entorno_de(cual)
    if cerrojo_puesto():
        return 2
    with open(CERROJO, 'w', encoding='utf-8') as fh:
        fh.write(f'{os.getpid()} desde {time.strftime("%H:%M")}')
    if pedidas == ['rapidas']:
        lista = [p for p in PRUEBAS if not p[2]]
    elif pedidas:
        lista = [p for p in PRUEBAS if p[0] in pedidas]
        if not lista:
            print('No conozco esas pruebas. Son: '
                  + ', '.join(p[0] for p in PRUEBAS))
            return 2
    else:
        lista = PRUEBAS

    print(f'{len(lista)} pruebas sobre la instalación de {cual.upper()} '
          f'({entorno_de(cual)["HURAKANTICK_RAIZ"]}).')
    print('Las que abren navegador van marcadas con ·nav·\n')
    pasan, fallan, rotas, sin_probar = [], [], [], []
    empezo = time.time()

    for nombre, margen, nav, para_que in lista:
        marca = '·nav· ' if nav else '      '
        print(f'{marca}{nombre:<16} {para_que}', flush=True)
        t = time.time()
        # Se lanza en su propio grupo de procesos y al vencer el plazo se
        # mata EL GRUPO, no sólo el hijo.
        #
        # Con subprocess.run(timeout=...) no bastaba: las pruebas que abren
        # navegador dejan procesos del navegador agarrados a la misma
        # salida, así que matar a Python no cierra la tubería y la espera
        # no termina nunca. Una prueba colgada colgaba la tanda entera:
        # `formularios` se quedó más de una hora con un plazo de cuarenta
        # minutos, y al final el reloj de fuera se llevó la tanda sin
        # informe. Ahora el plazo se cumple de verdad.
        proc = subprocess.Popen(
            [sys.executable, os.path.join(AQUI, nombre + '.py')],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            text=True, start_new_session=True, env=ambiente)
        try:
            salida = proc.communicate(timeout=margen)[0] or ''
            codigo = proc.returncode
        except subprocess.TimeoutExpired:
            try:
                os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
            except Exception:                    # noqa: BLE001
                proc.kill()
            # Y lo que haya dicho hasta colgarse, que suele señalar dónde.
            try:
                salida = proc.communicate(timeout=30)[0] or ''
            except Exception:                    # noqa: BLE001
                salida = ''
            codigo = -9
        tardo = int(time.time() - t)

        # Una prueba que se cae NO es una prueba que pasa.
        #
        # Al principio lo adivinaba buscando frases del resumen de cada
        # una, y daba por rotas dos que estaban pasando: cada prueba
        # redacta su resumen a su manera y la lista de frases nunca está
        # completa. Lo que sí es fiable: se cae si se cuelga, si deja un
        # «Traceback», o si termina con un código que no es ni 0 (no he
        # encontrado nada) ni 1 (he encontrado esto).
        # El 2 es «no se ha podido probar»: le falta algo para correr —la
        # de actualización necesita un paquete anterior— y no dice nada
        # del programa. No es un fallo, pero tampoco es un «pasa»: eso
        # sería decir que está bien sin haber mirado.
        no_probada = codigo == 2
        rota = (not no_probada
                and (codigo == -9
                     or 'Traceback (most recent call last)' in salida
                     or codigo not in (0, 1)
                     or not salida.strip()))

        if no_probada:
            ultimas = [x for x in salida.strip().split('\n') if x.strip()]
            sin_probar.append((nombre, ultimas[0][:110] if ultimas else ''))
            estado = 'no probada'
        elif rota:
            if codigo == -9:
                motivo = f'se ha quedado colgada (más de {margen}s)'
            elif not salida.strip():
                motivo = 'no ha dicho nada'
            else:
                lineas = [x for x in salida.strip().split('\n') if x.strip()]
                motivo = lineas[-1][:120]
            rotas.append((nombre, motivo))
            estado = 'ROTA'
        elif codigo == 0:
            pasan.append(nombre)
            estado = 'pasa'
        else:
            fallan.append((nombre, salida))
            estado = 'FALLA'

        print(f'       {" " * 16} → {estado}  ({tardo}s)\n', flush=True)

    minutos = int((time.time() - empezo) / 60)
    print('─' * 60)
    print(f'{len(pasan)} pasan · {len(fallan)} fallan · {len(rotas)} rotas'
          + (f' · {len(sin_probar)} sin probar' if sin_probar else '')
          + f' · {minutos} min')

    if fallan:
        print('\nLO QUE HAY QUE ARREGLAR EN EL PROGRAMA:')
        for nombre, salida in fallan:
            print(f'\n── {nombre} ' + '─' * (54 - len(nombre)))
            # Del informe de cada prueba interesa la parte de los hallazgos.
            lineas = salida.strip().split('\n')
            print('\n'.join(lineas[-18:]))

    if rotas:
        print('\nPRUEBAS QUE NO HAN PODIDO TERMINAR')
        print('(no dicen nada del programa: hay que arreglar la prueba)')
        for nombre, motivo in rotas:
            print(f'  · {nombre}: {motivo}')

    if sin_probar:
        print('\nNO SE HAN PODIDO PROBAR (les falta algo para correr):')
        for nombre, motivo in sin_probar:
            print(f'  · {nombre}: {motivo}')

    if not fallan and not rotas:
        print('\nLas que han podido mirar no han encontrado nada.'
              if sin_probar else
              '\nTodas han mirado y ninguna ha encontrado nada.')

    if not pedidas:
        red_de_seguridad(ambiente['HURAKANTICK_API'])

    try:
        os.remove(CERROJO)
    except Exception:                            # noqa: BLE001
        pass

    return 1 if (fallan or rotas) else 0


if __name__ == '__main__':
    sys.exit(main())
