/* =====================================================================
   Buscador, filtros y selección múltiple para una lista.

   Esto nació de un repaso: seis pantallas de la aplicación son listas
   que crecen con el uso —personas, revisiones, frases, el registro de
   seguridad, los errores— y ninguna tenía buscador. Con cincuenta
   personas dadas de alta, buscar a Marta es bajar rodando.

   Se hace UNA vez y lo usan las seis, por dos razones:

     · Seis implementaciones distintas significan seis comportamientos
       distintos para la misma cosa, y cinco sitios donde el fallo que
       se arregla en uno sigue vivo.
     · El buscador de filas que ya existía (ui.js → buscadorFilas) filtra
       el DOM: esconde filas con una clase. Para seleccionar en masa eso
       no sirve, porque «marcar todos» tiene que marcar lo que está
       visible y no lo que hay escondido. Aquí se filtran los DATOS y se
       vuelve a pintar.

   ──────────────────────────────────────────────────────────────────────
   Cómo se usa:

     const L = panelLista({
       datos: () => personas,                  // la lista completa
       id: (p) => p.id_login,                  // su identificador
       texto: (p) => [p.nombre, p.correo],     // por dónde se busca
       filtros: [ { clave:'rol', titulo:'Papel', opciones:[...] } ],
       acciones: [ { clave:'bloquear', texto:'Quitar acceso', ... } ],
       alCambiar: () => pintar(),              // repintar
     });

     L.html()            → el buscador, los filtros y la barra de selección
     L.visibles()        → lo que hay que pintar
     L.enganchar(zona)   → engancha los clics
     L.marcadas()        → los identificadores marcados
   ===================================================================== */

import { $, $$, icono, esc, textoPlano, confirmar } from './ui.js';

export function panelLista({
  datos,
  id,
  texto,
  filtros = [],
  /* Puede ser una lista o una función que la devuelva.
     Tiene que poder ser una función: varias pantallas deciden si hay
     acciones según algo que llega DESPUÉS —«¿puede editar esta
     persona?»—, y evaluándolo al construir la pieza siempre salía que
     no, porque los datos todavía no habían llegado. */
  acciones = [],
  alCambiar = () => {},
  placeholder = 'Buscar…',
  nombreUno = 'elemento',
  nombreVarios = 'elementos',
}) {
  /* Cada pieza con su identidad: es lo que permite saber si la zona
     está enganchada a ESTA o a la de otra pantalla. */
  const yo = Symbol('lista');

  let busca = '';
  const puestos = {};          // filtro → valor elegido
  const marcadas = new Set();
  let seleccionando = false;   // ¿está en modo «marcar»?

  filtros.forEach((f) => { puestos[f.clave] = f.porDefecto ?? null; });

  const lasAcciones = () => (typeof acciones === 'function' ? (acciones() || []) : acciones);

  /* ---------------------------------------------------------------- */
  /* Filtrado                                                         */
  /* ---------------------------------------------------------------- */
  function visibles() {
    const q = textoPlano(busca.trim());
    return (datos() || []).filter((d) => {
      if (q.length >= 2) {
        const heno = textoPlano((texto(d) || []).filter(Boolean).join(' '));
        /* Por palabras y en cualquier orden: «marta tecnico» encuentra a
           Marta aunque en la ficha el papel vaya antes que el nombre. */
        if (!q.split(/\s+/).every((p) => heno.includes(p))) return false;
      }
      for (const f of filtros) {
        const v = puestos[f.clave];
        if (v === null || v === undefined) continue;
        if (!f.cumple(d, v)) return false;
      }
      return true;
    });
  }

  /** Lo marcado QUE SIGUE VISIBLE.
   *
   *  Importa: alguien marca diez, cambia el filtro y pulsa «quitar
   *  acceso». Si se actuara sobre lo marcado a secas, se le quitaría el
   *  acceso a gente que ya no estaba viendo. */
  function marcadasVisibles() {
    const vis = new Set(visibles().map(id));
    return [...marcadas].filter((x) => vis.has(x));
  }

  /* ---------------------------------------------------------------- */
  /* Pintado                                                          */
  /* ---------------------------------------------------------------- */
  function html() {
    const vis = visibles();
    const total = (datos() || []).length;
    const nMarcadas = marcadasVisibles().length;
    const acc = lasAcciones();
    const hayFiltro = busca.trim() !== ''
      || filtros.some((f) => puestos[f.clave] !== null && puestos[f.clave] !== undefined);

    return `
      <div class="lst">
        <div class="lst__buscador">
          ${icono('buscar')}
          <input type="search" class="entrada" data-lst-busca
                 placeholder="${esc(placeholder)}" autocomplete="off"
                 value="${esc(busca)}" aria-label="${esc(placeholder)}">
          ${busca ? '<button type="button" class="lst__limpiar" data-lst-limpiar aria-label="Limpiar">×</button>' : ''}
        </div>

        ${filtros.length ? `
          <div class="lst__filtros">
            ${filtros.map((f) => `
              <div class="opciones lst__grupo" role="group" aria-label="${esc(f.titulo)}">
                <button type="button" class="opcion ${puestos[f.clave] === null ? 'opcion--activa' : ''}"
                        data-lst-filtro="${esc(f.clave)}" data-lst-valor="">${esc(f.titulo)}</button>
                ${f.opciones.map((o) => `
                  <button type="button" class="opcion ${String(puestos[f.clave]) === String(o.valor) ? 'opcion--activa' : ''}"
                          data-lst-filtro="${esc(f.clave)}" data-lst-valor="${esc(String(o.valor))}">
                    ${esc(o.texto)}</button>`).join('')}
              </div>`).join('')}
          </div>` : ''}

        <div class="lst__barra">
          <span class="lst__cuenta">
            ${hayFiltro
              ? `${vis.length} de ${total}`
              : `${total} ${total === 1 ? nombreUno : nombreVarios}`}
          </span>

          ${acc.length ? (seleccionando ? `
            <button type="button" class="pastilla" data-lst-todos>
              ${nMarcadas === vis.length && vis.length ? 'Ninguno' : 'Todos'}</button>
            <button type="button" class="pastilla" data-lst-salir>Salir</button>`
          : `<button type="button" class="pastilla" data-lst-marcar>Marcar varios</button>`) : ''}
        </div>

        ${seleccionando && nMarcadas ? `
          <div class="lst__acciones">
            <span class="lst__sel">${nMarcadas} marcado${nMarcadas === 1 ? '' : 's'}</span>
            ${acc.map((a) => `
              <button type="button" class="btn btn--s ${a.peligro ? 'btn--peligro' : 'btn--contorno'}"
                      data-lst-accion="${esc(a.clave)}">
                ${a.icono ? icono(a.icono) : ''}${esc(a.texto)}</button>`).join('')}
          </div>` : ''}

        ${vis.length === 0 && total > 0 ? `
          <p class="lst__nada">Nada con eso.
            <button type="button" class="lst__enlace" data-lst-reset>Quitar los filtros</button></p>` : ''}
      </div>`;
  }

  /** Lo que hay que poner en cada fila para que se pueda marcar. */
  function marca(d) {
    if (!seleccionando) return '';
    const puesta = marcadas.has(id(d));
    return `<span class="lst__casilla ${puesta ? 'lst__casilla--on' : ''}"
                  data-lst-marca="${esc(String(id(d)))}" role="checkbox"
                  aria-checked="${puesta ? 'true' : 'false'}">${puesta ? icono('ok') : ''}</span>`;
  }

  /* ---------------------------------------------------------------- */
  /* Clics y escritura                                                */
  /* ---------------------------------------------------------------- */
  /**
   * Engancha los clics y la escritura. SE LLAMA UNA SOLA VEZ.
   *
   * Dos cuidados que no son adorno:
   *
   *  · Se marca la zona. Llamar a esto en cada repintado añadía otro
   *    escuchador cada vez, así que tras diez repintados un clic en una
   *    fila abría diez fichas. Con la marca, llamarlo de más no hace
   *    nada.
   *  · Todo va DELEGADO en la zona, también el teclado. El buscador se
   *    vuelve a crear en cada repintado, así que engancharlo a él
   *    directamente dejaría de funcionar en cuanto se repinte.
   */
  function enganchar(zona) {
    /* La zona se queda con QUIÉN la tiene enganchada, no con un simple
       «sí».
       Con una marca de sí/no esto fallaba de una forma difícil de ver:
       #contenido es el MISMO elemento en todas las pantallas, así que al
       pasar de una lista a otra la segunda se encontraba la marca puesta
       y no se enganchaba. Los eventos los seguía atendiendo la pieza de
       la pantalla anterior, que filtraba su propia lista: escribías en
       el buscador de los errores y te filtraba las frases. */
    if (zona.__lst === yo) { return; }
    if (zona.__lst && typeof zona.__lstSoltar === 'function') {
      zona.__lstSoltar();
    }
    zona.__lst = yo;

    let reloj = null;

    const alEscribir = (e) => {
      const caja = e.target.closest('[data-lst-busca]');
      if (!caja) return;
      clearTimeout(reloj);
      reloj = setTimeout(() => {
        busca = caja.value;
        /* Se repinta y se devuelve el foco donde estaba. Sin esto cada
           letra manda el cursor al principio y no se puede escribir: es
           el fallo que más cansa de un buscador. */
        const pos = caja.selectionStart;
        alCambiar();
        const nueva = zona.querySelector('[data-lst-busca]');
        if (nueva) {
          nueva.focus();
          try { nueva.setSelectionRange(pos, pos); } catch { /* da igual */ }
        }
      }, 170);
    };

    const alTocar = async (e) => {
      const f = e.target.closest('[data-lst-filtro]');
      if (f) {
        const v = f.dataset.lstValor;
        puestos[f.dataset.lstFiltro] = v === '' ? null : v;
        alCambiar();
        return;
      }

      if (e.target.closest('[data-lst-limpiar]')) { busca = ''; alCambiar(); return; }

      if (e.target.closest('[data-lst-reset]')) {
        busca = '';
        filtros.forEach((x) => { puestos[x.clave] = null; });
        alCambiar();
        return;
      }

      if (e.target.closest('[data-lst-marcar]')) {
        seleccionando = true; alCambiar(); return;
      }
      if (e.target.closest('[data-lst-salir]')) {
        seleccionando = false; marcadas.clear(); alCambiar(); return;
      }
      if (e.target.closest('[data-lst-todos]')) {
        const vis = visibles().map(id);
        const todas = marcadasVisibles().length === vis.length && vis.length > 0;
        if (todas) { vis.forEach((x) => marcadas.delete(x)); }
        else { vis.forEach((x) => marcadas.add(x)); }
        alCambiar();
        return;
      }

      const m = e.target.closest('[data-lst-marca]');
      if (m) {
        /* Importante: no se deja pasar el clic a la fila. Si no, marcar
           a alguien abriría además su ficha. */
        e.preventDefault();
        e.stopPropagation();
        const v = m.dataset.lstMarca;
        // Los identificadores son números; el atributo, texto.
        const clave = /^-?\d+$/.test(v) ? parseInt(v, 10) : v;
        if (marcadas.has(clave)) { marcadas.delete(clave); } else { marcadas.add(clave); }
        alCambiar();
        return;
      }

      const a = e.target.closest('[data-lst-accion]');
      if (a) {
        const accion = lasAcciones().find((x) => x.clave === a.dataset.lstAccion);
        const ids = marcadasVisibles();
        if (!accion || !ids.length) return;

        if (accion.confirmar) {
          const c = typeof accion.confirmar === 'function'
            ? accion.confirmar(ids.length) : accion.confirmar;
          if (!await confirmar(c)) return;
        }

        a.disabled = true;
        try {
          await accion.hacer(ids);
          marcadas.clear();
          seleccionando = false;
        } finally {
          a.disabled = false;
        }
        alCambiar();
      }
    };

    zona.addEventListener('input', alEscribir);
    zona.addEventListener('click', alTocar);

    /* Y se deja dicho cómo soltarlos, para que la pieza de la pantalla
       siguiente pueda quitar los de esta. */
    zona.__lstSoltar = () => {
      zona.removeEventListener('input', alEscribir);
      zona.removeEventListener('click', alTocar);
      zona.__lstSoltar = null;
    };
  }

  return {
    html,
    marca,
    visibles,
    enganchar,
    marcadas: marcadasVisibles,
    seleccionando: () => seleccionando,
    limpiar: () => { busca = ''; marcadas.clear(); seleccionando = false; },
    /** Para quitar de la selección lo que ya no existe tras recargar. */
    olvidar: (ids) => { (ids || []).forEach((x) => marcadas.delete(x)); },
  };
}
