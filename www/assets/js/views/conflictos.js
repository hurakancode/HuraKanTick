/* =====================================================================
   Conflictos de sincronización.

   Aparecen cuando alguien movió un parte mientras este móvil estaba sin
   cobertura. No se pierde nada: aquí se ve lo que hay en el servidor,
   lo que se hizo sin conexión, y se elige.
   ===================================================================== */

import * as db from '../db.js';
import * as sync from '../sync.js';
import { hayRed } from '../api.js';
import { $, $$, icono, esc, mensaje, fechaLarga, haceCuanto, confirmar } from '../ui.js';
import { cabeceraVolver, estadoVacio } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';

const NOMBRES = {
  estado:     'Cambio de estado',
  asignacion: 'Reasignación',
  cerrado:    'Parte ya cerrado',
};

export async function vistaConflictos({ contenido }) {
  pintarCabecera(cabeceraVolver('Revisar cambios', 'Se han solapado con otra persona'));

  async function cargar() {
    const lista = await db.conflictos();

    if (!lista.length) {
      contenido.innerHTML = estadoVacio({
        icono: 'ok',
        titulo: 'No hay nada que revisar',
        texto: 'Todos tus cambios se han guardado sin pisar el trabajo de nadie.',
      });
      return;
    }

    contenido.innerHTML = `
      <div class="aviso aviso--senal">${icono('aviso')}<div>
        Mientras estabas sin conexión, otra persona modificó
        ${lista.length === 1 ? 'este parte' : 'estos partes'}.
        Tu cambio está guardado y no se ha aplicado todavía: decide cuál vale.
      </div></div>

      ${lista.map(tarjeta).join('')}`;

    contenido.onclick = async (e) => {
      const abrir = e.target.closest('[data-abrir]');
      if (abrir) { ir(`/ticket/${abrir.dataset.abrir}`); return; }

      const b = e.target.closest('[data-resolver]');
      if (!b) return;

      const idLocal = parseInt(b.dataset.id, 10);
      const modo = b.dataset.resolver;

      if (modo === 'servidor') {
        const ok = await confirmar({
          titulo: 'Descartar tu cambio',
          texto: 'El parte se queda como está en el servidor y tu cambio se elimina. Esto no se puede deshacer.',
          aceptar: 'Descartar el mío',
          peligro: true,
        });
        if (!ok) return;
        await sync.conflictoAceptarServidor(idLocal);
        mensaje('Cambio descartado.', 'ok');
        cargar();
        return;
      }

      if (!hayRed()) {
        mensaje('Para aplicar tu cambio hace falta conexión.', 'error');
        return;
      }

      b.disabled = true;
      b.innerHTML = `${icono('sync', 'girando')}Aplicando…`;
      const r = await sync.conflictoImponerLocal(idLocal);
      if (r.ok) {
        mensaje('Tu cambio se ha aplicado.', 'ok');
        cargar();
      } else {
        b.disabled = false;
        b.innerHTML = `${icono('ok')}Aplicar el mío`;
        mensaje(r.error || 'No se ha podido aplicar.', 'error');
      }
    };
  }

  function tarjeta(c) {
    const d = c.detalle || {};
    const t = c.ticket_servidor || {};
    const nota = c.operacion?.datos?.nota;

    return `
    <div class="tarjeta" style="margin-bottom:12px;border-left:4px solid var(--aviso)">
      <div class="tarjeta__cuerpo">

        <div class="parte__alto" style="margin-bottom:8px">
          <span class="parte__codigo">${esc(t.codigo || 'Parte')}</span>
          <span class="prio" style="--prio-color:var(--aviso)">${esc(NOMBRES[d.tipo] || 'Conflicto')}</span>
          <span class="parte__hora">${esc(haceCuanto(c.creado))}</span>
        </div>

        <div class="parte__titulo" style="margin-bottom:10px">${esc(t.titulo_ticket || '')}</div>
        ${t.zona_ruta ? `<div class="parte__ruta">${icono('ubicacion')}<span>${esc(t.zona_ruta)}</span></div>` : ''}

        <div class="datos" style="margin-bottom:12px">
          <div class="datos__celda">
            <dt>En el servidor</dt>
            <dd>${esc(d.valor_servidor || '—')}</dd>
          </div>
          <div class="datos__celda">
            <dt>Tu cambio</dt>
            <dd>${esc(d.valor_local || '—')}</dd>
          </div>
        </div>

        <p style="font-size:12.5px;color:var(--tinta-2);margin-bottom:${nota ? '8px' : '14px'}">
          ${icono('info')} Lo cambió ${esc(d.quien || 'otra persona')}
          ${d.cuando ? ' el ' + esc(fechaLarga(d.cuando)) : ''}.
          Tú partías de «${esc(d.valor_base || '—')}».
        </p>

        ${nota ? `<div class="hito__texto" style="margin-bottom:14px">
          <strong style="font-weight:640">Tu nota:</strong> ${esc(nota)}</div>` : ''}

        <div class="acciones">
          <button type="button" class="btn btn--contorno btn--s" data-resolver="servidor" data-id="${c.id_local}">
            ${icono('nube')}Quedarme con el suyo
          </button>
          <button type="button" class="btn btn--principal btn--s" data-resolver="local" data-id="${c.id_local}">
            ${icono('ok')}Aplicar el mío
          </button>
        </div>

        ${t.id_ticket ? `<button type="button" class="btn btn--texto btn--bloque btn--s" data-abrir="${t.id_ticket}"
             style="margin-top:6px">Ver el parte completo</button>` : ''}
      </div>
    </div>`;
  }

  await cargar();
  return {};
}
