#!/usr/bin/env python3
"""
¿Cada ruta tiene cargadas las clases que usa?

Esto nació de un fallo real: «restablecer hotel» llamaba a Tickets para
limpiar lo que cuelga de cada parte, pero esa clase no se carga sola —la
pide su propia ruta—, así que fallaba con «Class "Tickets" not found» en
TODOS los partes. El error se quedaba en el registro y la pantalla decía
tan tranquila «0 partes a la papelera».

Es un fallo que no se ve hasta que alguien usa justo ese camino, que
suele ser el menos probado. Esto lo caza antes.

    python3 pruebas/clases.py
"""
import json
import re
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
RUTAS = RAIZ / 'api/routes'
LIBS_DIR = RAIZ / 'api/lib'
HERRAMIENTAS = RAIZ / 'tools'
INDEX = RAIZ / 'api/index.php'
LIB = RAIZ / 'api/lib'

# Clases de PHP y cosas que no son clases nuestras.
AJENAS = {
    'PDO', 'PDOException', 'Throwable', 'Exception', 'DateTime', 'DateTimeZone',
    'DateInterval', 'ZipArchive', 'ArrayObject', 'Closure', 'Generator',
    'RuntimeException', 'InvalidArgumentException', 'JsonException', 'Error',
    'TypeError', 'ValueError', 'LogicException', 'SplFileObject',
}


def clases_cargadas_globalmente() -> set[str]:
    """Las que index.php trae para todo el mundo."""
    texto = INDEX.read_text(encoding='utf-8')
    return {m.group(1) for m in re.finditer(r"require(?:_once)?\s+__DIR__\s*\.\s*'/lib/(\w+)\.php'", texto)}


def armazon_del_sw() -> list[str]:
    """Lo que el trabajador de servicio promete guardar para sin cobertura."""
    txt = (RAIZ / 'www' / 'sw.js').read_text(encoding='utf-8')
    trozo = re.search(r'ARMAZON\s*=\s*\[(.*?)\];', txt, re.S)
    return re.findall(r"'\./([^']+)'", trozo.group(1)) if trozo else []


def revisar_sw() -> list[str]:
    """Que la lista del sw.js y lo que hay en disco digan lo mismo.

    Las dos equivocaciones duelen, cada una a su manera:

      · un fichero en la lista que no existe hace que `cache.addAll()`
        falle ENTERO —rechaza si una sola petición no va bien—, y el
        catch sólo escribe un aviso en la consola: la aplicación se
        queda sin nada guardado y nadie se entera hasta que alguien se
        mete en un sótano;

      · un módulo que existe y no está en la lista no se guarda, así
        que esa pantalla no abre sin cobertura. Aquí faltaban tres:
        views/mensajes.js y views/personal.js, que app.js importa
        siempre, y editor-bloques.js, que se pide sólo al abrir el
        editor de instrucciones.
    """
    lista = armazon_del_sw()
    if not lista:
        return ['No se encuentra la lista ARMAZON en www/sw.js: '
                'esta comprobación se ha quedado sin servir.']

    malos = []
    for rel in lista:
        if not (RAIZ / 'www' / rel).is_file():
            malos.append(f'sw.js promete «{rel}» y no existe. Con uno solo '
                         'así, addAll() falla entero y la aplicación se '
                         'queda SIN nada guardado para sin cobertura.')

    enlista = set(lista)
    for f in sorted((RAIZ / 'www' / 'assets' / 'js').rglob('*.js')):
        if 'vendor' in f.parts:
            continue
        rel = str(f.relative_to(RAIZ / 'www'))
        if rel not in enlista:
            malos.append(f'{rel} no está en la lista de sw.js: esa pantalla '
                         'no abrirá sin cobertura.')
    return malos


def revisar_version() -> list[str]:
    """Que todos los sitios que dicen la versión digan la misma.

    Llegó a haber CINCO números a la vez: «3.0.1» en la etiqueta de
    index.html y en version.json, «7.89» en el trabajador de servicio,
    «2.0.0» en package.json y «2.0.0» en lo que contestaba la API. Cada
    pantalla enseñaba uno, y diagnostico.html —que compara la etiqueta con
    version.json para saber si quedaron ficheros sin subir— comparaba dos
    números igual de viejos y decía que todo cuadraba.

    La API ya no lleva número propio: lo lee de version.json.
    """
    www = RAIZ / 'www'
    vistos = {}
    m = re.search(r'<meta name="hurakan-tick-version" content="([^"]+)"',
                  (www / 'index.html').read_text(encoding='utf-8'))
    vistos['la etiqueta de index.html'] = m.group(1) if m else None
    try:
        vistos['www/version.json'] = json.loads(
            (www / 'version.json').read_text(encoding='utf-8')).get('version')
    except Exception:                                 # noqa: BLE001
        vistos['www/version.json'] = None
    m = re.search(r"const VERSION = '[a-z-]*v([0-9.]+)'",
                  (www / 'sw.js').read_text(encoding='utf-8'))
    vistos['el trabajador de servicio (sw.js)'] = m.group(1) if m else None
    try:
        vistos['package.json'] = json.loads(
            (RAIZ / 'package.json').read_text(encoding='utf-8')).get('version')
    except Exception:                                 # noqa: BLE001
        vistos['package.json'] = None

    malos = [f'No encuentro la versión en {k}.' for k, v in vistos.items() if not v]
    distintos = {v for v in vistos.values() if v}
    if len(distintos) > 1:
        malos.append('La versión no es la misma en todas partes: '
                     + '; '.join(f'{k} dice {v}' for k, v in vistos.items())
                     + '. Cada pantalla enseñará una distinta y el diagnóstico '
                       'no sabrá si falta algún fichero por subir.')

    # Y nadie lee ya la etiqueta por su nombre viejo: con el nombre viejo
    # la versión sale como «?» en la entrada y en Mi cuenta.
    for f in list((www / 'assets' / 'js').rglob('*.js')) + list(www.glob('*.html')):
        if 'vendor' in f.parts:
            continue
        if 'hurakantick-version' in f.read_text(encoding='utf-8'):
            malos.append(f'{f.relative_to(RAIZ)} busca la etiqueta «hurakantick-version», '
                         'que ya no existe: enseñará la versión como «?».')
    return malos


def revisar_marca() -> list[str]:
    """Que no quede «SSTT» en nada que vea la gente.

    Hasta la 7.89, cada versión salía de aquí con «Partes de trabajo ·
    SSTT» en los títulos, y el nombre se cambiaba después a mano con unos
    scripts de reemplazo. Los scripts se quedaban cortos —no tocaban el
    aviso de prueba, ni los mensajes de Teams, ni la guía— y uno de ellos
    rompía, sin que se notara, la versión de la pantalla de entrada.

    Se mira como palabra suelta y fuera de comentarios: «hurakantick_token»,
    «HURAKANTICK_DB_HOST», la carpeta /hurakantick o la caché «hurakantick-v7…» son nombres
    internos y se quedan como están a propósito (cambiarlos cerraría la
    sesión de todo el mundo o rompería las direcciones guardadas).
    """
    pal = re.compile(r'(?<![A-Za-z0-9_\-/.])SSTT(?![A-Za-z0-9_\-])')
    malos = []
    for base in ('www', 'api', 'tools', 'plugins'):
        for f in sorted((RAIZ / base).rglob('*')):
            if not f.is_file() or 'vendor' in f.parts:
                continue
            if f.suffix not in ('.js', '.php', '.html', '.webmanifest', '.json', '.css'):
                continue
            for n, linea in enumerate(f.read_text(encoding='utf-8',
                                                  errors='replace').split('\n'), 1):
                t = linea.strip()
                if t.startswith(('//', '/*', '*', '#', '<!--', '--')):
                    continue
                if pal.search(linea):
                    malos.append(f'{f.relative_to(RAIZ)}:{n} dice «SSTT» donde lo '
                                 f've la gente: {t[:70]}')

    # Y el nombre de antes de la marca, cuando se usa COMO NOMBRE: título de
    # un aviso, etiqueta del icono… «Partes» como sección de la aplicación
    # es otra cosa y está bien. El título por defecto de las notificaciones
    # seguía siendo «Partes de trabajo» —el que sale en la pantalla de
    # bloqueo— y ningún guardián lo veía.
    como_nombre = re.compile(r"""(titulo|title|aria-label|appName|"name")\s*[:=]\s*['"]Partes( de trabajo)?['"]""")
    for base in ('www', 'api', 'android'):
        for f in sorted((RAIZ / base).rglob('*')):
            if not f.is_file() or 'vendor' in f.parts:
                continue
            if f.suffix not in ('.js', '.php', '.html', '.svg', '.json', '.webmanifest', '.xml'):
                continue
            for n, linea in enumerate(f.read_text(encoding='utf-8', errors='replace').split('\n'), 1):
                if como_nombre.search(linea):
                    malos.append(f'{f.relative_to(RAIZ)}:{n} usa «Partes de trabajo» como '
                                 f'nombre del producto: {linea.strip()[:70]}')
    return malos


# Lo que puede seguir llamándose «sstt», y por qué.
SSTT_PERMITIDO = (
    'david.sstt',              # el correo de una persona
    'sstt-antiguo',            # la carpeta del sistema viejo, de donde se importan fotos
    'sstt.local',              # los correos de la demo de antes, para retirarlos
    'db/sstt.sqlite', 'db/$de.sqlite', "'sstt', 'hurakantick'",
    "'sstt-papelera', 'hurakantick-papelera'",   # renombrar la base al actualizar
    'sstt/ms/uploads',         # dónde estaban las fotos en el sistema viejo
    '$sstt',                   # variable: «Servicios Técnicos», el departamento
    'sstt-*.zip', 'sstt-7.',   # los paquetes de antes, para probar a actualizar desde ellos
    'sstt_pruebas', 'sstt_carga', 'sstt_mysql',  # la MariaDB de la máquina de pruebas
    '/etc/ssl/sstt',           # el certificado de la máquina de pruebas
    "'sstt2026'", '«sstt2026»', '«sstt.sqlite»', '«db/sstt.sqlite»',  # citas al explicar fallos  # la contraseña de la demo de antes, para entrar en una base vieja
    "os.environ.get('HURAKANTICK_MY_USER', 'sstt')",  # el usuario de la MariaDB de pruebas
    'sstt-candado',            # se reconoce el candado viejo para renovarlo
    # La base del sistema ANTIGUO del hotel se llama así en su servidor: es
    # un hecho, no una marca. Sólo en la migración; como valor por defecto
    # de una instalación nueva, saltaría.
    '-p sstt_app >', '-p sstt_app <', '(sstt_app, MariaDB', '`sstt_app` es el nombre',
)


def revisar_nombres_internos() -> list[str]:
    """Que el nombre viejo no vuelva a colarse por dentro.

    Hasta la 7.89 lo interno se llamaba sstt: la carpeta, la base, las
    claves del navegador, las variables de entorno, el identificador de
    Android. Se cambió todo en la 7.90, antes de que nadie la usara,
    porque después cada uno de esos nombres queda fijo. Lo que queda está
    en SSTT_PERMITIDO, cada cosa con su motivo; lo demás, falla.
    """
    pal = re.compile(r'sstt', re.I)
    malos = []
    for base in ('www', 'api', 'tools', 'plugins', 'android', 'pruebas', 'docs'):
        d = RAIZ / base
        if not d.exists():
            continue
        for f in sorted(d.rglob('*')):
            if not f.is_file() or 'vendor' in f.parts or '__pycache__' in f.parts:
                continue
            if f.suffix not in ('.js', '.php', '.html', '.webmanifest', '.json', '.css',
                                '.py', '.md', '.gradle', '.xml', '.java', '.sh'):
                continue
            if f.name == 'clases.py':
                continue
            # La configuración de cada casa no es código: no viene en el
            # paquete, y quien tenga su base llamada «sstt_app» desde
            # siempre tiene todo el derecho a seguir llamándola así.
            if f.name == 'config.local.php':
                continue
            for n, linea in enumerate(f.read_text(encoding='utf-8',
                                                  errors='replace').split('\n'), 1):
                if not pal.search(linea):
                    continue
                limpia = linea
                for p in SSTT_PERMITIDO:
                    limpia = limpia.replace(p, '')
                # El nombre del producto en prosa —«Hasta la 7.89 se llamó
                # SSTT»— lo vigila revisar_marca() fuera de comentarios.
                if re.search(r'sstt', limpia) or re.search(r'SSTT_|sstt-|_sstt', limpia):
                    malos.append(f'{f.relative_to(RAIZ)}:{n} usa el nombre viejo por '
                                 f'dentro: {linea.strip()[:80]}')
    return malos


def revisar_bases_sueltas() -> list[str]:
    """Que en el código no viaje ninguna base de datos.

    Había un «db/sstt.sqlite» de 4 KB, vacío, olvidado en la carpeta de
    trabajo desde octubre. Al actualizar encima de una instalación —que
    es copiar los ficheros nuevos sobre los viejos— esa base vacía pisó
    la de verdad: 28 partes y siete personas desaparecieron, y el
    instalador, al no encontrar a nadie, creó un administrador nuevo. Lo
    vio pruebas/actualizar.py.

    El paquete se monta sin .sqlite, pero el código no debe llevarlos:
    un fichero de base en el código es una base vacía esperando a pisar
    otra. Sólo se mira cuando esto es una carpeta de código (sin
    api/config.local.php): una instalación sí tiene su base, claro.
    """
    if (RAIZ / 'api' / 'config.local.php').exists():
        return []
    malos = []
    for f in RAIZ.rglob('*'):
        if 'paquetes' in f.parts or '.git' in f.parts:
            continue
        if f.is_file() and re.search(r'\.sqlite(-wal|-shm)?$', f.name):
            malos.append(f'{f.relative_to(RAIZ)} es una base de datos dentro del '
                         'código: al actualizar encima de una instalación, pisaría '
                         'la de verdad.')
    return malos


def revisar_nombre_android() -> list[str]:
    """El nombre que sale bajo el icono del móvil.

    En la 7.90 se cambió todo a HuraKan Tick y se dio por hecho también
    aquí: no lo estaba. La aplicación de Android se seguía llamando
    «Partes» en los tres sitios de donde lo saca —los dos
    capacitor.config.json y strings.xml—, y nada lo comprobaba.
    """
    import json as _j
    malos = []
    for f in ('capacitor.config.json', 'android/app/src/main/assets/capacitor.config.json'):
        p = RAIZ / f
        if p.exists():
            nombre = _j.loads(p.read_text(encoding='utf-8')).get('appName')
            if nombre != 'HuraKan Tick':
                malos.append(f'{f}: la aplicación de Android se llama «{nombre}».')
    p = RAIZ / 'android/app/src/main/res/values/strings.xml'
    if p.exists():
        t = p.read_text(encoding='utf-8')
        for clave in ('app_name', 'title_activity_main'):
            m = re.search(rf'<string name="{clave}">([^<]*)</string>', t)
            if m and m.group(1) != 'HuraKan Tick':
                malos.append(f'strings.xml: {clave} dice «{m.group(1)}» (es lo que sale '
                             'bajo el icono del móvil).')
    return malos


def revisar_firma_android() -> list[str]:
    """Que el APK de release salga firmado y con la versión de verdad.

    Hasta la 7.90, build.gradle no leía android/key.properties aunque el
    manual y compilar-apk.sh lo pedían: «--release» sacaba un APK sin
    firmar que el móvil no instala. Y se declaraba versión «1.0» (código
    1) para siempre, así que el APK nuevo no podía instalarse encima del
    viejo. La lógica está en firma.gradle (probada con Gradle); esto
    vigila que build.gradle la siga usando.
    """
    malos = []
    p = RAIZ / 'android/app/build.gradle'
    if not p.exists():
        return malos
    t = p.read_text(encoding='utf-8')
    if "apply from: 'firma.gradle'" not in t or not (RAIZ / 'android/app/firma.gradle').exists():
        malos.append('android/app/build.gradle no carga firma.gradle: el APK de release saldría sin firmar.')
    if not re.search(r'versionCode\s+hurakanVersionCode', t) or not re.search(r'versionName\s+hurakanVersionName', t):
        malos.append('android/app/build.gradle no toma la versión de www/version.json '
                     '(un APK nuevo no se podría instalar encima del viejo).')
    if not re.search(r'signingConfig\s+signingConfigs\.release', t):
        malos.append('android/app/build.gradle no firma el APK de release.')
    e = RAIZ / 'empaquetar.sh'
    if e.exists() and 'key.properties' not in e.read_text(encoding='utf-8'):
        malos.append('empaquetar.sh no impide meter android/key.properties (la firma) en el paquete.')
    return malos


def revisar_pruebas_apuntan() -> list[str]:
    """Que cada prueba pruebe la instalación que se le dice.

    formularios.py llevaba la dirección del navegador escrita a pelo, la
    de SQLite, y sincertificado.py leía una variable que todas.py no
    ponía. En la tanda de MySQL, las dos probaban SQLite sin decirlo: la
    tanda decía «31 pasan en MySQL» con dos de ellas mirando otra base.
    Una dirección de instalación sólo puede aparecer como valor por
    defecto de una variable de entorno.
    """
    malos = []
    for f in sorted((RAIZ / 'pruebas').glob('*.py')):
        if f.name in ('clases.py', 'todas.py', 'entorno.py'):
            continue
        for n, linea in enumerate(f.read_text(encoding='utf-8').splitlines(), 1):
            if re.match(r"^\s*[A-Z_]+\s*=\s*['\"]https?://127\.0\.0\.1/", linea):
                malos.append(f'pruebas/{f.name}:{n}: dirección fija ({linea.strip()[:60]}); '
                             'tiene que venir de os.environ.get(...)')
    return malos


def revisar_variables() -> list[str]:
    """Variables que se leen sin asignarse nunca en su ámbito.

    «$quien» en auth.php (restablecer con código: 500 siempre) y «$u» en
    admin.php (las llaves de auto-entrada a nombre de nadie) se escaparon
    a todas las pruebas: PHP sólo se queja al ejecutar esa línea. Esto lo
    mira leyendo el código (pruebas/variables.php).
    """
    import subprocess as _sp
    r = _sp.run(['php', str(RAIZ / 'pruebas' / 'variables.php')], capture_output=True, text=True, timeout=120)
    if r.returncode == 0:
        return []
    return [l.strip().lstrip('· ') for l in r.stdout.splitlines() if l.strip().startswith('·')] \
        or [f'pruebas/variables.php no ha podido mirar: {(r.stderr or r.stdout)[:200]}']


def revisar_csv() -> list[str]:
    """Las exportaciones CSV no dejan pasar fórmulas (pruebas/js/csv.mjs).

    Un parte titulado =HYPERLINK(…) salía como enlace vivo en el Excel de
    quien exportaba la lista. Lo escribe cualquiera que dé un parte.
    """
    import shutil as _sh
    import subprocess as _sp
    if not _sh.which('node'):
        return []
    r = _sp.run(['node', str(RAIZ / 'pruebas/js/csv.mjs'), str(RAIZ / 'www/assets/js/ui.js')],
                capture_output=True, text=True, timeout=60)
    return [] if r.returncode == 0 else [l for l in (r.stdout or r.stderr).splitlines() if l.strip()][:6]


def main() -> int:
    globales = clases_cargadas_globalmente()
    disponibles = {f.stem for f in LIB.glob('*.php')}
    problemas = []

    for ruta in sorted(RUTAS.glob('*.php')):
        texto = ruta.read_text(encoding='utf-8')

        # Lo que esta ruta carga por su cuenta.
        propias = {m.group(1) for m in
                   re.finditer(r"require(?:_once)?\s+__DIR__\s*\.\s*'/\.\./lib/(\w+)\.php'", texto)}

        # Lo que usa: Clase::algo
        for m in re.finditer(r'\b([A-Z]\w+)::', texto):
            clase = m.group(1)
            if clase in AJENAS or clase not in disponibles:
                continue
            if clase in globales or clase in propias:
                continue
            linea = texto[:m.start()].count('\n') + 1
            problemas.append((ruta.name, linea, clase))

    # Y las clases entre sí.
    #
    # Esto faltaba, y se notó: Personal usaba Tickets::enHoraLocal() y
    # Tickets no se carga sola —la pide cada ruta que la necesita—, así
    # que exportar fichas moría con «Class "Tickets" not found». La
    # prueba miraba las rutas y daba el visto bueno.
    sueltas = []
    for lib in sorted(LIBS_DIR.glob('*.php')):
        if lib.stem in globales:
            continue   # ésta la carga index.php para todos
        texto = lib.read_text(encoding='utf-8')
        propias = {m.group(1) for m in
                   re.finditer(r"require(?:_once)?\s+__DIR__\s*\.\s*'/(\w+)\.php'", texto)}
        for m in re.finditer(r'\b([A-Z]\w+)::', texto):
            clase = m.group(1)
            if clase in AJENAS or clase not in disponibles:
                continue
            if clase == lib.stem or clase in globales or clase in propias:
                continue
            # class_exists() delante es una comprobación hecha a mano: vale.
            linea = texto[:m.start()].count('\n') + 1
            contexto = '\n'.join(texto.split('\n')[max(0, linea - 4):linea])
            if f"class_exists('{clase}')" in contexto or f'class_exists("{clase}")' in contexto:
                continue
            sueltas.append((lib.name, linea, clase))

    # Y las herramientas de consola.
    #
    # Mismo punto ciego que con las clases entre sí: diagnostico.php
    # comprobaba el tope real de subida dentro de un class_exists()
    # sobre una clase que no cargaba, así que la comprobación no se
    # ejecutaba NUNCA y el diagnóstico salía en verde sin mirar nada.
    # Las que otra herramienta incluye no se ejecutan solas: cuando
    # llegan, quien las incluyó ya ha cargado las clases. Comprobarlas
    # por separado da falsos positivos (demo.php lo incluye instalar.php).
    # Se busca el nombre del fichero en las demás, sin exigir que el
    # require esté escrito de una forma concreta: instalar.php lo arma en
    # una variable y un patrón fino se lo saltaba.
    incluidas = set()
    nombres = {h.name for h in HERRAMIENTAS.glob('*.php')}
    for h in HERRAMIENTAS.glob('*.php'):
        texto = h.read_text(encoding='utf-8')
        for otro in nombres:
            if otro != h.name and otro in texto:
                incluidas.add(otro)

    for her in sorted(HERRAMIENTAS.glob('*.php')):
        if her.name in incluidas:
            continue
        texto = her.read_text(encoding='utf-8')
        propias = {m.group(1) for m in re.finditer(
            r"require(?:_once)?\s+__DIR__\s*\.\s*'/\.\./api/lib/(\w+)\.php'", texto)}
        propias |= {m.group(1) for m in re.finditer(
            r"require(?:_once)?\s+__DIR__\s*\.\s*'/(\w+)\.php'", texto)}
        # Algunas cargan la carpeta entera de un tirón.
        if re.search(r"glob\(.*lib/\*\.php", texto):
            continue
        for m in re.finditer(r'\b([A-Z]\w+)::', texto):
            clase = m.group(1)
            if clase in AJENAS or clase not in disponibles or clase in propias:
                continue
            linea = texto[:m.start()].count('\n') + 1
            sueltas.append(('tools/' + her.name, linea, clase))

    print(f'Rutas revisadas: {len(list(RUTAS.glob("*.php")))}')
    print(f'Herramientas revisadas: {len(list(HERRAMIENTAS.glob("*.php")))}')
    print(f'Clases revisadas: {len(list(LIBS_DIR.glob("*.php")))}')

    if sueltas:
        print(f'\nUNA CLASE USA OTRA QUE NO SE CARGA SOLA ({len(sueltas)}):')
        for fichero, linea, clase in sorted(set(sueltas)):
            print(f'   {fichero if "/" in fichero else "lib/" + fichero}:{linea}  {clase}::…')
        print('\nO la cargas en index.php, o la pide la ruta que use esa clase,')
        print('o rodeas la llamada con class_exists() si es opcional.')

    if problemas:
        print(f'\nUNA RUTA USA UNA CLASE QUE NO CARGA ({len(problemas)}):')
        for fichero, linea, clase in sorted(set(problemas)):
            print(f'   {fichero}:{linea}  {clase}::…')
        print('\nAñade require_once __DIR__ . \'/../lib/<Clase>.php\'; al principio de esa ruta.')

    malos_sw = revisar_sw() + revisar_version() + revisar_marca() + revisar_nombres_internos() + revisar_bases_sueltas() + revisar_nombre_android() + revisar_firma_android() + revisar_pruebas_apuntan() + revisar_variables() + revisar_csv()
    if malos_sw:
        print(f'\nEL CÓDIGO SE CONTRADICE CONSIGO MISMO ({len(malos_sw)}):')
        for m in malos_sw:
            print('   ·', m)

    if problemas or sueltas or malos_sw:
        return 1

    print('Todas las rutas y clases tienen cargado lo que usan, y el '
          'trabajador de servicio promete lo que hay.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
