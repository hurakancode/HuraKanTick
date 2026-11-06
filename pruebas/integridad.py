#!/usr/bin/env python3
"""
Que el panel de integridad vea lo que tiene que ver.

Un panel que siempre dice «todo bien» es peor que no tenerlo: da una
tranquilidad que no se ha ganado. Así que esto no mira sólo que conteste,
sino que DETECTA. Se siembran a propósito cuatro desarreglos de los que
se han encontrado de verdad en esta aplicación:

  · historial de un parte que ya no existe (lo dejaba el borrado
    definitivo en MySQL: 18.947 líneas en la base de pruebas);
  · una ubicación sin hotel (la dejaba el alta de ubicaciones desde un
    parte: el buscador no la volvía a encontrar);
  · un aviso que apunta a un parte que no existe;
  · una foto de un parte que no existe.

Cada uno tiene que salir marcado. Se quitan, y cada uno tiene que volver
a «bien». Además:

  · sólo lo abre un administrador (un técnico, 403; sin sesión, 401);
  · no escribe nada: se cuentan las filas antes y después;
  · contesta en un tiempo razonable.

   python3 pruebas/integridad.py
"""

import json
import os
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

API = entorno.API
MARCA = 'zz-integridad'
NO_EXISTE = 987654321

# id de la comprobación (grupo.id) → cómo sembrar el desarreglo (PHP)
def _clonar(tabla, clave, cambios):
    """PHP que copia una fila real de la tabla con estos cambios: así se
    respetan todas las columnas obligatorias de cualquier motor."""
    pares = ', '.join(f'"{k}" => {v}' for k, v in cambios.items())
    return (f'$f = Db::one("SELECT * FROM {tabla} ORDER BY {clave} LIMIT 1");'
            f'if ($f) {{ unset($f["{clave}"]); $f = array_merge($f, [{pares}]); Db::insert("{tabla}", $f); }}')


SIEMBRA = {
    'Partes.historial': _clonar('ticket_incidencias_historial', 'id_historial', {
        'id_ticket': NO_EXISTE, 'uuid': f'"{MARCA}"', 'detalle_ticket': f'"{MARCA}"'}),
    'Ubicaciones.sin_hotel': _clonar('rel_zonas', 'id_rel', {
        'id_hotel': 'null', 'ruta': f'"{MARCA}"', 'id_zona_4': NO_EXISTE % 100000}),
    'Partes.avisos': _clonar('notificaciones', 'id_notificacion', {
        'id_ticket': NO_EXISTE, 'titulo': f'"{MARCA}"'}),
    # En una instalación recién hecha no hay ninguna foto que copiar: se
    # escribe entera. (Así salió la primera vez desde el zip: «no detecta»,
    # cuando lo que pasaba es que no se había sembrado nada.)
    'Partes.fotos_sueltas': (
        f'Db::insert("adjuntos", ["uuid" => "{MARCA}", "id_ticket" => {NO_EXISTE}, "archivo" => "{MARCA}.png",'
        f' "nombre_original" => "{MARCA}.png", "mime" => "image/png", "tamano" => 1, "hash" => "{MARCA}",'
        f' "ancho" => 1, "alto" => 1, "id_realiza_accion" => Db::value("SELECT MIN(id_login) FROM login"),'
        f' "borrado" => 0, "fecha_creado" => Db::now(), "updated_at" => Db::now()]);'),
}
QUITAR = (
    f'Db::run("DELETE FROM ticket_incidencias_historial WHERE id_ticket = {NO_EXISTE}");'
    f'Db::run("DELETE FROM rel_zonas WHERE ruta = ?", ["{MARCA}"]);'
    f'Db::run("DELETE FROM notificaciones WHERE id_ticket = {NO_EXISTE}");'
    f'Db::run("DELETE FROM adjuntos WHERE id_ticket = {NO_EXISTE}");'
)
# En SQLite las claves foráneas impiden sembrar huérfanos: se apagan sólo
# para sembrar (es justo lo que hacía MySQL al no tenerlas declaradas).
SIN_CLAVES = 'if (Db::driver() === "sqlite") { Db::run("PRAGMA foreign_keys = OFF"); }'

CONTAR = ['login', 'ticket_incidencias', 'ticket_incidencias_historial', 'rel_zonas',
          'notificaciones', 'adjuntos', 'chat_mensajes', 'ajustes_sistema']


def llamar(ruta, token=None):
    pet = urllib.request.Request(API + ruta)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    t0 = time.time()
    try:
        with urllib.request.urlopen(pet, timeout=120) as r:
            return r.status, json.loads(r.read() or b'{}'), time.time() - t0
    except urllib.error.HTTPError as e:
        return e.code, {}, time.time() - t0


def entrar(puesto):
    pet = urllib.request.Request(API + 'auth/login', method='POST')
    pet.add_header('Content-Type', 'application/json')
    cuerpo = json.dumps({'correo': entorno.usuario(puesto), 'password': entorno.CLAVE}).encode()
    with urllib.request.urlopen(pet, cuerpo, timeout=60) as r:
        return json.loads(r.read())['token']


def estados(d):
    return {f"{c['grupo']}.{c['id']}": c['estado'] for c in d.get('comprobaciones') or []}


def contar():
    return {t: entorno.valor(f'SELECT COUNT(*) FROM {t}') for t in CONTAR}


def main():
    fallos = []
    ad, tec = entrar('admin'), entrar('luis')

    st, _, _ = llamar('integridad', tec)
    if st != 403:
        fallos.append(f'un técnico abre el panel (contesta {st}; tiene que ser 403)')
    st, _, _ = llamar('integridad')
    if st != 401:
        fallos.append(f'sin sesión, el panel contesta {st} (tiene que ser 401)')

    entorno.consulta_cruda(QUITAR)          # por si quedó algo de una pasada rota
    antes = contar()
    st, d, dura = llamar('integridad', ad)
    if st != 200:
        print(f'  ROTO: el panel no contesta al administrador ({st})')
        return 2
    despues = contar()
    if antes != despues:
        cambios = {t: (antes[t], despues[t]) for t in CONTAR if antes[t] != despues[t]}
        fallos.append(f'abrir el panel ha cambiado datos: {cambios}')
    r = d.get('resumen') or {}
    n = len(d.get('comprobaciones') or [])
    if sum(r.values()) != n:
        fallos.append(f'el resumen ({r}) no suma las {n} comprobaciones')
    if dura > 15:
        fallos.append(f'tarda {dura:.1f} s en comprobar')
    e0 = estados(d)
    faltan = [k for k in SIEMBRA if k not in e0]
    if faltan:
        fallos.append(f'faltan comprobaciones en el panel: {faltan}')
    print(f'  {n} comprobaciones en {dura:.2f} s · {r}')

    # Que DETECTE: sembrar, mirar, quitar, mirar.
    try:
        entorno.consulta_cruda(SIN_CLAVES + ''.join(SIEMBRA.values()))
        sembrado = {
            'Partes.historial': entorno.valor(f'SELECT COUNT(*) FROM ticket_incidencias_historial WHERE id_ticket = {NO_EXISTE}'),
            'Ubicaciones.sin_hotel': entorno.valor('SELECT COUNT(*) FROM rel_zonas WHERE ruta = ?', [MARCA]),
            'Partes.avisos': entorno.valor(f'SELECT COUNT(*) FROM notificaciones WHERE id_ticket = {NO_EXISTE}'),
            'Partes.fotos_sueltas': entorno.valor(f'SELECT COUNT(*) FROM adjuntos WHERE id_ticket = {NO_EXISTE}'),
        }
        for k, n in sembrado.items():
            if not n:
                fallos.append(f'la prueba no ha conseguido sembrar «{k}»: así no demuestra nada')
        _, d1, _ = llamar('integridad', ad)
        e1 = estados(d1)
        for k in SIEMBRA:
            if e1.get(k) == 'bien':
                fallos.append(f'no detecta lo sembrado en «{k}»')
            else:
                print(f'  detecta {k:24} → {e1.get(k)}')
    finally:
        entorno.consulta_cruda(QUITAR)
    _, d2, _ = llamar('integridad', ad)
    e2 = estados(d2)
    for k in SIEMBRA:
        if e0.get(k) == 'bien' and e2.get(k) != 'bien':
            fallos.append(f'«{k}» no vuelve a «bien» al quitar lo sembrado ({e2.get(k)})')

    if fallos:
        for f in fallos:
            print('  FALLA:', f)
        return 1
    print('  BIEN: sólo lo abre el administrador, no cambia nada y ve lo que tiene que ver.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
