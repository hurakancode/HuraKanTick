#!/usr/bin/env bash
#
# Compilación del APK.
#
# El proyecto Android ya viene generado en android/. Este script sólo
# comprueba que tienes lo necesario, copia la web y lanza Gradle.
#
#   ./compilar-apk.sh              APK de pruebas
#   ./compilar-apk.sh --release    APK firmado (necesita android/key.properties)
#
set -e
cd "$(dirname "$0")"

azul='\033[0;36m'; rojo='\033[0;31m'; verde='\033[0;32m'; ama='\033[0;33m'; fin='\033[0m'
aviso() { printf "${ama}  %s${fin}\n" "$1"; }
error() { printf "${rojo}  %s${fin}\n" "$1"; }
bien()  { printf "${verde}  %s${fin}\n" "$1"; }

printf "${azul}Compilación del APK de HuraKan Tick${fin}\n\n"

# ---------------------------------------------------------------
# 1. Comprobaciones
# ---------------------------------------------------------------
fallos=0

if ! command -v node >/dev/null 2>&1; then
  error "Falta Node.js 18 o superior.  https://nodejs.org"
  fallos=1
else
  bien "Node $(node -v)"
fi

if ! command -v java >/dev/null 2>&1; then
  error "Falta el JDK 17.  sudo apt install openjdk-17-jdk"
  fallos=1
else
  jver=$(java -version 2>&1 | head -1)
  bien "Java: $jver"
fi

if [ -z "$ANDROID_HOME" ] && [ -z "$ANDROID_SDK_ROOT" ]; then
  if [ -d "$HOME/Android/Sdk" ]; then
    export ANDROID_HOME="$HOME/Android/Sdk"
    bien "SDK encontrado en $ANDROID_HOME"
  elif [ -d "$HOME/Library/Android/sdk" ]; then
    export ANDROID_HOME="$HOME/Library/Android/sdk"
    bien "SDK encontrado en $ANDROID_HOME"
  else
    error "No se encuentra el SDK de Android."
    error "Instala Android Studio y define ANDROID_HOME."
    fallos=1
  fi
else
  bien "SDK: ${ANDROID_HOME:-$ANDROID_SDK_ROOT}"
fi

# ---------------------------------------------------------------
# 2. La dirección del servidor: el olvido más habitual
# ---------------------------------------------------------------
# Sólo la asignación real, no la del comentario de ejemplo
api=$(grep -oP "^\s*window\.HURAKANTICK_API\s*=\s*'\K[^']*" www/index.html | tail -1 || true)
if [ -z "$api" ]; then
  aviso "www/index.html no tiene fijada la dirección del servidor."
  aviso "Dentro del APK no puede detectarse sola: se pedirá al entrar."
  aviso "Para fijarla:  window.HURAKANTICK_API = 'https://tu-servidor/hurakantick/api';"
  echo
else
  bien "Servidor: $api"
fi

if [ "$fallos" = "1" ]; then
  echo
  error "Faltan requisitos. Revisa lo de arriba."
  exit 1
fi

# ---------------------------------------------------------------
# 3. Dependencias y copia de la web
# ---------------------------------------------------------------
echo
if [ ! -d node_modules ]; then
  printf "${azul}Instalando dependencias…${fin}\n"
  npm install --no-audit --no-fund
fi

printf "${azul}Copiando la aplicación al proyecto Android…${fin}\n"
npx cap sync android

# ---------------------------------------------------------------
# 4. Compilación
# ---------------------------------------------------------------
echo
if [ "$1" = "--release" ]; then
  if [ ! -f android/key.properties ]; then
    error "Falta android/key.properties para firmar."
    echo
    echo "  Crea la firma una sola vez:"
    echo "    keytool -genkey -v -keystore hurakantick.keystore -alias hurakantick \\"
    echo "            -keyalg RSA -keysize 2048 -validity 10000"
    echo
    echo "  Y luego android/key.properties con:"
    echo "    storeFile=../../hurakantick.keystore"
    echo "    storePassword=TU_CONTRASEÑA"
    echo "    keyAlias=hurakantick"
    echo "    keyPassword=TU_CONTRASEÑA"
    echo
    echo "  Guarda el keystore: sin él no podrás publicar actualizaciones."
    exit 1
  fi
  printf "${azul}Compilando el APK firmado…${fin}\n"
  (cd android && ./gradlew assembleRelease)
  ruta="android/app/build/outputs/apk/release/app-release.apk"
else
  printf "${azul}Compilando el APK de pruebas…${fin}\n"
  (cd android && ./gradlew assembleDebug)
  ruta="android/app/build/outputs/apk/debug/app-debug.apk"
fi

echo
if [ -f "$ruta" ]; then
  bien "Listo: $ruta  ($(du -h "$ruta" | cut -f1))"
  echo
  echo "  Para instalarlo con el móvil conectado por USB:"
  echo "    adb install -r $ruta"
else
  error "Gradle terminó pero no se encuentra el APK en $ruta"
  exit 1
fi
