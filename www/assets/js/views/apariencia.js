/* =====================================================================
   Pantalla de apariencia.

   Cada persona la ajusta en su móvil. No es cosa del administrador:
   quien trabaja en un pasillo a media luz quiere la letra más grande, y
   quien lleva veinte partes al día quiere que quepan más en pantalla.
   ===================================================================== */

import { $, $$, icono, esc, mensaje, confirmar } from '../ui.js';
import { cabeceraVolver } from '../piezas.js';
import { pintarCabecera, app, ir } from '../app.js';
import {
  BLOQUES, BLOQUES_TICKET, BLOQUES_AJUSTES,
  apariencia, fijarApariencia, restaurarApariencia, aplicar,
} from '../apariencia.js';
import { tono } from '../sonido.js';
import { estadoEfectivo, fijarVistaMovil, pantallaGrande, esNativo } from '../vista-movil.js';

export async function vistaApariencia({ contenido }) {
  pintarCabecera(cabeceraVolver('Apariencia', 'Cómo se ve en tu móvil'));

  /**
   * La vista smartphone, según lo que haya decidido el superadministrador.
   *   · «no»        → no sale nada: no hay nada que decidir.
   *   · «todos»     → se dice que está puesta, sin controles.
   *   · «activa» / «inactiva» → cada uno la enciende o la apaga, y elige la
   *                   forma del teléfono.
   */
  function seccionVistaMovil(a, grupo) {
    const e = estadoEfectivo(app.catalogo?.ajustes);
    if (e.modo === 'no' || esNativo()) return '';

    const nombreRatio = { '9:16': '9:16', '9:19.5': '9:19,5', '9:20': '9:20' };

    if (!e.puede) {
      return `
        <div class="seccion"><h2>Vista smartphone</h2></div>
        <div class="aviso aviso--info">${icono('movil')}<div>
          En pantallas grandes la aplicación se ve dentro de un teléfono de
          proporción ${esc(nombreRatio[e.ratio] || e.ratio)}. Lo ha fijado el
          administrador para todo el hotel.
        </div></div>`;
    }

    const aviso = pantallaGrande() ? '' : `
      <div class="campo__ayuda" style="margin:-8px 0 18px">
        En este aparato no cambia nada: ya tiene pantalla de teléfono. Se nota
        en un ordenador o en una tableta.
      </div>`;

    return `
      <div class="seccion"><h2>Vista smartphone</h2></div>
      ${grupo('vistaMovil', 'Ver la aplicación dentro de un teléfono',
        'En el ordenador, la aplicación queda centrada con forma de móvil: ves lo mismo que el técnico.',
        [['si', 'Activada'], ['no', 'Desactivada']],
        e.on ? 'si' : 'no')}
      ${aviso}
      ${grupo('vistaMovilRatio', 'Forma del teléfono', '',
        [['', `La del hotel (${nombreRatio[e.ratioHotel]})`],
         ['9:16', '9:16'], ['9:19.5', '9:19,5'], ['9:20', '9:20']],
        a.vistaMovilRatio || '')}`;
  }

  function pintar() {
    const a = apariencia();

    const grupo = (id, titulo, ayuda, opciones, valor) => `
      <div class="campo" style="margin-bottom:18px">
        <label>${esc(titulo)}</label>
        <div class="opciones" data-grupo="${id}">
          ${opciones.map(([v, t]) => `
            <button type="button" class="opcion" data-v="${esc(v)}"
                    aria-pressed="${valor === v}">${esc(t)}</button>`).join('')}
        </div>
        ${ayuda ? `<div class="campo__ayuda">${esc(ayuda)}</div>` : ''}
      </div>`;

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Esto sólo cambia tu móvil. Nadie más lo nota, y se conserva aunque
        cierres la aplicación.
      </div></div>

      <div class="seccion"><h2>Cómo se lee</h2></div>

      ${grupo('letra', 'Tamaño de la letra',
        'Crece todo, no sólo los textos.',
        [['pequena', 'Pequeña'], ['normal', 'Normal'], ['grande', 'Grande'], ['enorme', 'Enorme']],
        a.letra)}

      ${grupo('aire', 'Espacio entre las cosas',
        'En compacto cabe casi una tarjeta más por pantalla; en amplio se acierta mejor con el pulgar.',
        [['compacto', 'Compacto'], ['normal', 'Normal'], ['amplio', 'Amplio']],
        a.aire)}

      ${grupo('tarjeta', 'Detalle de cada parte en el listado',
        'En mínima se ocultan la ubicación y el pie de la tarjeta.',
        [['minima', 'Mínima'], ['normal', 'Normal'], ['completa', 'Completa']],
        a.tarjeta)}

      ${grupo('cifrasPorFila', 'Cifras por fila en el inicio', '',
        [['1', 'Una'], ['2', 'Dos'], ['3', 'Tres']],
        String(a.cifrasPorFila))}

      ${seccionVistaMovil(a, grupo)}

      <div class="seccion"><h2>Bloques del inicio</h2>
        <span class="rotulo">${a.bloques.filter((b) => b.visible).length} de ${a.bloques.length}</span></div>
      <div class="campo__ayuda" style="margin:-4px 2px 10px">
        Toca el interruptor para ocultar uno. Las flechas lo suben o lo bajan, y
        el botón de la derecha cambia si ocupa media pantalla o entera.
        Un bloque que no te toca por tu puesto no aparece aunque esté encendido.
      </div>

      <div class="filas">
        ${a.bloques.map((b, i) => {
          const d = BLOQUES[b.id];
          if (!d) return '';
          return `
            <div class="fila" style="cursor:default;align-items:flex-start;
                        ${b.visible ? '' : 'opacity:.5'}">
              <span class="avatar" style="background:var(--superficie-3);color:var(--tinta-3);
                                          font-size:12px;font-weight:700">${i + 1}</span>
              <span class="fila__texto">${esc(d.nombre)}
                <small>${esc(d.detalle)}</small>
                <span style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                  <button type="button" class="pastilla" data-subir="${b.id}"
                          ${i === 0 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Subir">${icono('flecha-arriba')}</button>
                  <button type="button" class="pastilla" data-bajar="${b.id}"
                          ${i === a.bloques.length - 1 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Bajar">${icono('flecha-abajo')}</button>
                  <button type="button" class="pastilla" data-ancho="${b.id}"
                          aria-pressed="${b.ancho === 1}">
                    ${b.ancho === 1 ? 'Media pantalla' : 'Pantalla entera'}</button>
                </span>
              </span>
              <button type="button" class="interruptor" data-ver="${b.id}" role="switch"
                      aria-checked="${b.visible}" aria-label="Ver ${esc(d.nombre)}"></button>
            </div>`;
        }).join('')}
      </div>

      <div class="seccion"><h2>Bloques de la ficha de un parte</h2>
        <span class="rotulo">${a.bloquesTicket.filter((x) => x.visible).length} de ${a.bloquesTicket.length}</span></div>
      <div class="campo__ayuda" style="margin:-4px 2px 10px">
        Lo que ves al abrir un parte. La cabecera y los botones no se pueden
        quitar: sin la primera no sabrías qué parte es, y sin los segundos no
        podrías hacer nada con él.
      </div>

      <div class="filas">
        ${a.bloquesTicket.map((x, i) => {
          const d = BLOQUES_TICKET[x.id];
          if (!d) return '';
          return `
            <div class="fila" style="cursor:default;align-items:flex-start;
                        ${x.visible ? '' : 'opacity:.5'}">
              <span class="avatar" style="background:var(--superficie-3);color:var(--tinta-3);
                                          font-size:12px;font-weight:700">${i + 1}</span>
              <span class="fila__texto">${esc(d.nombre)}
                ${d.fijo ? '<span class="prio" style="--prio-color:var(--tinta-3);margin-left:6px">fijo</span>' : ''}
                <small>${esc(d.detalle)}</small>
                <span style="display:flex;gap:6px;margin-top:8px">
                  <button type="button" class="pastilla" data-t-subir="${x.id}"
                          ${i === 0 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Subir">${icono('flecha-arriba')}</button>
                  <button type="button" class="pastilla" data-t-bajar="${x.id}"
                          ${i === a.bloquesTicket.length - 1 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Bajar">${icono('flecha-abajo')}</button>
                </span>
              </span>
              ${d.fijo
                ? `<span class="fila__valor" style="color:var(--tinta-3);font-size:12px">siempre</span>`
                : `<button type="button" class="interruptor" data-t-ver="${x.id}" role="switch"
                     aria-checked="${x.visible}" aria-label="Ver ${esc(d.nombre)}"></button>`}
            </div>`;
        }).join('')}
      </div>

      <div class="seccion"><h2>Bloques de la pantalla de Ajustes</h2>
        <span class="rotulo">${a.bloquesAjustes.filter((x) => x.visible).length} de ${a.bloquesAjustes.length}</span></div>
      <div class="campo__ayuda" style="margin:-4px 2px 10px">
        Esta misma pantalla. Tu cuenta y Apariencia no se pueden quitar:
        sin la primera no podrías cambiar tu contraseña, y sin la segunda no
        podrías volver aquí.
      </div>

      <div class="filas">
        ${a.bloquesAjustes.map((x, i) => {
          const d = BLOQUES_AJUSTES[x.id];
          if (!d) return '';
          return `
            <div class="fila" style="cursor:default;align-items:flex-start;
                        ${x.visible ? '' : 'opacity:.5'}">
              <span class="avatar" style="background:var(--superficie-3);color:var(--tinta-3);
                                          font-size:12px;font-weight:700">${i + 1}</span>
              <span class="fila__texto">${esc(d.nombre)}
                ${d.fijo ? '<span class="prio" style="--prio-color:var(--tinta-3);margin-left:6px">fijo</span>' : ''}
                <small>${esc(d.detalle)}</small>
                <span style="display:flex;gap:6px;margin-top:8px">
                  <button type="button" class="pastilla" data-a-subir="${x.id}"
                          ${i === 0 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Subir">${icono('flecha-arriba')}</button>
                  <button type="button" class="pastilla" data-a-bajar="${x.id}"
                          ${i === a.bloquesAjustes.length - 1 ? 'disabled style="opacity:.35"' : ''}
                          aria-label="Bajar">${icono('flecha-abajo')}</button>
                </span>
              </span>
              ${d.fijo
                ? `<span class="fila__valor" style="color:var(--tinta-3);font-size:12px">siempre</span>`
                : `<button type="button" class="interruptor" data-a-ver="${x.id}" role="switch"
                     aria-checked="${x.visible}" aria-label="Ver ${esc(d.nombre)}"></button>`}
            </div>`;
        }).join('')}
      </div>

      <div class="acciones" style="margin-top:18px">
        <button type="button" class="btn btn--contorno btn--s" data-ver-inicio>
          ${icono('panel')}Ver cómo queda</button>
        <button type="button" class="btn btn--contorno btn--s" data-restaurar>
          ${icono('sync')}Dejarlo como estaba</button>
      </div>`;

    enganchar();
  }

  function enganchar() {
    // Los grupos de opciones sueltas
    $$('[data-grupo]', contenido).forEach((g) => {
      g.onclick = (e) => {
        const b = e.target.closest('[data-v]');
        if (!b) return;
        const clave = g.dataset.grupo;
        let valor = b.dataset.v;
        if (clave === 'cifrasPorFila') valor = parseInt(valor, 10);

        if (clave === 'vistaMovil' || clave === 'vistaMovilRatio') {
          const r = fijarVistaMovil({ [clave]: valor === '' ? null : valor });
          if (r?.cambiando) return;   // se va al marco o sale de él
          pintar();
          return;
        }

        fijarApariencia({ [clave]: valor });
        pintar();
      };
    });

    // Ocultar o mostrar un bloque
    $$('[data-ver]', contenido).forEach((b) => {
      b.onclick = () => {
        const bloques = apariencia().bloques.map((x) =>
          x.id === b.dataset.ver ? { ...x, visible: !x.visible } : x);
        fijarApariencia({ bloques });
        pintar();
      };
    });

    // Subir y bajar
    const mover = (id, salto) => {
      const bloques = [...apariencia().bloques];
      const i = bloques.findIndex((x) => x.id === id);
      const j = i + salto;
      if (i < 0 || j < 0 || j >= bloques.length) return;
      [bloques[i], bloques[j]] = [bloques[j], bloques[i]];
      fijarApariencia({ bloques });
      pintar();
    };
    $$('[data-subir]', contenido).forEach((b) => { b.onclick = () => mover(b.dataset.subir, -1); });
    $$('[data-bajar]', contenido).forEach((b) => { b.onclick = () => mover(b.dataset.bajar, 1); });

    // Media pantalla o entera
    $$('[data-ancho]', contenido).forEach((b) => {
      b.onclick = () => {
        const bloques = apariencia().bloques.map((x) =>
          x.id === b.dataset.ancho ? { ...x, ancho: x.ancho === 1 ? 2 : 1 } : x);
        fijarApariencia({ bloques });
        pintar();
      };
    });

    // Los mismos controles para la ficha del parte
    $$('[data-t-ver]', contenido).forEach((b) => {
      b.onclick = () => {
        const bloquesTicket = apariencia().bloquesTicket.map((x) =>
          x.id === b.dataset.tVer ? { ...x, visible: !x.visible } : x);
        fijarApariencia({ bloquesTicket });
        pintar();
      };
    });

    const moverTicket = (id, salto) => {
      const lista = [...apariencia().bloquesTicket];
      const i = lista.findIndex((x) => x.id === id);
      const j = i + salto;
      if (i < 0 || j < 0 || j >= lista.length) return;
      [lista[i], lista[j]] = [lista[j], lista[i]];
      fijarApariencia({ bloquesTicket: lista });
      pintar();
    };
    $$('[data-t-subir]', contenido).forEach((b) => { b.onclick = () => moverTicket(b.dataset.tSubir, -1); });
    $$('[data-t-bajar]', contenido).forEach((b) => { b.onclick = () => moverTicket(b.dataset.tBajar, 1); });

    // Los mismos controles para la pantalla de Ajustes
    $$('[data-a-ver]', contenido).forEach((b) => {
      b.onclick = () => {
        const bloquesAjustes = apariencia().bloquesAjustes.map((x) =>
          x.id === b.dataset.aVer ? { ...x, visible: !x.visible } : x);
        fijarApariencia({ bloquesAjustes });
        pintar();
      };
    });
    const moverAj = (id, salto) => {
      const lista = [...apariencia().bloquesAjustes];
      const i = lista.findIndex((x) => x.id === id);
      const j = i + salto;
      if (i < 0 || j < 0 || j >= lista.length) return;
      [lista[i], lista[j]] = [lista[j], lista[i]];
      fijarApariencia({ bloquesAjustes: lista });
      pintar();
    };
    $$('[data-a-subir]', contenido).forEach((b) => { b.onclick = () => moverAj(b.dataset.aSubir, -1); });
    $$('[data-a-bajar]', contenido).forEach((b) => { b.onclick = () => moverAj(b.dataset.aBajar, 1); });

    $('[data-ver-inicio]', contenido).onclick = () => ir('/panel');

    $('[data-restaurar]', contenido).onclick = async () => {
      const ok = await confirmar({
        titulo: 'Dejarlo como estaba',
        texto: 'Se descarta todo lo que hayas ajustado aquí y se vuelve a la apariencia de fábrica.',
        aceptar: 'Restaurar',
      });
      if (!ok) return;
      restaurarApariencia();
      tono('ok');
      mensaje('Apariencia restaurada.', 'ok');
      pintar();
    };
  }

  aplicar();
  pintar();
  return {};
}
