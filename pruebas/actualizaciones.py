#!/usr/bin/env python3
"""
¿Se entera la aplicación de que hay una versión nueva publicada?

La referencia es el repositorio público hurakancode/HuraKan-Tick. Cuando
se escribió esto estaba vacío, así que no se sabía cómo se iba a publicar
cada versión, y lib/Actualizaciones.php entiende las tres formas
habituales. Esta prueba no depende de lo que haya en GitHub ese día:
levanta un GitHub de mentira en esta máquina y repasa los casos.

  · una Release más nueva que la instalada            → avisa
  · una Release igual que la instalada                → no avisa
  · sin Releases, con etiquetas                       → coge la más alta
  · sin Releases ni etiquetas, con version.json       → lo lee
  · el repositorio vacío (como estaba al escribir esto) → lo dice, sin error
  · sin red                                           → lo dice, sin error

Y dos cosas que no se ven pero importan: que no pregunte cada vez (se
guarda lo que sabe doce horas) y que un fallo de red NO se guarde, porque
taparía doce horas una versión nueva.

   python3 pruebas/actualizaciones.py
"""

import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

PUERTO = 8095
REPO = 'hurakancode/HuraKan-Tick'

# Lo que contesta el GitHub de mentira en cada caso.
CASOS = {
    'release_nueva': {f'/repos/{REPO}/releases/latest':
                      (200, {'tag_name': 'v99.1.0',
                             'html_url': f'https://github.com/{REPO}/releases/tag/v99.1.0'})},
    'release_igual': {f'/repos/{REPO}/releases/latest': (200, {'tag_name': 'v{ACTUAL}'})},
    'etiquetas':     {f'/repos/{REPO}/tags':
                      (200, [{'name': 'v7.1.0'}, {'name': 'v99.3.0'}, {'name': 'prueba'},
                             {'name': 'v99.2.9'}])},
    'version_json':  {f'/{REPO}/HEAD/version.json': (200, {'version': '99.5.0'})},
    'vacio':         {},
}

ROUTER = r'''<?php
$caso = json_decode(file_get_contents(__DIR__ . '/caso.json'), true) ?: [];
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
file_put_contents(__DIR__ . '/pedidas.txt', $ruta . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (isset($caso[$ruta])) { http_response_code($caso[$ruta][0]); echo json_encode($caso[$ruta][1]); }
else { http_response_code(404); echo json_encode(['message' => 'Not Found']); }
'''


def preguntar(api, forzar=True):
    """Lanza lib/Actualizaciones.php de la instalación bajo prueba."""
    php = ('$c = require $raiz . "/api/config.php";'
           'require_once $raiz . "/api/lib/Actualizaciones.php";'
           'echo json_encode(Actualizaciones::estado($c, ' + ('true' if forzar else 'false') + '));')
    guion = '$raiz = ' + json.dumps(entorno.DIR) + ';' + php
    amb = dict(os.environ, HURAKANTICK_ACT_API=api, HURAKANTICK_ACT_RAW=api)
    r = subprocess.run(['php', '-r', guion], capture_output=True, text=True,
                       timeout=60, env=amb)
    try:
        return json.loads(r.stdout)
    except Exception:                                 # noqa: BLE001
        return {'_roto': (r.stdout + r.stderr)[-300:]}


def main() -> int:
    if not os.path.isfile(os.path.join(entorno.DIR, 'api', 'lib', 'Actualizaciones.php')):
        print('Esta instalación no tiene lib/Actualizaciones.php.')
        return 2
    with socket.socket() as s:
        if s.connect_ex(('127.0.0.1', PUERTO)) == 0:
            print(f'El puerto {PUERTO} está ocupado: el GitHub de mentira no puede arrancar.')
            return 2

    cache = os.path.join(entorno.DIR, 'db', 'actualizacion.json')
    respaldo = cache + '.antes-de-la-prueba'
    if os.path.isfile(cache):
        shutil.move(cache, respaldo)

    tmp = tempfile.mkdtemp(prefix='github-de-mentira-')
    open(os.path.join(tmp, 'router.php'), 'w').write(ROUTER)
    servidor = subprocess.Popen(['php', '-S', f'127.0.0.1:{PUERTO}', 'router.php'],
                                cwd=tmp, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, start_new_session=True)
    api = f'http://127.0.0.1:{PUERTO}'
    fallos = []
    try:
        for _ in range(30):
            time.sleep(0.2)
            with socket.socket() as s:
                if s.connect_ex(('127.0.0.1', PUERTO)) == 0:
                    break

        def poner(caso):
            actual = preguntar(api).get('actual', '0')
            datos = json.loads(json.dumps(CASOS[caso]).replace('{ACTUAL}', actual))
            open(os.path.join(tmp, 'caso.json'), 'w').write(json.dumps(datos))
            if os.path.isfile(cache):
                os.remove(cache)

        esperado = {
            'release_nueva': (True,  '99.1.0', 'release'),
            'release_igual': (False, None,     'release'),
            'etiquetas':     (True,  '99.3.0', 'etiqueta'),
            'version_json':  (True,  '99.5.0', 'version.json'),
        }
        for caso, (hay, ver, como) in esperado.items():
            poner(caso)
            r = preguntar(api)
            if '_roto' in r:
                fallos.append(f'{caso}: la comprobación revienta: {r["_roto"]}')
                continue
            ok = (r.get('hay_nueva') is hay and r.get('como') == como
                  and (ver is None or r.get('publicada') == ver))
            print(f'  {caso:14} → publicada {r.get("publicada")}, nueva: '
                  f'{"sí" if r.get("hay_nueva") else "no"}, por {r.get("como") or "—"}'
                  + ('' if ok else '   ← MAL'))
            if not ok:
                fallos.append(f'{caso}: esperaba nueva={hay}, versión {ver}, por {como}; '
                              f'contesta {json.dumps(r, ensure_ascii=False)[:200]}')

        # El repositorio vacío: como estaba al escribir esto.
        poner('vacio')
        r = preguntar(api)
        print(f'  {"vacío":14} → {r.get("motivo") or r}')
        if r.get('hay_nueva') or 'ninguna versión publicada' not in (r.get('motivo') or ''):
            fallos.append('Con el repositorio vacío no dice «todavía no hay ninguna '
                          f'versión publicada»: {r}')
        # Y esa respuesta se guarda: es una respuesta, no un fallo. Si no,
        # cada visita a Administración son tres consultas a GitHub, que
        # corta a las sesenta por hora.
        open(os.path.join(tmp, 'pedidas.txt'), 'w').close()
        preguntar(api, forzar=False)
        if open(os.path.join(tmp, 'pedidas.txt')).read().strip():
            fallos.append('«No hay nada publicado» no se guarda: cada visita vuelve '
                          'a preguntar a GitHub, que corta a las sesenta consultas '
                          'por hora.')

        # Que no pregunte cada vez: la segunda, sin forzar, sale de lo guardado.
        poner('release_nueva')
        preguntar(api)
        open(os.path.join(tmp, 'pedidas.txt'), 'w').close()
        r2 = preguntar(api, forzar=False)
        pedidas = open(os.path.join(tmp, 'pedidas.txt')).read().strip()
        if pedidas:
            fallos.append('Sin forzar vuelve a preguntar a GitHub en vez de usar lo '
                          'guardado: con varias personas mirando, GitHub cortaría por '
                          'límite de consultas.')
        elif r2.get('publicada') != '99.1.0':
            fallos.append(f'Lo guardado no devuelve lo que se supo: {r2}')
        else:
            print('  sin forzar      → sale de lo guardado, sin preguntar')

    finally:
        try:
            os.killpg(os.getpgid(servidor.pid), 15)
        except Exception:                             # noqa: BLE001
            servidor.kill()
        time.sleep(0.3)

    # Sin red: el GitHub de mentira ya no está.
    if os.path.isfile(cache):
        os.remove(cache)
    r = preguntar(api)
    print(f'  {"sin red":14} → {r.get("motivo") or r}')
    if '_roto' in r or 'No se ha podido llegar' not in (r.get('motivo') or ''):
        fallos.append(f'Sin red no lo dice con claridad, o revienta: {r}')
    if os.path.isfile(cache):
        fallos.append('Un fallo de red se ha guardado como respuesta: taparía doce '
                      'horas una versión nueva.')

    shutil.rmtree(tmp, ignore_errors=True)
    if os.path.isfile(cache):
        os.remove(cache)
    if os.path.isfile(respaldo):
        shutil.move(respaldo, cache)

    print(f'\n  que sale mal: {len(fallos)}')
    if fallos:
        print('\nLA COMPROBACIÓN DE VERSIONES:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nSe entera de una versión nueva se publique como se publique, y '
          'sin red lo dice sin romper nada.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
