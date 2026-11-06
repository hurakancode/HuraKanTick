/* =====================================================================
   Mantenimiento programado.

   Un hotel no sólo repara lo que se rompe: tiene trabajos que toca hacer
   aunque nadie avise, y algunos los exige la normativa. El historial de
   cada revisión es lo que se enseña en una inspección, y por eso cada
   una genera un parte normal en vez de un registro aparte.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { panelLista } from '../listas.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, elegirBuscando,
  confirmar, fechaLarga, fechaCorta, haceCuanto,
} from '../ui.js';
import { cabeceraVolver, tarjetaParte, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';
import { elegirUbicacion } from './selectores.js';

export async function vistaMantenimiento({ contenido }) {
  pintarCabecera(cabeceraVolver('Mantenimiento', 'Lo que toca hacer aunque nadie avise'));

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>El calendario de mantenimiento necesita conexión.</div></div>`;
    return {};
  }

  contenido.innerHTML = bloqueCarga(4);
  let datos;

  /* Buscador, filtros y acciones en masa.
     Un calendario de mantenimiento preventivo crece hasta cien líneas
     —extintores, filtros, legionela, por planta— y sin buscador no hay
     manera de encontrar una. */
  const L = panelLista({
    datos: () => datos?.mantenimientos || [],
    id: (m) => m.id_mantenimiento,
    texto: (m) => [m.nombre, m.descripcion, m.familia, m.departamento,
                   m.zona_ruta, m.elemento, m.persona, m.periodicidad],
    placeholder: 'Buscar una revisión…',
    nombreUno: 'revisión',
    nombreVarios: 'revisiones',

    filtros: [
      {
        clave: 'cuando', titulo: 'Todas',
        opciones: [{ valor: 'vencido', texto: 'Fuera de plazo' },
                   { valor: 'pronto', texto: 'Toca pronto' },
                   { valor: 'off', texto: 'Desactivadas' }],
        cumple: (m, v) => (v === 'vencido' ? (m.activo && m.vencido)
                        : v === 'pronto' ? (m.activo && !m.vencido && m.inminente)
                        : !m.activo),
      },
      {
        clave: 'oblig', titulo: 'Obligatorias o no',
        opciones: [{ valor: 'si', texto: 'Obligatorias' }, { valor: 'no', texto: 'Las demás' }],
        cumple: (m, v) => (v === 'si' ? !!m.obligatorio : !m.obligatorio),
      },
    ],

    acciones: () => (!datos?.editable ? [] : [
      {
        clave: 'activar', texto: 'Activar', icono: 'ok',
        hacer: async (ids) => { await enLote(ids, { activo: true }, 'activadas'); },
      },
      {
        clave: 'desactivar', texto: 'Desactivar', icono: 'candado', peligro: true,
        confirmar: (n) => ({
          titulo: `Desactivar ${n} revisión${n === 1 ? '' : 'es'}`,
          texto: 'Dejan de generar partes. No se borran: el historial de las que ya '
               + 'se hicieron se conserva, y se pueden volver a activar.',
          aceptar: 'Desactivarlas', peligro: true,
        }),
        hacer: async (ids) => { await enLote(ids, { activo: false }, 'desactivadas'); },
      },
    ]),

    alCambiar: () => pintar(),
  });

  /* Aquí no hay endpoint de lote: son pocas y la ruta ya acepta PATCH
     por revisión. Se mandan en serie y se cuenta lo que salió mal, en
     vez de dar por bueno el conjunto. */
  async function enLote(ids, cambios, comoSeDice) {
    let bien = 0;
    const mal = [];
    for (const id of ids) {
      try { await api.editarMantenimiento(id, cambios); bien++; }
      catch (e) {
        const m = (datos.mantenimientos || []).find((x) => x.id_mantenimiento === id);
        mal.push((m?.nombre || '#' + id) + ': ' + e.message);
      }
    }
    tono(bien ? 'ok' : 'error');
    mensaje(bien ? `${bien} revisión(es) ${comoSeDice}.` : 'No se ha podido con ninguna.',
            bien ? 'ok' : 'error');
    if (mal.length) { mensaje(mal.join(' · '), 'aviso'); }
    await cargar();
  }

  async function cargar() {
    try { datos = await api.mantenimientos(); }
    catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }

    pintar();
  }

  function pintar() {
    const lista = L.visibles();
    const activos = lista.filter((m) => m.activo);
    const vencidos = activos.filter((m) => m.vencido);
    const pronto = activos.filter((m) => !m.vencido && m.inminente);
    const resto = activos.filter((m) => !m.vencido && !m.inminente);
    const inactivos = lista.filter((m) => !m.activo);

    const fila = (m) => `
      <button type="button" class="fila" data-m="${m.id_mantenimiento}">
        ${L.marca(m)}
        <span class="avatar" style="background:${esc(m.familia_color || '#0B5563')}22;
                                    color:${esc(m.familia_color || '#0B5563')}">
          ${icono(m.familia_icono || 'herramientas')}</span>
        <span class="fila__texto">${esc(m.nombre)}
          ${m.obligatorio ? '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">obligatorio</span>' : ''}
          <small>${esc(m.periodicidad)}
            ${m.zona_ruta ? ' · ' + esc(m.zona_ruta) : ''}
            ${m.departamento ? ' · ' + esc(m.departamento) : ''}
            ${m.veces ? ' · hecho ' + m.veces + (m.veces === 1 ? ' vez' : ' veces') : ' · nunca hecho'}
            ${m.fin_motivo
              ? ' · ' + esc(m.fin_motivo)
              : (m.restantes !== null && m.restantes !== undefined
                  ? ` · ${m.restantes === 0 ? 'terminada' : 'quedan ' + m.restantes}` : '')}</small></span>
        <span class="fila__valor" style="${m.vencido ? 'color:var(--peligro);font-weight:700'
                                         : m.inminente ? 'color:var(--aviso);font-weight:700' : ''}">
          ${m.vencido ? Math.abs(m.dias_restantes) + ' d tarde'
            : m.dias_restantes === 0 ? 'hoy'
            : m.dias_restantes + ' d'}</span>
        ${icono('flecha', 'fila__flecha')}
      </button>`;

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Cada revisión genera un parte cuando le toca, con su historial y sus
        fotos. Eso es lo que sirve para demostrar en una inspección que se hizo.
      </div></div>

      ${L.html()}

      ${datos.editable ? `
        <div class="acciones" style="margin-bottom:16px">
          <button type="button" class="btn btn--principal" data-nuevo>${icono('mas')}Añadir</button>
          <button type="button" class="btn btn--contorno" data-generar>${icono('sync')}Comprobar ahora</button>
        </div>` : ''}

      ${vencidos.length ? `
        <div class="seccion"><h2 style="color:var(--peligro)">Fuera de plazo</h2>
          <span class="rotulo">${vencidos.length}</span></div>
        <div class="filas">${vencidos.map(fila).join('')}</div>` : ''}

      ${pronto.length ? `
        <div class="seccion"><h2>Toca pronto</h2>
          <span class="rotulo">${pronto.length}</span></div>
        <div class="filas">${pronto.map(fila).join('')}</div>` : ''}

      ${resto.length ? `
        <div class="seccion"><h2>Programado</h2>
          <span class="rotulo">${resto.length}</span></div>
        <div class="filas">${resto.map(fila).join('')}</div>` : ''}

      ${inactivos.length ? `
        <div class="seccion"><h2>Desactivados</h2></div>
        <div class="filas" style="opacity:.55">${inactivos.map(fila).join('')}</div>` : ''}

      ${!lista.length ? estadoVacio({
        icono: 'calendario',
        titulo: 'Todavía no hay nada programado',
        texto: 'Añade las revisiones que se repiten: extintores, filtros, legionela…',
      }) : ''}`;

    // La pieza se engancha una vez: ella misma lo comprueba.
    L.enganchar(contenido);

    contenido.onclick = (e) => {
      if (e.target.closest('[data-lst-marca]')) return;   // marcar no abre la ficha
      const b = e.target.closest('[data-m]');
      if (b) {
        const m = (datos.mantenimientos || [])
          .find((x) => x.id_mantenimiento === parseInt(b.dataset.m, 10));
        if (m) abrir(m);
      }
    };

    const nuevo = $('[data-nuevo]', contenido);
    if (nuevo) nuevo.onclick = () => editar(null);

    const generar = $('[data-generar]', contenido);
    if (generar) generar.onclick = comprobar;
  }

  /* --------------------------------------------------------------- */
  async function comprobar() {
    const b = $('[data-generar]', contenido);
    b.disabled = true;
    b.innerHTML = `${icono('sync', 'girando')}Comprobando…`;
    try {
      // Primero en seco, para que nadie cree ocho partes sin querer.
      const previo = await api.generarMantenimiento(true);
      if (!previo.generados) {
        mensaje('No hay ninguna revisión que toque ahora.', 'ok');
        b.disabled = false;
        b.innerHTML = `${icono('sync')}Comprobar ahora`;
        return;
      }
      const ok = await confirmar({
        titulo: `Crear ${previo.generados} parte${previo.generados > 1 ? 's' : ''}`,
        texto: previo.detalle.filter((d) => d.estado === 'simulado')
          .map((d) => '· ' + d.nombre).join('\n'),
        aceptar: 'Crear',
      });
      if (ok) {
        const r = await api.generarMantenimiento(false);
        tono('ok');
        mensaje(`${r.generados} parte${r.generados > 1 ? 's' : ''} creado${r.generados > 1 ? 's' : ''}.`, 'ok');
      }
    } catch (e) {
      mensaje(e.message, 'error');
    }
    cargar();
  }

  /* --------------------------------------------------------------- */
  async function abrir(m) {
    let tickets = [];
    try { tickets = (await api.historialMantenimiento(m.id_mantenimiento)).tickets; }
    catch { /* se enseña sin historial */ }

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      ${m.descripcion ? `<p style="font-size:14px;color:var(--tinta-2);white-space:pre-wrap;margin-bottom:14px">
        ${esc(m.descripcion)}</p>` : ''}

      <dl class="datos" style="margin-top:0;margin-bottom:14px">
        <div class="datos__celda"><dt>Cada</dt><dd>${esc(m.periodicidad)}</dd></div>
        <div class="datos__celda"><dt>Próxima</dt>
          <dd style="${m.vencido ? 'color:var(--peligro)' : ''}">${esc(fechaCorta(m.proximo))}</dd></div>
        <div class="datos__celda"><dt>Se avisa</dt><dd>${m.aviso_dias} días antes</dd></div>
        <div class="datos__celda"><dt>Veces hecho</dt><dd>${m.veces}</dd></div>
        <div class="datos__celda"><dt>Repetición</dt>
          <dd>${esc(m.tope_texto || 'Sin fecha final.')}</dd></div>
        ${m.familia ? `<div class="datos__celda"><dt>Área</dt><dd>${esc(m.familia)}</dd></div>` : ''}
        ${m.departamento ? `<div class="datos__celda"><dt>Grupo</dt><dd>${esc(m.departamento)}</dd></div>` : ''}
        ${m.persona ? `<div class="datos__celda"><dt>Persona</dt><dd>${esc(m.persona)}</dd></div>` : ''}
        ${m.zona_ruta ? `<div class="datos__celda"><dt>Dónde</dt><dd>${esc(m.zona_ruta)}</dd></div>` : ''}
      </dl>

      ${m.obligatorio ? `<div class="aviso aviso--senal">${icono('aviso')}<div>
        Revisión de obligado cumplimiento. Conserva el historial: es la prueba
        de que se ha hecho.</div></div>` : ''}

      <div class="seccion"><h2>Historial</h2>
        <span class="rotulo">${tickets.length}</span></div>
      ${tickets.length
        ? tickets.slice(0, 12).map((t) => `
            <button type="button" class="fila" data-t="${t.id_ticket}">
              <span class="avatar" style="background:${esc(t.estado_color)}22;color:${esc(t.estado_color)}">●</span>
              <span class="fila__texto">${esc(t.codigo)} · ${esc(t.nombre_estado)}
                <small>${esc(fechaLarga(t.fecha_creado))}${t.asignado_a ? ' · ' + esc(t.asignado_a) : ''}</small></span>
            </button>`).join('')
        : '<p class="campo__ayuda">Todavía no se ha generado ningún parte.</p>'}`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = datos.editable
      ? `<button type="button" class="btn btn--contorno" data-editar>${icono('editar')}Editar</button>
         <button type="button" class="btn btn--principal" data-cerrar-hoja>Cerrar</button>`
      : `<button type="button" class="btn btn--principal" data-cerrar-hoja>Cerrar</button>`;

    hoja({
      titulo: m.nombre,
      cuerpo, pie,
      alAbrir: (h) => {
        $$('[data-t]', h).forEach((b) => {
          b.onclick = () => { cerrarHoja(); ir(`/ticket/${b.dataset.t}`); };
        });
        $('[data-cerrar-hoja]', h).onclick = () => cerrarHoja();
        const ed = $('[data-editar]', h);
        if (ed) ed.onclick = () => { cerrarHoja(); editar(m); };
      },
    });
  }

  /* --------------------------------------------------------------- */
  function editar(m) {
    const esNuevo = !m;
    const est = {
      unidad: m?.unidad || 'meses',
      id_familia: m?.id_familia ?? null,
      id_departamento: m?.id_departamento ?? null,
      id_persona: m?.id_persona ?? null,
      id_prioridad: m?.id_prioridad ?? null,
      zona: { id_rel: m?.id_rel_zona ?? null, z1: null, z2: null, z3: null, z4: null,
              ruta: m?.zona_ruta || '' },
    };

    const hoy = new Date();
    const porDefecto = new Date(hoy.getTime() + 7 * 864e5).toISOString().slice(0, 10);

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="m-nombre">Qué hay que hacer</label>
        <input class="entrada" id="m-nombre" maxlength="120" value="${esc(m?.nombre || '')}"
               placeholder="Revisión de extintores"></div>

      <div class="campo"><label for="m-desc">Instrucciones</label>
        <textarea class="area" id="m-desc" maxlength="1000"
                  placeholder="Qué comprobar y qué anotar">${esc(m?.descripcion || '')}</textarea>
        <div class="campo__ayuda">Aparecerá en cada parte que se genere.</div></div>

      <div class="campo">
        <label>Cada cuánto</label>
        <div style="display:flex;gap:8px">
          <input class="entrada" id="m-intervalo" type="number" min="1" max="99"
                 style="width:88px" value="${m?.intervalo || 1}">
          <div class="opciones" id="m-unidad" style="flex:1">
            ${Object.entries(datos.unidades).map(([k, v]) => `
              <button type="button" class="opcion" data-u="${k}"
                      aria-pressed="${est.unidad === k}">${esc(v)}</button>`).join('')}
          </div>
        </div>
      </div>

      <div class="campo"><label for="m-proximo">Próxima vez</label>
        <input class="entrada" id="m-proximo" type="date"
               value="${esc(m?.proximo || porDefecto)}"></div>

      <div class="campo"><label for="m-aviso">Crear el parte con antelación</label>
        <input class="entrada" id="m-aviso" type="number" min="0" max="60"
               value="${m?.aviso_dias ?? 7}">
        <div class="campo__ayuda">Días antes de la fecha. Da margen para organizarse.</div></div>

      <!-- Hasta cuándo se repite.
           Antes no había forma de decirlo: una revisión se repetía para
           siempre y había que acordarse de entrar a desactivarla. -->
      <div class="campo"><label for="m-repeticiones">Cuántas veces en total</label>
        <input class="entrada" id="m-repeticiones" type="number" min="0" max="999"
               placeholder="Sin límite" value="${m?.repeticiones ?? ''}">
        <div class="campo__ayuda">En blanco se repite siempre. Pon un número si son
          las revisiones de un contrato, por ejemplo.</div></div>

      <div class="campo"><label for="m-hasta">O hasta esta fecha</label>
        <input class="entrada" id="m-hasta" type="date" value="${esc(m?.hasta || '')}">
        <div class="campo__ayuda">También se puede dejar en blanco. Si pones las dos
          cosas, termina con la primera que llegue.</div></div>

      <div class="campo">
        <label>Área</label>
        <button type="button" class="fila" id="m-familia"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('llaveinglesa')}
          <span class="fila__texto" id="m-txt-familia">${esc(nombreFamilia(est.id_familia))}
            <small>Determina el grupo y el icono</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="campo">
        <label>Dónde</label>
        <button type="button" class="fila" id="m-zona"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('ubicacion')}
          <span class="fila__texto" id="m-txt-zona">${esc(est.zona.ruta || 'Todo el hotel')}
            <small>Opcional</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="campo">
        <label>Quién lo hace</label>
        <button type="button" class="fila" id="m-quien"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('personas')}
          <span class="fila__texto" id="m-txt-quien">${esc(nombreQuien(est))}
            <small>Recibirá el aviso cuando toque</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('aviso')}
        <span class="fila__texto">De obligado cumplimiento
          <small>Se marca en el parte y no conviene desactivarlo</small></span>
        <button type="button" class="interruptor" id="m-oblig" role="switch"
                aria-checked="${m?.obligatorio ? 'true' : 'false'}"></button>
      </div>

      <div id="m-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = esNuevo
      ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
         <button type="button" class="btn btn--principal" data-si>Crear</button>`
      : `<button type="button" class="btn btn--peligro" data-borrar>Desactivar</button>
         <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: esNuevo ? 'Nueva revisión' : m.nombre,
      cuerpo, pie,
      alAbrir: (h) => {
        $('#m-unidad', h).onclick = (e) => {
          const b = e.target.closest('[data-u]');
          if (!b) return;
          $$('[data-u]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.unidad = b.dataset.u;
        };

        $('#m-familia', h).onclick = async () => {
          const v = await elegirBuscando({
            titulo: 'Área',
            opciones: [{ valor: 0, texto: 'Sin área concreta', icono: 'info' },
              ...(app.catalogo?.familias || []).map((f) => ({
                valor: f.id_familia, texto: f.nombre, icono: f.icono,
                detalle: f.descripcion }))],
            valor: est.id_familia ?? 0,
            marcador: 'Buscar área',
          });
          if (v === null) return;
          est.id_familia = v || null;
          // El grupo se hereda del área, que es lo que suele querer.
          const f = app.catalogo?.familias?.find((x) => x.id_familia === est.id_familia);
          if (f?.id_departamento && !est.id_persona) {
            est.id_departamento = f.id_departamento;
            $('#m-txt-quien', h).innerHTML =
              `${esc(nombreQuien(est))}<small>Recibirá el aviso cuando toque</small>`;
          }
          $('#m-txt-familia', h).innerHTML =
            `${esc(nombreFamilia(est.id_familia))}<small>Determina el grupo y el icono</small>`;
        };

        $('#m-zona', h).onclick = async () => {
          const r = await elegirUbicacion(est.zona);
          if (!r) return;
          est.zona = r;
          $('#m-txt-zona', h).innerHTML =
            `${esc(r.ruta || 'Todo el hotel')}<small>Opcional</small>`;
        };

        $('#m-quien', h).onclick = async () => {
          const gente = app.catalogo?.usuarios || [];
          const grupos = app.catalogo?.departamentos || [];
          const v = await elegirBuscando({
            titulo: 'Quién lo hace',
            opciones: [
              { valor: 'nadie', texto: 'Sin asignar', icono: 'persona', destacado: true },
              ...grupos.map((g) => ({ valor: 'g:' + g.id_departamento, texto: g.nombre,
                                      detalle: 'Grupo entero', icono: 'personas', destacado: true })),
              ...gente.map((u) => ({ valor: 'p:' + u.id_login, texto: u.nombre_completo,
                                     detalle: u.correo })),
            ],
            valor: est.id_persona ? 'p:' + est.id_persona
                 : (est.id_departamento ? 'g:' + est.id_departamento : 'nadie'),
            marcador: 'Buscar persona o grupo',
          });
          if (v === null) return;
          est.id_persona = null;
          est.id_departamento = null;
          if (v.startsWith('p:')) est.id_persona = parseInt(v.slice(2), 10);
          if (v.startsWith('g:')) est.id_departamento = parseInt(v.slice(2), 10);
          $('#m-txt-quien', h).innerHTML =
            `${esc(nombreQuien(est))}<small>Recibirá el aviso cuando toque</small>`;
        };

        const oblig = $('#m-oblig', h);
        oblig.onclick = () => oblig.setAttribute('aria-checked',
          oblig.getAttribute('aria-checked') !== 'true');

        const err = (t) => { $('#m-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

        const no = $('[data-no]', h);
        if (no) no.onclick = () => cerrarHoja();

        const borrar = $('[data-borrar]', h);
        if (borrar) borrar.onclick = async () => {
          const ok = await confirmar({
            titulo: 'Desactivar la revisión',
            texto: 'Dejará de generar partes. El historial de lo ya hecho se conserva.',
            aceptar: 'Desactivar', peligro: true,
          });
          if (!ok) return;
          try {
            await api.borrarMantenimiento(m.id_mantenimiento);
            cerrarHoja();
            mensaje('Revisión desactivada.', 'ok');
            cargar();
          } catch (e) { err(e.message); }
        };

        $('[data-si]', h).onclick = async () => {
          const nombre = $('#m-nombre', h).value.trim();
          if (!nombre) return err('Escribe qué hay que hacer.');
          const proximo = $('#m-proximo', h).value;
          if (!proximo) return err('Indica la fecha de la próxima vez.');

          const d = {
            nombre,
            descripcion: $('#m-desc', h).value.trim(),
            intervalo: parseInt($('#m-intervalo', h).value, 10) || 1,
            unidad: est.unidad,
            proximo,
            aviso_dias: parseInt($('#m-aviso', h).value, 10) || 0,
            // 0 y vacío son lo mismo aquí: sin tope.
            repeticiones: parseInt($('#m-repeticiones', h).value, 10) || 0,
            hasta: $('#m-hasta', h).value || null,
            id_familia: est.id_familia,
            id_rel_zona: est.zona.id_rel,
            id_departamento: est.id_departamento,
            id_persona: est.id_persona,
            obligatorio: oblig.getAttribute('aria-checked') === 'true',
          };
          try {
            if (esNuevo) await api.crearMantenimiento(d);
            else await api.editarMantenimiento(m.id_mantenimiento, d);
            cerrarHoja();
            tono('ok');
            mensaje('Revisión guardada.', 'ok');
            cargar();
          } catch (e) { err(e.message); }
        };
      },
    });
  }

  function nombreFamilia(id) {
    if (!id) return 'Sin área concreta';
    return app.catalogo?.familias?.find((f) => f.id_familia === id)?.nombre || '#' + id;
  }
  function nombreQuien(est) {
    if (est.id_persona) {
      return app.catalogo?.usuarios?.find((u) => u.id_login === est.id_persona)?.nombre_completo || 'Alguien';
    }
    if (est.id_departamento) {
      return app.catalogo?.departamentos?.find((d) => d.id_departamento === est.id_departamento)?.nombre || 'Un grupo';
    }
    return 'Sin asignar';
  }

  await cargar();
  return { refrescar: cargar };
}
