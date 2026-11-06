#!/usr/bin/env python3
"""
¿Se encuentra desde Ajustes todo lo que hay en Ajustes?

El buscador se apoya en un índice escrito a mano, y ya se quedó atrás una
vez: «Forzar la actualización de todos» existía en Administración y el
buscador no la conocía, porque al añadirla nadie se acordó del índice.

Esta prueba recorre las pantallas de verdad, saca las etiquetas que una
persona ve y comprueba que cada una se encuentra. Si alguien añade una
opción y no la indexa, esto lo dice.

    python3 pruebas/buscador.py
"""
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
VISTAS = RAIZ / 'www/assets/js/views'
INDICE = RAIZ / 'www/assets/js/indice-ajustes.js'

# Pantallas donde una persona busca ajustes.
PANTALLAS = ['admin.js', 'cuenta.js', 'ajustes.js', 'avisos-ajustes.js']

# Lo que no es una opción que se pueda buscar: encabezados, estados y
# textos que cambian con los datos.
RUIDO = re.compile(r'^\s*$|^\$\{|^[0-9]|^(Sin|Hay|Ya|No|Todos|Activo|Inactivo|Obligatorio|Deshabilitado)\b')


def etiquetas_de(fichero: Path) -> list[str]:
    """Las etiquetas literales que se ven en esa pantalla."""
    texto = fichero.read_text(encoding='utf-8')
    salida = []

    # <span class="fila__texto">Lo que se ve
    for m in re.finditer(r'class="fila__texto">\s*([^<\n$]{4,60})', texto):
        salida.append(m.group(1).strip())

    # fila('/ruta', 'icono', 'Título', ...)
    for m in re.finditer(r"fila\('[^']*',\s*'[^']*',\s*'([^']{4,60})'", texto):
        salida.append(m.group(1).strip())

    return [e for e in salida if not RUIDO.match(e)]


def main() -> int:
    indice = INDICE.read_text(encoding='utf-8').lower()
    faltan = []
    total = 0

    for nombre in PANTALLAS:
        f = VISTAS / nombre
        if not f.exists():
            continue
        for etiqueta in etiquetas_de(f):
            total += 1
            # Basta con que las primeras palabras estén en el índice: el
            # buscador parte por palabras, no exige la frase entera.
            clave = ' '.join(etiqueta.lower().split()[:3])
            if clave and clave not in indice:
                faltan.append((nombre, etiqueta))

    print(f'Etiquetas revisadas: {total}')

    if faltan:
        print(f'\nNO SE ENCUENTRAN DESDE EL BUSCADOR ({len(faltan)}):')
        for fichero, etiqueta in sorted(set(faltan)):
            print(f'   {fichero:20s} {etiqueta}')
        print('\nAñádelas a PANTALLAS o ACCIONES en indice-ajustes.js.')
        return 1

    print('Todo lo que se ve en los ajustes se puede buscar.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
