/* =====================================================================
   Piezas de interfaz que se repiten en varias pantallas.
   ===================================================================== */

import { icono, esc, haceCuanto, iniciales, restante, ICONOS_ELEMENTO } from './ui.js';

/**
 * Tarjeta de un parte: se lee como una etiqueta de servicio.
 * Con `rapida` lleva un botón para avanzarlo sin abrirlo, que es lo que
 * ahorra tiempo de verdad a quien cierra varios seguidos.
 */
export function tarjetaParte(t, opciones = {}) {
  const color = t.estado_color || '#64748B';
  const plazo = t.fecha_limite && !t.es_final ? restante(t.fecha_limite) : null;

  /* Un parte aparcado para más adelante no está «de retraso»: nadie lo
     ha dejado tirado, está esperando a su fecha. */
  if (plazo && t.visible_desde
      && new Date(String(t.visible_desde).replace(' ', 'T') + 'Z') > new Date()) {
    plazo.vencido = false;
    plazo.texto = 'En pausa';
  }

  const sel = opciones.seleccionable;
  const marcado = opciones.marcado;

  const clases = [
    'parte',
    t.id_prioridad >= 4 && !t.es_final ? 'parte--urgente' : '',
    t.pendiente_envio ? 'parte--pendiente-envio' : '',
  ].filter(Boolean).join(' ');

  return `
  <div class="${clases}${marcado ? ' parte--marcado' : ''}" style="--estado:${esc(color)}"
       data-ticket="${esc(t.id_ticket)}" role="button" tabindex="0">
    <div class="parte__alto">
      ${sel ? `<span class="parte__marca${marcado ? ' parte__marca--si' : ''}">
        ${marcado ? '✓' : ''}</span>` : ''}
      <span class="parte__codigo">${esc(t.codigo || ('T-' + t.id_ticket))}</span>
      <span class="parte__estado">${esc(t.nombre_estado || '')}</span>
      <span class="parte__hora">${esc(haceCuanto(t.fecha_creado))}</span>
      ${opciones.rapida && !sel && !t.es_final
        ? `<button type="button" class="parte__rapida" data-rapida="${esc(t.id_ticket)}"
                   aria-label="Acción rápida">${icono('flecha')}</button>` : ''}
    </div>

    <div class="parte__titulo">${esc(t.titulo_ticket)}</div>

    ${t.zona_ruta ? `<div class="parte__ruta">${icono('ubicacion')}<span>${esc(t.zona_ruta)}</span></div>` : ''}

    <div class="parte__pie">
      ${t.id_prioridad >= 3 ? `<span class="prio" style="--prio-color:${esc(t.prioridad_color || '#F2A007')}">${esc(t.prioridad)}</span>` : ''}
      ${plazo?.vencido ? `<span class="retraso">${icono('aviso')}${esc(plazo.texto)}</span>` : ''}
      ${t.elemento ? `<span class="parte__dato">${icono(ICONOS_ELEMENTO[t.elemento_icono] || 'llaveinglesa')}${esc(t.elemento)}</span>` : ''}
      ${t.n_adjuntos ? `<span class="parte__dato">${icono('imagen')}${t.n_adjuntos}</span>` : ''}
      ${t.pendiente_envio ? `<span class="parte__dato" style="color:var(--aviso);font-weight:650">${icono('nube')}Sin enviar</span>` : ''}
      ${t.asignado_a
        ? `<span class="parte__dato" style="margin-left:auto"><span class="avatar">${esc(iniciales(t.asignado_a))}</span></span>`
        : `<span class="parte__dato" style="margin-left:auto;color:var(--marca);font-weight:650">Sin asignar</span>`}
    </div>
  </div>`;
}

export function listaPartes(tickets, vacio, opciones) {
  if (!tickets.length) return estadoVacio(vacio);
  return tickets.map((t) => tarjetaParte(t, opciones)).join('');
}

export function estadoVacio({ icono: ic = 'lista', titulo, texto, accion } = {}) {
  return `
  <div class="vacio">
    ${icono(ic)}
    <h3>${esc(titulo || 'No hay nada por aquí')}</h3>
    <p>${esc(texto || '')}</p>
    ${accion ? `<button type="button" class="btn btn--principal" data-accion="${esc(accion.id)}">${icono(accion.icono || 'mas')}${esc(accion.texto)}</button>` : ''}
  </div>`;
}

export function bloqueCarga(n = 4) {
  return Array.from({ length: n }, () => '<div class="hueso hueso--parte"></div>').join('');
}

/** Cabecera con botón de volver. */
export function cabeceraVolver(titulo, subtitulo = '', acciones = '') {
  return `
    <button type="button" class="icono-btn" data-volver aria-label="Volver">${icono('atras')}</button>
    <div class="cabecera__titulo">
      <h1>${esc(titulo)}</h1>
      ${subtitulo ? `<small>${esc(subtitulo)}</small>` : ''}
    </div>
    ${acciones}`;
}
