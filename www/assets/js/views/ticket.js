/* Ficha de un parte: qué pasa, qué se ha hecho y qué hago yo ahora. */

import { api, hayRed, urlAdjunto } from '../api.js';
import * as db from '../db.js';
import * as sync from '../sync.js';
import {
  $, $$, icono, esc, mensaje, hoja, elegir, confirmar, verFoto,
  fechaLarga, haceCuanto, restante, aUtcDesdeLocal, aLocalParaCampo, iniciales, minutosATexto, ICONOS_ELEMENTO, cerrarHoja,
} from '../ui.js';
import { cabeceraVolver } from '../piezas.js';
import { pintarCabecera, ir, app, volver, modulo, ajuste } from '../app.js';
import { elegirFotos } from './fotos.js';
import { elegirElemento, elegirUbicacion } from './selectores.js';
import { instruccionesPara, abrirInstruccion } from './instrucciones.js';
import { abrirUnidad } from './unidades.js';
import { puedeQuedarselo } from './acciones.js';
import { bloquesTicketOrdenados } from '../apariencia.js';
import { elegirBuscando } from '../ui.js';
import { tono } from '../sonido.js';

export async function vistaTicket({ contenido, args }) {
  const id = parseInt(args[0], 10);

  let ticket = await db.leer('tickets', id);
  let historial = (await db.porIndice('historial', 'ticket', id)) || [];
  let adjuntos = (await db.porIndice('adjuntos', 'ticket', id)) || [];
  let camposPropios = [];
  let puedeEditar = true;
  let fotosPendientes = await db.fotosDe(id);
  // Quién sigue el parte sin trabajarlo. Se carga aparte: no hace falta
  // para pintar el parte y no debe retrasarlo.
  let observadores = [];

  /* Se calcula cada vez que se pinta, no una sola vez: el número de
     mensajes sin leer llega con la respuesta del servidor, y construirlo
     antes dejaba el contador siempre con el dato de la visita anterior. */
  const accionesCabecera = () =>
    `<button type="button" class="icono-btn" data-editar aria-label="Editar el parte">${icono('editar')}</button>
     <button type="button" class="icono-btn" data-chat aria-label="Conversación">
        ${icono('chat')}${ticket?.chat_sin_leer
          ? `<span class="chapa">${ticket.chat_sin_leer}</span>`
          : (ticket?.chat_total ? `<span class="chapa_leido">${ticket.chat_total}</span>` : '')}
      </button>
      <button type="button" class="icono-btn" data-compartir aria-label="Compartir">${icono('descarga')}</button>
     <button type="button" class="icono-btn" data-mas aria-label="Más opciones">${icono('mas-puntos')}</button>`;

  pintarCabecera(cabeceraVolver(
    ticket?.codigo || 'Parte',
    ticket?.nombre_estado || '',
    accionesCabecera()
  ));

  if (ticket) pintar();
  else contenido.innerHTML = `<div class="hueso" style="height:180px;margin-bottom:12px"></div><div class="hueso" style="height:220px"></div>`;

  if (hayRed() && id > 0) {
    try {
      const r = await api.ticket(id);
      ticket = r.ticket;
      historial = r.historial;
      adjuntos = r.adjuntos;
      camposPropios = r.campos || [];
      puedeEditar = r.puede_editar !== false;
      await db.poner('tickets', { ...ticket, pendiente_envio: false });
      await db.ponerVarios('historial', historial.map((h) => ({ ...h, id_ticket: id })));
      await db.ponerVarios('adjuntos', adjuntos);
      pintarCabecera(cabeceraVolver(ticket.codigo, ticket.nombre_estado, accionesCabecera()));

      /* Los observadores, ya con conexión. Si falla, el parte se ve igual:
         es información de más, no el parte en sí. */
      try {
        observadores = (await api.observadores(id)).observadores || [];
      } catch { observadores = []; }
      pintar();
    } catch (e) {
      // Si no hay copia local no se puede pintar nada: se avisa. Si la
      // hay, se sigue con ella, pero el fallo no se traga en silencio:
      // un error escribiendo en el almacén dejaba la ficha a medias sin
      // que nadie se enterase.
      console.error('Al cargar el parte:', e);
      if (!ticket) {
        contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
        return {};
      }
      mensaje('Se muestran los datos guardados en el móvil.', 'error');
    }
  }

  if (!ticket) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Este parte no está guardado en el móvil y ahora no hay conexión.</div></div>`;
    return {};
  }

  /* --------------------------------------------------------------- */
  function pintar() {
    const color = ticket.estado_color || '#64748B';
    const plazo = ticket.fecha_limite && !ticket.es_final ? restante(ticket.fecha_limite) : null;

    /* Un parte aparcado no está «de retraso»: está esperando a su fecha.
       Marcarlo en rojo hace pensar que alguien lo ha dejado tirado, que
       es justo lo contrario de lo que pasa. */
    if (plazo && ticket.visible_desde
        && new Date(String(ticket.visible_desde).replace(' ', 'T') + 'Z') > new Date()) {
      plazo.vencido = false;
      // Y el texto dice lo que pasa de verdad, no «15 días de retraso».
      plazo.texto = `En pausa hasta el ${fechaLarga(ticket.visible_desde)}`;
    }
    const mov = [...historial].sort((a, b) => (a.fecha_creado || '').localeCompare(b.fecha_creado || ''));

    /* La ficha se monta por bloques: cada persona elige cuáles ve y en
       qué orden desde Ajustes › Apariencia. La cabecera y los botones
       están marcados como fijos — sin la primera no se sabe qué parte
       es, y sin los segundos no se puede hacer nada con él. */
    const b = {};

    b.cabecera = `
    ${ticket.pendiente_envio ? `<div class="aviso aviso--senal">${icono('nube')}
      <div>Este parte todavía no ha salido del móvil. Se enviará solo al recuperar la conexión.</div></div>` : ''}

    <div class="ficha__cabecera" style="--estado:${esc(color)}">
      <div class="ficha__meta">
        <span class="parte__estado" style="--estado:${esc(color)}">${esc(ticket.nombre_estado || '')}</span>
        <span class="prio" style="--prio-color:${esc(ticket.prioridad_color || '#64748B')}">${esc(ticket.prioridad || '')}</span>
        ${plazo?.vencido ? `<span class="retraso">${icono('aviso')}${esc(plazo.texto)}</span>` : ''}
        <span class="parte__codigo" style="margin-left:auto">${esc(ticket.codigo || '')}</span>
      </div>

      <h1 class="ficha__titulo">${esc(ticket.titulo_ticket)}</h1>
      ${ticket.detalle_ticket ? `<div class="ficha__detalle">${esc(ticket.detalle_ticket)}</div>` : ''}
    </div>

    ${ticket.id_mantenimiento ? `
      <div class="aviso aviso--info" style="margin-bottom:12px">${icono('calendario')}
        <div>Parte generado por una revisión programada. Al cerrarlo queda
        constancia de que se hizo.</div></div>` : ''}`;

    /* Los datos van en su propia tarjeta y no dentro de la cabecera:
       así se pueden mover u ocultar por separado, que es de lo que se
       trata. */
    b.datos = `
    <div class="tarjeta" style="margin-bottom:12px"><div class="tarjeta__cuerpo">
      <dl class="datos" style="margin-top:0">
        <div class="datos__celda" ${ticket.id_rel_zona ? 'data-ver-zona style="cursor:pointer"' : ''}>
          <dt>Ubicación</dt>
          <dd>${esc(ticket.zona_ruta || '—')}
          ${ticket.id_rel_zona ? `<small style="display:block;color:var(--marca);font-weight:600;font-size:11.5px;margin-top:2px">
            Ver todo lo de este sitio ›</small>` : ''}</dd></div>
        ${ticket.numero_serie ? `
          <div class="datos__celda" data-ver-unidad style="cursor:pointer">
            <dt>Número de serie</dt>
            <dd style="font-family:var(--mono);font-size:13.5px">${esc(ticket.numero_serie)}
            <small style="display:block;color:var(--marca);font-weight:600;font-size:11.5px;margin-top:2px;
                          font-family:var(--tipo)">Ver su historia ›</small></dd></div>` : ''}
        <div class="datos__celda"><dt>Tipo</dt>
          <dd>${esc(ticket.elemento || ticket.familia || '—')}
          ${ticket.familia && ticket.elemento ? `<small style="display:block;font-weight:500;color:var(--tinta-3);font-size:11.5px">${esc(ticket.familia)}</small>` : ''}</dd></div>
        <div class="datos__celda"><dt>Asignado a</dt><dd>${esc(ticket.asignado_a || 'Sin asignar')}</dd></div>
        <div class="datos__celda"><dt>Abierto por</dt><dd>${esc(ticket.creado_por || '—')}</dd></div>
        <div class="datos__celda"><dt>Fecha de alta</dt><dd>${esc(fechaLarga(ticket.fecha_creado))}</dd></div>
        <div class="datos__celda"><dt>${ticket.es_final ? 'Cerrado' : 'Plazo'}</dt>
          <dd>${ticket.es_final ? esc(fechaLarga(ticket.fecha_cierre)) : (plazo ? esc(plazo.texto) : 'Sin plazo')}</dd></div>
        ${ticket.minutos_trabajo ? `<div class="datos__celda"><dt>Tiempo dedicado</dt><dd>${esc(minutosATexto(ticket.minutos_trabajo))}</dd></div>` : ''}
        ${ticket.departamento ? `<div class="datos__celda"><dt>Grupo asignado</dt><dd>${esc(ticket.departamento)}</dd></div>` : ''}
      </dl>
    </div></div>`;

    /* Los datos propios del tipo de avería: temperaturas, presiones, lo
     * que se mide al revisar.
     *
     * Hasta ahora sólo se podían poner al dar el parte, y eso no cuadra
     * con cómo se trabaja: el parte lo abre recepción, que no mide nada,
     * y el técnico que sí mide no tenía dónde anotarlo. Ahora se rellenan
     * desde aquí, cuando se tienen los datos, y cada cambio queda en el
     * seguimiento. */
    const sinRellenar = camposPropios.filter((c) => !c.valor_texto).length;

    b.campos = `    ${camposPropios.length ? `
      <div class="tarjeta" style="margin-bottom:12px;border-left:3px solid var(--marca)">
        <div class="tarjeta__cuerpo">
          <div class="rotulo" style="margin-bottom:9px">Datos del tipo de avería</div>
          <dl class="datos" style="margin-top:0">
            ${camposPropios.map((c) => `
              <div class="datos__celda"><dt>${esc(c.etiqueta)}${
                c.unidad ? ` (${esc(c.unidad)})` : ''}</dt>
                <dd>${esc(c.valor_texto || '—')}</dd></div>`).join('')}
          </dl>
          ${puedeEditar ? `
            <button type="button" class="btn btn--contorno btn--bloque" data-editar-campos
                    style="margin-top:10px">
              ${icono('editar')}${sinRellenar
                ? `Anotar los datos${sinRellenar < camposPropios.length ? ' que faltan' : ''}`
                : 'Corregir estos datos'}
            </button>` : ''}
        </div>
      </div>` : ''}`;

    b.guias = `    ${(() => {
      const guias = modulo('instrucciones') ? instruccionesPara(ticket) : [];
      if (!guias.length) return '';
      return `
        <div class="tarjeta" style="margin-bottom:12px;border-left:3px solid var(--ok)">
          <div class="tarjeta__cuerpo">
            <div class="rotulo" style="margin-bottom:9px">Cómo se hace esto</div>
            ${guias.slice(0, 3).map((g) => `
              <button type="button" class="fila" data-guia="${g.id_instruccion}"
                      style="padding-left:0;padding-right:0">
                ${icono('nota')}
                <span class="fila__texto">${esc(g.titulo)}
                  ${g.aviso ? '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">cuidado</span>' : ''}
                  <small>${esc(g.resumen || '')}</small></span>
                ${icono('flecha', 'fila__flecha')}
              </button>`).join('')}
          </div>
        </div>`;
    })()}`;

    /* Las notas, juntas y a la vista, antes de los botones.
       Estaban sólo dentro del seguimiento, mezcladas con los cambios de
       estado y al final de la ficha: quien llegaba al parte tenía que
       bajar hasta abajo y rebuscar entre los movimientos para enterarse
       de lo que había contado el compañero. */
    b.notas = (() => {
      const notas = mov.filter((m) => (m.detalle_ticket || '').trim());
      if (!notas.length) return '';
      const ultimas = notas.slice(-4).reverse();
      return `    <div class="seccion"><h2>Notas</h2>
        <span class="rotulo">${notas.length}</span></div>
      <div class="tarjeta" style="border-left:3px solid var(--marca)">
        <div class="tarjeta__cuerpo">
          ${ultimas.map((n) => `
            <div style="padding:9px 0;border-top:1px solid var(--borde)">
              <div style="white-space:pre-wrap;line-height:1.5">${esc(n.detalle_ticket.trim())}</div>
              <div class="rotulo" style="margin-top:5px">
                ${esc(n.autor || 'alguien')} · ${esc(haceCuanto(n.fecha_creado))}
                ${n.minutos ? ' · ' + esc(minutosATexto(n.minutos)) : ''}
              </div>
            </div>`).join('')}
          ${notas.length > ultimas.length ? `
            <button type="button" class="btn btn--contorno btn--bloque btn--s" data-ver-notas
                    style="margin-top:10px">Ver las ${notas.length} notas</button>` : ''}
        </div>
      </div>`;
    })();

    /* La conversación, también en el cuerpo.
     *
     * El icono de la cabecera se le pasa por alto a medio mundo, y esto
     * sólo sirve si la gente lo encuentra. Aquí se ve cuántos mensajes
     * hay y quién escribió el último. */
    /* Quién sigue el parte. Sólo si hay alguien: un apartado vacío
       llamado «Lo siguen» no dice nada y ocupa sitio. */
    b.observadores = observadores.length ? `
      <div class="seccion"><h2>También lo siguen</h2></div>
      <div class="filas">
        ${observadores.map((o) => `
          <div class="fila" style="cursor:default">
            ${icono(o.es_departamento ? 'personas' : 'persona')}
            <span class="fila__texto">${esc(o.nombre || '')}
              <small>${o.es_departamento ? 'Departamento entero' : 'Lo sigue'} · no lo trabaja</small></span>
          </div>`).join('')}
      </div>` : '';

    b.chat = `
      <div class="filas" style="margin-top:12px">
        <button type="button" class="fila" data-abrir-chat>
          ${icono('chat')}
          <span class="fila__texto">${ticket.chat_sin_leer
            ? `${ticket.chat_sin_leer} mensaje${ticket.chat_sin_leer === 1 ? '' : 's'} sin leer`
            : (ticket.chat_total
                ? `Conversación · ${ticket.chat_total} mensaje${ticket.chat_total === 1 ? '' : 's'}`
                : 'Conversación')}
            <small>Hablar con quien abrió el parte</small></span>
          ${(() => {
            /* Rojo lo que falta por leer; verde lo que ya se leyó.
               Si no se enseña nada cuando está todo leído, quien vuelve al
               parte no sabe que allí se habló, y la conversación se pierde
               igual que si no existiera. */
            if (ticket.chat_sin_leer) {
              return `<span class="chapa" style="position:static">${ticket.chat_sin_leer}</span>`;
            }
            return ticket.chat_total
              ? `<span class="chapa_leido" style="position:static">${ticket.chat_total}</span>` : '';
          })()}
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>`;


    /* Aparcado: se dice claramente y arriba del todo.

       Sin esto, el parte se veía igual que cualquier otro y alguien
       podía ponerse con él antes de tiempo: justo lo que se quería
       evitar al aparcarlo —el cliente con el cartel de no molestar—. */

    const aparcadoHasta = (() => {
      if (!ticket.visible_desde) return null;
      const cuando = new Date(String(ticket.visible_desde).replace(' ', 'T') + 'Z');
      return cuando > new Date() ? cuando : null;
    })();

    b.aparcado = aparcadoHasta ? `
      <div class="aviso aviso--aparcado">${icono('reloj')}<div>
        <b>Programado: no toca todavía</b><br>
        Vuelve a la cola el ${esc(fechaLarga(ticket.visible_desde))}.
        Hasta entonces no hay que ponerse con él.
        ${puedeEditar && !ticket.es_final ? `
          <div style="margin-top:8px">
            <button type="button" class="btn btn--contorno btn--s" data-accion="reprogramar">
              ${icono('reloj')}Cambiar la fecha</button>
          </div>` : ''}
      </div></div>` : '';


    b.acciones = `    ${botonera()}`;

    b.fotos = `    <div class="seccion"><h2>Fotografías</h2>
      <span class="rotulo">${adjuntos.filter((a) => !a.borrado).length + fotosPendientes.length}</span></div>
    <div class="fotos" id="fotos">${pintarFotos()}</div>`;

    b.historial = `    <div class="seccion"><h2>Seguimiento</h2>
      <span class="rotulo">${mov.length} ${mov.length === 1 ? 'movimiento' : 'movimientos'}</span></div>
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <div class="linea">${mov.map(hito).join('') || '<p style="color:var(--tinta-3);font-size:13.5px;margin:0">Todavía no hay movimientos.</p>'}</div>
    </div></div>`;

    /* «Ya ha pasado aquí»: el historial de ese mismo tipo de avería en
       ese mismo sitio. Se deja el hueco y se rellena después, porque son
       dos consultas más y la ficha no debe esperar por ellas. */
    /* El título lo pone el propio bloque cuando ya sabe si hay algo: un
       «Ya ha pasado aquí» encima de un hueco vacío hace pensar lo
       contrario de lo que pasa. */
    b.repetidos = (ajuste('historial_elemento', true) && ticket.id_elemento)
      ? '<div id="bloque-repetidos"></div>'
      : '';

    contenido.innerHTML = bloquesTicketOrdenados()
      .map((x) => b[x.id] || '')
      .filter((h) => h.trim())
      .join('\n');

    enganchar();
    pintarRepetidos();
  }

  /**
   * El historial del elemento: aquí y en el resto del hotel.
   *
   * Sin cobertura no se pinta nada —son datos que no están guardados en
   * el móvil— y tampoco se deja un cartel de error: el parte se sigue
   * pudiendo trabajar sin esto.
   */
  async function pintarRepetidos() {
    const caja = $('#bloque-repetidos', contenido);
    if (!caja) return;
    if (!hayRed()) { caja.remove(); return; }

    let r;
    try { r = await api.repetidosDe(ticket.id_ticket); }
    catch { caja.remove(); return; }

    const fila = (t) => `
      <button type="button" class="fila" data-ir-parte="${t.id_ticket}">
        <span class="punto" style="background:${esc(t.color_estado || '#94a3b8')}"></span>
        <span class="fila__texto">${esc(t.titulo_ticket)}
          <small>${esc(fechaLarga(t.fecha_creado))} · ${esc(t.nombre_estado || '')}${
            t.ruta ? ' · ' + esc(t.ruta) : ''}</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>`;

    const aqui  = r.aqui || [];
    const otros = r.otros || [];

    if (!aqui.length && !otros.length) {
      // Nada que contar: ni título ni tarjeta vacía.
      caja.remove();
      return;
    }

    caja.innerHTML = `
      <div class="seccion"><h2>Ya ha pasado aquí</h2></div>
      ${aqui.length ? `
        <span class="rotulo" style="display:block;margin:-4px 0 6px">
          ${r.total_aqui} ${r.total_aqui === 1 ? 'vez' : 'veces'} en este mismo sitio</span>
        <div class="filas">${aqui.map(fila).join('')}</div>` : ''}
      ${otros.length ? `
        <div class="seccion" style="margin-top:14px"><h2>El mismo tipo, en otros sitios</h2>
          <span class="rotulo">${r.total_otros}</span></div>
        <div class="filas">${otros.map(fila).join('')}</div>` : ''}
      <div class="filas" style="margin-top:10px">
        <button type="button" class="fila" data-historial-elemento>
          ${icono('panel')}
          <span class="fila__texto">Ver el historial completo
            <small>Todos los partes de ${esc(r.elemento || 'este tipo')}, en Informes</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>`;

    caja.onclick = (e) => {
      const p = e.target.closest('[data-ir-parte]');
      if (p) { ir('/parte/' + p.dataset.irParte); return; }
      if (e.target.closest('[data-historial-elemento]')) {
        /* Se deja preparado el informe: mismo tipo de avería, agrupado
           por sitio. Así el salto lleva a algo hecho, no a un formulario
           en blanco. */
        try {
          sessionStorage.setItem('hurakantick_informe', JSON.stringify({
            agrupar: 'lugar', medida: 'cuenta',
            // Con su nombre, que si no el informe enseña «Tipo de avería = 294».
            condiciones: [{ campo: 'elemento', operador: 'es', valor: ticket.id_elemento,
                            etiqueta_valor: r.elemento || ticket.nombre_elemento || '' }],
            titulo: 'Historial de ' + (r.elemento || 'este tipo'),
          }));
        } catch { /* si no se puede, se abre en blanco */ }
        ir('/informes');
      }
    };
  }

  function botonera() {
    if (!puedeEditar) {
      return `<div class="aviso aviso--info" style="margin-bottom:14px">${icono('info')}
        <div>Puedes consultar este parte, pero no modificarlo: no está asignado
        a ti ni a tu grupo.</div></div>`;
    }
    if (ticket.es_final) {
      /* Un parte cerrado se consulta, no se trabaja: por eso sólo hay
         nota y reabrir. Pero un administrador tiene que poder corregirlo
         igual —un dato mal puesto sigue mal después de cerrarlo, y un
         informe con ese dato sale torcido. */
      const mandaAqui = app.usuario.es_admin || app.usuario.es_superadmin
                        || app.usuario.es_responsable;
      return `<div class="acciones" style="margin-bottom:14px">
        <button type="button" class="btn btn--contorno" data-accion="nota">${icono('nota')}Añadir nota</button>
        <button type="button" class="btn btn--principal" data-accion="reabrir">${icono('sync')}Reabrir</button>
      </div>
      ${mandaAqui ? `
        <div class="acciones" style="margin-bottom:14px">
          <button type="button" class="btn btn--contorno btn--s" data-accion="estado">
            ${icono('banderin')}Cambiar estado</button>
          <button type="button" class="btn btn--contorno btn--s" data-accion="asignar">
            ${icono('personas')}Reasignar</button>
          <button type="button" class="btn btn--contorno btn--s" data-accion="editar">
            ${icono('editar')}Editar</button>
        </div>
        <div class="campo__ayuda" style="margin:-8px 2px 14px">
          Está cerrado, pero puedes corregirlo: los datos mal puestos
          siguen contando en los informes.
        </div>` : ''}`;
    }
    const mio = ticket.id_persona_asignada === app.usuario.id_login;

    /* Quien sólo avisa, avisa: puede corregir su parte y comentarlo, pero
       no cerrarlo ni moverlo de departamento. El servidor ya lo impide;
       aquí se quitan los botones para no ofrecer algo que va a fallar,
       que es peor que no ofrecerlo. */
    const soloAvisa = app.usuario.rol === 'reportador';

    return `
    ${soloAvisa ? '' : `
    <div class="acciones" style="margin-bottom:9px">
      <button type="button" class="btn btn--principal" data-accion="estado">${icono('banderin')}Cambiar estado</button>
      <button type="button" class="btn btn--contorno" data-accion="asignar">${icono('personas')}${mio ? 'Reasignar' : 'Asignar'}</button>
    </div>`}
    <div class="acciones" style="margin-bottom:14px">
      <button type="button" class="btn" data-accion="nota">${icono('nota')}Nota</button>
      ${modulo('fotos') ? `<button type="button" class="btn" data-accion="foto">${icono('camara')}Foto</button>` : ''}
      ${!mio && puedeQuedarselo(ticket)
        ? `<button type="button" class="btn btn--senal" data-accion="cogerlo">${icono('mios')}Me lo quedo</button>`
        : ''}
    </div>`;
  }

  function pintarFotos() {
    const vivas = adjuntos.filter((a) => !a.borrado);
    return [
      ...vivas.map((a) => `
        <div class="foto" data-foto="${esc(urlAdjunto(a.url))}">
          <img src="${esc(urlAdjunto(a.url))}" alt="${esc(a.nombre_original || 'Foto del parte')}" loading="lazy">
        </div>`),
      ...fotosPendientes.map((f) => `
        <div class="foto" data-foto-local="${esc(f.uuid)}">
          <img src="${esc(f.datos)}" alt="Foto pendiente de enviar">
          <span class="foto__pendiente">Sin enviar</span>
        </div>`),
      `<button type="button" class="foto foto--anadir" data-accion="foto" aria-label="Añadir fotografía">${icono('camara')}</button>`,
    ].join('');
  }

  function hito(h) {
    const color = h.estado_nuevo_color || '#7B8C91';
    let accion = '';
    if (h.tipo === 'alta') accion = 'abrió el parte';
    else if (h.tipo === 'estado') accion = `cambió el estado a <strong style="font-weight:640">${esc(h.estado_nuevo || '')}</strong>`;
    else if (h.tipo === 'asignacion') accion = `lo asignó a <strong style="font-weight:640">${esc(h.asignado_nuevo || 'nadie')}</strong>`;
    else if (h.tipo === 'adjunto') accion = 'añadió fotografías';
    else if (h.tipo === 'edicion') accion = 'corrigió los datos del parte';
    else accion = 'anotó';

    return `
    <div class="hito" style="--hito-color:${esc(color)}">
      <div class="hito__alto">
        <span class="hito__autor">${esc(h.autor || 'Alguien')}</span>
        <span class="hito__accion">${accion}</span>
        <span class="hito__hora">${esc(haceCuanto(h.fecha_creado))}</span>
        ${h.pendiente_envio ? `<span class="hito__hora" style="color:var(--aviso);font-weight:650">sin enviar</span>` : ''}
        ${h.minutos ? `<span class="hito__hora">· ${esc(minutosATexto(h.minutos))}</span>` : ''}
      </div>
      ${h.detalle_ticket ? `<div class="hito__texto">${esc(h.detalle_ticket)}</div>` : ''}
    </div>`;
  }

  /* --------------------------------------------------------------- */
  function enganchar() {
    contenido.onclick = async (e) => {
      // «Ver las N notas» lleva al seguimiento, donde están todas.
      if (e.target.closest('[data-editar-campos]')) { anotarDatos(); return; }

      if (e.target.closest('[data-ver-notas]')) {
        contenido.querySelector('[data-bloque="historial"], .seccion h2')
          ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const h = [...contenido.querySelectorAll('.seccion h2')].find((x) => /Seguimiento/.test(x.innerText));
        h?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
      if (e.target.closest('[data-ver-unidad]') && ticket.id_unidad) {
        abrirUnidad(ticket.id_unidad, () => pintar());
        return;
      }
      const g = e.target.closest('[data-guia]');
      if (g) {
        const guia = instruccionesPara(ticket).find(
          (x) => x.id_instruccion === parseInt(g.dataset.guia, 10));
        if (guia) abrirInstruccion(guia);
        return;
      }
      if (e.target.closest('[data-ver-zona]') && ticket.id_rel_zona) {
        ir(`/ubicacion/${ticket.id_rel_zona}`);
        return;
      }
      const f = e.target.closest('[data-foto]');
      if (f) { verFoto(f.dataset.foto); return; }
      const fl = e.target.closest('[data-foto-local]');
      if (fl) { verFoto($('img', fl).src); return; }

      const b = e.target.closest('[data-accion]');
      if (!b) return;

      switch (b.dataset.accion) {
        case 'estado':   await cambiarEstado(); break;
        case 'asignar':  await asignar(); break;
        case 'nota':     await anotar(); break;
        case 'foto':     await anadirFoto(); break;
        case 'cogerlo':  await aplicar({ id_persona_asignada: app.usuario.id_login,
                                         asignado_a: nombreDe(app.usuario),
                                         nota: 'Me hago cargo.' },
                                       'Parte asignado a ti.'); break;
        case 'reabrir':  await cambiarEstado(true); break;
        case 'reprogramar': await reprogramar(); break;
        case 'editar':   await editarParte(); break;
      }
    };

    const comp = document.querySelector('[data-compartir]');
    if (comp) comp.onclick = compartir;

    /* La conversación. Se engancha aquí, con los demás botones de la
       cabecera: hacerlo antes de pintar dejaba el icono sin efecto. */
    const chat = document.querySelector('[data-chat]');
    if (chat) chat.onclick = () => ir('/ticket/' + ticket.id_ticket + '/chat');

    const chatCuerpo = $('[data-abrir-chat]', contenido);
    if (chatCuerpo) chatCuerpo.onclick = () => ir('/ticket/' + ticket.id_ticket + '/chat');

    const mas = document.querySelector('[data-mas]');
    if (mas) mas.onclick = menuMas;

    const edi = document.querySelector('[data-editar]');
    if (edi) edi.onclick = editarParte;
  }

  async function aplicar(cambios, textoOk) {
    ticket = await sync.actualizarTicket(ticket, { ...cambios, autor: nombreDe(app.usuario) });
    historial = (await db.porIndice('historial', 'ticket', ticket.id_ticket)) || [];
    fotosPendientes = await db.fotosDe(ticket.id_ticket);
    pintar();
    tono(hayRed() ? 'ok' : 'guardado');
    mensaje(textoOk, 'ok');
  }

  /**
   * Hasta cuándo se aparca un parte.
   *
   * El caso de siempre: el cliente de la 801 tiene el cartel de «no
   * molestar» hasta el día 30. El parte existe y no se pierde, pero no
   * debe ensuciar la lista de lo que hay que hacer hoy.
   */
  function pedirFecha({ titulo, texto, desde = null, aceptar = 'Programar' }) {
    return new Promise((resolve) => {
      let resuelto = false;
      const hoy = new Date().toISOString().slice(0, 10);
      const manana = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

      /* Al reprogramar se parte de la fecha que ya tiene: lo normal es
         mover un parte unos días, no escribirlo todo otra vez. */
      const actual = desde ? aLocalParaCampo(desde) : '';
      const diaIni  = actual ? actual.slice(0, 10) : manana;
      const horaIni = actual ? actual.slice(11, 16) : '08:00';
      // Si la fecha que tiene ya pasó, no se puede ofrecer como mínimo.
      const minimo = diaIni < hoy ? diaIni : hoy;

      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `
        <div class="aviso aviso--info">${icono('info')}<div>${esc(texto)}</div></div>
        <div class="campo"><label for="pf-fecha">Volver a verlo el</label>
          <div style="display:flex;gap:8px">
            <input class="entrada" type="date" id="pf-fecha" value="${diaIni}" min="${minimo}" style="flex:2">
            <input class="entrada" type="time" id="pf-hora" value="${horaIni}" style="flex:1">
          </div>
          <div class="campo__ayuda">La hora importa: un parte para «el día 30 por
            la mañana» no es lo mismo que a las seis de la tarde.</div>
          <div class="campo__error oculto" id="err-fecha">Dime a partir de qué día.</div>
        </div>
        <div class="campo"><label for="pf-nota">Por qué (opcional)</label>
          <input class="entrada" id="pf-nota" maxlength="200"
                 placeholder="El cliente tiene el cartel de no molestar"></div>`;

      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `
        <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
        <button type="button" class="btn btn--principal" data-si>${esc(aceptar)}</button>`;

      hoja({
        titulo, cuerpo, pie,
        alAbrir: (h) => {
          $('[data-no]', h).onclick = () => { resuelto = true; resolve(null); cerrarHoja(); };
          $('[data-si]', h).onclick = () => {
            const f = $('#pf-fecha', h).value;
            if (!f) { $('#err-fecha', h).classList.remove('oculto'); return; }
            resuelto = true;
            const hora = $('#pf-hora', h).value || '08:00';
            // La hora que ha escrito es la de aquí; se guarda en universal.
            resolve({ fecha: aUtcDesdeLocal(f, hora), nota: $('#pf-nota', h).value.trim() });
            cerrarHoja();
          };
        },
        alCerrar: () => { if (!resuelto) resolve(null); },
      });
    });
  }

  /**
   * A la espera de un tercero: de quién y de qué empresa.
   *
   * Sin esto, el parte se queda en «Pte. Terceros» y dos semanas después
   * nadie recuerda a quién había que llamar ni qué se estaba esperando.
   */
  function pedirTercero({ titulo }) {
    return new Promise((resolve) => {
      let resuelto = false;
      const empresas = app.catalogo?.empresas || [];

      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `
        <div class="campo"><label for="pt-quien">A quién se espera</label>
          <input class="entrada" id="pt-quien" maxlength="80"
                 placeholder="Nombre del técnico o del contacto"></div>
        <div class="campo"><label for="pt-empresa">Empresa (opcional)</label>
          <input class="entrada" id="pt-empresa" maxlength="80" list="pt-empresas"
                 placeholder="Escribe o elige de las ya usadas">
          <datalist id="pt-empresas">
            ${empresas.map((x) => `<option value="${esc(x)}"></option>`).join('')}
          </datalist></div>
        <div class="campo"><label for="pt-nota">Qué se espera</label>
          <input class="entrada" id="pt-nota" maxlength="200"
                 placeholder="Han pedido la pieza, vienen el jueves">
          <div class="campo__error oculto" id="err-ter">Dime al menos a quién o a qué empresa.</div>
        </div>`;

      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `
        <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
        <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

      hoja({
        titulo, cuerpo, pie,
        alAbrir: (h) => {
          setTimeout(() => $('#pt-quien', h)?.focus(), 260);
          $('[data-no]', h).onclick = () => { resuelto = true; resolve(null); cerrarHoja(); };
          $('[data-si]', h).onclick = () => {
            const quien = $('#pt-quien', h).value.trim();
            const empresa = $('#pt-empresa', h).value.trim();
            const detalle = $('#pt-nota', h).value.trim();
            if (!quien && !empresa) { $('#err-ter', h).classList.remove('oculto'); return; }
            resuelto = true;
            resolve({
              nota: ['Se espera a ' + (quien || empresa),
                     (quien && empresa) ? `(${empresa})` : '',
                     detalle].filter(Boolean).join(' · '),
              empresa,
            });
            cerrarHoja();
          };
        },
        alCerrar: () => { if (!resuelto) resolve(null); },
      });
    });
  }

  /**
   * Anotar las temperaturas y demás datos del tipo de avería.
   *
   * Esto es lo que hace el técnico cuando llega: mide, apunta y deja
   * constancia. Antes sólo se podían poner al dar el parte —cuando nadie
   * los sabe todavía— y después no había manera de añadirlos.
   *
   * Cada cambio se apunta en el seguimiento con su antes y su después:
   * si la temperatura de impulsión era 18 y ahora es 12, eso es la
   * prueba de que el trabajo se hizo.
   */
  async function anotarDatos() {
    if (!camposPropios.length) return;

    const valores = {};
    camposPropios.forEach((c) => { valores[c.clave] = c.valor_texto || ''; });

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo__ayuda" style="margin-bottom:10px">
        Lo que se mide al revisar. Lo que dejes en blanco se queda sin anotar.
      </div>
      ${camposPropios.map((c) => campoEditable(c, valores[c.clave])).join('')}`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
      <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: 'Anotar los datos',
      cuerpo,
      pie,
      alAbrir: (h) => {
        // El primer campo con el teclado puesto: se viene a escribir.
        setTimeout(() => h.querySelector('input, textarea')?.focus(), 260);

        $$('[data-campo][data-tipo="lista"]', h).forEach((el) => {
          el.onclick = async () => {
            const c = camposPropios.find((x) => x.clave === el.dataset.campo);
            const v = await elegir({
              titulo: c.etiqueta,
              valor: valores[c.clave],
              opciones: [{ valor: '', texto: 'Sin indicar' },
                         ...(c.opciones || []).map((o) => ({ valor: o, texto: o }))],
            });
            if (v === null) return;
            valores[c.clave] = v || '';
            el.querySelector('.fila__texto').innerHTML =
              `${esc(v || c.etiqueta)}<small>${esc(v ? c.etiqueta : 'Toca para elegir')}</small>`;
          };
        });

        $$('.interruptor[data-campo]', h).forEach((el) => {
          el.onclick = () => {
            const puesto = el.getAttribute('aria-checked') !== 'true';
            el.setAttribute('aria-checked', puesto ? 'true' : 'false');
            valores[el.dataset.campo] = puesto ? '1' : '0';
          };
        });

        $('[data-no]', h).onclick = () => cerrarHoja();
        $('[data-si]', h).onclick = async () => {
          $$('input[data-campo], textarea[data-campo]', h).forEach((el) => {
            valores[el.dataset.campo] = el.value.trim();
          });
          cerrarHoja();
          await aplicar({ campos: valores }, 'Datos anotados.');

          /* Se espera a que el envío salga y se vuelve a leer el parte.
             Los valores y la línea del seguimiento los compone el
             servidor —el «26 °C → 21 °C» sale de comparar con lo que
             había—, así que antes de que llegue allí no hay nada que
             enseñar. Sin esta espera la tarjeta seguía con guiones y
             parecía que no se había guardado. */
          if (hayRed() && id > 0) {
            try {
              await sync.sincronizar();
              const r = await api.ticket(id);
              ticket = r.ticket || ticket;
              camposPropios = r.campos || camposPropios;
              historial = r.historial || historial;
              pintar();
            } catch { /* se verá al volver a entrar */ }
          }

        };
      },
    });
  }

  /** Un campo propio, listo para escribir en él. */
  function campoEditable(c, v) {
    if (c.tipo === 'interruptor') {
      return `<div class="fila" style="cursor:default;padding-left:0;padding-right:0">
        <span class="fila__texto">${esc(c.etiqueta)}
          ${c.ayuda ? `<small>${esc(c.ayuda)}</small>` : ''}</span>
        <button type="button" class="interruptor" data-campo="${esc(c.clave)}" role="switch"
                aria-checked="${v === '1'}"></button></div>`;
    }
    if (c.tipo === 'lista') {
      return `<button type="button" class="fila" data-campo="${esc(c.clave)}" data-tipo="lista"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde);margin-bottom:8px">
        ${icono('lista')}
        <span class="fila__texto">${esc(v || c.etiqueta)}
          <small>${esc(v ? c.etiqueta : 'Toca para elegir')}</small></span>
        ${icono('flecha', 'fila__flecha')}</button>`;
    }
    if (c.tipo === 'area') {
      return `<div class="campo"><label>${esc(c.etiqueta)}</label>
        <textarea class="area" data-campo="${esc(c.clave)}" style="min-height:76px">${esc(v)}</textarea>
        ${c.ayuda ? `<div class="campo__ayuda">${esc(c.ayuda)}</div>` : ''}</div>`;
    }
    const tipo = c.tipo === 'numero' || c.tipo === 'temperatura' ? 'number'
               : c.tipo === 'fecha' ? 'date' : 'text';
    return `<div class="campo">
      <label>${esc(c.etiqueta)}${c.unidad ? ` (${esc(c.unidad)})` : ''}</label>
      <input class="entrada" type="${tipo}" inputmode="${tipo === 'number' ? 'decimal' : 'text'}"
             data-campo="${esc(c.clave)}" value="${esc(v)}">
      ${c.ayuda ? `<div class="campo__ayuda">${esc(c.ayuda)}</div>` : ''}</div>`;
  }

  async function cambiarEstado(reabrir = false) {
    // Sólo los estados que tienen sentido para esta clase de parte:
    // montar una cuna no necesita «Pte. Terceros».
    const clase = ticket.clase || 'averia';
    const estados = (app.catalogo?.estados || [])
      .filter((e) => !e.clases || e.clases.includes(clase))
      .filter((e) => reabrir ? !e.es_final : true);
    const destino = await elegir({
      titulo: 'Nuevo estado',
      valor: ticket.id_estado,
      opciones: estados.map((e) => ({
        valor: e.id_estado, texto: e.nombre_estado, color: e.color,
        detalle: e.es_final ? 'Cierra el parte' : '',
      })),
    });
    if (!destino) return;
    const e = app.catalogo.estados.find((x) => x.id_estado === destino);

    /* Elegir el estado que ya tiene no suele querer decir nada... salvo
       en «Programado»: ahí lo que se quiere es mover la fecha. Antes
       esto se perdía en un `return` y la única salida era sacar el parte
       de programado y volver a programarlo. */
    if (destino === ticket.id_estado) {
      if ((e?.nombre_estado || '').toLowerCase().includes('programad')) {
        await reprogramar();
      }
      return;
    }

    /* Cada estado pide lo suyo.
     *
     * Preguntar «¿qué has hecho?» al pasar un parte a «Pendiente
     * Asignar» no tiene sentido: no se ha hecho nada, se está
     * devolviendo a la cola. Y al programarlo lo que hace falta es la
     * fecha, que es justo lo que no se preguntaba. */
    const nombre = (e.nombre_estado || '').toLowerCase();
    let extra = {};

    if (nombre.includes('programad')) {
      const cuando = await pedirFecha({
        titulo: `Pasar a «${e.nombre_estado}»`,
        texto: 'Hasta esa fecha el parte no saldrá en la lista de quien lo tiene '
             + 'que atender. Quien lo abrió y los responsables sí lo verán.',
      });
      if (cuando === null) return;
      extra = { visible_desde: cuando.fecha, nota: cuando.nota };

    } else if (nombre.includes('tercero')) {
      const quien = await pedirTercero({ titulo: `Pasar a «${e.nombre_estado}»` });
      if (quien === null) return;
      extra = { nota: quien.nota };

    } else if (e.es_final || nombre.includes('curso')) {
      // Aquí sí: se está contando trabajo hecho.
      const datos = await pedirNota({
        titulo: `Pasar a «${e.nombre_estado}»`,
        etiqueta: 'Qué has hecho',
        obligatorio: !!e.es_final && ajuste('cerrar_pide_nota', true),
        pedirTiempo: modulo('tiempo'),
      });
      if (datos === null) return;
      extra = { nota: datos.nota, minutos: datos.minutos };

    } else {
      // Devolver a la cola, reabrir, asignar: basta con confirmar y, si
      // acaso, dejar dicho por qué.
      const datos = await pedirNota({
        titulo: `Pasar a «${e.nombre_estado}»`,
        etiqueta: 'Nota',
        obligatorio: false,
        pedirTiempo: false,
      });
      if (datos === null) return;
      extra = { nota: datos.nota };
    }

    await aplicar({
      id_estado: destino,
      nombre_estado: e.nombre_estado,
      estado_color: e.color,
      es_final: e.es_final,
      ...extra,
    }, `Parte en «${e.nombre_estado}».`);
  }

  /**
   * Mover la fecha de un parte ya programado.
   *
   * Un parte aplazado es justo el que más veces cambia de fecha: el
   * cliente alarga la estancia, el proveedor no viene el día que dijo.
   * Se queda en el mismo estado; sólo se mueve el día y la hora, y
   * queda apuntado en el seguimiento.
   */
  async function reprogramar() {
    const cuando = await pedirFecha({
      titulo: 'Cambiar la fecha',
      texto: `Ahora vuelve a la cola el ${fechaLarga(ticket.visible_desde)}. `
           + 'Si pones una fecha ya pasada, el parte vuelve a la cola en cuanto se guarde.',
      desde: ticket.visible_desde,
      aceptar: 'Cambiar la fecha',
    });
    if (cuando === null) return;

    await aplicar(
      { visible_desde: cuando.fecha, nota: cuando.nota },
      'Fecha cambiada.',
    );
  }

  /**
   * Asignar el parte.
   *
   * Dos cosas distintas y por separado: el departamento —de quién es el
   * trabajo— y la persona —quién lo hace—. Al elegir persona sólo salen
   * las de ese departamento: poner a un camarero en una avería
   * eléctrica no la resuelve, y además la saca de la cola de quienes sí
   * podrían.
   */
  /** Quién sigue este parte sin trabajarlo. */
  async function gestionarObservadores() {
    const gente = app.catalogo?.usuarios || [];
    const grupos = app.catalogo?.departamentos || [];

    const opciones = [
      ...observadores.map((o) => ({
        valor: 'quitar:' + o.id_observador,
        texto: 'Quitar a ' + o.nombre,
        detalle: o.es_departamento ? 'Departamento entero' : 'Persona',
        icono: 'papelera',
      })),
      { valor: 'nuevo-dep', texto: 'Que lo siga un departamento', icono: 'personas' },
      { valor: 'nueva-persona', texto: 'Que lo siga una persona', icono: 'persona' },
    ];

    const elegido = await elegir({ titulo: 'Quién sigue el parte', opciones });
    if (!elegido) return;

    try {
      if (elegido.startsWith('quitar:')) {
        await api.quitarObservador(ticket.id_ticket, elegido.split(':')[1]);
        mensaje('Ya no lo sigue.', 'ok');
      } else if (elegido === 'nuevo-dep') {
        const v = await elegirBuscando({
          titulo: 'Qué departamento lo sigue',
          opciones: grupos.map((g) => ({
            valor: g.id_departamento, texto: g.nombre, icono: 'personas',
          })),
          marcador: 'Buscar departamento',
        });
        if (v === null) return;
        await api.ponerObservador(ticket.id_ticket, { id_departamento: v });
        mensaje('Ya lo está siguiendo.', 'ok');
      } else {
        const v = await elegirBuscando({
          titulo: 'Quién lo sigue',
          opciones: gente.map((g) => ({
            valor: g.id_login, texto: g.nombre_completo || g.nombre,
            detalle: g.departamento || '', icono: 'persona',
          })),
          marcador: 'Buscar persona',
        });
        if (v === null) return;
        await api.ponerObservador(ticket.id_ticket, { id_login: v });
        mensaje('Ya lo está siguiendo.', 'ok');
      }
      observadores = (await api.observadores(ticket.id_ticket)).observadores || [];
      pintar();
    } catch (e) {
      mensaje(e.message, 'error');
    }
  }

  async function asignar() {
    const yo = app.usuario.id_login;
    const gente = app.catalogo?.usuarios || [];
    const grupos = app.catalogo?.departamentos || [];
    const grupoActual = ticket.id_grupo_asignado;

    const que = await elegir({
      titulo: 'Qué se cambia',
      opciones: [
        { valor: 'persona', texto: 'Quién lo hace',
          detalle: ticket.asignado_a || 'Ahora mismo, nadie', icono: 'persona' },
        ...(modulo('grupos') ? [{ valor: 'grupo', texto: 'A qué departamento va',
          detalle: ticket.departamento || 'Sin departamento', icono: 'personas' }] : []),
        ...(app.usuario.es_responsable ? [{ valor: 'observador', texto: 'Quién lo sigue',
          detalle: observadores.length
            ? observadores.map((o) => o.nombre).join(', ')
            : 'Nadie, aparte de quien lo trabaja',
          icono: 'personas' }] : []),
      ],
    });
    if (!que) return;

    /* Observadores: dar acceso a ESTE parte a alguien que no lo trabaja.
     *
     * Es para cuando un parte lo lleva un departamento pero le interesa a
     * otro: la caja fuerte la arregla servicios técnicos, pero recepción
     * necesita seguirlo porque es quien da la cara con el cliente. */
    if (que === 'observador') {
      await gestionarObservadores();
      return;
    }

    if (que === 'grupo') {
      const v = await elegirBuscando({
        titulo: 'A qué departamento va',
        opciones: grupos.map((g) => ({
          valor: g.id_departamento, texto: g.nombre, icono: 'personas',
        })),
        valor: grupoActual,
        marcador: 'Buscar departamento',
      });
      if (v === null) return;

      const cambios = { id_grupo_asignado: v };
      // Si la persona asignada no es de ese departamento, se suelta:
      // dejarla sería asignar trabajo a quien ya no le toca.
      const p = gente.find((u) => u.id_login === ticket.id_persona_asignada);
      if (p && p.id_departamento !== v) {
        cambios.id_persona_asignada = null;
      }
      await aplicar(cambios,
        `Pasa a ${grupos.find((g) => g.id_departamento === v)?.nombre || 'otro departamento'}.`);
      return;
    }

    if (!grupoActual) {
      mensaje('Este parte no tiene departamento. Ponle uno primero.', 'error');
      return;
    }

    const delGrupo = gente.filter((u) => u.id_departamento === grupoActual);
    if (!delGrupo.length) {
      mensaje('Ese departamento no tiene a nadie dado de alta.', 'error');
      return;
    }

    const soyDelGrupo = app.usuario.id_departamento === grupoActual;
    const v = await elegirBuscando({
      titulo: 'Quién lo hace',
      opciones: [
        ...(soyDelGrupo && ticket.id_persona_asignada !== yo
            ? [{ valor: 'p:' + yo, texto: 'A mí', icono: 'mios', destacado: true }] : []),
        { valor: 'nadie', texto: 'Dejar sin asignar',
          detalle: 'Vuelve a la cola del departamento', icono: 'personas', destacado: true },
        ...delGrupo.filter((u) => u.id_login !== yo).map((u) => ({
          valor: 'p:' + u.id_login, texto: u.nombre_completo, detalle: u.correo,
        })),
      ],
      valor: ticket.id_persona_asignada ? 'p:' + ticket.id_persona_asignada : 'nadie',
      marcador: `Buscar entre ${delGrupo.length} de ${ticket.departamento || 'el departamento'}`,
      vacio: 'Nadie de ese departamento coincide.',
    });
    if (v === null) return;

    const idP = v.startsWith('p:') ? parseInt(v.slice(2), 10) : null;
    const p = delGrupo.find((u) => u.id_login === idP);
    await aplicar({ id_persona_asignada: idP },
      idP ? `Asignado a ${p?.nombre_completo || 'alguien'}.` : 'Vuelve a la cola del departamento.');
  }

  async function anotar() {
    const datos = await pedirNota({ titulo: 'Añadir una nota', etiqueta: 'Nota', obligatorio: true, pedirTiempo: true });
    if (!datos) return;
    await aplicar({ nota: datos.nota, minutos: datos.minutos }, 'Nota añadida.');
  }

  async function anadirFoto() {
    const fotos = await elegirFotos();
    if (!fotos.length) return;
    for (const f of fotos) await sync.subirFoto(ticket, f.datos, f.nombre);
    fotosPendientes = await db.fotosDe(ticket.id_ticket);
    pintar();
    tono('guardado');
    mensaje(`${fotos.length} ${fotos.length === 1 ? 'foto añadida' : 'fotos añadidas'}.`, 'ok');
  }

  /** Corrige los datos del parte: resumen, detalle, tipo y ubicación. */
  async function editarParte() {
    if (!Auth_puedeEditar()) {
      mensaje('No tienes permiso para modificar este parte.', 'error');
      return;
    }

    const zonaActual = { id_rel: ticket.id_rel_zona || null, z1: null, z2: null, z3: null, z4: null, ruta: ticket.zona_ruta || '' };
    const combo = (app.catalogo?.zonas?.combinaciones || [])
      .find((c) => c.id_rel === ticket.id_rel_zona);
    if (combo) Object.assign(zonaActual, { z1: combo.z1, z2: combo.z2, z3: combo.z3, z4: combo.z4 });

    let elegido = {
      id_elemento: ticket.id_elemento,
      id_familia: ticket.id_familia,
      zona: zonaActual,
      id_prioridad: ticket.id_prioridad,
      id_estado: ticket.id_estado,
      id_grupo_asignado: ticket.id_grupo_asignado,
      id_persona_asignada: ticket.id_persona_asignada,
    };

    /* Quién puede tocar el reparto y la urgencia, además del texto.
       Un administrador puede corregir cualquier parte en cualquier
       estado: si un dato está mal, sigue estándolo después de cerrarlo
       y sale torcido en los informes. */
    const mandaAqui = app.usuario.es_admin || app.usuario.es_superadmin
                      || app.usuario.es_responsable;

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo">
        <label for="e-titulo">Resumen</label>
        <input class="entrada" id="e-titulo" maxlength="120" value="${esc(ticket.titulo_ticket || '')}">
      </div>

      <div class="campo">
        <label for="e-detalle">Detalle</label>
        <textarea class="area" id="e-detalle" maxlength="2000">${esc(ticket.detalle_ticket || '')}</textarea>
      </div>

      <div class="campo">
        <label>Tipo de avería</label>
        <button type="button" class="fila" id="e-elemento"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('llaveinglesa')}
          <span class="fila__texto" id="e-txt-elemento">${esc(ticket.elemento || 'Sin especificar')}
            <small>Toca para cambiar</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="campo">
        <label>Ubicación</label>
        <button type="button" class="fila" id="e-zona"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('ubicacion')}
          <span class="fila__texto" id="e-txt-zona">${esc(ticket.zona_ruta || 'Sin ubicación')}
            <small>Toca para cambiar</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      ${mandaAqui ? `
        <!-- Lo que sólo cambia quien reparte. Un técnico corrige el
             texto de su parte; mover prioridades, plazos o dueños es
             otra cosa y por eso va aparte. -->
        <div class="seccion"><h2>Reparto y urgencia</h2></div>

        <div class="campo">
          <label>Prioridad</label>
          <div class="opciones" id="e-prioridad">
            ${(app.catalogo?.prioridades || []).map((pr) => `
              <button type="button" class="opcion" data-p="${pr.id_prioridad}"
                      aria-pressed="${pr.id_prioridad === ticket.id_prioridad}"
                      style="--prio-color:${esc(pr.color)}">${esc(pr.nombre)}</button>`).join('')}
          </div>
        </div>

        <div class="campo">
          <label>Estado</label>
          <button type="button" class="fila" id="e-estado"
                  style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
            ${icono('banderin')}
            <span class="fila__texto" id="e-txt-estado">${esc(ticket.nombre_estado || '')}
              <small>Toca para cambiar</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>
        </div>

        <div class="campo">
          <label>Departamento</label>
          <button type="button" class="fila" id="e-grupo"
                  style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
            ${icono('personas')}
            <span class="fila__texto" id="e-txt-grupo">${esc(ticket.departamento || 'Sin departamento')}
              <small>De quién es el trabajo</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>
        </div>

        <div class="campo">
          <label>Persona</label>
          <button type="button" class="fila" id="e-persona"
                  style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
            ${icono('persona')}
            <span class="fila__texto" id="e-txt-persona">${esc(ticket.asignado_a || 'Sin asignar')}
              <small>Sólo del departamento elegido</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>
        </div>

        <div class="campo">
          <label for="e-limite">Fecha límite</label>
          <input class="entrada" id="e-limite" type="datetime-local"
                 value="${esc(aLocalParaCampo(ticket.fecha_limite))}">
          <div class="campo__ayuda">
            Se recalcula sola al cambiar la prioridad. Aquí se fija a mano.
          </div>
        </div>

        ${modulo('coste') ? `
          <div class="campo">
            <label for="e-coste">Coste de la reparación (€)</label>
            <input class="entrada" id="e-coste" type="number" min="0" step="0.01"
                   inputmode="decimal" value="${ticket.coste ?? ''}">
            <div class="campo__ayuda">
              Material y horas externas. Sale en los informes por área.
            </div>
          </div>` : ''}

        ${modulo('tiempo') ? `
          <div class="campo">
            <label for="e-minutos">Tiempo dedicado (minutos)</label>
            <input class="entrada" id="e-minutos" type="number" min="0" max="10000"
                   inputmode="numeric" value="${ticket.minutos_trabajo || ''}">
          </div>` : ''}
      ` : ''}

      <div>
        <button type="button" class="btn btn--peligro btn--bloque btn--s" id="e-borrar">
          ${icono('papelera')}Eliminar este parte
        </button>
        <div class="campo__ayuda" style="text-align:center;margin-top:6px">
          Deja de verse en la aplicación. El historial se conserva en la base de datos.
        </div>
      </div>
      <div id="e-error"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
      <button type="button" class="btn btn--principal" data-si>Guardar cambios</button>`;

    hoja({
      titulo: 'Editar ' + (ticket.codigo || 'parte'),
      cuerpo, pie,
      alAbrir: (h) => {
        const niveles = app.catalogo?.niveles_zona || ['Edificio', 'Planta', 'Unidad', 'Punto'];

        $('#e-elemento', h).onclick = async () => {
          const v = await elegirElemento({
            id_familia: elegido.id_familia, id_elemento: elegido.id_elemento });
          if (v === null) return;
          elegido.id_elemento = v.id_elemento;
          elegido.id_familia  = v.id_familia;
          $('#e-txt-elemento', h).innerHTML =
            `${esc(v.nombre || 'Sin especificar')}<small>Toca para cambiar</small>`;
        };

        $('#e-zona', h).onclick = async () => {
          const r = await elegirUbicacion(elegido.zona, niveles);
          if (!r) return;
          elegido.zona = r;
          $('#e-txt-zona', h).innerHTML =
            `${esc(r.ruta || 'Sin ubicación')}<small>Toca para cambiar</small>`;
        };

        /* Los campos de reparto y urgencia, sólo para quien manda.
           Se resuelven aquí y no en el guardado porque cada uno abre su
           propio selector. */
        const gp = $('#e-prioridad', h);
        if (gp) gp.onclick = (e) => {
          const b = e.target.closest('[data-p]');
          if (!b) return;
          elegido.id_prioridad = parseInt(b.dataset.p, 10);
          $$('[data-p]', gp).forEach((x) =>
            x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        };

        const be = $('#e-estado', h);
        if (be) be.onclick = async () => {
          const v = await elegir({
            titulo: 'Estado',
            opciones: (app.catalogo?.estados || []).map((x) => ({
              valor: x.id_estado, texto: x.nombre_estado, color: x.color,
              detalle: x.es_final ? 'Cierra el parte' : '',
            })),
            valor: elegido.id_estado,
          });
          if (!v) return;
          elegido.id_estado = v;
          const x = (app.catalogo?.estados || []).find((y) => y.id_estado === v);
          $('#e-txt-estado', h).innerHTML =
            `${esc(x?.nombre_estado || '')}<small>Toca para cambiar</small>`;
        };

        const bg = $('#e-grupo', h);
        if (bg) bg.onclick = async () => {
          const v = await elegirBuscando({
            titulo: 'A qué departamento va',
            opciones: (app.catalogo?.departamentos || []).map((d) => ({
              valor: d.id_departamento, texto: d.nombre, icono: 'personas' })),
            valor: elegido.id_grupo_asignado,
            marcador: 'Buscar departamento',
          });
          if (v === null) return;

          // Cambiar de departamento suelta a quien ya no le toca.
          const p = (app.catalogo?.usuarios || [])
            .find((u) => u.id_login === elegido.id_persona_asignada);
          if (p && p.id_departamento !== v) {
            elegido.id_persona_asignada = null;
            $('#e-txt-persona', h).innerHTML =
              'Sin asignar<small>Sólo del departamento elegido</small>';
          }

          elegido.id_grupo_asignado = v;
          const d = (app.catalogo?.departamentos || []).find((x) => x.id_departamento === v);
          $('#e-txt-grupo', h).innerHTML =
            `${esc(d?.nombre || '')}<small>De quién es el trabajo</small>`;
        };

        const bp = $('#e-persona', h);
        if (bp) bp.onclick = async () => {
          if (!elegido.id_grupo_asignado) {
            mensaje('Elige antes el departamento.', 'error');
            return;
          }
          const gente = (app.catalogo?.usuarios || [])
            .filter((u) => u.id_departamento === elegido.id_grupo_asignado);
          if (!gente.length) {
            mensaje('Ese departamento no tiene a nadie dado de alta.', 'error');
            return;
          }
          const v = await elegirBuscando({
            titulo: 'Quién lo hace',
            opciones: [
              { valor: 'nadie', texto: 'Sin asignar',
                detalle: 'Vuelve a la cola del departamento', icono: 'personas', destacado: true },
              ...gente.map((u) => ({
                valor: 'p:' + u.id_login, texto: u.nombre_completo, detalle: u.correo })),
            ],
            valor: elegido.id_persona_asignada ? 'p:' + elegido.id_persona_asignada : 'nadie',
            marcador: `Buscar entre ${gente.length}`,
          });
          if (v === null) return;
          elegido.id_persona_asignada = v.startsWith('p:') ? parseInt(v.slice(2), 10) : null;
          const u = gente.find((x) => x.id_login === elegido.id_persona_asignada);
          $('#e-txt-persona', h).innerHTML = u
            ? `${esc(u.nombre_completo)}<small>Sólo del departamento elegido</small>`
            : 'Sin asignar<small>Sólo del departamento elegido</small>';
        };

        const borrarDef = $('#e-borrar-def', h);
        if (borrarDef) borrarDef.onclick = () => { cerrarHoja(); borrarDefinitivo(); };

        const borrar = $('#e-borrar', h);
        if (borrar) borrar.onclick = async () => {
          const ok = await confirmar({
            titulo: 'Eliminar el parte',
            texto: `${ticket.codigo} dejará de aparecer en la aplicación. Esto no se puede deshacer desde aquí.`,
            aceptar: 'Eliminar', peligro: true,
          });
          if (!ok) return;
          try {
            await api.borrarTicket(ticket.id_ticket);
            await db.borrar('tickets', ticket.id_ticket);
            cerrarHoja();
            mensaje('Parte eliminado.', 'ok');
            volver();
          } catch (e) {
            $('#e-error', h).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
          }
        };
        $('[data-no]', h).onclick = () => cerrarHoja();

        $('[data-si]', h).onclick = async () => {
          const titulo = $('#e-titulo', h).value.trim();
          if (!titulo) {
            $('#e-error', h).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>El resumen no puede quedar vacío.</div></div>`;
            return;
          }
          const cambios = { titulo, detalle: $('#e-detalle', h).value.trim(), nota: '' };

          /* Reparto y urgencia. Sólo se manda lo que de verdad cambia:
             enviarlo todo llenaría el historial de «cambios» que no lo
             son y haría imposible leerlo. */
          if (mandaAqui) {
            if (elegido.id_prioridad !== ticket.id_prioridad) {
              const pr = (app.catalogo?.prioridades || [])
                .find((x) => x.id_prioridad === elegido.id_prioridad);
              cambios.id_prioridad = elegido.id_prioridad;
              cambios.prioridad = pr?.nombre || null;
              cambios.prioridad_color = pr?.color || null;
            }
            if (elegido.id_estado !== ticket.id_estado) {
              const es = (app.catalogo?.estados || [])
                .find((x) => x.id_estado === elegido.id_estado);
              cambios.id_estado = elegido.id_estado;
              cambios.nombre_estado = es?.nombre_estado || null;
              cambios.estado_color = es?.color || null;
              cambios.es_final = !!es?.es_final;
            }
            if (elegido.id_grupo_asignado !== ticket.id_grupo_asignado) {
              const d = (app.catalogo?.departamentos || [])
                .find((x) => x.id_departamento === elegido.id_grupo_asignado);
              cambios.id_grupo_asignado = elegido.id_grupo_asignado;
              cambios.departamento = d?.nombre || null;
            }
            if (elegido.id_persona_asignada !== ticket.id_persona_asignada) {
              const u = (app.catalogo?.usuarios || [])
                .find((x) => x.id_login === elegido.id_persona_asignada);
              cambios.id_persona_asignada = elegido.id_persona_asignada;
              cambios.asignado_a = u?.nombre_completo || null;
            }

            const lim = $('#e-limite', h)?.value;
            const limActual = aLocalParaCampo(ticket.fecha_limite);
            if (lim !== limActual) {
              // El campo trabaja en la hora de aquí; se guarda en universal.
              cambios.fecha_limite = lim ? aUtcDesdeLocal(lim) : null;
            }

            const cos = $('#e-coste', h)?.value;
            if (cos !== undefined && String(ticket.coste ?? '') !== String(cos)) {
              cambios.coste = cos === '' ? null : parseFloat(cos);
            }

            const min = $('#e-minutos', h)?.value;
            if (min !== undefined && String(ticket.minutos_trabajo || '') !== String(min)) {
              cambios.minutos_trabajo = min ? parseInt(min, 10) : 0;
            }
          }

          if (elegido.id_elemento !== ticket.id_elemento || elegido.id_familia !== ticket.id_familia) {
            const el = app.catalogo?.elementos?.find((x) => x.id_elemento === elegido.id_elemento);
            cambios.id_elemento = elegido.id_elemento;
            cambios.id_familia  = elegido.id_familia;
            cambios.elemento = el?.nombre || null;
            cambios.elemento_icono = el?.icono || null;
          }

          // El selector ya devuelve el id_rel de la combinación, así que
          // no hace falta pedirlo al servidor salvo que sea nueva.
          if (elegido.zona.ruta !== (ticket.zona_ruta || '')) {
            if (!elegido.zona.id_rel && (elegido.zona.z1 || elegido.zona.z2 || elegido.zona.z3 || elegido.zona.z4)) {
              if (!hayRed()) {
                $('#e-error', h).innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}<div>
                  Esa ubicación aún no existe y crearla necesita conexión.
                  Lo demás sí se puede guardar ahora.</div></div>`;
                return;
              }
              try {
                const r = await api.crearZona({
                  zona1: elegido.zona.z1, zona2: elegido.zona.z2,
                  zona3: elegido.zona.z3, zona4: elegido.zona.z4,
                });
                elegido.zona.id_rel = r.id_rel;
                elegido.zona.ruta = r.ruta;
              } catch (e) {
                $('#e-error', h).innerHTML =
                  `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
                return;
              }
            }
            cambios.id_rel_zona = elegido.zona.id_rel;
            cambios.zona_ruta   = elegido.zona.ruta;
          }

          cerrarHoja();
          await aplicar(cambios, 'Parte actualizado.');
        };
      },
    });
  }

  function Auth_puedeEditar() {
    return app.usuario.es_admin || app.usuario.es_responsable
        || ticket.id_persona_asignada === app.usuario.id_login
        || ticket.id_realiza_accion === app.usuario.id_login;
  }

  /**
   * Menú de opciones del parte.
   *
   * El borrado vivía dentro de la hoja de editar y al final del todo:
   * existía, pero nadie lo encontraba. Aquí está donde se busca —los
   * tres puntos de la cabecera— y en un solo toque.
   */
  /**
   * Manda el parte a la papelera.
   *
   * Deja de verse en la aplicación pero sigue en la base de datos, así
   * que se puede recuperar. Es lo que se quiere casi siempre.
   */
  async function borrar() {
    /* Se llama «ocultar» porque es lo que hace: el parte deja de verse
       pero sigue en la base de datos. NO va a la papelera —allí sólo
       entra lo que se elimina del todo— y ahora mismo no hay pantalla
       para volver a sacarlo: hace falta mano de administrador sobre la
       base. Decirlo así evita que alguien oculte algo creyendo que lo
       recupera con dos toques. */
    const ok = await confirmar({
      titulo: 'Ocultar este parte',
      texto: `${ticket.codigo} deja de verse en las listas. El historial se `
           + 'conserva entero; para volver a sacarlo hay que ir a '
           + 'Administración → Gestión de partes.',
      aceptar: 'Ocultar',
      peligro: true,
    });
    if (!ok) return;
    try {
      await api.borrarTicket(ticket.id_ticket);
      await db.borrar('tickets', ticket.id_ticket);
      tono('ok');
      mensaje('Parte enviado a la papelera.', 'ok');
      ir('/lista');
    } catch (e) { mensaje(e.message, 'error'); }
  }

  /**
   * Borrado definitivo. Sólo administradores.
   *
   * Se avisa con claridad de que no hay vuelta atrás: la diferencia con
   * la papelera es justo esa, y confundirlas cuesta caro.
   */
  async function borrarDefinitivo() {
    const ok = await confirmar({
      titulo: 'Eliminar definitivamente',
      texto: `Se elimina ${ticket.codigo} de la base de datos con todo su historial, `
           + 'sus notas y sus fotos. No va a la papelera: desaparece. '
           + 'Esto no se puede deshacer.',
      aceptar: 'Eliminar definitivamente',
      peligro: true,
    });
    if (!ok) return;
    try {
      await api.borrarTicketDef(ticket.id_ticket);
      await db.borrar('tickets', ticket.id_ticket);
      tono('ok');
      mensaje('Parte eliminado definitivamente.', 'ok');
      ir('/lista');
    } catch (e) { mensaje(e.message, 'error'); }
  }

  async function menuMas() {
    const esAdmin = app.usuario.es_admin;
    const opciones = [
      { valor: 'editar', texto: 'Editar el parte', icono: 'editar' },
      { valor: 'compartir', texto: 'Compartir', icono: 'descarga' },
    ];
    // Ocultar es cosa del administrador, como en el servidor: ofrecérselo
    // a quien no puede usarlo sólo servía para darle un error.
    if (esAdmin) {
      opciones.push({ valor: 'papelera', texto: 'Ocultar de las listas',
                      detalle: 'Deja de verse; se recupera desde Gestión de partes', icono: 'ojo-no' });
    }
    if (esAdmin) {
      opciones.push({ valor: 'eliminar', texto: 'Eliminar definitivamente',
                      detalle: 'Sale de la base viva y va a la papelera, de donde se puede devolver',
                      icono: 'papelera', peligro: true });
    }

    const v = await elegir({ titulo: ticket.codigo || 'Opciones', opciones });
    if (!v) return;
    if (v === 'editar') { editarParte(); return; }
    if (v === 'compartir') { compartir(); return; }
    if (v === 'papelera') { borrar(); return; }
    if (v === 'eliminar') { borrarDefinitivo(); return; }
  }

  function compartir() {
    const texto = [
      `${ticket.codigo} · ${ticket.titulo_ticket}`,
      ticket.zona_ruta ? `Ubicación: ${ticket.zona_ruta}` : '',
      `Estado: ${ticket.nombre_estado} · Prioridad: ${ticket.prioridad}`,
      ticket.asignado_a ? `Asignado a: ${ticket.asignado_a}` : 'Sin asignar',
      '',
      ticket.detalle_ticket || '',
    ].filter(Boolean).join('\n');

    if (navigator.share) {
      navigator.share({ title: ticket.codigo, text: texto }).catch(() => {});
    } else {
      navigator.clipboard?.writeText(texto)
        .then(() => mensaje('Parte copiado al portapapeles.', 'ok'))
        .catch(() => mensaje('No se ha podido copiar.', 'error'));
    }
  }

  // Si el parte sale del móvil mientras se está mirando, el aviso de
  // «todavía no ha salido» tiene que desaparecer solo.
  const dejarDeEscuchar = sync.alCambiar(async (estado) => {
    if (estado.sincronizando) return;

    /* Mientras se escribe no se toca la pantalla: perder una nota a
       medias por un repintado es inaceptable. */
    const a = document.activeElement;
    if (a && (a.tagName === 'TEXTAREA' || a.isContentEditable
              || (a.tagName === 'INPUT' && !['checkbox', 'radio', 'button'].includes(a.type)))) {
      return;
    }
    const fresco = await db.leer('tickets', id);

    /* Si lo guardado difiere de lo que hay en pantalla, se repinta.
     *
     * Antes sólo se miraba la marca de «pendiente de envío», y además se
     * repintaba el cuerpo pero NO la cabecera: cambiabas el estado, el
     * servidor lo aceptaba y arriba seguía poniendo el estado viejo hasta
     * recargar con F5. La cabecera es justo donde se lee el estado. */
    const cambiado = fresco && (
      fresco.id_estado !== ticket?.id_estado
      || fresco.nombre_estado !== ticket?.nombre_estado
      || fresco.id_persona_asignada !== ticket?.id_persona_asignada
      || fresco.id_grupo_asignado !== ticket?.id_grupo_asignado
      || !!fresco.pendiente_envio !== !!ticket?.pendiente_envio
      || fresco.updated_at !== ticket?.updated_at);

    if (cambiado) {
      ticket = fresco;
      historial = await db.porIndice('historial', 'ticket', id);

      /* Y los datos propios del tipo de avería: los calcula el servidor
         —el «26 °C → 21 °C» sale de comparar con lo que había—, así que
         hay que volver a pedírselos cuando el envío ha salido. */
      if (hayRed() && id > 0) {
        try {
          const r = await api.ticket(id);
          camposPropios = r.campos || camposPropios;
          historial = r.historial || historial;
        } catch { /* se verá al refrescar */ }
      }
      pintarCabecera(cabeceraVolver(ticket.codigo, ticket.nombre_estado, accionesCabecera()));
      pintar();
    } else if (id < 0) {
      // El provisional se ha sustituido por el definitivo: se va a él.
      const reales = ticket.uuid ? await db.porIndice('tickets', 'uuid', ticket.uuid) : [];
      const real = reales.find((t) => t.id_ticket > 0);
      if (real) ir(`/ticket/${real.id_ticket}`, { reemplazar: true });
    }
  });

  return {
    limpiar() { if (typeof dejarDeEscuchar === 'function') dejarDeEscuchar(); },
  };
}

function nombreDe(u) {
  return [u.nombre, u.apellido1].filter(Boolean).join(' ') || u.correo;
}

/** Hoja para escribir una nota y, si procede, el tiempo dedicado. */
export function pedirNota({ titulo, etiqueta, obligatorio = false, pedirTiempo = false }) {
  return new Promise((resolve) => {
    let resuelto = false;
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo">
        <label for="nota">${esc(etiqueta)}${obligatorio ? '' : ' (opcional)'}</label>
        <textarea class="area" id="nota" placeholder="Describe brevemente lo que has hecho o lo que falta"></textarea>
        <div class="campo__error oculto" id="err-nota">Escribe algo antes de guardar.</div>
      </div>
      ${pedirTiempo ? `
      <div class="campo">
        <label>Tiempo dedicado</label>
        <div class="opciones" id="tiempo">
          ${[0, 15, 30, 60, 120].map((m) => `
            <button type="button" class="opcion" data-min="${m}" aria-pressed="${m === 0}">
              ${m === 0 ? 'Sin anotar' : minutosATexto(m)}
            </button>`).join('')}
        </div>
      </div>` : ''}`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `
      <button type="button" class="btn btn--contorno" data-no>Cancelar</button>
      <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo, cuerpo, pie,
      alAbrir: (h) => {
        const area = $('#nota', h);
        setTimeout(() => area.focus(), 260);

        let minutos = 0;
        const grupo = $('#tiempo', h);
        if (grupo) {
          grupo.onclick = (e) => {
            const b = e.target.closest('[data-min]');
            if (!b) return;
            $$('[data-min]', grupo).forEach((x) => x.setAttribute('aria-pressed', 'false'));
            b.setAttribute('aria-pressed', 'true');
            minutos = parseInt(b.dataset.min, 10);
          };
        }

        $('[data-no]', h).onclick = () => { resuelto = true; resolve(null); cerrarHoja(); };
        $('[data-si]', h).onclick = () => {
          const nota = area.value.trim();
          if (obligatorio && !nota) {
            $('#err-nota', h).classList.remove('oculto');
            area.focus();
            return;
          }
          resuelto = true;
          resolve({ nota, minutos });
          cerrarHoja();
        };
      },
      alCerrar: () => { if (!resuelto) resolve(null); },
    });
  });
}
