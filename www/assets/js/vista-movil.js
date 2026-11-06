/* =====================================================================
   Vista smartphone.

   En un ordenador o una tableta, la aplicación puede verse dentro de un
   teléfono centrado en la pantalla. Sirve para que quien trabaja desde el
   despacho vea exactamente lo que ve el técnico en su móvil.

   CÓMO FUNCIONA

   La aplicación no se toca. Se carga entera dentro de un <iframe> en
   marco.html, y el iframe tiene el tamaño de un teléfono. Para ella es
   un teléfono de verdad: su responsive, la barra de abajo, las hojas que
   suben, los avisos… todo se comporta como en un móvil, porque la
   pantalla que ve es la del iframe.

   Hacerlo con CSS —meterla en una caja estrecha— no funciona: las
   reglas @media seguirían viendo el monitor de 1920 px y pintarían el
   diseño de escritorio dentro de un hueco de móvil, y todo lo que va
   fijo a la pantalla se saldría de la caja.

   QUIÉN DECIDE

   El superadministrador fija el modo para todo el hotel:
     no        · como siempre
     todos     · todos en teléfono, sin poder cambiarlo
     activa    · encendida; cada uno puede quitársela
     inactiva  · apagada; cada uno puede ponérsela

   Lo de cada persona se guarda con su apariencia, en su aparato: igual
   que el tamaño de la letra. En el móvil no se nota nada —ya es un
   teléfono— y no tiene sentido arrastrarlo de un aparato a otro.

   REVERSIBLE

   Con el modo en «no», esto no hace nada: marco.html no se carga nunca y
   index.html arranca exactamente igual que antes de que existiera.
   ===================================================================== */

import { apariencia, fijarApariencia } from './apariencia.js';

/** Lo que lee el arranque de index.html antes de cargar nada. */
const CLAVE = 'hurakantick_vista_movil';

/** Alto entre ancho de cada proporción. */
export const RATIOS = {
  '9:16':   16 / 9,
  '9:19.5': 19.5 / 9,
  '9:20':   20 / 9,
};

const MODOS = ['no', 'todos', 'activa', 'inactiva'];

let ultimosAjustes = null;
let escuchando = false;

/* ------------------------------------------------------------------ */
/* Qué toca                                                            */
/* ------------------------------------------------------------------ */

/**
 * El estado que vale para esta persona en este aparato.
 *
 * @returns {{ modo, on, ratio, puede, ratioHotel }}
 *   · on     — si se ve en teléfono
 *   · puede  — si la persona puede cambiarlo ella misma
 */
export function estadoEfectivo(ajustes) {
  const modo = MODOS.includes(ajustes?.vista_movil_modo) ? ajustes.vista_movil_modo : 'no';
  const ratioHotel = RATIOS[ajustes?.vista_movil_ratio] ? ajustes.vista_movil_ratio : '9:19.5';
  const a = apariencia();

  const puede = modo === 'activa' || modo === 'inactiva';

  let on = false;
  if (modo === 'todos') {
    on = true;
  } else if (puede) {
    // Si la persona no ha dicho nada, manda lo que diga el hotel.
    on = a.vistaMovil === 'si' || a.vistaMovil === 'no'
      ? a.vistaMovil === 'si'
      : modo === 'activa';
  }

  // Quien no puede elegir, usa la del hotel.
  const ratio = puede && RATIOS[a.vistaMovilRatio] ? a.vistaMovilRatio : ratioHotel;

  return { modo, on, ratio, puede, ratioHotel };
}

/** La aplicación de Android: nunca se enmarca. Dentro de un iframe
    perdería el puente con el teléfono —cámara, avisos—, y además ya es
    un teléfono. */
export function esNativo() {
  return !!(window.Capacitor && (window.Capacitor.isNativePlatform?.() ?? true));
}

/** ¿Merece la pena el marco? En un móvil no: ya es un teléfono, también
    tumbado. Se mira el lado corto de la pantalla física, que en un móvil
    no pasa de 500 aunque se ponga en horizontal. */
export function pantallaGrande() {
  /* Dentro del marco, el ancho que se ve es el del teléfono dibujado, no
     el de la pantalla: 372 px en un monitor de 1440. Si estamos en el
     marco es precisamente porque la pantalla es grande. Sin esto, la
     pantalla de Apariencia decía «en este aparato no cambia nada» a quien
     estaba mirando el marco en su ordenador. */
  if (dentroDelMarco()) return true;

  const corto = Math.min(window.screen?.width || 0, window.screen?.height || 0);
  return corto >= 600 && window.innerWidth >= 700;
}

/** ¿Estamos dentro de NUESTRO marco? (Y no incrustados en otra web.) */
export function dentroDelMarco() {
  try {
    return window.top !== window.self
      && /\/marco\.html$/.test(window.top.location.pathname);
  } catch {
    return false;   // otro origen: no es nuestro marco
  }
}

/* ------------------------------------------------------------------ */
/* Aplicar                                                             */
/* ------------------------------------------------------------------ */

/**
 * Guarda lo que toca y, si hace falta, cambia de modo.
 *
 * Se llama al arrancar (con el catálogo ya cargado) y cada vez que la
 * persona toca su ajuste. Devuelve el estado para que la pantalla de
 * Apariencia lo pueda pintar.
 */
export function aplicarVistaMovil(ajustes) {
  if (ajustes) ultimosAjustes = ajustes;
  const e = estadoEfectivo(ultimosAjustes);

  escucharAlMarco();

  const guardar = JSON.stringify({
    on: e.on,
    ratio: e.ratio,
    puede: e.puede,
    modo: e.modo,
    color: ultimosAjustes?.org_color || '#0B5563',
  });

  let antes = null;
  try {
    antes = localStorage.getItem(CLAVE);
    /* Al cambiar este valor, el marco se entera solo: el navegador avisa
       con el evento «storage» a las demás páginas del mismo sitio, y el
       marco es otra página. */
    localStorage.setItem(CLAVE, guardar);
  } catch { /* sin almacenamiento: el marco no podrá saberlo, se queda como esté */ }

  if (esNativo()) return e;

  const arriba = window.top === window.self;

  if (e.on && arriba && pantallaGrande()) {
    // Entrar en el marco, conservando dónde se estaba.
    e.cambiando = true;
    location.replace('marco.html' + location.search + location.hash);
  } else if (!e.on && dentroDelMarco() && antes === guardar) {
    e.cambiando = true;
    /* Salir del marco. Normalmente lo hace el propio marco al ver el
       cambio; esto es por si el valor guardado ya era ese —un marcador
       viejo, por ejemplo— y por tanto no hubo aviso. */
    window.top.location.replace('index.html' + location.search + location.hash);
  }

  return e;
}

/** Lo que pide la persona desde su pantalla de Apariencia. */
export function fijarVistaMovil(cambios) {
  fijarApariencia(cambios);
  return aplicarVistaMovil();
}

/* ------------------------------------------------------------------ */
/* El botón «Pantalla completa» del marco                              */
/* ------------------------------------------------------------------ */

/* El marco está fuera de la aplicación y no puede guardar preferencias:
   manda un mensaje y la aplicación hace lo mismo que si la persona lo
   apagara desde Apariencia. Así sólo hay un sitio que lo decide. */
function escucharAlMarco() {
  if (escuchando) return;
  escuchando = true;

  window.addEventListener('message', (ev) => {
    if (ev.origin !== location.origin || ev.source !== window.parent) return;
    const d = ev.data;
    if (!d || d.tipo !== 'hurakantick-vista-movil' || d.accion !== 'salir') return;

    // Sólo si de verdad puede: en «todos» el botón ni sale, pero no se
    // fía uno de que nadie mande el mensaje a mano.
    if (!estadoEfectivo(ultimosAjustes).puede) return;
    fijarVistaMovil({ vistaMovil: 'no' });
  });
}
