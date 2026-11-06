#!/usr/bin/env python3
"""
Lo que intentaría alguien con malas intenciones al subir una foto.

Una aplicación donde cincuenta personas suben fotos desde el móvil es una
puerta al servidor si la subida se fía de lo que le dicen. Aquí se ataca
de verdad, por los dos caminos (fichero y base64), y se comprueba qué
queda en el disco y qué sirve el servidor:

  1. un .php de verdad, llamado .php y llamado .jpg → rechazado (415);
  2. una imagen con PHP dentro (GIF válido + código): se admite como
     imagen, pero se guarda con la extensión de imagen, NUNCA .php, y ni
     sirviéndola ni pidiéndola directamente se ejecuta. Las fotos se
     sirven directamente a propósito (un <img> no manda la sesión): su
     dirección es una llave de 64 caracteres, la carpeta no se lista, y
     salen con «nosniff» y «sandbox»;
  3. un SVG con <script> y un HTML → rechazados: servidos desde el mismo
     sitio, ejecutarían código con la sesión de quien los abre;
  4. un nombre con «../../» → no sale de la carpeta del parte;
  5. la descarga lleva «nosniff» y el tipo de verdad, para que el
     navegador no «adivine» que una foto es una página;
  6. subir a un parte que no puedes ver → 403.

   python3 pruebas/subidas.py
"""

import base64
import json
import os
import sys
import urllib.error
import urllib.request
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

API = entorno.API
RAIZ = os.environ.get('HURAKANTICK_RAIZ', API.split('/api/index.php')[0])
MARCA = 'ZZ-SUBIDAS-EJECUTADO'
PHP = f"<?php echo '{MARCA}'; ?>".encode()
# El GIF más pequeño válido, y detrás el PHP: un «polyglot».
GIF = base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
SVG = b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
HTML = b'<!doctype html><html><body><script>alert(1)</script></body></html>'


def llamar(metodo, ruta, token=None, cuerpo=None, crudo=None, tipo=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        pet.add_header('Content-Type', 'application/json')
        datos = json.dumps(cuerpo).encode()
    elif crudo is not None:
        pet.add_header('Content-Type', tipo)
        datos = crudo
    try:
        with urllib.request.urlopen(pet, datos, timeout=60) as r:
            return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


def json_de(b):
    try:
        return json.loads(b or b'{}')
    except Exception:                                  # noqa: BLE001
        return {}


def multipart(nombre, contenido, tipo='application/octet-stream'):
    limite = uuid.uuid4().hex
    cuerpo = (f'--{limite}\r\nContent-Disposition: form-data; name="archivo"; filename="{nombre}"\r\n'
              f'Content-Type: {tipo}\r\n\r\n').encode() + contenido + f'\r\n--{limite}--\r\n'.encode()
    return cuerpo, f'multipart/form-data; boundary={limite}'


def subir_fichero(tok, parte, nombre, contenido, tipo):
    c, t = multipart(nombre, contenido, tipo)
    st, b, _ = llamar('POST', f'tickets/{parte}/adjuntos', tok, crudo=c, tipo=t)
    return st, json_de(b)


def subir_base64(tok, parte, nombre, contenido, mime):
    datos = f'data:{mime};base64,' + base64.b64encode(contenido).decode()
    st, b, _ = llamar('POST', f'tickets/{parte}/adjuntos', tok, cuerpo={'datos': datos, 'nombre': nombre})
    return st, json_de(b)


def entrar(puesto):
    st, b, _ = llamar('POST', 'auth/login', cuerpo={'correo': entorno.usuario(puesto), 'password': entorno.CLAVE})
    return json_de(b)['token']


def main():
    fallos = []
    ad = entrar('admin')
    st, b, _ = llamar('GET', 'tickets&limite=1', ad)
    parte = json_de(b)['tickets'][0]['id_ticket']
    antes = int(entorno.valor('SELECT COALESCE(MAX(id_adjunto), 0) FROM adjuntos') or 0)
    dir_subidas = os.path.join(entorno.DIR, 'api', 'uploads', str(parte))
    try:
        # 1 y 3: lo que tiene que rechazar, por los dos caminos.
        for nombre, contenido, tipo, que in (
                ('x.php', PHP, 'application/x-php', 'un .php'),
                ('foto.jpg', PHP, 'image/jpeg', 'un .php disfrazado de .jpg'),
                ('dibujo.svg', SVG, 'image/svg+xml', 'un SVG con código'),
                ('pagina.html', HTML, 'text/html', 'una página HTML'),
                ('foto.jpg', HTML, 'image/jpeg', 'una página HTML disfrazada de .jpg')):
            for camino, subir in (('fichero', subir_fichero), ('base64', subir_base64)):
                s, r = subir(ad, parte, nombre, contenido, tipo)
                if s in (200, 201):
                    fallos.append(f'AGUJERO: admite {que} ({camino}): {r}')
                elif s != 415:
                    fallos.append(f'{que} ({camino}): contesta {s}, se esperaba 415 «tipo no admitido»')
        print('  rechaza .php, .php disfrazado, SVG con código y HTML, por los dos caminos')

        # 2 y 4: el polyglot, con un nombre que intenta escaparse.
        for camino, subir in (('fichero', subir_fichero), ('base64', subir_base64)):
            s, r = subir(ad, parte, '../../../api/zz-subidas.php', GIF + PHP, 'image/gif')
            if s not in (200, 201):
                fallos.append(f'una imagen GIF válida ({camino}) se rechaza: {s} {r}')
                continue
            a = (r.get('adjuntos') or [r])[0] if isinstance(r.get('adjuntos'), list) else (r.get('adjunto') or r)
            fichero = str(a.get('archivo') or a.get('url') or '')
            if fichero.endswith('.php') or '.php' in fichero:
                fallos.append(f'AGUJERO: la imagen con PHP se guarda como {fichero}')
            if not fichero.endswith(('.gif', '.png', '.jpg', '.webp')):
                fallos.append(f'la imagen se guarda con una extensión rara: {fichero}')
            # Ni una pizca fuera de la carpeta del parte.
            for sitio in (os.path.join(entorno.DIR, 'api', 'zz-subidas.php'),
                          os.path.join(entorno.DIR, 'zz-subidas.php')):
                if os.path.exists(sitio):
                    fallos.append(f'AGUJERO: el nombre «../» ha escrito fuera: {sitio}')
                    os.remove(sitio)
            idf = a.get('id_adjunto')
            if idf:
                # Servida por la API: tipo de verdad, nosniff, y sin ejecutar.
                s2, cuerpo, cab = llamar('GET', f'adjuntos/{idf}', ad)
                cabl = {k.lower(): v for k, v in cab.items()}
                if MARCA.encode() in cuerpo and b'<?php' not in cuerpo:
                    fallos.append('AGUJERO: al servir la imagen se ejecuta el PHP que lleva dentro')
                if 'nosniff' not in cabl.get('x-content-type-options', ''):
                    fallos.append('la descarga de una foto no lleva «nosniff»')
                if not cabl.get('content-type', '').startswith('image/'):
                    fallos.append(f'la descarga dice ser {cabl.get("content-type")}')
            # Pedida directamente, sin pasar por la API.
            #
            # Las fotos se SIRVEN así a propósito: la pantalla las pinta con
            # <img src="uploads/…">, y un <img> no puede mandar la sesión.
            # Lo que las protege es que la dirección es una llave: el nombre
            # es la huella SHA-256 del contenido (64 caracteres que no se
            # pueden adivinar), la carpeta no se lista, el PHP no se ejecuta
            # y todo sale con «nosniff» y una política «sandbox». Eso es lo
            # que se comprueba.
            nombre_disco = fichero.split('/')[-1]
            import re as _re
            if nombre_disco and not _re.fullmatch(r'[0-9a-f]{64}\.[a-z0-9]{2,5}', nombre_disco):
                fallos.append(f'el nombre en disco se puede adivinar: {nombre_disco}')
            if nombre_disco:
                try:
                    with urllib.request.urlopen(f'{RAIZ}/api/uploads/{parte}/{nombre_disco}', timeout=30) as rr:
                        cuerpo = rr.read()
                        cab = {k.lower(): v for k, v in rr.headers.items()}
                        if MARCA.encode() in cuerpo and b'<?php' not in cuerpo:
                            fallos.append('AGUJERO: pedida directamente, la imagen ejecuta el PHP')
                        if 'nosniff' not in cab.get('x-content-type-options', ''):
                            fallos.append('servida directamente, la foto no lleva «nosniff»')
                        if 'sandbox' not in cab.get('content-security-policy', ''):
                            fallos.append('servida directamente, la foto no lleva la política «sandbox»')
                except urllib.error.HTTPError as e:
                    if e.code not in (401, 403, 404):
                        fallos.append(f'pedida directamente, contesta {e.code}')
                # Y la carpeta del parte no se puede listar.
                try:
                    with urllib.request.urlopen(f'{RAIZ}/api/uploads/{parte}/', timeout=30) as rr:
                        if nombre_disco.encode() in rr.read():
                            fallos.append('AGUJERO: la carpeta de las fotos se puede listar: los nombres dejan de ser secretos')
                except urllib.error.HTTPError:
                    pass
        print('  una imagen con PHP dentro se guarda como imagen y no se ejecuta; su dirección es una llave '
              '(64 caracteres, carpeta sin listar, nosniff y sandbox)')

        # 6: subir a un parte que no puedes ver.
        tok = entrar('pilar')
        _, b, _ = llamar('GET', 'tickets&limite=500', tok)
        vis = {t['id_ticket'] for t in json_de(b).get('tickets') or []}
        _, b, _ = llamar('GET', 'tickets&limite=500', ad)
        ajeno = next((t['id_ticket'] for t in json_de(b).get('tickets') or [] if t['id_ticket'] not in vis), None)
        if ajeno:
            for camino, subir in (('fichero', subir_fichero), ('base64', subir_base64)):
                s, _ = subir(tok, ajeno, 'foto.gif', GIF, 'image/gif')
                if s in (200, 201):
                    fallos.append(f'AGUJERO: recepción sube una foto ({camino}) a un parte que no ve')
                elif s not in (403, 404):
                    fallos.append(f'subir a un parte ajeno ({camino}) contesta {s}')
            print('  nadie sube fotos a un parte que no puede ver')
    finally:
        entorno.consulta_cruda(f'Db::run("DELETE FROM adjuntos WHERE id_adjunto > {antes}");')
        # Los ficheros de esta prueba (el GIF con PHP) fuera del disco.
        if os.path.isdir(dir_subidas):
            for f in os.listdir(dir_subidas):
                p = os.path.join(dir_subidas, f)
                try:
                    with open(p, 'rb') as fh:
                        if PHP in fh.read():
                            os.remove(p)
                except OSError:
                    pass

    if fallos:
        for f in fallos:
            print('  FALLA:', f)
        return 1
    print('  BIEN: la subida no se fía del nombre ni del tipo que le dicen.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
