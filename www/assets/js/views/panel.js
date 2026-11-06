/* Pantalla de inicio: qué hay pendiente y qué hago ahora. */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import { $, $$, icono, esc, haceCuanto, minutosATexto, fechaLarga } from '../ui.js';
import { tarjetaParte, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, cabeceraPrincipal, ir, app, modulo, ajuste } from '../app.js';
import { accionRapida } from './acciones.js';
import { elegirPanelArea } from './familia.js';
import { bloqueVisible, bloqueAncho, bloquesOrdenados } from '../apariencia.js';

/**
 * Los mensajes de esta persona: bienvenida, aviso para hoy y frase del
 * día. Arriba del todo y con su aspa, porque son para leerlos y quitarlos
 * de en medio, no para que estorben cada día.
 *
 * Si no hay conexión o no hay nada que decir, no se pinta nada: un hueco
 * vacío en la portada es peor que no tener la función.
 */
async function pintarMensajes(caja) {
  if (!caja || !hayRed()) return;
  let lista = [];
  try { lista = (await api.misMensajes()).mensajes || []; } catch { return; }
  if (!lista.length) return;

  const clase = { bienvenida: 'msj--bienvenida', proximo: 'msj--aviso', frase: 'msj--frase' };
  caja.innerHTML = lista.map((m) => `
    <div class="msj ${clase[m.tipo] || ''}" data-msj="${esc(m.tipo)}"
         data-frase="${esc(m.id_frase || '')}">
      <div class="msj__cuerpo">
        <div class="msj__titulo">${esc(m.titulo)}</div>
        <div class="msj__texto">${esc(m.texto)}</div>
        ${m.autor ? `<div class="msj__autor">${esc(m.autor)}</div>` : ''}
      </div>
      <button type="button" class="msj__cerrar" data-cerrar-msj aria-label="Cerrar el mensaje">${icono('cerrar')}</button>
    </div>`).join('');

  caja.onclick = async (e) => {
    const b = e.target.closest('[data-cerrar-msj]');
    if (!b) return;
    const bloque = b.closest('[data-msj]');
    bloque.remove();
    try {
      await api.mensajeVisto({ tipo: bloque.dataset.msj,
                               id_frase: bloque.dataset.frase || null });
    } catch { /* si falla, se volverá a enseñar: mejor eso que perderlo */ }
  };
}

export async function vistaPanel({ contenido }) {
  const nombre = (app.usuario?.nombre || '').split(' ')[0] || '';
  pintarCabecera(cabeceraPrincipal(saludo() + (nombre ? ', ' + nombre : ''), app.catalogo?.organizacion || ''));

  contenido.innerHTML = `
    <div id="mensajes-persona"></div>
    <div class="rejilla-cifras" id="cifras">${bloqueCifras()}</div>
    <div id="atajos"></div>
    <div id="resto"></div>`;

  pintarAtajos($('#atajos', contenido));

  pintarMensajes($('#mensajes-persona', contenido));

  // El perfil se recuerda entre sesiones: sin cobertura se pinta el que
  // corresponde en vez de caer siempre en el de técnico.
  let perfil = localStorage.getItem('hurakantick_perfil') || 'tecnico';

  /* «sin_programar» deja fuera lo que está aparcado para más adelante.
     Un parte que no se puede atender hasta el día 30 no es «lo más
     urgente» de hoy: ocupa sitio y confunde. */
  const filtroReciente = (pf) => pf === 'reporta'
    ? { creados_por_mi: true, yo: app.usuario.id_login, abiertos: true,
        sin_programar: true, orden: 'reciente' }
    : { abiertos: true, sin_programar: true, orden: 'prioridad' };

  // 1. Se pinta al momento con lo que hay guardado en el móvil.
  const local = await db.resumenLocal(app.usuario.id_login);
  pintarCifras($('#cifras'), local, perfil, {});
  const locales = await db.ticketsLocales(filtroReciente(perfil));
  pintarResto($('#resto'), null, locales.slice(0, 5), perfil, {});

  // 2. Y se actualiza con el servidor si hay cobertura.
  if (hayRed()) {
    try {
      const datos = await api.panel();
      app.noLeidas = datos.no_leidas || 0;
      if (datos.perfil) {
        perfil = datos.perfil;
        localStorage.setItem('hurakantick_perfil', perfil);
      }
      pintarCabecera(cabeceraPrincipal(saludo() + (nombre ? ', ' + nombre : ''), app.catalogo?.organizacion || ''));
      pintarCifras($('#cifras'), datos.resumen, perfil, datos.extra || {});
      const recientes = await db.ticketsLocales(filtroReciente(perfil));
      pintarResto($('#resto'), datos, recientes.slice(0, 5), perfil, datos.extra || {});
    } catch (e) {
      if (!e.sinRed) console.warn(e);
    }
  }

  /* Un mensaje sin leer lleva directo a la conversación, no al parte:

     lo que hay que hacer es leerlo y contestar. */


  contenido.onclick = async (e) => {
    const verProg = e.target.closest('[data-ver-programados]');
    if (verProg) { ir('/lista?programados=1'); return; }

    const chatDe = e.target.closest('[data-chat-de]');
    if (chatDe) { ir('/ticket/' + chatDe.dataset.chatDe + '/chat'); return; }

    const r = e.target.closest('[data-rapida]');
    if (r) {
      e.stopPropagation();
      const locales = await db.ticketsLocales({});
      const t = locales.find((x) => x.id_ticket === parseInt(r.dataset.rapida, 10));
      if (t && await accionRapida(t)) app.refrescar();
      return;
    }
    if (e.target.closest('[data-ir-gestion]')) { ir('/gestion-partes'); return; }

    const area = e.target.closest('[data-area]');
    if (area) { ir(`/familia/${area.dataset.area}`); return; }
    if (e.target.closest('[data-mas-areas]')) { elegirPanelArea(); return; }

    const lug = e.target.closest('[data-lugar]');
    if (lug) { ir(`/ubicacion/${lug.dataset.lugar}`); return; }
    if (e.target.closest('[data-accion="nuevo"]')) { ir('/nuevo'); return; }

    const at = e.target.closest('[data-atajo]');
    if (at) {
      app.filtros = JSON.parse(at.dataset.atajo);
      app.filtrosPedidos = true;
      ir('/lista');
      return;
    }
    const c = e.target.closest('[data-filtro]');
    if (c) {
      app.filtros = JSON.parse(c.dataset.filtro);
      app.filtrosPedidos = true;
      ir('/lista');
      return;
    }
    const t = e.target.closest('[data-ticket]');
    if (t) ir(`/ticket/${t.dataset.ticket}`);
  };

  return {};
}

/**
 * Atajos: filtros guardados con nombre. Los define el administrador para
 * todos, y cada cual puede tener los suyos. Van justo debajo de las
 * cifras porque son el camino más corto a lo que se consulta a diario.
 */
function pintarAtajos(caja) {
  if (caja && !bloqueVisible('atajos')) { caja.innerHTML = ''; return; }
  if (!caja || !modulo('atajos')) return;
  const lista = app.catalogo?.atajos || [];
  if (!lista.length) return;

  caja.innerHTML = `
    <div class="seccion"><h2>Accesos rápidos</h2></div>
    <div class="carril" style="padding-bottom:4px">
      ${lista.map((a) => `
        <button type="button" class="pastilla" data-atajo='${esc(JSON.stringify(a.filtro))}'
                style="border-color:${esc(a.color)};color:${esc(a.color)}">
          ${icono(a.icono)}${esc(a.nombre)}
        </button>`).join('')}
    </div>`;
}

/**
 * Estados agrupados por lo que significan, no por su nombre.
 *
 *   final  → Resuelta y Cerrado, que para quien mira son lo mismo
 *   espera → Programado, Pte. Terceros y Pte. Usuario: están vivos pero
 *            no se puede hacer nada hasta que alguien de fuera responda
 */
function estadosPorClase(clase) {
  const todos = app.catalogo?.estados || [];
  if (clase === 'final') {
    return todos.filter((e) => e.es_final && !/cancel|elimin/i.test(e.nombre_estado))
                .map((e) => e.id_estado);
  }
  return todos.filter((e) => !e.es_final && /pte|pendiente de|programad|espera/i.test(e.nombre_estado))
              .map((e) => e.id_estado);
}

function saludo() {
  const h = new Date().getHours();
  if (h < 6) return 'Buenas noches';
  if (h < 13) return 'Buenos días';
  if (h < 21) return 'Buenas tardes';
  return 'Buenas noches';
}

function bloqueCifras() {
  return Array.from({ length: 4 }, () => '<div class="hueso" style="height:78px"></div>').join('');
}

/**
 * Las cifras del inicio cambian según a qué se dedica cada uno.
 *
 * Recepción no repara: lo suyo es haber avisado y que se resuelva. Un
 * técnico quiere su cola. Un responsable quiere el estado del hotel.
 * Enseñarles a los tres lo mismo obliga a los tres a filtrar mentalmente.
 */
function pintarCifras(caja, r, perfil, extra) {
  if (!caja || !r) return;
  const cifra = (valor, nombre, filtro, color, alerta = false) => `
    <button type="button" class="cifra ${alerta && valor > 0 ? 'cifra--alerta' : ''}" data-filtro='${esc(JSON.stringify(filtro))}'>
      ${color ? `<span class="cifra__marca" style="background:${color}"></span>` : ''}
      <div class="cifra__valor">${valor}</div>
      <div class="cifra__nombre">${nombre}</div>
    </button>`;

  const e = extra || {};

  if (perfil === 'reporta') {
    caja.innerHTML =
      cifra(e.mios_abiertos ?? 0, 'Avisos míos abiertos',
            { creados_por_mi: true, abiertos: true, orden: 'reciente' }, 'var(--marca)') +
      cifra(e.mios_cerrados_semana ?? 0, 'Resueltos esta semana',
            { creados_por_mi: true, cerrados_semana: true }, 'var(--ok)');
    return;
  }

  if (perfil === 'limpieza') {
    caja.innerHTML =
      cifra(r.mios ?? 0, 'Asignados a mí', { mios: true, abiertos: true, orden: 'prioridad' }, 'var(--marca)') +
      cifra(e.cola_grupo ?? 0, 'Cola de mi grupo',
            { mi_grupo: true, sin_asignar: true, abiertos: true }, 'var(--senal)') +
      cifra(e.mios_abiertos ?? 0, 'Avisos míos',
            { creados_por_mi: true, abiertos: true }, 'var(--tinta-3)') +
      cifra(r.vencidos ?? 0, 'Fuera de plazo',
            { vencidos: true, abiertos: true, orden: 'limite' }, 'var(--peligro)', true);
    return;
  }

  if (perfil === 'tecnico') {
    caja.innerHTML =
      cifra(r.mios ?? 0, 'Asignados a mí', { mios: true, abiertos: true, orden: 'prioridad' }, 'var(--marca)') +
      cifra(e.cola_grupo ?? 0, 'Cola de mi grupo',
            { mi_grupo: true, sin_asignar: true, abiertos: true }, 'var(--senal)') +
      cifra(r.vencidos ?? 0, 'Fuera de plazo',
            { vencidos: true, abiertos: true, orden: 'limite' }, 'var(--peligro)', true) +
      cifra(r.urgentes ?? 0, 'Urgentes',
            { abiertos: true, prioridad: [4], orden: 'limite' }, 'var(--peligro)') +
      cifra(e.resueltos ?? 0, 'Resueltos o cerrados',
            { estado: estadosPorClase('final'), orden: 'reciente' }, 'var(--ok)') +
      cifra(e.en_espera ?? 0, 'En espera',
            { estado: estadosPorClase('espera'), orden: 'reciente' }, 'var(--senal)');
    return;
  }

  // Responsable: el estado del hotel
  caja.innerHTML =
    cifra(r.mios ?? 0, 'Asignados a mí', { mios: true, abiertos: true, orden: 'prioridad' }, 'var(--marca)') +
    cifra(r.sin_asignar ?? 0, 'Sin asignar', { sin_asignar: true, abiertos: true, orden: 'reciente' }, 'var(--senal)') +
    cifra(r.vencidos ?? 0, 'Fuera de plazo', { vencidos: true, abiertos: true, orden: 'limite' }, 'var(--peligro)', true) +
    cifra(r.abiertos ?? 0, 'Abiertos', { abiertos: true, orden: 'prioridad' }, 'var(--tinta-3)') +
    cifra(e.resueltos ?? 0, 'Resueltos o cerrados',
          { estado: estadosPorClase('final'), orden: 'reciente' }, 'var(--ok)') +
    cifra(e.en_espera ?? 0, 'En espera',
          { estado: estadosPorClase('espera'), orden: 'reciente' }, 'var(--senal)');
}

function pintarResto(caja, datos, recientes, perfil = 'tecnico', extra = {}) {
  if (!caja) return;

  // Accesos directos a los paneles de área. El del aire acondicionado
  // es el que más se usa —es lo que más partes genera en un hotel— pero
  // el mecanismo sirve para cualquiera; el administrador elige cuáles
  // salen aquí.
  const preferidas = ajuste('paneles_area', []) || [];
  const familias = app.catalogo?.familias || [];
  const paneles = preferidas.length
    ? preferidas.map((id) => familias.find((f) => f.id_familia === id)).filter(Boolean)
    : familias.filter((f) => /climatiz|aire/i.test(f.nombre)).slice(0, 1);

  const reparto = datos?.por_estado?.filter((e) => e.n > 0) || [];
  const maximo = Math.max(1, ...reparto.map((e) => e.n));

  const serie = datos?.serie || [];
  const techo = Math.max(1, ...serie.map((d) => Math.max(d.creados, d.cerrados)));

  const lugares = extra.lugares_pendientes || [];

  /* Cada bloque se pinta en el orden y con el tamaño que haya elegido
     esta persona, y sólo si lo tiene encendido. Los que no le tocan por
     su puesto siguen sin salir aunque estén encendidos: la apariencia
     manda sobre el gusto, no sobre los permisos. */
  /* Cada bloque se guarda por separado y se monta al final en el orden
     y con el tamaño que haya elegido esta persona.

     La apariencia manda sobre el gusto, no sobre los permisos: un bloque
     que no le toca por su puesto sigue sin salir aunque lo tenga
     encendido, y por eso cada trozo conserva su propia condición. */
  const t = {};

  /* Mensajes sin leer.
   *
   * Es el bloque que hace que esto sirva de algo: quien abre un parte no
   * vuelve a mirarlo —recepción abre veinte al día—, así que si el
   * técnico contesta «la caja funciona, el cliente no sabía usarla» y
   * nadie lo lee, el cliente se queja otra vez. Va el primero y en rojo
   * cuando hay algo importante. */
  /* Lo que viene programado.
   *
   * Un técnico necesita ver lo que le cae esta semana para organizarse, y
   * sobre todo lo que está a punto de llegar: si mañana toca la 801,
   * mejor saberlo hoy. */
  const prog = datos?.programados || null;
  t.programados = (prog && prog.total > 0) ? `
      <div class="seccion"><h2>Programado</h2>
        <button type="button" class="seccion__enlace" data-ver-programados>Ver todos</button></div>
      ${prog.cercanos > 0 ? `
        <div class="aviso aviso--info" style="margin-bottom:8px">${icono('reloj')}
          <div><b>${prog.cercanos} parte${prog.cercanos === 1 ? '' : 's'}</b>
            ${prog.cercanos === 1 ? 'vuelve' : 'vuelven'} a la cola en los próximos
            ${prog.dias} días</div></div>` : `
        <div class="aviso aviso--info" style="margin-bottom:8px">${icono('reloj')}
          <div>${prog.total} parte${prog.total === 1 ? '' : 's'} programado${
            prog.total === 1 ? '' : 's'} para más adelante</div></div>`}
      <div class="filas">
        ${(prog.partes || []).map((x) => `
          <button type="button" class="fila" data-abrir="${x.id_ticket}">
            ${icono('reloj')}
            <span class="fila__texto">${esc(x.titulo || '')}
              <small>${esc(x.codigo)}${x.ruta ? ' · ' + esc(x.ruta) : ''}</small>
              <small style="opacity:.8">Vuelve el ${esc(fechaLarga(x.cuando))}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>` : '';

  const ch = datos?.chat || {};
  t.chat = (ch.total > 0) ? `
      <div class="seccion"><h2>Mensajes sin leer</h2>
        <span class="rotulo">${ch.total}</span></div>
      ${ch.importantes > 0 ? `
        <div class="aviso aviso--error" style="margin-bottom:8px">${icono('aviso')}
          <div><b>${ch.importantes} mensaje${ch.importantes === 1 ? '' : 's'} importante${
            ch.importantes === 1 ? '' : 's'} sin leer</b></div></div>` : ''}
      <div class="filas">
        ${(ch.mensajes || []).map((m) => `
          <button type="button" class="fila" data-chat-de="${m.id_ticket}">
            ${icono('chat')}
            <span class="fila__texto">${esc(m.autor || 'Alguien')}${
              m.importante ? ' · importante' : ''}
              <small>${esc(m.texto)}</small>
              <small style="opacity:.7">${esc(m.codigo)} · ${esc(m.titulo_ticket || '')}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>` : '';


  t.areas = paneles.length ? `
      <div class="seccion"><h2>Paneles de área</h2>
        <button type="button" class="seccion__enlace" data-mas-areas>Ver todas</button></div>
      <div class="carril" style="padding-bottom:8px">
        ${paneles.map((f) => `
          <button type="button" class="pastilla" data-area="${f.id_familia}"
                  style="border-color:${esc(f.color)}55;color:${esc(f.color)}">
            ${icono(f.icono)}${esc(f.nombre)}
          </button>`).join('')}
      </div>` : '';

  t.avisar = perfil === 'reporta' ? `
      <button type="button" class="btn btn--principal btn--bloque" data-accion="nuevo" style="margin:6px 0">
        ${icono('mas')}Avisar de algo
      </button>
      <div class="campo__ayuda" style="text-align:center">
        Elige el área, la habitación y ya está.
      </div>` : '';

  t.lugares = lugares.length ? `
      <div class="seccion"><h2>Habitaciones con algo pendiente</h2>
        <span class="rotulo">${lugares.length}</span></div>
      <div class="campo__ayuda" style="margin:-4px 2px 8px">
        Antes de entregarlas, comprueba que no queda nada vivo.
      </div>
      <div class="carril" style="padding-bottom:6px">
        ${lugares.map((l) => `
          <button type="button" class="pastilla" data-lugar="${l.id_rel}"
                  style="${l.urgente ? 'border-color:var(--peligro);color:var(--peligro)' : ''}">
            ${icono('ubicacion')}${esc(l.lugar)}
            <span class="pastilla__n">${l.n}</span>
          </button>`).join('')}
      </div>` : '';

  t.urgente = recientes.length ? `
      <div class="seccion">
        <h2>${perfil === 'reporta' ? 'Lo que has avisado' : 'Lo más urgente'}</h2>
        <button type="button" class="seccion__enlace" data-filtro='${perfil === 'reporta'
          ? '{"creados_por_mi":true,"abiertos":true,"orden":"reciente"}'
          : '{"abiertos":true,"orden":"prioridad"}'}'>Ver todos</button>
      </div>
      ${recientes.map((x) => tarjetaParte(x, { rapida: perfil !== 'reporta' })).join('')}`
    : estadoVacio({
        icono: 'ok',
        titulo: perfil === 'reporta' ? 'No tienes avisos abiertos' : 'No hay partes abiertos',
        texto: perfil === 'reporta'
          ? 'Todo lo que has avisado está resuelto.'
          : 'Cuando entre uno nuevo aparecerá aquí.',
      });

  t.reparto = reparto.length ? `
      <div class="seccion"><h2>Reparto por estado</h2></div>
      <div class="tarjeta"><div class="tarjeta__cuerpo">
        <div class="reparto">
          ${reparto.map((e) => `
            <div class="reparto__fila">
              <span class="reparto__nombre">${esc(e.nombre_estado)}</span>
              <span class="reparto__pista"><span class="reparto__barra"
                    style="width:${Math.round(e.n / maximo * 100)}%;background:${esc(e.color)}"></span></span>
              <span class="reparto__n">${e.n}</span>
            </div>`).join('')}
        </div>
      </div></div>` : '';

  t.actividad = serie.length ? `
      <div class="seccion"><h2>Últimas dos semanas</h2></div>
      <div class="tarjeta"><div class="tarjeta__cuerpo">
        <div class="actividad">
          ${serie.map((d) => `
            <div class="actividad__dia" title="${esc(d.dia)}: ${d.creados} nuevos, ${d.cerrados} cerrados">
              <span class="actividad__creados" style="height:${Math.round(d.creados / techo * 60)}%"></span>
              <span class="actividad__cerrados" style="height:${Math.round(d.cerrados / techo * 60)}%"></span>
            </div>`).join('')}
        </div>
        <div class="leyenda">
          <span><i style="background:var(--marca)"></i>Nuevos</span>
          <span><i style="background:color-mix(in srgb, var(--ok) 65%, transparent)"></i>Cerrados</span>
          ${datos?.resolucion?.media_horas != null
            ? `<span style="margin-left:auto">Media de cierre: <strong>${minutosATexto(Math.round(datos.resolucion.media_horas * 60))}</strong></span>`
            : ''}
        </div>
      </div></div>` : '';

  t.carga = datos?.carga?.length ? `
      <div class="seccion"><h2>Carga del equipo</h2></div>
      <div class="tarjeta"><div class="tarjeta__cuerpo">
        <div class="reparto">
          ${datos.carga.map((c) => `
            <div class="reparto__fila">
              <span class="reparto__nombre" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.nombre_completo)}</span>
              <span class="reparto__pista"><span class="reparto__barra"
                    style="width:${Math.round(c.n / Math.max(1, ...datos.carga.map((x) => x.n)) * 100)}%;background:var(--marca)"></span></span>
              <span class="reparto__n">${c.n}</span>
            </div>`).join('')}
        </div>
      </div></div>` : '';

  // Los bloques a media pantalla se emparejan de dos en dos; los de
  // pantalla entera van solos. Un bloque vacío no ocupa hueco.
  const piezas = bloquesOrdenados()
    .filter((b) => b.id !== 'cifras' && b.id !== 'atajos')
    .map((b) => ({ ...b, html: t[b.id] || '' }))
    .filter((b) => b.html.trim());

  let salida = '';
  let i = 0;
  while (i < piezas.length) {
    const a = piezas[i];
    const b = piezas[i + 1];
    if (a.ancho === 1 && b && b.ancho === 1) {
      salida += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:start">
                   <div>${a.html}</div><div>${b.html}</div></div>`;
      i += 2;
    } else {
      salida += a.html;
      i += 1;
    }
  }

  /* Gestión de partes al final del inicio, para quien puede usarla.
     Es lo que se busca cuando hay que limpiar, y tenerlo sólo dentro de
     Ajustes obligaba a acordarse de dónde estaba. */
  const gestion = (app.usuario?.es_responsable || app.usuario?.es_admin) ? `
      <div class="seccion"><h2>Gestión</h2></div>
      <button type="button" class="fila" data-ir-gestion
              style="border-radius:var(--r-m);border:1.5px solid var(--borde);margin-bottom:6px">
        ${icono('papelera')}
        <span class="fila__texto">Gestión de partes
          <small>Buscar, mover y eliminar en bloque</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>` : '';

  caja.innerHTML = salida + gestion + (!hayRed()
    ? `<div class="aviso aviso--senal" style="margin-top:16px">${icono('sinred')}
        <div>Estás sin conexión. Los datos son los de la última sincronización.</div></div>`
    : '');
}
