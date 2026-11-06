#!/usr/bin/env python3
"""
¿Alguna variable se la comen las comillas españolas?

Esto es una trampa de PHP que no se ve leyendo el código. En:

    "El correo «$correo» no vale"

PHP admite bytes altos en los nombres de variable, así que no lee
`$correo` seguido de `»`: lee una variable llamada `$correo»`, que no
existe. El mensaje sale sin el dato y en el registro aparece un aviso de
«variable no definida».

No rompe nada a la vista —el texto se imprime igual, sólo que vacío—, y
por eso llevaba tiempo así en tools/importar.php sin que nadie lo notara.

Se arregla escribiendo {$correo} en vez de $correo.

    python3 pruebas/comillas.py
"""
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent

# Los caracteres que PHP se traga como parte del nombre de la variable.
PEGAJOSOS = '»«ºª°ñáéíóúÁÉÍÓÚÑ'

PATRON = re.compile(r'\$[a-zA-Z_][a-zA-Z0-9_]*[' + PEGAJOSOS + ']')


def main() -> int:
    malos = []
    revisados = 0

    for f in sorted(list((RAIZ / 'api').rglob('*.php'))
                    + list((RAIZ / 'tools').rglob('*.php'))):
        revisados += 1
        for n, linea in enumerate(f.read_text(encoding='utf-8').split('\n'), 1):
            # Sólo dentro de cadenas con comillas dobles: en las simples
            # no se interpola nada y no hay problema.
            if '"' not in linea:
                continue
            for m in PATRON.finditer(linea):
                malos.append((f.relative_to(RAIZ), n, m.group(0)))

    print(f'Ficheros revisados: {revisados}')

    if malos:
        print(f'\nVARIABLES QUE SE COME LA COMILLA ({len(malos)}):')
        for fichero, n, trozo in malos:
            print(f'   {str(fichero):34s}:{n:<5d} {trozo}')
        print('\nEscríbelas entre llaves: "{$variable}".')
        return 1

    print('Ninguna variable se pierde dentro de las comillas.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
