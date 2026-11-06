/* =====================================================================
   Mensajes: la ventana de conversaciones y avisos.

   Lo que la gente espera de una aplicación de mensajes, porque en el
   hotel vienen de usar Teams: una sola pantalla con TODO lo que hay que
   leer, lo no leído arriba, y un buscador.

   Antes esto estaba repartido: las conversaciones había que abrirlas
   desde la ficha de cada parte —o acordarse de ir a Ajustes—, y los
   avisos vivían en otra pantalla. Con 50 personas hablando por aquí eso
   no se sostiene: lo urgente se queda sin ver porque está a tres toques
   de distancia.

   Dos decisiones que importan:

   · El contador de la barra de abajo cuenta conversaciones sin leer MÁS
     avisos sin leer. Es lo que la persona tiene pendiente, y no le
     interesa de qué cajón viene.

   · Se refresca solo cada 20 segundos, pero NUNCA mientras alguien está
     escribiendo en el buscador ni con un panel abierto. Perder lo que
     estás escribiendo por un refresco automático es el fallo que más
     cansa, y aquí la tentación de repintar es constante.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, haceCuanto, textoPlano, mensaje } from '../ui.js';
import { cabeceraVolver, bloqueCarga, estadoVacio } from '../piezas.js';
import { pintarCabecera, ir, app, fijarSinLeer } from '../app.js';

export async function vistaMensajes({ contenido }) {
  let pestana = 'chats';          // chats | avisos
  let busca   = '';
  let chats   = [];
  let avisos  = [];
  let reloj   = null;

  pintarCabecera(cabeceraVolver('Mensajes'));
  contenido.innerHTML = bloqueCarga(4);

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Los mensajes necesitan conexión. Lo que ya habías leído sigue
      en la ficha de cada parte.</div></div>`;
    return {};
  }

  /* ------------------------------------------------------------------ */
  /* Datos                                                              */
  /* ------------------------------------------------------------------ */
  async function traer() {
    const [c, a] = await Promise.allSettled([api.conversaciones(), api.avisos()]);
    if (c.status === 'fulfilled') chats = c.value.conversaciones || [];
    /* La API los llama «notificaciones»; aquí se guardan ya en una lista
       y con un solo nombre. Leer «avisos» de una respuesta que trae
       «notificaciones» daba siempre vacío: la pestaña de Avisos no
       enseñaba NADA, ni siquiera los que sí tenían parte. */
    /* Sin los avisos de mensaje: cada uno repite un mensaje que ya está
       en la pestaña de Conversaciones. Con ellos, seis mensajes de Fany
       salían como una conversación Y seis avisos, y la chapa contaba 7.
       La campana de arriba sí los tiene, y se apagan al leer la
       conversación. */
    if (a.status === 'fulfilled') {
      avisos = (a.value.notificaciones || []).filter((x) => x.evento !== 'chat');
    }
    // Si una de las dos falla, la otra se enseña igual: media pantalla
    // es mejor que un mensaje de error que tapa lo que sí se puede leer.
    if (c.status === 'rejected' && a.status === 'rejected') {
      throw new Error(c.reason?.message || 'No se han podido traer los mensajes.');
    }
  }

  /* CONVERSACIONES pendientes, no mensajes sueltos.
     Antes esto sumaba los mensajes: con una conversación de seis sin leer,
     la pestaña ponía «Conversaciones · 6» mientras el encabezado de debajo
     ponía «Sin leer 1». Dos números distintos, en la misma pantalla, para
     la misma cosa. El número ahora significa siempre lo mismo en todas
     partes: cuántas cosas tienes que atender. Cuántos mensajes hay dentro
     de cada una se ve en su propia chapa, que es donde importa. */
  const sinLeerChats  = () => chats.filter((c) => (c.sin_leer || 0) > 0).length;
  const sinLeerAvisos = () => (avisos || []).filter((a) => !a.leida).length;

  /* La chapa de la barra, con lo que acabamos de ver aquí. Se llama
     después de traer y después de marcar algo como leído: si no, el
     número se queda como estaba hasta recargar la aplicación. */
  const ponerChapa = () =>
    fijarSinLeer({ chats: sinLeerChats(), avisos: sinLeerAvisos() });

  /* ------------------------------------------------------------------ */
  /* Pintado                                                            */
  /* ------------------------------------------------------------------ */
  function filaChat(c) {
    const pendiente = c.sin_leer > 0;
    return `
      <button type="button" class="fila" data-chat="${c.id_ticket}"
              style="align-items:flex-start${pendiente ? ';font-weight:600' : ''}">
        <span class="avatar" style="background:${esc(c.estado_color || '#0B5563')}22;
                                    color:${esc(c.estado_color || '#0B5563')}">
          ${icono(c.importante && pendiente ? 'aviso' : 'chat')}</span>
        <span class="fila__texto">${esc(c.autor || 'Alguien')}${
          c.mio ? ' · <span style="color:var(--senal)">tuyo</span>' : ''}${
          c.es_final ? ' · <span style="color:var(--tinta-3)">cerrado</span>' : ''}
          <small style="${pendiente ? 'color:var(--tinta-1)' : ''}">${esc(c.texto || '')}</small>
          <small style="opacity:.7">${esc(c.codigo)}${
            c.titulo ? ' · ' + esc(c.titulo) : ''} · ${esc(haceCuanto(c.ultimo))}</small></span>
        ${pendiente
          ? `<span class="chapa" style="position:static">${c.sin_leer}</span>`
          : (c.mensajes ? `<span class="chapa_leido" style="position:static">${c.mensajes}</span>` : '')}
      </button>`;
  }

  function filaAviso(a) {
    return `
      <button type="button" class="fila" data-aviso="${a.id_notificacion}"
              style="align-items:flex-start${a.leida ? '' : ';font-weight:600'}">
        ${icono(a.leida ? 'campana' : 'aviso')}
        <span class="fila__texto">${esc(a.titulo || '')}
          <small>${esc(a.cuerpo || '')}</small>
          <small style="opacity:.7">${esc(haceCuanto(a.fecha))}${
            a.id_ticket ? '' : ' · el parte ya no existe'}</small></span>
        ${a.leida ? '' : '<span class="chapa" style="position:static">1</span>'}
      </button>`;
  }

  /** Lo que encaja con lo escrito: por quién, por texto, por parte. */
  function filtrar(lista, campos) {
    const q = textoPlano(busca.trim());
    if (q.length < 2) return lista;
    return lista.filter((x) =>
      textoPlano(campos.map((k) => x[k] || '').join(' ')).includes(q));
  }

  function pintar() {
    const nC = sinLeerChats();
    const nA = sinLeerAvisos();

    const visiblesChats = filtrar(chats, ['autor', 'texto', 'codigo', 'titulo']);
    const visiblesAvisos = filtrar(avisos || [], ['titulo', 'cuerpo']);

    /* Sin leer arriba, y dentro de cada montón lo más reciente primero.
       Una bandeja ordenada sólo por fecha entierra lo no leído en cuanto
       llegan cuatro mensajes nuevos de otra conversación. */
    const pendientes = visiblesChats.filter((c) => c.sin_leer > 0);
    const leidos     = visiblesChats.filter((c) => !c.sin_leer);

    contenido.innerHTML = `
      <div class="opciones" style="margin-bottom:10px">
        <button type="button" class="opcion ${pestana === 'chats' ? 'opcion--activa' : ''}"
                data-pest="chats">Conversaciones${nC ? ` · ${nC}` : ''}</button>
        <button type="button" class="opcion ${pestana === 'avisos' ? 'opcion--activa' : ''}"
                data-pest="avisos">Avisos${nA ? ` · ${nA}` : ''}</button>
      </div>

      <div class="buscador" style="margin-bottom:10px">
        <input type="search" class="entrada" id="m-busca" placeholder="Buscar en los mensajes…"
               autocomplete="off" value="${esc(busca)}" aria-label="Buscar en los mensajes">
      </div>

      ${pestana === 'chats' ? `
        ${!chats.length ? estadoVacio({ icono: 'chat',
            titulo: 'Todavía no hay conversaciones',
            texto: 'Se abren desde la ficha de un parte, con el icono de conversación. '
                 + 'Sirven para hablar con quien lo abrió sin salir del parte.' }) : `
          ${!visiblesChats.length ? `
            <p style="color:var(--tinta-3);font-size:14px;margin:12px 2px">
              Nada con esa palabra.</p>` : ''}
          ${pendientes.length ? `
            <div class="seccion"><h2>Sin leer</h2>
              <span class="rotulo">${pendientes.length}</span></div>
            <div class="filas">${pendientes.map(filaChat).join('')}</div>` : ''}
          ${leidos.length ? `
            <div class="seccion"><h2>${pendientes.length ? 'Leídas' : 'Conversaciones'}</h2>
              <span class="rotulo">${leidos.length}</span></div>
            <div class="filas">${leidos.map(filaChat).join('')}</div>` : ''}`}
      ` : `
        ${!(avisos || []).length ? estadoVacio({ icono: 'campana', titulo: 'No hay avisos',
            texto: 'Aquí aparece lo que pasa en los partes que abriste o tienes asignados.' }) : `
          ${nA ? `
            <div class="acciones" style="margin-bottom:10px">
              <button type="button" class="btn btn--contorno btn--s" data-leer-todo>
                ${icono('ok')}Marcar todo como leído</button>
            </div>` : ''}
          ${!visiblesAvisos.length ? `
            <p style="color:var(--tinta-3);font-size:14px;margin:12px 2px">
              Nada con esa palabra.</p>`
          : `<div class="filas">${visiblesAvisos.map(filaAviso).join('')}</div>`}`}
      `}`;

    enganchar();
  }

  /* ------------------------------------------------------------------ */
  /* Interacción                                                        */
  /* ------------------------------------------------------------------ */
  function enganchar() {
    const caja = $('#m-busca', contenido);
    if (caja) {
      /* El foco se repone donde estaba. Sin esto, cada letra repinta la
         lista y el cursor se va al principio: no se puede escribir. */
      let temporizador = null;
      caja.oninput = () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(() => {
          busca = caja.value;
          const pos = caja.selectionStart;
          pintar();
          const nueva = $('#m-busca', contenido);
          if (nueva) { nueva.focus(); nueva.setSelectionRange(pos, pos); }
        }, 160);
      };
    }

    contenido.onclick = async (e) => {
      const p = e.target.closest('[data-pest]');
      if (p) { pestana = p.dataset.pest; pintar(); return; }

      const c = e.target.closest('[data-chat]');
      if (c) { ir(`/ticket/${c.dataset.chat}/chat`); return; }

      const a = e.target.closest('[data-aviso]');
      if (a) {
        const av = (avisos || [])
          .find((x) => String(x.id_notificacion) === a.dataset.aviso);
        if (!av) return;

        /* Marcar como leído SIEMPRE, lleve parte o no.
           Antes esto sólo pasaba si el aviso tenía parte, así que uno
           cuyo parte se hubiera borrado no se podía quitar de ninguna
           manera: pulsarlo no hacía nada y seguía contando en la chapa.
           Es la otra mitad del fallo de los «2 mensajes». */
        try {
          if (!av.leida) {
            await api.leerAvisos([av.id_notificacion]);
            av.leida = 1;
          }
        } catch { /* se reintenta al siguiente toque */ }

        ponerChapa();
        if (av.id_ticket) {
          ir('/ticket/' + av.id_ticket);
        } else {
          // Sin parte no hay a dónde ir: se repinta para que se vea leído.
          pintar();
        }
        return;
      }

      if (e.target.closest('[data-leer-todo]')) {
        try {
          const ids = (avisos || []).filter((x) => !x.leida)
                        .map((x) => x.id_notificacion);
          if (ids.length) await api.leerAvisos(ids);
          await traer();
          ponerChapa();
          pintar();
        } catch (err) { mensaje(err.message, 'error'); }
      }
    };
  }

  /* ------------------------------------------------------------------ */
  /* Arranque y refresco                                                */
  /* ------------------------------------------------------------------ */
  try {
    await traer();
  } catch (e) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>${esc(e.message)}</div></div>`;
    return {};
  }
  ponerChapa();
  pintar();

  /** ¿Se puede repintar ahora mismo sin estropearle nada a nadie? */
  function momentoMalo() {
    const a = document.activeElement;
    if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable)) {
      return true;    // está escribiendo
    }
    if (document.querySelector('.hoja, .velo.visible')) return true;  // panel abierto
    if (document.hidden) return true;                                 // ni se está mirando
    return false;
  }

  async function refrescar() {
    if (momentoMalo()) return;
    try {
      await traer();
      if (momentoMalo()) return;   // pudo empezar a escribir mientras llegaba
      ponerChapa();
      pintar();
    } catch { /* se reintenta en el siguiente turno */ }
  }

  reloj = setInterval(refrescar, 20000);

  return {
    refrescar,
    limpiar: () => clearInterval(reloj),
  };
}
