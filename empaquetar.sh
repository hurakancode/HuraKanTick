#!/usr/bin/env bash
# =====================================================================
#  Montar el paquete de una versión: paquetes/hurakan-tick-X.Y.Z.zip
#
#  Hasta la 7.90 esto se hacía a mano, con unas órdenes que se escribían
#  de nuevo cada vez. La de la 7.90 llevaba «rm db/*.sqlite*» para quitar
#  las bases de datos… y ese patrón también casa con «schema.sqlite.sql»:
#  se fueron los 34 scripts de SQLite y el paquete no instalaba. Se vio
#  porque el paquete se instala siempre antes de darlo por bueno.
#
#  Así que ahora es un script, y al final COMPRUEBA lo que ha metido:
#  que estén los esquemas, que no haya ninguna base ni configuración de
#  nadie, y que estén todos los candados. Si algo falla, no hay paquete.
#
#     ./empaquetar.sh
# =====================================================================
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")" && pwd)"
VERSION="$(python3 -c "import json;print(json.load(open('$RAIZ/www/version.json'))['version'])")"
NOMBRE="hurakan-tick-$VERSION.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "HuraKan Tick $VERSION"
cp -a "$RAIZ" "$TMP/hurakantick"
cd "$TMP/hurakantick"

# ---- Lo que no viaja ------------------------------------------------
# Las bases, por su nombre EXACTO de terminación: «.sqlite», no «.sqlite*».
find . -type f \( -name '*.sqlite' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' \) \
     -not -path './paquetes/*' -print -exec rm -f {} +
rm -f api/config.local.php db/actualizacion.json db/REINSTALAR db/REINSTALAR.txt
rm -f paquetes/*.zip
find . -name '__pycache__' -type d -prune -exec rm -rf {} +
# La firma del APK y lo compilado. .gitignore los deja fuera del repositorio,
# pero este script no lo lee: con el APK firmado compilado aquí, la clave de
# firma y su contraseña habrían viajado dentro del zip.
rm -f android/key.properties android/local.properties
find . \( -name '*.keystore' -o -name '*.jks' -o -name '*.apk' -o -name '*.aab' \) -type f -exec rm -f {} +
rm -rf android/app/build android/build android/.gradle android/app/src/main/assets/public
# Lo subido y las copias son de cada casa: las carpetas viajan, con su
# candado, y vacías.
find api/copias -mindepth 1 ! -name '.htaccess' -exec rm -rf {} + 2>/dev/null || true
find api/uploads -mindepth 1 -maxdepth 1 ! -name '.htaccess' ! -name 'instrucciones' -exec rm -rf {} + 2>/dev/null || true
find api/uploads/instrucciones -mindepth 1 ! -name '.htaccess' -exec rm -rf {} + 2>/dev/null || true

cd "$TMP"
mkdir -p "$RAIZ/paquetes"
rm -f "$RAIZ/paquetes/$NOMBRE"
zip -rq "$RAIZ/paquetes/$NOMBRE" hurakantick -x '*.pyc'

# ---- Comprobar lo que se ha metido ---------------------------------
LISTA="$(unzip -Z1 "$RAIZ/paquetes/$NOMBRE")"
mal=0
for f in db/schema.sqlite.sql db/schema.mysql.sql db/catalogos.sqlite.sql db/catalogos.mysql.sql \
         tools/instalar.php tools/clave_demo.php www/version.json api/config.php \
         .htaccess db/.htaccess tools/.htaccess www/.htaccess api/.htaccess \
         api/copias/.htaccess api/uploads/.htaccess api/uploads/instrucciones/.htaccess; do
  if ! grep -qx "hurakantick/$f" <<<"$LISTA"; then echo "  FALTA: $f"; mal=1; fi
done
n_sqlite_sql="$(grep -c '^hurakantick/db/.*\.sqlite\.sql$' <<<"$LISTA" || true)"
n_mysql_sql="$(grep -c '^hurakantick/db/.*\.mysql\.sql$' <<<"$LISTA" || true)"
if [ "$n_sqlite_sql" -lt 30 ]; then
  echo "  Sólo $n_sqlite_sql scripts de SQLite (hay más de 30): algo los ha quitado."; mal=1
fi
if grep -Eq '\.sqlite(-wal|-shm)?$' <<<"$LISTA"; then
  echo "  HAY UNA BASE DE DATOS DENTRO:"; grep -E '\.sqlite(-wal|-shm)?$' <<<"$LISTA" | sed 's/^/    /'; mal=1
fi
if grep -Eq '(key\.properties|local\.properties|\.keystore|\.jks|\.apk|\.aab)$' <<<"$LISTA"; then
  echo "  HAY UNA FIRMA O UN APK DENTRO:"; grep -E '(key\.properties|local\.properties|\.keystore|\.jks|\.apk|\.aab)$' <<<"$LISTA" | sed 's/^/    /'; mal=1
fi
if grep -Eq '(config\.local\.php|actualizacion\.json)$' <<<"$LISTA"; then
  echo "  HAY CONFIGURACIÓN DE UNA INSTALACIÓN DENTRO."; mal=1
fi
if [ "$mal" -ne 0 ]; then
  rm -f "$RAIZ/paquetes/$NOMBRE"
  echo "El paquete NO es bueno: no se ha dejado."
  exit 1
fi

echo "  $(wc -l <<<"$LISTA") ficheros · $n_sqlite_sql scripts SQLite · $n_mysql_sql scripts MySQL · ningún .sqlite"
echo "paquetes/$NOMBRE  ($(du -h "$RAIZ/paquetes/$NOMBRE" | cut -f1))"
echo "Antes de darlo por bueno: instálalo desde el zip (docs/PRUEBAS.md)."
