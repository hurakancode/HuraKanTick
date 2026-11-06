#!/usr/bin/env python3
"""
¿Lleva a algún sitio cada resultado del buscador?

Esto nació de un fallo que se repitió muchas veces y que desde fuera
parecía otra cosa: buscabas «modo mantenimiento» en Ajustes, pulsabas, y
acababas en la pantalla de «Administrar el sistema» teniendo que buscar
otra vez dentro.

La causa no era el buscador. El índice apuntaba a /admin/mantenimiento,
que NO EXISTE: el enrutador tiene una regla comodín /admin/<algo> y, si
la sección no está, pinta el menú de Administración sin decir nada. Una
ruta mal escrita no fallaba, te dejaba en otro sitio.

Esta prueba compara el índice con el enrutador de verdad y con las
secciones de Administración. Si alguien añade una entrada a una pantalla
que no existe, salta aquí y no en el móvil de un técnico.

    python3 pruebas/rutas.py
"""
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
APP = RAIZ / 'www/assets/js/app.js'
ADMIN = RAIZ / 'www/assets/js/views/admin.js'
INDICE = RAIZ / 'www/assets/js/indice-ajustes.js'
INDEX_API = RAIZ / 'api/index.php'
RUTAS_API = RAIZ / 'api/routes'


def rutas_del_enrutador() -> list[re.Pattern]:
    """Los patrones de RUTAS, tal cual los usa la aplicación."""
    texto = APP.read_text(encoding='utf-8')
    bloque = texto.split('const RUTAS = [', 1)[1].split('\n];', 1)[0]
    salida = []
    for m in re.finditer(r'patron:\s*/\^(.+?)\$/', bloque):
        # De expresión de JavaScript a una de Python: aquí sólo hay
        # \/, \w, \d, (), + y ?, que se escriben igual.
        salida.append(re.compile('^' + m.group(1) + '$'))
    return salida


def secciones_de_admin() -> set[str]:
    """Las secciones que vistaAdmin sabe pintar de verdad."""
    texto = ADMIN.read_text(encoding='utf-8')
    bloque = texto.split('const secciones = {', 1)[1].split('\n  };', 1)[0]
    fuera = set()
    for m in re.finditer(r"^\s*(?:'([^']*)'|(\w+))\s*:", bloque, re.M):
        fuera.add(m.group(1) if m.group(1) is not None else m.group(2))
    return fuera


def entradas_del_indice() -> list[tuple[str, str, str]]:
    """(lista, título, ruta) de PANTALLAS y ACCIONES."""
    texto = INDICE.read_text(encoding='utf-8')
    salida = []
    for lista in ('PANTALLAS', 'ACCIONES'):
        bloque = texto.split(f'export const {lista} = [', 1)[1].split('\n];', 1)[0]
        for m in re.finditer(r"\[\s*'([^']+)',\s*'(/[^']*)'", bloque):
            salida.append((lista, m.group(1), m.group(2)))
    return salida


def secciones_de_api() -> tuple[set[str], dict[str, set[str]]]:
    """
    Lo que index.php sabe atender, y lo que cada fichero de ruta atiende.

    Mismo fallo que el del buscador, pero del lado del servidor: se
    escribe `if ($seccion === 'teams')` en sistema.php y se olvida añadir
    `case 'teams':` al switch de index.php. La ruta existe, está
    probada... y desde la aplicación contesta «Ruta no encontrada».
    """
    texto = INDEX_API.read_text(encoding='utf-8')
    atendidas = {m.group(1) for m in re.finditer(r"^\s*case\s+'([a-z0-9_-]+)'\s*:", texto, re.M)}

    declaradas: dict[str, set[str]] = {}
    for f in sorted(RUTAS_API.glob('*.php')):
        t = f.read_text(encoding='utf-8')
        s = {m.group(1) for m in re.finditer(r"\$seccion\s*===\s*'([a-z0-9_-]+)'", t)}
        if s:
            declaradas[f.name] = s
    return atendidas, declaradas


def main() -> int:
    patrones = rutas_del_enrutador()
    secciones = secciones_de_admin()
    entradas = entradas_del_indice()

    sin_ruta = []
    sin_seccion = []

    for lista, titulo, ruta in entradas:
        if not any(p.match(ruta) for p in patrones):
            sin_ruta.append((lista, titulo, ruta))
            continue
        # La regla comodín /admin/<algo> casa con todo: hay que comprobar
        # además que esa sección exista, o se pinta el menú y a correr.
        m = re.match(r'^/admin/(\w+)$', ruta)
        if m and m.group(1) not in secciones:
            sin_seccion.append((lista, titulo, ruta))

    # Y el lado del servidor.
    atendidas, declaradas = secciones_de_api()
    sueltas = []
    for fichero, secs in declaradas.items():
        for s in sorted(secs):
            if s not in atendidas:
                sueltas.append((fichero, s))

    print(f'Entradas del índice: {len(entradas)}')
    print(f'Rutas del enrutador: {len(patrones)}')
    print(f'Secciones de Administración: {len(secciones)}')

    print(f'Secciones de la API: {len(atendidas)} atendidas')

    if sueltas:
        print(f'\nRUTAS DE LA API SIN ENGANCHAR EN index.php ({len(sueltas)}):')
        for fichero, s in sueltas:
            print(f'   {fichero:20s} atiende «{s}» y nadie se la pasa')
        print("\n   Añade  case '<seccion>':  al switch de api/index.php.")

    if sin_ruta or sin_seccion or sueltas:
        if sin_ruta:
            print(f'\nAPUNTAN A UNA PANTALLA QUE NO EXISTE ({len(sin_ruta)}):')
            for lista, titulo, ruta in sin_ruta:
                print(f'   {lista:10s} {titulo:45s} → {ruta}')
        if sin_seccion:
            print(f'\nSECCIÓN DE ADMINISTRACIÓN INEXISTENTE ({len(sin_seccion)}):')
            for lista, titulo, ruta in sin_seccion:
                print(f'   {lista:10s} {titulo:45s} → {ruta}')
            print('\n   Estas no fallan: pintan el menú de Administración y la')
            print('   persona tiene que buscar otra vez dentro.')
        print('\nCorrige la ruta en indice-ajustes.js, o añade la pantalla.')
        return 1

    print('Todas las entradas del buscador llevan a una pantalla real.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
