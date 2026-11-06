/* =====================================================================
   La conversación de un parte.

   Como un chat de los de siempre, y a propósito: la gente ya sabe usarlo
   y no hay que explicar nada. Lo único distinto es el acuse de lectura
   —quién lo ha leído y cuándo— y la marca de importante, que hace que el
   mensaje salga en el inicio de quien deba leerlo.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as sync from '../sync.js';
import { $, $$, icono, esc, mensaje, haceCuanto, fechaLarga } from '../ui.js';
import { cabeceraVolver, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app, fijarSinLeer } from '../app.js';

export async function vistaChat({ contenido, args }) {
  const idTicket = parseInt(args[0], 10);
  pintarCabecera(cabeceraVolver('Conversación'));

  contenido.innerHTML = bloqueCarga(3);

  /* Sin cobertura se puede escribir igual: el mensaje entra en la cola y
     sale al recuperar señal. Lo que no se puede es leer lo que haya
     escrito otro mientras tanto, y eso se dice. */
  let datos = { mensajes: [], participantes: [], sin_leer: 0 };
  /* «hayRed» sólo se entera cuando falla una petición; el navegador sabe
     antes que se ha quedado sin línea. Se miran las dos cosas para que el
     aviso salga a la primera y no después del primer intento fallido. */
  let sinRed = !hayRed() || navigator.onLine === false;

  if (!sinRed) {
    try {
      datos = await api.chat(idTicket);
    } catch (e) {
      if (e.sinRed) { sinRed = true; }
      else {
        contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
          <div>${esc(e.message)}</div></div>`;
        return {};
      }
    }
  }

  pintar();

  /* Al abrir la conversación se da por leída: si estás mirándola, la has
     visto. Se hace después de pintar, para que el acuse no retrase lo
     que la persona quiere ver. */
  if (datos.sin_leer > 0) {
    /* Y la chapa de «Mensajes» y la campana, en el momento. Sin esto,
       quien abría la conversación desde el inicio o desde la ficha del
       parte la leía, volvía, y la chapa seguía contándola hasta entrar
       en la pantalla de Mensajes (17 antes de leer, 17 después). */
    api.chatLeido(idTicket).then((r) => {
      const n = app.catalogo?.sin_leer?.chats;
      if (n !== undefined) fijarSinLeer({ chats: n - 1 });
      if (r && typeof r.no_leidas === 'number') app.noLeidas = r.no_leidas;
    }).catch(() => { /* ya se marcará al volver */ });
  }

  function burbuja(m) {
    const leido = (m.leido_por || []).map((l) => l.nombre).join(', ');
    return `
      <div class="charla__linea ${m.mio ? 'charla__linea--mia' : ''}">
        <div class="charla__burbuja ${m.mio ? 'charla__burbuja--mia' : ''}
                    ${m.importante ? 'charla__burbuja--importante' : ''}">
          ${!m.mio ? `<div class="charla__autor">${esc(m.autor || 'Alguien')}${
            m.departamento_autor ? ` · ${esc(m.departamento_autor)}` : ''}</div>` : ''}
          ${m.importante ? `<div class="charla__marca">${icono('aviso')} Importante</div>` : ''}
          <div class="charla__texto">${esc(m.texto)}</div>
          <div class="charla__pie">
            ${esc(haceCuanto(m.creado))}
            ${m.pendiente ? ' · esperando señal'
              : (m.mio ? (leido ? ` · leído por ${esc(leido)}` : ' · sin leer') : '')}
          </div>
        </div>
      </div>`;
  }

  function pintar() {
    const ms = datos.mensajes || [];
    contenido.innerHTML = `
      ${sinRed ? `
        <div class="aviso aviso--senal">${icono('sinred')}<div>
          Sin cobertura: no se ve lo que hayan escrito otros, pero lo que
          escribas ahora se envía en cuanto haya señal.
        </div></div>` : ''}

      ${(!sinRed && ms.length === 0) ? `
        <div class="aviso aviso--info">${icono('info')}<div>
          Todavía no ha escrito nadie. Esto sirve para hablar con quien abrió el
          parte: si al revisar resulta que no había avería, decirlo aquí evita
          que el cliente vuelva a quejarse.
        </div></div>` : ''}

      <div class="charla" id="charla">
        ${ms.map(burbuja).join('')}
      </div>

      <div class="charla__escribir">
        <label class="charla__importante">
          <input type="checkbox" id="c-importante">
          <span>Importante · sale en el inicio de los demás hasta que lo lean</span>
        </label>
        <div class="charla__caja">
          <textarea id="c-texto" rows="2" maxlength="2000"
                    placeholder="Escribe un mensaje"></textarea>
          <button type="button" class="btn btn--principal" id="c-enviar" aria-label="Enviar">
            ${icono('enviar')}
          </button>
        </div>
      </div>

      ${(datos.participantes || []).length ? `
        <p class="charla__gente">En esta conversación:
          ${(datos.participantes || []).map((p) => esc(p.nombre)).join(' · ')}</p>` : ''}

      <div class="filas" style="margin-top:14px">
        <button type="button" class="fila" data-ir-parte>
          ${icono('parte')}
          <span class="fila__texto">Ver el parte
            <small>Volver a la ficha completa</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>`;

    const charla = $('#charla', contenido);
    if (charla) charla.scrollTop = charla.scrollHeight;

    $('[data-ir-parte]', contenido).onclick = () => ir('/ticket/' + idTicket);

    const texto = $('#c-texto', contenido);
    const enviar = async () => {
      const t = texto.value.trim();
      if (!t) return;
      const importante = $('#c-importante', contenido).checked;
      $('#c-enviar', contenido).disabled = true;
      try {
        const r = await sync.escribirEnChat({ id_ticket: idTicket }, t, importante);
        texto.value = '';
        $('#c-importante', contenido).checked = false;
        if (r.enviado) {
          datos = await api.chat(idTicket);
          pintar();
        } else {
          mensaje('Sin cobertura: se enviará en cuanto haya señal.', 'ok');
          datos.mensajes = [...(datos.mensajes || []), {
            id_mensaje: 0, texto: t, importante, mio: true,
            autor: 'Tú', creado: new Date().toISOString(), leido_por: [], pendiente: true,
          }];
          pintar();
        }
      } catch (e) {
        mensaje(e.message, 'error');
      } finally {
        const b = $('#c-enviar', contenido);
        if (b) b.disabled = false;
      }
    };

    $('#c-enviar', contenido).onclick = enviar;
    texto.onkeydown = (e) => {
      // Enter envía; Mayúsculas+Enter hace un salto de línea.
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); }
    };
    texto.focus();
  }

  return {};
}
