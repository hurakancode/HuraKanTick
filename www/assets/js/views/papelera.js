/* =====================================================================
   Modo papelera.

   Todo lo que se ha borrado del sistema, en su propia base de datos.
   Sólo lo ve un superadministrador: cruza hoteles y contiene datos de
   todos, así que mirarlo es una capacidad distinta de administrar el
   propio hotel.

   La aplicación cambia de color mientras se está aquí. No es adorno:
   es una pantalla que enseña cosas que ya no existen, y confundirla con
   el listado normal llevaría a buscar un parte que se borró hace un mes
   creyendo que sigue vivo.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, mensaje, hoja, cerrarHoja, confirmar, fechaLarga, haceCuanto, enfocarBuscador } from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';

/** Nombres de tabla a algo que se entienda. */
const TIPOS = {
  ticket_incidencias: { nombre: 'Partes', icono: 'lista' },
  login:              { nombre: 'Personas', icono: 'personas' },
  ticket_incidencias_historial: { nombre: 'Movimientos', icono: 'reloj' },
  adjuntos:           { nombre: 'Fotos', icono: 'camara' },
};

export function activarModoPapelera(si) {
  document.documentElement.dataset.papelera = si ? 'si' : '';
  try {
    if (si) localStorage.setItem('hurakantick_papelera', '1');
    else localStorage.removeItem('hurakantick_papelera');
  } catch { /* sin sitio */ }
}

export function enModoPapelera() {
  return document.documentElement.dataset.papelera === 'si';
}

export async function vistaPapelera({ contenido }) {
  pintarCabecera(cabeceraVolver('Papelera', 'Lo que se ha borrado',
    `<button type="button" class="icono-btn" data-salir aria-label="Salir del modo papelera">${icono('cerrar')}</button>`));

  if (!app.usuario.es_superadmin) {
    activarModoPapelera(false);
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Sólo un administrador puede ver la papelera.</div></div>`;
    return {};
  }
  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>La papelera vive en el servidor y necesita conexión.</div></div>`;
    return {};
  }

  activarModoPapelera(true);

  let datos = null;
  let filtros = { limite: 100 };

  async function cargar() {
    $('#pap-lista', contenido).innerHTML = bloqueCarga(3);
    try { datos = await api.papelera(filtros); }
    catch (e) {
      $('#pap-lista', contenido).innerHTML =
        `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    pintarLista();
  }

  function pintar() {
    contenido.innerHTML = `
      <div class="aviso aviso--papelera">${icono('papelera')}<div>
        <strong>Estás en la papelera.</strong> Esto no es la aplicación: son
        cosas que ya no existen en el sistema. Se guardan en una base de datos
        aparte y no aparecen en ningún listado, informe ni recuento.
      </div></div>

      <div class="buscador" style="margin-bottom:10px">
        ${icono('buscar')}
        <input class="entrada" id="pap-q" type="search" enterkeyhint="search"
               placeholder="Buscar por código, texto o quien lo borró" autocomplete="off">
      </div>

      <div class="carril" id="pap-tipos" style="margin-bottom:12px"></div>
      <div id="pap-lista"></div>`;

    const q = $('#pap-q', contenido);
    enfocarBuscador($('#pap-q', contenido));
    let reloj = null;
    q.oninput = () => {
      clearTimeout(reloj);
      reloj = setTimeout(() => { filtros.q = q.value.trim() || undefined; cargar(); }, 320);
    };
  }

  function pintarLista() {
    const res = datos.resumen || {};
    $('#pap-tipos', contenido).innerHTML = [
      `<button type="button" class="pastilla" data-t="" aria-pressed="${!filtros.tabla}">
         ${icono('lista')}Todo <span class="pastilla__n">${datos.total}</span></button>`,
      ...Object.entries(res).map(([t, n]) => {
        const d = TIPOS[t] || { nombre: t, icono: 'archivo' };
        return `<button type="button" class="pastilla" data-t="${esc(t)}" aria-pressed="${filtros.tabla === t}">
                  ${icono(d.icono)}${esc(d.nombre)} <span class="pastilla__n">${n}</span></button>`;
      }),
    ].join('');

    $$('[data-t]', contenido).forEach((b) => {
      b.onclick = () => { filtros.tabla = b.dataset.t || undefined; cargar(); };
    });

    const lista = datos.entradas || [];
    $('#pap-lista', contenido).innerHTML = lista.length ? `
      <div class="rotulo" style="margin:0 2px 8px">
        ${datos.total} ${datos.total === 1 ? 'cosa borrada' : 'cosas borradas'}
      </div>
      <div class="filas">
        ${lista.map((e) => {
          const d = TIPOS[e.tabla] || { nombre: e.tabla, icono: 'archivo' };
          return `
            <div class="fila" data-e="${e.id_entrada}" role="button" tabindex="0">
              ${icono(d.icono)}
              <span class="fila__texto">${esc(e.etiqueta || '(sin nombre)')}
                <small>${esc(e.resumen || d.nombre)}</small>
                <small style="display:block;color:var(--tinta-3)">
                  ${esc(e.hotel || 'sin hotel')} · ${esc(e.motivo || '')}
                  · ${esc(haceCuanto(e.fecha))}${e.autor ? ' · ' + esc(e.autor) : ''}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </div>`;
        }).join('')}
      </div>`
      : estadoVacio({
          icono: 'ok',
          titulo: filtros.q || filtros.tabla ? 'Nada con esos filtros' : 'La papelera está vacía',
          texto: 'Aquí acaba todo lo que se borra: partes, personas y lo que se '
               + 'lleve un restablecimiento.',
        });

    $$('[data-e]', contenido).forEach((b) => {
      b.onclick = () => verEntrada(parseInt(b.dataset.e, 10));
    });
  }

  async function verEntrada(id) {
    let f;
    try { f = await api.papeleraFila(id); }
    catch (e) { mensaje(e.message, 'error'); return; }

    const e = f.entrada;
    const d = f.datos || {};
    const oculto = (c) => c.startsWith('pap_');

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="aviso aviso--papelera">${icono('papelera')}<div>
        Borrado ${esc(fechaLarga(e.fecha))}${e.autor ? ' por ' + esc(e.autor) : ''}.
        <br>Motivo: ${esc(e.motivo || 'sin indicar')}
      </div></div>

      <dl class="datos">
        ${Object.entries(d).filter(([c]) => !oculto(c) && d[c] !== null && d[c] !== '')
          .map(([c, v]) => `
            <div class="datos__celda"><dt>${esc(c.replace(/_/g, ' '))}</dt>
              <dd style="word-break:break-word">${esc(String(v).slice(0, 300))}</dd></div>`).join('')}
      </dl>

      <div class="campo__ayuda" style="margin-top:12px">
        Esta es la fila tal y como estaba al borrarse. Las contraseñas no se
        guardan nunca, ni siquiera aquí.
      </div>`;

    const yaVuelto = Number(e.restaurado) === 1;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      <button type="button" class="btn btn--contorno" data-cerrar>Cerrar</button>
      <button type="button" class="btn btn--peligro btn--s" data-purgar>${icono('papelera')}</button>
      ${yaVuelto
        ? '<button type="button" class="btn btn--principal" disabled>Ya restaurado</button>'
        : '<button type="button" class="btn btn--principal" data-restaurar>Devolverlo</button>'}`;

    hoja({
      titulo: e.etiqueta || 'Entrada',
      cuerpo, pie,
      alAbrir: (h) => {
        $('[data-cerrar]', h).onclick = () => cerrarHoja();

        const bp = $('[data-purgar]', h);
        if (bp) bp.onclick = async () => {
          const ok = await confirmar({
            titulo: `Tirar «${e.etiqueta}»`,
            texto: 'Desaparece del todo, con sus fotos. Esto no se puede deshacer '
                 + 'ni hay copia de la que sacarlo.',
            aceptar: 'Tirarlo',
            peligro: true,
          });
          if (!ok) return;
          try {
            const r = await api.purgarPapelera(e.id_entrada);
            cerrarHoja();
            mensaje(r.mensaje, 'ok');
            cargar();
          } catch (err) { mensaje(err.message, 'error'); }
        };

        const br = $('[data-restaurar]', h);
        if (!br) return;

        br.onclick = async () => {
          const esPersona = e.tabla === 'login';
          const ok = await confirmar({
            titulo: `Devolver «${e.etiqueta}»`,
            texto: esPersona
              ? 'Vuelve con sus datos pero sin acceso: las contraseñas no se guardan '
                + 'ni aquí, así que habrá que darle una nueva.'
              : 'Vuelve al listado con su mismo número y su historial, como si no '
                + 'se hubiera borrado.',
            aceptar: 'Devolverlo',
          });
          if (!ok) return;

          br.disabled = true;
          br.textContent = 'Devolviendo…';
          try {
            const r = await api.restaurarPapelera(e.id_entrada);
            cerrarHoja();
            tono('ok');
            mensaje(r.mensaje, 'ok');
            cargar();
          } catch (err) {
            br.disabled = false;
            br.textContent = 'Devolverlo';
            mensaje(err.message, 'error');
          }
        };
      },
    });
  }

  const salir = document.querySelector('[data-salir]');
  if (salir) salir.onclick = () => { activarModoPapelera(false); ir('/panel'); };

  pintar();
  await cargar();
  return {
    refrescar: cargar,
    limpiar() { activarModoPapelera(false); },
  };
}
