/* =====================================================================
   Gestión de partes.

   Buscar, seleccionar y actuar sobre varios a la vez, incluido
   eliminarlos definitivamente.

   Existe como pantalla propia porque el borrado en masa no es algo que
   se haga desde el listado del día a día: se busca a propósito, con la
   cabeza puesta en limpiar. Y porque escondido dentro de un menú de un
   parte concreto no lo encontraba nadie.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, confirmar, elegir, elegirBuscando, fechaCorta, enfocarBuscador } from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';

export async function vistaGestionPartes({ contenido }) {
  pintarCabecera(cabeceraVolver('Gestión de partes', 'Buscar, mover y eliminar'));

  if (!app.usuario.es_responsable && !app.usuario.es_admin) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Hace falta ser responsable o administrador.</div></div>`;
    return {};
  }
  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Esta pantalla necesita conexión.</div></div>`;
    return {};
  }

  const marcados = new Set();
  let lista = [];
  let filtros = { limite: 100, orden: 'reciente' };

  async function cargar() {
    $('#gp-lista', contenido).innerHTML = bloqueCarga(3);
    try {
      const r = await api.tickets(filtros);
      lista = r.tickets || [];
    } catch (e) {
      $('#gp-lista', contenido).innerHTML =
        `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    // Lo marcado que ya no está en la lista deja de contar
    for (const id of [...marcados]) {
      if (!lista.some((t) => t.id_ticket === id)) marcados.delete(id);
    }
    pintarLista();
  }

  function pintar() {
    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Aquí se trabaja sobre varios partes a la vez. Lo que se elimina
        definitivamente sale de la aplicación con su historial, sus notas y
        sus fotos, pero queda guardado en la papelera: desde allí puede
        devolverlo un administrador.
      </div></div>

      <div class="buscador" style="margin-bottom:10px">
        ${icono('buscar')}
        <input class="entrada" id="gp-q" type="search" enterkeyhint="search"
               placeholder="Buscar por texto, código o ubicación" autocomplete="off">
      </div>

      <div class="carril" style="margin-bottom:12px">
        <button type="button" class="pastilla" data-f="todos" aria-pressed="${!filtros.abiertos && !filtros.estado}">
          ${icono('lista')}Todos</button>
        <button type="button" class="pastilla" data-f="abiertos" aria-pressed="${!!filtros.abiertos}">
          ${icono('banderin')}Sólo abiertos</button>
        <button type="button" class="pastilla" data-f="cerrados" aria-pressed="${!!filtros.estado}">
          ${icono('ok')}Sólo cerrados</button>
        <button type="button" class="pastilla" data-f="antiguos" aria-pressed="${filtros.orden === 'antiguo'}">
          ${icono('reloj')}Más antiguos primero</button>
      </div>

      <div class="acciones" style="margin-bottom:12px">
        <button type="button" class="btn btn--contorno btn--s" data-todos>Marcar todos</button>
        <button type="button" class="btn btn--contorno btn--s" data-ninguno>Desmarcar</button>
      </div>

      <div id="gp-lista"></div>`;

    const q = $('#gp-q', contenido);
    enfocarBuscador($('#gp-q', contenido));
    let reloj = null;
    q.oninput = () => {
      clearTimeout(reloj);
      reloj = setTimeout(() => {
        filtros.q = q.value.trim() || undefined;
        cargar();
      }, 320);
    };

    $$('[data-f]', contenido).forEach((b) => {
      b.onclick = () => {
        const f = b.dataset.f;
        if (f === 'todos') { delete filtros.abiertos; delete filtros.estado; }
        if (f === 'abiertos') { filtros.abiertos = true; delete filtros.estado; }
        if (f === 'cerrados') {
          delete filtros.abiertos;
          filtros.estado = (app.catalogo?.estados || [])
            .filter((e) => e.es_final).map((e) => e.id_estado);
        }
        if (f === 'antiguos') {
          filtros.orden = filtros.orden === 'antiguo' ? 'reciente' : 'antiguo';
        }
        pintar();
        cargar();
      };
    });

    $('[data-todos]', contenido).onclick = () => {
      lista.forEach((t) => marcados.add(t.id_ticket));
      pintarLista();
    };
    $('[data-ninguno]', contenido).onclick = () => { marcados.clear(); pintarLista(); };
  }

  function pintarLista() {
    const caja = $('#gp-lista', contenido);
    caja.innerHTML = lista.length ? `
      <div class="rotulo" style="margin:0 2px 8px">
        ${lista.length} ${lista.length === 1 ? 'parte' : 'partes'}
      </div>
      <div class="filas">
        ${lista.map((t) => `
          <div class="fila" data-t="${t.id_ticket}" role="button" tabindex="0"
               style="${marcados.has(t.id_ticket) ? 'background:var(--marca-tenue)' : ''}">
            <span class="parte__marca${marcados.has(t.id_ticket) ? ' parte__marca--si' : ''}">
              ${marcados.has(t.id_ticket) ? '✓' : ''}</span>
            <span class="fila__texto">${esc(t.titulo_ticket || '(sin título)')}
              <small>${esc(t.codigo)} · ${esc(t.nombre_estado || '')}
                · ${esc(fechaCorta(t.fecha_creado))}
                ${t.zona_ruta ? ' · ' + esc(t.zona_ruta) : ''}</small></span>
          </div>`).join('')}
      </div>`
      : estadoVacio({ icono: 'buscar', titulo: 'Nada con esos filtros', texto: '' });

    $$('[data-t]', caja).forEach((b) => {
      b.onclick = () => {
        const id = parseInt(b.dataset.t, 10);
        if (marcados.has(id)) marcados.delete(id); else marcados.add(id);
        pintarLista();
      };
    });

    pintarBarra();
  }

  function pintarBarra() {
    let barra = document.getElementById('gp-barra');
    if (!marcados.size) { barra?.remove(); return; }
    if (!barra) {
      barra = document.createElement('div');
      barra.id = 'gp-barra';
      barra.className = 'barra-seleccion';
      document.body.appendChild(barra);
    }
    barra.innerHTML = `
      <strong>${marcados.size} marcado${marcados.size > 1 ? 's' : ''}</strong>
      <button type="button" class="btn btn--contorno btn--s" data-mover>Mover</button>
      <button type="button" class="btn btn--peligro btn--s" data-eliminar>
        ${icono('papelera')}Eliminar</button>`;
    barra.querySelector('[data-mover]').onclick = mover;
    barra.querySelector('[data-eliminar]').onclick = eliminar;
  }

  async function mover() {
    const v = await elegir({
      titulo: `${marcados.size} partes`,
      opciones: [
        { valor: 'estado', texto: 'Cambiar el estado', icono: 'banderin' },
        { valor: 'grupo', texto: 'Pasar a otro departamento', icono: 'personas' },
        /* «Ocultar», no «Mandar a la papelera».
           Esto marca el parte como borrado y deja de verse, pero NO
           entra en la papelera: allí sólo va lo que se elimina del
           todo. Llamarlo papelera prometía un sitio donde recuperarlo
           que no existe — y eso se descubre el día que hace falta. */
        { valor: 'ocultar', texto: 'Ocultar de las listas',
          detalle: 'Deja de verse · el historial se conserva', icono: 'ojo-no' },
      ],
    });
    if (!v) return;

    if (v === 'ocultar') {
      const ok = await confirmar({
        titulo: `Ocultar ${marcados.size} parte${marcados.size > 1 ? 's' : ''}`,
        texto: 'Dejan de verse en la aplicación y el historial se conserva. '
             + 'No van a la papelera: para eso hay que eliminarlos.',
        aceptar: 'Ocultar', peligro: true,
      });
      if (ok) await lote({ accion: 'borrar' });
      return;
    }
    if (v === 'estado') {
      const est = await elegir({
        titulo: 'Nuevo estado',
        opciones: (app.catalogo?.estados || []).map((e) => ({
          valor: e.id_estado, texto: e.nombre_estado, color: e.color })),
      });
      if (est) await lote({ accion: 'estado', id_estado: est });
      return;
    }
    const g = await elegirBuscando({
      titulo: 'A qué departamento',
      opciones: (app.catalogo?.departamentos || []).map((d) => ({
        valor: d.id_departamento, texto: d.nombre, icono: 'personas' })),
      marcador: 'Buscar departamento',
    });
    if (g) await lote({ accion: 'asignar', id_grupo_asignado: g, id_persona_asignada: null });
  }

  async function eliminar() {
    if (!app.usuario.es_admin) {
      mensaje('Sólo un administrador puede eliminar partes definitivamente.', 'error');
      return;
    }
    const n = marcados.size;
    const ok = await confirmar({
      titulo: `Eliminar ${n} ${n === 1 ? 'parte' : 'partes'}`,
      texto: 'Se borran de la base de datos con su historial, sus notas y sus fotos. '
           + 'No van a la papelera: desaparecen. Esto no se puede deshacer.',
      aceptar: `Eliminar ${n}`,
      peligro: true,
    });
    if (!ok) return;
    await lote({ accion: 'eliminar' });
  }

  async function lote(datos) {
    try {
      const r = await api.loteTickets({ ids: [...marcados], ...datos });
      for (const id of marcados) { await db.borrar('tickets', id).catch(() => {}); }
      marcados.clear();
      tono('ok');
      mensaje(r.mensaje, 'ok');
      cargar();
    } catch (e) { mensaje(e.message, 'error'); }
  }

  pintar();
  await cargar();
  return {
    refrescar: cargar,
    limpiar() { document.getElementById('gp-barra')?.remove(); },
  };
}
