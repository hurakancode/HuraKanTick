/* =====================================================================
   Avisos sonoros y vibración.

   Los tonos se sintetizan con Web Audio en el propio dispositivo: no hay
   ficheros de sonido que descargar, así que funcionan igual sin cobertura
   y no engordan el APK.

   El contexto de audio sólo puede arrancar tras una interacción del
   usuario (regla de los navegadores), así que se despierta con el primer
   toque en la pantalla y a partir de ahí queda listo.
   ===================================================================== */

const CLAVE_SONIDO   = 'hurakantick_sonido';
const CLAVE_VIBRAR   = 'hurakantick_vibrar';
const CLAVE_SILENCIO = 'hurakantick_silencio';

let ctx = null;
let despierto = false;

/* ---------------------------------------------------------------- */
/* Preferencias                                                     */
/* ---------------------------------------------------------------- */
export function sonidoActivo() {
  return localStorage.getItem(CLAVE_SONIDO) !== '0';
}
export function fijarSonido(activo) {
  localStorage.setItem(CLAVE_SONIDO, activo ? '1' : '0');
  if (activo) { despertar(); tono('prueba'); }
}
export function vibracionActiva() {
  return localStorage.getItem(CLAVE_VIBRAR) !== '0';
}
export function fijarVibracion(activa) {
  localStorage.setItem(CLAVE_VIBRAR, activa ? '1' : '0');
  if (activa) vibrar([40, 60, 40]);
}

/* ---------------------------------------------------------------- */
/* Horario de silencio                                              */
/*                                                                  */
/* Los avisos siguen llegando: lo que se calla es el sonido. Un      */
/* parte que entra de madrugada tiene que estar ahí por la mañana,   */
/* pero no despertar a nadie.                                        */
/* ---------------------------------------------------------------- */
export function silencio() {
  try {
    const g = JSON.parse(localStorage.getItem(CLAVE_SILENCIO) || '{}');
    return {
      activo: !!g.activo,
      desde: g.desde || '22:00',
      hasta: g.hasta || '07:00',
      salvo_urgentes: g.salvo_urgentes !== false,
    };
  } catch {
    return { activo: false, desde: '22:00', hasta: '07:00', salvo_urgentes: true };
  }
}

export function fijarSilencio(cambios) {
  const actual = silencio();
  const nuevo = { ...actual, ...cambios };
  localStorage.setItem(CLAVE_SILENCIO, JSON.stringify(nuevo));
  return nuevo;
}

/** ¿Estamos ahora dentro del horario de silencio? */
export function enSilencio() {
  const s = silencio();
  if (!s.activo) return false;

  const ahora = new Date();
  const min = ahora.getHours() * 60 + ahora.getMinutes();
  const [hd, md] = s.desde.split(':').map(Number);
  const [hh, mh] = s.hasta.split(':').map(Number);
  const desde = hd * 60 + md;
  const hasta = hh * 60 + mh;

  // El horario cruza la medianoche casi siempre (22:00 a 07:00), así
  // que hay que tratarlo como dos tramos.
  return desde <= hasta
    ? (min >= desde && min < hasta)
    : (min >= desde || min < hasta);
}

/* ---------------------------------------------------------------- */
/* Motor                                                            */
/* ---------------------------------------------------------------- */
function despertar() {
  if (ctx) return ctx;
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return null;
  try {
    ctx = new AC();
    despierto = ctx.state === 'running';
  } catch { ctx = null; }
  return ctx;
}

/** Se engancha al primer toque: sin eso el navegador no deja sonar nada. */
export function prepararAudio() {
  const activar = () => {
    const c = despertar();
    if (c && c.state === 'suspended') c.resume().then(() => { despierto = true; });
    else if (c) despierto = true;
  };
  ['pointerdown', 'keydown', 'touchstart'].forEach((ev) =>
    window.addEventListener(ev, activar, { once: true, passive: true }));
}

/**
 * Una nota. Se usa onda triangular porque en el altavoz de un móvil se
 * oye clara sin resultar estridente en un pasillo silencioso.
 */
function nota(frecuencia, inicio, duracion, volumen = 0.16, onda = 'triangle') {
  if (!ctx) return;
  const osc = ctx.createOscillator();
  const gan = ctx.createGain();
  osc.type = onda;
  osc.frequency.setValueAtTime(frecuencia, ctx.currentTime + inicio);

  // Ataque y caída suaves: un corte seco suena a chasquido.
  gan.gain.setValueAtTime(0.0001, ctx.currentTime + inicio);
  gan.gain.exponentialRampToValueAtTime(volumen, ctx.currentTime + inicio + 0.012);
  gan.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + inicio + duracion);

  osc.connect(gan).connect(ctx.destination);
  osc.start(ctx.currentTime + inicio);
  osc.stop(ctx.currentTime + inicio + duracion + 0.02);
}

/* Cada aviso tiene su forma: se distinguen sin mirar la pantalla. */
const TONOS = {
  // Confirmación breve, dos notas ascendentes
  ok:        [[880, 0, 0.09], [1174, 0.08, 0.13]],
  // Guardado en el móvil sin enviar: una sola nota media, discreta
  guardado:  [[660, 0, 0.11]],
  // Sincronización completada: tres notas subiendo
  sync:      [[698, 0, 0.07], [880, 0.06, 0.07], [1046, 0.12, 0.16]],
  // Parte nuevo asignado: llamada de atención, dos golpes iguales
  asignado:  [[988, 0, 0.12], [988, 0.18, 0.16]],
  // Urgente: cuatro notas rápidas y agudas
  urgente:   [[1318, 0, 0.08], [1318, 0.11, 0.08], [1318, 0.22, 0.08], [1568, 0.33, 0.2]],
  // Error: dos notas descendentes
  error:     [[440, 0, 0.11], [330, 0.11, 0.19]],
  // Conflicto al sincronizar: descendente y más grave, para que no se
  // confunda con un error corriente
  conflicto: [[523, 0, 0.1], [415, 0.1, 0.1], [330, 0.2, 0.24]],
  // Prueba desde ajustes
  prueba:    [[784, 0, 0.09], [1046, 0.08, 0.14]],
};

const VIBRACIONES = {
  ok: [30],
  guardado: [25],
  sync: [20, 40, 20],
  asignado: [60, 80, 60],
  urgente: [90, 60, 90, 60, 140],
  error: [110],
  conflicto: [70, 50, 70, 50, 70],
  prueba: [40],
};

export function vibrar(patron) {
  if (!vibracionActiva()) return;

  /* Dentro del APK se usa Haptics.
     navigator.vibrate() existe en Android pero muchos fabricantes lo
     ignoran desde una vista web, y entonces la vibración simplemente no
     ocurre sin dar ningún error. Haptics va por la vía nativa y sí
     funciona; fuera del APK se usa lo del navegador, que en el móvil es
     lo único que hay. */
  const H = window.Capacitor?.Plugins?.Haptics;
  if (H) {
    try {
      const ms = Array.isArray(patron)
        ? patron.filter((_, i) => i % 2 === 0).reduce((a, b) => a + b, 0)
        : patron;
      H.vibrate({ duration: Math.min(Math.max(ms || 60, 20), 500) });
      return;
    } catch { /* si falla se intenta con el navegador */ }
  }

  if (!navigator.vibrate) return;
  try { navigator.vibrate(patron); } catch { /* algunos navegadores lo bloquean */ }
}

/**
 * Reproduce un aviso. Nunca lanza excepción: un fallo de audio no puede
 * tumbar la acción que lo provocó.
 */
export function tono(nombre) {
  const patronVibracion = VIBRACIONES[nombre];
  if (patronVibracion) vibrar(patronVibracion);

  if (!sonidoActivo()) return;

  // De noche se calla, salvo lo urgente si así se ha pedido: una avería
  // urgente a las tres de la mañana sigue siendo urgente.
  if (enSilencio()) {
    const s = silencio();
    const esUrgente = nombre === 'urgente' || nombre === 'conflicto';
    if (!(esUrgente && s.salvo_urgentes)) return;
  }

  try {
    const c = despertar();
    if (!c) return;
    if (c.state === 'suspended') {
      // Todavía no ha habido interacción: se intenta reanudar y se deja
      // pasar. No se encola nada; un pitido a destiempo confunde más.
      c.resume().catch(() => {});
      if (c.state !== 'running') return;
    }
    (TONOS[nombre] || TONOS.ok).forEach(([f, t, d]) => nota(f, t, d));
  } catch { /* el audio nunca debe romper la aplicación */ }
}

import { nativo, avisoNativo, permisoAvisosNativo } from './nativo.js';

/** Aviso de sistema del navegador, si el usuario lo ha permitido. */
export async function permisoNotificaciones() {
  if (nativo()) return permisoAvisosNativo();
  if (!('Notification' in window)) return 'no-soportado';
  if (Notification.permission === 'granted') return 'granted';
  if (Notification.permission === 'denied') return 'denied';
  try { return await Notification.requestPermission(); } catch { return 'denied'; }
}

export function avisoSistema(titulo, cuerpo, datos = {}) {
  // En la aplicación de Android va por el complemento nativo: llega
  // aunque esté cerrada, que es lo que se espera de un aviso.
  if (nativo()) {
    if (document.visibilityState !== 'visible') {
      avisoNativo(titulo, cuerpo, { ruta: datos.ruta });
    }
    return;
  }
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  if (document.visibilityState === 'visible') return;   // ya lo está viendo
  try {
    const n = new Notification(titulo, {
      body: cuerpo,
      icon: './assets/icons/icono-192.png',
      badge: './assets/icons/icono-192.png',
      tag: datos.tag || 'hurakantick',
      silent: !sonidoActivo(),
    });
    n.onclick = () => {
      window.focus();
      if (datos.ruta) location.hash = datos.ruta;
      n.close();
    };
  } catch { /* algunos navegadores exigen el trabajador de servicio */ }
}
