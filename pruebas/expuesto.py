#!/usr/bin/env python3
"""
Qué se lleva un desconocido que sólo sepa la dirección.

No con una sesión, ni con un fallo del programa: escribiendo la dirección
en el navegador, sin entrar. El programa no interviene —esto lo sirve el
servidor web directamente— así que ninguna prueba que pase por la API lo
habría visto nunca.

Y esto NO es una hipótesis. En la instalación de pruebas, con todos los
candados del proyecto puestos y al día:

    http://servidor/hurakantick/db/hurakantick.sqlite   →  200, nueve megas

La base entera. Los partes, las personas, `pwd_php`, `pwd_sha2` y
`autologin_hash` —que es una llave de entrada directa—. Y la papelera al
lado, que guarda copia de todo lo borrado.

El candado estaba puesto y era correcto: `db/.htaccess` dice
`Require all denied` y lo explica. Lo que pasa es que **Apache en Debian
y en Ubuntu viene con `AllowOverride None`**, y con eso no lee ningún
`.htaccess` de nadie. El candado es un papel pegado en una puerta que
nadie mira. En nginx es peor: ahí los `.htaccess` ni existen.

Por eso esta prueba no mira si el fichero de candado está: **pide las
direcciones por HTTP y mira qué contesta el servidor**. Es la única
forma de saberlo, porque las dos cosas —el candado puesto y la carpeta
abierta de par en par— tienen exactamente el mismo aspecto desde dentro.

   python3 pruebas/expuesto.py
"""

import os
import re
import sys
import urllib.error
import urllib.request

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
API = os.environ.get('HURAKANTICK_API',
                     'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
# La raíz del sitio, deducida de la dirección de la API.
BASE = re.sub(r'api/index\.php.*$', '', API)

# (ruta, qué se lleva quien la pida)
#
# Las de carpeta prueban el listado: si contesta, cualquiera ve los
# nombres y a partir de ahí pide lo que quiera.
MIRAR = [
    ('db/hurakantick.sqlite',      'LA BASE ENTERA: partes, personas, contraseñas '
                            'y las llaves de auto-entrada'),
    ('db/hurakantick-papelera.sqlite', 'TODO LO BORRADO, con las filas completas'),
    ('db/',                 'los nombres de los ficheros de la base'),
    ('api/config.php',      'la configuración; si el servidor no ejecuta PHP, '
                            'la contraseña de la base en claro'),
    ('api/config.local.php', 'la configuración propia de esta casa, con la '
                             'contraseña de la base'),
    ('api/copias/',         'las copias de seguridad, que son la base entera'),
    ('api/uploads/',        'las fotos de todos los partes, sin sesión'),
    ('tools/instalar.php',  'el instalador, que sabe vaciar la base'),
    ('tools/diagnostico.php', 'el diagnóstico, que cuenta cómo está montado'),
    ('paquetes/',           'los paquetes de versiones'),
    ('.git/config',         'el repositorio, si se subió sin querer'),
]


" Y aparte, qué CABECERAS manda. Mismo motivo: los bloques del "
" .htaccess van envueltos en <IfModule mod_headers.c>, así que si el "
" módulo no está puestos no pasa nada visible: Apache no se queja, la "
" aplicación funciona, y las cabeceras sencillamente no salen. En esta "
" misma máquina mod_headers NO estaba activado. "
CABECERAS = [
    ('www/index.html', 'x-frame-options',
     'cualquier página podría meter HuraKan Tick en un marco invisible: la sesión '
     'del técnico va dentro, y un clic suyo cierra o reasigna un parte'),
    ('www/index.html', 'x-content-type-options',
     'el navegador adivina el tipo de los ficheros en vez de hacer caso'),
    ('www/sw.js', 'cache-control',
     'el trabajador de servicio se cachearía, y es justo lo que trae las '
     'actualizaciones: una versión nueva no llegaría a los móviles'),
]


def cabeceras(ruta):
    try:
        pet = urllib.request.Request(BASE + ruta, method='HEAD')
        with urllib.request.urlopen(pet, None, timeout=30) as r:
            return {k.lower(): v for k, v in r.headers.items()}
    except Exception:                                 # noqa: BLE001
        return {}


def pedir(ruta):
    """Devuelve (código, bytes leídos). Sin sesión: como un desconocido."""
    try:
        with urllib.request.urlopen(BASE + ruta, None, timeout=30) as r:
            return r.status, len(r.read(4096))
    except urllib.error.HTTPError as e:
        return e.code, 0
    except Exception:                                 # noqa: BLE001
        return 0, 0


def escrituras_de_herramientas():
    """Las herramientas abiertas por web pueden MIRAR, no escribir.

    importar, ubicaciones y clasificar se dejan abrir con «?clave=instalar»
    —una palabra del manual— para poder ensayar sin consola. Pero también
    escriben: crear personas y sitios en la base, volcar un CSV a disco.
    Un desconocido de la red del hotel, con sólo la dirección, no puede
    hacer eso: aplicar exige la consola o el fichero db/REINSTALAR (como
    reinstalar). Esto lo comprueba pidiéndolo de verdad, sin sesión y sin
    permiso: el ensayo tiene que contestar, y aplicar tiene que cortar.

    No escribe nada: cada «aplicar» que se prueba debe cortarse ANTES de
    tocar la base; si alguno contesta 200, es justamente el fallo.
    """
    problemas = []
    # El ensayo (sin aplicar) sigue abierto: hay que poder mirar.
    cod, _ = pedir('tools/ubicaciones.php?clave=instalar&plantas=1-2&habitaciones=1-3')
    if cod not in (200, 0):
        problemas.append(f'el ENSAYO de ubicaciones contesta {cod}: debería dejar mirar.')

    # Aplicar, sin permiso, tiene que cortar en las tres.
    escrituras = [
        ('tools/ubicaciones.php?clave=instalar&plantas=1-2&habitaciones=1-3&aplicar=1',
         'crear ubicaciones en la base'),
        ('tools/importar.php?clave=instalar&plantillas=1',
         'sacar las plantillas a disco'),
        ('tools/clasificar.php?clave=instalar&aplicar=1',
         'reescribir la familia de los tipos'),
        ('tools/ubicaciones.php?clave=instalar&plantas=1-1&habitaciones=1-1&csv=/tmp/x.csv&aplicar=1',
         'escribir un CSV en cualquier ruta del servidor'),
        ('tools/ubicaciones.php?clave=instalar&plantas=1-1&habitaciones=1-1&csv=db/../api/x.csv&aplicar=1',
         'escapar de db/ con ../ al escribir el CSV'),
    ]
    for ruta, que in escrituras:
        cod, _ = pedir(ruta)
        if cod == 200:
            problemas.append(f'un desconocido puede {que} (contesta 200): {ruta}')
        # 0 = no contesta (servidor caído): no se puede afirmar nada, no se cuenta.
    return problemas


def main() -> int:
    # Primero, que el servidor esté donde se cree. Si no, todo saldría
    # «protegido» por la razón equivocada y sería peor que no probar.
    cod, _ = pedir('www/index.html')
    if cod not in (200, 301, 302):
        cod2, _ = pedir('')
        if cod2 not in (200, 301, 302, 403):
            print(f'No contesta nada en {BASE} (ni la portada): esta prueba '
                  'no puede saber qué está abierto.')
            return 2

    abiertas, protegidas = [], 0
    for ruta, que in MIRAR:
        cod, leidos = pedir(ruta)
        if cod == 200 and leidos > 0:
            abiertas.append((ruta, que, leidos))
        elif cod in (403, 404, 401):
            protegidas += 1
        # 0 = no contesta: ni abierta ni protegida, no se cuenta.

    # El asistente de instalación, sobre un sistema que ya funciona.
    #
    # Su única llave era «?clave=instalar», una palabra escrita en el
    # código que va dentro del paquete. Y tools/.htaccess permite este
    # fichero a propósito, porque el asistente tiene que poder abrirse.
    # Así que cualquiera de la red lo tenía delante: sin poder vaciar la
    # base, pero sí reescribir api/config.php para apuntar el hotel a
    # otra base, o crear las cuentas de ejemplo con la contraseña que
    # viene en el manual.
    cod, leidos = pedir('tools/instalar_gui.php?clave=instalar')
    if cod == 200 and leidos > 0:
        abiertas.append((
            'tools/instalar_gui.php?clave=instalar',
            'EL ASISTENTE DE INSTALACIÓN, sobre el hotel funcionando: se '
            'puede apuntar el sistema a otra base de datos o crear las '
            'cuentas de ejemplo', leidos))

    # Las herramientas de mantenimiento: mirar sí, escribir no.
    escrituras = escrituras_de_herramientas()

    # Las cabeceras, pidiéndolas.
    faltan = []
    for ruta, cab, porque in CABECERAS:
        cs = cabeceras(ruta)
        if not cs:
            continue
        valor = cs.get(cab, '')
        if cab == 'cache-control':
            # No basta con que esté: tiene que decir que no se cachee.
            if 'no-cache' not in valor.lower():
                faltan.append((ruta, cab, valor or '(no viene)', porque))
        elif valor == '':
            faltan.append((ruta, cab, '(no viene)', porque))

    print(f'Direcciones probadas sin sesión: {len(MIRAR) + 1}')
    print(f'  cerradas: {protegidas}   ABIERTAS: {len(abiertas)}')
    print(f'Cabeceras comprobadas: {len(CABECERAS)}   mal: {len(faltan)}')

    if faltan:
        print('\nCABECERAS QUE EL SERVIDOR NO MANDA:')
        for ruta, cab, valor, porque in faltan:
            print(f'  · {ruta} → {cab}: {valor}')
            print(f'      {porque}')
        print('\n  Están escritas en www/.htaccess, pero dentro de un '
              '<IfModule mod_headers.c>.\n  Si el módulo no está, no salen y '
              'nadie se entera. Actívalo:\n\n      a2enmod headers && '
              'apachectl -k graceful\n')

    if escrituras:
        print('\nHERRAMIENTAS QUE DEJAN ESCRIBIR SIN SESIÓN NI PERMISO:')
        for p in escrituras:
            print(f'  · {p}')
        print('\n  Con «?clave=instalar» —una palabra del manual— sólo se debe '
              'poder\n  ENSAYAR. Aplicar necesita la consola o el fichero '
              'db/REINSTALAR.\n  La comprobación está en tools/permiso_navegador.php.\n')

    if not abiertas and not faltan and not escrituras:
        print('\nNada de lo sensible se sirve por web. El servidor lee los '
              'candados, y las herramientas sólo dejan mirar.')
        return 0

    if not abiertas:
        return 1

    print('\nCUALQUIERA QUE SEPA LA DIRECCIÓN SE LLEVA ESTO:')
    for ruta, que, leidos in abiertas:
        print(f'  · {BASE}{ruta}')
        print(f'      {que}')

    # El porqué, con la orden exacta, porque el candado del proyecto está
    # puesto y quien lo mire pensará que esto no puede estar pasando.
    hay_htaccess = os.path.isfile(os.path.join(RAIZ, 'db', '.htaccess'))
    print('\nPOR QUÉ:')
    if hay_htaccess:
        print('  db/.htaccess está puesto y dice «Require all denied». El '
              'servidor NO lo está leyendo.')
    else:
        print('  falta db/.htaccess. Pasa tools/instalar.php, que lo escribe.')
    print('  En Apache, el sitio tiene que llevar AllowOverride All:\n')
    print('      <Directory /var/www/html/hurakantick>\n'
          '          AllowOverride All\n'
          '      </Directory>\n')
    print('  y recargar: apachectl -k graceful\n')
    print('  En nginx los .htaccess no existen; hace falta, en el sitio:\n')
    print('      location ~ ^/(db|tools|paquetes)/ { deny all; }\n'
          '      location ~ /api/(config\\.php|copias/) { deny all; }\n')
    print('  Mientras esto siga así, cambiar las contraseñas no sirve de '
          'nada: la base se descarga con ellas dentro.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
