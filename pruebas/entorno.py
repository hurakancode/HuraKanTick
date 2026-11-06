#!/usr/bin/env python3
"""
Qué instalación se está probando, y cómo se le pregunta a su base.

Hasta ahora cada prueba llevaba escrito dentro
`http://127.0.0.1/hurakantick/...` y, varias de ellas, además
`/var/www/html/hurakantick/db/hurakantick.sqlite`. Con eso la batería sólo sabía
probar **una** instalación: la de SQLite de esta máquina.

Y eso deja sin probar justo el motor con el que va a funcionar el hotel.
Con cincuenta personas lo normal es MySQL, y MySQL no se comporta igual:
agrupa distinto, compara cadenas distinto, trata las fechas distinto y
las transacciones no son las mismas. Toda la batería daba verde contra
SQLite sin que nadie hubiera pasado una sola de esas pruebas contra
MySQL.

Peor todavía: al apuntar las pruebas a la instalación de MySQL sin
arreglar las rutas de disco, varias **mentían**. `hoteles` creaba la
revisión programada en MySQL, lanzaba el generador contra la carpeta de
SQLite y luego buscaba el parte en MySQL: no estaba, y lo cantaba como
«las revisiones de obligado cumplimiento nacen invisibles». Un fallo
gravísimo que no existía. Media hora detrás de él.

Así que la instalación bajo prueba se dice en un sitio y sólo uno:

    HURAKANTICK_API   dirección de la API    (http://127.0.0.1/hurakantick/api/index.php?_ruta=)
    HURAKANTICK_RAIZ  dirección del sitio    (http://127.0.0.1/hurakantick)
    HURAKANTICK_DIR   carpeta en el disco    (/var/www/html/hurakantick)

Y a la base se le pregunta **a través de su propia configuración**, no
con credenciales repetidas aquí: `consulta()` lanza un PHP que carga el
`config.php` de esa instalación. Así funciona igual con SQLite que con
MySQL, y si mañana cambia la contraseña, no hay que tocar nada.

   HURAKANTICK_API="http://127.0.0.1/hurakantick-my/api/index.php?_ruta=" \\
   HURAKANTICK_RAIZ="http://127.0.0.1/hurakantick-my" \\
   HURAKANTICK_DIR=/var/www/html/hurakantick-my \\
   python3 pruebas/todas.py
"""

import json
import os
import subprocess

class ErrorDeBase(RuntimeError):
    """La consulta no se ha podido hacer. No es lo mismo que «no hay nada»."""


DIR = os.environ.get('HURAKANTICK_DIR', '/var/www/html/hurakantick')
RAIZ = os.environ.get('HURAKANTICK_RAIZ', 'http://127.0.0.1/hurakantick')
API = os.environ.get('HURAKANTICK_API', RAIZ + '/api/index.php?_ruta=')


# ---- Las cuentas de la demostración --------------------------------
#
# Las pruebas hablan de PUESTOS, no de personas: «el administrador»,
# «el responsable», «el técnico», «el de recepción». Durante mucho tiempo
# esos puestos coincidían con el nombre de usuario de la demo —admin,
# ana, luis, pilar—, y las pruebas entraban así casi doscientas veces.
#
# Desde la 7.90 la demo trae a la gente del hotel, y el nombre de usuario
# sale del correo: el administrador es «daniel.melgar», el responsable
# «carlos.duarte»… Cambiado a pelo, la batería entera se habría quedado
# en la puerta. Así que el puesto sigue siendo la palabra corta de
# siempre, y SÓLO al entrar se traduce a la persona que lo ocupa.
#
# Lo que no está en la tabla pasa tal cual: los usuarios desechables que
# crean las propias pruebas («zzz…») no se tocan.
USUARIOS = {
    'admin': 'daniel.melgar',
    'ana':   'carlos.duarte',          # responsable · Servicios Técnicos
    'luis':  'david.sstt',             # técnico · Servicios Técnicos
    'marta': 'fany.prieto',            # técnica · Pisos
    'jose':  'test1',                  # técnico · Informática
    'pilar': 'recepcion.costadelsol',  # recepción: sólo avisar
    'rosa':  'test2',                  # responsable · Pisos
}


def _clave_de(carpeta: str) -> str:
    """La contraseña de la demo que trae el código de esa carpeta."""
    f = os.path.join(carpeta, 'tools', 'clave_demo.php')
    try:
        texto = open(f, encoding='utf-8').read()
    except OSError:
        return 'sstt2026'
    import re
    m = re.search(r"return\s+'([^']*)'\s*;", texto)
    return m.group(1) if m else 'sstt2026'


_DEMO_NUEVA = None


def demo_nueva() -> bool:
    """¿La BASE de la instalación bajo prueba tiene la demo de la 7.90?

    Se pregunta a la base y no al código a propósito. Una instalación de
    antes, actualizada con el código nuevo, trae ya tools/clave_demo.php
    pero sigue teniendo dentro a la gente de antes con su contraseña de
    antes: actualizar no toca los datos, que es justo lo que se le pide.
    Decidirlo por el fichero habría hecho que la batería entrara con unas
    credenciales que esa base no conoce.
    """
    global _DEMO_NUEVA
    if _DEMO_NUEVA is None:
        try:
            n = valor('SELECT COUNT(*) AS n FROM login WHERE usuario = ?',
                      [USUARIOS['admin']])
            _DEMO_NUEVA = bool(n) and int(n) > 0
        except Exception:                             # noqa: BLE001
            _DEMO_NUEVA = False
    return _DEMO_NUEVA


def usuario(puesto):
    """El nombre de usuario con que entra ese puesto en esa instalación."""
    if not isinstance(puesto, str) or puesto not in USUARIOS:
        return puesto
    return USUARIOS[puesto] if demo_nueva() else puesto


def clave() -> str:
    """La contraseña de la demo que tiene de verdad esa base."""
    return _clave_de(DIR) if demo_nueva() else 'sstt2026'


def motor() -> str:
    """«sqlite» o «mysql», según lo que diga esa instalación."""
    try:
        return consulta_cruda('echo (string)($config["db"]["driver"] ?? "");')
    except ErrorDeBase:
        return ''


def consulta_cruda(php: str) -> str:
    """Un trozo de PHP con $config ya cargado y Db ya inicializada."""
    guion = (
        '$raiz = ' + json.dumps(DIR) + ';'
        '$config = require $raiz . "/api/config.php";'
        'require $raiz . "/api/lib/compat.php";'
        'require $raiz . "/api/lib/Http.php";'
        'require $raiz . "/api/lib/Db.php";'
        'try { Db::init($config["db"]); } catch (Throwable $e) {}'
        + php
    )
    try:
        r = subprocess.run(['php', '-r', guion], capture_output=True,
                           text=True, timeout=120)
    except Exception as e:                            # noqa: BLE001
        raise ErrorDeBase(f'no se ha podido lanzar php: {e}') from None

    # Un SQL mal escrito tiene que DOLER aquí mismo.
    #
    # Esto devolvía '' y quien llamaba entendía «no hay filas». Escribí
    # una consulta con un nombre de columna que no existe y la respuesta
    # fue una lista vacía, tan tranquila: me pasé un rato buscando por
    # qué «no había partes sin ubicación» cuando los había. Una consulta
    # que miente por silencio es peor que una que falla.
    err = (r.stderr or '').strip()
    if r.returncode != 0 or 'Fatal error' in err or 'Fatal error' in (r.stdout or ''):
        linea = next((x for x in (err or r.stdout or '').split('\n')
                      if 'error' in x.lower()), err[:200])
        raise ErrorDeBase(linea.strip()[:300])
    return (r.stdout or '').strip()


def consulta(sql: str, params=None):
    """Las filas que devuelve ese SQL, como lista de diccionarios.

    Mismo SQL para los dos motores: el que se escriba tiene que valer en
    ambos (nada de SUBSTR raro ni de comillas dobles para cadenas).
    """
    # JSON_INVALID_UTF8_SUBSTITUTE, y no por capricho: sin él, una sola
    # fila con un byte suelto —que las hay, vienen de importaciones
    # viejas— hace que json_encode devuelva false, y esto contestaba una
    # lista vacía como si no hubiera nada. Una consulta que miente por
    # silencio es peor que una que falla.
    php = ('echo json_encode(Db::all(' + json.dumps(sql) + ', '
           + json.dumps(list(params or [])) + '), JSON_INVALID_UTF8_SUBSTITUTE);')
    salida = consulta_cruda(php)
    try:
        return json.loads(salida) if salida else []
    except Exception:                                 # noqa: BLE001
        return []


def valor(sql: str, params=None):
    filas = consulta(sql, params)
    if not filas:
        return None
    return list(filas[0].values())[0]


# Al final, cuando ya existen consulta() y valor(): muchas pruebas hacen
# «CLAVE = entorno.CLAVE» al cargarse.
CLAVE = clave()
