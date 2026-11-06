/* =====================================================================
   Departamentos.

   Son de la cadena, no de un hotel: «Servicios Técnicos» significa lo
   mismo en todos, y un informe conjunto sólo cuadra si es así.

   Lo que cambia de un hotel a otro es cuáles se usan. Uno de treinta
   habitaciones no tiene jardinería ni informática, y le sobra verlas en
   cada desplegable.

   Por eso esta pantalla enseña dos cosas distintas: el catálogo común
   —que sólo toca un superadministrador— y qué usa este hotel, que se
   elige aquí.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, confirmar, hoja, cerrarHoja,
} from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, app } from '../app.js';
import { tono } from '../sonido.js';

export async function vistaDepartamentos({ contenido }) {
  pintarCabecera(cabeceraVolver('Departamentos', 'Quién se encarga de qué',
    app.usuario.es_superadmin
      ? `<button type="button" class="icono-btn" data-nuevo aria-label="Nuevo departamento">${icono('mas')}</button>`
      : ''));

  if (!app.usuario.es_admin && !app.usuario.es_superadmin) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Hace falta ser administrador.</div></div>`;
    return {};
  }
  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Esta pantalla necesita conexión.</div></div>`;
    return {};
  }

  let datos = null;

  async function cargar() {
    contenido.innerHTML = bloqueCarga(4);
    try {
      datos = await api.departamentos({ todos: app.usuario.es_superadmin ? 1 : undefined });
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
        <div>${esc(e.message)}</div></div>`;
      return;
    }
    pintar();
  }

  function pintar() {
    const lista = datos.departamentos || [];
    const enUso = lista.filter((d) => d.en_este_hotel);
    const fuera = lista.filter((d) => !d.en_este_hotel);

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Los departamentos son comunes a todos los hoteles: así un informe de
        la cadena cuadra. Lo que elige cada hotel es <strong>cuáles usa</strong>,
        y con qué nombre los llama en casa.
      </div></div>

      <div class="seccion"><h2>Se usan en este hotel</h2></div>
      ${enUso.length ? `<div class="filas">
        ${enUso.map((d) => fila(d, true)).join('')}
      </div>` : estadoVacio({ icono: 'personas', titulo: 'Ninguno todavía',
          texto: 'Sin departamentos no se puede dar un parte.' })}

      ${app.usuario.es_superadmin && fuera.length ? `
        <div class="seccion"><h2>Del catálogo, sin usar aquí</h2></div>
        <div class="filas">
          ${fuera.map((d) => fila(d, false)).join('')}
        </div>
        <div class="campo__ayuda" style="margin-top:8px">
          Existen en la cadena pero este hotel no los ofrece.
        </div>` : ''}`;

    $$('[data-d]', contenido).forEach((b) => {
      b.onclick = () => abrir(parseInt(b.dataset.d, 10));
    });

    const bn = document.querySelector('[data-nuevo]');
    if (bn) bn.onclick = () => abrir(null);
  }

  function fila(d, dentro) {
    const propio = d.alias && d.alias !== d.nombre;
    return `
      <div class="fila" data-d="${d.id_departamento}" role="button" tabindex="0"
           style="${dentro ? '' : 'opacity:.62'}">
        <span class="punto" style="background:${esc(d.color || 'var(--borde)')}"></span>
        <span class="fila__texto">${esc(d.nombre_en_hotel)}
          ${propio ? `<small>En la cadena: ${esc(d.nombre)}</small>` : ''}
          <small>${d.personas} persona${d.personas === 1 ? '' : 's'}
            · ${d.partes} parte${d.partes === 1 ? '' : 's'}
            ${app.usuario.es_superadmin ? ` · en ${d.hoteles} hotel${d.hoteles === 1 ? '' : 'es'}` : ''}
            ${d.activo ? '' : ' · desactivado'}</small></span>
        ${icono('flecha', 'fila__flecha')}
      </div>`;
  }

  /* ---------------------------------------------------------------- */
  async function abrir(id) {
    const d = id === null ? null : (datos.departamentos || []).find((x) => x.id_departamento === id);
    const nuevo = d === null;
    const manda = app.usuario.es_superadmin;

    let hoteles = [];
    if (!nuevo && manda) {
      try { hoteles = (await api.departamentoHoteles(id)).hoteles || []; } catch { /* sin red */ }
    }

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      ${manda ? `
        <div class="campo">
          <label for="d-nombre">Nombre en la cadena</label>
          <input class="entrada" id="d-nombre" value="${esc(d?.nombre || '')}"
                 placeholder="Servicios Técnicos" autocomplete="off">
          <div class="campo__ayuda">
            Lo ven todos los hoteles. Cambiarlo aquí lo cambia en todas partes.
          </div>
        </div>` : `
        <div class="rotulo" style="margin:0 2px 10px">${esc(d?.nombre || '')}</div>`}

      ${!nuevo ? `
        <div class="campo">
          <label for="d-alias">Cómo se llama en este hotel</label>
          <input class="entrada" id="d-alias" value="${esc(d?.alias || '')}"
                 placeholder="${esc(d?.nombre || '')}" autocomplete="off">
          <div class="campo__ayuda">
            Déjalo vacío para usar el nombre de la cadena. Por dentro sigue
            siendo el mismo, así que los informes conjuntos siguen cuadrando.
          </div>
        </div>` : ''}

      ${manda ? `
        <div class="campo">
          <label for="d-color">Color</label>
          <input class="entrada" id="d-color" type="color"
                 value="${esc(d?.color || '#0B5563')}" style="height:46px">
        </div>` : ''}

      ${!nuevo ? `
        <div class="fila" style="cursor:default;border-radius:var(--r-m);
                    border:1.5px solid var(--borde);margin-top:6px">
          ${icono('ok')}
          <span class="fila__texto">Se usa en este hotel
            <small>${d.en_este_hotel
              ? 'Aparece al dar y repartir partes'
              : 'Ahora mismo no se ofrece aquí'}</small></span>
          <button type="button" class="interruptor" id="d-usar" role="switch"
                  aria-checked="${d.en_este_hotel}"></button>
        </div>` : ''}

      ${manda && hoteles.length > 1 ? `
        <div class="seccion"><h2>En qué hoteles se usa</h2></div>
        <div class="filas" id="d-hoteles">
          ${hoteles.map((h) => `
            <div class="fila" style="cursor:default">
              ${icono('edificio')}
              <span class="fila__texto">${esc(h.nombre)}</span>
              <button type="button" class="interruptor" data-h="${h.id_hotel}" role="switch"
                      aria-checked="${h.usa}"></button>
            </div>`).join('')}
        </div>` : ''}

      ${!nuevo && manda && (d.personas || d.partes) ? `
        <div class="aviso aviso--senal" style="margin-top:14px">${icono('info')}<div>
          Tiene ${d.personas} personas y ${d.partes} partes detrás. Si lo borras
          se desactivará en vez de desaparecer: el histórico tiene que poder
          seguir diciendo de quién era cada parte.
        </div></div>` : ''}

      <div id="d-error"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      ${!nuevo && manda ? `<button type="button" class="btn btn--peligro btn--s" data-borrar>
        ${icono('papelera')}</button>` : ''}
      <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
      <button type="button" class="btn btn--principal" data-si>${nuevo ? 'Crear' : 'Guardar'}</button>`;

    hoja({
      titulo: nuevo ? 'Nuevo departamento' : (d.nombre_en_hotel || 'Departamento'),
      cuerpo, pie,
      alAbrir: (h) => {
        const usar = $('#d-usar', h);
        if (usar) usar.onclick = () =>
          usar.setAttribute('aria-checked', usar.getAttribute('aria-checked') !== 'true');

        $$('[data-h]', h).forEach((b) => {
          b.onclick = () => b.setAttribute('aria-checked', b.getAttribute('aria-checked') !== 'true');
        });

        $('[data-no]', h).onclick = () => cerrarHoja();

        const bb = $('[data-borrar]', h);
        if (bb) bb.onclick = async () => {
          const ok = await confirmar({
            titulo: `Borrar «${d.nombre}»`,
            texto: d.personas || d.partes
              ? 'Tiene gente y partes detrás, así que se desactivará en vez de borrarse.'
              : 'Desaparece de todos los hoteles. Esto no se puede deshacer.',
            aceptar: 'Borrar', peligro: true,
          });
          if (!ok) return;
          try {
            const r = await api.borrarDepartamento(d.id_departamento);
            cerrarHoja();
            mensaje(r.mensaje, 'ok');
            cargar();
          } catch (e) { mensaje(e.message, 'error'); }
        };

        $('[data-si]', h).onclick = async () => {
          const bs = $('[data-si]', h);
          bs.disabled = true;
          try {
            if (nuevo) {
              const r = await api.crearDepartamento({
                nombre: $('#d-nombre', h).value.trim(),
                color: $('#d-color', h)?.value,
              });
              cerrarHoja();
              tono('ok');
              mensaje(r.mensaje, 'ok');
            } else {
              const cambios = {};
              if (manda) {
                const n = $('#d-nombre', h)?.value.trim();
                if (n && n !== d.nombre) cambios.nombre = n;
                const c = $('#d-color', h)?.value;
                if (c && c !== d.color) cambios.color = c;
              }
              const alias = $('#d-alias', h)?.value.trim() ?? '';
              if (alias !== (d.alias || '')) cambios.alias = alias;

              if (Object.keys(cambios).length) {
                await api.editarDepartamento(d.id_departamento, cambios);
              }

              // Si se ha tocado el interruptor de este hotel o el reparto
              if (manda) {
                const marcados = $$('[data-h]', h)
                  .filter((b) => b.getAttribute('aria-checked') === 'true')
                  .map((b) => parseInt(b.dataset.h, 10));
                const usarAqui = usar?.getAttribute('aria-checked') === 'true';
                const hotelActual = datos.hotel;

                if ($$('[data-h]', h).length) {
                  await api.repartirDepartamento(d.id_departamento, marcados);
                } else if (usar && usarAqui !== d.en_este_hotel && hotelActual) {
                  const base = hoteles.filter((x) => x.usa).map((x) => x.id_hotel);
                  const nuevos = usarAqui
                    ? [...new Set([...base, hotelActual])]
                    : base.filter((x) => x !== hotelActual);
                  await api.repartirDepartamento(d.id_departamento, nuevos);
                }
              }
              cerrarHoja();
              tono('ok');
              mensaje('Departamento actualizado.', 'ok');
            }
            cargar();
          } catch (e) {
            bs.disabled = false;
            $('#d-error', h).innerHTML =
              `<div class="aviso aviso--error" style="margin-top:10px">${icono('aviso')}
                <div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  await cargar();
  return { refrescar: cargar };
}
