#!/usr/bin/env python3
"""
La guía por dentro.

No comprueba que esté bien escrita —eso lo mira una persona—, sino las
tres cosas que se rompen solas al añadir un apartado en medio:

  1. El índice y los apartados van en el mismo orden. El índice es un
     <ol> que numera el navegador, así que si el orden no coincide con
     los números escritos en los <h2>, el apartado 19 del índice lleva
     al 20 de la página y nadie entiende por qué.

  2. Los números van de 1 a N sin saltos, y cada apéndice (14b, 16b)
     cuelga del número de su apartado. Un apéndice con el número de
     otro apartado es peor que no tener número.

  3. Todas las capturas existen. Una imagen rota en una guía hace dudar
     de todo lo demás que dice.

Se ejecuta sola:  python3 pruebas/guia.py
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GUIA = os.path.join(RAIZ, 'www', 'guia.html')


def numeros_cuadran(html_guia):
    """¿Lo que la guía PROMETE es lo que el programa hace?

    Lo de arriba comprueba que la guía esté cuadrada: índice, números de
    apartado, capturas y enlaces. Eso no dice nada de si lo que cuenta es
    verdad. Y se quedó vieja sin que saltara nada: decía «a los ocho
    intentos la cuenta se bloquea; Daniel la desbloquea», cuando el
    bloqueo ya se suelta solo a los quince minutos. Quien se quedara
    fuera leería que hay que ir a buscar al administrador en vez de
    esperar un rato.

    Una guía que cuenta algo distinto de lo que hace el programa es peor
    que no tenerla: se sigue, no funciona, y se deja de creer el resto.

    Aquí se atan los números concretos que promete a los que tiene
    puestos el programa. Si alguien cambia la configuración y no toca la
    guía, esto lo dice.
    """
    import sqlite3
    import subprocess

    raiz = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
    texto = re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', html_guia))

    def de_config(ruta):
        try:
            r = subprocess.run(
                ['php', '-r', f'$c = require "{raiz}/api/config.php"; echo {ruta};'],
                capture_output=True, text=True, timeout=60)
            return r.stdout.strip()
        except Exception:                        # noqa: BLE001
            return ''

    def de_ajustes(clave, defecto=''):
        for bd in (os.path.join(raiz, 'db', 'hurakantick.sqlite'),
                   entorno.DIR + '/db/hurakantick.sqlite'):
            if not os.path.isfile(bd):
                continue
            try:
                con = sqlite3.connect(bd)
                fila = con.execute(
                    'SELECT valor FROM ajustes_sistema WHERE clave = ?',
                    (clave,)).fetchone()
                con.close()
                if fila and fila[0] not in (None, ''):
                    return str(fila[0])
            except Exception:                    # noqa: BLE001
                pass
        return defecto

    # (lo que dice la guía, de qué habla, de dónde sale la verdad, qué
    #  número tiene que salir)
    promesas = [
        ('La sesión dura 30 días', 'la sesión en el móvil',
         lambda: de_config('$c["auth"]["token_dias"]'), '30'),
        ('A los ocho intentos', 'los intentos antes de bloquear',
         lambda: de_config('$c["auth"]["intentos_maximos"]'), '8'),
        ('sola en quince minutos', 'lo que dura el bloqueo',
         lambda: de_config('$c["auth"]["bloqueo_minutos"]'), '15'),
        ('de los últimos 90 días', 'los días que se baja el móvil',
         lambda: de_ajustes('dias_historico', '90'), '90'),
    ]
    letras = {'8': 'ocho', '15': 'quince', '30': 'treinta', '90': 'noventa',
              '180': 'ciento ochenta', '365': 'trescientos sesenta y cinco'}

    problemas, mirados = [], 0
    for frase, que, leer, esperado in promesas:
        if frase not in texto:
            problemas.append(
                f'la guía ya no dice «{frase}»: esta comprobación se ha '
                'quedado sin servir, hay que actualizarla')
            continue
        mirados += 1
        real = str(leer()).strip()
        if real and real != esperado:
            problemas.append(
                f'la guía dice «{frase}» y {que} es {real} '
                f'(«{letras.get(real, real)}»). Quien lea la guía contará mal.')

    print(f'Promesas comprobadas contra la configuración: {mirados}')
    return problemas


def main() -> int:
    if not os.path.isfile(GUIA):
        print('No encuentro www/guia.html')
        return 1

    with open(GUIA, encoding='utf-8') as f:
        html = f.read()

    fallos = []

    # ---- 1. El índice y los apartados, en el mismo orden ----------------
    indice = re.findall(r'<li data-papeles="[^"]*"><a href="#([^"]+)"', html)
    secciones = re.findall(r'<section class="guia-seccion guia-entra" id="([^"]+)"', html)

    if len(indice) != len(secciones):
        fallos.append(f'El índice tiene {len(indice)} entradas y hay '
                      f'{len(secciones)} apartados.')
    else:
        for i, (a, b) in enumerate(zip(indice, secciones)):
            if a != b:
                fallos.append(f'El índice y los apartados se cruzan en el número '
                              f'{i + 1}: el índice dice «{a}» y la página «{b}».')
                break

    # ---- 2. La numeración ----------------------------------------------
    numeros = re.findall(r'<span class="(guia-seccion__n|num)">([0-9a-z]+)</span>', html)
    esperado = 0
    letra = {}
    for clase, valor in numeros:
        if clase == 'guia-seccion__n':
            esperado += 1
            letra.clear()
            if valor != str(esperado):
                fallos.append(f'El apartado número {esperado} está escrito como «{valor}».')
        else:
            toca = f'{esperado}{chr(ord("b") + letra.get(esperado, 0))}'
            letra[esperado] = letra.get(esperado, 0) + 1
            if valor != toca:
                fallos.append(f'El apéndice «{valor}» debería ser «{toca}»: '
                              f'cuelga del apartado {esperado}.')

    # ---- 3. Las capturas -------------------------------------------------
    for img in sorted(set(re.findall(r'src="(assets/guia/[^"]+)"', html))):
        if not os.path.isfile(os.path.join(RAIZ, 'www', img)):
            fallos.append(f'Falta la captura {img}.')

    # ---- 4. Los enlaces internos ----------------------------------------
    anclas = set(re.findall(r'\sid="([^"]+)"', html))
    for destino in sorted(set(re.findall(r'href="#([^"]+)"', html))):
        if destino and destino not in anclas:
            fallos.append(f'El enlace «#{destino}» no lleva a ninguna parte.')

    # ---- Resultado -------------------------------------------------------
    print(f'Apartados: {esperado}')
    print(f'Entradas del índice: {len(indice)}')
    print(f'Capturas: {len(set(re.findall(chr(34) + r"(assets/guia/[^" + chr(34) + r"]+)" + chr(34), html)))}')

    # Y que cada captura EXISTA. Antes se contaban las referencias (75) y
    # nunca se miraba si el fichero estaba: renombrar una captura dejaba
    # un hueco en la guía sin que saltara nada. Mirarlo en el navegador
    # engaña, porque son de carga diferida y las que están fuera de
    # pantalla figuran como «no cargadas» aunque estén perfectamente.
    raiz_www = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            '..', 'www')
    citadas = sorted(set(re.findall(
        chr(34) + r'(assets/guia/[^' + chr(34) + r']+)' + chr(34), html)))
    sin_fichero = [c for c in citadas
                   if not os.path.isfile(os.path.join(raiz_www, c))]
    if sin_fichero:
        fallos.append(f'{len(sin_fichero)} capturas que la guía enseña no '
                      'existen: ' + ', '.join(
                          os.path.basename(c) for c in sin_fichero[:6]))
    else:
        print(f'Capturas que existen de verdad: {len(citadas)} de {len(citadas)}')

    fallos += numeros_cuadran(html)

    if fallos:
        print()
        for f_ in fallos:
            print('  ·', f_)
        return 1

    print('La guía está cuadrada: índice, números, capturas y enlaces.')
    return 0


if __name__ == '__main__':
    sys.exit(main())

