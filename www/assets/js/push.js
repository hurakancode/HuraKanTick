/* =====================================================================
   Avisos con la aplicación cerrada.

   Sin esto, un aviso sólo llegaba cuando la aplicación preguntaba al
   servidor — y una aplicación cerrada no pregunta. Justo de madrugada,
   con el móvil en el bolsillo, es cuando peor funcionaba.

   Dos caminos, porque no hay uno que valga para los dos sitios:

     · navegador → Web Push, con el trabajador de servicio escuchando
     · APK       → Firebase, que es lo único que Android admite

   Ambos acaban en la misma tabla del servidor. Aquí sólo se decide cuál
   toca y se registra el aparato.
   ===================================================================== */

import { api } from './api.js';
import { nativo } from './nativo.js';

/** ¿Puede este aparato recibir avisos con la aplicación cerrada? */
export function hayPush() {
  if (nativo()) {
    return !!window.Capacitor?.Plugins?.PushNotifications;
  }
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/** ¿Está ya registrado? */
export async function estadoPush() {
  if (!hayPush()) return { disponible: false, activo: false };

  if (nativo()) {
    try {
      const P = window.Capacitor.Plugins.PushNotifications;
      const p = await P.checkPermissions();
      return { disponible: true, activo: p.receive === 'granted', permiso: p.receive };
    } catch { return { disponible: true, activo: false }; }
  }

  try {
    const reg = await navigator.serviceWorker.ready;
    const sus = await reg.pushManager.getSubscription();
    return {
      disponible: true,
      activo: !!sus,
      permiso: Notification.permission,
    };
  } catch { return { disponible: true, activo: false }; }
}

/**
 * Da de alta este aparato.
 *
 * Devuelve un texto explicando qué ha pasado, porque los motivos por los
 * que esto falla son muy distintos entre sí y «no se pudo activar» no
 * ayuda a nadie: sin permiso, sin HTTPS, o el navegador no lo admite.
 */
export async function activarPush() {
  if (!hayPush()) {
    return { ok: false, motivo: nativo()
      ? 'Esta versión de la aplicación no trae avisos en segundo plano.'
      : 'Este navegador no admite avisos con la aplicación cerrada.' };
  }

  return nativo() ? activarNativo() : activarWeb();
}

/* ------------------------------------------------------------------ */
async function activarWeb() {
  // Sin HTTPS el navegador no deja: es la causa más común y conviene
  // decirlo con esas palabras, no con un error genérico.
  if (location.protocol !== 'https:' && location.hostname !== 'localhost'
      && location.hostname !== '127.0.0.1') {
    return { ok: false, motivo: 'Hace falta HTTPS. Con http:// el navegador no permite estos avisos.' };
  }

  const permiso = await Notification.requestPermission();
  if (permiso !== 'granted') {
    return { ok: false, motivo: permiso === 'denied'
      ? 'Has bloqueado los avisos para esta página. Hay que permitirlos en los ajustes del navegador.'
      : 'No se ha dado permiso para los avisos.' };
  }

  let clave;
  try {
    const r = await api.pushClave();
    clave = r.clave;
    if (!r.activo) {
      return { ok: false, motivo: 'El administrador tiene desactivados los avisos en segundo plano.' };
    }
  } catch (e) {
    return { ok: false, motivo: 'No se ha podido hablar con el servidor: ' + e.message };
  }

  try {
    const reg = await navigator.serviceWorker.ready;
    let sus = await reg.pushManager.getSubscription();

    if (!sus) {
      sus = await reg.pushManager.subscribe({
        userVisibleOnly: true,          // obligatorio en Chrome
        applicationServerKey: aBytes(clave),
      });
    }

    const j = sus.toJSON();
    await api.pushRegistrar({
      tipo: 'web',
      destino: j.endpoint,
      clave_p256: j.keys.p256dh,
      clave_auth: j.keys.auth,
      aparato: nombreAparato(),
    });
    return { ok: true, motivo: 'Este aparato recibirá avisos aunque cierres la aplicación.' };
  } catch (e) {
    return { ok: false, motivo: 'No se ha podido suscribir: ' + e.message };
  }
}

/* ------------------------------------------------------------------ */
async function activarNativo() {
  const P = window.Capacitor.Plugins.PushNotifications;
  try {
    let p = await P.checkPermissions();
    if (p.receive !== 'granted') {
      p = await P.requestPermissions();
    }
    if (p.receive !== 'granted') {
      return { ok: false, motivo: 'Hay que permitir los avisos en los ajustes del móvil.' };
    }

    /* El testigo llega por un aviso, no como respuesta: se espera a que
       llegue en vez de dar por hecho que ya está. */
    const testigo = await new Promise((resolve, reject) => {
      const reloj = setTimeout(() => reject(new Error('Firebase no ha respondido.')), 15000);
      P.addListener('registration', (t) => { clearTimeout(reloj); resolve(t.value); });
      P.addListener('registrationError', (e) => { clearTimeout(reloj); reject(new Error(e.error || 'error')); });
      P.register();
    });

    await api.pushRegistrar({
      tipo: 'android',
      destino: testigo,
      aparato: nombreAparato(),
    });
    return { ok: true, motivo: 'Este móvil recibirá avisos aunque cierres la aplicación.' };
  } catch (e) {
    return { ok: false, motivo: e.message };
  }
}

/* ------------------------------------------------------------------ */
export async function desactivarPush() {
  try {
    if (!nativo()) {
      const reg = await navigator.serviceWorker.ready;
      const sus = await reg.pushManager.getSubscription();
      if (sus) {
        await api.pushBorrar(sus.endpoint).catch(() => {});
        await sus.unsubscribe();
      }
    }
    return { ok: true, motivo: 'Este aparato deja de recibir avisos en segundo plano.' };
  } catch (e) {
    return { ok: false, motivo: e.message };
  }
}

/**
 * Los avisos que llegan por Firebase los pinta la aplicación, no
 * Android: así se decide el icono, el sonido y qué hacer al tocarlos.
 */
export function escucharNativo(alTocar) {
  if (!nativo()) return;
  const P = window.Capacitor?.Plugins?.PushNotifications;
  if (!P) return;

  P.addListener('pushNotificationReceived', async (n) => {
    const LN = window.Capacitor?.Plugins?.LocalNotifications;
    if (!LN) return;
    const d = n.data || {};
    try {
      await LN.schedule({ notifications: [{
        id: Math.floor(Math.random() * 2147483647),
        title: d.titulo || n.title || 'Aviso',
        body: d.cuerpo || n.body || '',
        smallIcon: 'ic_stat_icon',
        extra: { ruta: d.ruta || '' },
      }] });
    } catch { /* un aviso que no se pinta no rompe nada */ }
  });

  P.addListener('pushNotificationActionPerformed', (a) => {
    const ruta = a?.notification?.data?.ruta;
    if (ruta && alTocar) alTocar(ruta);
  });
}

/* ------------------------------------------------------------------ */
function aBytes(base64) {
  const relleno = '='.repeat((4 - (base64.length % 4)) % 4);
  const b = (base64 + relleno).replace(/-/g, '+').replace(/_/g, '/');
  const cruda = atob(b);
  return Uint8Array.from([...cruda].map((c) => c.charCodeAt(0)));
}

function nombreAparato() {
  const ua = navigator.userAgent;
  if (/Android/i.test(ua)) return 'Android';
  if (/iPhone|iPad/i.test(ua)) return 'iPhone o iPad';
  if (/Windows/i.test(ua)) return 'Windows';
  if (/Mac/i.test(ua)) return 'Mac';
  return 'Este aparato';
}
