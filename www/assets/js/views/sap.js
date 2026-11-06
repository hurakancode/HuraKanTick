/* =====================================================================
   Las pantallas del módulo SAP.

   Vive aparte a propósito: si mañana se borra la carpeta del módulo,
   esto se borra con ella y la aplicación no se entera.

   Cada pantalla enseña sólo lo que el servidor le manda, y el servidor
   ya ha recortado según el alcance de quien mira. Aquí no se esconde
   nada: lo que no debe verse, no ha llegado.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, mensaje, fechaLarga } from '../ui.js';
import { cabeceraVolver, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';

/* Qué significa cada estado, en palabras de hotel. */
const ESTADOS = {
  ocupada:   { texto: 'Ocupada',        color: '#B45309', icono: 'personas' },
  libre:     { texto: 'Libre',          color: '#15803D', icono: 'ok' },
  limpia:    { texto: 'Limpia',         color: '#15803D', icono: 'ok' },
  sucia:     { texto: 'Sucia',          color: '#B45309', icono: 'aviso' },
  bloqueada: { texto: 'Bloqueada',      color: '#B91C1C', icono: 'candado' },
  limpieza:  { texto: 'En limpieza',    color: '#0B5563', icono: 'reloj' },
};

function pinta(estado) {
  return ESTADOS[(estado || '').toLowerCase()]
      || { texto: estado || 'sin dato', color: 'var(--tinta-3)', icono: 'info' };
}

/** La tarjeta con lo que sepamos de una habitación. */
export function tarjetaHabitacion(d, { compacta = false } = {}) {
  if (!d) return '';
  const e = pinta(d.estado);
  const huespedes = d.huespedes || [];

  const avisos = [
    d.no_molestar ? 'No molestar' : '',
    d.salida_hoy ? 'Sale hoy' : '',
  ].filter(Boolean);

  return `
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:${compacta ? 6 : 10}px">
        <span class="punto" style="background:${e.color};width:10px;height:10px"></span>
        <b style="font-size:${compacta ? 15 : 17}px">Habitación ${esc(d.habitacion)}</b>
        <span class="rotulo" style="margin-left:auto;color:${e.color}">${esc(e.texto)}</span>
      </div>

      ${d.ocupada === undefined ? '' : `
        <p style="margin:0 0 8px;font-size:14px">
          ${d.ocupada ? 'Hay alguien dentro.' : 'No hay nadie dentro.'}
        </p>`}

      ${huespedes.length ? `
        <div class="filas" style="margin:0 0 8px">
          ${huespedes.map((g) => `
            <div class="fila" style="cursor:default;padding-left:0">
              ${icono('persona')}
              <span class="fila__texto">${esc(g.nombre || 'Huésped')}
                ${g.idioma ? `<small>${esc(g.pais || '')} · habla ${esc(g.idioma)}</small>`
                           : (g.pais ? `<small>${esc(g.pais)}</small>` : '')}</span>
            </div>`).join('')}
        </div>` : ''}

      ${(d.desde || d.hasta) ? `
        <p style="margin:0 0 6px;font-size:13.5px;color:var(--tinta-2)">
          ${d.desde ? 'Entró el ' + esc(fechaLarga(d.desde).split(',')[0]) : ''}
          ${d.hasta ? ' · se va el ' + esc(fechaLarga(d.hasta).split(',')[0]) : ''}
        </p>` : ''}

      ${(d.adultos || d.ninos) ? `
        <p style="margin:0 0 6px;font-size:13.5px;color:var(--tinta-2)">
          ${d.adultos || 0} adulto${(d.adultos || 0) === 1 ? '' : 's'}${
            d.ninos ? ` · ${d.ninos} niño${d.ninos === 1 ? '' : 's'}` : ''}</p>` : ''}

      ${avisos.length ? `
        <div class="aviso aviso--info" style="margin:8px 0 0">${icono('aviso')}
          <div>${avisos.map(esc).join(' · ')}</div></div>` : ''}

      ${d.de_cache ? `<p style="margin:8px 0 0;font-size:12px;color:var(--tinta-3)">
        Dato guardado hace unos minutos.</p>` : ''}
    </div></div>`;
}

/* ------------------------------------------------------------------ */
/* Consultar una habitación                                            */
/* ------------------------------------------------------------------ */

export async function vistaSapHabitacion({ contenido }) {
  pintarCabecera(cabeceraVolver('Consultar una habitación'));

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Esto pregunta al sistema del hotel y necesita conexión.</div></div>`;
    return {};
  }

  contenido.innerHTML = `
    <div class="buscador" style="margin-bottom:12px">
      ${icono('buscar')}
      <input type="search" id="hab" placeholder="Número de habitación" inputmode="numeric"
             autocomplete="off" enterkeyhint="search">
    </div>
    <div id="resultado"></div>`;

  const caja = $('#resultado', contenido);
  const campo = $('#hab', contenido);

  const consultar = async () => {
    const n = campo.value.trim();
    if (!n) { caja.innerHTML = ''; return; }
    caja.innerHTML = bloqueCarga(1);
    try {
      const d = await api.sapHabitacion(n);
      caja.innerHTML = tarjetaHabitacion(d);
    } catch (e) {
      caja.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
        <div>${esc(e.message)}</div></div>`;
    }
  };

  let espera = null;
  campo.oninput = () => { clearTimeout(espera); espera = setTimeout(consultar, 450); };
  campo.onkeydown = (e) => { if (e.key === 'Enter') { clearTimeout(espera); consultar(); } };
  campo.focus();

  return {};
}

/* ------------------------------------------------------------------ */
/* Conexión y prueba                                                   */
/* ------------------------------------------------------------------ */

export async function vistaSapConexion({ contenido }) {
  pintarCabecera(cabeceraVolver('Conexión con SAP'));

  contenido.innerHTML = `
    <div class="aviso aviso--info">${icono('info')}<div>
      Los datos de la conexión se rellenan en Configuración → Conector con SAP.
      Aquí se comprueba que responden.
    </div></div>

    <div class="filas" style="margin-bottom:14px">
      <button type="button" class="fila" data-probar>
        ${icono('enlace')}
        <span class="fila__texto">Probar la conexión
          <small>Pregunta por una habitación y cuenta qué pasa</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
      <button type="button" class="fila" data-ajustes>
        ${icono('ajustes')}
        <span class="fila__texto">Rellenar los datos
          <small>Dirección, usuario, mandante y qué puede ver cada uno</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>
    <div id="prueba"></div>`;

  $('[data-ajustes]', contenido).onclick = () => {
    try { sessionStorage.setItem('hurakantick_ir_ajuste', 'sap_tipo'); } catch { /* da igual */ }
    ir('/admin/ajustes');
  };

  $('[data-probar]', contenido).onclick = async () => {
    const caja = $('#prueba', contenido);
    caja.innerHTML = bloqueCarga(1);
    try {
      const r = await api.sapProbar();
      caja.innerHTML = `
        <div class="aviso aviso--${r.ok ? 'ok' : 'error'}">${icono(r.ok ? 'ok' : 'aviso')}
          <div>${esc(r.resumen || '')}</div></div>
        <div class="tarjeta"><div class="tarjeta__cuerpo" style="padding:4px 0">
          ${(r.pasos || []).map((p) => `
            <div class="fila" style="cursor:default">
              ${icono(p.ok ? 'ok' : 'aviso')}
              <span class="fila__texto">${esc(p.paso)}
                <small>${esc(p.detalle || '')}</small></span>
            </div>`).join('')}
        </div></div>
        ${r.ejemplo ? `<div class="seccion"><h2>Lo que devuelve</h2></div>
          ${tarjetaHabitacion(r.ejemplo)}` : ''}`;
    } catch (e) {
      caja.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
        <div>${esc(e.message)}</div></div>`;
    }
  };

  return {};
}

/* ------------------------------------------------------------------ */
/* Estado de varias habitaciones                                       */
/* ------------------------------------------------------------------ */

export async function vistaSapEstados({ contenido }) {
  pintarCabecera(cabeceraVolver('Estado de las habitaciones'));

  contenido.innerHTML = `
    <div class="aviso aviso--info">${icono('info')}<div>
      Escribe un rango —por ejemplo <b>101-120</b>— y se consultan de una vez.
      No pidas cientos de golpe: cada una es una pregunta a SAP.
    </div></div>
    <div class="buscador" style="margin-bottom:12px">
      ${icono('buscar')}
      <input type="search" id="rango" placeholder="101-120" autocomplete="off">
    </div>
    <div id="lista"></div>`;

  const caja = $('#lista', contenido);
  const campo = $('#rango', contenido);

  campo.onkeydown = async (e) => {
    if (e.key !== 'Enter') return;
    const m = campo.value.trim().match(/^(\d+)\s*-\s*(\d+)$/);
    const numeros = m
      ? Array.from({ length: Math.min(40, Math.abs(+m[2] - +m[1]) + 1) },
                   (_, i) => String(Math.min(+m[1], +m[2]) + i))
      : campo.value.split(',').map((x) => x.trim()).filter(Boolean).slice(0, 40);

    if (!numeros.length) { caja.innerHTML = ''; return; }
    caja.innerHTML = bloqueCarga(2);

    const filas = [];
    for (const n of numeros) {
      try {
        const d = await api.sapHabitacion(n);
        const e2 = pinta(d.estado);
        filas.push(`
          <button type="button" class="fila" data-hab="${esc(n)}">
            <span class="punto" style="background:${e2.color}"></span>
            <span class="fila__texto">${esc(n)}
              <small>${esc(e2.texto)}${d.huespedes?.[0]?.idioma
                ? ' · habla ' + esc(d.huespedes[0].idioma) : ''}</small></span>
          </button>`);
      } catch {
        filas.push(`
          <div class="fila" style="cursor:default">
            ${icono('info')}
            <span class="fila__texto">${esc(n)}<small>sin datos</small></span>
          </div>`);
      }
      caja.innerHTML = `<div class="filas">${filas.join('')}</div>`;
    }
  };

  campo.focus();
  return {};
}
