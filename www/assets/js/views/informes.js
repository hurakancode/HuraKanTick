/* =====================================================================
   Informes.

   Tres decisiones y ya hay respuesta: qué mides, cómo lo agrupas y qué
   condiciones pones. Se empieza con lo básico visible y las condiciones
   se añaden sólo si hacen falta, para que la pantalla no asuste.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, elegirBuscando,
  confirmar, descargarFichero, aCsv, fechaLarga, minutosATexto,
} from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';

let defs = null;              // campos, operadores, medidas, periodos

export async function vistaInformes({ contenido }) {
  pintarCabecera(cabeceraVolver('Informes', '',
    `<button type="button" class="icono-btn" data-guardados aria-label="Informes guardados">${icono('archivo')}</button>`));

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Los informes consultan el servidor y necesitan conexión.</div></div>`;
    return {};
  }

  contenido.innerHTML = bloqueCarga(3);

  try {
    defs = defs || await api.informeDefiniciones();
  } catch (e) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
    return {};
  }

  // Estado del informe que se está construyendo
  const q = {
    medida: 'cuenta',
    agrupar: 'elemento',
    periodo: '',
    condiciones: [],
    union: 'y',
    orden: 'valor',
    limite: 20,
  };

  /* Si se llega desde el bloque «Ya ha pasado aquí» de un parte, el
     informe viene ya preparado: mismo tipo de avería, agrupado por sitio.
     Llegar a un formulario en blanco después de pulsar «ver el historial
     completo» sería hacer trabajar dos veces a quien ya dijo qué quería. */
  try {
    const guardado = sessionStorage.getItem('hurakantick_informe');
    if (guardado) {
      sessionStorage.removeItem('hurakantick_informe');
      const d = JSON.parse(guardado);
      if (d && typeof d === 'object') {
        if (d.medida)      q.medida = d.medida;
        if (d.agrupar)     q.agrupar = d.agrupar;
        if (d.condiciones) q.condiciones = d.condiciones;
        q.limite = 100;
      }
    }
  } catch { /* si no se puede leer, se empieza en blanco */ }

  let ultimo = null;

  function nombreCampo(clave) {
    return defs.campos.find((c) => c.clave === clave)?.etiqueta || clave;
  }

  function textoCondicion(c) {
    const op = defs.operadores[c.operador] || c.operador;
    let v = c.valor;
    if (Array.isArray(v)) v = v.join(', ');
    if (typeof v === 'boolean') v = v ? 'sí' : 'no';
    const sinValor = ['vacio', 'no_vacio'].includes(c.operador);
    return `${nombreCampo(c.campo)} ${op}${sinValor ? '' : ' ' + (c.etiqueta_valor || v)}`;
  }

  function pintar() {
    contenido.innerHTML = `
      <div class="tarjeta" style="margin-bottom:14px"><div class="tarjeta__cuerpo">

        <div class="campo" style="margin-bottom:12px">
          <label>Qué quiero medir</label>
          <button type="button" class="fila" data-elige="medida"
                  style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
            ${icono('panel')}
            <span class="fila__texto">${esc(defs.medidas[q.medida])}</span>
            ${icono('flecha', 'fila__flecha')}
          </button>
        </div>

        <div class="campo" style="margin-bottom:12px">
          <label>Agrupado por</label>
          <button type="button" class="fila" data-elige="agrupar"
                  style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
            ${icono('lista')}
            <span class="fila__texto">${esc(q.periodo ? defs.periodos[q.periodo] : nombreCampo(q.agrupar))}</span>
            ${icono('flecha', 'fila__flecha')}
          </button>
        </div>

        <div class="campo" style="margin-bottom:0">
          <label>Condiciones ${q.condiciones.length ? `(${q.condiciones.length})` : ''}</label>
          ${q.condiciones.length ? `
            <div class="filas" style="margin-bottom:8px">
              ${q.condiciones.map((c, i) => `
                <div class="fila" style="cursor:default;font-size:13px">
                  ${icono('filtro')}
                  <span class="fila__texto">${esc(textoCondicion(c))}</span>
                  <button type="button" class="icono-btn" data-quitar="${i}" aria-label="Quitar"
                          style="min-width:36px;height:36px">${icono('cerrar')}</button>
                </div>`).join('')}
            </div>
            ${q.condiciones.length > 1 ? `
              <div class="opciones" style="margin-bottom:8px">
                <button type="button" class="opcion" data-union="y" aria-pressed="${q.union === 'y'}">Se cumplen todas</button>
                <button type="button" class="opcion" data-union="o" aria-pressed="${q.union === 'o'}">Basta con una</button>
              </div>` : ''}
          ` : `<p class="campo__ayuda" style="margin:0 0 8px">
                 Sin condiciones se cuentan todos los partes.</p>`}

          <button type="button" class="btn btn--contorno btn--bloque btn--s" data-anadir>
            ${icono('mas')}Añadir condición
          </button>
        </div>
      </div></div>

      <div class="acciones" style="margin-bottom:16px">
        <button type="button" class="btn btn--contorno" data-guardar>${icono('guardar')}Guardar</button>
        <button type="button" class="btn btn--principal" data-ver>${icono('panel')}Ver resultado</button>
      </div>

      <div id="resultado"></div>`;

    enganchar();
  }

  function enganchar() {
    $$('[data-elige]', contenido).forEach((b) => {
      b.onclick = () => b.dataset.elige === 'medida' ? elegirMedida() : elegirAgrupacion();
    });
    $$('[data-quitar]', contenido).forEach((b) => {
      b.onclick = () => { q.condiciones.splice(parseInt(b.dataset.quitar, 10), 1); pintar(); };
    });
    $$('[data-union]', contenido).forEach((b) => {
      b.onclick = () => { q.union = b.dataset.union; pintar(); };
    });
    const anadir = $('[data-anadir]', contenido);
    if (anadir) anadir.onclick = anadirCondicion;

    /* El atributo llevaba una comilla de más —«data-ver"»—, así que el
       navegador creaba un atributo con ese nombre raro y «[data-ver]» no
       encontraba nada. Se había tapado buscando el botón por su texto, lo
       que funciona hasta que alguien traduce la aplicación o cambia la
       palabra. Arreglado el atributo, basta con buscarlo por él. */
    const ver = contenido.querySelector('[data-ver]');
    if (ver) ver.onclick = ejecutar;

    const guardar = $('[data-guardar]', contenido);
    if (guardar) guardar.onclick = guardarInforme;
  }

  /* --------------------------------------------------------------- */
  async function elegirMedida() {
    const v = await elegir({
      titulo: 'Qué quiero medir',
      valor: q.medida,
      opciones: Object.entries(defs.medidas).map(([k, t]) => ({ valor: k, texto: t })),
    });
    if (v) { q.medida = v; pintar(); }
  }

  async function elegirAgrupacion() {
    const opciones = [
      ...defs.campos.filter((c) => c.agrupable)
        .map((c) => ({ valor: 'c:' + c.clave, texto: c.etiqueta })),
      ...Object.entries(defs.periodos)
        .map(([k, t]) => ({ valor: 'p:' + k, texto: 'Por ' + t.toLowerCase(), detalle: 'Evolución en el tiempo' })),
    ];
    const v = await elegirBuscando({
      titulo: 'Agrupar por',
      opciones,
      valor: q.periodo ? 'p:' + q.periodo : 'c:' + q.agrupar,
      marcador: 'Buscar campo',
    });
    if (!v) return;
    if (v.startsWith('p:')) { q.periodo = v.slice(2); }
    else { q.agrupar = v.slice(2); q.periodo = ''; }
    pintar();
  }

  /* --------------------------------------------------------------- */
  async function anadirCondicion() {
    const campo = await elegirBuscando({
      titulo: 'Sobre qué campo',
      opciones: defs.campos.map((c) => ({
        valor: c.clave,
        texto: c.etiqueta,
        // Los campos propios se marcan con su tipo de avería: hay que
        // saber que «Temperatura de la sala» sólo existe en los partes
        // de aire acondicionado.
        detalle: c.propio
          ? (c.elemento ? 'Sólo en ' + c.elemento : 'Campo propio') + ' · ' + tipoTexto(c.tipo)
          : tipoTexto(c.tipo),
        icono: c.propio ? 'nota' : null,
      })),
      marcador: 'Buscar campo',
    });
    if (!campo) return;

    const def = defs.campos.find((c) => c.clave === campo);
    const ops = operadoresDe(def.tipo);

    const operador = await elegir({
      titulo: def.etiqueta,
      opciones: ops.map((o) => ({ valor: o, texto: defs.operadores[o] })),
      valor: ops[0],
    });
    if (!operador) return;

    if (['vacio', 'no_vacio'].includes(operador)) {
      q.condiciones.push({ campo, operador, valor: null });
      pintar();
      return;
    }

    const valor = await pedirValor(def, operador);
    if (valor === null) return;

    q.condiciones.push({ campo, operador, ...valor });
    pintar();
  }

  function tipoTexto(t) {
    return { catalogo: 'De una lista', fecha: 'Fecha', numero: 'Número',
             texto: 'Texto', booleano: 'Sí o no', especial: '',
             lista_propia: 'De una lista' }[t] || '';
  }

  function operadoresDe(tipo) {
    if (tipo === 'lista_propia') return ['es', 'no_es', 'vacio', 'no_vacio'];
    if (tipo === 'catalogo') return ['es', 'no_es', 'en', 'contiene', 'vacio', 'no_vacio'];
    if (tipo === 'fecha')    return ['ultimos_dias', 'mayor', 'menor', 'entre', 'vacio', 'no_vacio'];
    if (tipo === 'numero')   return ['mayor', 'menor', 'entre', 'es'];
    if (tipo === 'booleano' || tipo === 'especial') return ['es'];
    return ['contiene', 'no_contiene', 'empieza', 'es', 'vacio', 'no_vacio'];
  }

  /** Pide el valor de la condición, con la lista adecuada si es catálogo. */
  function pedirValor(def, operador) {
    // Catálogos que la app ya tiene descargados: se elige de la lista.
    const listas = {
      estados: (app.catalogo?.estados || []).map((e) => ({ valor: e.id_estado, texto: e.nombre_estado })),
      prioridades: (app.catalogo?.prioridades || []).map((p) => ({ valor: p.id_prioridad, texto: p.nombre })),
      elementos: (app.catalogo?.elementos || []).map((e) => ({ valor: e.id_elemento, texto: e.nombre, extra: e.abreviatura })),
      departamentos: (app.catalogo?.departamentos || []).map((d) => ({ valor: d.id_departamento, texto: d.nombre })),
      usuarios: (app.catalogo?.usuarios || []).map((u) => ({ valor: u.id_login, texto: u.nombre_completo })),
      zona_1: (app.catalogo?.zonas?.zona1 || []).map((z) => ({ valor: z.id, texto: z.nombre })),
      zona_2: (app.catalogo?.zonas?.zona2 || []).map((z) => ({ valor: z.id, texto: z.nombre })),
      zona_3: (app.catalogo?.zonas?.zona3 || []).map((z) => ({ valor: z.id, texto: z.nombre })),
      zona_4: (app.catalogo?.zonas?.zona4 || []).map((z) => ({ valor: z.id, texto: z.nombre })),
      zonas:  (app.catalogo?.zonas?.combinaciones || []).map((c) => ({ valor: c.id_rel, texto: c.ruta })),
    };

    if (def.tipo === 'catalogo' && listas[def.catalogo] && ['es', 'no_es'].includes(operador)) {
      return elegirBuscando({
        titulo: def.etiqueta,
        opciones: listas[def.catalogo],
        marcador: `Buscar entre ${listas[def.catalogo].length}`,
      }).then((v) => {
        if (v === null) return null;
        const o = listas[def.catalogo].find((x) => x.valor === v);
        // Se manda el identificador y se marca explícitamente, porque hay
        // nombres que son números (las habitaciones) y adivinarlo falla.
        return { valor: v, por: 'id', etiqueta_valor: o?.texto };
      });
    }

    // Un campo propio de tipo lista trae sus propias opciones.
    if (def.propio && def.opciones?.length && ['es', 'no_es'].includes(operador)) {
      return elegir({
        titulo: def.etiqueta,
        opciones: def.opciones.map((o) => ({ valor: o, texto: o })),
      }).then((v) => v === null ? null : { valor: v, etiqueta_valor: v });
    }

    if (def.propio && def.tipo === 'booleano') {
      return elegir({
        titulo: def.etiqueta,
        opciones: [{ valor: '1', texto: 'Sí' }, { valor: '0', texto: 'No' }],
      }).then((v) => v === null ? null : { valor: v, etiqueta_valor: v === '1' ? 'sí' : 'no' });
    }

    if (def.tipo === 'booleano' || def.tipo === 'especial') {
      return elegir({
        titulo: def.etiqueta,
        opciones: [{ valor: 'si', texto: 'Sí' }, { valor: 'no', texto: 'No' }],
      }).then((v) => v === null ? null : { valor: v === 'si', etiqueta_valor: v === 'si' ? 'sí' : 'no' });
    }

    // Valor escrito a mano
    return new Promise((resolve) => {
      let resuelto = false;
      const entre = operador === 'entre';
      const esFecha = def.tipo === 'fecha' && operador !== 'ultimos_dias';
      const tipoInput = esFecha ? 'date' : (def.tipo === 'numero' || operador === 'ultimos_dias' ? 'number' : 'text');

      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `
        <div class="campo">
          <label for="v1">${esc(def.etiqueta)} ${esc(defs.operadores[operador])}</label>
          <input class="entrada" id="v1" type="${tipoInput}"
                 placeholder="${operador === 'ultimos_dias' ? 'Número de días' : ''}">
        </div>
        ${entre ? `<div class="campo"><label for="v2">y</label>
                     <input class="entrada" id="v2" type="${tipoInput}"></div>` : ''}
        ${def.tipo === 'catalogo' ? `<p class="campo__ayuda">
           Se busca por el nombre, no hace falta el número interno.</p>` : ''}`;

      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                       <button type="button" class="btn btn--principal" data-si>Añadir</button>`;

      hoja({
        titulo: 'Valor', cuerpo, pie,
        alAbrir: (h) => {
          setTimeout(() => $('#v1', h).focus(), 250);
          $('[data-no]', h).onclick = () => cerrarHoja();
          $('[data-si]', h).onclick = () => {
            const a = $('#v1', h).value.trim();
            if (!a) return;
            resuelto = true;
            if (entre) {
              const b = $('#v2', h).value.trim();
              resolve({ valor: [a, b], etiqueta_valor: `${a} y ${b}` });
            } else {
              // Texto sobre un catálogo: se compara con el nombre.
              const extra = def.tipo === 'catalogo' ? { por: 'nombre' } : {};
              resolve({ valor: a, ...extra, etiqueta_valor: a });
            }
            cerrarHoja();
          };
        },
        alCerrar: () => { if (!resuelto) resolve(null); },
      });
    });
  }

  /* --------------------------------------------------------------- */
  async function ejecutar() {
    const caja = $('#resultado', contenido);
    caja.innerHTML = bloqueCarga(3);
    try {
      ultimo = await api.informeEjecutar(q);
      pintarResultado(caja, ultimo);
      tono('ok');
    } catch (e) {
      caja.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      tono('error');
    }
  }

  function pintarResultado(caja, r) {
    if (!r.filas.length) {
      caja.innerHTML = estadoVacio({
        icono: 'buscar',
        titulo: 'Sin resultados',
        texto: 'Ningún parte cumple esas condiciones. Prueba a quitar alguna.',
      });
      return;
    }

    const maximo = Math.max(...r.filas.map((f) => f.valor), 1);
    const formato = (v) => {
      if (r.medida === 'minutos') return minutosATexto(Math.round(v));
      if (r.medida === 'coste') return v.toFixed(2) + ' €';
      if (r.medida === 'media_horas') return v ? v + ' h' : '—';
      if (r.medida === 'porcentaje_plazo') return (v ?? 0) + ' %';
      return String(Math.round(v));
    };

    caja.innerHTML = `
      <div class="rejilla-cifras" style="margin-bottom:14px">
        <div class="cifra" style="cursor:default">
          <div class="cifra__valor">${r.total.partes}</div>
          <div class="cifra__nombre">Partes encontrados</div>
        </div>
        <div class="cifra" style="cursor:default">
          <div class="cifra__valor">${r.total.abiertos}</div>
          <div class="cifra__nombre">Todavía abiertos</div>
        </div>
      </div>

      <div class="seccion">
        <h2>${esc(r.medida_nombre)}</h2>
        <span class="rotulo">por ${esc(r.etiqueta_grupo.toLowerCase())}</span>
      </div>

      <div class="tarjeta"><div class="tarjeta__cuerpo">
        <div class="reparto">
          ${r.filas.map((f) => `
            <div class="reparto__fila" style="grid-template-columns:1fr 90px 62px">
              <span class="reparto__nombre" title="${esc(f.etiqueta)}"
                    style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(f.etiqueta)}</span>
              <span class="reparto__pista"><span class="reparto__barra"
                    style="width:${Math.round(f.valor / maximo * 100)}%;background:var(--marca)"></span></span>
              <span class="reparto__n">${esc(formato(f.valor))}</span>
            </div>`).join('')}
        </div>
      </div></div>

      <div class="acciones" style="margin-top:14px">
        <button type="button" class="btn btn--contorno btn--s" data-csv>${icono('descarga')}Exportar</button>
        <button type="button" class="btn btn--contorno btn--s" data-detalle>${icono('lista')}Ver los partes</button>
      </div>`;

    $('[data-csv]', caja).onclick = () => exportar(r);
    $('[data-detalle]', caja).onclick = verDetalle;
  }

  async function exportar(r) {
    /* «Número de partes» va aparte sólo si la medida es otra cosa.
     *
     * Al contar partes, la medida Y el recuento son lo mismo, y el CSV
     * salía con la columna repetida: quien lo abría en Excel veía dos
     * veces la misma cifra y no sabía cuál mirar. */
    const repetida = (r.medida_nombre || '').toLowerCase().includes('número de partes');

    const cabeceras = repetida
      ? [r.etiqueta_grupo, r.medida_nombre]
      : [r.etiqueta_grupo, r.medida_nombre, 'Número de partes'];

    const csv = aCsv(
      cabeceras,
      r.filas.map((f) => (repetida
        ? [f.etiqueta, f.valor]
        : [f.etiqueta, f.valor, f.n_partes]))
    );
    const ok = await descargarFichero(
      `informe-${new Date().toISOString().slice(0, 10)}.csv`, csv, 'text/csv;charset=utf-8');
    if (ok) mensaje('Informe exportado.', 'ok');
  }

  async function verDetalle() {
    try {
      const r = await api.informeEjecutar({ ...q, detalle: true });
      const lista = r.partes || [];
      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = lista.length
        ? `<div class="filas">${lista.slice(0, 100).map((t) => `
            <button type="button" class="fila" data-t="${t.id_ticket}">
              <span class="avatar" style="background:${esc(t.estado_color)}22;color:${esc(t.estado_color)}">●</span>
              <span class="fila__texto">${esc(t.titulo_ticket)}
                <small>${esc(t.codigo)} · ${esc(t.nombre_estado)}${t.zona_ruta ? ' · ' + esc(t.zona_ruta) : ''}</small></span>
            </button>`).join('')}</div>
           ${lista.length > 100 ? `<p class="campo__ayuda">Se muestran 100 de ${lista.length}.</p>` : ''}`
        : '<p style="color:var(--tinta-3)">No hay partes que mostrar.</p>';

      hoja({
        titulo: `${lista.length} partes`,
        cuerpo,
        alAbrir: (h) => {
          $$('[data-t]', h).forEach((b) => {
            b.onclick = () => { cerrarHoja(); ir(`/ticket/${b.dataset.t}`); };
          });
        },
      });
    } catch (e) {
      mensaje(e.message, 'error');
    }
  }

  /* --------------------------------------------------------------- */
  async function guardarInforme() {
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="i-nombre">Nombre</label>
        <input class="entrada" id="i-nombre" maxlength="120" placeholder="Averías de fontanería por planta"></div>
      <div class="campo"><label for="i-desc">Para qué sirve</label>
        <input class="entrada" id="i-desc" maxlength="200" placeholder="Opcional"></div>
      <div class="campo"><label for="inf-alcance">Quién lo va a ver</label>
        <select class="entrada" id="inf-alcance">
          <option value="yo">Sólo yo</option>
          <option value="departamento">Mi departamento</option>
          ${app.usuario.es_responsable
            ? '<option value="todos">Todo el equipo</option>' : ''}
        </select>
        <div class="campo__ayuda">Compartir con todo el equipo lo deciden los
          responsables: una lista que llena cualquiera deja de servir enseguida.</div>
      </div>
      <div id="i-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: 'Guardar informe', cuerpo, pie,
      alAbrir: (h) => {
        $('[data-no]', h).onclick = () => cerrarHoja();
        $('[data-si]', h).onclick = async () => {
          const nombre = $('#i-nombre', h).value.trim();
          if (!nombre) {
            $('#i-err', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>Ponle un nombre.</div></div>`;
            return;
          }
          try {
            await api.guardarInforme({
              nombre,
              descripcion: $('#i-desc', h).value.trim(),
              definicion: q,
              alcance: $('#inf-alcance', h)?.value || 'yo',
            });
            cerrarHoja();
            mensaje('Informe guardado.', 'ok');
          } catch (e) {
            $('#i-err', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  async function verGuardados() {
    let lista = [];
    try { lista = (await api.informes()).informes; } catch (e) { mensaje(e.message, 'error'); return; }

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = lista.length
      ? `<div class="filas">${lista.map((i) => `
          <button type="button" class="fila" data-i="${i.id_informe}">
            ${icono('panel')}
            <span class="fila__texto">${esc(i.nombre)}
              <small>${esc(i.descripcion || '')}${
                { departamento: ' · de mi departamento', todos: ' · de todo el equipo' }[i.alcance] || ''}
                ${i.mio ? '' : ' · de ' + esc(i.autor || '')}</small></span>
            ${i.mio ? `<button type="button" class="icono-btn" data-borrar="${i.id_informe}" aria-label="Borrar"
                        style="min-width:38px;height:38px">${icono('papelera')}</button>` : ''}
          </button>`).join('')}</div>`
      : `<p style="color:var(--tinta-3);font-size:13.5px">Todavía no has guardado ningún informe.
         Monta uno y pulsa «Guardar».</p>`;

    hoja({
      titulo: 'Informes guardados', cuerpo,
      alAbrir: (h) => {
        $$('[data-i]', h).forEach((b) => {
          b.onclick = (ev) => {
            if (ev.target.closest('[data-borrar]')) return;
            const inf = lista.find((x) => x.id_informe === parseInt(b.dataset.i, 10));
            if (inf?.definicion) {
              Object.assign(q, inf.definicion);
              cerrarHoja();
              pintar();
              ejecutar();
            }
          };
        });
        $$('[data-borrar]', h).forEach((b) => {
          b.onclick = async (ev) => {
            ev.stopPropagation();
            const ok = await confirmar({
              titulo: 'Eliminar informe',
              texto: 'Se borrará de tu lista. Los partes no se tocan.',
              aceptar: 'Eliminar', peligro: true,
            });
            if (!ok) return;
            await api.borrarInforme(parseInt(b.dataset.borrar, 10));
            cerrarHoja();
            mensaje('Informe eliminado.', 'ok');
          };
        });
      },
    });
  }

  pintar();
  const g = document.querySelector('[data-guardados]');
  if (g) g.onclick = verGuardados;

  return {};
}
