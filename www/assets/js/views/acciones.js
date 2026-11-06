/* =====================================================================
   Acciones rápidas.

   Un técnico con ocho partes asignados no debería abrir ocho fichas para
   marcarlos. Desde el listado, un toque abre la hoja y otro resuelve.

   Los estados que se ofrecen dependen de la clase del parte: montar una
   cuna no necesita «Pte. Terceros», y ofrecerlo es justo lo que hace que
   la gente deje de usar una herramienta.
   ===================================================================== */

import * as sync from '../sync.js';
import * as db from '../db.js';
import { hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, minutosATexto,
} from '../ui.js';
import { app, modulo, ajuste } from '../app.js';
import { tono } from '../sonido.js';

/** Estados aplicables a un parte, según su clase. */
export function estadosPara(ticket) {
  const todos = app.catalogo?.estados || [];
  const clase = ticket.clase || 'averia';
  return todos.filter((e) => !e.clases || e.clases.includes(clase));
}

/** ¿Puede esta persona mover este parte? */
function puedeTocar(t) {
  const u = app.usuario;
  if (u.es_admin || u.es_responsable) return true;
  if (t.id_persona_asignada === u.id_login) return true;
  if (t.id_realiza_accion === u.id_login) return true;
  if (t.id_grupo_asignado && t.id_grupo_asignado === u.id_departamento) return true;
  return false;
}

/**
 * Hoja de acción rápida sobre un parte.
 * Devuelve true si se hizo algo, para que quien llama refresque.
 */
/**
 * ¿Puede quien mira quedarse este parte?
 *
 * Sólo si va a su departamento. Si no, cualquiera podría llevarse
 * trabajo de otro oficio: un camarero cogiendo una avería eléctrica no
 * la va a resolver, y mientras tanto los electricistas dejan de verla
 * sin asignar.
 *
 * Los responsables sí pueden: repartir es su trabajo.
 */
export function puedeQuedarselo(ticket) {
  const u = app.usuario || {};
  if (u.es_responsable || u.es_admin) return true;
  const grupo = ticket.id_grupo_asignado;
  if (!grupo) return true;               // sin grupo, está huérfano
  return u.id_departamento === grupo;
}

export function accionRapida(ticket) {
  return new Promise((resolve) => {
    let hecho = false;

    if (!puedeTocar(ticket)) {
      mensaje('Este parte no está asignado a ti ni a tu grupo.', 'error');
      resolve(false);
      return;
    }

    const yo = app.usuario.id_login;
    const estados = estadosPara(ticket).filter((e) => e.id_estado !== ticket.id_estado);
    const rapidos = estados.filter((e) => e.rapido);
    const resto   = estados.filter((e) => !e.rapido);

    const mio = ticket.id_persona_asignada === yo;

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="parte__alto" style="margin-bottom:10px">
        <span class="parte__codigo">${esc(ticket.codigo)}</span>
        <span class="parte__estado" style="--estado:${esc(ticket.estado_color || '#64748B')}">
          ${esc(ticket.nombre_estado || '')}</span>
      </div>
      <div class="parte__titulo" style="margin-bottom:4px">${esc(ticket.titulo_ticket)}</div>
      ${ticket.zona_ruta ? `<div class="parte__ruta" style="margin-bottom:14px">
        ${icono('ubicacion')}<span>${esc(ticket.zona_ruta)}</span></div>` : '<div style="height:10px"></div>'}

      ${!mio && puedeQuedarselo(ticket) ? `
        <button type="button" class="btn btn--senal btn--bloque" data-a="cogerlo" style="margin-bottom:9px">
          ${icono('mios')}Me lo quedo
        </button>` : ''}
      ${!mio && !puedeQuedarselo(ticket) ? `
        <div class="campo__ayuda" style="text-align:center;margin-bottom:9px">
          Va a ${esc(ticket.departamento || 'otro departamento')}: sólo puede
          cogerlo alguien de ahí.
        </div>` : ''}

      ${rapidos.map((e) => `
        <button type="button" class="btn btn--bloque" data-estado="${e.id_estado}"
                style="margin-bottom:9px;background:color-mix(in srgb, ${esc(e.color)} 14%, transparent);
                       color:${esc(e.color)}">
          ${icono(e.es_final ? 'ok' : 'banderin')}${esc(e.nombre_estado)}
        </button>`).join('')}

      <div class="acciones" style="margin-top:4px">
        <button type="button" class="btn btn--contorno btn--s" data-a="nota">${icono('nota')}Nota</button>
        ${resto.length ? `<button type="button" class="btn btn--contorno btn--s" data-a="otros">
          ${icono('lista')}Otro estado</button>` : ''}
        <button type="button" class="btn btn--contorno btn--s" data-a="abrir">${icono('flecha')}Abrir</button>
      </div>

      ${!hayRed() ? `<div class="campo__ayuda" style="text-align:center;margin-top:12px">
        ${icono('sinred')} Sin conexión: se guardará y se enviará después.</div>` : ''}`;

    hoja({
      titulo: 'Acción rápida',
      cuerpo,
      alAbrir: (h) => {
        const cerrarCon = async (cambios, texto) => {
          try {
            await sync.actualizarTicket(ticket, {
              ...cambios,
              autor: [app.usuario.nombre, app.usuario.apellido1].filter(Boolean).join(' '),
            });
            hecho = true;
            tono(hayRed() ? 'ok' : 'guardado');
            mensaje(texto, 'ok');
            cerrarHoja();
          } catch (e) {
            tono('error');
            mensaje(e.message, 'error');
          }
        };

        $$('[data-estado]', h).forEach((b) => {
          b.onclick = async () => {
            const id = parseInt(b.dataset.estado, 10);
            const e = estados.find((x) => x.id_estado === id);

            // Cerrar exige nota si el administrador lo ha pedido: es lo
            // que convierte el historial en algo que se puede consultar.
            const pideNota = e.es_final && ajuste('cerrar_pide_nota', true);
            const datos = pideNota || modulo('tiempo')
              ? await pedirCierre(e, pideNota)
              : { nota: '', minutos: 0 };
            if (datos === null) return;

            const cambios = {
              id_estado: id,
              nombre_estado: e.nombre_estado,
              estado_color: e.color,
              es_final: e.es_final,
              nota: datos.nota,
              minutos: datos.minutos,
            };
            // Quien resuelve algo que no tenía asignado se lo queda: si no,
            // el parte queda cerrado sin constar quién lo hizo.
            if (!mio && ajuste('autoasignar_al_coger', true)) {
              cambios.id_persona_asignada = yo;
              cambios.asignado_a = [app.usuario.nombre, app.usuario.apellido1].filter(Boolean).join(' ');
            }
            await cerrarCon(cambios, `${ticket.codigo} en «${e.nombre_estado}».`);
          };
        });

        const coger = $('[data-a="cogerlo"]', h);
        if (coger) coger.onclick = () => cerrarCon({
          id_persona_asignada: yo,
          asignado_a: [app.usuario.nombre, app.usuario.apellido1].filter(Boolean).join(' '),
          nota: 'Me hago cargo.',
        }, `${ticket.codigo} asignado a ti.`);

        $('[data-a="nota"]', h).onclick = async () => {
          const datos = await pedirCierre(null, true);
          if (datos === null) return;
          await cerrarCon({ nota: datos.nota, minutos: datos.minutos }, 'Nota añadida.');
        };

        const otros = $('[data-a="otros"]', h);
        if (otros) otros.onclick = async () => {
          const { elegir } = await import('../ui.js');
          const v = await elegir({
            titulo: 'Cambiar a',
            valor: ticket.id_estado,
            opciones: resto.map((e) => ({
              valor: e.id_estado, texto: e.nombre_estado, color: e.color,
              detalle: e.es_final ? 'Cierra el parte' : '',
            })),
          });
          if (!v) return;
          const e = resto.find((x) => x.id_estado === v);
          const datos = await pedirCierre(e, e.es_final && ajuste('cerrar_pide_nota', true));
          if (datos === null) return;
          await cerrarCon({
            id_estado: v, nombre_estado: e.nombre_estado, estado_color: e.color,
            es_final: e.es_final, nota: datos.nota, minutos: datos.minutos,
          }, `${ticket.codigo} en «${e.nombre_estado}».`);
        };

        $('[data-a="abrir"]', h).onclick = () => {
          cerrarHoja();
          location.hash = `#/ticket/${ticket.id_ticket}`;
        };
      },
      alCerrar: () => resolve(hecho),
    });
  });
}

/** Nota y tiempo, en una hoja compacta. */
function pedirCierre(estado, notaObligatoria) {
  return new Promise((resolve) => {
    let resuelto = false;
    const conTiempo = modulo('tiempo');

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo">
        <label for="ar-nota">${estado ? 'Qué has hecho' : 'Nota'}${notaObligatoria ? '' : ' (opcional)'}</label>
        <textarea class="area" id="ar-nota" style="min-height:84px"
                  placeholder="Una línea basta"></textarea>
        <div class="campo__error oculto" id="ar-err">Escribe algo antes de guardar.</div>
      </div>
      ${conTiempo ? `
      <div class="campo" style="margin-bottom:0">
        <label>Tiempo dedicado</label>
        <div class="opciones" id="ar-tiempo">
          ${[0, 10, 20, 30, 60, 120].map((m) => `
            <button type="button" class="opcion" data-min="${m}" aria-pressed="${m === 0}">
              ${m === 0 ? 'Sin anotar' : minutosATexto(m)}</button>`).join('')}
        </div>
      </div>` : ''}`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: estado ? estado.nombre_estado : 'Añadir nota',
      cuerpo, pie,
      alAbrir: (h) => {
        const area = $('#ar-nota', h);
        setTimeout(() => area.focus(), 250);

        let minutos = 0;
        const grupo = $('#ar-tiempo', h);
        if (grupo) {
          grupo.onclick = (e) => {
            const b = e.target.closest('[data-min]');
            if (!b) return;
            $$('[data-min]', grupo).forEach((x) => x.setAttribute('aria-pressed', 'false'));
            b.setAttribute('aria-pressed', 'true');
            minutos = parseInt(b.dataset.min, 10);
          };
        }

        $('[data-no]', h).onclick = () => { resuelto = true; resolve(null); cerrarHoja(); };
        $('[data-si]', h).onclick = () => {
          const nota = area.value.trim();
          if (notaObligatoria && !nota) {
            $('#ar-err', h).classList.remove('oculto');
            area.focus();
            return;
          }
          resuelto = true;
          resolve({ nota, minutos });
          cerrarHoja();
        };
      },
      alCerrar: () => { if (!resuelto) resolve(null); },
    });
  });
}
