/* =====================================================================
   Iconos y utilidades de interfaz.
   Los iconos van incrustados en el propio fichero: sin peticiones de red,
   así la aplicación se ve igual sin cobertura.
   ===================================================================== */

const TRAZOS = {
  panel:      '<path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/>',
  lista:      '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  mios:       '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  /* La conversación de un parte. */
  chat:       '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9 9 0 0 1-3.8-.8L3 21l1.9-5.1A8.4 8.4 0 0 1 4 11.5 8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5Z"/>',
  enviar:     '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  ajustes:    '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
  mas:        '<path d="M12 5v14M5 12h14"/>',
  atras:      '<path d="m15 18-6-6 6-6"/>',
  cerrar:     '<path d="M18 6 6 18M6 6l12 12"/>',
  buscar:     '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  ubicacion:  '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
  reloj:      '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  camara:     '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3.5"/>',
  imagen:     '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.6-4.6a2 2 0 0 0-2.8 0L3 21"/>',
  adjunto:    '<path d="M21.4 11.05 12.25 20.2a5.5 5.5 0 1 1-7.78-7.78l8.49-8.49a3.5 3.5 0 1 1 4.95 4.95l-8.49 8.49a1.5 1.5 0 1 1-2.12-2.12l7.78-7.78"/>',
  nota:       '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
  aviso:      '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
  ok:         '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/>',
  info:       '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
  /* Tres que se usaban sin existir: icono() cae al de información si
     no encuentra el nombre, así que no rompían nada, pero salía una «i»
     donde tenía que haber un candado o un teléfono. */
  candado:    '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  movil:      '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/>',
  'ojo-no':   '<path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/>'
            + '<path d="M6.61 6.61A18.2 18.2 0 0 0 2 12s3 8 10 8a9.1 9.1 0 0 0 5.39-1.61"/>'
            + '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M2 2l20 20"/>',
  copiar:     '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  edificio:   '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
  libro:      '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>'
            + '<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'
            + '<path d="M9 7h7M9 11h5"/>',
  sinred:     '<path d="M2 2l20 20"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 3.6-2.3"/><path d="M2 8.8a15 15 0 0 1 4.2-2.6"/><path d="M15.4 10.6A10 10 0 0 1 19 12.9"/><path d="M10.7 5.1A15 15 0 0 1 22 8.8"/><path d="M12 20h.01"/>',
  sync:       '<path d="M21 12a9 9 0 0 1-9 9 9 9 0 0 1-8-4.9"/><path d="M3 12a9 9 0 0 1 9-9 9 9 0 0 1 8 4.9"/><path d="M20 4v4h-4M4 20v-4h4"/>',
  nube:       '<path d="M18 16.5A4.5 4.5 0 0 0 17.2 8h-1.3A7 7 0 1 0 4 14.9"/><path d="M12 12v9M8.5 17.5 12 21l3.5-3.5"/>',
  salir:      '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
  llave:      '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.6-8.6M17 6l2.5 2.5M14 9l2.5 2.5"/>',
  persona:    '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  personas:   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
  'mas-puntos': '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
  'flecha-arriba': '<path d="m18 15-6-6-6 6"/>',
  'flecha-abajo': '<path d="m6 9 6 6 6-6"/>',
  flecha:     '<path d="m9 18 6-6-6-6"/>',
  filtro:     '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3Z"/>',
  campana:    '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
  papelera:   '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  guardar:    '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
  editar:     '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
  calendario: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
  banderin:   '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1Z"/><path d="M4 22v-7"/>',
  descarga:   '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
  luna:       '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
  llaveinglesa: '<path d="M14.7 6.3a4 4 0 0 0 5 5l-9.4 9.4a2.8 2.8 0 0 1-4-4l9.4-9.4a4 4 0 0 0-1 -1Z"/>',
  /* Elementos del catálogo */
  gota:       '<path d="M12 2.7 6.3 8.4a8 8 0 1 0 11.4 0L12 2.7Z"/>',
  rayo:       '<path d="M13 2 3 14h8l-1 8 10-12h-8l1-8Z"/>',
  martillo:   '<path d="m15 12-8.4 8.4a2 2 0 0 1-2.8-2.8L12 9"/><path d="m18 15 4-4-6.5-6.5-2 2 1.5 1.5-3 3 3 3 3-3 1.5 1.5Z"/>',
  silla:      '<path d="M19 9V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4M3 11v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a2 2 0 0 0-4 0v1H7v-1a2 2 0 0 0-4 0ZM6 16v5M18 16v5"/>',
  viento:     '<path d="M12.8 20a2 2 0 1 0 1.4-3.4H2M17.7 4.6A2.5 2.5 0 1 1 19.5 9H2M9.6 3.5A2 2 0 1 1 11 7H2"/>',
  tele:       '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="m17 2-5 5-5-5"/>',
  wifi:       '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a15 15 0 0 1 20 0M12 20h.01"/>',
  brillo:     '<path d="m12 3 1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3ZM19 15l.9 2.6L22.5 18l-2.6.9L19 21l-.9-2.1L15.5 18l2.6-.4L19 15Z"/>',
  fichero:    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/>',
  archivo:    '<path d="M20.6 7.5 12 12 3.4 7.5"/><path d="M12 22V12"/><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>',
  herramientas: '<path d="M14.7 6.3a4 4 0 0 0 5.3 5.3l-8.6 8.6a2.8 2.8 0 0 1-4-4l8.6-8.6a4 4 0 0 0-1.3-1.3Z"/><path d="m3 6 3-3 4 4-3 3Z"/>',
};

/** Devuelve el marcado SVG de un icono. */
/* ------------------------------------------------------------------ */
/* Iconos cambiados desde Iconografía                                  */
/* ------------------------------------------------------------------ */

/* Cada icono de la aplicación tiene un nombre interno («aviso», «buscar»…).
   Desde Ajustes › Iconografía se puede sustituir cualquiera por uno de
   Font Awesome; aquí se guarda ese mapa —nombre interno → código del
   icono— y icono() lo consulta antes de dibujar el suyo. Vacío, no cambia
   nada: se sigue usando el dibujo de siempre. */
let sustitutos = {};

export function fijarIconosPersonalizados(mapa) {
  sustitutos = mapa && typeof mapa === 'object' ? mapa : {};
}

export function iconoPersonalizado(nombre) {
  return sustitutos[nombre] || null;
}

export function icono(nombre, clases = '') {
  // ¿Lo han cambiado desde Iconografía? Se dibuja el de Font Awesome, del
  // mismo tamaño, para que la pantalla no se mueva ni un píxel.
  /* Un icono guardado como «fa-f0f3» —lo que se elige para una familia
     desde Font Awesome— se dibuja tal cual. */
  const propio = /^fa-([0-9a-f]{1,5})$/i.exec(String(nombre || ''));
  if (propio) {
    return `<span class="fa ${clases}" aria-hidden="true">&#x${propio[1]};</span>`;
  }

  const fa = sustitutos[nombre];
  if (fa) {
    return `<span class="fa ${clases}" aria-hidden="true">&#x${esc(fa)};</span>`;
  }
  const t = TRAZOS[nombre] || TRAZOS.info;
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
    stroke-linecap="round" stroke-linejoin="round" class="${clases}" aria-hidden="true">${t}</svg>`;
}

/** Los nombres internos de todos los iconos, para la pantalla de Iconografía. */
export function nombresDeIconos() {
  return Object.keys(TRAZOS).sort();
}

export const ICONOS_ELEMENTO = {
  gota: 'gota', rayo: 'rayo', martillo: 'martillo', silla: 'silla', viento: 'viento',
  tele: 'tele', llave: 'llave', wifi: 'wifi', brillo: 'brillo',
  'llave-inglesa': 'llaveinglesa', wrench: 'llaveinglesa',
};

/* --------------------------------------------------------------- */
/* Utilidades del DOM                                              */
/* --------------------------------------------------------------- */
export const $  = (sel, raiz = document) => raiz.querySelector(sel);
export const $$ = (sel, raiz = document) => Array.from(raiz.querySelectorAll(sel));

/** Escapa texto que venga de la base de datos antes de insertarlo. */
export function esc(v) {
  if (v === null || v === undefined) return '';
  return String(v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* --------------------------------------------------------------- */
/* Fechas: siempre se guardan en UTC y se muestran en local         */
/* --------------------------------------------------------------- */
function aFecha(v) {
  if (!v) return null;
  if (v instanceof Date) return v;
  const s = String(v).trim();
  // 'YYYY-MM-DD HH:MM:SS' del servidor se interpreta como UTC
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
  if (m) {
    const [, a, mes, dia, h, mi, se] = m.map(Number);

    /* Date.UTC no valida: con mes 0 y día 0 se va a noviembre del año
       anterior, y con mes 13 salta al siguiente. Una fecha imposible
       —«0000-00-00», que llega de bases antiguas de MySQL— acababa
       apareciendo como «30 nov» sin que nadie entendiera de dónde salía.
       Mejor no mostrar nada que mostrar un día inventado. */
    if (a < 1900 || mes < 1 || mes > 12 || dia < 1 || dia > 31
        || h > 23 || mi > 59 || se > 59) {
      return null;
    }
    const d = new Date(Date.UTC(a, mes - 1, dia, h, mi, se));
    // Y se comprueba que el día no se haya desbordado (31 de febrero).
    return d.getUTCDate() === dia && d.getUTCMonth() === mes - 1 ? d : null;
  }
  const d = new Date(s);
  return isNaN(d) ? null : d;
}

export function haceCuanto(v) {
  const d = aFecha(v);
  if (!d) return '';
  const seg = Math.round((Date.now() - d.getTime()) / 1000);

  /* Fechas en el futuro.
     Un reloj mal puesto en un móvil, una migración con fechas raras o un
     plazo escrito a mano bastan para que aquí llegue algo posterior a
     hoy. Sin esto salía «ahora», y un parte de dentro de tres años
     aparecía como recién creado en lo alto del listado. */
  if (seg < -60) {
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  if (seg < 45) return 'ahora';
  if (seg < 3600) return `hace ${Math.round(seg / 60)} min`;
  if (seg < 86400) return `hace ${Math.round(seg / 3600)} h`;
  const dias = Math.round(seg / 86400);
  if (dias === 1) return 'ayer';
  if (dias < 7) return `hace ${dias} días`;
  return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
}

/* ------------------------------------------------------------------ */
/* Horas: dentro todo es universal, fuera todo es la hora de aquí      */
/* ------------------------------------------------------------------ */

/**
 * La hora que ha escrito la persona, pasada a hora universal.
 *
 * Todo lo que guarda el servidor está en hora universal y se enseña ya
 * convertido a la de aquí. Al escribir una fecha hay que hacer el camino
 * contrario: si no, pasa lo que pasaba con los partes programados —se
 * ponía «13:05» y el parte quedaba a las 15:05, dos horas más tarde, que
 * es justo la diferencia de España en verano—.
 *
 * Admite «2026-10-30T13:05» o la fecha y la hora por separado.
 */
export function aUtcDesdeLocal(fecha, hora = null) {
  if (!fecha) return null;

  const [dia, resto] = String(fecha).includes('T')
    ? String(fecha).split('T')
    : [String(fecha), hora || '00:00'];

  const [a, m, d] = dia.split('-').map(Number);
  const [h, mi] = String(resto || '00:00').split(':').map(Number);
  if (!a || !m || !d) return null;

  // Construida en la hora de aquí; toISOString la devuelve en universal.
  const cuando = new Date(a, m - 1, d, h || 0, mi || 0, 0);
  if (Number.isNaN(cuando.getTime())) return null;

  return cuando.toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Al revés: lo guardado, listo para meterlo en un campo de fecha y hora.
 *
 * Los campos del navegador trabajan siempre en la hora de aquí, así que
 * enseñarles el texto del servidor tal cual mostraba dos horas de menos.
 */
export function aLocalParaCampo(utc, conHora = true) {
  const d = aFecha(utc);
  if (!d) return '';
  const dos = (n) => String(n).padStart(2, '0');
  const dia = `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`;
  return conHora ? `${dia}T${dos(d.getHours())}:${dos(d.getMinutes())}` : dia;
}

export function fechaLarga(v) {
  const d = aFecha(v);
  if (!d) return '—';
  return d.toLocaleString('es-ES', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

export function fechaCorta(v) {
  const d = aFecha(v);
  if (!d) return '—';
  return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' });
}

export function restante(v) {
  const d = aFecha(v);
  if (!d) return null;
  const h = Math.round((d.getTime() - Date.now()) / 3600000);
  if (h < 0) return { vencido: true, texto: `${Math.abs(h) < 48 ? Math.abs(h) + ' h' : Math.round(Math.abs(h) / 24) + ' d'} de retraso` };
  if (h < 24) return { vencido: false, texto: `quedan ${h} h` };
  return { vencido: false, texto: `quedan ${Math.round(h / 24)} d` };
}

export function iniciales(nombre) {
  if (!nombre) return '?';
  const p = String(nombre).trim().split(/\s+/);
  return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
}

export function minutosATexto(m) {
  if (!m) return '—';
  const h = Math.floor(m / 60), r = m % 60;
  return h ? `${h} h${r ? ' ' + r + ' min' : ''}` : `${r} min`;
}

/* --------------------------------------------------------------- */
/* Mensajes emergentes                                             */
/* --------------------------------------------------------------- */
export function mensaje(texto, tipo = 'info', accion = null) {
  const cajon = $('#mensajes');
  if (!cajon) return;
  const el = document.createElement('div');
  el.className = `mensaje mensaje--${tipo}`;
  el.innerHTML = `${icono(tipo === 'ok' ? 'ok' : tipo === 'error' ? 'aviso' : 'info')}<span>${esc(texto)}</span>`;
  if (accion) {
    const b = document.createElement('button');
    b.className = 'mensaje__accion';
    b.textContent = accion.texto;
    b.onclick = () => { accion.al(); el.remove(); };
    el.appendChild(b);
  }
  cajon.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity 200ms, transform 200ms';
    el.style.opacity = '0';
    el.style.transform = 'translateY(8px)';
    setTimeout(() => el.remove(), 220);
  }, accion ? 6000 : 3400);
}

/* --------------------------------------------------------------- */
/* Hoja emergente                                                  */
/* --------------------------------------------------------------- */
/**
 * Pila de hojas.
 *
 * Se apilan porque unas abren a otras: la de ubicación abre la del
 * buscador, la de edición abre la del tipo de avería. Si al abrir una
 * se cerrara la anterior, su promesa se resolvería con null y el usuario
 * perdería lo que llevaba elegido.
 */
const pilaHojas = [];

/** ¿Lleva este panel un buscador? Entonces va arriba. */
function marcarBuscador(h) {
  const conBuscador = h.querySelector('input[type="search"], [data-buscador]');
  h.classList.toggle('hoja--arriba', !!conBuscador);
  if (conBuscador) seguirAltoVisible();
}

/* El alto que de verdad se ve, que con el teclado abierto es mucho menos
   que la pantalla. El panel de arriba no pasa de ahí, para que su lista
   de resultados se pueda recorrer entera sin meterse bajo el teclado. */
let siguiendoAlto = false;
function seguirAltoVisible() {
  if (siguiendoAlto || !window.visualViewport) return;
  siguiendoAlto = true;
  const fijar = () => document.documentElement.style.setProperty(
    '--alto-visible', `${Math.round(window.visualViewport.height)}px`);
  window.visualViewport.addEventListener('resize', fijar);
  fijar();
}

/* ---------------------------------------------------------------- */
/* El teclado del móvil                                             */
/* ---------------------------------------------------------------- */

/**
 * Cuánto ocupa el teclado, publicado como variable CSS.
 *
 * Sin esto, al tocar un campo dentro de una hoja el teclado tapa los
 * botones de abajo —justo «Guardar»— y hay que cerrar el teclado a
 * ciegas para poder pulsar. El navegador no lo dice directamente, pero
 * la diferencia entre la ventana y lo que queda visible es exactamente
 * el hueco que ocupa.
 */
function vigilarTeclado() {
  const vv = window.visualViewport;
  if (!vv) return () => {};

  const medir = () => {
    const alto = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    // Por debajo de 120px suele ser la barra del navegador, no el teclado.
    document.documentElement.style.setProperty('--teclado', (alto > 120 ? alto : 0) + 'px');
  };

  medir();
  vv.addEventListener('resize', medir);
  vv.addEventListener('scroll', medir);
  return () => {
    vv.removeEventListener('resize', medir);
    vv.removeEventListener('scroll', medir);
    document.documentElement.style.setProperty('--teclado', '0px');
  };
}

/**
 * Una hoja (panel) modal.
 *
 * `antesDeCerrar` permite vetar el cierre: si devuelve false —o una
 * promesa que resuelve false—, la hoja NO se cierra. Hace falta para el
 * editor de manuales: alguien escribe veinte pasos, pulsa Escape por
 * reflejo o toca fuera del panel, y hasta ahora lo perdía todo sin una
 * sola pregunta.
 */
export function hoja({ titulo, cuerpo, pie, alAbrir, alCerrar, antesDeCerrar }) {
  const soltarTeclado = vigilarTeclado();
  const nivel = pilaHojas.length;

  const velo = document.createElement('div');
  velo.className = 'velo';
  velo.style.zIndex = String(60 + nivel * 2);

  const h = document.createElement('div');
  h.className = 'hoja';
  h.style.zIndex = String(61 + nivel * 2);
  h.setAttribute('role', 'dialog');
  h.setAttribute('aria-modal', 'true');
  h.innerHTML = `
    <div class="hoja__asa"></div>
    <div class="hoja__cabecera">
      <h2>${esc(titulo)}</h2>
      <button type="button" class="icono-btn" data-cerrar aria-label="Cerrar">${icono('cerrar')}</button>
    </div>
    <div class="hoja__cuerpo"></div>
    ${pie ? '<div class="hoja__pie"></div>' : ''}`;

  const cuerpoEl = $('.hoja__cuerpo', h);
  if (typeof cuerpo === 'string') cuerpoEl.innerHTML = cuerpo;
  else if (cuerpo) cuerpoEl.appendChild(cuerpo);

  if (pie) {
    const pieEl = $('.hoja__pie', h);
    if (typeof pie === 'string') pieEl.innerHTML = pie;
    else pieEl.appendChild(pie);
  }

  /* Los paneles con buscador salen ARRIBA en el móvil.
   *
   * Desde abajo, al escribir sube el teclado y tapa justo la mitad de
   * abajo del panel, que es donde aparecen los resultados: se busca
   * «301», el resultado está detrás del teclado, y la persona cree que no
   * hay nada. Arriba, el campo y los primeros resultados quedan siempre a
   * la vista. En el ordenador el panel es una ventana centrada y no cambia. */
  marcarBuscador(h);

  document.body.appendChild(velo);
  document.body.appendChild(h);
  document.body.style.overflow = 'hidden';

  /* Quién tenía el foco antes, para devolvérselo al cerrar. */
  const focoPrevio = document.activeElement;

  requestAnimationFrame(() => {
    velo.classList.add('visible');
    h.classList.add('visible');

    /* El foco entra en la hoja.
       Sin esto se quedaba fuera: quien navega con teclado abría una hoja
       y el tabulador seguía recorriendo la pantalla de detrás, sin
       manera de llegar a las opciones que acababa de pedir. */
    const primero = h.querySelector(
      'input:not([type=hidden]), select, textarea, .eleccion__item, [data-si], button');
    (primero || h).focus({ preventScroll: true });
  });

  let cerrada = false;
  const cerrar = () => {
    if (cerrada) return;
    cerrada = true;

    const i = pilaHojas.findIndex((x) => x.el === h);
    if (i !== -1) pilaHojas.splice(i, 1);

    velo.classList.remove('visible');
    h.classList.remove('visible');
    setTimeout(() => { velo.remove(); h.remove(); }, 240);

    if (!pilaHojas.length) document.body.style.overflow = '';
    document.removeEventListener('keydown', alPulsar);

    // El foco vuelve al botón que abrió la hoja: si no, se pierde al
    // principio de la página y hay que recorrerla otra vez entera.
    if (focoPrevio && document.contains(focoPrevio)) {
      try { focoPrevio.focus({ preventScroll: true }); } catch { /* ya no está */ }
    }

    soltarTeclado();
    if (alCerrar) alCerrar();
  };

  /* El cierre de verdad pasa por aquí. Si hay veto se le pregunta
     primero, y se vuelve a comprobar que la hoja sigue siendo la de
     arriba: mientras se contestaba la pregunta pudo abrirse otra. */
  const cerrarSiSePuede = async () => {
    if (antesDeCerrar) {
      let sigue = true;
      try { sigue = await antesDeCerrar(); }
      catch { sigue = true; }   // si el veto falla, no se atranca la hoja
      if (sigue === false) return;
    }
    cerrar();
  };

  // Escape cierra sólo la de arriba, no toda la pila.
  const alPulsar = (e) => {
    /* El tabulador da la vuelta dentro de la hoja.
       Una hoja es modal: lo de detrás no se puede tocar con el ratón, y
       tampoco debería alcanzarse con el teclado. Si no, el foco
       desaparece detrás del velo y no hay forma de saber dónde está. */
    if (e.key === 'Tab' && pilaHojas[pilaHojas.length - 1]?.el === h) {
      const dentro = [...h.querySelectorAll(
        'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]),'
        + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((el) => el.offsetParent !== null);
      if (!dentro.length) return;

      const primero = dentro[0];
      const ultimo  = dentro[dentro.length - 1];

      if (!h.contains(document.activeElement)) {
        e.preventDefault();
        (e.shiftKey ? ultimo : primero).focus();
        return;
      }
      if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      } else if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault();
        ultimo.focus();
      }
      return;
    }

    if (e.key !== 'Escape') return;
    if (pilaHojas[pilaHojas.length - 1]?.el !== h) return;
    e.stopPropagation();
    cerrarSiSePuede();
  };

  velo.onclick = cerrarSiSePuede;
  $('[data-cerrar]', h).onclick = cerrarSiSePuede;
  document.addEventListener('keydown', alPulsar);

  const ficha = { el: h, cerrar };
  pilaHojas.push(ficha);

  /* Preparar la ventana no puede fallar en silencio.
     «alAbrir» es donde se enganchan los botones del pie. Si algo revienta
     a mitad —una variable que no existe, un campo que no está—, lo de
     arriba ya se enganchó y lo de abajo no: queda un formulario con una
     pinta perfecta cuyo botón «Guardar» no hace NADA. Ni manda, ni avisa.
     Eso fue exactamente lo que pasó con el alta de personas, y no había
     forma de verlo desde fuera.

     Así que si falla: se dice en la propia ventana, se deja el error en el
     registro para que salga en la pantalla de Errores, y se desactivan los
     botones del pie en vez de dejarlos ahí aparentando que funcionan. */
  if (alAbrir) {
    try {
      alAbrir(h, cerrar);
    } catch (e) {
      const pieHtml = h.querySelector('.hoja__pie');
      if (pieHtml) {
        pieHtml.querySelectorAll('button').forEach((b) => {
          if (b.dataset.no === undefined) { b.disabled = true; }
        });
      }
      const caja = h.querySelector('.hoja__cuerpo') || h;
      const aviso = document.createElement('div');
      aviso.className = 'aviso aviso--error';
      /* Marca propia: el contenido de una instrucción también usa
         «aviso--error» para su aviso de seguridad, y la prueba de los
         formularios confundía «Nunca mezcles cloro y reductor de pH»
         con una ventana rota. */
      aviso.dataset.hojaRota = '1';
      aviso.innerHTML = '<div><b>Esta ventana no se ha podido preparar.</b><br>'
        + 'No la uses: no guardaría nada. Ciérrala y avisa de que ha pasado '
        + 'esto; queda apuntado en el registro de errores.</div>';
      caja.prepend(aviso);
      /* Y que llegue al registro de errores por el camino de siempre: se
         vuelve a lanzar fuera de este try, donde lo recoge el vigilante
         global de app.js. Así no hay dos maneras distintas de apuntar un
         fallo, que es como una de las dos se queda sin mantener. */
      console.error('hoja/alAbrir', e);
      setTimeout(() => { throw e; }, 0);
    }
  }
  return ficha;
}

/** Cierra la hoja de arriba de la pila. */
export function cerrarHoja() {
  pilaHojas[pilaHojas.length - 1]?.cerrar();
}

/** Cierra todas: se usa al navegar a otra pantalla. */
export function cerrarTodasLasHojas() {
  while (pilaHojas.length) pilaHojas[pilaHojas.length - 1].cerrar();
  document.querySelectorAll('.visor').forEach((v) => v.remove());
  document.body.style.overflow = '';
}

/** Lista de opciones en hoja. Devuelve el valor elegido o null. */
export function elegir({ titulo, opciones, valor }) {
  return new Promise((resolve) => {
    let resuelto = false;
    const lista = document.createElement('div');
    lista.className = 'eleccion';
    opciones.forEach((o) => {
      const b = document.createElement('button');
      b.className = 'eleccion__item';
      b.setAttribute('aria-selected', String(o.valor === valor));
      b.innerHTML = `${o.icono ? icono(o.icono) : (o.color ? `<span class="avatar" style="background:${esc(o.color)}22;color:${esc(o.color)}">●</span>` : '')}
        <span><strong style="font-weight:inherit">${esc(o.texto)}</strong>${o.detalle ? `<small>${esc(o.detalle)}</small>` : ''}</span>`;
      b.onclick = () => { resuelto = true; resolve(o.valor); cerrarHoja(); };
      lista.appendChild(b);
    });
    hoja({ titulo, cuerpo: lista, alCerrar: () => { if (!resuelto) resolve(null); } });
  });
}

/**
 * Selector con buscador, para catálogos grandes.
 *
 * La instalación real tiene 313 tipos de avería y 1.285 ubicaciones: una
 * rejilla de iconos o un carril de pastillas es inservible con esos
 * volúmenes. Aquí se escribe y se filtra.
 *
 * opciones: [{valor, texto, detalle, extra, icono, destacado}]
 *   · destacado = true lo coloca arriba mientras no se busque nada
 *   · extra es texto adicional por el que también se puede buscar
 *     (por ejemplo la abreviatura "A/A" de aire acondicionado)
 */
/* «siempre»: opciones que no se filtran y se ven debajo de los resultados,
   también cuando no hay ninguno. Es la salida para quien no encuentra lo
   que busca: sin ella, «No hay ningún tipo con ese nombre» era un callejón
   sin salida y había que borrar lo escrito para volver a ver algo útil. */
export function elegirBuscando({ titulo, opciones, valor, marcador = 'Escribe para filtrar', vacio = 'Nada coincide', limite = 60, siempre = [] }) {
  return new Promise((resolve) => {
    let resuelto = false;

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="buscador" style="margin-bottom:10px">
        ${icono('buscar')}
        <input class="entrada" type="search" id="b-filtro" placeholder="${esc(marcador)}"
               autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="done">
        <button type="button" class="buscador__limpiar oculto" id="b-limpiar" aria-label="Limpiar">${icono('cerrar')}</button>
      </div>
      <div class="rotulo" id="b-cuenta" style="margin:0 2px 6px"></div>
      <div class="eleccion" id="b-lista"></div>`;

    const normaliza = (t) => String(t || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    function pintar(consulta) {
      const q = normaliza(consulta).trim();
      const partes = q ? q.split(/\s+/) : [];

      let lista = opciones;
      if (partes.length) {
        lista = opciones.filter((o) => {
          const heno = normaliza(o.texto + ' ' + (o.detalle || '') + ' ' + (o.extra || ''));
          return partes.every((t) => heno.includes(t));
        });
        // Lo que empieza por lo escrito va primero.
        lista = lista.slice().sort((a, b) => {
          const ia = normaliza(a.texto).startsWith(partes[0]) ? 0 : 1;
          const ib = normaliza(b.texto).startsWith(partes[0]) ? 0 : 1;
          return ia - ib || a.texto.localeCompare(b.texto, 'es');
        });
      } else {
        const arriba = opciones.filter((o) => o.destacado);
        const resto  = opciones.filter((o) => !o.destacado);
        lista = [...arriba, ...resto];
      }

      const cuenta = cuerpo.querySelector('#b-cuenta');
      cuenta.textContent = lista.length > limite
        ? `${lista.length} resultados · se muestran ${limite}`
        : `${lista.length} ${lista.length === 1 ? 'resultado' : 'resultados'}`;

      const caja = cuerpo.querySelector('#b-lista');
      const opcion = (o) => `
        <button type="button" class="eleccion__item" data-v="${esc(o.valor)}" aria-selected="${o.valor === valor}">
          ${o.icono ? icono(o.icono) : ''}
          <span>${esc(o.texto)}${o.detalle ? `<small>${esc(o.detalle)}</small>` : ''}</span>
          ${o.extra ? `<span class="mono" style="margin-left:auto;font-size:11px;color:var(--tinta-3)">${esc(o.extra)}</span>` : ''}
        </button>`;
      const fijas = siempre.length
        ? `<div class="rotulo" style="padding:14px 4px 6px">${partes.length ? '¿No lo encuentras?' : 'También'}</div>`
          + siempre.map(opcion).join('')
        : '';

      caja.innerHTML = (lista.length
        ? lista.slice(0, limite).map(opcion).join('')
        : `<p style="padding:18px 4px 6px;color:var(--tinta-3);font-size:13.5px;text-align:center">${esc(vacio)}</p>`)
        + fijas;

      caja.querySelectorAll('[data-v]').forEach((b) => {
        b.onclick = () => {
          const bruto = b.dataset.v;
          const elegida = [...lista, ...siempre].find((o) => String(o.valor) === bruto);
          resuelto = true;
          resolve(elegida ? elegida.valor : null);
          cerrarHoja();
        };
      });
    }

    hoja({
      titulo, cuerpo,
      alAbrir: (h) => {
        const filtro = $('#b-filtro', h);
        const limpiar = $('#b-limpiar', h);
        let reloj = null;

        filtro.oninput = () => {
          limpiar.classList.toggle('oculto', !filtro.value);
          clearTimeout(reloj);
          reloj = setTimeout(() => pintar(filtro.value), 110);
        };
        limpiar.onclick = () => { filtro.value = ''; limpiar.classList.add('oculto'); pintar(''); filtro.focus(); };
        filtro.onkeydown = (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            const primero = $('#b-lista .eleccion__item', h);
            if (primero) primero.click();
          }
        };

        pintar('');

        /* El teclado, puesto, también en el móvil.
         *
         * Antes se saltaba por miedo a tapar la lista, y era un error de
         * criterio: un panel de búsqueda no se abre por casualidad —lo
         * abres para escribir— y encontrabas la lista entera y ningún
         * sitio donde teclear. Las pantallas con buscador arriba son otra
         * cosa y siguen sin abrirlo solas.
         *
         * El panel ya se apoya encima del teclado, así que no tapa nada. */
        if (window.foco_buscador !== false) {
          setTimeout(() => { try { filtro.focus(); } catch { /* da igual */ } }, 240);
        }
      },
      alCerrar: () => { if (!resuelto) resolve(null); },
    });
  });
}

/** Confirmación con dos botones. */
export function confirmar({ titulo, texto, aceptar = 'Continuar', peligro = false }) {
  return new Promise((resolve) => {
    let resuelto = false;
    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
      <button type="button" class="btn ${peligro ? 'btn--peligro' : 'btn--principal'}" data-si>${esc(aceptar)}</button>`;
    hoja({
      titulo,
      cuerpo: `<p style="color:var(--tinta-2);font-size:14.5px;margin:0">${esc(texto)}</p>`,
      pie,
      alAbrir: (h) => {
        $('[data-no]', h).onclick = () => { resuelto = true; resolve(false); cerrarHoja(); };
        $('[data-si]', h).onclick = () => { resuelto = true; resolve(true); cerrarHoja(); };
      },
      alCerrar: () => { if (!resuelto) resolve(false); },
    });
  });
}

/* --------------------------------------------------------------- */
/* Visor de fotografías                                            */
/* --------------------------------------------------------------- */
export function verFoto(src) {
  const v = document.createElement('div');
  v.className = 'visor';
  v.innerHTML = `<img src="${esc(src)}" alt="Fotografía del parte">
    <button type="button" class="visor__cerrar" aria-label="Cerrar">${icono('cerrar')}</button>`;
  v.onclick = () => v.remove();
  document.body.appendChild(v);
}

/**
 * Descarga un fichero generado en el propio móvil. Se usa para el listado
 * en CSV: funciona sin conexión porque los datos ya están en IndexedDB.
 * En Android empaquetado, si el navegador no permite descargar, se ofrece
 * compartir, que es lo que la gente hace de todos modos.
 */
export async function descargarFichero(nombre, contenido, tipo = 'text/plain;charset=utf-8') {
  const blob = new Blob(['\ufeff', contenido], { type: tipo });

  if (navigator.canShare && navigator.canShare({ files: [new File([blob], nombre, { type: tipo })] })) {
    try {
      await navigator.share({ files: [new File([blob], nombre, { type: tipo })], title: nombre });
      return true;
    } catch (e) {
      if (e.name === 'AbortError') return false;
    }
  }

  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
  return true;
}

/** Convierte filas a CSV con punto y coma, que es lo que espera Excel en español. */
/* Lo que Excel o LibreOffice toman por una fórmula al abrir un CSV.
 *
 * Un parte titulado =HYPERLINK("http://…";"Pulsa aquí") salía en el Excel
 * del administrador como un enlace vivo, y =WEBSERVICE(…) puede mandar el
 * contenido de otras celdas fuera. Lo escribe cualquiera que dé un parte,
 * recepción incluida. Lo que empiece así va con un apóstrofo delante: es
 * la forma reconocida de decirle a la hoja «esto es texto». Un número
 * negativo de verdad (-3, -2,5) no se toca. */
const PARECE_FORMULA = /^[=+\-@\t\r]/;
const ES_NUMERO = /^-?\d+(?:[.,]\d+)?$/;

export function aCsv(cabeceras, filas) {
  const campo = (v) => {
    let t = v === null || v === undefined ? '' : String(v);
    if (typeof v !== 'number' && PARECE_FORMULA.test(t) && !ES_NUMERO.test(t)) t = "'" + t;
    return /[";\n\r\t]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  };
  return [cabeceras, ...filas].map((f) => f.map(campo).join(';')).join('\r\n');
}

/** Barra de progreso en línea (para cargas). */
export function huesos(n = 4) {
  return Array.from({ length: n }, () => '<div class="hueso hueso--parte"></div>').join('');
}

/* ------------------------------------------------------------------ */
/* Buscador de filas                                                   */
/* ------------------------------------------------------------------ */

/** Sin tildes y en minúsculas: «configuracion» encuentra «Configuración». */
/**
 * El plazo, en lo que entiende una persona.
 *
 * «Baja · 168h» obliga a dividir entre 24 para saber que es una semana.
 * Por dentro no cambia nada: las horas siguen siendo las que manda el
 * servidor y los cálculos de plazo son los mismos; esto es sólo el texto.
 */
export function plazoCorto(horas) {
  const h = Number(horas);
  if (!h || h <= 0) return '';
  if (h % 24 === 0) {
    const d = h / 24;
    if (d % 7 === 0 && d >= 14) return `${d / 7} sem`;
    return `${d}d`;
  }
  return `${h}h`;
}

/** Lo mismo, escrito largo: «7 días», «72 horas». */
export function plazoLargo(horas) {
  const h = Number(horas);
  if (!h || h <= 0) return '';
  if (h % 24 === 0) {
    const d = h / 24;
    return `${d} ${d === 1 ? 'día' : 'días'}`;
  }
  return `${h} ${h === 1 ? 'hora' : 'horas'}`;
}

export function textoPlano(t) {
  return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

/**
 * Pone un buscador encima de una pantalla hecha de secciones y filas, y
 * esconde lo que no coincide.
 *
 * Las pantallas de Ajustes y de Configuración crecieron hasta el punto de
 * que las cosas no se encontraban: la vista smartphone, al final de
 * Configuración, era invisible para quien no bajara hasta abajo.
 *
 * @param contenedor  donde están las filas
 * @param opciones.extra  función (texto) → HTML con resultados de otras
 *                        pantallas, que se pinta encima de la lista
 */
/**
 * Pone el foco en un buscador.
 *
 * Quien abre una pantalla con buscador viene a buscar: obligarle a tocar
 * el campo antes de escribir es un gesto de más, y en el escritorio le
 * hace soltar el teclado. En móvil no se hace: abriría el teclado y
 * taparía media pantalla antes de que dé tiempo a ver qué hay.
 */
export function enfocarBuscador(campo) {
  // En las pantallas con lista el teclado no sale solo en el móvil:
  // taparía justo lo que se ha venido a mirar.
  if (!campo || window.foco_buscador === false || 'ontouchstart' in window) return;
  setTimeout(() => {
    try { campo.focus({ preventScroll: true }); } catch { /* da igual */ }
  }, 60);
}

export function buscadorFilas(contenedor, { placeholder = 'Buscar…', extra = null } = {}) {
  const caja = document.createElement('div');
  caja.className = 'buscador-filas';
  caja.innerHTML = `
    <span class="buscador-filas__lupa">${icono('buscar')}</span>
    <input type="search" class="entrada" placeholder="${esc(placeholder)}"
           autocomplete="off" aria-label="${esc(placeholder)}">
    <div class="buscador-filas__extra"></div>
    <p class="buscador-filas__nada oculto">Nada con esa palabra.</p>`;
  contenedor.prepend(caja);

  const campo = caja.querySelector('input');

  /* El foco, puesto: quien abre una pantalla con buscador viene a buscar.
     En móvil no, que abriría el teclado y taparía media pantalla antes de
     que a nadie le dé tiempo a ver qué hay. */
  if (!('ontouchstart' in window)) {
    setTimeout(() => { try { campo.focus({ preventScroll: true }); } catch { /* da igual */ } }, 60);
  }

  const zonaExtra = caja.querySelector('.buscador-filas__extra');
  const nada = caja.querySelector('.buscador-filas__nada');
  let reloj = null;

  /* Cada búsqueda lleva número.
     `extra` consulta al servidor la primera vez, así que dos búsquedas
     seguidas pueden volver en cualquier orden y la vieja pintaba encima
     de la nueva: escribías «forzar» y veías el resultado de «forza», o
     ninguno. Sólo pinta la última que se pidió. */
  let turno = 0;

  async function filtrar() {
    const mio = ++turno;
    const q = textoPlano(campo.value.trim());
    const filas = [...contenedor.querySelectorAll('.fila')].filter((f) => !caja.contains(f));

    filas.forEach((f) => f.classList.toggle('oculto', q.length > 0 && !textoPlano(f.innerText).includes(q)));

    // Una sección sin nada visible se esconde entera, con su título.
    let visibles = 0;
    contenedor.querySelectorAll('.filas').forEach((bloque) => {
      if (caja.contains(bloque)) return;
      const hay = [...bloque.querySelectorAll('.fila')].some((f) => !f.classList.contains('oculto'));
      if (hay) visibles += 1;
      bloque.classList.toggle('oculto', q.length > 0 && !hay);
      const titulo = bloque.previousElementSibling;
      if (titulo && titulo.classList.contains('seccion')) {
        titulo.classList.toggle('oculto', q.length > 0 && !hay);
      }
    });
    // Los avisos sueltos estorban mientras se busca.
    contenedor.querySelectorAll(':scope > .aviso').forEach((a) => a.classList.toggle('oculto', q.length > 0));

    let masResultados = false;
    if (extra && q.length >= 2) {
      const html = await extra(q);
      if (mio !== turno) return;   // llegó tarde: ya hay otra búsqueda
      zonaExtra.innerHTML = html || '';
      masResultados = !!html;
    } else {
      zonaExtra.innerHTML = '';
    }

    if (mio !== turno) return;
    nada.classList.toggle('oculto', !(q.length > 0 && visibles === 0 && !masResultados));
  }

  campo.addEventListener('input', () => { clearTimeout(reloj); reloj = setTimeout(filtrar, 140); });
  campo.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { campo.value = ''; filtrar(); }
  });
  return { campo, filtrar };
}
