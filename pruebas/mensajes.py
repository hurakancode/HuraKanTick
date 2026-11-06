#!/usr/bin/env python3
"""
Leer una conversación apaga sus avisos (y sólo los suyos).

Cada mensaje del chat de un parte deja dos cosas a quien participa: el
mensaje sin leer en la conversación y un aviso «Mensaje · T-…» en la
campana. Leer la conversación marcaba lo primero y dejaba lo segundo:
quien había leído los seis mensajes de Fany seguía con seis avisos sin
leer, la campana en rojo y «Avisos · N» contando lo ya visto. Se vio al
rehacer las capturas de la guía: Carlos había leído la conversación de
Daniel y su aviso seguía ahí.

Lo que se comprueba, pidiéndolo de verdad por la API:

  1. tres mensajes de otro dejan tres avisos de chat sin leer;
  2. tras leer la conversación quedan 0, y «no_leidas» baja lo mismo;
  3. un aviso que NO es de chat del mismo parte sigue sin leer;
  4. los avisos de chat de OTRA persona en ese parte no se tocan;
  5. la chapa de «Mensajes» cuenta la conversación una vez, no una
     vez más por cada mensaje (eso hacía: 6 mensajes = 7);
  6. «chat/leido» devuelve los avisos que quedan, los mismos que la campana.

   python3 pruebas/mensajes.py
"""

import json
import os
import sys
import urllib.error
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

BASE = entorno.API
MARCA = 'zz-prueba-mensajes'


def llamar(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(BASE + ruta, method=metodo)
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        return e.code, {'error': e.read(2000).decode('utf-8', 'replace')}


def entrar(puesto):
    st, r = llamar('POST', 'auth/login',
                   {'correo': entorno.usuario(puesto), 'password': entorno.CLAVE})
    if st != 200 or 'token' not in r:
        print(f'  ROTO: no entra {puesto}: {st} {r}')
        sys.exit(2)
    return r['token'], int(r['usuario']['id_login'])


def sin_leer_chat(id_login, id_ticket):
    return int(entorno.valor(
        "SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND id_ticket = ?"
        " AND evento = 'chat' AND leida = 0", [id_login, id_ticket]) or 0)


def main():
    tok_a, id_a = entrar('admin')
    tok_l, id_l = entrar('luis')
    st, r = llamar('GET', 'tickets&limite=5', token=tok_l)
    if st != 200 or not r.get('tickets'):
        print(f'  ROTO: luis no ve ningún parte: {st}')
        return 2
    parte = int(r['tickets'][0]['id_ticket'])
    tope_msg = int(entorno.valor('SELECT COALESCE(MAX(id_mensaje), 0) FROM chat_mensajes') or 0)
    tope_av = int(entorno.valor('SELECT COALESCE(MAX(id_notificacion), 0) FROM notificaciones') or 0)
    fallos = []

    try:
        # Lo que hubiera antes de esta prueba no cuenta: se parte de cero.
        llamar('POST', f'tickets/{parte}/chat/leido', {}, tok_a)
        llamar('POST', f'tickets/{parte}/chat/leido', {}, tok_l)

        # El administrador escribe (así participa) y luis le contesta tres veces.
        llamar('POST', f'tickets/{parte}/chat', {'texto': f'{MARCA}: ¿cómo va?'}, tok_a)
        for i in range(3):
            st, _ = llamar('POST', f'tickets/{parte}/chat', {'texto': f'{MARCA}: {i}'}, tok_l)
            if st not in (200, 201):
                print(f'  ROTO: luis no puede escribir en T-{parte}: {st}')
                return 2

        # Un aviso que no es del chat, en el mismo parte.
        entorno.consulta_cruda(
            'Db::insert("notificaciones", ["id_login" => ' + str(id_a) + ', "id_ticket" => '
            + str(parte) + ', "titulo" => "' + MARCA + '", "cuerpo" => "cambio de estado",'
            ' "leida" => 0, "fecha" => Db::now(), "updated_at" => Db::now(),'
            ' "evento" => "cambio_estado", "silenciosa" => 0]);')

        # Y uno más del administrador, para que luis tenga un aviso suyo
        # en este parte: con 0, «no se ha tocado» no demostraría nada.
        llamar('POST', f'tickets/{parte}/chat', {'texto': f'{MARCA}: gracias'}, tok_a)

        antes = sin_leer_chat(id_a, parte)
        de_luis = sin_leer_chat(id_l, parte)
        if de_luis < 1:
            fallos.append('luis no tiene ningún aviso de chat en el parte: el punto 4 no prueba nada')
        _, n0 = llamar('GET', 'notificaciones', token=tok_a)
        print(f'  avisos de chat sin leer del administrador en T-{parte}: {antes}')
        if antes < 3:
            fallos.append(f'tres mensajes dejan {antes} avisos de chat (se esperaban 3): '
                          'sin ellos esta prueba no prueba nada')

        # 5. La chapa de «Mensajes»: la conversación cuenta UNA, y sus
        #    mensajes no vuelven a contar como avisos.
        _, cat = llamar('GET', 'catalogo', token=tok_a)
        chapa = cat.get('sin_leer') or {}
        otros = int(entorno.valor(
            "SELECT COUNT(*) FROM notificaciones WHERE id_login = ? AND leida = 0"
            " AND (evento IS NULL OR evento <> 'chat')", [id_a]) or 0)
        print(f'  chapa: {chapa}  (avisos que no son de chat: {otros})')
        if int(chapa.get('avisos', -1)) != otros:
            fallos.append(f'la chapa cuenta {chapa.get("avisos")} avisos y sólo hay {otros} '
                          'que no sean de chat: los mensajes cuentan dos veces')
        if int(chapa.get('total', -1)) != int(chapa.get('chats', 0)) + otros:
            fallos.append('el total de la chapa no es conversaciones + avisos')

        st, leido = llamar('POST', f'tickets/{parte}/chat/leido', {}, tok_a)
        despues = sin_leer_chat(id_a, parte)
        _, n1 = llamar('GET', 'notificaciones', token=tok_a)
        # 6. La respuesta trae los avisos que quedan, para que la campana
        #    se ponga al día sin esperar a la sincronización.
        if leido.get('no_leidas') != n1.get('no_leidas'):
            fallos.append(f'chat/leido dice no_leidas={leido.get("no_leidas")} y la campana '
                          f'{n1.get("no_leidas")}')
        print(f'  después de leer la conversación: {despues}')
        if despues != 0:
            fallos.append(f'leída la conversación quedan {despues} avisos de chat sin leer')
        bajada = int(n0.get('no_leidas', 0)) - int(n1.get('no_leidas', 0))
        print(f'  «no_leidas» de la campana: {n0.get("no_leidas")} -> {n1.get("no_leidas")}')
        if bajada != antes:
            fallos.append(f'la campana bajó {bajada}, se esperaba {antes}')

        otro = int(entorno.valor(
            "SELECT leida FROM notificaciones WHERE id_login = ? AND titulo = ?",
            [id_a, MARCA]) or 0)
        if otro != 0:
            fallos.append('se apagó también un aviso de cambio de estado del mismo parte')
        else:
            print('  el aviso de cambio de estado del mismo parte sigue sin leer')

        if sin_leer_chat(id_l, parte) != de_luis:
            fallos.append('se tocaron los avisos de chat de otra persona')
        else:
            print(f'  los avisos de luis en ese parte, intactos ({de_luis})')
    finally:
        entorno.consulta_cruda(
            f'Db::run("DELETE FROM chat_lecturas WHERE id_mensaje > {tope_msg}");'
            f'Db::run("DELETE FROM chat_mensajes WHERE id_mensaje > {tope_msg}");'
            f'Db::run("DELETE FROM notificaciones WHERE id_notificacion > {tope_av}");')

    if fallos:
        for f in fallos:
            print('  FALLA:', f)
        return 1
    print('  BIEN: leer la conversación apaga sus avisos, y sólo los suyos.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
