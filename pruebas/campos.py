#!/usr/bin/env python3
"""
Que cada campo de un formulario sirva para algo.

Hasta ahora las pruebas comprobaban que un formulario abre y que al pulsar
«Guardar» sale una petición. Eso deja fuera el fallo más corriente y más
molesto: **un campo que se rellena, se guarda sin quejarse, y no cambia
nada**. O que cambia una cosa distinta. O que al volver a abrir la ficha
aparece como estaba.

No da ningún error. La persona lo rellena, lo guarda, y semanas después
descubre que el teléfono de medio equipo está en blanco.

Esto hace el viaje completo con cada campo, uno a uno:

   1. abre la ficha y apunta cómo está;
   2. cambia UN campo a un valor reconocible;
   3. guarda;
   4. vuelve a abrir la ficha y mira si el valor está;
   5. y lo comprueba también contra el servidor, no sólo contra la
      pantalla —que puede estar enseñando lo que tiene en el móvil—.

   python3 pruebas/campos.py [--ver]
"""

import os
import asyncio
import json
import random
import sys
import urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import entorno                                       # noqa: E402

RAIZ = os.environ.get('HURAKANTICK_RAIZ', 'http://127.0.0.1/hurakantick')
BASE = RAIZ + '/www/index.html'
API = RAIZ + '/api/index.php?_ruta='
CLAVE = entorno.CLAVE
N = random.randint(1000, 9999)


def api(metodo, ruta, cuerpo=None, token=None):
    pet = urllib.request.Request(API + ruta, method=metodo)
    if token:
        pet.add_header('Authorization', 'Bearer ' + token)
    datos = None
    if cuerpo is not None:
        datos = json.dumps(cuerpo).encode()
        pet.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(pet, datos, timeout=30) as r:
            return r.status, r.read(400000).decode('utf-8', 'replace')
    except Exception as e:                       # noqa: BLE001
        return 0, str(e)[:160]


# Campos de la ficha de una persona.
#
# clase: 'texto'    se escribe
#        'opcion'   se pulsa un botón de una lista
#        'lista'    se elige en un desplegable
#        'interruptor' se enciende o se apaga
CAMPOS_PERSONA = [
    ('Nombre',               'texto',       '#u-nombre',  f'Nombre{N}',    'nombre'),
    ('Primer apellido',      'texto',       '#u-ap1',     f'Apellido{N}',  'apellido1'),
    ('Teléfono',             'texto',       '#u-tel',     '600111222',     'telefono'),
    ('Departamento',         'lista',       '#u-dep',     None,            'id_departamento'),
    ('Departamentos extra',  'opcion',      '[data-dep-extra]', None,      'departamentos_extra'),
    ('Permisos sueltos',     'interruptor', '[data-permiso]',   None,      'permisos'),
    ('Mensaje de bienvenida', 'texto',      '#u-bienv',   f'Bienvenida {N}', 'mensaje_bienvenida'),
    ('Aviso al entrar',      'texto',       '#u-proximo', f'Aviso {N}',    'mensaje_proximo'),
]


# Los demás formularios: qué se escribe, qué se espera leer luego.
#
# Cada entrada es: pantalla, cómo se abre, campos, y dónde mirar después.
# Se comprueba contra el SERVIDOR, que es lo único que cuenta: la pantalla
# puede estar enseñando lo que tiene guardado el móvil.
OTROS_FORMULARIOS = [
    {
        'nombre': 'Departamento',
        'ruta': '/departamentos',
        'abrir': '[data-nuevo]',
        'campos': [('#d-nombre', 'texto', 'zzz Depto {n}')],
        # El alias sólo existe al editar: un departamento es común a la
        # cadena y cada hotel le pone su nombre de casa.
        'al_editar': [('#d-alias', 'texto', 'zzz Alias {n}')],
        'fila_editar': '[data-d="{id}"]',
        'lista': ('departamentos', 'departamentos', 'nombre'),
        'clave': 'zzz Depto {n}',
        'borrar': 'departamentos/{id}',
        'id': 'id_departamento',
    },
    {
        'nombre': 'Hotel',
        'ruta': '/hoteles',
        'abrir': '[data-nuevo]',
        'campos': [('#h-nombre', 'texto', 'zzz Hotel {n}'),
                   ('#h-codigo', 'texto', 'ZZ{n}'),
                   ('#h-ciudad', 'texto', 'zzz Ciudad'),
                   ('#h-dir', 'texto', 'zzz Calle 1')],
        'lista': ('hoteles', 'hoteles', 'nombre'),
        'clave': 'zzz Hotel {n}',
        'borrar': 'hoteles/{id}',
        'id': 'id_hotel',
    },
    {
        'nombre': 'Revisión',
        'ruta': '/mantenimiento',
        'abrir': '[data-nuevo]',
        'fila_editar': '[data-m="{id}"]',
        'paso_editar': '[data-editar]',
        'al_editar': [('#m-desc', 'texto', 'zzz corregido {n}')],
        'campos': [('#m-nombre', 'texto', 'zzz Revisión {n}'),
                   ('#m-desc', 'texto', 'zzz lo que hay que mirar'),
                   ('#m-intervalo', 'numero', '3'),
                   ('#m-aviso', 'numero', '5'),
                   ('#m-repeticiones', 'numero', '4')],
        'lista': ('mantenimiento', 'mantenimientos', 'nombre'),
        'clave': 'zzz Revisión {n}',
        'borrar': 'mantenimiento/{id}',
        'id': 'id_mantenimiento',
        'comprobar': {'descripcion': 'zzz lo que hay que mirar',
                      'intervalo': 3, 'aviso_dias': 5, 'repeticiones': 4},
    },
    {
        'nombre': 'Instrucción',
        'ruta': '/instrucciones',
        'abrir': '[data-crear="texto"]',
        'fila_editar': '[data-i="{id}"]',
        'campos': [('#n-titulo', 'texto', 'zzz Instrucción {n}'),
                   ('#n-resumen', 'texto', 'zzz en una línea'),
                   ('#n-contenido', 'texto', '1. Primer paso'),
                   ('#n-aviso', 'texto', 'zzz no hacer esto'),
                   ('#n-etiquetas', 'texto', 'zzz, prueba')],
        'lista': ('instrucciones', 'instrucciones', 'titulo'),
        'clave': 'zzz Instrucción {n}',
        'borrar': 'instrucciones/{id}',
        'id': 'id_instruccion',
        'comprobar': {'resumen': 'zzz en una línea', 'contenido': '1. Primer paso',
                      'aviso': 'zzz no hacer esto'},
    },
    {
        'nombre': 'Frase del día',
        'ruta': '/frases',
        'abrir': '[data-nueva]',
        'campos': [('#f-texto', 'texto', 'zzz Frase {n}'),
                   ('#f-autor', 'texto', 'zzz Autor')],
        'lista': ('frases', 'frases', 'texto'),
        'clave': 'zzz Frase {n}',
        'borrar': 'frases/{id}',
        'id': 'id_frase',
        'comprobar': {'autor': 'zzz Autor'},
    },
]


async def probar_otros(pag, ficha, fallos):
    """Rellena cada formulario entero y comprueba lo que llega al servidor."""
    hechos = 0
    for f in OTROS_FORMULARIOS:
        n = random.randint(1000, 9999)
        await pag.goto(BASE + '#' + f['ruta'])
        await pag.wait_for_timeout(2600)
        if await pag.evaluate("(s)=>{const b=document.querySelector(s);"
                              " if(!b) return 0; b.click(); return 1;}", f['abrir']) == 0:
            fallos.append(f'{f["nombre"]}: no aparece con qué crear uno ({f["abrir"]}).')
            continue
        await pag.wait_for_timeout(2200)

        puestos = []
        for sel, clase, valor in f['campos']:
            v = valor.format(n=n)
            ok = await pag.evaluate(
                "([s,v])=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                " const e=h && h.querySelector(s); if(!e) return 0;"
                " e.value=v; e.dispatchEvent(new Event('input',{bubbles:true}));"
                " e.dispatchEvent(new Event('change',{bubbles:true})); return 1;}", [sel, v])
            if not ok:
                fallos.append(f'{f["nombre"]}: falta el campo {sel}.')
            else:
                puestos.append((sel, v))
        if not puestos:
            continue

        await pag.evaluate("()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                           " const b=h && h.querySelector('[data-si]'); if(b) b.click();}")
        await pag.wait_for_timeout(3200)
        hechos += 1

        ruta, clave_lista, campo_clave = f['lista']
        _, cuerpo = api('GET', ruta, None, ficha)
        try:
            filas = json.loads(cuerpo).get(clave_lista, [])
        except Exception:                        # noqa: BLE001
            filas = []
        buscado = f['clave'].format(n=n)
        fila = next((x for x in filas
                     if str(x.get(campo_clave) or x.get(campo_clave + '_ticket') or '')
                     == buscado), None)
        if fila is None:
            fallos.append(f'{f["nombre"]}: se rellena y se guarda, pero no aparece '
                          f'en la lista del servidor («{buscado}»).')
            continue

        for campo, esperado in (f.get('comprobar') or {}).items():
            tiene = fila.get(campo)
            if str(tiene if tiene is not None else '') != str(esperado):
                fallos.append(f'{f["nombre"]} · {campo}: se escribió «{esperado}» y el '
                              f'servidor guardó «{tiene}».')

        idf = fila.get(f['id'])

        # ---- Y ahora EDITARLO -------------------------------------------
        # Crear y editar son dos formularios distintos aunque se parezcan, y
        # hay campos que sólo salen en uno —el alias de un departamento, por
        # ejemplo—. Probar sólo el alta deja la mitad sin mirar.
        if idf and f.get('al_editar'):
            await pag.goto(BASE + '#' + f['ruta'])
            await pag.wait_for_timeout(2600)
            abierto = await pag.evaluate(
                "(s)=>{const b=document.querySelector(s); if(!b) return 0;"
                " b.click(); return 1;}", f['fila_editar'].format(id=idf))
            if not abierto:
                fallos.append(f'{f["nombre"]}: no se puede abrir para editar.')
            else:
                await pag.wait_for_timeout(2200)

                # Algunas fichas se abren primero en modo lectura, con un
                # botón «Editar» que es el que saca el formulario. Pulsarlo
                # forma parte de editar: sin esto la prueba se quedaba en la
                # ficha de sólo lectura, no encontraba los campos, y lo
                # cantaba como «falta el campo», que no era verdad.
                if f.get('paso_editar'):
                    await pag.evaluate(
                        "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                        " const b=h && h.querySelector(s); if(b) b.click();}",
                        f['paso_editar'])
                    await pag.wait_for_timeout(2000)

                hechos += 1
                for sel, _clase, valor in f['al_editar']:
                    v = valor.format(n=n)
                    ok = await pag.evaluate(
                        "([s,v])=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                        " const e=h && h.querySelector(s); if(!e) return 0;"
                        " e.value=v; e.dispatchEvent(new Event('input',{bubbles:true}));"
                        " return 1;}", [sel, v])
                    if not ok:
                        fallos.append(f'{f["nombre"]} (editar): falta el campo {sel}.')
                await pag.evaluate(
                    "()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const b=h && h.querySelector('[data-si]'); if(b) b.click();}")
                await pag.wait_for_timeout(3000)

                _, cuerpo = api('GET', ruta, None, ficha)
                try:
                    filas = json.loads(cuerpo).get(clave_lista, [])
                except Exception:                # noqa: BLE001
                    filas = []
                fila2 = next((x for x in filas if x.get(f['id']) == idf), None)
                for sel, _clase, valor in f['al_editar']:
                    campo = sel.lstrip('#').split('-', 1)[-1]
                    campo = {'desc': 'descripcion'}.get(campo, campo)
                    if fila2 is None:
                        fallos.append(f'{f["nombre"]} (editar): desaparece de la lista '
                                      'después de guardar.')
                        break
                    tiene = str(fila2.get(campo) or '')
                    if tiene != valor.format(n=n):
                        fallos.append(
                            f'{f["nombre"]} (editar) · {campo}: se escribió '
                            f'«{valor.format(n=n)}» y el servidor guardó «{tiene}».')

        if idf:
            api('DELETE', f['borrar'].format(id=idf), None, ficha)
    return hechos


async def principal(ver: bool) -> int:
    from playwright.async_api import async_playwright

    fallos, desechable = [], None
    revisados = 0

    _, c = api('POST', 'auth/login', {'correo': entorno.usuario('admin'), 'password': CLAVE})
    ficha = json.loads(c)['token']

    async with async_playwright() as p:
        nav = await p.chromium.launch(headless=not ver)
        ctx = await nav.new_context(viewport={'width': 390, 'height': 844},
                                    locale='es-ES', is_mobile=True, has_touch=True)
        pag = await ctx.new_page()
        pag.set_default_timeout(25000)
        errores_js = []
        pag.on('pageerror', lambda e: errores_js.append(str(e)[:150]))

        await pag.goto(BASE)
        await pag.wait_for_timeout(2000)
        await pag.fill('#correo', entorno.usuario('admin'))
        await pag.fill('#password', CLAVE)
        await pag.click('#b-entrar')
        await pag.wait_for_timeout(9000)

        # Una persona de usar y tirar: así se le puede cambiar todo sin
        # estropearle la ficha a nadie.
        _, c = api('POST', 'usuarios', {
            'usuario': f'zzz_campos{N}', 'correo': f'zzz_campos{N}@prueba.local',
            'password': 'Prueba2026zz', 'rol': 'tecnico'}, ficha)
        try:
            idu = json.loads(c)['id_login']
        except Exception:                        # noqa: BLE001
            print('No se ha podido crear la persona de prueba:', c[:160])
            await nav.close()
            return 1

        async def abrir_ficha(desde_cero=False):
            # Con sólo cambiar la almohadilla el navegador NO recarga: la
            # pantalla sigue con los datos que se bajó al entrar. Para lo
            # que ha cambiado por fuera hay que recargar de verdad, que es
            # además lo que hace quien abre la aplicación más tarde.
            await pag.goto(BASE + '#/equipo')
            if desde_cero:
                await pag.reload()
            await pag.wait_for_timeout(2600)
            abierto = await pag.evaluate(
                "(id)=>{const b=document.querySelector('[data-u=\"'+id+'\"]');"
                " if(!b) return 0; b.click(); return 1;}", str(idu))
            await pag.wait_for_timeout(2200)
            return bool(abierto) and await pag.locator('.hoja').count() > 0

        async def guardar():
            await pag.evaluate("()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                               " const b=h && h.querySelector('[data-si]'); if(b) b.click();}")
            await pag.wait_for_timeout(3000)

        for etiqueta, clase, sel, valor, campo_api in CAMPOS_PERSONA:
            revisados += 1
            if not await abrir_ficha():
                fallos.append(f'{etiqueta}: no se puede abrir la ficha de la persona.')
                continue

            existe = await pag.evaluate(
                "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                " return h ? h.querySelectorAll(s).length : 0;}", sel)
            if not existe:
                fallos.append(f'{etiqueta}: el campo no está en la ficha ({sel}).')
                continue

            esperado = None

            if clase == 'texto':
                await pag.evaluate(
                    "([s,v])=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const e=h.querySelector(s); e.value=v;"
                    " e.dispatchEvent(new Event('input',{bubbles:true}));}", [sel, valor])
                esperado = valor

            elif clase == 'lista':
                esperado = await pag.evaluate(
                    "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const e=h.querySelector(s);"
                    " const o=[...e.options].find(x=>x.value && x.value!==e.value);"
                    " if(!o) return null; e.value=o.value;"
                    " e.dispatchEvent(new Event('change',{bubbles:true}));"
                    " return o.value;}", sel)
                if esperado is None:
                    fallos.append(f'{etiqueta}: el desplegable no tiene entre qué elegir.')
                    continue

            elif clase == 'opcion':
                esperado = await pag.evaluate(
                    "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const b=[...h.querySelectorAll(s)]"
                    "   .find(x=>!x.className.includes('opcion--activa'));"
                    " if(!b) return null; b.click();"
                    " return b.dataset.depExtra || b.innerText.trim();}", sel)
                if esperado is None:
                    fallos.append(f'{etiqueta}: no hay ninguna opción que marcar. '
                                  'La sección sale vacía.')
                    continue

            elif clase == 'interruptor':
                esperado = await pag.evaluate(
                    "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const b=[...h.querySelectorAll(s)]"
                    "   .find(x=>!x.disabled && x.getAttribute('aria-checked')!=='true');"
                    " if(!b) return null; b.click();"
                    " return b.dataset.permiso || true;}", sel)
                if esperado is None:
                    fallos.append(f'{etiqueta}: no hay ningún interruptor que se pueda '
                                  'encender.')
                    continue

            await guardar()

            # 1) ¿Lo tiene el SERVIDOR?
            _, cuerpo = api('GET', 'usuarios', None, ficha)
            try:
                gente = json.loads(cuerpo).get('usuarios', [])
                yo = next((x for x in gente if x.get('id_login') == idu), {})
            except Exception:                    # noqa: BLE001
                yo = {}

            guardado = yo.get(campo_api)
            ok = False
            if campo_api == 'departamentos_extra':
                ok = int(esperado) in [int(x) for x in (guardado or [])]
            elif campo_api == 'permisos':
                ok = esperado in (guardado or [])
            elif campo_api == 'id_departamento':
                ok = str(guardado) == str(esperado)
            else:
                ok = str(guardado or '') == str(esperado)

            if not ok:
                fallos.append(f'{etiqueta}: se rellena y se guarda sin quejarse, pero el '
                              f'servidor sigue con «{guardado}» en vez de «{esperado}».')
                continue

            # 2) ¿Lo enseña la ficha al volver a abrirla?
            if not await abrir_ficha():
                continue
            if clase == 'texto':
                visto = await pag.evaluate(
                    "(s)=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const e=h.querySelector(s); return e ? e.value : null;}", sel)
                if str(visto or '') != str(esperado):
                    fallos.append(f'{etiqueta}: se guardó bien, pero al volver a abrir la '
                                  f'ficha aparece «{visto}» en vez de «{esperado}».')
            elif clase == 'opcion':
                visto = await pag.evaluate(
                    "([s,v])=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const b=[...h.querySelectorAll(s)].find(x=>(x.dataset.depExtra||'')===String(v));"
                    " return b ? b.className.includes('opcion--activa') : null;}",
                    [sel, esperado])
                if visto is not True:
                    fallos.append(f'{etiqueta}: se guardó bien, pero al volver a abrir no '
                                  'sale marcado.')

            await pag.evaluate("()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                               " const b=h && h.querySelector('[data-no]'); if(b) b.click();}")
            await pag.wait_for_timeout(700)

        # ---- El caso que de verdad muerde ------------------------------
        # Un departamento que el hotel retira, con gente dentro.
        #
        # Antes, a esa gente la ficha le enseñaba «Sin departamento»
        # —porque esa opción ya no estaba en la lista— y al guardar se le
        # borraba el suyo de verdad. Y nadie tocaba ese campo: bastaba con
        # abrir la ficha a cambiar un teléfono. Un técnico sin departamento
        # deja de ver los partes de su equipo, y eso no avisa de nada.
        revisados += 1

        # OJO: el departamento que se retira es uno DESECHABLE, creado
        # aquí mismo. La primera versión retiraba el de verdad de la
        # persona de prueba —uno de los siete del hotel— y no lo devolvía.
        # Siete pasadas de esta prueba dejaron el hotel sin ningún
        # departamento activo, y entonces la ficha no enseñaba ninguno
        # para elegir. Pasé un buen rato buscando en el código un fallo
        # que había metido la propia prueba en los datos.
        _, cuerpo = api('POST', 'departamentos',
                        {'nombre': f'zzz desechable {N}'}, ficha)
        try:
            d = json.loads(cuerpo)
            depto = d.get('id_departamento') or (d.get('departamento') or {}).get('id_departamento')
        except Exception:                        # noqa: BLE001
            depto = None

        if depto:
            api('PATCH', f'usuarios/{idu}', {'id_departamento': depto}, ficha)
            api('DELETE', f'departamentos/{depto}', None, ficha)

            if await abrir_ficha(desde_cero=True):
                elegido = await pag.evaluate(
                    "()=>{const h=[...document.querySelectorAll('.hoja')].pop();"
                    " const s=h.querySelector('#u-dep');"
                    " return s ? s.value : null;}")
                if str(elegido or '') != str(depto):
                    fallos.append(
                        'Departamento retirado: la ficha de quien estaba en él lo '
                        f'enseña como «{elegido or "sin departamento"}». Al guardar '
                        'se le borra el suyo sin avisar.')
                await guardar()

                _, cuerpo = api('GET', 'usuarios', None, ficha)
                try:
                    gente = json.loads(cuerpo).get('usuarios', [])
                    yo = next((x for x in gente if x.get('id_login') == idu), {})
                    ahora = yo.get('id_departamento')
                except Exception:                # noqa: BLE001
                    ahora = None
                if str(ahora or '') != str(depto):
                    fallos.append(
                        'Departamento retirado: abrir la ficha y guardar le ha '
                        f'borrado el departamento (tenía {depto}, ahora {ahora}).')

            # Del todo no se puede todavía: mientras la persona de prueba
            # siga dentro, el servidor lo desactiva en vez de borrarlo —y
            # hace bien, porque borrarlo dejaría partes sin dueño—. Se
            # remata abajo, cuando ya no queda nadie dentro.
            desechable = depto

        revisados += await probar_otros(pag, ficha, fallos)

        if errores_js:
            fallos.append(f'La pantalla da errores: {errores_js[0]}')

        await nav.close()

    api('DELETE', f'usuarios/{idu}&definitivo=1', None, ficha)
    if desechable:
        api('DELETE', f'departamentos/{desechable}', None, ficha)

    print(f'Campos probados de punta a punta: {revisados}')
    print(f'  que no hacen lo que dicen:      {len(fallos)}')
    if fallos:
        print('\nCAMPOS QUE NO SIRVEN:')
        for f in fallos:
            print('  ·', f)
        return 1
    print('\nCada campo se rellena, se guarda y vuelve a salir.')
    return 0


if __name__ == '__main__':
    sys.exit(asyncio.run(principal('--ver' in sys.argv)))
