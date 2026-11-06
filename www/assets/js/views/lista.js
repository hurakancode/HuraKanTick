/* Listado de partes: buscar, filtrar y abrir. */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import { $, $$, icono, esc, mensaje, elegir, descargarFichero, aCsv, fechaLarga, confirmar, elegirBuscando, enfocarBuscador } from '../ui.js';
import { tarjetaParte, estadoVacio, bloqueCarga, cabeceraVolver } from '../piezas.js';
import { accionRapida } from './acciones.js';
import { pintarCabecera, cabeceraPrincipal, ir, app, rutaActual, modulo } from '../app.js';
import * as sync from '../sync.js';
import { tono } from '../sonido.js';

/* Selección múltiple.
   Vive fuera de la vista a propósito: una sincronización de fondo la
   repinta, y perder lo que llevabas marcado por eso es de las cosas que
   más irritan de una aplicación. */
const marcados = new Set();
let seleccionando = false;

export async function vistaLista({ contenido }) {
  const soloMios = rutaActual() === '/mios';

  /* Los filtros de fábrica de cada pantalla.

     «Partes» arranca limpio siempre que se entra desde la barra de
     abajo: si conservara lo de la última vez, entrar por ahí daría un
     resultado distinto cada día sin que se vea por qué. Lo que sí se
     conserva es lo que viene de una cifra del inicio o de un atajo,
     porque ahí el filtro es justo lo que se ha pedido. */
  const deFabrica = soloMios
    ? { mios: true, abiertos: true, orden: 'prioridad' }
    : { abiertos: true, orden: 'prioridad' };

  /* ¿Se viene del aviso de «Programado» del inicio? Entonces la pantalla
     arranca enseñando justo eso: lo aparcado para más adelante, con los
     próximos tres días por delante, que es lo que sirve para organizarse. */
  const pedido = new URLSearchParams((location.hash.split('?')[1] || ''));
  const vieneProgramados = pedido.get('programados') === '1';

  const filtros = vieneProgramados
    ? { programados: true, dias: parseInt(pedido.get('dias'), 10) || 3, orden: 'reciente' }
    : (soloMios || !app.filtrosPedidos)
      ? { ...deFabrica }
      : { ...app.filtros };

  // Se consume: la próxima vez que se entre por la barra, limpio.
  app.filtrosPedidos = false;

  // Con botón de volver: a esta pantalla se llega desde muchos sitios
  // —atajos, cifras del inicio, informes— y quedarse sin salida obliga a
  // dar un rodeo por la barra de abajo.
  pintarCabecera(
    `<button type="button" class="icono-btn" data-volver aria-label="Volver">${icono('atras')}</button>
     <div class="cabecera__titulo">
       <h1>${soloMios ? 'Míos' : 'Partes'}</h1>
       ${soloMios ? '<small>Asignados a mí y abiertos por mí</small>' : ''}
     </div>
     <button type="button" class="icono-btn" data-seleccionar aria-label="Elegir varios">${icono('ok')}</button>
     ${modulo('exportar') ? `<button type="button" class="icono-btn" data-exportar aria-label="Exportar el listado">${icono('descarga')}</button>` : ''}
     <button type="button" class="icono-btn" data-sync aria-label="Sincronizar ahora">${icono('sync')}</button>`
  );

  contenido.innerHTML = `
    <div class="buscador">
      ${icono('buscar')}
      <input class="entrada" id="q" type="search" placeholder="Buscar por texto, ubicación o código"
             inputmode="search" autocomplete="off" value="${esc(filtros.q || '')}">
      <button type="button" class="buscador__limpiar oculto" id="limpiar" aria-label="Limpiar">${icono('cerrar')}</button>
    </div>

    ${filtros.programados ? `
      <div class="aviso aviso--info" style="margin:4px 0 10px">${icono('reloj')}
        <div>Partes aparcados que vuelven a la cola más adelante.</div></div>
      <div class="opciones" id="dias-prog" style="margin-bottom:10px">
        ${[3, 7, 15, 30, 0].map((d) => `
          <button type="button" class="opcion ${(filtros.dias || 0) === d ? 'opcion--activa' : ''}"
                  data-dias="${d}">${d ? d + ' días' : 'Todos'}</button>`).join('')}
      </div>` : ''}

    ${filtros.programados ? '' : '<div class="carril" id="carril"></div>'}
    <div id="resultado">${bloqueCarga(5)}</div>`;

  const caja = $('#resultado', contenido);


  /* El rango de días de lo programado. Tres días por defecto, pero

     mirar la semana o el mes entero es un toque. */

  const zonaDias = $('#dias-prog', contenido);

  if (zonaDias) {

    zonaDias.onclick = (e) => {

      const b = e.target.closest('[data-dias]');

      if (!b) return;

      $$('[data-dias]', zonaDias).forEach((x) => x.classList.remove('opcion--activa'));

      b.classList.add('opcion--activa');

      filtros.dias = parseInt(b.dataset.dias, 10) || null;

      cargar();

    };

  }
  const entrada = $('#q', contenido);
  enfocarBuscador($('#q', contenido));
  const limpiar = $('#limpiar', contenido);

  /**
   * ¿Hay algún filtro puesto?
   *
   * El orden no cuenta: siempre hay uno y quitarlo no significa nada.
   * «Abiertos» tampoco, porque es lo que trae la pantalla de fábrica.
   */
  function hayFiltros() {
    return !!(filtros.q || filtros.mios || filtros.mi_grupo || filtros.sin_asignar
      || filtros.vencidos || filtros.estado?.length || filtros.familia
      || filtros.clase?.length || filtros.creados_por_mi || filtros.prioridad?.length
      || filtros.zona || filtros.abiertos === false);
  }

  function entrarSeleccion() {
    seleccionando = true;
    marcados.clear();
    pintar(ultimaLista);
  }

  function salirSeleccion() {
    seleccionando = false;
    marcados.clear();
    pintar(ultimaLista);
  }

  /** Barra flotante con lo que se puede hacer con lo marcado. */
  function pintarBarraSeleccion() {
    let barra = document.getElementById('barra-seleccion');
    if (!seleccionando) { barra?.remove(); return; }

    if (!barra) {
      barra = document.createElement('div');
      barra.id = 'barra-seleccion';
      barra.className = 'barra-seleccion';
      document.body.appendChild(barra);
    }

    const n = marcados.size;
    const puedeBorrar = app.usuario.es_admin;

    barra.innerHTML = `
      <button type="button" class="icono-btn" data-sel-cancelar aria-label="Salir">${icono('cerrar')}</button>
      <strong>${n ? `${n} seleccionado${n > 1 ? 's' : ''}` : 'Toca los partes'}</strong>
      <button type="button" class="btn btn--contorno btn--s" data-sel-todos>
        ${marcados.size === ultimaLista.length && ultimaLista.length ? 'Ninguno' : 'Todos'}</button>
      ${puedeBorrar ? `<button type="button" class="btn btn--peligro btn--s" data-sel-borrar ${n ? '' : 'disabled'}>
        ${icono('papelera')}Eliminar</button>` : ''}
      <button type="button" class="btn btn--principal btn--s" data-sel-accion ${n ? '' : 'disabled'}>Acciones</button>`;

    barra.querySelector('[data-sel-cancelar]').onclick = salirSeleccion;
    barra.querySelector('[data-sel-todos]').onclick = () => {
      if (marcados.size === ultimaLista.length) marcados.clear();
      else ultimaLista.forEach((t) => marcados.add(t.id_ticket));
      pintar(ultimaLista);
    };
    const bb = barra.querySelector('[data-sel-borrar]');
    if (bb) bb.onclick = borrarLote;
    barra.querySelector('[data-sel-accion]').onclick = accionesLote;
  }

  async function borrarLote() {
    const lista = ultimaLista.filter((t) => marcados.has(t.id_ticket));
    const ok = await confirmar({
      titulo: `Eliminar ${lista.length} ${lista.length === 1 ? 'parte' : 'partes'}`,
      texto: 'Se borran de la base de datos con su historial, sus notas y sus fotos. '
           + 'No van a la papelera: desaparecen. Esto no se puede deshacer.',
      aceptar: 'Eliminar definitivamente',
      peligro: true,
    });
    if (!ok) return;
    try {
      const r = await api.loteTickets({ ids: [...marcados], accion: 'eliminar' });
      for (const idT of marcados) { await db.borrar('tickets', idT); }
      salirSeleccion();
      tono('ok');
      mensaje(r.mensaje, 'ok');
      cargar();
    } catch (e) { mensaje(e.message, 'error'); }
  }

  async function accionesLote() {
    const v = await elegir({
      titulo: `${marcados.size} partes`,
      opciones: [
        { valor: 'estado', texto: 'Cambiar el estado', icono: 'banderin' },
        { valor: 'asignar', texto: 'Asignar a alguien', icono: 'personas' },
        ...(app.usuario?.es_admin
          ? [{ valor: 'borrar', texto: 'Ocultar de las listas', icono: 'ojo-no' }] : []),
      ],
    });
    if (!v) return;

    if (v === 'borrar') {
      const ok = await confirmar({
        titulo: `Ocultar ${marcados.size} parte${marcados.size > 1 ? 's' : ''}`,
        texto: 'Dejan de verse en la aplicación y el historial se conserva. '
             + 'No van a la papelera: para eso hay que eliminarlos.',
        aceptar: 'Ocultar', peligro: true,
      });
      if (!ok) return;
      await lote({ accion: 'borrar' });
      return;
    }

    if (v === 'estado') {
      const est = await elegir({
        titulo: 'Nuevo estado',
        opciones: (app.catalogo?.estados || []).map((e) => ({
          valor: e.id_estado, texto: e.nombre_estado, color: e.color })),
      });
      if (est) await lote({ accion: 'estado', id_estado: est });
      return;
    }

    const gente = app.catalogo?.usuarios || [];
    const grupos = app.catalogo?.departamentos || [];
    const q = await elegirBuscando({
      titulo: 'Asignar a',
      opciones: [
        { valor: 'nadie', texto: 'Quitar la asignación', icono: 'persona', destacado: true },
        ...grupos.map((g) => ({ valor: 'g:' + g.id_departamento, texto: g.nombre,
                                detalle: 'Todo el grupo', icono: 'personas', destacado: true })),
        ...gente.map((x) => ({ valor: 'p:' + x.id_login, texto: x.nombre_completo, detalle: x.correo })),
      ],
      marcador: 'Buscar persona o grupo',
    });
    if (!q) return;
    const d = { accion: 'asignar', id_persona_asignada: null, id_grupo_asignado: null };
    if (q.startsWith('p:')) d.id_persona_asignada = parseInt(q.slice(2), 10);
    if (q.startsWith('g:')) d.id_grupo_asignado = parseInt(q.slice(2), 10);
    await lote(d);
  }

  async function lote(datos) {
    try {
      const r = await api.loteTickets({ ids: [...marcados], ...datos });
      salirSeleccion();
      tono('ok');
      mensaje(r.mensaje, 'ok');
      cargar();
    } catch (e) { mensaje(e.message, 'error'); }
  }

  function pintarCarril() {

    // En la lista de programados no hay carril: no se pinta nada.

    if (!$('#carril', contenido)) return;
    const p = (id, texto, activo, extra = '') =>
      `<button type="button" class="pastilla" data-f="${id}" aria-pressed="${activo}">${extra}${esc(texto)}</button>`;

    $('#carril', contenido).innerHTML = [
      p('abiertos', 'Abiertos', !!filtros.abiertos),

      /* En la pantalla «Míos» las dos primeras pastillas separan lo que
         la palabra mezcla: lo que me han dado para hacer y lo que yo
         avisé. Fuera de ella, «Míos» es una pastilla más. */
      soloMios ? p('mios', 'Asignados a mí', !!filtros.mios) : '',
      soloMios ? p('creados_por_mi', 'Abiertos por mí', !!filtros.creados_por_mi) : '',
      soloMios ? '' : p('mios', 'Míos', !!filtros.mios),

      p('mi_grupo', 'De mi grupo', !!filtros.mi_grupo),
      p('sin_asignar', 'Sin asignar', !!filtros.sin_asignar),
      p('vencidos', 'Fuera de plazo', !!filtros.vencidos, icono('aviso')),
      p('estado', filtros.estado?.length ? nombreEstados(filtros.estado) : 'Estado', !!filtros.estado?.length, icono('banderin')),
      p('familia', filtros.familia ? nombreFamilia(filtros.familia) : 'Área', !!filtros.familia, icono('llaveinglesa')),
      p('clase', filtros.clase?.length ? nombreClase(filtros.clase[0]) : 'Clase', !!filtros.clase?.length, icono('banderin')),

      /* La prioridad se podía filtrar —los atajos lo hacen— pero no se
         veía en ningún sitio: el listado enseñaba dos partes de veinte
         sin decir por qué, y no había manera de quitar ese filtro salvo
         a ciegas con «quitar filtros». */
      p('prioridad', filtros.prioridad?.length ? nombrePrioridad(filtros.prioridad[0]) : 'Prioridad',
        !!filtros.prioridad?.length, icono('aviso')),
      // «Orden:» delante: si no, ordenar por prioridad salía como una
      // segunda pastilla «Prioridad» al lado del filtro del mismo nombre.
      p('orden', 'Orden: ' + nombreOrden(filtros.orden), false, icono('filtro')),
      // Sólo aparece si hay algo que quitar: un botón que no hace nada
      // ocupa sitio y confunde.
      hayFiltros() ? `<button type="button" class="pastilla" data-f="limpiar-todo"
          style="border-color:var(--peligro)55;color:var(--peligro)">
          ${icono('cerrar')}Quitar filtros</button>` : '',
    ].join('');
  }

  function nombreEstados(ids) {
    if (ids.length === 1) return app.catalogo?.estados?.find((e) => e.id_estado === ids[0])?.nombre_estado || 'Estado';
    return `${ids.length} estados`;
  }
  function nombreFamilia(id) {
    return app.catalogo?.familias?.find((f) => f.id_familia === id)?.nombre || 'Área';
  }
  function nombrePrioridad(id) {
    const p = (app.catalogo?.prioridades || []).find((x) => x.id_prioridad === id);
    return p ? p.nombre : 'Prioridad';
  }

  function nombreClase(c) {
    return { averia: 'Averías', peticion: 'Peticiones', tarea: 'Tareas' }[c] || 'Clase';
  }
  function nombreOrden(o) {
    return { reciente: 'Más recientes', antiguo: 'Más antiguos', prioridad: 'Prioridad', limite: 'Plazo', estado: 'Estado' }[o || 'reciente'];
  }

  /* Cuántas tarjetas se pintan de una vez.
   *
   * El listado se traía todo lo que hubiera en el móvil y lo pintaba de
   * golpe. Con un hotel de verdad eso son miles de tarjetas: la pantalla
   * tarda segundos en aparecer y se gasta memoria en algo que nadie va a
   * bajar. Se pinta un tramo y se amplía a petición. */
  const TRAMO = 60;
  let aPintar = TRAMO;

  async function cargar() {
    aPintar = TRAMO;
    const f = { ...filtros, yo: app.usuario.id_login };

    // Siempre se pinta primero desde el móvil: respuesta inmediata.
    const locales = await db.ticketsLocales(f);
    pintar(locales, true);

    if (hayRed()) {
      try {
        const r = await api.tickets(aParametros(filtros));
        await db.ponerVarios('tickets', r.tickets);
        // Se conservan los partes que aún no han salido del móvil.
        const pendientes = (await db.ticketsLocales(f)).filter((t) => t.pendiente_envio);
        const ids = new Set(r.tickets.map((t) => t.id_ticket));
        pintar([...pendientes.filter((t) => !ids.has(t.id_ticket)), ...r.tickets], false, r.total);
      } catch (e) {
        if (!e.sinRed) mensaje(e.message, 'error');
      }
    }
  }

  let ultimaLista = [];
  let ultimoTotal = 0;

  function pintar(tickets, provisional, total) {
    ultimaLista = tickets;
    if (total !== undefined) ultimoTotal = total;
    if (!tickets.length && !provisional) {
      caja.innerHTML = estadoVacio({
        icono: filtros.q ? 'buscar' : 'lista',
        titulo: filtros.q ? 'Sin resultados' : 'No hay partes con estos filtros',
        texto: filtros.q
          ? `Nada coincide con «${filtros.q}». Prueba con otra palabra o quita filtros.`
          : 'Cambia los filtros de arriba o crea un parte nuevo.',
        accion: { id: 'nuevo', texto: 'Nuevo parte', icono: 'mas' },
      });
      return;
    }
    if (!tickets.length && provisional) { caja.innerHTML = bloqueCarga(4); return; }

    const opciones = {
      rapida: modulo('acciones_rapidas'),
      seleccionable: seleccionando,
    };

    caja.innerHTML = `
      <div class="rotulo" style="margin:2px 2px 8px">
        ${tickets.length} ${tickets.length === 1 ? 'parte' : 'partes'}${total && total > tickets.length ? ` de ${total}` : ''}

      </div>
      ${tickets.slice(0, aPintar).map((t) => tarjetaParte(t, {
        ...opciones, marcado: marcados.has(t.id_ticket),
      })).join('')}

      ${tickets.length > aPintar ? `
        <button type="button" class="btn btn--contorno btn--bloque" data-mas style="margin-top:12px">
          Ver ${Math.min(TRAMO, tickets.length - aPintar)} más
          <span class="rotulo" style="margin-left:6px">
            ${aPintar} de ${tickets.length}</span>
        </button>` : ''}`;

    const bMas = $('[data-mas]', contenido);
    if (bMas) bMas.onclick = () => {
      aPintar += TRAMO;
      pintar(ultimaLista, false, ultimoTotal);
    };

    pintarBarraSeleccion();
  }

  /* --- interacción ------------------------------------------------ */
  let reloj = null;
  entrada.oninput = () => {
    limpiar.classList.toggle('oculto', !entrada.value);
    clearTimeout(reloj);
    reloj = setTimeout(() => {
      filtros.q = entrada.value.trim();
      guardar();
      // Repintar el carril: al escribir aparece (o desaparece) el botón
      // de quitar filtros, y sin esto no se enteraba.
      pintarCarril();
      cargar();
    }, 260);
  };
  limpiar.classList.toggle('oculto', !entrada.value);
  limpiar.onclick = () => {
    entrada.value = '';
    filtros.q = '';
    limpiar.classList.add('oculto');
    guardar();
    pintarCarril();
    cargar();
  };

  // Sin carril —la lista de programados— no hay nada que escuchar.
  if ($('#carril', contenido)) $('#carril', contenido).onclick = async (e) => {
    const b = e.target.closest('[data-f]');
    if (!b) return;
    const f = b.dataset.f;

    /* En «Míos», las dos primeras pastillas son excluyentes.
       «Míos» significa dos cosas —lo que me han dado para hacer y lo que
       yo avisé— y verlas mezcladas es justo lo que impide entender qué
       se está mirando. */
    if (soloMios && (f === 'mios' || f === 'creados_por_mi')) {
      if (f === 'mios') {
        filtros.mios = true;
        delete filtros.creados_por_mi;
      } else {
        filtros.creados_por_mi = true;
        delete filtros.mios;
      }
      guardar();
      pintarCarril();
      cargar();
      return;
    }

    if (f === 'limpiar-todo') {
      // Se vacía el objeto en vez de reasignarlo: «filtros» es const y
      // se comparte por referencia con cargar(), guardar() y el
      // buscador. Cambiar la referencia dejaría a esos tres mirando el
      // objeto viejo.
      Object.keys(filtros).forEach((k) => delete filtros[k]);

      // Se vuelve a lo que trae la pantalla de fábrica, no a nada: un
      // listado con todos los partes de la historia no le sirve a nadie.
      Object.assign(filtros, deFabrica);

      app.filtros = { ...filtros };
      app.filtrosPedidos = true;
      const campoQ = $('#q', contenido);
      if (campoQ) campoQ.value = '';
      limpiar?.classList.add('oculto');
      guardar();
      pintarCarril();
      cargar();
      return;
    }

    if (f === 'prioridad') {
      const v = await elegir({
        titulo: 'Prioridad',
        opciones: [
          { valor: 0, texto: 'Cualquiera', icono: 'lista' },
          ...(app.catalogo?.prioridades || []).map((x) => ({
            valor: x.id_prioridad, texto: x.nombre, color: x.color })),
        ],
        valor: filtros.prioridad?.[0] ?? 0,
      });
      if (v === null) return;
      if (v) filtros.prioridad = [v];
      else delete filtros.prioridad;
      guardar();
      pintarCarril();
      cargar();
      return;
    }

    if (f === 'estado') {
      const v = await elegir({
        titulo: 'Filtrar por estado',
        valor: filtros.estado?.length === 1 ? filtros.estado[0] : null,
        opciones: [
          { valor: null, texto: 'Todos los estados' },
          ...(app.catalogo?.estados || []).map((e2) => ({
            valor: e2.id_estado, texto: e2.nombre_estado, color: e2.color,
          })),
        ],
      });
      if (v === null) delete filtros.estado; else filtros.estado = [v];
    } else if (f === 'familia') {
      const v = await elegir({
        titulo: 'Filtrar por área',
        valor: filtros.familia,
        opciones: [
          { valor: null, texto: 'Todas las áreas' },
          ...(app.catalogo?.familias || []).map((fa) => ({
            valor: fa.id_familia, texto: fa.nombre, color: fa.color,
            detalle: fa.n_elementos + ' tipos',
          })),
        ],
      });
      if (v === null) delete filtros.familia; else filtros.familia = v;
    } else if (f === 'clase') {
      const v = await elegir({
        titulo: 'Clase de parte',
        valor: filtros.clase?.[0],
        opciones: [
          { valor: null, texto: 'Todas' },
          { valor: 'averia',   texto: 'Averías',    detalle: 'Algo que reparar' },
          { valor: 'peticion', texto: 'Peticiones', detalle: 'Cama extra, cuna, VIP…' },
          { valor: 'tarea',    texto: 'Tareas',     detalle: 'Repasos y revisiones' },
        ],
      });
      if (v === null) delete filtros.clase; else filtros.clase = [v];
    } else if (f === 'orden') {
      const v = await elegir({
        titulo: 'Ordenar por',
        valor: filtros.orden || 'reciente',
        opciones: [
          { valor: 'reciente', texto: 'Más recientes primero' },
          { valor: 'prioridad', texto: 'Prioridad más alta primero' },
          { valor: 'limite', texto: 'Plazo más cercano primero' },
          { valor: 'estado', texto: 'Agrupados por estado' },
          { valor: 'antiguo', texto: 'Más antiguos primero' },
        ],
      });
      if (v) filtros.orden = v;
    } else {
      if (filtros[f]) delete filtros[f]; else filtros[f] = true;
    }

    guardar();
    pintarCarril();
    cargar();
  };

  /* --- toque y acción rápida -------------------------------------- */
  caja.onclick = async (e) => {
    const r = e.target.closest('[data-rapida]');
    if (r) {
      e.stopPropagation();
      const t = ultimaLista.find((x) => x.id_ticket === parseInt(r.dataset.rapida, 10));
      if (t && await accionRapida(t)) cargar();
      return;
    }

    const tarjeta = e.target.closest('[data-ticket]');
    if (tarjeta) {
      if (seleccionando) {
        // Se corta la propagación por si alguna pantalla anterior dejó
        // un manejador colgado del contenedor.
        e.stopPropagation();
        const idT = parseInt(tarjeta.dataset.ticket, 10);
        if (marcados.has(idT)) marcados.delete(idT); else marcados.add(idT);
        pintar(ultimaLista);
        return;
      }
      ir(`/ticket/${tarjeta.dataset.ticket}`);
      return;
    }
    if (e.target.closest('[data-accion="nuevo"]')) ir('/nuevo');
  };



  function guardar() {
    if (!soloMios) app.filtros = { ...filtros };
      app.filtrosPedidos = true;
  }

  const bSel = document.querySelector('[data-seleccionar]');
  if (bSel) bSel.onclick = () => (seleccionando ? salirSeleccion() : entrarSeleccion());

  const bExportar = document.querySelector('[data-exportar]');
  if (bExportar) bExportar.onclick = exportar;


  async function exportar() {
    if (!ultimaLista.length) {
      mensaje('No hay partes que exportar con estos filtros.', 'error');
      return;
    }

    const csv = aCsv(
      ['Código', 'Título', 'Estado', 'Prioridad', 'Tipo', 'Ubicación',
       'Asignado a', 'Abierto por', 'Fecha de alta', 'Plazo', 'Cierre',
       'Minutos', 'Fotos', 'Detalle'],
      ultimaLista.map((t) => [
        t.codigo || ('T-' + t.id_ticket),
        t.titulo_ticket,
        t.nombre_estado,
        t.prioridad,
        t.elemento,
        t.zona_ruta,
        t.asignado_a || 'Sin asignar',
        t.creado_por,
        fechaLarga(t.fecha_creado),
        t.fecha_limite ? fechaLarga(t.fecha_limite) : '',
        t.fecha_cierre ? fechaLarga(t.fecha_cierre) : '',
        t.minutos_trabajo || 0,
        t.n_adjuntos || 0,
        (t.detalle_ticket || '').replace(/\s+/g, ' ').trim(),
      ])
    );

    const hoy = new Date().toISOString().slice(0, 10);
    const ok = await descargarFichero(`partes-${hoy}.csv`, csv, 'text/csv;charset=utf-8');
    if (ok) mensaje(`${ultimaLista.length} partes exportados.`, 'ok');
  }

  pintarCarril();
  await cargar();

  return {
    // Actualiza los datos sin volver a montar la pantalla: así una
    // sincronización de fondo no se lleva la selección ni la posición.
    refrescar() { cargar(); },

    limpiar() {
      document.getElementById('barra-seleccion')?.remove();
      seleccionando = false;
      marcados.clear();
    },
  };
}

function aParametros(f) {
  return {
    q: f.q, estado: f.estado, prioridad: f.prioridad, elemento: f.elemento,
    familia: f.familia, clase: f.clase,
    mios: f.mios, mi_grupo: f.mi_grupo, sin_asignar: f.sin_asignar,
    abiertos: f.abiertos, cerrados_semana: f.cerrados_semana,
    programados: f.programados, dias: f.dias,
    vencidos: f.vencidos, orden: f.orden, limite: 100,
  };
}
