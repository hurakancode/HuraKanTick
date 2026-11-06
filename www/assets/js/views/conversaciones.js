/* =====================================================================
   Las conversaciones de esta persona.

   Para volver a una conversación sin acordarse de en qué parte era, que
   es como funciona cualquier aplicación de mensajes y es lo que la gente
   espera. Ordenadas por el último mensaje, con lo que queda sin leer
   arriba del todo.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, icono, esc, haceCuanto } from '../ui.js';
import { cabeceraVolver, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir } from '../app.js';

export async function vistaConversaciones({ contenido }) {
  pintarCabecera(cabeceraVolver('Conversaciones'));
  contenido.innerHTML = bloqueCarga(3);

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Las conversaciones necesitan conexión.</div></div>`;
    return {};
  }

  let lista = [];
  try {
    lista = (await api.conversaciones()).conversaciones || [];
  } catch (e) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>${esc(e.message)}</div></div>`;
    return {};
  }

  if (!lista.length) {
    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Todavía no hay conversaciones. Se abren desde la ficha de un parte, con el
        icono de conversación: sirven para hablar con quien lo abrió.
      </div></div>`;
    return {};
  }

  const sinLeer = lista.filter((c) => c.sin_leer > 0);

  contenido.innerHTML = `
    ${sinLeer.length ? `
      <div class="seccion"><h2>Sin leer</h2>
        <span class="rotulo">${sinLeer.length}</span></div>` : ''}
    <div class="filas">
      ${lista.map((c) => `
        <button type="button" class="fila" data-ir="${c.id_ticket}">
          ${icono('chat')}
          <span class="fila__texto">${esc(c.autor || 'Alguien')}${
            c.importante && c.sin_leer ? ' · importante' : ''}
            <small>${esc(c.texto)}</small>
            <small style="opacity:.7">${esc(c.codigo)} · ${esc(c.titulo || '')}
              · ${esc(haceCuanto(c.ultimo))}</small></span>
          ${c.sin_leer ? `<span class="chapa" style="position:static">${c.sin_leer}</span>` : ''}
          ${icono('flecha', 'fila__flecha')}
        </button>`).join('')}
    </div>`;

  contenido.onclick = (e) => {
    const b = e.target.closest('[data-ir]');
    if (b) ir(`/ticket/${b.dataset.ir}/chat`);
  };

  return {};
}
