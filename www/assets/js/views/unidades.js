/* =====================================================================
   Aparatos con número de serie.

   La mayoría de los elementos no lo tienen: una cerradura o un zócalo se
   cambian y nadie los identifica. Pero un televisor sí, y entonces
   interesa saber cuál está puesto, desde cuándo y a cuál sustituyó.

   La pregunta que responde esta pantalla es «la 1008 ha tenido cinco
   televisores, cuatro dados de baja por rotura» — que no se contesta
   con el historial de partes, porque el parte habla de la habitación y
   no del aparato.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, elegirBuscando,
  confirmar, fechaLarga, fechaCorta, haceCuanto, enfocarBuscador } from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app, modulo } from '../app.js';
import { tono } from '../sonido.js';
import { elegirUbicacion } from './selectores.js';

const COLOR_ESTADO = {
  activa: 'var(--ok)',
  reparacion: 'var(--senal)',
  almacen: 'var(--tinta-3)',
  baja: 'var(--peligro)',
};

/* ================================================================== */
/* Buscador general                                                   */
/* ================================================================== */
export async function vistaUnidades({ contenido }) {
  pintarCabecera(cabeceraVolver('Números de serie', 'Los aparatos que lo llevan',
    app.usuario.rol !== 'reportador'
      ? `<button type="button" class="icono-btn" data-nueva aria-label="Dar de alta">${icono('mas')}</button>` : ''));

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>El inventario se consulta en el servidor y necesita conexión.</div></div>`;
    return {};
  }

  let q = '';

  async function cargar() {
    contenido.innerHTML = `
      <div class="buscador" style="margin-bottom:12px">
        ${icono('buscar')}
        <input class="entrada" id="u-q" type="search" enterkeyhint="search"
               placeholder="Número de serie, o parte de él" value="${esc(q)}"
               autocomplete="off" style="font-family:var(--mono)">
      </div>
      <div id="u-res">${q ? bloqueCarga(2) : `
        <div class="aviso aviso--info">${icono('info')}<div>
          Escribe un número de serie para localizarlo, o entra por la ficha de una
          habitación para ver todo lo que hay en ella.
        </div></div>`}</div>`;

    const entrada = $('#u-q', contenido);
    enfocarBuscador($('#u-q', contenido));
    let reloj = null;
    entrada.oninput = () => {
      clearTimeout(reloj);
      reloj = setTimeout(buscar, 280);
    };
    entrada.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); buscar(); } };
    if (q) buscar();
  }

  async function buscar() {
    const entrada = $('#u-q', contenido);
    q = entrada.value.trim();
    const caja = $('#u-res', contenido);
    if (!q) { caja.innerHTML = ''; return; }

    try {
      const r = await api.unidades({ q });
      caja.innerHTML = r.unidades.length
        ? `<div class="rotulo" style="margin:0 2px 8px">
             ${r.unidades.length} ${r.unidades.length === 1 ? 'aparato' : 'aparatos'}</div>
           ${listaUnidades(r.unidades)}`
        : estadoVacio({ icono: 'buscar', titulo: 'Sin resultados',
                        texto: 'No hay ningún aparato con ese número.' });
      engancharLista(caja);
    } catch (e) {
      caja.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
    }
  }

  const nueva = document.querySelector('[data-nueva]');
  if (nueva) nueva.onclick = () => altaUnidad(null, null, () => cargar());

  await cargar();
  return { refrescar: cargar };
}

/* ================================================================== */
/* Piezas reutilizables                                               */
/* ================================================================== */
export function listaUnidades(unidades) {
  return `<div class="filas">${unidades.map((u) => `
    <button type="button" class="fila" data-unidad="${u.id_unidad}" style="align-items:flex-start">
      <span class="avatar" style="background:${esc(COLOR_ESTADO[u.estado])}1f;
                                  color:${esc(COLOR_ESTADO[u.estado])}">
        ${icono(u.familia_icono || 'herramientas')}</span>
      <span class="fila__texto">
        <span style="font-family:var(--mono);font-size:13.5px">${esc(u.numero_serie)}</span>
        <small>${esc(u.elemento || '')}${u.marca ? ' · ' + esc(u.marca) : ''}
          ${u.modelo ? ' ' + esc(u.modelo) : ''}</small>
        <small style="display:block;margin-top:2px;color:${esc(COLOR_ESTADO[u.estado])};font-weight:640">
          ${esc(u.estado_nombre)}${u.motivo_nombre ? ' · ' + esc(u.motivo_nombre) : ''}
          ${u.garantia_viva ? ' · en garantía' : ''}</small>
        ${u.zona_ruta ? `<small style="display:block;color:var(--tinta-3)">
          ${esc(u.zona_ruta)}</small>` : ''}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>`).join('')}</div>`;
}

export function engancharLista(caja, alVolver) {
  $$('[data-unidad]', caja).forEach((b) => {
    b.onclick = () => abrirUnidad(parseInt(b.dataset.unidad, 10), alVolver);
  });
}

/* ================================================================== */
/* Ficha de un aparato                                                */
/* ================================================================== */
export async function abrirUnidad(idUnidad, alVolver) {
  let d;
  try { d = await api.unidad(idUnidad); }
  catch (e) { mensaje(e.message, 'error'); return; }

  const u = d.unidad;
  const puedeTocar = app.usuario.rol !== 'reportador';

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="parte__alto" style="margin-bottom:12px">
      <span class="parte__estado" style="--estado:${esc(COLOR_ESTADO[u.estado])}">
        ${esc(u.estado_nombre)}</span>
      ${u.motivo_nombre ? `<span class="prio" style="--prio-color:var(--peligro)">
        ${esc(u.motivo_nombre)}</span>` : ''}
      ${u.garantia_viva ? `<span class="prio" style="--prio-color:var(--ok)">en garantía</span>` : ''}
    </div>

    <dl class="datos" style="margin-top:0">
      <div class="datos__celda"><dt>Tipo</dt><dd>${esc(u.elemento || '—')}</dd></div>
      ${u.marca || u.modelo ? `<div class="datos__celda"><dt>Modelo</dt>
        <dd>${esc([u.marca, u.modelo].filter(Boolean).join(' '))}</dd></div>` : ''}
      <div class="datos__celda"><dt>Dónde está</dt><dd>${esc(u.zona_ruta || 'Sin ubicar')}</dd></div>
      <div class="datos__celda"><dt>Desde</dt><dd>${esc(fechaCorta(u.fecha_alta))}</dd></div>
      ${u.fecha_baja ? `<div class="datos__celda"><dt>De baja el</dt>
        <dd>${esc(fechaCorta(u.fecha_baja))}</dd></div>` : ''}
      ${u.garantia_hasta ? `<div class="datos__celda"><dt>Garantía hasta</dt>
        <dd>${esc(fechaCorta(u.garantia_hasta))}</dd></div>` : ''}
      ${u.serie_anterior ? `<div class="datos__celda"><dt>Sustituyó a</dt>
        <dd style="font-family:var(--mono);font-size:13px">${esc(u.serie_anterior)}</dd></div>` : ''}
    </dl>

    ${u.notas ? `<p style="font-size:13.5px;color:var(--tinta-2);margin:12px 0">
      ${esc(u.notas)}</p>` : ''}

    ${d.tickets.length ? `
      <div class="seccion"><h2>Partes de este aparato</h2>
        <span class="rotulo">${d.tickets.length}</span></div>
      <div class="filas">
        ${d.tickets.slice(0, 10).map((t) => `
          <button type="button" class="fila" data-t="${t.id_ticket}">
            <span class="avatar" style="background:${esc(t.estado_color)}22;color:${esc(t.estado_color)}">●</span>
            <span class="fila__texto">${esc(t.titulo_ticket)}
              <small>${esc(t.codigo)} · ${esc(t.nombre_estado)} · ${esc(fechaCorta(t.fecha_creado))}</small></span>
          </button>`).join('')}
      </div>` : ''}

    ${d.movimientos.length ? `
      <div class="seccion"><h2>Su historia</h2></div>
      <ol class="linea">
        ${d.movimientos.map((m) => `
          <li class="hito">
            <span class="hito__punto" style="--punto:${esc(COLOR_ESTADO[m.estado] || 'var(--marca)')}"></span>
            <div class="hito__cuerpo">
              <div class="hito__alto">
                <strong>${esc(m.estado_nombre)}</strong>
                <span class="hito__hora">${esc(haceCuanto(m.fecha))}</span>
              </div>
              ${m.motivo ? `<div class="hito__texto">${esc(m.motivo)}</div>` : ''}
              ${m.zona_ruta ? `<div class="hito__meta">${esc(m.zona_ruta)}</div>` : ''}
              ${m.persona ? `<div class="hito__meta">${esc(m.persona)}</div>` : ''}
            </div>
          </li>`).join('')}
      </ol>` : ''}`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = puedeTocar
    ? `<button type="button" class="btn btn--contorno" data-cambiar>${icono('editar')}Cambiar</button>
       <button type="button" class="btn btn--principal" data-cerrar-u>Cerrar</button>`
    : `<button type="button" class="btn btn--principal btn--bloque" data-cerrar-u>Cerrar</button>`;

  hoja({
    titulo: u.numero_serie,
    cuerpo, pie,
    alAbrir: (h) => {
      $('[data-cerrar-u]', h).onclick = () => cerrarHoja();
      $$('[data-t]', h).forEach((b) => {
        b.onclick = () => { cerrarHoja(); ir(`/ticket/${b.dataset.t}`); };
      });
      const c = $('[data-cambiar]', h);
      if (c) c.onclick = () => { cerrarHoja(); cambiarUnidad(u, d, alVolver); };
    },
  });
}

/* ================================================================== */
/* Cambios de estado y ubicación                                      */
/* ================================================================== */
function cambiarUnidad(u, d, alVolver) {
  const est = { estado: u.estado, motivo_baja: u.motivo_baja, zona: { id_rel: u.id_rel_zona, ruta: u.zona_ruta || '' } };

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo">
      <label>Estado</label>
      <div class="opciones" id="cu-estado">
        ${Object.entries(d.estados).map(([k, v]) => `
          <button type="button" class="opcion" data-e="${k}" aria-pressed="${est.estado === k}">
            ${esc(v)}</button>`).join('')}
      </div>
    </div>

    <div class="campo" id="cu-motivo-caja" ${est.estado === 'baja' ? '' : 'hidden'}>
      <label>Por qué se da de baja</label>
      <div class="opciones" id="cu-motivo">
        ${Object.entries(d.motivos).map(([k, v]) => `
          <button type="button" class="opcion" data-m="${k}" aria-pressed="${est.motivo_baja === k}">
            ${esc(v)}</button>`).join('')}
      </div>
    </div>

    <div class="campo">
      <label>Dónde está</label>
      <button type="button" class="fila" id="cu-zona"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('ubicacion')}
        <span class="fila__texto" id="cu-txt-zona">${esc(est.zona.ruta || 'Sin ubicar')}
          <small>Toca para cambiar</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    <div class="campo"><label for="cu-motivo-texto">Nota del cambio</label>
      <input class="entrada" id="cu-motivo-texto" maxlength="120"
             placeholder="Queda en la historia del aparato"></div>

    <div id="cu-err"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  hoja({
    titulo: 'Cambiar ' + u.numero_serie,
    cuerpo, pie,
    alAbrir: (h) => {
      $('#cu-estado', h).onclick = (e) => {
        const b = e.target.closest('[data-e]');
        if (!b) return;
        $$('[data-e]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        est.estado = b.dataset.e;
        $('#cu-motivo-caja', h).hidden = est.estado !== 'baja';
      };
      $('#cu-motivo', h).onclick = (e) => {
        const b = e.target.closest('[data-m]');
        if (!b) return;
        $$('[data-m]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        est.motivo_baja = b.dataset.m;
      };
      $('#cu-zona', h).onclick = async () => {
        const r = await elegirUbicacion(est.zona);
        if (!r) return;
        est.zona = r;
        $('#cu-txt-zona', h).innerHTML =
          `${esc(r.ruta || 'Sin ubicar')}<small>Toca para cambiar</small>`;
      };
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = async () => {
        if (est.estado === 'baja' && !est.motivo_baja) {
          $('#cu-err', h).innerHTML =
            `<div class="aviso aviso--error">${icono('aviso')}<div>Indica por qué se da de baja.</div></div>`;
          return;
        }
        try {
          await api.cambiarUnidad(u.id_unidad, {
            estado: est.estado,
            motivo_baja: est.motivo_baja,
            id_rel_zona: est.zona.id_rel,
            motivo: $('#cu-motivo-texto', h).value.trim(),
          });
          cerrarHoja();
          tono('ok');
          mensaje('Aparato actualizado.', 'ok');
          if (alVolver) alVolver();
        } catch (e) {
          $('#cu-err', h).innerHTML =
            `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
        }
      };
    },
  });
}

/* ================================================================== */
/* Alta                                                               */
/* ================================================================== */
/**
 * Da de alta un aparato. Si sustituye a otro, aquel se da de baja
 * automáticamente: dos televisores no pueden estar puestos en la misma
 * pared, y olvidarse de dar de baja el viejo es lo que ensucia el
 * inventario con el tiempo.
 */
export function altaUnidad(zonaPrevia, elementoPrevio, alTerminar, sustituye) {
  const est = {
    id_elemento: elementoPrevio ?? null,
    zona: zonaPrevia || { id_rel: null, ruta: '' },
    id_sustituye: sustituye?.id_unidad ?? null,
    motivo_anterior: 'rotura',
  };

  const conSerie = (app.catalogo?.elementos || []).filter((e) => e.usa_serie);

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    ${sustituye ? `<div class="aviso aviso--senal" style="margin-bottom:14px">
      ${icono('info')}<div>Sustituye a <strong style="font-family:var(--mono)">
      ${esc(sustituye.numero_serie)}</strong>, que quedará de baja.</div></div>` : ''}

    <div class="campo"><label for="au-serie">Número de serie</label>
      <input class="entrada" id="au-serie" maxlength="80" autocomplete="off"
             style="font-family:var(--mono);font-size:16px"
             placeholder="Como venga en la etiqueta">
      <div class="campo__ayuda">Cópialo tal cual, con guiones y todo.</div></div>

    <div class="campo">
      <label>Qué aparato es</label>
      <button type="button" class="fila" id="au-elem"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('herramientas')}
        <span class="fila__texto" id="au-txt-elem">${esc(nombreElemento(est.id_elemento))}
          <small>Sólo los tipos que llevan número de serie</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    <div class="campo">
      <label>Dónde se instala</label>
      <button type="button" class="fila" id="au-zona"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('ubicacion')}
        <span class="fila__texto" id="au-txt-zona">${esc(est.zona.ruta || 'Elegir ubicación')}
          <small>Toca para cambiar</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    <div style="display:flex;gap:10px">
      <div class="campo" style="flex:1"><label for="au-marca">Marca</label>
        <input class="entrada" id="au-marca" maxlength="80" placeholder="Opcional"></div>
      <div class="campo" style="flex:1"><label for="au-modelo">Modelo</label>
        <input class="entrada" id="au-modelo" maxlength="120" placeholder="Opcional"></div>
    </div>

    <div class="campo"><label for="au-garantia">Garantía hasta</label>
      <input class="entrada" id="au-garantia" type="date">
      <div class="campo__ayuda">Antes de pagar una reparación conviene mirarlo.</div></div>

    ${sustituye ? `
      <div class="campo">
        <label>Y el anterior se da de baja por</label>
        <div class="opciones" id="au-motivo">
          ${[['rotura','Rota'],['obsoleto','Obsoleta'],['fin_vida','Fin de vida'],
             ['garantia','Cambio en garantía'],['robo','Robada']].map(([k,v]) => `
            <button type="button" class="opcion" data-ma="${k}"
                    aria-pressed="${est.motivo_anterior === k}">${esc(v)}</button>`).join('')}
        </div>
      </div>` : ''}

    <div id="au-err"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Dar de alta</button>`;

  hoja({
    titulo: sustituye ? 'Aparato de repuesto' : 'Alta de aparato',
    cuerpo, pie,
    alAbrir: (h) => {
      setTimeout(() => $('#au-serie', h).focus(), 250);

      $('#au-elem', h).onclick = async () => {
        const v = await elegirBuscando({
          titulo: 'Qué aparato es',
          opciones: conSerie.map((e) => ({ valor: e.id_elemento, texto: e.nombre, extra: e.abreviatura })),
          valor: est.id_elemento,
          marcador: `Buscar entre ${conSerie.length}`,
          vacio: 'Ningún tipo con ese nombre lleva número de serie. Se activa en Administración.',
        });
        if (v === null) return;
        est.id_elemento = v;
        $('#au-txt-elem', h).innerHTML =
          `${esc(nombreElemento(v))}<small>Sólo los tipos que llevan número de serie</small>`;
      };

      $('#au-zona', h).onclick = async () => {
        const r = await elegirUbicacion(est.zona);
        if (!r) return;
        est.zona = r;
        $('#au-txt-zona', h).innerHTML =
          `${esc(r.ruta || 'Sin ubicar')}<small>Toca para cambiar</small>`;
      };

      const gm = $('#au-motivo', h);
      if (gm) gm.onclick = (e) => {
        const b = e.target.closest('[data-ma]');
        if (!b) return;
        $$('[data-ma]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        est.motivo_anterior = b.dataset.ma;
      };

      $('[data-no]', h).onclick = () => cerrarHoja();

      $('[data-si]', h).onclick = async () => {
        const err = (t) => { $('#au-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };
        const serie = $('#au-serie', h).value.trim();
        if (!serie) return err('Falta el número de serie.');
        if (!est.id_elemento) return err('Indica qué aparato es.');

        const d = {
          numero_serie: serie,
          id_elemento: est.id_elemento,
          id_rel_zona: est.zona.id_rel,
          marca: $('#au-marca', h).value.trim(),
          modelo: $('#au-modelo', h).value.trim(),
          garantia_hasta: $('#au-garantia', h).value || null,
          id_sustituye: est.id_sustituye,
          motivo_anterior: est.motivo_anterior,
        };
        try {
          await api.crearUnidad(d);
          cerrarHoja();
          tono('ok');
          mensaje('Aparato dado de alta.', 'ok');
          if (alTerminar) alTerminar();
        } catch (e) {
          // Un número repetido no siempre es un error: dos fabricantes
          // pueden coincidir. Se ofrece seguir en vez de bloquear.
          if (e.codigo === 409) {
            const ok = await confirmar({
              titulo: 'Ese número ya existe',
              texto: e.message + '\n\n¿Lo das de alta igualmente?',
              aceptar: 'Sí, darlo de alta',
            });
            if (!ok) return;
            try {
              await api.crearUnidad({ ...d, forzar: true });
              cerrarHoja();
              mensaje('Aparato dado de alta.', 'ok');
              if (alTerminar) alTerminar();
            } catch (e2) { err(e2.message); }
          } else {
            err(e.message);
          }
        }
      };
    },
  });
}

function nombreElemento(id) {
  if (!id) return 'Elegir aparato';
  return app.catalogo?.elementos?.find((e) => e.id_elemento === id)?.nombre || '#' + id;
}
