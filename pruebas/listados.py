#!/usr/bin/env python3
"""
Que la lista de partes y su número digan la verdad.

La cabecera de la lista pone «20 PARTES» y la gente se lo cree: es lo que
mira el responsable para saber si el turno va bien. Si ese número y la
lista no cuentan lo mismo, nadie lo nota —hay que sumar las páginas a
mano para pillarlo— y se toman decisiones con un número inventado.

Importa ahora más que antes porque el número ya no siempre se cuenta: en
la primera página, si vienen menos partes que el tope, el total son los
que han venido y se ahorra barrer la tabla otra vez. Con cien mil partes
eso bajó las búsquedas de 1,30 s a 0,67 s —la mitad—, pero abre la puerta
a que un día alguien «optimice» el número hasta volverlo aproximado.

Aquí se comprueba, con cada filtro:

  · que el total coincide con las filas cuando caben todas;
  · que pasando página se llega exactamente al total, sin repetir ni
    saltarse ninguno;
  · que un filtro que no encuentra nada dice 0 y no «todos»;
  · y que el total del filtro no es nunca mayor que el de la lista sin
    filtrar, que es la equivocación clásica al tocar esto.

   python3 pruebas/listados.py
"""

import json
import os
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = os.environ.get('HURAKANTICK_API',
                      'http://127.0.0.1/hurakantick/api/index.php?_ruta=')
CLAVE = entorno.CLAVE
FILTROS = [
    ('sin filtro',        ''),
    ('míos',              '&mios=1'),
    ('sin asignar',       '&sin_asignar=1'),
    ('urgentes',          '&prioridad=3'),
    ('buscando «a»',      '&q=a'),
    ('buscando una palabra corriente', '&q=agua'),
    ('buscando algo que no existe',    '&q=zzzznohaynadaasi'),
    ('por código',        '&q=T-00012'),
]


def llamar(ruta, token=None):
    pet = urllib.request.Request(BASE + ruta)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(pet, None, timeout=120) as r:
            return r.status, json.loads(r.read(9000000))
    except urllib.error.HTTPError as e:
        return e.code, {}
    except Exception:                            # noqa: BLE001
        return 0, {}


def main() -> int:
    pet = urllib.request.Request(BASE + 'auth/login', method='POST')
    pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, json.dumps(
                {'correo': entorno.usuario('admin'), 'password': CLAVE}).encode(),
                timeout=60) as r:
            ficha = json.loads(r.read(200000))['token']
    except Exception as e:                       # noqa: BLE001
        print(f'No se ha podido entrar: {str(e)[:90]}')
        return 1

    fallos = []
    _, d = llamar('tickets&limite=200', ficha)
    total_sin_filtro = d.get('total')
    if not isinstance(total_sin_filtro, int):
        print('La lista no devuelve ningún total.')
        return 1
    print(f'Partes visibles en total: {total_sin_filtro}')

    revisados = 0
    for nombre, extra in FILTROS:
        revisados += 1
        # Con un tope grande: si caben todas, el total TIENE que ser las
        # que han venido.
        _, d = llamar(f'tickets&limite=200{extra}', ficha)
        total = d.get('total')
        filas = d.get('tickets') or []
        if not isinstance(total, int):
            fallos.append(f'{nombre}: la lista no devuelve total.')
            continue
        if len(filas) < 200 and total != len(filas):
            fallos.append(f'{nombre}: dice «{total}» y trae {len(filas)} '
                          'partes, cabiendo todos. El número de la cabecera '
                          'está mal.')
        if total > total_sin_filtro:
            fallos.append(f'{nombre}: dice «{total}», más que la lista sin '
                          f'filtrar ({total_sin_filtro}). Un filtro no puede '
                          'encontrar más que todo.')

        # Y pasando página de cinco en cinco se tiene que llegar justo al
        # total, sin repetidos.
        if total <= 60:
            vistos, pagina, tope = [], 0, 5
            while pagina < 200:
                _, p = llamar(f'tickets&limite={tope}&offset={pagina}{extra}',
                              ficha)
                trozo = p.get('tickets') or []
                if not trozo:
                    break
                vistos += [t.get('id_ticket') for t in trozo]
                if p.get('total') != total:
                    fallos.append(
                        f'{nombre}: el total cambia según la página '
                        f'({total} en la primera, {p.get("total")} en el '
                        f'salto {pagina}).')
                    break
                pagina += tope
            if len(vistos) != total:
                fallos.append(f'{nombre}: pasando página salen {len(vistos)} '
                              f'partes y el total dice {total}.')
            if len(set(vistos)) != len(vistos):
                repes = len(vistos) - len(set(vistos))
                fallos.append(f'{nombre}: pasando página se repiten {repes} '
                              'partes, así que alguna otra no se ve nunca.')

    # Lo que no existe tiene que dar cero, no todo.
    _, d = llamar('tickets&limite=50&q=zzzznohaynadaasi', ficha)
    if (d.get('total') or 0) != 0:
        fallos.append('Buscando algo que no existe devuelve '
                      f'{d.get("total")} partes: el filtro no se aplica.')

    # Un informe y los partes que hay detrás («Ver los partes»): el mismo
    # número. En MySQL esto daba error 500 —«IN (SELECT … LIMIT)» no lo
    # admite— y ninguna prueba lo pedía, porque en SQLite funcionaba.
    import urllib.request as _ur
    pet = _ur.Request(BASE + 'informes/ejecutar', method='POST',
                      data=json.dumps({'agrupar': 'estado', 'medida': 'cuenta', 'detalle': True}).encode())
    pet.add_header('Content-Type', 'application/json')
    pet.add_header('Authorization', 'Bearer ' + ficha)
    try:
        with _ur.urlopen(pet, timeout=120) as r:
            inf = json.loads(r.read())
        tot = int((inf.get('total') or {}).get('partes') or 0)
        detras = len(inf.get('partes') or [])
        if detras != min(tot, 300):
            fallos.append(f'Un informe dice {tot} partes y «Ver los partes» trae {detras}.')
        revisados += 1
    except Exception as e:                            # noqa: BLE001
        fallos.append(f'Un informe con «Ver los partes» no contesta: {e}')

    print(f'Filtros revisados: {revisados}')
    print(f'  que no cuadran:  {len(fallos)}')
    if fallos:
        print('\nLA LISTA Y SU NÚMERO NO DICEN LO MISMO:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nEl número de la cabecera es exacto, y pasando página sale la '
          'cuenta.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
