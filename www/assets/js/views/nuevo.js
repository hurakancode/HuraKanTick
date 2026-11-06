/* Alta de un parte. Es la acción más frecuente: tres toques y una foto. */

import * as sync from '../sync.js';
import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, elegir, elegirBuscando, cerrarHoja, ICONOS_ELEMENTO, plazoCorto, plazoLargo, aUtcDesdeLocal } from '../ui.js';
import { elegirElemento, elegirUbicacion } from './selectores.js';
import { tono } from '../sonido.js';
import { cabeceraVolver } from '../piezas.js';
import { pintarCabecera, ir, app, volver, modulo, ajuste } from '../app.js';
import { elegirFotos } from './fotos.js';

/** Marca visual de campo obligatorio, según lo que fije el administrador. */
/**
 * ¿Puede quien crea el parte elegir también a la persona?
 *
 * Sólo quien reparte: responsables, administradores y superadministrador.
 * Quien sólo avisa elige el departamento y ya está — poner nombres sin
 * saber quién está libre no ayuda a nadie.
 */
function puedeAsignarPersona() {
  const u = app.usuario || {};
  return !!(u.es_admin || u.es_superadmin || u.es_responsable);
}

function obligatorio(clave) {
  const exigidos = ajuste('alta_obligatorios', []) || [];
  return exigidos.includes(clave)
    ? ' <span style="color:var(--peligro);font-weight:700" title="Obligatorio">*</span>'
    : '';
}

export async function vistaNuevo({ contenido }) {
  /* El subtítulo dice la verdad según lo que el administrador exija: con
     campos obligatorios, «todo lo demás es opcional» era mentira. */
  const exigidosAhora = ajuste('alta_obligatorios', []) || [];
  pintarCabecera(cabeceraVolver('Nuevo parte',
    exigidosAhora.length ? 'Lo marcado con * es obligatorio' : 'Todo lo demás es opcional'));

  const seleccion = {
    id_elemento: null,
    id_prioridad: 2,
    // Si se llega desde la ficha de una ubicación, ya viene puesta: es
    // el atajo que ahorra el paso más lento del formulario.
    zona: (() => {
      try {
        const g = sessionStorage.getItem('hurakantick_zona_previa');
        if (g) { sessionStorage.removeItem('hurakantick_zona_previa'); return JSON.parse(g); }
      } catch { /* nada */ }
      return { id_rel: null, z1: null, z2: null, z3: null, z4: null, ruta: '' };
    })(),
    // Si se llega desde el panel de un área, ya viene puesta
    id_familia: (() => {
      try {
        const g = sessionStorage.getItem('hurakantick_familia_previa');
        if (g) { sessionStorage.removeItem('hurakantick_familia_previa'); return parseInt(g, 10); }
      } catch { /* nada */ }
      return null;
    })(),
    nombre_tipo: '',
    id_persona_asignada: null,
    // A quién va si nadie dice otra cosa. Lo fija el administrador y lo
    // habitual es Servicios Técnicos: la mayoría de los avisos son suyos.
    id_grupo_asignado: ajuste('alta_departamento', null),
    campos: {},
    fotos: [],
  };

  /* ¿Ha tocado alguien el departamento a mano? Entonces no se le cambia. */
  let depAMano = false;

  const niveles = app.catalogo?.niveles_zona || ['Edificio', 'Planta', 'Unidad', 'Punto'];

  contenido.innerHTML = `
  <form id="f-nuevo" novalidate>

    <div class="campo">
      <label>¿Qué falla?${obligatorio('elemento')}</label>
      <button type="button" class="fila" id="b-elemento"
              style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('llaveinglesa')}
        <span class="fila__texto" id="txt-elemento">Elegir tipo de avería
          <small>Hay ${(app.catalogo?.elementos || []).length} tipos: busca por nombre</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
      <div class="opciones" id="frecuentes" style="margin-top:8px"></div>
    </div>

    <div id="campos-propios"></div>

    <div class="campo">
      <label for="titulo">Resumen${obligatorio('titulo')}</label>
      <input class="entrada" type="text" id="titulo" maxlength="120"
             placeholder="Por ejemplo: el aire no enfría">
      <div class="campo__ayuda">Si lo dejas en blanco se compone con el tipo y la ubicación.</div>
    </div>

    <div id="aviso-duplicado"></div>

    <div class="campo">
      <label>¿Dónde?${obligatorio('ubicacion')}</label>
      <button type="button" class="fila" id="b-zona" style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('ubicacion')}
        <span class="fila__texto" id="txt-zona">
          ${seleccion.zona.ruta ? esc(seleccion.zona.ruta) : 'Elegir ubicación'}
          <small>${seleccion.zona.ruta ? 'Toca para cambiar' : esc(niveles.join(' › '))}</small>
        </span>
        ${icono('flecha', 'fila__flecha')}
      </button>
      <div id="sap-ocupacion"></div>
    </div>

    <div class="campo" id="caja-prioridad" ${modulo('prioridad') ? '' : 'hidden'}>
      <label>Prioridad</label>
      <div class="opciones" id="prioridades">
        ${(app.catalogo?.prioridades || []).map((p) => `
          <button type="button" class="opcion" data-color data-prioridad="${p.id_prioridad}"
                  style="--c:${esc(p.color)}" aria-pressed="${p.id_prioridad === 2}">
            ${esc(p.nombre)}${p.sla_horas ? ` · ${plazoCorto(p.sla_horas)}` : ''}
          </button>`).join('')}
      </div>
      <div class="campo__ayuda" id="ayuda-sla"></div>
    </div>

    <div class="campo">
      <label for="detalle">Detalle${obligatorio('detalle')}</label>
      <textarea class="area" id="detalle" maxlength="2000"
                placeholder="Lo que has visto, desde cuándo pasa, si molesta a un cliente…"></textarea>
    </div>

    <div class="campo" id="caja-fotos" ${modulo('fotos') ? '' : 'hidden'}>
      <label>Fotografías</label>
      <div class="fotos" id="fotos"></div>
      <div class="campo__ayuda">Se comprimen en el móvil. Si no hay cobertura se guardan y se envían después.</div>
    </div>

    <!-- Dos asignaciones distintas, y no una sola cosa:
         el DEPARTAMENTO dice de quién es el trabajo, y es obligatorio
         porque un parte sin grupo no aparece en la cola de nadie.
         La PERSONA es quién lo hace, y puede venir después: la pone el
         responsable del grupo, o se la queda alguien del grupo. -->
    <div class="campo">
      <label>Departamento <span style="color:var(--peligro);font-weight:700"
             title="Obligatorio">*</span></label>
      <button type="button" class="fila" id="b-grupo" style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('personas')}
        <span class="fila__texto" id="txt-grupo">${(() => {
          const g = (app.catalogo?.departamentos || [])
            .find((d) => d.id_departamento === seleccion.id_grupo_asignado);
          return g ? `${esc(g.nombre)}<small>De quién es el trabajo</small>`
                   : 'Elegir departamento<small>Obligatorio: sin él nadie lo ve</small>';
        })()}</span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    ${puedeAsignarPersona() ? `
      <div class="campo">
        <label>Persona <span style="color:var(--tinta-3);font-weight:500;font-size:12px">
          · opcional</span></label>
        <button type="button" class="fila" id="b-persona"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('persona')}
          <span class="fila__texto" id="txt-persona">Sin asignar<small>Alguien del departamento se lo quedará</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
        <div class="campo__ayuda">
          Sólo aparecen las personas de ese departamento.
        </div>
      </div>` : `
      <div class="campo__ayuda" style="margin:-6px 2px 14px">
        La persona concreta la pondrá el responsable del departamento, o
        se lo quedará alguien de ahí.
      </div>`}

    <div class="filas" style="margin:4px 0 10px">
      <div class="fila" style="cursor:default">
        ${icono('reloj')}
        <span class="fila__texto">Publicarlo más adelante
          <small>Para lo que no se puede atender todavía: el cliente tiene el
            cartel de no molestar, la pieza llega el jueves…</small></span>
        <button type="button" class="interruptor" id="n-programar" role="switch" aria-checked="false"></button>
      </div>
    </div>
    <div class="campo oculto" id="n-programar-campo">
      <label for="n-visible">No se verá hasta el</label>
      <div style="display:flex;gap:8px">
        <input class="entrada" type="date" id="n-visible" style="flex:2"
               min="${new Date().toISOString().slice(0, 10)}">
        <input class="entrada" type="time" id="n-visible-hora" value="08:00" style="flex:1">
      </div>
      <div class="campo__ayuda">Hasta esa fecha el parte se queda en estado
        «Programado»; a esa hora vuelve solo a la cola para que alguien lo coja.
        Mientras tanto se ve, para poder organizarse.</div>
    </div>

    <button class="btn btn--principal btn--bloque" type="submit" id="b-guardar" style="margin-top:6px">
      ${icono('guardar')}Crear parte
    </button>
    <div class="campo__ayuda" style="text-align:center;margin-top:10px" id="pie-red"></div>
  </form>`;

  const form = $('#f-nuevo', contenido);

  /* --- elemento --------------------------------------------------- */
  const elementos = app.catalogo?.elementos || [];

  function pintarElemento() {
    const fam = app.catalogo?.familias?.find((f) => f.id_familia === seleccion.id_familia);
    const el = elementos.find((x) => x.id_elemento === seleccion.id_elemento);

    let texto, detalle;
    if (el) {
      texto = el.nombre;
      detalle = fam ? fam.nombre : (el.abreviatura || 'Toca para cambiar');
    } else if (fam) {
      texto = fam.nombre;
      detalle = 'Sin concretar más · toca para precisar';
    } else {
      texto = 'Elegir qué falla';
      detalle = 'Por áreas o buscando por nombre';
    }
    $('#txt-elemento', contenido).innerHTML = `${esc(texto)}<small>${esc(detalle)}</small>`;

    /* Atajo a los tipos de siempre. De dónde salen y cuántos lo decide el
       administrador: lo más usado en el hotel, en tu departamento, lo que
       más usas tú, o una lista fija. */
    const cuantos = Math.max(0, Math.min(6, Number(ajuste('rapidos_cantidad', 6))));
    const origen = ajuste('rapidos_origen', 'hotel');
    const porId = new Map(elementos.map((x) => [x.id_elemento, x]));
    const deIds = (ids) => (ids || []).map((id) => porId.get(id)).filter(Boolean);
    let top;
    if (cuantos === 0) top = [];
    else if (origen === 'manual')       top = deIds(ajuste('rapidos_manual', []));
    else if (origen === 'usuario')      top = deIds(ajuste('rapidos_usuario', []));
    else if (origen === 'departamento') top = deIds(ajuste('rapidos_grupo', []));
    else top = elementos.filter((x) => (x.usos || 0) > 0);
    /* Si aún no hay historial —una persona nueva, un departamento recién
       creado—, se cae a lo más usado del hotel: mejor eso que un hueco. */
    if (!top.length && origen !== 'manual') top = elementos.filter((x) => (x.usos || 0) > 0);
    top = top.slice(0, cuantos);
    $('#frecuentes', contenido).innerHTML = top.map((x) => `
      <button type="button" class="opcion" data-rapido="${x.id_elemento}"
              aria-pressed="${x.id_elemento === seleccion.id_elemento}">
        ${icono(ICONOS_ELEMENTO[x.icono] || (/^fa-[0-9a-f]{1,5}$/i.test(x.icono || '') ? x.icono : 'llaveinglesa'))}${esc(x.nombre)}
      </button>`).join('');
  }

  $('#b-elemento', contenido).onclick = async () => {
    const v = await elegirElemento({
      id_familia: seleccion.id_familia, id_elemento: seleccion.id_elemento });
    if (v === null) return;
    seleccion.id_familia  = v.id_familia;
    seleccion.id_elemento = v.id_elemento;
    seleccion.nombre_tipo = v.nombre;
    pintarElemento();
    departamentoSegunTipo();
    pintarCamposPropios();
  };

  /* La ocupación de la habitación, si el módulo de SAP está encendido.
   *
   * Va envuelto entero: si el módulo está apagado, mal configurado o SAP
   * no contesta, el alta sigue exactamente igual. Un parte nunca debe
   * dejar de darse porque un sistema de fuera esté caído. */
  async function ocupacionDeLaHabitacion() {
    const caja = $('#sap-ocupacion', contenido);
    if (!caja) return;
    caja.innerHTML = '';

    const ruta = seleccion.zona?.ruta || '';
    const numero = (ruta.match(/\b(\d{2,5})\b/g) || []).pop();
    if (!numero || !hayRed()) return;

    try {
      const est = await api.sapEstado();
      if (!est?.en_parte) return;

      const d = await api.sapHabitacion(numero);
      const { tarjetaHabitacion } = await import('./sap.js');
      caja.innerHTML = `
        <div class="seccion"><h2>La habitación ahora</h2>
          <span class="rotulo">del sistema del hotel</span></div>
        ${tarjetaHabitacion(d, { compacta: true })}
        ${(d.ocupada && est.avisar) ? `
          <div class="aviso aviso--info" style="margin-top:8px">${icono('aviso')}
            <div>Hay alguien dentro: avisa a recepción antes de subir.</div></div>` : ''}`;
    } catch {
      /* Sin módulo, sin permiso o sin respuesta: no se enseña nada y no
         se molesta a quien está dando el parte. */
    }
  }

  /* El departamento, según lo que falla.
   *
   * El servidor ya reparte por familia, pero esta pantalla mandaba SIEMPRE
   * el departamento por defecto, así que el reparto no llegaba a decidir
   * nada: se eligiera lo que se eligiera, el parte iba a Servicios
   * Técnicos. Aquí se pone el que toca en cuanto se elige el tipo.
   *
   * Si la persona cambia el departamento a mano, manda ella: no se le
   * vuelve a tocar aunque luego cambie el tipo. */
  function departamentoSegunTipo() {
    if (depAMano) return;
    if (!ajuste('rutar_por_familia', true)) return;

    const el  = (app.catalogo?.elementos || []).find((x) => x.id_elemento === seleccion.id_elemento);
    const fam = (app.catalogo?.familias  || []).find((f) => f.id_familia === seleccion.id_familia);
    // El tipo manda sobre su familia sólo cuando difiere: es la excepción
    // de la caja fuerte, que está en Pisos y la arregla Servicios Técnicos.
    /* Manda la familia, salvo que el tipo esté marcado como excepción
       —la caja fuerte—. Antes mandaba el tipo siempre que difiriera, y eso
       incluía a los que se habían quedado con el departamento viejo tras
       cambiar el de su familia. */
    const dep = el?.dep_propio && el?.id_departamento
      ? el.id_departamento
      : (fam?.id_departamento ?? el?.id_departamento ?? null);
    if (!dep || dep === seleccion.id_grupo_asignado) return;

    seleccion.id_grupo_asignado = dep;
    // La persona elegida era de otro grupo: deja de valer.
    seleccion.id_persona_asignada = null;
    refrescarGrupoYPersona();
  }

  /* Los textos del departamento y de la persona. Se arman igual que al
     pintar la pantalla; aquí sólo se ponen al día. */
  function refrescarGrupoYPersona() {
    const g = (app.catalogo?.departamentos || [])
      .find((d) => d.id_departamento === seleccion.id_grupo_asignado);
    const txtG = $('#txt-grupo', contenido);
    if (txtG) {
      txtG.innerHTML = g
        ? `${esc(g.nombre)}<small>Se lo repartirá su responsable</small>`
        : 'Elegir departamento<small>Obligatorio: sin él nadie lo ve</small>';
    }
    const txtP = $('#txt-persona', contenido);
    if (txtP && !seleccion.id_persona_asignada) {
      txtP.innerHTML = 'Sin asignar<small>Alguien del departamento se lo quedará</small>';
    }
  }

  $('#frecuentes', contenido).onclick = (e) => {
    const b = e.target.closest('[data-rapido]');
    if (!b) return;
    const id = parseInt(b.dataset.rapido, 10);
    const el = elementos.find((x) => x.id_elemento === id);
    if (seleccion.id_elemento === id) {
      seleccion.id_elemento = null;
      seleccion.id_familia = null;
    } else {
      seleccion.id_elemento = id;
      seleccion.id_familia = el?.id_familia ?? null;
    }
    pintarElemento();
    departamentoSegunTipo();
    pintarCamposPropios();
  };

  /* --- campos propios del tipo elegido ---------------------------- */
  /* Es la generalización del formulario que antes tenía a mano el aire
     acondicionado: cada tipo puede pedir lo suyo sin llenar de casillas
     los partes que no lo necesitan. */
  function camposDelTipo() {
    if (!modulo('campos')) return [];
    return (app.catalogo?.campos || []).filter(
      (c) => c.id_elemento === null || c.id_elemento === seleccion.id_elemento);
  }

  function pintarCamposPropios() {
    const caja = $('#campos-propios', contenido);
    const lista = camposDelTipo();
    if (!lista.length) { caja.innerHTML = ''; return; }

    caja.innerHTML = `
      <div class="tarjeta" style="margin-bottom:14px;border-left:3px solid var(--marca)">
        <div class="tarjeta__cuerpo">
          <div class="rotulo" style="margin-bottom:10px">Datos de este tipo de avería</div>
          ${lista.map((c) => campoHtml(c)).join('')}
        </div>
      </div>`;

    caja.querySelectorAll('[data-campo]').forEach((el) => {
      const clave = el.dataset.campo;
      if (el.classList.contains('interruptor')) {
        el.onclick = () => {
          const n = el.getAttribute('aria-checked') !== 'true';
          el.setAttribute('aria-checked', String(n));
          seleccion.campos[clave] = n ? '1' : '0';
        };
      } else if (el.dataset.tipo === 'lista') {
        el.onclick = async () => {
          const c = lista.find((x) => x.clave === clave);
          const v = await elegir({
            titulo: c.etiqueta,
            valor: seleccion.campos[clave],
            opciones: [{ valor: '', texto: 'Sin indicar' },
                       ...(c.opciones || []).map((o) => ({ valor: o, texto: o }))],
          });
          if (v === null) return;
          seleccion.campos[clave] = v || '';
          el.querySelector('.fila__texto').innerHTML =
            `${esc(v || c.etiqueta)}<small>${esc(v ? c.etiqueta : 'Toca para elegir')}</small>`;
        };
      } else {
        el.oninput = () => { seleccion.campos[clave] = el.value.trim(); };
      }
    });
  }

  function campoHtml(c) {
    const v = seleccion.campos[c.clave] || '';
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
    const tipo = c.tipo === 'numero' || c.tipo === 'temperatura' ? 'number'
               : c.tipo === 'fecha' ? 'date' : 'text';
    if (c.tipo === 'area') {
      return `<div class="campo"><label>${esc(c.etiqueta)}</label>
        <textarea class="area" data-campo="${esc(c.clave)}"
                  style="min-height:76px">${esc(v)}</textarea>
        ${c.ayuda ? `<div class="campo__ayuda">${esc(c.ayuda)}</div>` : ''}</div>`;
    }
    return `<div class="campo"><label>${esc(c.etiqueta)}${c.unidad ? ` (${esc(c.unidad)})` : ''}</label>
      <input class="entrada" type="${tipo}" inputmode="${tipo === 'number' ? 'decimal' : 'text'}"
             data-campo="${esc(c.clave)}" value="${esc(v)}">
      ${c.ayuda ? `<div class="campo__ayuda">${esc(c.ayuda)}</div>` : ''}</div>`;
  }

  // Si venía con familia puesta, el texto lo refleja
  if (seleccion.id_familia && !seleccion.nombre_tipo) {
    seleccion.nombre_tipo =
      app.catalogo?.familias?.find((f) => f.id_familia === seleccion.id_familia)?.nombre || '';
  }
  pintarElemento();
  pintarCamposPropios();

  /* --- prioridad -------------------------------------------------- */
  const ayudaSla = $('#ayuda-sla', contenido);
  function refrescarSla() {
    const p = app.catalogo?.prioridades?.find((x) => x.id_prioridad === seleccion.id_prioridad);
    ayudaSla.textContent = p?.sla_horas
      ? `Compromiso de atención: ${plazoLargo(p.sla_horas)} desde el alta.`
      : 'Sin plazo comprometido.';
  }
  $('#prioridades', contenido).onclick = (e) => {
    const b = e.target.closest('[data-prioridad]');
    if (!b) return;
    $$('[data-prioridad]', contenido).forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    seleccion.id_prioridad = parseInt(b.dataset.prioridad, 10);
    refrescarSla();
  };
  refrescarSla();

  /* --- ubicación -------------------------------------------------- */

  /* Programar el parte para más adelante. El campo sólo aparece si se
     pide: en el alta normal estorba. */
  const swProg = $('#n-programar', contenido);
  if (swProg) {
    swProg.onclick = () => {
      const puesto = swProg.getAttribute('aria-checked') !== 'true';
      swProg.setAttribute('aria-checked', puesto ? 'true' : 'false');
      $('#n-programar-campo', contenido).classList.toggle('oculto', !puesto);
      if (puesto && !$('#n-visible', contenido).value) {
        $('#n-visible', contenido).value =
          new Date(Date.now() + 86400000).toISOString().slice(0, 10);
      }
    };
  }
  $('#b-zona', contenido).onclick = async () => {
    const r = await elegirUbicacion(seleccion.zona, niveles);
    if (!r) return;
    seleccion.zona = r;
    ocupacionDeLaHabitacion();
    $('#txt-zona', contenido).innerHTML = r.ruta
      ? `${esc(r.ruta)}<small>Toca para cambiar</small>`
      : `Elegir ubicación<small>${esc(niveles.join(' › '))}</small>`;
    comprobarDuplicados();
  };

  /**
   * Avisa si ya hay partes abiertos en el mismo sitio.
   *
   * En un hotel pasa constantemente: recepción abre «la TV de la 214 no
   * va» cuando Servicios Técnicos ya tiene uno abierto por lo mismo. No
   * se impide crear el parte —a veces son cosas distintas— pero se
   * enseña lo que hay para que quien avisa decida.
   */
  async function comprobarDuplicados() {
    const caja = $('#aviso-duplicado', contenido);
    if (!caja || !hayRed() || (!seleccion.zona.id_rel && !seleccion.zona.z3)) {
      if (caja) caja.innerHTML = '';
      return;
    }
    try {
      const r = await api.abiertosEn(
        seleccion.zona.z3 ? { lugar: seleccion.zona.z3 } : { zona: seleccion.zona.id_rel });
      if (!r.tickets.length) { caja.innerHTML = ''; return; }

      caja.innerHTML = `
        <div class="aviso aviso--senal" style="margin-bottom:14px">
          ${icono('aviso')}
          <div style="flex:1">
            <strong>Ya hay ${r.tickets.length} parte${r.tickets.length > 1 ? 's' : ''} abierto${r.tickets.length > 1 ? 's' : ''} en este sitio.</strong>
            <div style="margin-top:8px;display:flex;flex-direction:column;gap:5px">
              ${r.tickets.slice(0, 4).map((t) => `
                <button type="button" data-dup="${t.id_ticket}"
                        style="text-align:left;background:none;border:0;padding:2px 0;cursor:pointer;
                               color:inherit;font-size:12.5px">
                  · ${esc(t.codigo)} ${esc(t.titulo_ticket)}
                  <span style="opacity:.7">— ${esc(t.nombre_estado)}</span>
                </button>`).join('')}
            </div>
            ${r.tickets.length > 4 ? `<div style="margin-top:6px;font-size:12px;opacity:.8">
              y ${r.tickets.length - 4} más</div>` : ''}
          </div>
        </div>`;

      caja.querySelectorAll('[data-dup]').forEach((b) => {
        b.onclick = () => ir(`/ticket/${b.dataset.dup}`);
      });
    } catch { caja.innerHTML = ''; }
  }

  comprobarDuplicados();

  /* --- departamento ----------------------------------------------- */
  $('#b-grupo', contenido).onclick = async () => {
    const grupos = app.catalogo?.departamentos || [];
    const v = await elegirBuscando({
      titulo: 'A qué departamento va',
      opciones: grupos.map((g) => ({
        valor: g.id_departamento, texto: g.nombre, icono: 'personas',
        detalle: g.descripcion || '',
      })),
      valor: seleccion.id_grupo_asignado,
      marcador: 'Buscar departamento',
    });
    if (v === null) return;

    const cambia = v !== seleccion.id_grupo_asignado;
    seleccion.id_grupo_asignado = v;
    depAMano = true;      // lo ha elegido una persona: manda sobre el reparto

    // Al cambiar de departamento, la persona elegida deja de valer:
    // era de otro grupo.
    if (cambia && seleccion.id_persona_asignada) {
      const p = (app.catalogo?.usuarios || [])
        .find((u) => u.id_login === seleccion.id_persona_asignada);
      if (!p || p.id_departamento !== v) {
        seleccion.id_persona_asignada = null;
        const tp = $('#txt-persona', contenido);
        if (tp) tp.innerHTML = 'Sin asignar<small>Alguien del departamento se lo quedará</small>';
      }
    }

    const g = grupos.find((x) => x.id_departamento === v);
    $('#txt-grupo', contenido).innerHTML =
      `${esc(g?.nombre || 'Departamento')}<small>De quién es el trabajo</small>`;
  };

  /* --- persona (opcional, y sólo del departamento elegido) --------- */
  const bPersona = $('#b-persona', contenido);
  if (bPersona) bPersona.onclick = async () => {
    if (!seleccion.id_grupo_asignado) {
      mensaje('Elige antes el departamento.', 'error');
      return;
    }
    const gente = (app.catalogo?.usuarios || [])
      .filter((u) => u.id_departamento === seleccion.id_grupo_asignado);

    if (!gente.length) {
      mensaje('Ese departamento no tiene a nadie dado de alta.', 'error');
      return;
    }

    const v = await elegirBuscando({
      titulo: 'Quién lo hace',
      opciones: [
        { valor: 'nadie', texto: 'Sin asignar todavía',
          detalle: 'Se lo quedará alguien del departamento', icono: 'personas', destacado: true },
        ...gente.map((u) => ({
          valor: 'p:' + u.id_login,
          texto: u.nombre_completo + (u.id_login === app.usuario.id_login ? ' (yo)' : ''),
          detalle: u.correo,
        })),
      ],
      valor: seleccion.id_persona_asignada ? 'p:' + seleccion.id_persona_asignada : 'nadie',
      marcador: `Buscar entre ${gente.length}`,
    });
    if (v === null) return;

    seleccion.id_persona_asignada = v.startsWith('p:') ? parseInt(v.slice(2), 10) : null;
    const p = gente.find((u) => u.id_login === seleccion.id_persona_asignada);
    $('#txt-persona', contenido).innerHTML = p
      ? `${esc(p.nombre_completo)}<small>Recibirá un aviso</small>`
      : 'Sin asignar<small>Alguien del departamento se lo quedará</small>';
  };

  /* --- fotos ------------------------------------------------------ */
  const cajaFotos = $('#fotos', contenido);
  function pintarFotos() {
    cajaFotos.innerHTML = [
      ...seleccion.fotos.map((f, i) => `
        <div class="foto">
          <img src="${esc(f.datos)}" alt="Foto ${i + 1}">
          <button type="button" class="foto__quitar" data-quitar="${i}" aria-label="Quitar">${icono('cerrar')}</button>
        </div>`),
      `<button type="button" class="foto foto--anadir" data-anadir aria-label="Añadir foto">${icono('camara')}</button>`,
    ].join('');
  }
  cajaFotos.onclick = async (e) => {
    const q = e.target.closest('[data-quitar]');
    if (q) { seleccion.fotos.splice(parseInt(q.dataset.quitar, 10), 1); pintarFotos(); return; }
    if (e.target.closest('[data-anadir]')) {
      const nuevas = await elegirFotos();
      seleccion.fotos.push(...nuevas);
      if (seleccion.fotos.length > 8) seleccion.fotos.length = 8;
      pintarFotos();
    }
  };
  pintarFotos();

  /* --- pie de red -------------------------------------------------- */
  const pie = $('#pie-red', contenido);
  pie.innerHTML = hayRed()
    ? ''
    : `${icono('sinred')} Sin conexión: el parte se guardará y se enviará solo.`;

  /* --- guardar ----------------------------------------------------- */
  form.onsubmit = async (e) => {
    e.preventDefault();
    const boton = $('#b-guardar', contenido);
    const titulo = $('#titulo', contenido).value.trim();
    const detalle = $('#detalle', contenido).value.trim();

    if (!titulo && !detalle && !seleccion.id_elemento && !seleccion.id_familia) {
      mensaje('Indica al menos qué falla o escribe un resumen.', 'error');
      $('#titulo', contenido).focus();
      return;
    }

    // Lo que el administrador ha marcado como obligatorio
    const exigidos = ajuste('alta_obligatorios', []) || [];
    const comprobar = [
      ['elemento',  !seleccion.id_elemento && !seleccion.id_familia, 'Indica qué falla.', '#b-elemento'],
      ['ubicacion', !seleccion.zona.id_rel && !seleccion.zona.z3,    'Indica dónde es.', '#b-zona'],
      ['titulo',    !titulo,                                        'Escribe un resumen.', '#titulo'],
      ['detalle',   !detalle,                                       'Escribe el detalle.', '#detalle'],
      ['foto',      !seleccion.fotos.length,                        'Hace falta al menos una foto.', '#fotos'],
      ['asignado',  !seleccion.id_persona_asignada && !seleccion.id_grupo_asignado,
                    'Indica a quién va.', '#b-persona'],
    ];
    /* El grupo es obligatorio siempre, no por configuración: un parte sin
       departamento no aparece en la cola de nadie y se queda muerto. La
       persona concreta puede venir después, o cogérselo alguien. */
    if (!seleccion.id_grupo_asignado) {
      mensaje('Indica a qué departamento va el parte.', 'error');
      contenido.querySelector('#b-grupo')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    for (const [clave, faltaEste, texto, foco] of comprobar) {
      if (exigidos.includes(clave) && faltaEste) {
        mensaje(texto, 'error');
        contenido.querySelector(foco)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
    }

    const falta = camposDelTipo().find(
      (c) => c.obligatorio && !String(seleccion.campos[c.clave] || '').trim());
    if (falta) {
      mensaje(`Falta rellenar «${falta.etiqueta}».`, 'error');
      const el = contenido.querySelector(`[data-campo="${falta.clave}"]`);
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    boton.disabled = true;
    boton.innerHTML = `${icono('sync', 'girando')}Guardando…`;

    const el = app.catalogo?.elementos?.find((x) => x.id_elemento === seleccion.id_elemento);
    const pr = app.catalogo?.prioridades?.find((x) => x.id_prioridad === seleccion.id_prioridad);
    const pe = app.catalogo?.usuarios?.find((x) => x.id_login === seleccion.id_persona_asignada);

    try {
      const parte = await sync.crearTicket({
        titulo: titulo || [seleccion.nombre_tipo, seleccion.zona.ruta].filter(Boolean).join(' · '),
        detalle,
        id_elemento: seleccion.id_elemento,
        id_familia: seleccion.id_familia,
        id_prioridad: seleccion.id_prioridad,
        id_rel_zona: seleccion.zona.id_rel,
        // Si se ha pedido publicarlo más adelante, va con su fecha.
        visible_desde: (() => {
          const sw = $('#n-programar', contenido);
          const f = $('#n-visible', contenido)?.value;
          const hora = $('#n-visible-hora', contenido)?.value || '08:00';
          // La hora que se escribe es la de aquí; se guarda en universal.
          return (sw?.getAttribute('aria-checked') === 'true' && f)
            ? aUtcDesdeLocal(f, hora) : null;
        })(),
        zona1: seleccion.zona.z1, zona2: seleccion.zona.z2,
        zona3: seleccion.zona.z3, zona4: seleccion.zona.z4,
        id_persona_asignada: seleccion.id_persona_asignada,
        id_grupo_asignado: seleccion.id_grupo_asignado,
        campos: seleccion.campos,
        // datos sólo para pintar el provisional
        elemento_nombre: el?.nombre,
        prioridad_nombre: pr?.nombre,
        prioridad_color: pr?.color,
        zona_ruta: seleccion.zona.ruta,
        asignado_nombre: pe?.nombre_completo,
        creado_por: [app.usuario.nombre, app.usuario.apellido1].filter(Boolean).join(' '),
      }, seleccion.fotos);

      tono(hayRed() ? 'ok' : 'guardado');
      mensaje(hayRed() ? 'Parte creado.' : 'Parte guardado. Se enviará al recuperar la conexión.', 'ok');
      ir(`/ticket/${parte.id_ticket}`, { reemplazar: true });
    } catch (err) {
      boton.disabled = false;
      boton.innerHTML = `${icono('guardar')}Crear parte`;
      tono('error');
      mensaje(err.message, 'error');
    }
  };

  setTimeout(() => $('#titulo', contenido)?.focus({ preventScroll: true }), 250);
  return {};
}
