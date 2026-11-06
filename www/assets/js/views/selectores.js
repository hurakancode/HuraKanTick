/* =====================================================================
   Selectores de catálogo grande.
   La instalación real tiene 313 tipos de avería y 1.285 ubicaciones, así
   que todo se elige buscando, no navegando.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegirBuscando, ICONOS_ELEMENTO,
} from '../ui.js';
import { app, ajuste, modulo } from '../app.js';

/* ---------------------------------------------------------------- */
/* Tipo de avería                                                   */
/* ---------------------------------------------------------------- */
/**
 * Elegir qué falla.
 *
 * Con 313 tipos, pedir que se busque a ciegas sólo funciona si ya sabes
 * el nombre exacto. Recepción no lo sabe: sabe que «el baño de la 214
 * pierde agua». Por eso se elige primero la familia —quince botones con
 * icono— y dentro se concreta si hace falta.
 *
 * Devuelve { id_familia, id_elemento, nombre } o null.
 * id_elemento puede venir vacío: quedarse en la familia es una respuesta
 * legítima, y muchas veces la única que quien avisa puede dar.
 */
/* Con las familias desactivadas se ofrece la lista completa de tipos en
   vez de la rejilla por oficios: es lo que promete el interruptor. */
export async function elegirElemento(actual = {}) {
  // Con el interruptor apagado se ignoran, aunque existan: es lo que
  // promete «Familias de elementos» en Configuración.
  const familias = modulo('familias') ? (app.catalogo?.familias || []) : [];
  const elementos = app.catalogo?.elementos || [];

  // Sin familias configuradas se vuelve a la lista plana.
  if (!familias.length) {
    const v = await listaPlana(actual.id_elemento);
    if (v === null) return null;
    const el = elementos.find((e) => e.id_elemento === v);
    return { id_familia: el?.id_familia ?? null, id_elemento: v || null, nombre: el?.nombre || '' };
  }

  return new Promise((resolve) => {
    let resuelto = false;

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <button type="button" class="btn btn--contorno btn--bloque btn--s" data-buscar-todo
              style="margin-bottom:14px">
        ${icono('buscar')}Buscar entre los ${elementos.length} tipos
      </button>

      <div class="rotulo" style="margin:0 2px 8px">O elige el área</div>
      <div class="rejilla-elementos">
        ${familias.map((f) => `
          <button type="button" class="elemento" data-fam="${f.id_familia}"
                  aria-pressed="${actual.id_familia === f.id_familia}"
                  style="${actual.id_familia === f.id_familia ? `border-color:${esc(f.color)};background:${esc(f.color)}14` : ''}">
            <span style="color:${esc(f.color)}">${icono(iconoFamilia(f.icono))}</span>
            <span>${esc(f.nombre)}</span>
            ${f.clase !== 'averia' ? `<span class="rotulo" style="font-size:9px;letter-spacing:.05em">
              ${f.clase === 'peticion' ? 'petición' : 'tarea'}</span>` : ''}
          </button>`).join('')}
      </div>`;

    cuerpo.querySelector('[data-buscar-todo]').onclick = async () => {
      const v = await listaPlana(actual.id_elemento);
      if (v === null) return;
      if (v === -1) {
        const otros = familiaOtros();
        resuelto = true;
        resolve({ id_familia: otros.id_familia, id_elemento: null, nombre: otros.nombre });
        cerrarHoja();
        return;
      }
      const el = elementos.find((e) => e.id_elemento === v);
      resuelto = true;
      resolve({ id_familia: el?.id_familia ?? null, id_elemento: v || null, nombre: el?.nombre || '' });
      cerrarHoja();
    };

    cuerpo.querySelectorAll('[data-fam]').forEach((b) => {
      b.onclick = async () => {
        const idF = parseInt(b.dataset.fam, 10);
        const fam = familias.find((f) => f.id_familia === idF);
        const dentro = elementos.filter((e) => e.id_familia === idF);

        // Familia sin elementos, o con muy pocos: no merece un segundo
        // paso, se resuelve aquí mismo.
        if (dentro.length === 0) {
          resuelto = true;
          resolve({ id_familia: idF, id_elemento: null, nombre: fam.nombre });
          cerrarHoja();
          return;
        }

        const v = await elegirBuscando({
          titulo: fam.nombre,
          opciones: [
            ...dentro.map((e) => ({
              valor: e.id_elemento, texto: e.nombre, extra: e.abreviatura,
              destacado: (e.usos || 0) > 0,
            })),
          ],
          valor: actual.id_elemento,
          marcador: `Buscar entre ${dentro.length}`,
          vacio: 'Nada con ese nombre en ' + fam.nombre,
          siempre: [{ valor: 0, texto: 'Sin concretar más',
                      detalle: 'Basta con «' + fam.nombre + '»', icono: 'info' }],
        });
        if (v === null) return;

        const el = dentro.find((e) => e.id_elemento === v);
        resuelto = true;
        resolve({
          id_familia: idF,
          id_elemento: v || null,
          nombre: el ? el.nombre : fam.nombre,
        });
        cerrarHoja();
      };
    });

    hoja({
      titulo: '¿Qué falla?',
      cuerpo,
      alCerrar: () => { if (!resuelto) resolve(null); },
    });
  });
}

/** Búsqueda directa sobre los 313, para quien sabe el nombre. */
function listaPlana(actual) {
  const lista = app.catalogo?.elementos || [];
  return elegirBuscando({
    titulo: 'Buscar tipo',
    opciones: [
      { valor: 0, texto: 'Sin especificar', icono: 'info' },
      ...lista.map((el, i) => ({
        valor: el.id_elemento,
        texto: el.nombre || el.nombre_elemento,
        extra: el.abreviatura || el.abreviatura_elemento || '',
        detalle: nombreFamilia(el.id_familia),
        icono: ICONOS_ELEMENTO[el.icono] || (/^fa-[0-9a-f]{1,5}$/i.test(el.icono || '') ? el.icono : 'llaveinglesa'),
        destacado: i < 8 && (el.usos || 0) > 0,
      })),
    ],
    valor: actual,
    marcador: 'Buscar entre ' + lista.length + ' tipos',
    vacio: 'No hay ningún tipo con ese nombre.',
    // Si no está en la lista, «Otros»: el parte se da igual y el detalle
    // se cuenta en la descripción.
    siempre: familiaOtros() ? [{
      valor: -1, texto: 'Otros', icono: 'info',
      detalle: 'No está en la lista: cuéntalo en la descripción',
    }] : [],
  });
}

/** La familia «Otros», si existe: donde va lo que no encaja en ninguna. */
function familiaOtros() {
  return app.catalogo?.familias?.find((f) => /^otros$/i.test((f.nombre || '').trim())) || null;
}

function nombreFamilia(id) {
  if (!id) return '';
  return app.catalogo?.familias?.find((f) => f.id_familia === id)?.nombre || '';
}

/** Los iconos de familia usan los nombres del catálogo, no los internos. */
function iconoFamilia(nombre) {
  return ICONOS_ELEMENTO[nombre] || nombre || 'llaveinglesa';
}

/* ---------------------------------------------------------------- */
/* Ubicación                                                        */
/* ---------------------------------------------------------------- */
/**
 * Devuelve {id_rel, z1..z4, ruta} o null.
 *
 * Dos caminos: escribir («101» encuentra «Planta 01 › Habitaciones › 101»)
 * o ir bajando por niveles. El primero es el rápido y el que se usa el
 * 95 % de las veces; el segundo hace falta cuando la combinación aún no
 * existe.
 */
/**
 * ¿Puede esta persona crear ubicaciones nuevas?
 *
 * Es cómodo dejarlo abierto, pero con mucha gente el catálogo se llena
 * de duplicados —«101», «Hab 101», «101 baño»— y luego no hay quien
 * busque. Por eso lo decide el administrador.
 */
function puedeCrearZona() {
  if (!ajuste('permitir_zona_nueva', true)) return false;
  const quien = ajuste('quien_crea_zona', 'responsable');
  const u = app.usuario || {};
  if (quien === 'nadie') return false;
  if (quien === 'todos') return true;
  if (quien === 'admin') return !!u.es_admin;
  if (quien === 'responsable') return !!(u.es_admin || u.es_responsable);
  return u.rol !== 'reportador';        // «tecnico»
}

export function elegirUbicacion(actual = {}, niveles) {
  return new Promise((resolve) => {
    let resuelto = false;
    const zonas = app.catalogo?.zonas || {};
    const combos = zonas.combinaciones || [];
    niveles = niveles || app.catalogo?.niveles_zona || ['Planta', 'Zona', 'Lugar', 'SubLugar'];

    // En qué orden se pregunta. No coincide con el de la base: se
    // pregunta antes por la Zona («Habitaciones», «Cocinas») que por la
    // planta, porque reduce mucho más y es como se piensa al dar un
    // aviso — primero qué clase de sitio, después dónde exactamente.
    const orden = app.catalogo?.orden_zona || [2, 1, 3, 4];

    const sel = {
      id_rel: actual.id_rel ?? null,
      z1: actual.z1 ?? null, z2: actual.z2 ?? null,
      z3: actual.z3 ?? null, z4: actual.z4 ?? null,
      ruta: actual.ruta || '',
    };

    const nombreZona = (n, id) => (zonas[`zona${n}`] || []).find((z) => z.id === id)?.nombre || '';

    const rutaDe = (o) => [1, 2, 3, 4]
      .map((n) => nombreZona(n, o[`z${n}`]))
      .filter(Boolean).join(' › ');

    /**
     * Valores posibles de un nivel, dado lo ya elegido.
     *
     * Se filtra por TODO lo elegido, no sólo por los niveles anteriores:
     * así elegir «Habitaciones» primero deja sólo las plantas que tienen
     * habitaciones, y elegir después la planta deja sólo sus números.
     */
    const compatibles = (n) => {
      const todas = zonas[`zona${n}`] || [];

      // El primer nivel del orden se ofrece entero, aunque algún valor
      // no tenga ninguna ubicación registrada todavía. Es la puerta de
      // entrada: esconder «Cocinas» porque nadie ha dado de alta una
      // ubicación ahí la deja inalcanzable, y entonces no se puede ni
      // crear la primera.
      const esEntrada = orden[0] === n && ![1, 2, 3, 4].some((k) => k !== n && sel[`z${k}`]);
      if (esEntrada) return todas;

      const filtrados = combos.filter((c) =>
        [1, 2, 3, 4].every((k) => k === n || !sel[`z${k}`] || c[`z${k}`] === sel[`z${k}`]));
      const usados = new Set(filtrados.map((c) => c[`z${n}`]).filter(Boolean));
      return usados.size ? todas.filter((z) => usados.has(z.id)) : todas;
    };

    const cuerpo = document.createElement('div');
    const pie = document.createElement('div');
    pie.style.display = 'contents';

    function pintarPie() {
      const n = coincidencias().length;
      const exacta = !!sel.id_rel;
      pie.innerHTML = `
        <button type="button" class="btn btn--contorno" data-vaciar>Sin ubicación</button>
        ${exacta || n === 0
          ? `<button type="button" class="btn btn--principal" data-ok ${n === 0 && !exacta ? 'disabled' : ''}>
               ${exacta ? 'Usar esta' : 'Elige algo'}</button>`
          : `<button type="button" class="btn btn--principal" data-elegir>Elegir entre ${n}</button>`}`;

      const vaciar = $('[data-vaciar]', pie);
      if (vaciar) vaciar.onclick = () => {
        resuelto = true;
        resolve({ id_rel: null, z1: null, z2: null, z3: null, z4: null, ruta: '' });
        cerrarHoja();
      };
      const ok = $('[data-ok]', pie);
      if (ok) ok.onclick = () => {
        if (!sel.id_rel) return;
        resuelto = true;
        resolve({ ...sel, ruta: sel.ruta || rutaDe(sel) });
        cerrarHoja();
      };
      const elegir2 = $('[data-elegir]', pie);
      if (elegir2) elegir2.onclick = elegirEntreCoincidencias;
    }

    /**
     * Con la selección a medias hay varias que encajan: se ofrecen para
     * rematar. Es lo que se espera al poner sólo «Planta 06» — no que no
     * pase nada, sino ver qué hay en esa planta.
     */
    async function elegirEntreCoincidencias() {
      const encajan = coincidencias();
      const v = await elegirBuscando({
        titulo: 'Cuál de estas',
        opciones: encajan.map((c) => ({ valor: c.id_rel, texto: c.ruta, icono: 'ubicacion' })),
        marcador: `Buscar entre ${encajan.length}`,
        limite: 80,
      });
      if (v === null) return;
      const c = encajan.find((x) => x.id_rel === v);
      if (c) {
        Object.assign(sel, { id_rel: c.id_rel, z1: c.z1, z2: c.z2, z3: c.z3, z4: c.z4, ruta: c.ruta });
        resuelto = true;
        resolve({ ...sel });
        cerrarHoja();
      }
    }

    function pintar() {
      const rutaActual = sel.ruta || rutaDe(sel);
      const algoElegido = [1, 2, 3, 4].some((n) => sel[`z${n}`]);
      const encajan = coincidencias().length;

      cuerpo.innerHTML = `
        <div class="aviso ${rutaActual ? 'aviso--info' : 'aviso--senal'}" style="margin-bottom:14px">
          ${icono('ubicacion')}<div>
            ${esc(rutaActual || 'Todavía no has elegido ubicación')}
            ${!sel.id_rel && encajan > 1 ? `<div style="margin-top:5px;font-size:12.5px">
              ${encajan} ubicaciones encajan con lo elegido. Sigue bajando o
              toca «Elegir entre ${encajan}».</div>` : ''}
            ${!sel.id_rel && algoElegido && encajan === 0 ? `<div style="margin-top:5px;font-size:12.5px">
              Ninguna ubicación existente encaja con esa combinación.</div>` : ''}
          </div>
        </div>

        <button type="button" class="btn btn--contorno btn--bloque btn--s" data-buscar
                style="margin-bottom:6px">
          ${icono('buscar')}Buscar por nombre o número
        </button>
        <div class="campo__ayuda" style="text-align:center;margin-bottom:16px">
          Si sabes la habitación, escríbela. Hay ${combos.length} ubicaciones.
        </div>

        <div class="rotulo" style="margin:0 2px 8px">O baja por niveles</div>

        ${orden.map((n, i) => {
          const nombre = niveles[n - 1] || 'Nivel ' + n;
          const elegido = nombreZona(n, sel[`z${n}`]);
          const cuantos = compatibles(n).length;
          // Un nivel sin opciones compatibles se deja ver pero apagado:
          // esconderlo haría que la lista bailara al elegir.
          const vacio = cuantos === 0;
          // Contenedor <div> y no <button type="button">: la X de quitar es un botón y
          // un botón dentro de otro es HTML inválido — el navegador
          // cierra el exterior y la X se queda fuera de su manejador, de
          // modo que no hacía nada.
          return `
            <div class="fila" data-nivel="${n}" role="button" tabindex="${vacio ? -1 : 0}"
                 aria-disabled="${vacio}"
                 style="border-radius:var(--r-m);border:1.5px solid ${elegido ? 'var(--marca)' : 'var(--borde)'};
                        margin-bottom:7px;cursor:${vacio ? 'default' : 'pointer'};
                        ${vacio ? 'opacity:.45;' : ''}
                        ${elegido ? 'background:var(--marca-tenue)' : ''}">
              <span class="avatar" style="background:${elegido ? 'var(--marca)' : 'var(--superficie-3)'};
                                          color:${elegido ? '#fff' : 'var(--tinta-3)'};
                                          font-size:12px;font-weight:700">${i + 1}</span>
              <span class="fila__texto">${esc(nombre)}
                <small>${esc(elegido || (vacio ? 'ninguna registrada · créala abajo' : `${cuantos} opciones`))}</small></span>
              ${elegido ? `<button type="button" class="icono-btn" data-quitar="${n}"
                            aria-label="Quitar ${esc(nombre)}"
                            style="min-width:42px;height:42px;color:var(--tinta-3)">${icono('cerrar')}</button>`
                        : icono('flecha', 'fila__flecha')}
            </div>`;
        }).join('')}

        ${algoElegido ? `
          <button type="button" class="btn btn--texto btn--bloque btn--s" data-limpiar>
            Empezar de nuevo
          </button>` : ''}

        ${puedeCrearZona() ? `
          <button type="button" class="btn btn--contorno btn--bloque btn--s" data-nueva
                  style="margin-top:6px">
            ${icono('mas')}Dar de alta una ubicación nueva
          </button>` : ''}`;

      // Un solo manejador delegado en el cuerpo: más simple y no se
      // pierde nada al repintar.
      cuerpo.onclick = (e) => {
        const q = e.target.closest('[data-quitar]');
        if (q) {
          e.stopPropagation();
          quitarNivel(parseInt(q.dataset.quitar, 10));
          return;
        }
        const b = e.target.closest('[data-nivel]');
        if (b && b.getAttribute('aria-disabled') !== 'true') {
          elegirNivel(parseInt(b.dataset.nivel, 10));
          return;
        }
        if (e.target.closest('[data-buscar]')) { buscarCombinacion(); return; }
        if (e.target.closest('[data-limpiar]')) {
          [1, 2, 3, 4].forEach((n) => { sel[`z${n}`] = null; });
          sel.id_rel = null;
          sel.ruta = '';
          pintar();
          return;
        }
        if (e.target.closest('[data-nueva]')) crearUbicacion();
      };

      pintarPie();
    }

    /** Las combinaciones que encajan con lo elegido hasta ahora. */
    function coincidencias() {
      if (![1, 2, 3, 4].some((n) => sel[`z${n}`])) return [];
      return combos.filter((c) =>
        [1, 2, 3, 4].every((n) => !sel[`z${n}`] || c[`z${n}`] === sel[`z${n}`]));
    }

    /**
     * Recalcula el id_rel.
     *
     * Una selección parcial —sólo la planta, por ejemplo— casi nunca
     * tiene una combinación exacta: en el catálogo real no hay ninguna
     * fila con sólo «Planta 06». Si hay una sola que encaje se toma esa;
     * si hay varias, se deja sin resolver y el botón pasa a ofrecer
     * elegir entre ellas, que es lo que hace falta.
     */
    function resolverCombinacion() {
      const exacta = combos.find((c) =>
        c.z1 === sel.z1 && c.z2 === sel.z2 && c.z3 === sel.z3 && c.z4 === sel.z4);
      if (exacta) {
        sel.id_rel = exacta.id_rel;
        sel.ruta = exacta.ruta;
        return;
      }
      const encajan = coincidencias();
      if (encajan.length === 1) {
        sel.id_rel = encajan[0].id_rel;
        sel.ruta = encajan[0].ruta;
        return;
      }
      sel.id_rel = null;
      sel.ruta = '';
    }

    function quitarNivel(n) {
      // Se limpia también todo lo que se preguntó después.
      //
      // Si no, quitar la planta y dejar la habitación no cambia nada: esa
      // habitación sólo existe en esa planta, así que se resuelve a la
      // misma y parece que el botón no funciona. Quitar un nivel tiene
      // que deshacer también lo que se eligió a partir de él.
      const desde = orden.indexOf(n);
      orden.slice(desde).forEach((k) => { sel[`z${k}`] = null; });
      resolverCombinacion();
      pintar();
    }

    /* --- camino rápido: buscar sobre la ruta completa ---------------- */
    async function buscarCombinacion() {
      const opciones = combos
        .filter((c) => c.ruta)
        .map((c) => ({ valor: c.id_rel, texto: c.ruta, icono: 'ubicacion' }));

      const v = await elegirBuscando({
        titulo: 'Buscar ubicación',
        opciones,
        valor: sel.id_rel,
        marcador: 'Habitación, planta o nombre del sitio',
        vacio: 'No hay ninguna ubicación con ese nombre. Puedes darla de alta abajo.',
        limite: 80,
      });
      if (v === null) return;

      const c = combos.find((x) => x.id_rel === v);
      if (c) {
        Object.assign(sel, { id_rel: c.id_rel, z1: c.z1, z2: c.z2, z3: c.z3, z4: c.z4, ruta: c.ruta });
        resuelto = true;
        resolve({ ...sel });
        cerrarHoja();
      }
    }

    /* --- camino por niveles ----------------------------------------- */
    async function elegirNivel(n) {
      const disponibles = compatibles(n);
      if (!disponibles.length) return;

      const v = await elegirBuscando({
        titulo: niveles[n - 1] || 'Nivel ' + n,
        opciones: [
          { valor: 0, texto: 'Cualquiera', icono: 'info' },
          ...disponibles.map((z) => ({ valor: z.id, texto: z.nombre })),
        ],
        valor: sel[`z${n}`],
        marcador: `Buscar entre ${disponibles.length}`,
      });
      if (v === null) return;

      sel[`z${n}`] = v || null;

      // Lo elegido después en el orden de preguntas deja de valer si ya
      // no encaja: se limpia lo que haya quedado incompatible.
      const posicion = orden.indexOf(n);
      orden.slice(posicion + 1).forEach((k) => {
        if (!sel[`z${k}`]) return;
        const encaja = combos.some((c) =>
          [1, 2, 3, 4].every((j) => !sel[`z${j}`] || c[`z${j}`] === sel[`z${j}`]));
        if (!encaja) sel[`z${k}`] = null;
      });

      resolverCombinacion();
      pintar();
    }

    /* --- alta de ubicación nueva ------------------------------------- */
    function crearUbicacion() {
      if (!hayRed()) {
        mensaje('Para crear una ubicación nueva hace falta conexión.', 'error');
        return;
      }
      const c2 = document.createElement('div');
      c2.innerHTML = orden.map((n) => `
        <div class="campo">
          <label for="nu${n}">${esc(niveles[n - 1] || 'Nivel ' + n)}</label>
          <input class="entrada" type="text" id="nu${n}" autocomplete="off"
                 value="${esc(nombreZona(n, sel[`z${n}`]))}"
                 placeholder="Dejar en blanco si no aplica">
        </div>`).join('') + '<div id="err-nu"></div>';

      const p2 = document.createElement('div');
      p2.style.display = 'contents';
      p2.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                      <button type="button" class="btn btn--principal" data-si>Crear</button>`;

      hoja({
        titulo: 'Nueva ubicación',
        cuerpo: c2, pie: p2,
        alAbrir: (h) => {
          $('[data-no]', h).onclick = () => cerrarHoja();
          $('[data-si]', h).onclick = async () => {
            const datos = {};
            [1, 2, 3, 4].forEach((n) => {
              const v = $(`#nu${n}`, h)?.value.trim();
              if (v) datos[`nombre${n}`] = v;
            });
            if (!Object.keys(datos).length) {
              $('#err-nu', h).innerHTML =
                `<div class="aviso aviso--error">${icono('aviso')}<div>Escribe al menos un nivel.</div></div>`;
              return;
            }
            try {
              const r = await api.crearZona(datos);
              app.catalogo.zonas = r.zonas;
              await db.ajuste('catalogo', app.catalogo);
              resuelto = true;
              cerrarHoja();          // cierra la hoja de alta
              cerrarHoja();          // y la de ubicación que hay debajo
              const c = r.zonas.combinaciones.find((x) => x.id_rel === r.id_rel);
              resolve({
                id_rel: r.id_rel,
                z1: c?.z1 ?? null, z2: c?.z2 ?? null, z3: c?.z3 ?? null, z4: c?.z4 ?? null,
                ruta: r.ruta || '',
              });
              mensaje('Ubicación creada.', 'ok');
            } catch (e) {
              $('#err-nu', h).innerHTML =
                `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
            }
          };
        },
      });
    }

    pintar();

    hoja({
      titulo: 'Ubicación',
      cuerpo, pie,
      alCerrar: () => { if (!resuelto) resolve(null); },
    });
  });
}
