/* =====================================================================
   Arranque de la aplicación: estado compartido, navegación y armazón.
   ===================================================================== */

import * as db from './db.js';
import { api, token, fijarToken, hayRed, alCambiarRed, comprobarRed, ErrorApi, alCaducarSesion,
         hotelActivo } from './api.js';
import * as sync from './sync.js';
import { aplicar as aplicarApariencia } from './apariencia.js';
import { aplicarVistaMovil } from './vista-movil.js';
import { escucharNativo } from './push.js';
import { $, icono, esc, mensaje, confirmar, cerrarTodasLasHojas, fijarIconosPersonalizados,
} from './ui.js';

import { vistaAcceso }  from './views/acceso.js';
import { vistaPanel }   from './views/panel.js';
import { vistaLista }   from './views/lista.js';
import { vistaTicket }  from './views/ticket.js';
import { vistaNuevo }   from './views/nuevo.js';
import { vistaCuenta }  from './views/cuenta.js';
import { vistaAvisos }  from './views/avisos.js';
import { vistaEquipo }  from './views/equipo.js';
import { vistaConflictos } from './views/conflictos.js';
import { vistaInformes } from './views/informes.js';
import { vistaUbicacion, buscarUbicacion } from './views/ubicacion.js';
import { vistaMantenimiento } from './views/mantenimiento.js';
import { vistaSeguridad } from './views/seguridad.js';
import { vistaInstrucciones } from './views/instrucciones.js';
import { vistaUnidades } from './views/unidades.js';
import { vistaFamilia } from './views/familia.js';
import { vistaApariencia } from './views/apariencia.js';
import { vistaHoteles } from './views/hoteles.js';
import { vistaGestionPartes } from './views/gestion-partes.js';
import { vistaPapelera } from './views/papelera.js';
import { vistaGuiaApi } from './views/guia-api.js';
import { vistaChat } from './views/chat.js';
import { vistaConversaciones } from './views/conversaciones.js';
import { vistaMensajes } from './views/mensajes.js';
import { vistaPersonal } from './views/personal.js';
import { vistaErrores } from './views/errores.js';
import { vistaSapHabitacion, vistaSapConexion, vistaSapEstados } from './views/sap.js';
import { vistaDepartamentos } from './views/departamentos.js';
import { vistaAvisosAjustes } from './views/avisos-ajustes.js';
import { vistaAdmin } from './views/admin.js';
import { vistaIconografia } from './views/iconografia.js';
import { vistaFrases } from './views/frases.js';
import { prepararAudio, tono, permisoNotificaciones } from './sonido.js';
import { iniciarNativo, nativo, alTocarAviso } from './nativo.js';

/* ---------------------------------------------------------------- */
/* Estado compartido                                                */
/* ---------------------------------------------------------------- */
let vistaActual = null;

export const app = {
  usuario: null,
  catalogo: null,
  noLeidas: 0,
  /* Con lo que arranca el listado.
     Abiertos y por prioridad: quien entra en «Partes» quiere ver lo que
     hay pendiente, no un histórico con los cerrados mezclados. Es
     también a lo que devuelve el botón de quitar filtros, para que
     entrar y limpiar den lo mismo. */
  filtros: { abiertos: true, orden: 'prioridad' },

  /* Marca que los filtros vienen de una petición concreta —una cifra del
     inicio, un atajo, un informe— y no de la última vez que se estuvo
     en la pantalla. Sin esto, entrar en «Partes» por la barra devolvía
     lo que se hubiera filtrado hace tres días. */
  filtrosPedidos: false,
  ir,
  /**
   * Actualiza la pantalla actual. Si la vista sabe refrescarse sola, se
   * lo pide a ella; si no, se vuelve a montar entera.
   *
   * La diferencia importa: volver a montarla borra lo que la persona
   * tuviera a medias —una selección múltiple, un filtro desplegado, la
   * posición de la lista— y eso pasaba cada vez que terminaba una
   * sincronización de fondo, sin que nadie lo hubiera pedido.
   */
  refrescar: (porMiCuenta = false) => {
    /* Si la persona está escribiendo, NO se toca la pantalla.
     *
     * Repintar borra lo que tuviera a medias. Da igual que sea una nota
     * de tres líneas o un mensaje largo en la conversación: perder lo
     * escrito por un refresco que nadie ha pedido es de las cosas que
     * más enfadan, con razón.
     *
     * «porMiCuenta» marca los refrescos automáticos —los que vienen de
     * una sincronización de fondo—. Cuando la persona pulsa un botón que
     * actualiza, eso sí se hace siempre: lo ha pedido ella. */
    if (porMiCuenta) {
      // Apagado por la dirección: la pantalla no se mueve sola, y punto.
      if (app.catalogo?.ajustes?.refresco_auto === false) return;

      /* Y sólo donde sirve de algo: el inicio y las listas, que son las
         pantallas que enseñan el estado de muchos partes a la vez. En la
         ficha de un parte, en la conversación o en un formulario, un
         repintado sin pedirlo molesta más de lo que ayuda. */
      const r = rutaActual();
      const refrescables = ['/panel', '/lista', '/mios', '/avisos'];
      if (!refrescables.some((x) => r === x || r.startsWith(x + '?'))) return;

      if (hayAlgoEscribiendose()) {
        refrescoPendiente = true;
        return;
      }
    }
    refrescoPendiente = false;
    if (vistaActual?.refrescar) { vistaActual.refrescar(); return; }
    pintar(rutaActual());
  },
};


/* Un refresco automático que llegó mientras se escribía. Se guarda para
   hacerlo en cuanto la persona suelte el teclado, no para tirarlo. */
let refrescoPendiente = false;

/**
 * ¿Hay alguien escribiendo ahora mismo?
 *
 * Basta con que el foco esté en un campo de texto, o con que haya un
 * campo con algo escrito a medias en la pantalla: un formulario a medio
 * rellenar también se pierde si se repinta.
 */
function hayAlgoEscribiendose() {
  const a = document.activeElement;
  if (a && (a.tagName === 'TEXTAREA'
            || (a.tagName === 'INPUT' && !['checkbox', 'radio', 'button', 'submit'].includes(a.type))
            || a.isContentEditable)) {
    return true;
  }
  return [...document.querySelectorAll('textarea, input[type=text], input[type=search], input:not([type])')]
    .some((c) => (c.value || '').trim() !== '');
}

/* En cuanto deja de escribir, se hace el refresco que quedó esperando. */
document.addEventListener('focusout', () => {
  setTimeout(() => {
    if (refrescoPendiente && !hayAlgoEscribiendose()) app.refrescar(true);
  }, 400);
});

/** ¿Está encendida esta función? Por defecto sí, para no romper nada. */
export function modulo(nombre) {
  const m = app.catalogo?.modulos;
  return m ? m[nombre] !== false : true;
}
export function ajuste(clave, def = null) {
  const a = app.catalogo?.ajustes;
  return a && clave in a ? a[clave] : def;
}

export function estadoDe(id)    { return app.catalogo?.estados?.find((e) => e.id_estado === id); }
export function prioridadDe(id) { return app.catalogo?.prioridades?.find((p) => p.id_prioridad === id); }
export function elementoDe(id)  { return app.catalogo?.elementos?.find((e) => e.id_elemento === id); }
export function usuarioDe(id)   { return app.catalogo?.usuarios?.find((u) => u.id_login === id); }

/* ---------------------------------------------------------------- */
/* Navegación (por almohadilla: funciona igual dentro del APK)      */
/* ---------------------------------------------------------------- */
const RUTAS = [
  { patron: /^\/panel$/,          vista: vistaPanel,  pestana: 'panel' },
  { patron: /^\/lista$/,          vista: vistaLista,  pestana: 'lista' },
  { patron: /^\/mios$/,           vista: vistaLista,  pestana: 'mios' },
  { patron: /^\/nuevo$/,          vista: vistaNuevo,  pestana: null },
  { patron: /^\/ticket\/(-?\d+)$/, vista: vistaTicket, pestana: null },
  { patron: /^\/ticket\/(\d+)\/chat$/, vista: vistaChat, pestana: null },
  { patron: /^\/mensajes$/,      vista: vistaMensajes, pestana: 'mensajes' },
  /* La lista antigua de conversaciones se queda apuntando a la ventana
     nueva: hay enlaces a /conversaciones por toda la aplicación y en los
     avisos, y romperlos para estrenar pantalla sería gratuito. */
  { patron: /^\/conversaciones$/, vista: vistaMensajes, pestana: 'mensajes' },
  { patron: /^\/errores$/,        vista: vistaErrores, pestana: 'cuenta' },
  { patron: /^\/cuenta$/,         vista: vistaCuenta, pestana: 'cuenta' },
  { patron: /^\/avisos$/,         vista: vistaAvisos, pestana: null },
  { patron: /^\/equipo$/,         vista: vistaEquipo, pestana: 'cuenta' },
  { patron: /^\/conflictos$/,     vista: vistaConflictos, pestana: null },
  { patron: /^\/informes$/,       vista: vistaInformes, pestana: 'cuenta' },
  { patron: /^\/ubicacion\/(\d+)$/, vista: vistaUbicacion, pestana: null },
  { patron: /^\/mantenimiento$/,  vista: vistaMantenimiento, pestana: 'cuenta' },
  { patron: /^\/seguridad$/,      vista: vistaSeguridad, pestana: 'cuenta' },
  { patron: /^\/instrucciones$/,  vista: vistaInstrucciones, pestana: 'cuenta' },
  { patron: /^\/unidades$/,       vista: vistaUnidades, pestana: 'cuenta' },
  { patron: /^\/familia\/(\d+)$/,  vista: vistaFamilia, pestana: null },
  { patron: /^\/apariencia$/,     vista: vistaApariencia, pestana: 'cuenta' },
  { patron: /^\/hoteles$/,        vista: vistaHoteles, pestana: 'cuenta' },
  { patron: /^\/gestion-partes$/, vista: vistaGestionPartes, pestana: 'cuenta' },
  { patron: /^\/papelera$/,       vista: vistaPapelera, pestana: 'cuenta' },
  { patron: /^\/guia-api$/,       vista: vistaGuiaApi,  pestana: 'cuenta' },
  { patron: /^\/sap\/habitacion$/, vista: vistaSapHabitacion, pestana: 'cuenta' },
  { patron: /^\/sap\/estados$/,    vista: vistaSapEstados,    pestana: 'cuenta' },
  { patron: /^\/sap\/conexion$/,   vista: vistaSapConexion,   pestana: 'cuenta' },
  { patron: /^\/departamentos$/,  vista: vistaDepartamentos, pestana: 'cuenta' },
  { patron: /^\/avisos-ajustes$/, vista: vistaAvisosAjustes, pestana: 'cuenta' },
  { patron: /^\/personal$/,      vista: vistaPersonal, pestana: 'cuenta' },
  { patron: /^\/admin$/,          vista: vistaAdmin, pestana: 'cuenta' },
  { patron: /^\/iconografia$/,    vista: vistaIconografia, pestana: 'cuenta' },
  { patron: /^\/frases$/,         vista: vistaFrases, pestana: 'cuenta' },
  { patron: /^\/admin\/(\w+)$/,    vista: vistaAdmin, pestana: 'cuenta' },
];

export function rutaActual() {
  /* Sin la parte de «?...»: una pantalla puede recibir datos por ahí
     —«/lista?programados=1»— y eso no cambia qué pantalla es. Si se
     devolviera entero, la dirección no casaría con ninguna ruta y se
     vería un «no encontrado» por pedir un filtro. */
  const h = (location.hash.replace(/^#/, '') || '/panel').split('?')[0];
  return h.startsWith('/') ? h : '/' + h;
}

export function ir(ruta, { reemplazar = false } = {}) {
  cerrarTodasLasHojas();
  const destino = '#' + (ruta.startsWith('/') ? ruta : '/' + ruta);
  if (reemplazar) location.replace(destino);
  else location.hash = destino;
}

export function volver() {
  if (history.length > 1) history.back();
  else ir('/panel', { reemplazar: true });
}

/* ---------------------------------------------------------------- */
/* Armazón                                                          */
/* ---------------------------------------------------------------- */
function armazon() {
  document.body.innerHTML = `
    <div id="app">
      <header class="cabecera" id="cabecera"></header>
      <div id="tiras"></div>
      <main class="contenido" id="contenido"></main>
      <nav class="barra" id="barra" aria-label="Secciones"></nav>
    </div>
    <div id="mensajes" aria-live="polite"></div>`;
}

/**
 * Pestañas disponibles para la barra inferior.
 *
 * El botón de alta no está en la lista: es la acción que justifica la
 * aplicación y no debe poder quitarse. Las otras cuatro sí, porque no
 * todos los puestos necesitan lo mismo — recepción no tiene partes
 * asignados y «Míos» le sobra.
 */
export const PESTANAS = {
  panel:         { ruta: '/panel',         icono: 'panel',        texto: 'Inicio' },
  lista:         { ruta: '/lista',         icono: 'lista',        texto: 'Partes' },
  mios:          { ruta: '/mios',          icono: 'mios',         texto: 'Míos' },
  avisos:        { ruta: '/avisos',        icono: 'campana',      texto: 'Avisos' },
  mensajes:      { ruta: '/mensajes',      icono: 'chat',         texto: 'Mensajes' },
  instrucciones: { ruta: '/instrucciones', icono: 'nota',         texto: 'Cómo' },
  cuenta:        { ruta: '/cuenta',        icono: 'ajustes',      texto: 'Ajustes' },
};

/** Lo que ve cada puesto si no ha tocado nada. */
/* «Mensajes» entra en la barra de todos.
 *
 * Un mensaje urgente escondido a tres toques no es urgente. La pestaña
 * lleva la chapa con lo que hay sin leer, así que se ve desde cualquier
 * pantalla, que es lo que se espera de una aplicación de mensajes.
 *
 * Lo que sale a cambio: «Partes» en los puestos de trabajo —a la lista
 * completa se llega desde Inicio, y «Míos» es lo que se mira cada día— y
 * «Avisos» en recepción, porque los avisos ya están dentro de Mensajes.
 *
 * Cada persona puede recomponer su barra en Apariencia. */
const MENU_POR_PERFIL = {
  responsable: ['panel', 'mios', 'mensajes', 'cuenta'],
  tecnico:     ['panel', 'mios', 'mensajes', 'cuenta'],
  limpieza:    ['panel', 'mios', 'mensajes', 'cuenta'],
  reporta:     ['panel', 'lista', 'mensajes', 'cuenta'],
};

/** Las cuatro pestañas de esta persona. */
export function menuActual() {
  try {
    const propio = JSON.parse(localStorage.getItem('hurakantick_menu') || 'null');
    if (Array.isArray(propio) && propio.length === 4
        && propio.every((k) => PESTANAS[k])) {
      return propio;
    }
  } catch { /* preferencia corrupta: se usa la del puesto */ }
  const perfil = localStorage.getItem('hurakantick_perfil') || 'tecnico';
  return MENU_POR_PERFIL[perfil] || MENU_POR_PERFIL.tecnico;
}

export function fijarMenu(claves) {
  if (Array.isArray(claves) && claves.length === 4) {
    localStorage.setItem('hurakantick_menu', JSON.stringify(claves));
  } else {
    localStorage.removeItem('hurakantick_menu');
  }
  pintarBarra(pestanaActiva);
}

let pestanaActiva = null;

/**
 * Poner al día el número de la chapa sin ir al servidor.
 *
 * La chapa sale del catálogo, y el catálogo sólo se trae al arrancar. Sin
 * esto, leías un mensaje y el número seguía ahí hasta recargar la
 * aplicación: parecía que no se había enterado.
 */
export function fijarSinLeer({ chats, avisos }) {
  if (!app.catalogo) return;
  const antes = app.catalogo.sin_leer || { chats: 0, avisos: 0, total: 0 };
  const c = chats  === undefined ? antes.chats  : Math.max(0, chats);
  const a = avisos === undefined ? antes.avisos : Math.max(0, avisos);
  app.catalogo.sin_leer = { chats: c, avisos: a, total: c + a };
  if (pestanaActiva !== null) pintarBarra(pestanaActiva);
}

function pintarBarra(activa) {
  pestanaActiva = activa;
  const barra = $('#barra');
  if (!barra) return;

  /* Lo que esta persona tiene por leer, para la chapa.
     Un mensaje urgente tiene que verse desde donde se esté, no sólo al
     entrar en la pantalla de mensajes. */
  const pendientes = {
    mensajes: app.catalogo?.sin_leer?.total || 0,
    avisos:   app.catalogo?.sin_leer?.avisos || 0,
  };

  const item = (id) => {
    const p = PESTANAS[id];
    if (!p) return '';
    const n = pendientes[id] || 0;
    return `
      <button type="button" class="barra__item" data-ruta="${p.ruta}" ${activa === id ? 'aria-current="page"' : ''}
              ${n ? `aria-label="${esc(p.texto)}, ${n} sin leer"` : ''}>
        <span style="position:relative;display:inline-flex">
          ${icono(p.icono)}${n ? `<span class="chapa">${n > 99 ? '99+' : n}</span>` : ''}
        </span><span>${p.texto}</span>
      </button>`;
  };

  const [a, b, c, d] = menuActual();
  barra.innerHTML =
    item(a) + item(b)
    + `<button type="button" class="barra__alta" data-ruta="/nuevo" aria-label="Nuevo parte">${icono('mas')}</button>`
    + item(c) + item(d);

  barra.onclick = (e) => {
    const btn = e.target.closest('[data-ruta]');
    if (!btn) return;

    /* Entrar por la barra de abajo es empezar de cero.
       Si se conservaran los filtros de la última vez, tocar «Partes»
       daría un resultado distinto cada día sin que se vea por qué. Los
       filtros que vienen de una cifra del inicio o de un atajo sí se
       respetan, porque ahí se han pedido a propósito. */
    app.filtrosPedidos = false;

    // Y si ya se está en esa pantalla, se vuelve a montar: si no, tocar
    // la pestaña estando dentro no hacía nada.
    if (rutaActual() === btn.dataset.ruta) {
      pintar(btn.dataset.ruta);
      return;
    }
    ir(btn.dataset.ruta);
  };
}

/** Cabecera por defecto: título, avisos y sincronización manual. */
export function pintarCabecera(html) {
  const c = $('#cabecera');
  if (c) c.innerHTML = html;
}

export function cabeceraPrincipal(titulo, subtitulo = '') {
  return `
    <div class="cabecera__titulo" style="padding-left:12px">
      <h1>${esc(titulo)}</h1>
      ${subtitulo ? `<small>${esc(subtitulo)}</small>` : ''}
    </div>
    <button type="button" class="icono-btn" data-ubicacion aria-label="Consultar una ubicación">${icono('ubicacion')}</button>
    <button type="button" class="icono-btn" data-avisos aria-label="Avisos">
      ${icono('campana')}
      ${app.noLeidas ? `<span class="icono-btn__pico">${app.noLeidas > 99 ? '99+' : app.noLeidas}</span>` : ''}
    </button>
    <button type="button" class="icono-btn" data-sync aria-label="Sincronizar ahora">${icono('sync', sync.estado.sincronizando ? 'girando' : '')}</button>`;
}

/* ---------------------------------------------------------------- */
/* Tiras de aviso: sin conexión / cola pendiente                    */
/* ---------------------------------------------------------------- */
/**
 * En qué hotel se está trabajando.
 *
 * Sólo se enseña a quien puede cambiarlo. Para el resto sería ruido: su
 * hotel es el único que existe y no hay decisión que tomar.
 */
function tiraHotel() {
  /* Sólo quien lleva la cadena. A un técnico o a recepción el nombre del
     hotel no le dice nada —sólo trabajan en uno— y el botón invita a
     cambiarse a otro, que es lo último que queremos. */
  if (!app.usuario?.es_cadena) return '';
  const h = app.usuario.hotel;
  if (!h) return '';
  const activo = hotelActivo();
  const otro = activo && activo !== app.usuario.id_hotel;
  return `<button type="button" class="tira tira--hotel" data-ir-hoteles>
      ${icono('panel')}
      <span>${esc(app.catalogo?.hotel?.nombre || h.nombre)}${otro ? ' · no es el tuyo' : ''}</span>
      ${icono('flecha')}
    </button>`;
}

function pintarTiras() {
  const zona = $('#tiras');
  if (!zona) return;
  const partes = [];

  // La primera, en qué hotel se está: es lo que más importa saber
  // cuando se puede cambiar.
  const th = tiraHotel();
  if (th) partes.push(th);

  /* Datos a medias: se dice.
     Un móvil con parte del histórico creyendo que está al día es peor
     que uno que avisa, porque nadie sospecha nada al no encontrar un
     parte. */
  if (sync.estado.incompleto) {
    partes.push(`<div class="tira">${icono('aviso')}
      <span>Hay más partes en el servidor de los que caben aquí. Busca en línea.</span>
    </div>`);
  }

  if (!hayRed()) {
    partes.push(`<div class="tira">${icono('sinred')}
      <span>Sin conexión · puedes seguir trabajando</span>
      ${sync.estado.pendientes ? `<span class="tira__cuenta">${sync.estado.pendientes} en cola</span>` : ''}
    </div>`);
  } else if (sync.estado.sincronizando) {
    partes.push(`<div class="tira tira--sync">${icono('sync', 'girando')}
      <span>Sincronizando…</span></div>`);
  } else if (sync.estado.mantenimiento) {
    /* El servidor está cerrado mientras se restaura una copia.
       Lo que se guarde ahora se queda en la cola y sube al reabrir, así
       que se puede seguir trabajando: sólo hay que decirlo. */
    zona.className = 'tira tira--senal';
    zona.innerHTML = `${icono('candado')}
      <span>Actualizando el sistema · lo que hagas se enviará al terminar</span>`;
  } else if (sync.estado.pendientes) {
    partes.push(`<div class="tira tira--sync">${icono('nube')}
      <span>${sync.estado.pendientes} cambio${sync.estado.pendientes > 1 ? 's' : ''} por enviar</span>
      <span class="tira__cuenta">Se enviarán solos</span></div>`);
  }

  if (sessionStorage.getItem('hurakantick_sin_offline') === '1' && !sessionStorage.getItem('hurakantick_sin_offline_visto')) {
    partes.push(`<div class="tira">${icono('aviso')}
      <span>Sin HTTPS la app necesita red para abrirse</span>
      <button type="button" class="tira__cuenta" data-cerrar-aviso style="border:0;background:none;color:inherit;font-weight:700;cursor:pointer">Entendido</button>
    </div>`);
  }

  if (sync.estado.conflictos) {
    partes.push(`<div class="tira" style="background:color-mix(in srgb,var(--peligro) 12%,transparent);color:var(--peligro);border-color:color-mix(in srgb,var(--peligro) 25%,transparent)">
      ${icono('aviso')}
      <span>${sync.estado.conflictos} cambio${sync.estado.conflictos > 1 ? 's' : ''} pisado${sync.estado.conflictos > 1 ? 's' : ''} por otra persona</span>
      <button type="button" class="tira__cuenta" data-ver-conflictos style="border:0;background:none;color:inherit;font-weight:700;cursor:pointer;text-decoration:underline">Revisar</button>
    </div>`);
  }

  const soloErrores = Math.max(0, sync.estado.errores - sync.estado.conflictos);
  if (soloErrores) {
    partes.push(`<div class="tira">${icono('aviso')}
      <span>${soloErrores} envío${soloErrores > 1 ? 's' : ''} con problemas</span>
      <button type="button" class="tira__cuenta" data-ver-errores style="border:0;background:none;color:inherit;font-weight:700;cursor:pointer;text-decoration:underline">Revisar</button>
    </div>`);
  }

  zona.innerHTML = partes.join('');
  const b = zona.querySelector('[data-ver-errores]');
  /* «Revisar» abre la lista de envíos con problemas directamente. Antes
     llevaba a Ajustes y había que adivinar dónde estaba: quien tiene un
     envío atascado no debería tener que buscarlo. */
  if (b) b.onclick = () => { try { sessionStorage.setItem('hurakantick_ver_errores', '1'); } catch { /* da igual */ } ir('/cuenta'); };
  const c = zona.querySelector('[data-ver-conflictos]');
  if (c) c.onclick = () => ir('/conflictos');
  const av = zona.querySelector('[data-cerrar-aviso]');
  if (av) av.onclick = () => {
    sessionStorage.setItem('hurakantick_sin_offline_visto', '1');
    pintarTiras();
  };
}

/* ---------------------------------------------------------------- */
/* Pintado de la vista                                              */
/* ---------------------------------------------------------------- */
async function pintar(ruta) {
  if (!app.usuario) {
    pintarAcceso();
    return;
  }

  const encontrada = RUTAS.find((r) => r.patron.test(ruta));
  if (!encontrada) { ir('/panel', { reemplazar: true }); return; }

  const args = ruta.match(encontrada.patron).slice(1);
  const contenido = $('#contenido');

  if (vistaActual?.limpiar) vistaActual.limpiar();

  /* Se sueltan los manejadores de la pantalla anterior.
     Las vistas se enganchan con «contenido.onclick = ...», y el de la
     que se va seguía ahí. Si la nueva pone el suyo en un hijo —el
     listado lo pone en #resultado— los dos se disparaban: el hijo
     marcaba el parte y, al burbujear, el viejo navegaba a su ficha.
     Se notaba como que la selección múltiple «no funcionaba». */
  contenido.onclick = null;
  contenido.oninput = null;
  contenido.onchange = null;

  pintarBarra(encontrada.pestana);
  pintarTiras();

  try {
    vistaActual = await encontrada.vista({ contenido, args, app });
  } catch (e) {
    console.error(e);
    if (e instanceof ErrorApi && e.codigo === 401) {
      await cerrarSesion(false);
      return;
    }
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div><strong>No se ha podido cargar la pantalla.</strong><br>${esc(e.message)}</div></div>
      <button type="button" class="btn btn--contorno btn--bloque" onclick="location.reload()">Reintentar</button>`;
  }

  /* Volver arriba al cambiar de pantalla.
     Se tocan los tres posibles contenedores porque cuál hace scroll
     depende del navegador y de los estilos, y quedarse a medias
     significa abrir una pantalla por su final. */
  window.scrollTo(0, 0);
  document.documentElement.scrollTop = 0;
  document.body.scrollTop = 0;
}

function pintarAcceso() {
  document.body.innerHTML = '<div id="acceso"></div><div id="mensajes" aria-live="polite"></div>';
  vistaAcceso({ raiz: $('#acceso'), alEntrar: iniciar });
}

/* ---------------------------------------------------------------- */
/* Sesión                                                           */
/* ---------------------------------------------------------------- */
export async function cerrarSesion(avisarServidor = true) {
  if (avisarServidor && hayRed()) {
    try { await api.salir(); } catch { /* da igual: el token caduca solo */ }
  }
  fijarToken(null);
  app.usuario = null;
  await db.limpiarCache();
  localStorage.removeItem('hurakantick_usuario');
  location.hash = '';
  pintarAcceso();
}

async function cargarCatalogo() {
  try {
    const c = await api.catalogo();
    app.catalogo = c;

    /* Se deja a mano para los componentes comunes, que no conocen el
       catálogo: ui.js lo consulta al abrir un panel de búsqueda. */
    window.foco_buscador = app.catalogo?.ajustes?.foco_buscador !== false;
    await db.ajuste('catalogo', c);

    /* La barra se repinta con el catálogo recién traído: ahí viene el
       número de mensajes sin leer, y una chapa que sólo se actualiza al
       recargar la página no avisa de nada. */
    if (pestanaActiva !== null) pintarBarra(pestanaActiva);
  } catch (e) {
    const guardado = await db.ajuste('catalogo');
    if (guardado) {
      app.catalogo = guardado;
    } else if (!e.sinRed) {
      throw e;
    }
  }
}


/* ------------------------------------------------------------------ */
/* Los fallos del móvil                                               */
/* ------------------------------------------------------------------ */

/**
 * Recoge los errores de la aplicación y los manda al servidor.
 *
 * Sin esto, cuando a alguien «no le funciona» no hay forma de saber qué
 * pasó: el fallo ocurre en su teléfono y muere ahí. Ahora queda en el
 * registro, con la pantalla donde estaba y la versión que tenía.
 *
 * Tres cuidados para que el remedio no sea peor:
 *   · se manda como mucho uno cada pocos segundos, para que un fallo en
 *     un bucle no inunde el servidor;
 *   · el mismo mensaje no se repite en la misma sesión;
 *   · si el envío falla, se calla. Nunca se avisa de un error avisando
 *     de otro.
 */
function vigilarFallos() {
  const vistos = new Set();
  let ultimo = 0;

  const mandar = (mensaje, detalle) => {
    try {
      if (!mensaje) return;
      const clave = String(mensaje).slice(0, 180);
      if (vistos.has(clave)) return;
      if (Date.now() - ultimo < 4000) return;
      vistos.add(clave);
      ultimo = Date.now();

      api.avisarError({
        mensaje: clave,
        detalle: String(detalle || '').slice(0, 3000),
        ruta: location.hash || '/',
        version: (self.VERSION_APP || document.querySelector('meta[name=version]')?.content || ''),
      }).catch(() => { /* si no se puede avisar, se calla */ });
    } catch { /* nunca estorbar */ }
  };

  addEventListener('error', (e) => {
    mandar(e.message, `${e.filename || ''}:${e.lineno || ''}\n${e.error?.stack || ''}`);
  });

  addEventListener('unhandledrejection', (e) => {
    const r = e.reason;
    mandar(r?.message || String(r), r?.stack || '');
  });
}

async function iniciar(usuario) {
  app.usuario = usuario;
  vigilarFallos();
  localStorage.setItem('hurakantick_usuario', JSON.stringify(usuario));

  /* La primera vez que alguien entra en este aparato, se le piden los
     avisos.
     *
     * Se hace aquí y no en el arranque porque el navegador sólo deja
     * pedirlo a raíz de un gesto de la persona, y entrar lo es. Y se
     * pregunta una sola vez por aparato: quien dijo que no, dijo que no,
     * y volver a preguntarle cada día es la forma más rápida de que
     * bloquee los avisos para siempre. */
  try {
    const clave = 'hurakantick_avisos_pedidos';
    if (!localStorage.getItem(clave)
        && typeof Notification !== 'undefined'
        && Notification.permission === 'default') {
      localStorage.setItem(clave, '1');
      const { permisoNotificaciones } = await import('./sonido.js');
      await permisoNotificaciones();
    }
  } catch { /* si el navegador no quiere, la aplicación funciona igual */ }

  armazon();
  await cargarCatalogo();

  // Los iconos que se hayan cambiado desde Iconografía, antes de pintar.
  try { fijarIconosPersonalizados(app.catalogo?.ajustes?.iconos || {}); } catch (e) { /* los de siempre */ }

  /* Con el catálogo ya se sabe qué ha decidido el superadministrador
     sobre la vista smartphone. Si hay que cambiar —entrar al marco o
     salir de él—, se hace ahora. Envuelto: un fallo aquí no puede dejar a
     nadie sin aplicación. */
  /* Se apunta la orden de renovación vigente: quien acaba de identificarse
     ya tiene lo último, y no hay que hacerle vaciar y volver a entrar. */
  try {
    const e = await api.estado();
    if (e?.renovacion && !sync.marcaRenovacion()) sync.anotarRenovacion(e.renovacion);
  } catch { /* sin red: ya se mirará */ }

  let vista = null;
  try { vista = aplicarVistaMovil(app.catalogo?.ajustes); } catch (e) { console.warn('Vista smartphone:', e); }
  // Si esta copia se va al marco, no arranca nada más: la de verdad
  // arrancará dentro, y dos sincronizando a la vez es buscarse problemas.
  if (vista?.cambiando) return;

  prepararAudio();
  sync.fijarUsuario(usuario.id_login);
  sync.arrancar();

  // Integración con la aplicación de Android, si es donde se ejecuta.
  iniciarNativo({
    alVolver: async () => {
      const pend = (await db.colaPendiente()).length;
      const salir = await confirmar({
        titulo: 'Salir de la aplicación',
        texto: pend
          ? `Quedan ${pend} cambios sin enviar. Se conservan y se mandarán la próxima vez que abras con cobertura.`
          : '¿Cerrar la aplicación?',
        aceptar: 'Salir',
      });
      if (salir) window.Capacitor?.Plugins?.App?.exitApp();
    },
    alSincronizar: () => sync.sincronizar(),
  });
  alTocarAviso((ruta) => { location.hash = ruta; });

  /* Mensajes del trabajador de servicio.
     Al tocar un aviso con la aplicación ya abierta manda la ruta en vez
     de abrir otra ventana; y si el navegador ha renovado la suscripción
     por su cuenta, se le vuelve a decir al servidor — si no, los avisos
     dejarían de llegar sin que nadie se entere. */
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (e) => {
      const m = e.data || {};
      if (m.tipo === 'ir' && m.ruta) {
        location.hash = m.ruta;
      } else if (m.tipo === 'push-renovado') {
        api.pushRegistrar({
          tipo: 'web', destino: m.destino,
          clave_p256: m.clave_p256, clave_auth: m.clave_auth,
        }).catch(() => {});
      }
    });
  }
  // La última sincronización que ya se ha reflejado en pantalla.
  let ultimaVistaSync = null;


  escucharNativo((ruta) => { location.hash = ruta; });
  sync.alCambiar((e) => {
    /* Si lo guardado en el móvil se ha vaciado y descargado de nuevo, la
       pantalla que se ve es de antes: se repinta. Sin esto, tras reinstalar
       el hotel el inicio seguía enseñando partes que ya no existían. */
    if (e.renovado && !e.sincronizando) {
      if (e.descartados > 0) {
        mensaje(`El servidor se ha restablecido. Se han descartado ${e.descartados} `
          + `cambio${e.descartados === 1 ? '' : 's'} que no se habían enviado.`, 'aviso');
      }
      app.refrescar?.(true);
    }

    /* Al terminar de enviar, se repinta la pantalla.
     *
     * Sin esto, quien pulsaba «me lo quedo» desde el inicio veía el parte
     * con su «Sin enviar» para siempre: el envío salía bien y la copia
     * local se actualizaba, pero lo que había en pantalla era de antes y
     * sólo se arreglaba recargando con F5. */
    if (!e.sincronizando && sync.estado.ultima !== ultimaVistaSync) {
      ultimaVistaSync = sync.estado.ultima;
      if (!e.pendientes) app.refrescar?.(true);
    }

    const antes = app.noLeidas;
    app.noLeidas = e.noLeidas ?? app.noLeidas;
    pintarTiras();

    /* El pico de la campana lleva el número de avisos sin leer, y vive
       en la cabecera. Antes sólo se repintaban las tiras, así que
       llegaba un parte nuevo, sonaba el aviso... y el número seguía en
       el que estaba hasta cambiar de pantalla. */
    if (app.noLeidas !== antes) {
      const pico = document.querySelector('[data-avisos] .icono-btn__pico');
      const boton = document.querySelector('[data-avisos]');
      if (boton) {
        if (app.noLeidas > 0) {
          const texto = app.noLeidas > 99 ? '99+' : String(app.noLeidas);
          if (pico) pico.textContent = texto;
          else boton.insertAdjacentHTML('beforeend',
            `<span class="icono-btn__pico">${texto}</span>`);
        } else if (pico) {
          pico.remove();
        }
      }
    }

    const b = document.querySelector('[data-sync] svg');
    if (b) b.classList.toggle('girando', e.sincronizando);
  });
  alCambiarRed(() => pintarTiras());

  document.body.addEventListener('click', (e) => {
    if (e.target.closest('[data-ir-hoteles]')) { ir('/hoteles'); return; }
    if (e.target.closest('[data-volver]')) volver();
    if (e.target.closest('[data-avisos]')) ir('/avisos');
    if (e.target.closest('[data-ubicacion]')) buscarUbicacion();
    const s = e.target.closest('[data-sync]');
    if (s) {
      sync.sincronizar({ forzar: true }).then((r) => {
        if (r?.sinRed) { tono('error'); mensaje('Sigue sin haber conexión.', 'error'); }
        else { tono('sync'); mensaje('Todo al día.', 'ok'); app.refrescar(); }
      });
    }
  });

  // Al cambiar de pantalla se cierra lo que hubiera abierto. Sin esto,
  // volver atrás con el botón del navegador deja la hoja tapando todo.
  window.addEventListener('hashchange', () => {
    cerrarTodasLasHojas();
    pintar(rutaActual());
  });

  // El trabajador de servicio avisa cuando Android recupera la red.
  navigator.serviceWorker?.addEventListener('message', (e) => {
    if (e.data?.tipo === 'sincronizar') sync.sincronizar();
  });

  if (!location.hash) location.replace('#/panel');
  aplicarApariencia();

  /* Si el servidor deja de reconocer la sesión, se vuelve al acceso.
     Quedarse dentro enseñando lo guardado del móvil hace creer que todo
     va bien hasta que algo no se guarda y nadie entiende por qué. */
  alCaducarSesion(() => {
    if (document.querySelector('#acceso')) return;   // ya está fuera
    mensaje('Tu sesión ha caducado. Vuelve a entrar.', 'error');
    // Sin avisar al servidor: el testigo ya no vale y esa llamada
    // devolvería otro 401, encadenando el aviso consigo mismo.
    setTimeout(() => cerrarSesion(false), 900);
  });

  // La lista de hoteles hace falta en varias pantallas; se pide una vez.
  if (app.usuario?.es_superadmin && hayRed()) {
    api.hoteles().then((r) => { app.hoteles = r.hoteles; }).catch(() => {});
  }
  await pintar(rutaActual());

  // Primera sincronización en segundo plano
  comprobarRed().then((hay) => { if (hay) sync.sincronizar().then(() => app.refrescar(true)); });
}

/* ---------------------------------------------------------------- */
/* Punto de entrada                                                 */
/* ---------------------------------------------------------------- */
async function arrancar() {
  /* Forzar HTTPS, si el hotel lo tiene puesto.
   *
   * Lo primero de todo, antes incluso de saber si hay sesión: el momento
   * en que más importa es la pantalla de entrada, que es cuando viajan el
   * usuario y la contraseña.
   *
   * Se recuerda la respuesta anterior para poder saltar al momento, sin
   * esperar al servidor; y se vuelve a preguntar por si ha cambiado. En
   * una red local sin certificado esto se deja apagado: si no, la
   * aplicación no abriría. */
  await (async () => {
    const enLocal = /^(localhost$|127\.|\[::1\])/.test(location.hostname);
    if (location.protocol !== 'http:' || enLocal) { return; }
    try {
      if (localStorage.getItem('hurakantick_https') === '1') {
        /* La ventana de arriba, no el marco: con la vista smartphone la
           aplicación vive dentro de un iframe, y redirigir sólo el iframe
           dejaba la página en http, que es justo lo que se quería evitar. */
        const arriba = window.top || window;
        const destino = (arriba.location.href || location.href).replace(/^http:/, 'https:');
        arriba.location.replace(destino);
        await new Promise(() => {});          // la página se va: no se sigue
      }
    } catch { /* sin localStorage: se mira igualmente abajo */ }
    try {
      const e = await api.estado();
      localStorage.setItem('hurakantick_https', e?.https ? '1' : '0');
      if (e?.https) {
        /* La ventana de arriba, no el marco: con la vista smartphone la
           aplicación vive dentro de un iframe, y redirigir sólo el iframe
           dejaba la página en http, que es justo lo que se quería evitar. */
        const arriba = window.top || window;
        const destino = (arriba.location.href || location.href).replace(/^http:/, 'https:');
        arriba.location.replace(destino);
        await new Promise(() => {});
      }
    } catch { /* sin servidor no se toca nada */ }
  })();

  // Nada de lo que hay aquí debe impedir que se vea la pantalla de acceso:
  // si el almacenamiento falla, se avisa y se sigue.
  let almacenOk = true;
  try {
    await db.abrir();
  } catch (e) {
    almacenOk = false;
    console.error('IndexedDB no disponible:', e);
  }

  try {
    const tema = localStorage.getItem('hurakantick_tema');
    if (tema) document.documentElement.dataset.tema = tema;
  } catch { /* almacenamiento bloqueado */ }

  if (!almacenOk) {
    pintarAcceso();
    setTimeout(() => mensaje(
      'Este navegador tiene el almacenamiento bloqueado: no se podrá trabajar sin conexión.',
      'error'), 900);
    return;
  }

  if (!token()) { pintarAcceso(); return; }

  // Se entra con la sesión guardada y se valida después: así la app
  // abre al instante aunque no haya cobertura.
  const guardado = localStorage.getItem('hurakantick_usuario');
  if (guardado) {
    try { await iniciar(JSON.parse(guardado)); } catch (e) { console.error(e); }
    comprobarRed().then(async (hay) => {
      if (!hay) return;
      try {
        const r = await api.yo();
        app.usuario = r.usuario;
        localStorage.setItem('hurakantick_usuario', JSON.stringify(r.usuario));
      } catch (e) {
        if (e.codigo === 401) {
          mensaje('La sesión ha caducado. Vuelve a entrar.', 'error');
          cerrarSesion(false);
        }
      }
    });
    return;
  }

  try {
    const r = await api.yo();
    await iniciar(r.usuario);
  } catch {
    fijarToken(null);
    pintarAcceso();
  }
}

/* ------------------------------------------------------------------ */
/* Trabajador de servicio: es lo que permite abrir la aplicación sin
   cobertura. Los navegadores sólo lo admiten en contexto seguro, es
   decir con HTTPS o en localhost. Servida por HTTP en una IP de red
   local no existe, y conviene decirlo en vez de fallar en silencio el
   día que alguien se quede sin datos.                                  */
/* ------------------------------------------------------------------ */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./sw.js').catch((e) => console.warn('SW:', e));
  });
} else if (!window.isSecureContext) {
  console.warn(
    'Sin HTTPS no hay trabajador de servicio: la aplicación necesita red para abrirse. ' +
    'Los datos sí se guardan en el móvil y se sincronizan, pero no podrás iniciarla estando sin cobertura. ' +
    'Usa HTTPS o la aplicación de Android.'
  );
  sessionStorage.setItem('hurakantick_sin_offline', '1');
}

/* Si el arranque de index.html ya ha decidido ir al marco, esta copia no
   arranca: la de verdad se carga dentro del marco. Sin esto habría dos
   aplicaciones sincronizando a la vez durante el cambio. */
if (!window.__hurakantick_al_marco) arrancar().catch((e) => {
  console.error('Fallo al arrancar:', e);
  document.body.innerHTML =
    '<div style="padding:24px;font:15px/1.6 system-ui,sans-serif;max-width:520px;margin:0 auto">'
    + '<h1 style="font-size:18px;margin:0 0 10px">No se ha podido iniciar</h1>'
    + '<p style="color:#46585D">' + String(e && e.message ? e.message : e) + '</p>'
    + '<p><a href="diagnostico.html">Abrir el diagnóstico</a></p></div>';
});
