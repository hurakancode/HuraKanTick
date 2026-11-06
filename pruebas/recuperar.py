#!/usr/bin/env python3
"""
La vida de una cuenta: olvidar la contraseña, cambiarla, salir, y las
llaves de auto-entrada. De punta a punta, como lo haría la gente.

Nació de un fallo que no veía nadie: restablecer la contraseña con el
código que da el administrador (Administración → Contraseñas olvidadas)
devolvía un error 500 SIEMPRE. El código usaba una variable que no
existía. Ninguna prueba llegaba a usar el código: había pruebas de que
no se filtra, de que no revela quién tiene cuenta… y ninguna lo usaba.

Lo que se comprueba, con una persona de la demostración:

  1. el administrador da un código, la persona lo usa con su correo y
     entra con la contraseña nueva; las sesiones que tenía abiertas caen;
  2. lo mismo con el nombre de usuario en vez del correo;
  3. un código sólo vale una vez;
  4. quien intenta adivinarlo se queda sin intentos: tras cinco fallos,
     ni el código bueno vale;
  5. con un usuario que no existe, la misma respuesta que con uno que
     sí: no se puede averiguar quién tiene cuenta;
  6. cambiar la propia contraseña cierra las demás sesiones, no ésta;
  7. salir invalida la sesión de verdad;
  8. un enlace de auto-entrada deja entrar, y retirado deja de valer al
     instante; con la llave cambiada, no vale.

Al terminar, la persona vuelve a tener la contraseña de la demostración.

   python3 pruebas/recuperar.py
"""

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

API = entorno.API
NUEVA = 'Prueba-Recuperar-2026'


def llamar(metodo, ruta, token=None, cuerpo=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
        datos = json.dumps(cuerpo).encode()
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b'{}')
        except Exception:                              # noqa: BLE001
            return e.code, {}


def entrar(usuario, clave):
    st, r = llamar('POST', 'auth/login', cuerpo={'correo': usuario, 'password': clave})
    return r.get('token') if st == 200 else None


def vale(token):
    return llamar('GET', 'auth/yo', token)[0] == 200


def main():
    fallos = []
    ad = entrar(entorno.usuario('admin'), entorno.CLAVE)
    persona = entorno.consulta('SELECT id_login, correo, usuario FROM login WHERE correo LIKE ? OR usuario = ?',
                               ['test1@%', 'jose'])
    if not persona:
        print('  ROTO: no está la persona de prueba (test1 / jose)')
        return 2
    idp, correo, usuario = persona[0]['id_login'], persona[0]['correo'], persona[0]['usuario']
    clave_demo = entorno.CLAVE

    def codigo():
        st, r = llamar('POST', f'usuarios/{idp}/codigo', ad, {})
        return r.get('codigo') if st == 200 else None

    inicio = entorno.valor("SELECT MAX(fecha) FROM registro_seguridad") or '1970-01-01'
    try:
        # 1. Con el correo; y las sesiones que tenía, fuera.
        sesion_vieja = entrar(usuario, clave_demo)
        c = codigo()
        st, r = llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': c, 'nueva': NUEVA})
        if st != 200:
            fallos.append(f'con el código del administrador y el correo, no restablece: {st} {r}')
        elif not entrar(usuario, NUEVA):
            fallos.append('restablecida, no entra con la contraseña nueva')
        if sesion_vieja and vale(sesion_vieja):
            fallos.append('AGUJERO: tras restablecer la contraseña, la sesión de antes sigue valiendo')
        print(f'  restablecer con el correo: {st}')

        # 3. El mismo código, otra vez.
        st, _ = llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': c, 'nueva': NUEVA + 'x'})
        if st == 200:
            fallos.append('AGUJERO: un código de recuperación vale dos veces')

        # 2. Con el nombre de usuario.
        c = codigo()
        st, r = llamar('POST', 'auth/codigo', cuerpo={'correo': usuario, 'codigo': c, 'nueva': NUEVA})
        if st != 200:
            fallos.append(f'con el nombre de usuario, no restablece: {st} {r}')
        print(f'  restablecer con el nombre de usuario: {st}')

        # 4. Adivinar: cinco fallos y ni el bueno vale.
        c = codigo()
        malo = '000000' if c != '000000' else '111111'
        for _ in range(5):
            llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': malo, 'nueva': NUEVA})
        st, _ = llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': c, 'nueva': NUEVA})
        if st == 200:
            fallos.append('AGUJERO: tras cinco intentos fallidos, el código sigue valiendo: se puede adivinar')
        print(f'  tras 5 intentos fallidos, el código bueno: {st} (tiene que ser 401)')

        # 4b. Lo mismo, pero a la vez. El límite se comprobaba leyendo
        # «intentos» y sumando después: treinta intentos lanzados juntos
        # leían todos «0» y se comparaban los treinta. Lo que se mira es
        # el contador: el intento se reserva antes de comparar, así que
        # no puede pasar de 5 —más de 5 querría decir más de 5 códigos
        # comparados—.
        import threading
        c = codigo()
        malo = '000000' if c != '000000' else '111111'
        salida = threading.Barrier(30)

        def adivina():
            salida.wait()
            llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': malo, 'nueva': NUEVA})
        hilos = [threading.Thread(target=adivina) for _ in range(30)]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()
        comparados = int(entorno.valor(
            'SELECT intentos FROM recuperaciones WHERE id_login = ? '
            'ORDER BY id_recuperacion DESC LIMIT 1', [idp]) or 0)
        if comparados > 5:
            fallos.append(f'AGUJERO: treinta intentos a la vez y se han comparado {comparados}: '
                          'el límite de cinco no aguanta peticiones simultáneas')
        print(f'  30 intentos a la vez, comparados: {comparados} (máximo 5)')

        # 4c. El código bueno, mandado veinte veces a la vez: vale UNA.
        c = codigo()
        salida = threading.Barrier(20)
        buenas = []

        def usa():
            salida.wait()
            st, _ = llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': c, 'nueva': NUEVA})
            buenas.append(st)
        hilos = [threading.Thread(target=usa) for _ in range(20)]
        for h in hilos:
            h.start()
        for h in hilos:
            h.join()
        if buenas.count(200) != 1:
            fallos.append(f'El mismo código, veinte veces a la vez: ha valido {buenas.count(200)} '
                          'veces (tiene que ser una)')
        print(f'  el mismo código veinte veces a la vez: vale {buenas.count(200)} (tiene que ser 1)')

        # 5. Quién tiene cuenta: la misma respuesta.
        st1, r1 = llamar('POST', 'auth/codigo', cuerpo={'correo': correo, 'codigo': '123456', 'nueva': NUEVA})
        st2, r2 = llamar('POST', 'auth/codigo', cuerpo={'correo': 'nadie-zz@ninguna.parte', 'codigo': '123456', 'nueva': NUEVA})
        if (st1, r1.get('error')) != (st2, r2.get('error')):
            fallos.append(f'se distingue quién tiene cuenta: «{r1.get("error")}» frente a «{r2.get("error")}»')

        # 6. Cambiar la propia: las demás caen, ésta no.
        a = entrar(usuario, NUEVA)
        b = entrar(usuario, NUEVA)
        st, r = llamar('POST', 'auth/password', a, {'actual': NUEVA, 'nueva': clave_demo})
        if st != 200:
            fallos.append(f'cambiar la propia contraseña: {st} {r}')
        if not vale(a):
            fallos.append('al cambiar la contraseña se cierra también la sesión con la que se ha cambiado')
        if vale(b):
            fallos.append('AGUJERO: al cambiar la contraseña, las demás sesiones siguen abiertas')
        print('  cambiar la propia contraseña: las demás sesiones caen')

        # 7. Salir.
        s = entrar(usuario, clave_demo)
        llamar('POST', 'auth/logout', s, {})
        if vale(s):
            fallos.append('AGUJERO: después de salir, la sesión sigue valiendo')

        # 8. Auto-entrada. Con la función apagada en el hotel, no se genera
        #    (antes se generaba un enlace que luego no abría); encendida, sí.
        global_antes = entorno.valor('SELECT valor FROM ajustes_sistema WHERE clave = ?', ['autologin_global'])
        entorno.consulta_cruda('Db::run("UPDATE ajustes_sistema SET valor = \'0\' WHERE clave = \'autologin_global\'");')
        st0, r0 = llamar('POST', f'usuarios/{idp}/enlace', ad, {'base': 'https://x'})
        if st0 == 200:
            fallos.append('con la auto-entrada apagada se genera un enlace que no abriría')
        entorno.consulta_cruda('Db::run("UPDATE ajustes_sistema SET valor = \'1\' WHERE clave = \'autologin_global\'");')
        llamar('PATCH', f'usuarios/{idp}/enlace', ad, {'activo': True})
        st, r = llamar('POST', f'usuarios/{idp}/enlace', ad, {'base': 'https://x'})
        if st == 200 and r.get('url'):
            q = urllib.parse.parse_qs(urllib.parse.urlparse(r['url']).query)
            u, k = q.get('u', [''])[0], q.get('k', [''])[0]
            st1, r1 = llamar('POST', 'auth/enlace', cuerpo={'u': u, 'k': k})
            st2, _ = llamar('POST', 'auth/enlace', cuerpo={'u': u, 'k': k[:-2] + ('AA' if not k.endswith('AA') else 'BB')})
            llamar('DELETE', f'usuarios/{idp}/enlace', ad)
            st3, _ = llamar('POST', 'auth/enlace', cuerpo={'u': u, 'k': k})
            if st1 != 200:
                fallos.append(f'el enlace de auto-entrada no deja entrar: {st1} {r1}')
            if st2 == 200:
                fallos.append('AGUJERO: el enlace deja entrar con la llave cambiada')
            if st3 == 200:
                fallos.append('AGUJERO: retirado el enlace, sigue dejando entrar')
            print(f'  auto-entrada: entra {st1} · llave cambiada {st2} · retirado {st3}')
            # Y el registro de seguridad dice QUIÉN repartió y retiró la llave.
            # (Lo apuntaba a nombre de nadie: $u sin definir en admin.php.)
            sin_autor = entorno.valor(
                "SELECT COUNT(*) FROM registro_seguridad WHERE tipo = 'autologin' AND id_login = ?"
                " AND fecha >= ? AND (id_autor IS NULL OR id_autor = 0)", [idp, inicio])
            if sin_autor:
                fallos.append(f'el registro de seguridad apunta {sin_autor} cambios de la llave a nombre de nadie')
        else:
            fallos.append(f'encendida la auto-entrada, no se genera el enlace: {st} {r}')
        entorno.consulta_cruda('Db::run("UPDATE ajustes_sistema SET valor = ? WHERE clave = \'autologin_global\'", ['
                               + json.dumps(str(global_antes if global_antes is not None else '1')) + ']);')
    finally:
        # La persona, como estaba.
        entorno.consulta_cruda(
            f'require_once $raiz . "/api/lib/Auth.php";'
            f'Db::run("UPDATE login SET pwd_php = ?, bloqueo = 0, intentos = 0 WHERE id_login = {int(idp)}",'
            f' [password_hash({json.dumps(clave_demo)}, PASSWORD_DEFAULT)]);'
            f'Db::run("DELETE FROM recuperaciones WHERE id_login = {int(idp)}");')

    if fallos:
        for f in fallos:
            print('  FALLA:', f)
        return 1
    print('  BIEN: olvidar, cambiar, salir y la auto-entrada hacen lo que dicen.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
