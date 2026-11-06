/* =====================================================================
   Panel de un área.

   El aire acondicionado tenía su propio panel en la versión anterior, y
   con razón: es lo que más partes genera en un hotel y lo que más
   aparatos tiene detrás. Pero el problema no es del aire — es de
   cualquier área con volumen.

   Así que en vez de una pantalla para el A/A, una para cada familia. La
   del aire se alcanza de un toque desde el inicio; las demás, desde
   aquí. Todas enseñan lo mismo: qué hay abierto, dónde da guerra, qué
   aparatos hay, qué revisiones tocan y cómo se hacen las cosas.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, elegirBuscando, haceCuanto, fechaCorta, minutosATexto,
} from '../ui.js';
import { cabeceraVolver, tarjetaParte, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app, modulo } from '../app.js';
import { accionRapida } from './acciones.js';
import { abrirInstruccion } from './instrucciones.js';
import { abrirUnidad, listaUnidades } from './unidades.js';

export async function vistaFamilia({ contenido, args }) {
  const idFamilia = parseInt(args[0], 10);
  const familia = app.catalogo?.familias?.find((f) => f.id_familia === idFamilia);

  if (!familia) {
    pintarCabecera(cabeceraVolver('Área'));
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Esa área no existe o está desactivada.</div></div>`;
    return {};
  }

  pintarCabecera(cabeceraVolver(familia.nombre, familia.descripcion || '',
    `<button type="button" class="icono-btn" data-nuevo aria-label="Abrir un parte aquí">${icono('mas')}</button>`));

  contenido.innerHTML = bloqueCarga(4);

  async function cargar() {
    if (!hayRed()) {
      contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
        <div>Este panel consulta el servidor y necesita conexión.</div></div>`;
      return;
    }

    const [abiertos, porLugar, porTipo, unidades, mant] = await Promise.all([
      api.tickets({ familia: idFamilia, abiertos: true, orden: 'prioridad', limite: 60 })
        .catch(() => ({ tickets: [], total: 0 })),
      api.informeEjecutar({
        condiciones: [{ campo: 'familia', operador: 'es', valor: idFamilia, por: 'id' }],
        agrupar: 'lugar', medida: 'cuenta', limite: 8,
      }).catch(() => null),
      api.informeEjecutar({
        condiciones: [{ campo: 'familia', operador: 'es', valor: idFamilia, por: 'id' }],
        agrupar: 'elemento', medida: 'cuenta', limite: 8,
      }).catch(() => null),
      modulo('unidades')
        ? api.unidades({}).then((r) => ({
            ...r,
            unidades: (r.unidades || []).filter((u) => u.id_familia === idFamilia),
          })).catch(() => null)
        : Promise.resolve(null),
      modulo('mantenimiento')
        ? api.mantenimientos().then((r) => ({
            ...r,
            mantenimientos: (r.mantenimientos || []).filter((m) => m.id_familia === idFamilia),
          })).catch(() => null)
        : Promise.resolve(null),
    ]);

    const guias = (app.catalogo?.instrucciones || []).filter((i) => i.id_familia === idFamilia);
    const urgentes = (abiertos.tickets || []).filter((t) => (t.id_prioridad || 0) >= 4);
    const vencidos = (abiertos.tickets || []).filter(
      (t) => t.fecha_limite && new Date(t.fecha_limite) < new Date());

    contenido.innerHTML = `
      <div class="rejilla-cifras" style="margin-bottom:14px">
        <button type="button" class="cifra" data-filtro='${esc(JSON.stringify({ familia: idFamilia, abiertos: true, orden: 'prioridad' }))}'>
          <span class="cifra__marca" style="background:${esc(familia.color)}"></span>
          <div class="cifra__valor">${abiertos.total ?? 0}</div>
          <div class="cifra__nombre">Abiertos</div>
        </button>
        <button type="button" class="cifra ${vencidos.length ? 'cifra--alerta' : ''}"
                data-filtro='${esc(JSON.stringify({ familia: idFamilia, vencidos: true, abiertos: true, orden: 'limite' }))}'>
          <div class="cifra__valor">${vencidos.length}</div>
          <div class="cifra__nombre">Fuera de plazo</div>
        </button>
        ${unidades ? `
        <button type="button" class="cifra" data-unidades>
          <div class="cifra__valor">${unidades.unidades.filter((u) => u.estado === 'activa').length}</div>
          <div class="cifra__nombre">Aparatos en servicio</div>
        </button>` : ''}
        ${mant ? `
        <button type="button" class="cifra ${mant.mantenimientos.some((m) => m.vencido) ? 'cifra--alerta' : ''}"
                data-mant>
          <div class="cifra__valor">${mant.mantenimientos.length}</div>
          <div class="cifra__nombre">Revisiones</div>
        </button>` : ''}
      </div>

      <div class="acciones" style="margin-bottom:16px">
        <button type="button" class="btn btn--principal" data-nuevo-abajo>${icono('mas')}Abrir un parte</button>
        <button type="button" class="btn btn--contorno" data-informe>${icono('panel')}Informe</button>
      </div>

      ${urgentes.length ? `
        <div class="aviso aviso--error">${icono('aviso')}<div>
          <strong>${urgentes.length} urgente${urgentes.length > 1 ? 's' : ''} sin resolver.</strong>
        </div></div>` : ''}

      ${guias.length ? `
        <div class="seccion"><h2>Cómo se hacen las cosas</h2>
          <span class="rotulo">${guias.length}</span></div>
        <div class="filas">
          ${guias.slice(0, 4).map((g) => `
            <button type="button" class="fila" data-guia="${g.id_instruccion}">
              ${icono('nota')}
              <span class="fila__texto">${esc(g.titulo)}
                ${g.aviso ? '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">cuidado</span>' : ''}
                <small>${esc(g.resumen || '')}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </button>`).join('')}
        </div>` : ''}

      ${porLugar?.filas?.length ? `
        <div class="seccion"><h2>Dónde da más guerra</h2></div>
        <div class="tarjeta"><div class="tarjeta__cuerpo">
          <div class="reparto">
            ${porLugar.filas.map((f) => `
              <div class="reparto__fila" style="grid-template-columns:1fr 80px 34px">
                <span class="reparto__nombre">${esc(f.etiqueta)}</span>
                <span class="reparto__pista"><span class="reparto__barra"
                  style="width:${Math.round(f.valor / porLugar.filas[0].valor * 100)}%;
                         background:${esc(familia.color)}"></span></span>
                <span class="reparto__n">${Math.round(f.valor)}</span>
              </div>`).join('')}
          </div>
        </div></div>` : ''}

      ${porTipo?.filas?.length > 1 ? `
        <div class="seccion"><h2>Qué falla</h2></div>
        <div class="tarjeta"><div class="tarjeta__cuerpo">
          <div class="reparto">
            ${porTipo.filas.map((f) => `
              <div class="reparto__fila" style="grid-template-columns:1fr 80px 34px">
                <span class="reparto__nombre">${esc(f.etiqueta)}</span>
                <span class="reparto__pista"><span class="reparto__barra"
                  style="width:${Math.round(f.valor / porTipo.filas[0].valor * 100)}%;
                         background:${esc(familia.color)}"></span></span>
                <span class="reparto__n">${Math.round(f.valor)}</span>
              </div>`).join('')}
          </div>
        </div></div>` : ''}

      ${mant?.mantenimientos?.length ? `
        <div class="seccion"><h2>Revisiones programadas</h2></div>
        <div class="filas">
          ${mant.mantenimientos.map((m) => `
            <button type="button" class="fila" data-mant-ir>
              ${icono('calendario')}
              <span class="fila__texto">${esc(m.nombre)}
                <small>${esc(m.periodicidad)}${m.obligatorio ? ' · obligatoria' : ''}</small></span>
              <span class="fila__valor" style="${m.vencido ? 'color:var(--peligro);font-weight:700'
                : m.inminente ? 'color:var(--aviso);font-weight:700' : ''}">
                ${m.vencido ? Math.abs(m.dias_restantes) + ' d tarde' : m.dias_restantes + ' d'}</span>
            </button>`).join('')}
        </div>` : ''}

      <div class="seccion"><h2>Partes abiertos</h2>
        <span class="rotulo">${abiertos.total ?? 0}</span></div>
      ${(abiertos.tickets || []).length
        ? (abiertos.tickets || []).slice(0, 15).map((t) => tarjetaParte(t, { rapida: true })).join('')
        : estadoVacio({ icono: 'ok', titulo: 'Nada abierto en esta área', texto: '' })}

      ${unidades?.unidades?.length ? `
        <div class="seccion"><h2>Aparatos con número de serie</h2>
          <span class="rotulo">${unidades.unidades.length}</span></div>
        ${listaUnidades(unidades.unidades.slice(0, 12))}` : ''}`;

    enganchar(unidades);
  }

  function enganchar(unidades) {
    contenido.onclick = async (e) => {
      const c = e.target.closest('[data-filtro]');
      if (c) { app.filtros = JSON.parse(c.dataset.filtro);
      app.filtrosPedidos = true; ir('/lista'); return; }

      const r = e.target.closest('[data-rapida]');
      if (r) {
        e.stopPropagation();
        const t = await api.ticket(parseInt(r.dataset.rapida, 10)).then((x) => x.ticket).catch(() => null);
        if (t && await accionRapida(t)) cargar();
        return;
      }

      const g = e.target.closest('[data-guia]');
      if (g) {
        const guia = (app.catalogo?.instrucciones || [])
          .find((x) => x.id_instruccion === parseInt(g.dataset.guia, 10));
        if (guia) abrirInstruccion(guia);
        return;
      }

      const un = e.target.closest('[data-unidad]');
      if (un) { abrirUnidad(parseInt(un.dataset.unidad, 10), cargar); return; }

      const t = e.target.closest('[data-ticket]');
      if (t) { ir(`/ticket/${t.dataset.ticket}`); return; }

      if (e.target.closest('[data-unidades]')) { ir('/unidades'); return; }
      if (e.target.closest('[data-mant]') || e.target.closest('[data-mant-ir]')) {
        ir('/mantenimiento'); return;
      }
      if (e.target.closest('[data-informe]')) {
        app.informePrevio = {
          condiciones: [{ campo: 'familia', operador: 'es', valor: idFamilia, por: 'id',
                          etiqueta_valor: familia.nombre }],
          agrupar: 'lugar', medida: 'cuenta',
        };
        ir('/informes');
        return;
      }
      if (e.target.closest('[data-nuevo-abajo]')) abrirParte();
    };

    const nuevo = document.querySelector('[data-nuevo]');
    if (nuevo) nuevo.onclick = abrirParte;
  }

  /** Alta con el área ya puesta: ahorra el primer paso. */
  function abrirParte() {
    sessionStorage.setItem('hurakantick_familia_previa', String(idFamilia));
    ir('/nuevo');
  }

  await cargar();
  return { refrescar: cargar };
}

/* ================================================================== */
/** Elegir un área para abrir su panel. */
export async function elegirPanelArea() {
  const familias = app.catalogo?.familias || [];
  const v = await elegirBuscando({
    titulo: 'Panel de área',
    opciones: familias.map((f) => ({
      valor: f.id_familia, texto: f.nombre, icono: f.icono, detalle: f.descripcion,
    })),
    marcador: 'Buscar área',
  });
  if (v) ir(`/familia/${v}`);
}
