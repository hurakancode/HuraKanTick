/* =====================================================================
   Instrucciones.

   Lo que sabe hacer el técnico veterano, escrito una vez. En un hotel la
   plantilla rota cada temporada y ese conocimiento se pierde con quien
   se va.

   Se leen sin cobertura a propósito: quien las necesita está en un
   sótano, en la cubierta o en un cuarto de máquinas, que es justo donde
   una base de conocimiento que exige conexión no sirve de nada. Por eso
   viajan con el catálogo y no se piden al abrirlas.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, elegirBuscando,
  confirmar, fechaLarga, haceCuanto, enfocarBuscador } from '../ui.js';
import { cabeceraVolver, estadoVacio } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';
/* El lector de bloques pesa nada y se carga siempre. El editor —400 KB—
   se trae con import() sólo cuando alguien pulsa «Manual con fotos y
   pasos»: quien únicamente lee no lo descarga nunca. */
import { dibujarBloques, esBloques, bloquesATexto } from '../bloques.js';

/** Todas las que hay en el móvil. */
export function instrucciones() {
  return app.catalogo?.instrucciones || [];
}

/** Las que aplican a un parte, de lo más concreto a lo más general. */
/**
 * ¿A esta persona le toca ver esta instrucción?
 *
 * Las instrucciones son notas técnicas —cómo se purga el circuito, cómo
 * se cambia una cerradura—. Se enseñaban a cualquiera que abriera el
 * parte, así que recepción, que sólo da el aviso, veía el procedimiento
 * de climatización: ni le sirve ni le corresponde.
 *
 * Cada instrucción dice a quién va dirigida. Los responsables y los
 * administradores las ven siempre: son quienes revisan lo que hay
 * escrito y quienes lo corrigen.
 */
function puedeVerla(instruccion, ticket) {
  const u = app.usuario || {};
  if (u.es_admin || u.es_responsable) return true;

  // Quien lo está trabajando ve lo suyo, mande lo que mande la regla.
  if (ticket.id_persona_asignada === u.id_login) return true;

  switch (instruccion.visible_para || 'departamento') {
    case 'todos':
      return true;

    case 'asignado':
      // Sólo quien lo tiene asignado; ya se ha comprobado arriba.
      return false;

    case 'departamento':
    default: {
      /* El departamento que atiende el parte, contando los adicionales:
         si David lleva también Informática, las notas de Informática le
         sirven. */
      const suyos = [u.id_departamento, ...(u.departamentos_extra || [])].filter(Boolean);
      return suyos.includes(ticket.id_grupo_asignado);
    }
  }
}

export function instruccionesPara(ticket) {
  const todas = instrucciones();
  if (!todas.length || !ticket) return [];

  // La familia del parte puede venir vacía y llegar sólo por el
  // elemento: es lo normal en los partes anteriores a las familias y en
  // los que se crearon eligiendo el tipo concreto.
  const familia = ticket.id_familia
    ?? app.catalogo?.elementos?.find((e) => e.id_elemento === ticket.id_elemento)?.id_familia
    ?? null;

  const porElemento = todas.filter((i) => i.id_elemento && i.id_elemento === ticket.id_elemento);
  const porFamilia = familia
    ? todas.filter((i) => !i.id_elemento && i.id_familia === familia)
    : [];
  return [...porElemento, ...porFamilia].filter((i) => puedeVerla(i, ticket));
}

/* ================================================================== */
export async function vistaInstrucciones({ contenido }) {
  pintarCabecera(cabeceraVolver('Instrucciones', 'Cómo se hacen las cosas',
    app.usuario.es_responsable
      ? `<button type="button" class="icono-btn" data-nueva aria-label="Escribir una">${icono('mas')}</button>` : ''));

  let filtro = '';
  let familia = null;

  function pintar() {
    const todas = instrucciones();
    const familias = [...new Set(todas.map((i) => i.id_familia).filter(Boolean))]
      .map((id) => app.catalogo?.familias?.find((f) => f.id_familia === id))
      .filter(Boolean);

    const norm = (t) => (t || '').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const busca = norm(filtro);

    const lista = todas.filter((i) => {
      if (familia && i.id_familia !== familia) return false;
      if (!busca) return true;
      return busca.split(/\s+/).every((p) =>
        norm(i.titulo).includes(p) || norm(i.resumen).includes(p)
        /* En un manual por bloques el contenido es JSON, así que buscar
           ahí dentro encontraría «paragraph» y «blocks». Se busca en su
           texto de verdad. */
        || norm(esBloques(i.formato, i.contenido) ? bloquesATexto(i.contenido) : i.contenido).includes(p)
        || (i.etiquetas || []).some((e) => norm(e).includes(p)));
    });

    contenido.innerHTML = `
      <div class="buscador" style="margin-bottom:10px">
        ${icono('buscar')}
        <input class="entrada" id="i-q" type="search" enterkeyhint="search"
               placeholder="Buscar: purgar, cerradura, cloro…"
               value="${esc(filtro)}" autocomplete="off">
        ${filtro ? `<button type="button" class="buscador__limpiar" data-limpiar aria-label="Limpiar">
          ${icono('cerrar')}</button>` : ''}
      </div>

      ${familias.length > 1 ? `
        <div class="carril" style="margin-bottom:12px">
          <button type="button" class="pastilla" data-fam="0" aria-pressed="${!familia}">
            ${icono('lista')}Todas</button>
          ${familias.map((f) => `
            <button type="button" class="pastilla" data-fam="${f.id_familia}"
                    aria-pressed="${familia === f.id_familia}"
                    style="${familia === f.id_familia ? '' : `border-color:${esc(f.color)}44;color:${esc(f.color)}`}">
              ${icono(f.icono)}${esc(f.nombre)}</button>`).join('')}
        </div>` : ''}

      ${!hayRed() ? `<div class="aviso aviso--info">${icono('sinred')}<div>
        Sin cobertura. Las instrucciones se leen igual: están guardadas en el
        móvil.</div></div>` : ''}

      ${app.usuario.es_responsable ? `
        <!-- El «+» de la cabecera existía, pero nadie lo encontraba, y el
             editor avanzado quedaba escondido detrás de él. Aquí se ve con
             su nombre y se elige el editor antes de empezar. -->
        <div class="opciones" style="margin-bottom:12px">
          <button type="button" class="opcion" data-crear="texto">
            ${icono('mas')}Nota rápida</button>
          <button type="button" class="opcion" data-crear="bloques">
            ${icono('nota')}Manual con fotos, pasos y vídeo</button>
        </div>` : ''}

      <div class="rotulo" style="margin:2px 2px 8px">
        ${lista.length} ${lista.length === 1 ? 'instrucción' : 'instrucciones'}
      </div>

      ${lista.length ? `<div class="filas">
        ${lista.map((i) => `
          <button type="button" class="fila" data-i="${i.id_instruccion}" style="align-items:flex-start">
            <span class="avatar" style="background:${esc(i.familia_color || '#0B5563')}22;
                                        color:${esc(i.familia_color || '#0B5563')}">
              ${icono(i.familia_icono || 'nota')}</span>
            <span class="fila__texto">${esc(i.titulo)}
              ${i.aviso ? `<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">
                cuidado</span>` : ''}
              <small>${esc(i.resumen
                || (esBloques(i.formato, i.contenido)
                      ? bloquesATexto(i.contenido) : (i.contenido || '')).slice(0, 70))}</small>
              <small style="display:block;margin-top:3px;color:var(--tinta-3)">
                ${esc(i.elemento || i.familia || 'General')}
                ${i.vistas ? ` · leída ${i.vistas} ${i.vistas === 1 ? 'vez' : 'veces'}` : ''}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>` : estadoVacio({
        icono: 'nota',
        titulo: todas.length ? 'Nada con esa búsqueda' : 'Todavía no hay instrucciones',
        texto: todas.length
          ? 'Prueba con otra palabra.'
          : 'Escribe aquí lo que hoy sólo sabe quien lleva más tiempo.',
      })}`;

    const q = $('#i-q', contenido);
    enfocarBuscador($('#i-q', contenido));
    let reloj = null;
    q.oninput = () => {
      clearTimeout(reloj);
      reloj = setTimeout(() => {
        filtro = q.value;
        const pos = q.selectionStart;
        pintar();
        const nuevo = $('#i-q', contenido);
        nuevo.focus();
        nuevo.setSelectionRange(pos, pos);
      }, 220);
    };

    contenido.onclick = (e) => {
      const c = e.target.closest('[data-crear]');
      if (c) { editarInstruccion(null, () => pintar(), c.dataset.crear); return; }

      const l = e.target.closest('[data-limpiar]');
      if (l) { filtro = ''; pintar(); return; }

      const f = e.target.closest('[data-fam]');
      if (f) {
        const v = parseInt(f.dataset.fam, 10);
        familia = v || null;
        pintar();
        return;
      }

      const b = e.target.closest('[data-i]');
      if (b) {
        const i = instrucciones().find((x) => x.id_instruccion === parseInt(b.dataset.i, 10));
        if (i) abrirInstruccion(i);
      }
    };
  }

  const nueva = document.querySelector('[data-nueva]');
  if (nueva) nueva.onclick = () => editarInstruccion(null, () => { pintar(); });

  pintar();
  return { refrescar: pintar };
}

/* ================================================================== */
/* Lectura                                                            */
/* ================================================================== */
/**
 * Muestra una instrucción. Se abre en hoja y no en pantalla propia
 * porque casi siempre se consulta con el parte delante: cerrarla debe
 * devolver a donde se estaba, no navegar a otro sitio.
 */
export function abrirInstruccion(i) {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    ${i.aviso ? `<div class="aviso aviso--error" style="margin-bottom:14px">
      ${icono('aviso')}<div><strong>${esc(i.aviso)}</strong></div></div>` : ''}

    ${i.resumen ? `<p style="font-size:14.5px;color:var(--tinta-2);margin-bottom:16px">
      ${esc(i.resumen)}</p>` : ''}

    <div class="instruccion">${
      esBloques(i.formato, i.contenido) ? dibujarBloques(i.contenido) : formatear(i.contenido)
    }</div>

    <div class="datos" style="margin-top:18px">
      ${i.familia ? `<div class="datos__celda"><dt>Área</dt><dd>${esc(i.familia)}</dd></div>` : ''}
      ${i.elemento ? `<div class="datos__celda"><dt>Tipo</dt><dd>${esc(i.elemento)}</dd></div>` : ''}
      ${i.planta ? `<div class="datos__celda"><dt>Dónde</dt><dd>${esc(i.planta)}</dd></div>` : ''}
    </div>

    ${(i.etiquetas || []).length ? `
      <div class="carril" style="margin-top:12px">
        ${i.etiquetas.map((e) => `<span class="pastilla" style="cursor:default">${esc(e)}</span>`).join('')}
      </div>` : ''}

    <button type="button" class="btn btn--contorno btn--bloque btn--s" data-util style="margin-top:16px">
      ${icono('ok')}Me ha servido
    </button>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = app.usuario.es_responsable
    ? `<button type="button" class="btn btn--contorno" data-editar>${icono('editar')}Editar</button>
       <button type="button" class="btn btn--principal" data-cerrar-h>Cerrar</button>`
    : `<button type="button" class="btn btn--principal btn--bloque" data-cerrar-h>Cerrar</button>`;

  hoja({
    titulo: i.titulo,
    cuerpo, pie,
    alAbrir: (h) => {
      $('[data-cerrar-h]', h).onclick = () => cerrarHoja();

      $('[data-util]', h).onclick = async (e) => {
        const b = e.currentTarget;
        b.disabled = true;
        b.innerHTML = `${icono('ok')}Gracias`;
        tono('ok');
        if (hayRed()) {
          try { await api.instruccionUtil(i.id_instruccion); } catch { /* da igual */ }
        }
      };

      const ed = $('[data-editar]', h);
      if (ed) ed.onclick = () => { cerrarHoja(); editarInstruccion(i, () => app.refrescar()); };
    },
  });
}

/**
 * Da formato al texto sin permitir HTML.
 *
 * Se admite lo justo —listas numeradas, viñetas y saltos— porque una
 * instrucción se escribe en el móvil, entre dos averías, y pedir
 * sintaxis complicada garantiza que nadie escriba ninguna.
 */
function formatear(texto) {
  const lineas = (texto || '').split('\n');
  const salida = [];
  let enLista = null;

  const cerrar = () => {
    if (enLista) { salida.push(`</${enLista}>`); enLista = null; }
  };

  for (const bruta of lineas) {
    const l = bruta.trimEnd();
    const numerada = l.match(/^\s*(\d+)[.)]\s+(.*)$/);
    const vineta = l.match(/^\s*[·•*-]\s+(.*)$/);

    if (numerada) {
      if (enLista !== 'ol') { cerrar(); salida.push('<ol>'); enLista = 'ol'; }
      salida.push(`<li>${esc(numerada[2])}</li>`);
    } else if (vineta) {
      if (enLista !== 'ul') { cerrar(); salida.push('<ul>'); enLista = 'ul'; }
      salida.push(`<li>${esc(vineta[1])}</li>`);
    } else if (l.trim() === '') {
      cerrar();
    } else if (enLista) {
      // Continuación indentada de la línea anterior
      salida.push(`<li class="continua">${esc(l.trim())}</li>`);
    } else {
      salida.push(`<p>${esc(l)}</p>`);
    }
  }
  cerrar();
  return salida.join('');
}

/* ================================================================== */
/* Escritura                                                          */
/* ================================================================== */
function editarInstruccion(i, alTerminar, modoInicial) {
  const esNueva = !i;
  const est = {
    id_familia: i?.id_familia ?? null,
    id_elemento: i?.id_elemento ?? null,
  };

  /* Con qué editor se abre: con el que se escribió. Abrir un manual por
     bloques en el editor sencillo enseñaría el JSON en crudo, y alguien
     lo guardaría encima. */
  const arranqueBloques = esNueva
    ? modoInicial === 'bloques'
    : esBloques(i?.formato, i?.contenido);
  let modo = arranqueBloques ? 'bloques' : 'texto';
  let editorAv = null;          // la instancia de Editor.js, si se abre

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="n-titulo">Título</label>
      <input class="entrada" id="n-titulo" maxlength="180" value="${esc(i?.titulo || '')}"
             placeholder="Purgar el circuito de climatización"></div>

    <div class="campo"><label for="n-resumen">En una línea</label>
      <input class="entrada" id="n-resumen" maxlength="300" value="${esc(i?.resumen || '')}"
             placeholder="Cuándo hace falta y por qué">
      <div class="campo__ayuda">Es lo que se lee en la lista.</div></div>

    <div class="campo">
      <label>Cómo se hace</label>

      <!-- Los dos editores. El sencillo se queda tal cual estaba: para
           cuatro pasos escritos a mano es más rápido que cualquier
           editor, y funciona en el móvil más viejo del hotel. -->
      <div class="opciones" style="margin-bottom:8px">
        <button type="button" class="opcion ${arranqueBloques ? '' : 'opcion--activa'}"
                data-modo="texto">Sencillo</button>
        <button type="button" class="opcion ${arranqueBloques ? 'opcion--activa' : ''}"
                data-modo="bloques">Manual con fotos y pasos</button>
      </div>

      <textarea class="area" id="n-contenido" style="min-height:200px${arranqueBloques ? ';display:none' : ''}"
                placeholder="1. Primer paso&#10;2. Segundo paso&#10;&#10;· O con viñetas">${esc(arranqueBloques ? '' : (i?.contenido || ''))}</textarea>

      <div id="n-bloques" class="edb" style="${arranqueBloques ? '' : 'display:none'}"></div>
      <div id="n-bloques-carga" class="campo__ayuda oculto">Cargando el editor…</div>

      <div class="campo__ayuda" id="n-ayuda-ed">
        ${arranqueBloques
          ? 'Pulsa <b>+</b> para añadir un paso, una foto, un aviso, una tabla o un vídeo.'
          : 'Numera los pasos o usa viñetas con «·». Se ve tal cual lo escribas.'}
      </div></div>

    <div class="campo"><label for="n-aviso">Lo que no se debe hacer nunca</label>
      <input class="entrada" id="n-aviso" maxlength="500" value="${esc(i?.aviso || '')}"
             placeholder="Opcional, pero es lo que evita accidentes">
      <div class="campo__ayuda">Sale destacado en rojo arriba del todo.</div></div>

    <div class="campo">
      <label>Aparece sola en los partes de</label>
      <button type="button" class="fila" id="n-donde"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('llaveinglesa')}
        <span class="fila__texto" id="n-txt-donde">${esc(dondeTexto(est))}
          <small>Se ofrece al abrir un parte de ese tipo</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    <div class="campo">
      <label>Quién la ve</label>
      <div class="opciones" id="n-quien">
        ${[['departamento', 'El departamento que atiende'],
           ['asignado', 'Sólo quien lo trabaja'],
           ['todos', 'Cualquiera que vea el parte']].map(([v, t]) => `
          <button type="button" class="opcion ${(i?.visible_para || 'departamento') === v ? 'opcion--activa' : ''}"
                  data-quien="${v}">${t}</button>`).join('')}
      </div>
      <div class="campo__ayuda">
        Una nota técnica no le sirve a quien sólo da el aviso: recepción no
        tiene por qué ver cómo se purga un circuito. Los responsables las ven
        todas, para poder revisarlas.
      </div>
    </div>

    <div class="campo"><label for="n-etiquetas">Palabras para buscarla</label>
      <input class="entrada" id="n-etiquetas" maxlength="300"
             value="${esc((i?.etiquetas || []).join(', '))}"
             placeholder="purgar, aire, no enfría">
      <div class="campo__ayuda">Separadas por comas. Piensa en cómo la buscaría otro.</div></div>

    <div id="n-err"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = esNueva
    ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
       <button type="button" class="btn btn--principal" data-si>Publicar</button>`
    : `<button type="button" class="btn btn--peligro" data-borrar>Archivar</button>
       <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  hoja({
    titulo: esNueva ? 'Escribir una instrucción' : i.titulo,
    cuerpo, pie,

    /* Antes de cerrar sin guardar se pregunta.
     *
     * Escape y tocar fuera del panel cerraban y se llevaban lo escrito
     * sin decir nada. Con cuatro líneas a mano se perdona; con un manual
     * de veinte pasos y tres fotos, no. Sólo se pregunta si hay algo
     * escrito: en un panel vacío la pregunta estorba. */
    antesDeCerrar: async () => {
      const hayTitulo = ($('#n-titulo')?.value || '').trim() !== '';
      let hayCuerpo = false;

      if (modo === 'bloques' && editorAv) {
        try {
          const d = await editorAv.guardar();
          hayCuerpo = (d.blocks || []).length > 0;
        } catch { hayCuerpo = true; }   // si no se puede leer, mejor preguntar
      } else {
        hayCuerpo = ($('#n-contenido')?.value || '').trim() !== '';
      }

      if (!hayTitulo && !hayCuerpo) { return true; }

      const salir = await confirmar({
        titulo: 'Salir sin guardar',
        texto: modo === 'bloques'
          ? 'Se perderá el manual que llevas escrito, con sus fotos y sus pasos.'
          : 'Se perderá lo que llevas escrito.',
        aceptar: 'Salir y perderlo', peligro: true,
      });
      if (salir) { editorAv?.destruir(); }
      return salir;
    },

    alAbrir: (h) => {

      /* Quién ve la instrucción: una opción marcada a la vez. */
      const zonaQuien = $('#n-quien', h);
      if (zonaQuien) {
        zonaQuien.onclick = (e) => {
          const b = e.target.closest('[data-quien]');
          if (!b) return;
          $$('[data-quien]', zonaQuien).forEach((x) => x.classList.remove('opcion--activa'));
          b.classList.add('opcion--activa');
        };
      }
      $('#n-donde', h).onclick = async () => {
        const familias = app.catalogo?.familias || [];
        const elementos = app.catalogo?.elementos || [];
        const v = await elegirBuscando({
          titulo: 'Dónde aparece',
          opciones: [
            { valor: 'nada', texto: 'En ningún sitio concreto',
              detalle: 'Sólo se encuentra buscando', icono: 'info', destacado: true },
            ...familias.map((f) => ({ valor: 'f:' + f.id_familia, texto: f.nombre,
                                      detalle: 'Toda el área', icono: f.icono, destacado: true })),
            ...elementos.map((e) => ({ valor: 'e:' + e.id_elemento, texto: e.nombre,
                                       detalle: 'Sólo ese tipo' })),
          ],
          valor: est.id_elemento ? 'e:' + est.id_elemento
               : (est.id_familia ? 'f:' + est.id_familia : 'nada'),
          marcador: 'Buscar área o tipo',
        });
        if (v === null) return;
        est.id_familia = null;
        est.id_elemento = null;
        if (v.startsWith('f:')) est.id_familia = parseInt(v.slice(2), 10);
        if (v.startsWith('e:')) {
          est.id_elemento = parseInt(v.slice(2), 10);
          est.id_familia = elementos.find((x) => x.id_elemento === est.id_elemento)?.id_familia ?? null;
        }
        $('#n-txt-donde', h).innerHTML =
          `${esc(dondeTexto(est))}<small>Se ofrece al abrir un parte de ese tipo</small>`;
      };

      const err = (t) => { $('#n-err', h).innerHTML =
        `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

      /* ───────── El conmutador de editores ───────── */
      const zonaBloques = $('#n-bloques', h);
      const carga = $('#n-bloques-carga', h);
      const area = $('#n-contenido', h);
      const ayudaEd = $('#n-ayuda-ed', h);

      async function abrirAvanzado(datosIniciales) {
        if (editorAv) return true;
        carga.classList.remove('oculto');
        try {
          const mod = await import('../editor-bloques.js');
          editorAv = await mod.montarEditor(zonaBloques, datosIniciales,
                                            i?.id_instruccion || null,
                                            app.catalogo?.subidas?.video || null);
          return true;
        } catch (e) {
          err(e.message);
          return false;
        } finally {
          carga.classList.add('oculto');
        }
      }

      /** Lo escrito en el editor sencillo, repartido en bloques. */
      function textoABloques(txt) {
        const lineas = String(txt || '').split(/\n/);
        const blocks = [];
        for (const l of lineas) {
          const s = l.trim();
          if (s === '') continue;
          const numerado = /^\d+[.)]\s+(.*)$/.exec(s);
          const viñeta = /^[·*-]\s+(.*)$/.exec(s);
          if (numerado) { blocks.push({ type: 'paso', data: { text: numerado[1] } }); }
          else if (viñeta) { blocks.push({ type: 'paso', data: { text: viñeta[1] } }); }
          else { blocks.push({ type: 'paragraph', data: { text: s } }); }
        }
        return { blocks };
      }

      $$('[data-modo]', h).forEach((b) => {
        b.onclick = async () => {
          const quiere = b.dataset.modo;
          if (quiere === modo) return;

          if (quiere === 'bloques') {
            /* Lo escrito a mano se pasa a bloques: las líneas numeradas o
               con viñeta se convierten en pasos. Así nadie pierde lo que
               llevaba escrito al cambiar de editor. */
            const inicial = area.value.trim()
              ? textoABloques(area.value)
              : (arranqueBloques && i?.contenido ? JSON.parse(i.contenido) : { blocks: [] });

            if (!await abrirAvanzado(inicial)) return;

            modo = 'bloques';
            area.style.display = 'none';
            zonaBloques.style.display = '';
            ayudaEd.innerHTML = 'Pulsa <b>+</b> para añadir un paso, una foto, '
                              + 'un aviso, una tabla o un vídeo.';
          } else {
            /* Al revés se pierde formato, así que se avisa ANTES: fotos,
               tablas y avisos no caben en un textarea. */
            if (editorAv) {
              const d = await editorAv.guardar();
              const tieneRico = (d.blocks || []).some((x) =>
                ['image', 'video', 'table', 'aviso', 'embed', 'checklist'].includes(x.type));
              if (tieneRico && !await confirmar({
                titulo: 'Pasar al editor sencillo',
                texto: 'Las fotos, los vídeos, las tablas y los recuadros de aviso no '
                     + 'caben en el editor sencillo: se quedarán como texto y se '
                     + 'perderán al guardar. Los pasos y el texto se conservan.',
                aceptar: 'Pasar de todas formas', peligro: true,
              })) return;
              area.value = bloquesATexto(d);
            }
            modo = 'texto';
            zonaBloques.style.display = 'none';
            area.style.display = '';
            ayudaEd.textContent = 'Numera los pasos o usa viñetas con «·». '
                                + 'Se ve tal cual lo escribas.';
          }
          $$('[data-modo]', h).forEach((x) =>
            x.classList.toggle('opcion--activa', x.dataset.modo === modo));
        };
      });

      // Si la instrucción ya venía por bloques, se abre con el avanzado.
      if (arranqueBloques) {
        let iniciales = { blocks: [] };
        if (i?.contenido) {
          try { iniciales = JSON.parse(i.contenido); } catch { /* se abre vacío */ }
        }
        abrirAvanzado(iniciales);
      }

      const no = $('[data-no]', h);
      if (no) no.onclick = () => { editorAv?.destruir(); cerrarHoja(); };

      const borrar = $('[data-borrar]', h);
      if (borrar) borrar.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Archivar la instrucción',
          texto: 'Deja de aparecer, pero no se borra: se puede recuperar desde el servidor.',
          aceptar: 'Archivar', peligro: true,
        });
        if (!ok) return;
        try {
          await api.borrarInstruccion(i.id_instruccion);
          cerrarHoja();
          mensaje('Instrucción archivada.', 'ok');
          await recargar();
          alTerminar();
        } catch (e) { err(e.message); }
      };

      $('[data-si]', h).onclick = async () => {
        const titulo = $('#n-titulo', h).value.trim();
        if (!titulo) return err('Ponle un título.');

        /* El contenido sale del editor que esté abierto, y el formato va
           con él. Mandar texto plano marcado como bloques —o al revés—
           dejaría el manual en blanco al abrirlo. */
        let contenido = '';
        let formato = 'texto';

        if (modo === 'bloques' && editorAv) {
          let d;
          try { d = await editorAv.guardar(); }
          catch { return err('No se ha podido leer lo escrito en el editor avanzado.'); }
          if (!(d.blocks || []).length) {
            return err('El manual está vacío: añade al menos un paso o un párrafo.');
          }
          contenido = JSON.stringify(d);
          formato = 'bloques';
        } else {
          contenido = $('#n-contenido', h).value.trim();
          if (!contenido) return err('Escribe cómo se hace.');
        }

        if (!hayRed()) return err('Para publicar hace falta conexión. Lo escrito no se pierde si no cierras.');

        const d = {
          titulo,
          contenido,
          formato,
          resumen: $('#n-resumen', h).value.trim(),
          aviso: $('#n-aviso', h).value.trim(),
          etiquetas: $('#n-etiquetas', h).value.trim(),
          visible_para: $('#n-quien .opcion--activa', h)?.dataset.quien || 'departamento',
          id_familia: est.id_familia,
          id_elemento: est.id_elemento,
        };
        try {
          if (esNueva) await api.crearInstruccion(d);
          else await api.editarInstruccion(i.id_instruccion, d);
          cerrarHoja();
          tono('ok');
          mensaje('Instrucción guardada.', 'ok');
          await recargar();
          alTerminar();
        } catch (e) { err(e.message); }
      };
    },
  });
}

function dondeTexto(est) {
  if (est.id_elemento) {
    return app.catalogo?.elementos?.find((e) => e.id_elemento === est.id_elemento)?.nombre || 'Un tipo';
  }
  if (est.id_familia) {
    return app.catalogo?.familias?.find((f) => f.id_familia === est.id_familia)?.nombre || 'Un área';
  }
  return 'En ningún sitio concreto';
}

async function recargar() {
  const c = await api.catalogo();
  app.catalogo = c;
  await db.ajuste('catalogo', c);
}
