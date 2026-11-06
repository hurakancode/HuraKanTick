/* =====================================================================
   Integración con la aplicación nativa.

   Todo lo de aquí es opcional: si los complementos de Capacitor no están
   —porque se está usando desde el navegador— cada función se queda en
   nada y la aplicación funciona igual. Así hay un solo código para las
   dos formas de usarla.
   ===================================================================== */

let cap = null;
let esNativo = false;

export function nativo() { return esNativo; }
export function plataforma() { return cap?.getPlatform?.() || 'web'; }

/** Carga los complementos si están. Se llama una vez al arrancar. */
export async function iniciarNativo({ alVolver, alSincronizar } = {}) {
  cap = window.Capacitor || null;
  esNativo = !!(cap?.isNativePlatform?.());

  if (!esNativo) {
    return false;
  }

  const P = cap.Plugins || {};

  /* --- Barra de estado: que no tape la cabecera ------------------- */
  try {
    if (P.StatusBar) {
      await P.StatusBar.setStyle({ style: 'DARK' });     // texto claro
      await P.StatusBar.setBackgroundColor({ color: '#0B5563' });
      await P.StatusBar.setOverlaysWebView({ overlay: false });
    }
  } catch (e) { console.warn('StatusBar:', e); }

  /* --- Pantalla de arranque --------------------------------------- */
  try {
    if (P.SplashScreen) await P.SplashScreen.hide();
  } catch { /* da igual */ }

  /* --- Botón físico de atrás -------------------------------------- */
  // En Android es el gesto más usado. Sin esto, cierra la aplicación
  // desde cualquier pantalla, que es lo último que espera nadie.
  try {
    if (P.App) {
      P.App.addListener('backButton', ({ canGoBack }) => {
        // Si hay una hoja abierta, el atrás la cierra a ella.
        const hoja = document.querySelector('.hoja.visible');
        const visor = document.querySelector('.visor');
        if (visor) { visor.remove(); return; }
        if (hoja) {
          hoja.querySelector('[data-cerrar]')?.click();
          return;
        }
        const ruta = location.hash.replace(/^#/, '');
        if (ruta === '/panel' || ruta === '' || !canGoBack) {
          // En la pantalla principal, el atrás sale de la aplicación,
          // pero pidiendo confirmación para no perder nada por error.
          if (alVolver) alVolver();
          else P.App.exitApp();
        } else {
          history.back();
        }
      });

      /* --- Vuelta desde segundo plano ------------------------------ */
      P.App.addListener('appStateChange', ({ isActive }) => {
        if (isActive && alSincronizar) alSincronizar();
      });
    }
  } catch (e) { console.warn('App:', e); }

  /* --- Estado de la red ------------------------------------------- */
  // El complemento de Capacitor distingue mejor que el navegador entre
  // «hay wifi» y «hay salida a internet».
  try {
    if (P.Network) {
      P.Network.addListener('networkStatusChange', (estado) => {
        window.dispatchEvent(new Event(estado.connected ? 'online' : 'offline'));
      });
    }
  } catch (e) { console.warn('Network:', e); }

  return true;
}

/* ---------------------------------------------------------------- */
/* Cámara nativa                                                    */
/* ---------------------------------------------------------------- */
/**
 * Devuelve una foto como data-url, o null si el complemento no está o
 * la persona cancela. Quien llama debe tener siempre lista la vía del
 * navegador como alternativa.
 */
export async function fotoNativa({ galeria = false } = {}) {
  const Camera = window.Capacitor?.Plugins?.Camera;
  if (!Camera) return null;
  try {
    const foto = await Camera.getPhoto({
      quality: 72,
      allowEditing: false,
      resultType: 'dataUrl',
      source: galeria ? 'PHOTOS' : 'CAMERA',
      width: 1600,
      correctOrientation: true,
      promptLabelHeader: 'Fotografía del parte',
      promptLabelPhoto: 'Elegir de la galería',
      promptLabelPicture: 'Hacer una foto',
      promptLabelCancel: 'Cancelar',
    });
    return foto?.dataUrl || null;
  } catch (e) {
    // Cancelar lanza excepción: no es un error que haya que enseñar.
    if (String(e?.message || e).toLowerCase().includes('cancel')) return null;
    console.warn('Camera:', e);
    return null;
  }
}

/* ---------------------------------------------------------------- */
/* Avisos del sistema en el móvil                                   */
/* ---------------------------------------------------------------- */
export async function permisoAvisosNativo() {
  const LN = window.Capacitor?.Plugins?.LocalNotifications;
  if (!LN) return 'no-soportado';
  try {
    const r = await LN.requestPermissions();
    return r?.display === 'granted' ? 'granted' : 'denied';
  } catch { return 'denied'; }
}

/**
 * Aviso local. Se usa cuando la sincronización trae algo que merece
 * interrumpir: un parte asignado o uno urgente.
 */
export async function avisoNativo(titulo, cuerpo, datos = {}) {
  const LN = window.Capacitor?.Plugins?.LocalNotifications;
  if (!LN) return false;
  try {
    await LN.schedule({
      notifications: [{
        id: Math.floor(Math.random() * 2147483647),
        title: titulo,
        body: cuerpo,
        smallIcon: 'ic_stat_icon',
        extra: datos,
        // Sin schedule = inmediato
      }],
    });
    return true;
  } catch (e) {
    console.warn('LocalNotifications:', e);
    return false;
  }
}

/** Al tocar un aviso se abre el parte correspondiente. */
export function alTocarAviso(fn) {
  const LN = window.Capacitor?.Plugins?.LocalNotifications;
  if (!LN) return;
  try {
    LN.addListener('localNotificationActionPerformed', (accion) => {
      const ruta = accion?.notification?.extra?.ruta;
      if (ruta) fn(ruta);
    });
  } catch { /* nada */ }
}
