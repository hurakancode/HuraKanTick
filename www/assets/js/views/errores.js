/* =====================================================================
   El registro de errores.

   Lo que falla, agrupado y con lo necesario para entenderlo: a quién le
   pasó, en qué pantalla, cuántas veces y con qué versión.

   Esta pantalla existe para que, cuando alguien diga «no me deja», haya
   un sitio donde mirar en vez de adivinar.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, icono, esc, mensaje, haceCuanto, confirmar } from '../ui.js';
import { cabeceraVolver, bloqueCarga } from '../piezas.js';
import { panelLista } from '../listas.js';
import { pintarCabecera } from '../app.js';

export async function vistaErrores({ contenido }) {
  pintarCabecera(cabeceraVolver('Errores'));
  contenido.innerHTML = bloqueCarga(3);

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Hace falta conexión para ver el registro.</div></div>`;
    return {};
  }

  let todos = false;
  let lista = [];
  let resumen = { grupos: 0, veces: 0 };

  /* El registro de errores es la lista que más crece de la aplicación:
     un fallo repetido se agrupa, pero con treinta fallos distintos hay
     que poder buscar «SQLSTATE» o el nombre de quien lo sufrió. */
  const L = panelLista({
    datos: () => lista,
    id: (e) => e.id_error,
    texto: (e) => [e.mensaje, e.usuario, e.ruta, e.version, e.detalle],
    placeholder: 'Buscar en los fallos…',
    nombreUno: 'fallo',
    nombreVarios: 'fallos',
    filtros: [
      {
        clave: 'donde', titulo: 'Donde sea',
        opciones: [{ valor: 'movil', texto: 'En un móvil' },
                   { valor: 'servidor', texto: 'En el servidor' }],
        cumple: (e, v) => e.origen === v,
      },
      {
        clave: 'cuantos', titulo: 'Todos',
        opciones: [{ valor: 'repe', texto: 'Repetidos' }],
        cumple: (e, v) => (v === 'repe' ? e.veces > 1 : true),
      },
    ],
    alCambiar: () => dibujar(),
  });

  async function cargar() {
    let datos;
    try {
      datos = await api.errores(todos);
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
        <div>${esc(e.message)}</div></div>`;
      return;
    }

    lista = datos.errores || [];
    resumen = datos.resumen || { grupos: 0, veces: 0 };
    dibujar();
  }

  function dibujar() {
    const r = resumen;
    const vis = L.visibles();

    contenido.innerHTML = `
      ${r.grupos === 0 ? `
        <div class="aviso aviso--ok">${icono('ok')}<div>
          No hay errores sin revisar. Si alguien te dice que algo falla y aquí no
          sale nada, pídele que te cuente qué pantalla y qué pulsó: puede ser un
          problema de permisos o de datos, no un fallo de la aplicación.
        </div></div>` : `
        <div class="aviso aviso--error">${icono('aviso')}<div>
          <b>${r.grupos} fallo${r.grupos === 1 ? '' : 's'} distinto${r.grupos === 1 ? '' : 's'}</b>
          · ${r.veces} ${r.veces === 1 ? 'vez' : 'veces'} en total
        </div></div>`}

      <div class="opciones" style="margin-bottom:10px">
        <button type="button" class="opcion ${todos ? '' : 'opcion--activa'}" data-ver="nuevos">Sin revisar</button>
        <button type="button" class="opcion ${todos ? 'opcion--activa' : ''}" data-ver="todos">Todos</button>
        ${r.grupos > 0 ? '<button type="button" class="opcion" data-visto-todo>Dar todos por vistos</button>' : ''}
      </div>

      ${lista.length ? L.html() : ''}

      ${vis.length === 0 ? `
        <p style="color:var(--tinta-3);font-size:14px;margin:12px 2px">Nada que enseñar.</p>` : `
        <div class="filas">
          ${vis.map((e) => `
            <button type="button" class="fila" data-error="${e.id_error}" style="align-items:flex-start">
              ${icono(e.origen === 'movil' ? 'movil' : 'ajustes')}
              <span class="fila__texto">${esc(e.mensaje)}
                <small>${e.origen === 'movil' ? 'En un móvil' : 'En el servidor'}${
                  e.usuario ? ' · ' + esc(e.usuario) : ''}${
                  e.ruta ? ' · ' + esc(e.ruta) : ''}</small>
                <small style="opacity:.75">${esc(haceCuanto(e.ultimo || e.creado))}${
                  e.veces > 1 ? ` · ${e.veces} veces` : ''}${
                  e.version ? ' · versión ' + esc(e.version) : ''}</small></span>
              ${e.veces > 1 ? `<span class="chapa" style="position:static">${e.veces}</span>` : ''}
            </button>`).join('')}
        </div>`}`;

    L.enganchar(contenido);

    contenido.onclick = async (ev) => {
      const ver = ev.target.closest('[data-ver]');
      if (ver) { todos = ver.dataset.ver === 'todos'; cargar(); return; }

      const todoVisto = ev.target.closest('[data-visto-todo]');
      if (todoVisto) {
        if (!await confirmar({
          titulo: 'Dar todos por vistos',
          texto: 'Dejarán de salir como pendientes, pero no se borran: seguirán '
               + 'en «Todos» por si hace falta volver a mirarlos.',
          aceptar: 'Darlos por vistos',
        })) return;
        try {
          const res = await api.errorVisto(null);
          mensaje(res.mensaje || 'Hecho.', 'ok');
          cargar();
        } catch (e) { mensaje(e.message, 'error'); }
        return;
      }

      const uno = ev.target.closest('[data-error]');
      if (uno) {
        const e = lista.find((x) => String(x.id_error) === uno.dataset.error);
        if (e) detalle(e, cargar);
      }
    };
  }

  await cargar();
  return { refrescar: cargar };
}

/** Un fallo por dentro: lo que haga falta para poder arreglarlo. */
async function detalle(e, alCerrar) {
  const { hoja, cerrarHoja } = await import('../ui.js');

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <p style="margin:0 0 8px;font-weight:650">${esc(e.mensaje)}</p>
      <dl class="datos">
        <div class="datos__celda"><dt>Dónde</dt>
          <dd>${e.origen === 'movil' ? 'En el móvil de alguien' : 'En el servidor'}</dd></div>
        <div class="datos__celda"><dt>Pantalla</dt><dd>${esc(e.ruta || '—')}</dd></div>
        <div class="datos__celda"><dt>Quién</dt><dd>${esc(e.usuario || '—')}</dd></div>
        <div class="datos__celda"><dt>Veces</dt><dd>${e.veces}</dd></div>
        <div class="datos__celda"><dt>Primera</dt><dd>${esc(haceCuanto(e.creado))}</dd></div>
        <div class="datos__celda"><dt>Última</dt><dd>${esc(haceCuanto(e.ultimo || e.creado))}</dd></div>
        ${e.version ? `<div class="datos__celda"><dt>Versión</dt><dd>${esc(e.version)}</dd></div>` : ''}
      </dl>
    </div></div>

    ${e.detalle ? `
      <div class="seccion"><h2>Detalle técnico</h2></div>
      <pre class="codigo">${esc(e.detalle)}</pre>
      <p style="font-size:13px;color:var(--tinta-3);margin:8px 2px">
        Esto es lo que hay que mandar a quien lleve el mantenimiento del sistema.</p>` : ''}`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `
    <button type="button" class="btn btn--contorno" data-copiar>Copiar</button>
    <button type="button" class="btn btn--principal" data-visto>Dar por visto</button>`;

  hoja({
    titulo: 'Detalle del fallo',
    cuerpo,
    pie,
    alAbrir: (h) => {
      $('[data-copiar]', h).onclick = async () => {
        const texto = [e.mensaje, e.ruta, e.usuario, 'x' + e.veces, e.version, '', e.detalle]
          .filter(Boolean).join('\n');
        try {
          await navigator.clipboard.writeText(texto);
          mensaje('Copiado.', 'ok');
        } catch { mensaje('El navegador no ha dejado copiar.', 'aviso'); }
      };
      $('[data-visto]', h).onclick = async () => {
        try {
          await api.errorVisto(e.id_error);
          cerrarHoja();
          alCerrar?.();
        } catch (err) { mensaje(err.message, 'error'); }
      };
    },
  });
}
