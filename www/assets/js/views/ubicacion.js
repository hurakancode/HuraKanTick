/* =====================================================================
   Ficha de una ubicación.

   «¿Qué pasa en la 214?» es la pregunta que más se hace en un hotel y la
   hacen los tres grupos por motivos distintos: recepción cuando un
   cliente se queja, housekeeping antes de entregar la habitación, y el
   técnico que ya va para allá y quiere saber si hay algo más que hacer
   de camino.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegirBuscando,
  fechaLarga, haceCuanto, minutosATexto,
} from '../ui.js';
import { cabeceraVolver, tarjetaParte, estadoVacio, bloqueCarga } from '../piezas.js';
import { accionRapida } from './acciones.js';
import { listaUnidades, engancharLista, abrirUnidad, altaUnidad } from './unidades.js';
import { elegirUbicacion } from './selectores.js';
import { modulo } from '../app.js';
import { pintarCabecera, ir, app } from '../app.js';

export async function vistaUbicacion({ contenido, args }) {
  const idRel = parseInt(args[0], 10);
  let mismoLugar = true;

  pintarCabecera(cabeceraVolver('Ubicación', ''));
  contenido.innerHTML = bloqueCarga(3);

  if (!hayRed()) {
    // Sin cobertura se responde con lo que hay guardado en el móvil.
    const locales = (await db.todos('tickets')).filter((t) => t.id_rel_zona === idRel && !t.borrado);
    const ruta = locales[0]?.zona_ruta || 'Ubicación';
    pintarCabecera(cabeceraVolver(ruta, 'Sin conexión'));
    contenido.innerHTML = `
      <div class="aviso aviso--senal">${icono('sinred')}<div>
        Sin conexión sólo se ve lo que hay descargado en el móvil.</div></div>
      ${locales.length ? locales.map(tarjetaParte).join('')
        : estadoVacio({ titulo: 'Nada guardado de este sitio', texto: '' })}`;
    contenido.onclick = (e) => {
      const t = e.target.closest('[data-ticket]');
      if (t) ir(`/ticket/${t.dataset.ticket}`);
    };
    return {};
  }

  async function cargar() {
    let d, inv = null;
    try {
      d = await api.ubicacion(idRel, mismoLugar);
      if (modulo('unidades')) {
        try { inv = await api.unidades({ zona: idRel, lugar: mismoLugar ? 1 : 0 }); }
        catch { /* sin inventario se sigue igual */ }
      }
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }

    const u = d.ubicacion;
    const r = d.resumen;
    pintarCabecera(cabeceraVolver(
      u.lugar || u.ruta,
      u.ruta,
      `<button type="button" class="icono-btn" data-nuevo aria-label="Abrir parte aquí">${icono('mas')}</button>`
    ));

    const abiertos = d.tickets.filter((t) => !t.es_final);
    const cerrados = d.tickets.filter((t) => t.es_final);

    // Si algo se repite mucho aquí, no es mala suerte: es que no se
    // arregló bien. Merece decirlo en vez de dejarlo en una tabla.
    const repetida = d.por_familia.find((f) => f.n >= 3);

    contenido.innerHTML = `
      <div class="tarjeta" style="margin-bottom:14px"><div class="tarjeta__cuerpo">
        <div class="parte__ruta" style="margin-bottom:12px">
          ${icono('ubicacion')}<span>${esc(u.ruta)}</span>
        </div>

        <div class="rejilla-cifras" style="grid-template-columns:repeat(3,1fr)">
          <div class="cifra ${r.abiertos ? 'cifra--alerta' : ''}" style="cursor:default;box-shadow:none;border:0;padding:0">
            <div class="cifra__valor">${r.abiertos}</div>
            <div class="cifra__nombre">Abiertos</div>
          </div>
          <div class="cifra" style="cursor:default;box-shadow:none;border:0;padding:0">
            <div class="cifra__valor">${r.total}</div>
            <div class="cifra__nombre">En total</div>
          </div>
          <div class="cifra" style="cursor:default;box-shadow:none;border:0;padding:0">
            <div class="cifra__valor" style="font-size:19px">${r.minutos ? esc(minutosATexto(r.minutos)) : '—'}</div>
            <div class="cifra__nombre">Dedicado</div>
          </div>
        </div>

        ${r.ultimo ? `<div class="campo__ayuda" style="margin-top:10px">
          Último parte ${esc(haceCuanto(r.ultimo))}${r.primero && r.primero !== r.ultimo
            ? ` · el primero, ${esc(fechaLarga(r.primero))}` : ''}</div>` : ''}
      </div></div>

      ${u.z3 ? `
        <div class="opciones" style="margin-bottom:16px">
          <button type="button" class="opcion" data-ambito="lugar" aria-pressed="${mismoLugar}">
            Todo el lugar</button>
          <button type="button" class="opcion" data-ambito="punto" aria-pressed="${!mismoLugar}">
            Sólo este punto</button>
        </div>` : ''}

      ${repetida ? `<div class="aviso aviso--senal">${icono('aviso')}<div>
        <strong>${esc(repetida.familia)}</strong> ha dado problemas
        ${repetida.n} veces aquí. Puede que convenga mirarlo a fondo en vez de
        seguir reparando lo mismo.</div></div>` : ''}

      ${d.por_familia.length > 1 ? `
        <div class="seccion"><h2>De qué han sido</h2></div>
        <div class="tarjeta"><div class="tarjeta__cuerpo">
          <div class="reparto">
            ${d.por_familia.map((f) => `
              <div class="reparto__fila" style="grid-template-columns:1fr 80px 28px">
                <span class="reparto__nombre">${esc(f.familia)}</span>
                <span class="reparto__pista"><span class="reparto__barra"
                  style="width:${Math.round(f.n / d.por_familia[0].n * 100)}%;
                         background:${esc(f.color || 'var(--marca)')}"></span></span>
                <span class="reparto__n">${f.n}</span>
              </div>`).join('')}
          </div>
        </div></div>` : ''}

      ${inv && inv.unidades.length ? `
        <div class="seccion"><h2>Aparatos con número de serie</h2>
          <span class="rotulo">${inv.unidades.length}</span></div>

        ${inv.resumen.length ? `
          <div class="tarjeta" style="margin-bottom:12px"><div class="tarjeta__cuerpo">
            ${inv.resumen.map((r) => {
              const bajas = Object.entries(r.por_motivo || {})
                .map(([m, n]) => `${n} ${({rotura:'por rotura',obsoleto:'por obsolescencia',
                    robo:'por robo o pérdida',fin_vida:'por fin de vida',
                    garantia:'en garantía',otro:'por otro motivo'})[m] || m}`);
              return `
                <div style="padding:7px 0;border-bottom:1px solid var(--borde-suave)">
                  <div style="display:flex;align-items:center;gap:8px">
                    <span style="color:${esc(r.color || 'var(--marca)')}">${icono(r.icono || 'herramientas')}</span>
                    <strong style="font-size:14px">${esc(r.elemento)}</strong>
                    <span class="rotulo" style="margin-left:auto">${r.total} en total</span>
                  </div>
                  <div style="font-size:12.5px;color:var(--tinta-2);margin-top:3px;padding-left:26px">
                    ${r.activas} en servicio${bajas.length ? ' · ' + bajas.join(', ') : ''}
                  </div>
                </div>`;
            }).join('')}
          </div></div>` : ''}

        ${listaUnidades(inv.unidades)}

        ${app.usuario.rol !== 'reportador' ? `
          <button type="button" class="btn btn--contorno btn--bloque btn--s" data-alta-unidad style="margin-bottom:6px">
            ${icono('mas')}Dar de alta un aparato aquí
          </button>` : ''}
      ` : ''}

      ${abiertos.length ? `
        <div class="seccion"><h2>Pendiente ahora</h2>
          <span class="rotulo">${abiertos.length}</span></div>
        ${abiertos.map((t) => tarjetaParte(t, { rapida: true })).join('')}` : `
        <div class="aviso aviso--ok" style="margin-top:16px">${icono('ok')}
          <div>No hay nada pendiente en este sitio.</div></div>`}

      ${cerrados.length ? `
        <div class="seccion"><h2>Historial</h2>
          <span class="rotulo">${cerrados.length} cerrados</span></div>
        ${cerrados.slice(0, 30).map(tarjetaParte).join('')}
        ${cerrados.length > 30 ? `<p class="campo__ayuda" style="text-align:center">
          Se muestran los 30 más recientes.</p>` : ''}` : ''}

      <button type="button" class="btn btn--principal btn--bloque" data-nuevo-abajo style="margin-top:18px">
        ${icono('mas')}Abrir un parte aquí
      </button>`;

    contenido.onclick = async (e) => {
      const r = e.target.closest('[data-rapida]');
      if (r) {
        e.stopPropagation();
        const t = d.tickets.find((x) => x.id_ticket === parseInt(r.dataset.rapida, 10));
        if (t && await accionRapida(t)) cargar();
        return;
      }
      const un = e.target.closest('[data-unidad]');
      if (un) { abrirUnidad(parseInt(un.dataset.unidad, 10), cargar); return; }

      if (e.target.closest('[data-alta-unidad]')) {
        altaUnidad({ id_rel: u.id_rel, ruta: u.ruta }, null, cargar);
        return;
      }

      const t = e.target.closest('[data-ticket]');
      if (t) { ir(`/ticket/${t.dataset.ticket}`); return; }

      const amb = e.target.closest('[data-ambito]');
      if (amb) {
        mismoLugar = amb.dataset.ambito === 'lugar';
        cargar();
        return;
      }

      if (e.target.closest('[data-nuevo-abajo]')) abrirAqui(u);
    };

    const nuevo = document.querySelector('[data-nuevo]');
    if (nuevo) nuevo.onclick = () => abrirAqui(u);
  }

  /** Alta con la ubicación ya puesta: es el atajo que ahorra tiempo. */
  function abrirAqui(u) {
    sessionStorage.setItem('hurakantick_zona_previa', JSON.stringify({
      id_rel: u.id_rel, z1: u.z1, z2: u.z2, z3: u.z3, z4: u.z4, ruta: u.ruta,
    }));
    ir('/nuevo');
  }

  await cargar();
  return {};
}

/* ---------------------------------------------------------------- */
/* Buscador de ubicaciones                                          */
/* ---------------------------------------------------------------- */
/** Abre el buscador y navega a la ficha del sitio elegido. */
export async function buscarUbicacion() {
  if (!hayRed()) {
    // Sin red se busca sobre el catálogo guardado.
    const combos = app.catalogo?.zonas?.combinaciones || [];
    const v = await elegirBuscando({
      titulo: 'Buscar ubicación',
      opciones: combos.filter((c) => c.ruta)
        .map((c) => ({ valor: c.id_rel, texto: c.ruta, icono: 'ubicacion' })),
      marcador: 'Habitación o nombre del sitio',
    });
    if (v) ir(`/ubicacion/${v}`);
    return;
  }

  return new Promise((resolve) => {
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="buscador" style="margin-bottom:6px">
        ${icono('buscar')}
        <input class="entrada" id="ub-q" type="search" enterkeyhint="search"
               placeholder="Habitación, planta o nombre del sitio" autocomplete="off">
      </div>

      <button type="button" class="btn btn--contorno btn--bloque btn--s" data-niveles
              style="margin:8px 0 4px">
        ${icono('ubicacion')}O bajar por zona, planta y lugar
      </button>

      <div class="rotulo" id="ub-cuenta" style="margin:10px 2px 6px"></div>
      <div class="eleccion" id="ub-lista"></div>`;

    let reloj = null;

    hoja({
      titulo: 'Consultar una ubicación',
      cuerpo,
      alAbrir: (h) => {
        const entrada = $('#ub-q', h);
        const lista = $('#ub-lista', h);
        const cuenta = $('#ub-cuenta', h);

        const buscar = async () => {
          const q = entrada.value.trim();
          if (q.length < 1) {
            cuenta.textContent = 'Escribe para buscar';
            lista.innerHTML = '';
            return;
          }
          try {
            const r = await api.buscarUbicacion(q);
            cuenta.textContent = r.ubicaciones.length
              ? `${r.ubicaciones.length} ${r.ubicaciones.length === 1 ? 'sitio' : 'sitios'}`
              : 'Nada con ese nombre';
            lista.innerHTML = r.ubicaciones.map((u) => `
              <button type="button" class="eleccion__item" data-u="${u.id_rel}">
                ${icono('ubicacion')}
                <span>${esc(u.ruta)}
                  <small>${u.abiertos ? u.abiertos + ' pendiente' + (u.abiertos > 1 ? 's' : '') : 'sin nada pendiente'}
                    · ${u.total} en total</small></span>
                ${u.abiertos ? `<span class="pastilla__n" style="background:var(--peligro);color:#fff">${u.abiertos}</span>` : ''}
              </button>`).join('');
            $$('[data-u]', lista).forEach((b) => {
              b.onclick = () => { cerrarHoja(); ir(`/ubicacion/${b.dataset.u}`); resolve(); };
            });
          } catch (e) {
            cuenta.textContent = e.message;
          }
        };

        // El mismo selector de cuatro niveles que en el alta: quien no
        // recuerda el número de habitación llega igual bajando.
        $('[data-niveles]', h).onclick = async () => {
          const r = await elegirUbicacion({});
          if (r?.id_rel) {
            cerrarHoja();
            ir(`/ubicacion/${r.id_rel}`);
            resolve();
          } else if (r) {
            // Se eligió algo pero sin rematar: no se cierra nada, que la
            // persona vea que sigue faltando concretar.
            mensaje('Concreta hasta una ubicación para poder consultarla.', 'error');
          }
        };

        entrada.oninput = () => { clearTimeout(reloj); reloj = setTimeout(buscar, 240); };
        entrada.onkeydown = (e) => {
          if (e.key === 'Enter') { e.preventDefault(); $('#ub-lista .eleccion__item', h)?.click(); }
        };
        cuenta.textContent = 'Escribe para buscar';
        if (window.matchMedia('(min-width: 900px)').matches) {
          setTimeout(() => entrada.focus(), 240);
        }
      },
      alCerrar: () => resolve(),
    });
  });
}
