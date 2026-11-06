import re, glob, os
#!/usr/bin/env python3
"""
Compara el esquema SQLite con el de MySQL.

La aplicación funciona con los dos motores, y cada tabla se escribe dos
veces. Es fácil que una se quede atrás: se añade una columna en un sitio
y no en el otro, o se usa una sintaxis que sólo entiende uno.

Eso no se ve hasta que alguien instala en el motor que no se probó, y
entonces falla en la instalación o —peor— se instala a medias. Por eso
esta comprobación no necesita servidor: lee los ficheros y compara.

    python3 pruebas/esquemas.py
"""

import os
raiz=os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'db')

print("1. ¿HAY VERSIÓN MYSQL DE CADA AMPLIACIÓN?")
sq = sorted(os.path.basename(f) for f in glob.glob(f'{raiz}/*.sqlite.sql'))
my = sorted(os.path.basename(f) for f in glob.glob(f'{raiz}/*.mysql.sql'))
faltan = [s.replace('.sqlite.','.mysql.') for s in sq if s.replace('.sqlite.','.mysql.') not in my]
print(f"   sqlite: {len(sq)} · mysql: {len(my)}")
print(f"   sin equivalente: {faltan or 'ninguna'}")

print()
print("2. ¿COINCIDEN LAS TABLAS Y COLUMNAS?")
def tablas(txt):
    out={}
    for m in re.finditer(r'CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)', txt, re.S|re.I):
        cols=set()
        for l in m.group(2).split('\n'):
            l=l.strip()
            c=re.match(r'`?(\w+)`?\s+(INT|INTEGER|TEXT|VARCHAR|CHAR|DATETIME|DATE|TIME|REAL|DOUBLE|DECIMAL|BLOB|TINYINT|BIGINT|SMALLINT|MEDIUMTEXT|LONGTEXT|NUMERIC|BOOLEAN)', l, re.I)
            if c and c.group(1).upper() not in ('PRIMARY','FOREIGN','UNIQUE','KEY','INDEX','CONSTRAINT'):
                cols.add(c.group(1))
        out[m.group(1)]=cols
    for m in re.finditer(r'ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?', txt, re.I):
        out.setdefault(m.group(1), set()).add(m.group(2))
    return out

todoS = ''.join(open(f,encoding='utf-8').read() for f in sorted(glob.glob(f'{raiz}/*.sqlite.sql')))
todoM = ''.join(open(f,encoding='utf-8').read() for f in sorted(glob.glob(f'{raiz}/*.mysql.sql')))
tS, tM = tablas(todoS), tablas(todoM)
soloS = set(tS) - set(tM)
soloM = set(tM) - set(tS)
print(f"   tablas sqlite: {len(tS)} · mysql: {len(tM)}")
print(f"   sólo en sqlite: {sorted(soloS) or 'ninguna'}")
print(f"   sólo en mysql:  {sorted(soloM) or 'ninguna'}")
dif=0
for t in sorted(set(tS) & set(tM)):
    d = tS[t] ^ tM[t]
    if d:
        dif+=1
        print(f"   {t}: difieren {sorted(d)}")
print(f"   tablas con columnas distintas: {dif}")

print()
print("3. SINTAXIS SQLITE QUE MYSQL NO ENTIENDE")
malos=[]
for f in sorted(glob.glob(f'{raiz}/*.mysql.sql')):
    txt=re.sub(r'^--.*$', '', open(f,encoding='utf-8').read(), flags=re.M)
    for pat, que in [(r'\bAUTOINCREMENT\b','AUTOINCREMENT (mysql usa AUTO_INCREMENT)'),
                     (r'INSERT OR IGNORE','INSERT OR IGNORE (mysql usa INSERT IGNORE)'),
                     (r"strftime\(",'strftime() (mysql no la tiene)'),
                     (r'\bWITHOUT ROWID\b','WITHOUT ROWID'),
                     (r'CREATE INDEX IF NOT EXISTS','CREATE INDEX IF NOT EXISTS (mysql no lo soporta)')]:
        if re.search(pat, txt, re.I):
            malos.append(f"   {os.path.basename(f)}: {que}")
print('\n'.join(malos) if malos else "   ninguna")
#!/usr/bin/env python3
"""
Compara el esquema SQLite con el de MySQL.

La aplicación funciona con los dos motores, y cada tabla se escribe dos
veces. Es fácil que una se quede atrás: se añade una columna en un sitio
y no en el otro, o se usa una sintaxis que sólo entiende uno.

Eso no se ve hasta que alguien instala en el motor que no se probó, y
entonces falla en la instalación o —peor— se instala a medias. Por eso
esta comprobación no necesita servidor: lee los ficheros y compara.

    python3 pruebas/esquemas.py
"""

import os
raiz=os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'db')

print("1. ¿HAY VERSIÓN MYSQL DE CADA AMPLIACIÓN?")
sq = sorted(os.path.basename(f) for f in glob.glob(f'{raiz}/*.sqlite.sql'))
my = sorted(os.path.basename(f) for f in glob.glob(f'{raiz}/*.mysql.sql'))
faltan = [s.replace('.sqlite.','.mysql.') for s in sq if s.replace('.sqlite.','.mysql.') not in my]
print(f"   sqlite: {len(sq)} · mysql: {len(my)}")
print(f"   sin equivalente: {faltan or 'ninguna'}")

print()
print("2. ¿COINCIDEN LAS TABLAS Y COLUMNAS?")
def tablas(txt):
    out={}
    for m in re.finditer(r'CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)', txt, re.S|re.I):
        cols=set()
        for l in m.group(2).split('\n'):
            l=l.strip()
            c=re.match(r'`?(\w+)`?\s+(INT|INTEGER|TEXT|VARCHAR|CHAR|DATETIME|DATE|TIME|REAL|DOUBLE|DECIMAL|BLOB|TINYINT|BIGINT|SMALLINT|MEDIUMTEXT|LONGTEXT|NUMERIC|BOOLEAN)', l, re.I)
            if c and c.group(1).upper() not in ('PRIMARY','FOREIGN','UNIQUE','KEY','INDEX','CONSTRAINT'):
                cols.add(c.group(1))
        out[m.group(1)]=cols
    for m in re.finditer(r'ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?', txt, re.I):
        out.setdefault(m.group(1), set()).add(m.group(2))
    return out

todoS = ''.join(open(f,encoding='utf-8').read() for f in sorted(glob.glob(f'{raiz}/*.sqlite.sql')))
todoM = ''.join(open(f,encoding='utf-8').read() for f in sorted(glob.glob(f'{raiz}/*.mysql.sql')))
tS, tM = tablas(todoS), tablas(todoM)
soloS = set(tS) - set(tM)
soloM = set(tM) - set(tS)
print(f"   tablas sqlite: {len(tS)} · mysql: {len(tM)}")
print(f"   sólo en sqlite: {sorted(soloS) or 'ninguna'}")
print(f"   sólo en mysql:  {sorted(soloM) or 'ninguna'}")
dif=0
for t in sorted(set(tS) & set(tM)):
    d = tS[t] ^ tM[t]
    if d:
        dif+=1
        print(f"   {t}: difieren {sorted(d)}")
print(f"   tablas con columnas distintas: {dif}")

print()
print("3. SINTAXIS SQLITE QUE MYSQL NO ENTIENDE")
malos=[]
for f in sorted(glob.glob(f'{raiz}/*.mysql.sql')):
    txt=re.sub(r'^--.*$', '', open(f,encoding='utf-8').read(), flags=re.M)
    for pat, que in [(r'\bAUTOINCREMENT\b','AUTOINCREMENT (mysql usa AUTO_INCREMENT)'),
                     (r'INSERT OR IGNORE','INSERT OR IGNORE (mysql usa INSERT IGNORE)'),
                     (r"strftime\(",'strftime() (mysql no la tiene)'),
                     (r'\bWITHOUT ROWID\b','WITHOUT ROWID'),
                     (r'CREATE INDEX IF NOT EXISTS','CREATE INDEX IF NOT EXISTS (mysql no lo soporta)')]:
        if re.search(pat, txt, re.I):
            malos.append(f"   {os.path.basename(f)}: {que}")
print('\n'.join(malos) if malos else "   ninguna")

print()
print("4. ¿SABE EL BORRADO DE UNA PERSONA DE TODAS LAS TABLAS QUE LA APUNTAN?")
#
# Borrar a una persona de verdad tiene que decidir, tabla por tabla, qué
# pasa con lo que dejó: se borra, pasa a quien borra, o se queda sin
# dueño. La lista está escrita a mano en admin.php a propósito.
#
# El problema es que una tabla nueva que apunte a login no avisa: el
# borrado empieza a fallar con «FOREIGN KEY constraint failed» —el error
# de la base de datos, en crudo, en la pantalla de un administrador— y
# nadie lo nota hasta que alguien intenta borrar a alguien. Pasó: la
# lista se había quedado corta en NUEVE tablas.
#
# Esto lo caza en el momento de añadir la tabla, que es cuando hay que
# decidir, y no meses después.
esq = ''
for f in glob.glob(f'{raiz}/*.sqlite.sql'):
    esq += open(f, encoding='utf-8').read() + '\n'
for f in glob.glob(os.path.join(raiz, '..', 'paquetes', '*', '*.sqlite.sql')):
    esq += open(f, encoding='utf-8').read() + '\n'

apuntan = {}
for m in re.finditer(r'CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)', esq, re.S | re.I):
    for ln in m.group(2).split('\n'):
        c = re.match(r'\s*`?(\w+)`?\s+\w+[^,]*REFERENCES\s+`?login`?', ln, re.I)
        if c:
            apuntan.setdefault(m.group(1), set()).add(c.group(1))

admin = open(os.path.join(raiz, '..', 'api', 'routes', 'admin.php'), encoding='utf-8').read()
ini = admin.find('$borrar = [')
# El corte va desde la lista hasta el borrado de login QUE VIENE
# DESPUÉS: buscándolo desde el principio del fichero se encontraba
# otro anterior, el trozo salía vacío y la comprobación daba por
# sin decidir las veintitantas tablas. Una prueba que falla siempre
# se acaba ignorando, que es lo mismo que no tenerla.
trozo = admin[ini:admin.find("DELETE FROM login WHERE", ini)]

# ticket_incidencias y login se tratan aparte, a mano, antes de la lista.
A_MANO = {'ticket_incidencias', 'login'}
sin_decidir = []
for tabla, cols in sorted(apuntan.items()):
    if tabla in A_MANO:
        continue
    if f"'{tabla}'" not in trozo:
        sin_decidir.append(f"   {tabla} ({', '.join(sorted(cols))})")

print(f"   tablas que apuntan a login: {len(apuntan)}")
if sin_decidir:
    print("   SIN DECIDIR QUÉ PASA CON ELLAS AL BORRAR A UNA PERSONA:")
    print('\n'.join(sin_decidir))
    print("   (el borrado definitivo fallará con FOREIGN KEY constraint failed)")
else:
    print("   todas están en la lista del borrado")
