/* =====================================================================
   Avisos.

   Lo que ha pasado en los partes que abriste o tienes asignados. Se
   pueden borrar: una bandeja que sólo crece deja de mirarse.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, haceCuanto, mensaje, confirmar } from '../ui.js';
import { estadoVacio, cabeceraVolver, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';

export async function vistaAvisos({ contenido }) {
  pintarCabecera(cabeceraVolver('Avisos', '',
    `<button type="button" class="icono-btn" data-leer aria-label="Marcar todo como leído">${icono('ok')}</button>`));

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Los avisos necesitan conexión. Vuelve a intentarlo cuando tengas cobertura.</div></div>`;
    return {};
  }

  let datos = null;

  async function cargar() {
    contenido.innerHTML = bloqueCarga(4);
    try {
      datos = await api.avisos();
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    app.noLeidas = datos.no_leidas || 0;
    pintar();
  }

  function pintar() {
    const lista = datos.notificaciones || [];
    const leidas = lista.filter((n) => n.leida).length;

    contenido.innerHTML = lista.length
      ? `<div class="filas">
          ${lista.map((n) => `
            <div class="fila" data-ticket="${esc(n.id_ticket || '')}"
                 data-evento="${esc(n.evento || '')}"
                 role="button" tabindex="0" style="${n.leida ? '' : 'background:var(--marca-tenue)'}">
              <span class="avatar" style="background:${esc(n.estado_color || '#0B5563')}22;
                                          color:${esc(n.estado_color || '#0B5563')}">
                ${n.leida ? icono('campana') : '●'}</span>
              <span class="fila__texto">
                <strong style="font-weight:${n.leida ? 550 : 680}">${esc(n.titulo)}</strong>
                <small>${esc(n.cuerpo || n.titulo_ticket || '')}</small>
                <small style="display:block;color:var(--tinta-3)">${esc(haceCuanto(n.fecha))}</small>
              </span>
              <button type="button" class="icono-btn" data-borrar="${n.id_notificacion}"
                      aria-label="Borrar este aviso"
                      style="min-width:42px;height:42px;color:var(--tinta-3)">${icono('cerrar')}</button>
            </div>`).join('')}
         </div>

         <div class="acciones" style="margin-top:14px">
           ${leidas ? `<button type="button" class="btn btn--contorno btn--s" data-vaciar="leidas">
             ${icono('papelera')}Borrar los ${leidas} leídos</button>` : ''}
           <button type="button" class="btn btn--contorno btn--s" data-vaciar="todo">
             ${icono('papelera')}Borrarlos todos</button>
         </div>`
      : estadoVacio({
          icono: 'campana',
          titulo: 'No hay avisos',
          texto: 'Aquí aparecerán los cambios en los partes que hayas abierto o tengas asignados.',
        });

    enganchar();
  }

  function enganchar() {
    // El aspa borra sin abrir el parte: es lo que se hace con la mayoría.
    $$('[data-borrar]', contenido).forEach((b) => {
      b.onclick = async (e) => {
        e.stopPropagation();
        const id = parseInt(b.dataset.borrar, 10);
        try {
          await api.borrarAviso(id);
          datos.notificaciones = datos.notificaciones.filter((n) => n.id_notificacion !== id);
          pintar();
        } catch (err) { mensaje(err.message, 'error'); }
      };
    });

    $$('[data-vaciar]', contenido).forEach((b) => {
      b.onclick = async () => {
        const soloLeidas = b.dataset.vaciar === 'leidas';
        const ok = await confirmar({
          titulo: soloLeidas ? 'Borrar los avisos leídos' : 'Borrar todos los avisos',
          texto: soloLeidas
            ? 'Los que aún no has leído se quedan.'
            : 'Se borran también los que no has leído. Los partes no se tocan.',
          aceptar: 'Borrar',
          peligro: !soloLeidas,
        });
        if (!ok) return;
        try {
          const r = await api.vaciarAvisos(soloLeidas);
          mensaje(`${r.borrados} ${r.borrados === 1 ? 'aviso borrado' : 'avisos borrados'}.`, 'ok');
          cargar();
        } catch (err) { mensaje(err.message, 'error'); }
      };
    });

    $$('[data-ticket]', contenido).forEach((b) => {
      b.onclick = (e) => {
        if (e.target.closest('[data-borrar]')) return;
        if (!b.dataset.ticket) return;
        /* Un aviso de mensaje lleva a la conversación, no a la ficha: lo
           que hay que hacer con un mensaje es leerlo y contestar. */
        ir(b.dataset.evento === 'chat'
          ? `/ticket/${b.dataset.ticket}/chat`
          : `/ticket/${b.dataset.ticket}`);
      };
    });
  }

  const leer = document.querySelector('[data-leer]');
  if (leer) leer.onclick = async () => {
    try {
      const r = await api.leerAvisos(null);
      app.noLeidas = r.no_leidas || 0;
      mensaje('Avisos marcados como leídos.', 'ok');
      cargar();
    } catch (err) { mensaje(err.message, 'error'); }
  };

  await cargar();

  // Se marcan como leídos al abrir, pero sin repintar: si las marcas
  // desaparecieran de golpe no daría tiempo a ver cuáles eran nuevos.
  if (datos?.no_leidas) {
    api.leerAvisos(null).then(() => { app.noLeidas = 0; }).catch(() => {});
  }

  return { refrescar: cargar };
}
