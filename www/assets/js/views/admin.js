/* =====================================================================
   Administración.

   Separada de los ajustes personales a propósito: aquí se decide cómo
   funciona el sistema para todos, y eso no debe estar a un toque de
   distancia de «cambiar mi contraseña».
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { elegirIconoFA } from './iconografia.js';
import { htmlDeBusqueda, engancharBusqueda, olvidarAjustes } from '../indice-ajustes.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, elegirBuscando,
  confirmar, fechaLarga, haceCuanto,
  buscadorFilas, plazoLargo,
} from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { modulo } from '../app.js';
import { pintarCabecera, ir, app, ajuste } from '../app.js';
import { tono } from '../sonido.js';

/* Qué iconos sugerir para una familia, por lo que dice su nombre. */
const PISTAS_FAMILIA = [
  [/lencer|textil|s[áa]bana|toalla/i, ['shirt', 'bed', 'basket-shopping']],
  [/limpieza|repaso/i,                ['broom', 'spray-can-sparkles', 'soap']],
  [/petici|cliente|hu[ée]sped/i,      ['bell-concierge', 'user', 'hand-holding-heart']],
  [/inform[áa]tica|red/i,             ['wifi', 'network-wired', 'computer']],
  [/fontaner|agua/i,                  ['droplet', 'faucet', 'shower']],
  [/electric/i,                       ['bolt', 'plug', 'lightbulb']],
  [/climatiz|aire/i,                  ['fan', 'wind', 'temperature-half']],
  [/carpinter|obra/i,                 ['hammer', 'trowel-bricks', 'screwdriver']],
  [/mobiliario|mueble/i,              ['chair', 'couch', 'bed']],
  [/cerrajer|acceso|llave/i,          ['key', 'lock', 'door-closed']],
  [/televis|sonido|tv/i,              ['tv', 'volume-high', 'satellite-dish']],
  [/cocina|office|a&b/i,              ['utensils', 'kitchen-set', 'blender']],
  [/seguridad|emergencia/i,           ['shield-halved', 'fire-extinguisher', 'triangle-exclamation']],
  [/zona|com[úu]n|exterior/i,         ['tree', 'person-swimming', 'umbrella-beach']],
  [/equipamiento|habitaci/i,          ['bed', 'suitcase-rolling', 'vault']],
  [/jardin|planta|riego/i,            ['seedling', 'tree', 'leaf']],
  [/ascensor|elevador/i,              ['elevator', 'arrows-up-down', 'building']],
];

/** El icono que le tocaría por su familia, para saber si tiene uno propio. */
function iconoDeSuFamilia(el) {
  const f = (app.catalogo?.familias || []).find((x) => x.id_familia === el?.id_familia);
  return f?.icono || '';
}

function sugerenciasPorNombre(nombre) {
  for (const [patron, iconos] of PISTAS_FAMILIA) {
    if (patron.test(nombre)) return iconos;
  }
  return ['screwdriver-wrench', 'wrench', 'toolbox'];
}

/* El valor que tienen ahora los ajustes, de la última carga. Va aquí
   arriba y no dentro: declarado junto a la función que lo usa, la
   pantalla reventaba antes de pintar nada. */
let ajustesCargados = {};
const ajusteValor = (clave) => ajustesCargados[clave];

/* Los datos que se pueden exigir al dar un parte. */
const CAMPOS_ALTA = {
  elemento:  'Qué falla',
  ubicacion: 'Dónde es',
  titulo:    'Resumen',
  detalle:   'Detalle',
  foto:      'Una foto',
  asignado:  'A quién va',
};


/**
 * Llevar derecho a lo que se buscó en Ajustes, y resaltarlo un momento.
 *
 * Vale para cualquier pantalla de administración, no sólo para la de
 * Configuración: «Forzar la actualización de todos» vive en el menú, y
 * antes el buscador te dejaba en Administración y había que volver a
 * buscarlo a ojo, que es justo lo que el buscador tenía que ahorrar.
 *
 * El desplazamiento va con un pequeño retraso a propósito: al terminar de
 * pintar, el enrutador sube la pantalla arriba del todo, y un movimiento
 * hecho antes se pierde.
 */
function irAlBuscado(contenido) {
  try {
    const destino = sessionStorage.getItem('hurakantick_ir_ajuste');
    if (!destino) return;
    sessionStorage.removeItem('hurakantick_ir_ajuste');

    const f = contenido.querySelector(`[data-fila-clave="${CSS.escape(destino)}"]`);
    if (!f) return;

    setTimeout(() => {
      f.scrollIntoView({ block: 'center' });
      f.classList.add('fila--resaltada');
    }, 120);
  } catch { /* sin sessionStorage: se queda arriba */ }
}

export async function vistaAdmin({ contenido, args }) {
  const seccion = args[0] || '';

  if (!app.usuario.es_admin) {
    pintarCabecera(cabeceraVolver('Administración'));
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Esta pantalla es sólo para administradores.</div></div>`;
    return {};
  }

  if (!hayRed()) {
    pintarCabecera(cabeceraVolver('Administración'));
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>La administración necesita conexión con el servidor.</div></div>`;
    return {};
  }

  const secciones = {
    '':         { titulo: 'Administración',   pintar: menu },
    claves:     { titulo: 'Contraseñas',      pintar: claves },
    ajustes:    { titulo: 'Configuración',    pintar: () => ajustes() },
    /* La vista smartphone tiene entrada propia: dentro de Configuración era
       la última sección de una pantalla muy larga y no había quien la
       encontrara. Es la misma pantalla, con sólo esa parte. */
    vista:      { titulo: 'Vista smartphone', pintar: () => ajustes(['vista']) },
    seguridad:  { titulo: 'Seguridad',        pintar: () => ajustes(['seguridad']) },
    familias:   { titulo: 'Familias',         pintar: familias },
    elementos:  { titulo: 'Tipos de avería',  pintar: elementos },
    zonas:      { titulo: 'Ubicaciones',      pintar: zonas },
    campos:     { titulo: 'Campos por tipo',  pintar: campos },
    atajos:     { titulo: 'Atajos del inicio',pintar: atajos },
    copias:     { titulo: 'Copias de seguridad', pintar: copias },
    integridad: { titulo: 'Integridad', pintar: integridad },
  };
  const s = secciones[seccion] || secciones[''];
  pintarCabecera(cabeceraVolver(s.titulo, seccion ? 'Administración' : app.catalogo?.organizacion || ''));
  contenido.innerHTML = bloqueCarga(3);
  await s.pintar();
  irAlBuscado(contenido);
  return {};

  /* ================================================================ */
  /* Menú                                                             */
  /* ================================================================ */
  async function menu() {
    const fila = (ruta, ico, titulo, detalle) => `
      <button type="button" class="fila" data-ir="${ruta}">
        ${icono(ico)}
        <span class="fila__texto">${esc(titulo)}<small>${esc(detalle)}</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>`;

    contenido.innerHTML = `
      ${app.usuario.es_superadmin ? `
        <div class="seccion"><h2>Hoteles</h2></div>
        <div class="filas">
          ${fila('/hoteles', 'panel', 'Gestión de hoteles',
                 'Crear, cambiar de hotel y copiar ubicaciones')}
        </div>` : ''}

      <div class="seccion"><h2>Personas</h2></div>
      <div class="filas">
        ${fila('/equipo', 'personas', 'Equipo',
               'Alta, editar correo, permisos, grupo y borrado definitivo')}
        ${fila('/departamentos', 'personas', 'Departamentos',
               'Comunes a la cadena · cada hotel elige los que usa')}
        ${fila('/admin/claves', 'llave', 'Contraseñas olvidadas',
               'Entregar un código a quien no puede entrar')}
        ${fila('/seguridad', 'aviso', 'Registro de seguridad',
               'Quién entra, desde dónde y qué cambia')}
      </div>

      <div class="seccion"><h2>Catálogos</h2></div>
      <div class="filas">
        ${fila('/admin/elementos', 'llaveinglesa', 'Tipos de avería',
               'Crear, editar, deshabilitar · 313 tipos')}
        ${fila('/admin/familias', 'panel', 'Familias',
               'Agrupar los tipos por oficio y decidir quién los lleva')}
        ${fila('/admin/zonas', 'ubicacion', 'Ubicaciones',
               'Plantas, zonas, lugares y sublugares')}
        ${(app.usuario.es_cadena || ajuste('guia_api_todos', false)) ? `${fila('/guia-api', 'codigo', 'Guía de la API',
          'Cómo conectar otro programa con el sistema')}` : ''}
        ${app.usuario.es_cadena ? `${fila('/admin/campos', 'nota', 'Campos por tipo de avería',
               'El formulario propio del aire acondicionado y similares')}` : ''}
      </div>

      <div class="seccion"><h2>Consultar</h2></div>
      <div class="filas">
        ${fila('/informes', 'panel', 'Informes',
               'Cuántas averías, dónde, de qué tipo y cuánto se tardó')}
        ${fila('/unidades', 'herramientas', 'Números de serie',
               'Localizar un aparato y ver su historia')}
        ${fila('/instrucciones', 'nota', 'Instrucciones',
               'Cómo se hacen las cosas · se leen sin cobertura')}
      </div>

      <div class="seccion"><h2>El sistema</h2></div>
      <div class="filas">
        ${fila('/admin/ajustes', 'ajustes', 'Configuración',
               'Qué funciones están activas, quién ve qué, avisos y mantenimiento')}
        ${fila('/admin/vista', 'movil', 'Vista smartphone',
               'Ver la aplicación dentro de un teléfono en ordenadores y tabletas')}
        ${fila('/errores', 'aviso', 'Errores',
               app.catalogo?.errores_sin_ver
                 ? `${app.catalogo.errores_sin_ver} sin revisar`
                 : 'Lo que ha fallado, para poder arreglarlo')}
        ${fila('/admin/seguridad', 'candado', 'Seguridad',
               'Intentos antes de bloquear, sesiones y enlaces de auto-entrada')}
        ${fila('/admin/atajos', 'banderin', 'Atajos del inicio',
               'Filtros con nombre en la pantalla principal')}
        ${fila('/mantenimiento', 'calendario', 'Mantenimiento programado',
               'Extintores, filtros, legionela: lo que toca aunque nadie avise')}
        ${app.usuario.es_admin ? `
        <button type="button" class="fila" data-version-nueva data-fila-clave="version_nueva">
          ${icono('info')}
          <span class="fila__texto">¿Hay una versión nueva?
            <small data-version-nueva-texto>Pulsa para preguntar al repositorio público</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>` : ''}
        ${app.usuario.es_admin ? `
        <button type="button" class="fila" data-renovar data-fila-clave="forzar_actualizacion">
          ${icono('sync')}
          <span class="fila__texto">Forzar la actualización de todos
            <small>Cada aparato sube lo pendiente, vacía lo guardado y vuelve a entrar</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>` : ''}
        ${app.usuario.es_admin ? `
        <button type="button" class="fila" data-probar-teams data-fila-clave="teams_activo">
          ${icono('chat')}
          <span class="fila__texto">Comprobar la copia a Teams
            <small>Dice en qué paso se rompe, para pasárselo a tu informático</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>` : ''}
        ${app.usuario.es_admin ? fila('/personal', 'personas', 'Importar / Exportar personas',
               'Dar de alta a mucha gente de una vez con ficheros de texto') : ''}
        ${modulo('copias') ? fila('/admin/copias', 'archivo', 'Copias de seguridad',
               'Descargar y conservar una copia completa') : ''}
        ${fila('/admin/integridad', 'candado', 'Integridad',
               'Comprobar que los datos, los ficheros y el servidor están en su sitio')}
      </div>

      <div class="aviso aviso--info" style="margin-top:18px">${icono('info')}
        <div>Lo que desactives en Configuración desaparece de la aplicación para
        todo el mundo. Nada se borra: al volver a activarlo, los datos siguen ahí.</div>
      </div>`;

    /* Comprobar Teams.
       Primero sólo Azure —que es lo que falla el 90% de las veces, por el
       secreto caducado— y después, si eso va, con una persona de verdad,
       que es el único modo de saber si la aplicación está instalada para
       ella. */
    const bTeams = $('[data-probar-teams]', contenido);
    if (bTeams) bTeams.onclick = async () => {
      bTeams.style.opacity = '.6';
      let r;
      try { r = await api.probarTeams(); }
      catch (e) { bTeams.style.opacity = ''; mensaje(e.message, 'error'); return; }
      bTeams.style.opacity = '';

      const pasos = (r.pasos || []).map((p) =>
        `${p.bien ? '✓' : '✗'} ${esc(p.paso)}${p.detalle ? `<br><small style="color:var(--peligro)">${esc(p.detalle)}</small>` : ''}`
      ).join('<br>');

      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `
        <div class="aviso aviso--${r.bien ? 'ok' : 'error'}">${icono(r.bien ? 'ok' : 'aviso')}
          <div>${esc(r.mensaje || '')}</div></div>
        <p style="font-size:14px;line-height:1.9;margin:10px 2px">${pasos || 'Sin pasos.'}</p>
        <dl class="datos">
          <div class="datos__celda"><dt>Por mandar</dt><dd>${r.cola?.pendientes ?? 0}</dd></div>
          <div class="datos__celda"><dt>Ya mandados</dt><dd>${r.cola?.enviados ?? 0}</dd></div>
          <div class="datos__celda"><dt>Perdidos</dt><dd>${r.cola?.fallidos ?? 0}</dd></div>
        </dl>
        ${r.bien ? `
          <p style="font-size:13px;color:var(--tinta-3);margin:10px 2px">
            Azure contesta. Lo que esto NO dice todavía es si la aplicación de
            HuraKan Tick está instalada para cada persona: para eso hay que probar con
            alguien concreto.</p>` : ''}`;

      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `
        <button type="button" class="btn btn--contorno" data-cerrar>Cerrar</button>
        ${r.bien ? '<button type="button" class="btn btn--principal" data-con-persona>Probar con una persona</button>' : ''}`;

      hoja({
        titulo: 'Copia a Teams', cuerpo, pie,
        alAbrir: (h) => {
          $('[data-cerrar]', h).onclick = cerrarHoja;
          const bp = $('[data-con-persona]', h);
          if (bp) bp.onclick = async () => {
            let gente = [];
            try { gente = (await api.usuarios()).usuarios || []; }
            catch (e) { mensaje(e.message, 'error'); return; }
            const quien = await elegir({
              titulo: 'A quién le mandamos la prueba',
              opciones: gente
                .filter((g) => (g.correo || '').includes('@'))
                .map((g) => ({ valor: g.id_login,
                               texto: [g.nombre, g.apellido1].filter(Boolean).join(' ') || g.usuario,
                               detalle: g.correo })),
            });
            if (!quien) return;
            try {
              const r2 = await api.probarTeams(quien);
              cerrarHoja();
              mensaje(r2.mensaje || 'Hecho.', r2.bien ? 'ok' : 'error');
              if (!r2.bien) {
                const mal = (r2.pasos || []).find((p) => !p.bien);
                if (mal) mensaje(mal.paso + ': ' + mal.detalle, 'error');
              }
            } catch (e) { mensaje(e.message, 'error'); }
          };
        },
      });
    };

    /* ¿Hay una versión nueva? Lo que se sabe sale nada más abrir —el
       servidor lo guarda doce horas, así que no hace esperar—, y al
       pulsar se vuelve a preguntar a GitHub. Si hay una, se abre la
       página donde está; esto no descarga ni instala nada. */
    const bNueva = $('[data-version-nueva]', contenido);
    if (bNueva) {
      const texto = $('[data-version-nueva-texto]', bNueva);
      let donde = '';
      const pintar = (a) => {
        donde = a.hay_nueva ? a.donde : '';
        texto.textContent = a.hay_nueva
          ? `Sí: la ${a.publicada} (tienes la ${a.actual}). Pulsa para verla.`
          : (a.publicada ? `No: la ${a.actual} es la última publicada.` : (a.motivo || 'No se sabe.'));
      };
      api.versionNueva(false).then((r) => pintar(r.actualizacion)).catch(() => {});
      bNueva.onclick = async () => {
        if (donde) { window.open(donde, '_blank', 'noopener'); return; }
        texto.textContent = 'Preguntando…';
        try { pintar((await api.versionNueva(true)).actualizacion); }
        catch (e) { texto.textContent = e.message; }
      };
    }

    const bRenovar = $('[data-renovar]', contenido);
    if (bRenovar) bRenovar.onclick = async () => {
      /* Para cuando se sube una versión nueva y los móviles siguen con la
         guardada: cada uno sube lo pendiente, vacía y vuelve a descargar. */
      const ok = await confirmar({
        titulo: 'Forzar la actualización de todos',
        texto: 'Cada aparato, la próxima vez que tenga conexión: sube lo que tenga sin enviar, '
             + 'borra lo que guarda para trabajar sin cobertura, cierra la sesión y descarga todo '
             + 'de nuevo. Habrá que volver a identificarse. No se pierde nada del servidor.',
        aceptar: 'Forzarla', peligro: true,
      });
      if (!ok) return;
      try { mensaje((await api.renovarTodos()).mensaje, 'ok'); }
      catch (e) { mensaje(e.message, 'error'); }
    };

    $$('[data-ir]', contenido).forEach((b) => { b.onclick = () => ir(b.dataset.ir); });

    /* El menú de Administración tampoco tenía buscador: con veinte
       pantallas, encontrar «Números de serie» era cuestión de memoria. */
    buscadorFilas(contenido, {
      placeholder: 'Buscar una pantalla o un ajuste',
      extra: (q) => htmlDeBusqueda(q, { esAdmin: !!app.usuario?.es_admin }),
    });
    engancharBusqueda(contenido, ir);
  }

  /* ================================================================ */
  /* Configuración                                                    */
  /* ================================================================ */
  async function ajustes(soloCategorias = null) {
    let datos;
    try { datos = await api.ajustes(); }
    catch (e) { return error(e); }

    // Con una entrada propia —«Vista smartphone», «Seguridad»— se enseña
    // sólo esa parte; desde «Configuración», todo.
    ajustesCargados = Object.fromEntries(
      Object.values(datos.ajustes || {}).flat().map((a) => [a.clave, a.valor]));

    if (soloCategorias) {
      datos.categorias = Object.fromEntries(
        Object.entries(datos.categorias).filter(([c]) => soloCategorias.includes(c)));
    }

    const pendientes = {};

    const control = (a) => {
      /* Lo que no se puede cambiar se enseña, pero con candado y sin
         reaccionar al toque. Esconderlo haría creer que no existe; dejarlo
         tocable haría que el servidor lo rechazara al guardar. */
      if (a.bloqueo) {
        const texto = a.tipo === 'interruptor' ? (a.valor ? 'Sí' : 'No')
          : (a.tipo === 'lista' && a.opciones
              ? (a.opciones.find((o) => o.v === a.valor)?.t || a.valor)
              : String(a.valor));
        return `<span class="fila__bloqueado" title="${esc(a.bloqueo)}">${icono('candado')}<span>${esc(texto)}</span></span>`;
      }
      if (a.tipo === 'interruptor') {
        return `<button type="button" class="interruptor" data-clave="${esc(a.clave)}" role="switch"
                        aria-checked="${a.valor ? 'true' : 'false'}"></button>`;
      }
      if (a.tipo === 'lista' && a.opciones) {
        const act = a.opciones.find((o) => o.v === a.valor);
        return `<span class="fila__valor fila__valor--elegible" data-lista="${esc(a.clave)}">${esc(act?.t || a.valor)}</span>`;
      }
      /* Dos ajustes son listas y con un editor de texto en crudo no había
         quien los usara: «qué hay que rellenar por fuerza» y los accesos
         rápidos del alta. Cada uno con su control. */
      if (a.clave === 'alta_obligatorios') {
        const v = Array.isArray(a.valor) ? a.valor : [];
        return `<span class="fila__valor fila__valor--elegible" data-obligatorios>
                  ${v.length ? esc(v.map((x) => CAMPOS_ALTA[x] || x).join(', ')) : 'Nada obligatorio'}</span>`;
      }
      if (a.clave === 'alta_rapidos_manual') {
        const v = Array.isArray(a.valor) ? a.valor : [];
        return `<span class="fila__valor fila__valor--elegible" data-rapidos>
                  ${v.length ? v.length + (v.length === 1 ? ' tipo' : ' tipos') : 'Sin elegir'}</span>`;
      }

      return `<span class="fila__valor" data-texto="${esc(a.clave)}"
                    style="cursor:pointer;color:var(--marca);font-weight:640">${esc(String(a.valor))}</span>`;
    };

    contenido.innerHTML = Object.entries(datos.categorias).map(([cat, titulo]) => {
      const lista = datos.ajustes[cat] || [];
      if (!lista.length) return '';
      return `
        <div class="seccion"><h2>${esc(titulo)}</h2></div>
        <div class="filas">
          ${lista.map((a) => `
            <div class="fila" style="cursor:default" data-fila-clave="${esc(a.clave)}">
              <span class="fila__texto">${esc(a.titulo)}
                ${a.descripcion ? `<small>${esc(a.descripcion)}</small>` : ''}
                ${a.bloqueo ? `<small style="color:var(--tinta-3)">${esc(a.bloqueo)}</small>` : ''}</span>
              ${control(a)}
            </div>`).join('')}
        </div>`;
    }).join('') + `
      <div id="guardar-barra" style="position:sticky;bottom:calc(var(--barra) + 12px);margin-top:18px" class="oculto">
        <button type="button" class="btn btn--principal btn--bloque" data-guardar>
          ${icono('guardar')}Guardar cambios
        </button>
      </div>`;

    // El buscador, en la pantalla completa: en las de una sola sección
    // (Vista smartphone, Seguridad) hay dos o tres ajustes.
    if (!soloCategorias) buscadorFilas(contenido, {
      placeholder: 'Buscar un ajuste o una pantalla',
      /* Busca también fuera: antes sólo filtraba las filas de esta
         pantalla, así que «departamentos» o «frases» no daban nada
         aunque existan sus pantallas. */
      extra: (q) => htmlDeBusqueda(q, {
        esAdmin: !!app.usuario?.es_admin,
        omitir: Object.keys(ajustesCargados),
      }),
    });

    irAlBuscado(contenido);

    const filaObl = $('[data-obligatorios]', contenido);
    if (filaObl) filaObl.onclick = async () => {
      const actual = new Set(Array.isArray(ajusteValor('alta_obligatorios')) ? ajusteValor('alta_obligatorios') : []);
      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `<div class="filas">${Object.entries(CAMPOS_ALTA).map(([k, n]) => `
        <button type="button" class="fila" data-c="${k}">
          <span class="fila__texto">${esc(n)}</span>
          <span class="interruptor" role="switch" data-int="${k}"
                aria-checked="${actual.has(k) ? 'true' : 'false'}"></span>
        </button>`).join('')}</div>
        <p class="campo__ayuda" style="margin-top:10px">Lo que marques aquí hay que rellenarlo
        sí o sí para poder dar un parte, también desde un móvil sin cobertura.</p>`;
      cuerpo.onclick = (e) => {
        const b = e.target.closest('[data-c]');
        if (!b) return;
        const k = b.dataset.c;
        if (actual.has(k)) actual.delete(k); else actual.add(k);
        b.querySelector('[data-int]').setAttribute('aria-checked', actual.has(k) ? 'true' : 'false');
      };
      hoja({ titulo: 'Campos obligatorios al crear partes', cuerpo,
        pie: `<button type="button" class="btn btn--principal btn--bloque" data-si>Guardar</button>`,
        alAbrir: (h) => {
          $('[data-si]', h).onclick = async () => {
            try {
              await api.guardarAjustes({ alta_obligatorios: [...actual] });
              cerrarHoja();
              mensaje('Guardado.', 'ok');
        olvidarAjustes();      // el índice de búsqueda vuelve a pedirlos
              ajustes();
            } catch (e) { mensaje(e.message, 'error'); }
          };
        } });
    };

    const filaRap = $('[data-rapidos]', contenido);
    if (filaRap) filaRap.onclick = async () => {
      const elegidos = [...(Array.isArray(ajusteValor('alta_rapidos_manual')) ? ajusteValor('alta_rapidos_manual') : [])];
      const todos = (app.catalogo?.elementos || []);
      const v = await elegirBuscando({
        titulo: 'Añadir un tipo al acceso rápido',
        marcador: 'Buscar entre ' + todos.length + ' tipos',
        vacio: 'No hay ningún tipo con ese nombre.',
        opciones: todos.map((e) => ({ valor: e.id_elemento, texto: e.nombre,
                                      detalle: elegidos.includes(e.id_elemento) ? 'Ya está en la lista' : '' })),
      });
      if (v === null) return;
      if (!elegidos.includes(v)) elegidos.push(v);
      try {
        await api.guardarAjustes({ alta_rapidos_manual: elegidos.slice(0, 6) });
        mensaje('Añadido. Toca de nuevo para añadir otro.', 'ok');
        ajustes();
      } catch (e) { mensaje(e.message, 'error'); }
    };

    const barra = $('#guardar-barra', contenido);
    const marcar = (clave, valor) => {
      pendientes[clave] = valor;
      barra.classList.remove('oculto');
    };

    $$('[data-clave]', contenido).forEach((b) => {
      b.onclick = () => {
        const nuevo = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', String(nuevo));
        marcar(b.dataset.clave, nuevo);
      };
    });

    $$('[data-lista]', contenido).forEach((el) => {
      el.onclick = async () => {
        const clave = el.dataset.lista;
        const a = Object.values(datos.ajustes).flat().find((x) => x.clave === clave);
        const v = await elegir({
          titulo: a.titulo,
          valor: a.valor,
          opciones: a.opciones.map((o) => ({ valor: o.v, texto: o.t })),
        });
        if (v === null) return;
        a.valor = v;
        el.textContent = a.opciones.find((o) => o.v === v)?.t || v;
        marcar(clave, v);
      };
    });

    $$('[data-texto]', contenido).forEach((el) => {
      el.onclick = () => {
        const clave = el.dataset.texto;
        const a = Object.values(datos.ajustes).flat().find((x) => x.clave === clave);
        pedirTexto(a.titulo, String(a.valor), a.tipo === 'numero' ? 'number' : 'text').then((v) => {
          if (v === null) return;
          el.textContent = v;
          marcar(clave, v);
        });
      };
    });

    $('[data-guardar]', contenido).onclick = async () => {
      try {
        await api.guardarAjustes(pendientes);
        // El catálogo lleva los módulos activos: hay que recargarlo para
        // que la aplicación esconda o muestre lo que toca.
        const c = await api.catalogo();
        app.catalogo = c;
        await db.ajuste('catalogo', c);
        tono('ok');
        mensaje('Configuración guardada.', 'ok');
        barra.classList.add('oculto');
      } catch (e) { mensaje(e.message, 'error'); }
    };
  }

  /* ================================================================ */
  /* Tipos de avería                                                  */
  /* ================================================================ */
  async function elementos() {
    let filtro = '';
    let familiaSel = null;
    let verBajas = false;

    async function pintarLista() {
      let datos;
      try {
        datos = await api.elementos({
          q: filtro || undefined,
          familia: familiaSel || undefined,
          incluir_baja: verBajas ? 1 : undefined,
        });
      } catch (e) { return error(e); }

      const familias = app.catalogo?.familias || [];

      contenido.innerHTML = `
        <div class="buscador" style="margin-bottom:10px">
          ${icono('buscar')}
          <input class="entrada" id="el-q" type="search" placeholder="Buscar tipo de avería"
                 value="${esc(filtro)}" autocomplete="off">
        </div>

        <div class="carril" style="margin-bottom:10px">
          <button type="button" class="pastilla" data-f="0" aria-pressed="${!familiaSel}">
            ${icono('lista')}Todas</button>
          ${familias.map((f) => `
            <button type="button" class="pastilla" data-f="${f.id_familia}" aria-pressed="${familiaSel === f.id_familia}">
              ${icono(f.icono)}${esc(f.nombre)}</button>`).join('')}
        </div>

        <div class="acciones" style="margin-bottom:14px">
          <button type="button" class="btn btn--principal btn--s" data-nuevo>${icono('mas')}Crear tipo</button>
          <button type="button" class="btn btn--contorno btn--s" data-bajas aria-pressed="${verBajas}">
            ${icono(verBajas ? 'ok' : 'info')}${verBajas ? 'Ocultando nada' : 'Ver deshabilitados'}</button>
        </div>

        <div class="rotulo" style="margin:0 2px 8px">
          ${datos.elementos.length} ${datos.elementos.length === 1 ? 'tipo' : 'tipos'}</div>

        ${datos.elementos.length ? `<div class="filas">
          ${datos.elementos.map((e) => `
            <button type="button" class="fila" data-e="${e.id_elemento}" ${e.baja ? 'style="opacity:.5"' : ''}>
              <span class="avatar" style="background:${esc(e.familia_color || '#64748B')}22;
                                          color:${esc(e.familia_color || '#64748B')}">
                ${icono(e.familia_icono || 'llaveinglesa')}</span>
              <span class="fila__texto">${esc(e.nombre_elemento)}
                ${e.usa_serie ? '<span class="prio" style="--prio-color:var(--marca);margin-left:6px">nº serie</span>' : ''}
                ${e.baja ? '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">deshabilitado</span>' : ''}
                <small>${esc(e.familia || 'Sin familia')}
                  ${e.abreviatura_elemento ? ' · ' + esc(e.abreviatura_elemento) : ''}
                  ${e.n_partes ? ' · ' + e.n_partes + ' partes' : ''}
                  ${e.n_unidades ? ' · ' + e.n_unidades + ' aparatos' : ''}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </button>`).join('')}
        </div>` : estadoVacio({ icono: 'buscar', titulo: 'Nada con esa búsqueda', texto: '' })}`;

      const q = $('#el-q', contenido);
      let reloj = null;
      q.oninput = () => {
        clearTimeout(reloj);
        reloj = setTimeout(() => {
          filtro = q.value;
          const pos = q.selectionStart;
          pintarLista().then(() => {
            const n = $('#el-q', contenido);
            if (n) { n.focus(); n.setSelectionRange(pos, pos); }
          });
        }, 300);
      };

      $$('[data-f]', contenido).forEach((b) => {
        b.onclick = () => { familiaSel = parseInt(b.dataset.f, 10) || null; pintarLista(); };
      });
      $('[data-bajas]', contenido).onclick = () => { verBajas = !verBajas; pintarLista(); };
      $('[data-nuevo]', contenido).onclick = () => editarElemento(null, pintarLista);
      $$('[data-e]', contenido).forEach((b) => {
        b.onclick = () => editarElemento(
          datos.elementos.find((x) => x.id_elemento === parseInt(b.dataset.e, 10)), pintarLista);
      });
    }

    await pintarLista();
  }

  function editarElemento(el, alTerminar) {
    let iconoEl = el?.icono || '';
    const esNuevo = !el;
    const est = {
      id_familia: el?.id_familia ?? null,
      id_departamento: el?.id_departamento ?? null,
    };

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="te-nombre">Nombre</label>
        <input class="entrada" id="te-nombre" maxlength="150" value="${esc(el?.nombre_elemento || '')}"
               placeholder="Aire acondicionado"></div>

      <div class="campo"><label for="te-abrev">Abreviatura</label>
        <input class="entrada" id="te-abrev" maxlength="20" value="${esc(el?.abreviatura_elemento || '')}"
               placeholder="A/A · opcional"></div>

      <div class="campo"><label>Icono</label>
        <div class="opciones">
          <button type="button" class="opcion" id="te-icono-fam"
                  aria-pressed="${!el?.icono || el.icono === iconoDeSuFamilia(el)}"
                  style="min-width:46px;justify-content:center"
                  title="El de su familia">${icono('personas')}</button>
          <button type="button" class="opcion" id="te-icono-fa"
                  aria-pressed="${/^fa-/.test(el?.icono || '')}"
                  style="min-width:46px;justify-content:center"
                  title="Elegir entre 1.400">${el?.icono ? icono(el.icono) : icono('buscar')}</button>
        </div>
        <div class="campo__ayuda">El primero usa el de su familia; el segundo abre los
          1.400 de Font Awesome. Así cada tipo puede tener el suyo.</div>
      </div>

      <div class="campo">
        <label>Familia</label>
        <button type="button" class="fila" id="te-fam"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('panel')}
          <span class="fila__texto" id="te-txt-fam">${esc(nombreFam(est.id_familia))}
            <small>Determina el icono y el grupo que lo lleva</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('herramientas')}
        <span class="fila__texto">Lleva número de serie
          <small>Se podrá seguir la pista a cada aparato</small></span>
        <button type="button" class="interruptor" id="te-serie" role="switch"
                aria-checked="${el?.usa_serie ? 'true' : 'false'}"></button>
      </div>

      ${!esNuevo && el.baja ? `
        <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde);margin-top:10px">
          ${icono('aviso')}
          <span class="fila__texto">Deshabilitado
            <small>No aparece al dar de alta un parte</small></span>
          <button type="button" class="interruptor" id="te-baja" role="switch" aria-checked="true"></button>
        </div>` : ''}

      ${!esNuevo && (el.n_partes || el.n_unidades) ? `
        <div class="aviso aviso--info" style="margin-top:14px">${icono('info')}<div>
          Este tipo se usa en ${el.n_partes} partes${el.n_unidades ? ' y ' + el.n_unidades + ' aparatos' : ''}.
          Si lo borras, se deshabilitará en vez de desaparecer, para que el
          historial siga teniendo sentido.</div></div>` : ''}

      <div id="te-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = esNuevo
      ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
         <button type="button" class="btn btn--principal" data-si>Crear</button>`
      : `<button type="button" class="btn btn--peligro" data-borrar>${el.baja ? 'Borrar' : 'Deshabilitar'}</button>
         <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: esNuevo ? 'Tipo de avería nuevo' : el.nombre_elemento,
      cuerpo, pie,
      alAbrir: (h) => {
      $('#te-icono-fam', h).onclick = () => {
        // Vacío significa «el de su familia»: el servidor lo copia al guardar.
        iconoEl = '';
        $('#te-icono-fam', h).setAttribute('aria-pressed', 'true');
        $('#te-icono-fa', h).setAttribute('aria-pressed', 'false');
      };
      $('#te-icono-fa', h).onclick = async () => {
        const elegido = await elegirIconoFA({
          titulo: 'Icono del tipo',
          sugeridos: sugerenciasPorNombre($('#te-nombre', h)?.value || el?.nombre || ''),
        });
        if (!elegido) return;
        iconoEl = elegido;
        $('#te-icono-fam', h).setAttribute('aria-pressed', 'false');
        const b = $('#te-icono-fa', h);
        b.setAttribute('aria-pressed', 'true');
        b.innerHTML = icono(elegido);
      };

        $('#te-fam', h).onclick = async () => {
          const v = await elegirBuscando({
            titulo: 'Familia',
            opciones: [{ valor: 0, texto: 'Sin familia', icono: 'info' },
              ...(app.catalogo?.familias || []).map((f) => ({
                valor: f.id_familia, texto: f.nombre, icono: f.icono, detalle: f.descripcion }))],
            valor: est.id_familia ?? 0,
            marcador: 'Buscar familia',
          });
          if (v === null) return;
          est.id_familia = v || null;
          $('#te-txt-fam', h).innerHTML =
            `${esc(nombreFam(est.id_familia))}<small>Determina el icono y el grupo que lo lleva</small>`;
        };

        const serie = $('#te-serie', h);
        serie.onclick = () => serie.setAttribute('aria-checked',
          serie.getAttribute('aria-checked') !== 'true');
        const baja = $('#te-baja', h);
        if (baja) baja.onclick = () => baja.setAttribute('aria-checked',
          baja.getAttribute('aria-checked') !== 'true');

        const err = (t) => { $('#te-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

        const no = $('[data-no]', h);
        if (no) no.onclick = () => cerrarHoja();

        const borrar = $('[data-borrar]', h);
        if (borrar) borrar.onclick = async () => {
          const conDatos = el.n_partes > 0;
          const ok = await confirmar({
            titulo: conDatos ? 'Deshabilitar el tipo' : 'Borrar el tipo',
            texto: conDatos
              ? `Tiene ${el.n_partes} partes detrás, así que se deshabilitará: dejará de ofrecerse pero el historial se conserva.`
              : 'No tiene partes detrás, así que se borrará del todo. Esto no se puede deshacer.',
            aceptar: conDatos ? 'Deshabilitar' : 'Borrar',
            peligro: true,
          });
          if (!ok) return;
          try {
            const r = await api.borrarElemento(el.id_elemento, el.baja && !conDatos);
            cerrarHoja();
            mensaje(r.mensaje, 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };

        $('[data-si]', h).onclick = async () => {
          const nombre = $('#te-nombre', h).value.trim();
          if (!nombre) return err('Ponle un nombre.');
          const d = {
            nombre_elemento: nombre,
            abreviatura_elemento: $('#te-abrev', h).value.trim(),
            id_familia: est.id_familia,
            usa_serie: serie.getAttribute('aria-checked') === 'true',
            // Vacío = el de su familia; el servidor lo copia al guardar.
            icono: iconoEl,
          };
          if (baja) d.baja = baja.getAttribute('aria-checked') === 'true';
          try {
            if (esNuevo) await api.crearElemento(d);
            else await api.editarElemento(el.id_elemento, d);
            cerrarHoja();
            tono('ok');
            mensaje('Tipo guardado.', 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };
      },
    });
  }

  function nombreFam(id) {
    if (!id) return 'Sin familia';
    return app.catalogo?.familias?.find((f) => f.id_familia === id)?.nombre || '#' + id;
  }

  /* ================================================================ */
  /* Ubicaciones                                                      */
  /* ================================================================ */
  /**
   * Crear habitaciones por rango.
   *
   * Dar de alta una planta a mano son treinta habitaciones por cuatro
   * partes cada una: ciento veinte toques para algo que se sabe de
   * antemano. Aquí se dice de dónde a dónde y qué tiene cada una.
   */
  async function porRango(datos, volver) {
    const nivel = (n) => datos.niveles[n - 1] || 'Nivel ' + n;
    const opciones = (n) => (datos.zonas['zona' + n] || [])
      .map((z) => `<option value="${z.id}">${esc(z.nombre)}</option>`).join('');

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo">
        <label for="r-planta">${esc(nivel(1))}</label>
        <select class="entrada" id="r-planta">${opciones(1)}</select>
      </div>

      <div class="campo">
        <label for="r-zona">${esc(nivel(2))}</label>
        <select class="entrada" id="r-zona">
          <option value="">— sin ${esc(nivel(2).toLowerCase())} —</option>
          ${opciones(2)}
        </select>
      </div>

      <div class="seccion"><h2>Los números</h2></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="campo" style="margin:0">
          <label for="r-desde">Desde</label>
          <input class="entrada" id="r-desde" type="number" inputmode="numeric" value="101">
        </div>
        <div class="campo" style="margin:0">
          <label for="r-hasta">Hasta</label>
          <input class="entrada" id="r-hasta" type="number" inputmode="numeric" value="130">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div class="campo" style="margin:0">
          <label for="r-prefijo">Antes del número</label>
          <input class="entrada" id="r-prefijo" placeholder="Hab. " autocomplete="off">
        </div>
        <div class="campo" style="margin:0">
          <label for="r-sufijo">Después</label>
          <input class="entrada" id="r-sufijo" placeholder="" autocomplete="off">
        </div>
      </div>

      <div class="campo">
        <label for="r-saltar">Números que no existen</label>
        <input class="entrada" id="r-saltar" placeholder="113, 114" autocomplete="off"
               inputmode="numeric">
        <div class="campo__ayuda">
          Separados por comas. En muchos hoteles no hay habitación 13.
        </div>
      </div>

      ${(datos.zonas.zona4 || []).length ? `
        <div class="seccion"><h2>Qué tiene cada una</h2></div>
        <div class="carril" id="r-subs" style="flex-wrap:wrap;gap:6px">
          ${(datos.zonas.zona4 || []).slice(0, 30).map((z) => `
            <button type="button" class="pastilla" data-sub="${z.id}" aria-pressed="false">
              ${esc(z.nombre)}</button>`).join('')}
        </div>
        <div class="campo__ayuda" style="margin-top:8px">
          Si no eliges ninguno, cada habitación será una sola ubicación.
        </div>` : ''}

      <div class="aviso aviso--info" style="margin-top:16px" id="r-resumen">
        ${icono('info')}<div>Se crearán <strong>30</strong> ubicaciones.</div>
      </div>

      <div id="r-error"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--principal" data-si>Crear</button>`;

    hoja({
      titulo: 'Habitaciones por rango',
      cuerpo, pie,
      alAbrir: (h) => {
        const marcados = () => $$('[data-sub]', h)
          .filter((b) => b.getAttribute('aria-pressed') === 'true')
          .map((b) => parseInt(b.dataset.sub, 10));

        const resumir = () => {
          const d = parseInt($('#r-desde', h).value, 10) || 0;
          const t = parseInt($('#r-hasta', h).value, 10) || 0;
          const saltar = ($('#r-saltar', h).value.match(/\d+/g) || []).length;
          const n = Math.max(0, t - d + 1) - saltar;
          const subs = marcados().length || 1;
          $('#r-resumen', h).innerHTML = `${icono('info')}<div>
            Se crearán <strong>${Math.max(0, n)}</strong>
            ${n === 1 ? 'ubicación' : 'ubicaciones'}${subs > 1
              ? `, con <strong>${subs}</strong> partes cada una:
                 ${Math.max(0, n * subs)} sitios donde dar un parte` : ''}.
          </div>`;
        };

        ['#r-desde', '#r-hasta', '#r-saltar'].forEach((sel) => {
          const el = $(sel, h);
          if (el) el.oninput = resumir;
        });
        $$('[data-sub]', h).forEach((b) => {
          b.onclick = () => {
            b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') !== 'true');
            resumir();
          };
        });
        resumir();

        $('[data-no]', h).onclick = () => cerrarHoja();
        $('[data-si]', h).onclick = async () => {
          const bs = $('[data-si]', h);
          bs.disabled = true;
          bs.textContent = 'Creando…';
          try {
            const r = await api.crearRangoUbicaciones({
              id_zona_1: parseInt($('#r-planta', h).value, 10),
              id_zona_2: $('#r-zona', h).value ? parseInt($('#r-zona', h).value, 10) : null,
              desde: parseInt($('#r-desde', h).value, 10),
              hasta: parseInt($('#r-hasta', h).value, 10),
              prefijo: $('#r-prefijo', h).value,
              sufijo: $('#r-sufijo', h).value,
              saltar: ($('#r-saltar', h).value.match(/\d+/g) || []).map(Number),
              sublugares: marcados(),
            });
            cerrarHoja();
            tono('ok');
            mensaje(r.mensaje, 'ok');
            volver();
          } catch (e) {
            bs.disabled = false;
            bs.textContent = 'Crear';
            $('#r-error', h).innerHTML =
              `<div class="aviso aviso--error" style="margin-top:10px">${icono('aviso')}
                <div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  async function zonas() {
    let datos;
    try { datos = await api.zonasAdmin(); }
    catch (e) { return error(e); }

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Las ubicaciones son <strong>de este hotel</strong>: sus plantas, sus
        números de habitación. No se comparten con los demás.
        <br><br>
        Se montan en cuatro niveles. Las combinaciones concretas
        (${datos.combinaciones} ahora) se crean al vuelo desde el selector,
        cuando alguien elige una que todavía no existía.
      </div></div>

      <button type="button" class="btn btn--principal btn--bloque" data-rango style="margin-bottom:16px">
        ${icono('mas')}Crear habitaciones por rango
      </button>
      <div class="campo__ayuda" style="text-align:center;margin:-10px 0 16px">
        De la 101 a la 130 de una vez, con sus baños y terrazas.
      </div>

      ${[1, 2, 3, 4].map((n) => {
        const lista = datos.zonas['zona' + n] || [];
        return `
          <div class="seccion"><h2>${esc(datos.niveles[n - 1] || 'Nivel ' + n)}</h2>
            <span class="rotulo">${lista.length}</span></div>
          <button type="button" class="btn btn--contorno btn--bloque btn--s" data-add="${n}" style="margin-bottom:8px">
            ${icono('mas')}Añadir ${esc((datos.niveles[n - 1] || '').toLowerCase())}
          </button>
          ${lista.length ? `<div class="filas">
            ${lista.slice(0, 60).map((z) => `
              <button type="button" class="fila" data-z="${n}:${z.id}" data-nombre="${esc(z.nombre)}">
                ${icono('ubicacion')}
                <span class="fila__texto">${esc(z.nombre)}
                  <small>${z.usos} ${z.usos === 1 ? 'ubicación lo usa' : 'ubicaciones lo usan'}</small></span>
                ${icono('flecha', 'fila__flecha')}
              </button>`).join('')}
            ${lista.length > 60 ? `<div class="fila" style="cursor:default">
              <span class="fila__texto" style="color:var(--tinta-3)">
                y ${lista.length - 60} más</span></div>` : ''}
          </div>` : '<p class="campo__ayuda">Ninguno todavía.</p>'}`;
      }).join('')}`;

    const br = $('[data-rango]', contenido);
    if (br) br.onclick = () => porRango(datos, zonas);

    $$('[data-add]', contenido).forEach((b) => {
      b.onclick = () => nuevoNivel(parseInt(b.dataset.add, 10), datos, zonas);
    });
    $$('[data-z]', contenido).forEach((b) => {
      const [n, id] = b.dataset.z.split(':').map(Number);
      b.onclick = () => editarNivel(n, id, b.dataset.nombre, datos, zonas);
    });
  }

  function nuevoNivel(n, datos, alTerminar) {
    pedirTexto('Nuevo ' + (datos.niveles[n - 1] || 'nivel ' + n).toLowerCase(), '').then(async (v) => {
      if (!v) return;
      try {
        const r = await api.crearNivelZona(n, v);
        mensaje(r.ya_existia ? 'Ya existía.' : 'Creado.', 'ok');
        await recargarCatalogo();
        alTerminar();
      } catch (e) { mensaje(e.message, 'error'); }
    });
  }

  function editarNivel(n, id, nombre, datos, alTerminar) {
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="zn-nombre">Nombre</label>
        <input class="entrada" id="zn-nombre" maxlength="150" value="${esc(nombre)}"></div>
      <div class="campo__ayuda">
        Al renombrarlo se actualizan todas las ubicaciones que lo usan.</div>
      <div id="zn-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--peligro" data-borrar>Eliminar</button>
                     <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: nombre,
      cuerpo, pie,
      alAbrir: (h) => {
        const err = (t) => { $('#zn-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

        $('[data-borrar]', h).onclick = async () => {
          const ok = await confirmar({
            titulo: 'Eliminar',
            texto: 'Sólo se puede si no lo usa ninguna ubicación.',
            aceptar: 'Eliminar', peligro: true,
          });
          if (!ok) return;
          try {
            await api.borrarNivelZona(n, id);
            cerrarHoja();
            mensaje('Eliminado.', 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };

        $('[data-si]', h).onclick = async () => {
          const v = $('#zn-nombre', h).value.trim();
          if (!v) return err('El nombre no puede quedar vacío.');
          try {
            await api.renombrarNivelZona(n, id, v);
            cerrarHoja();
            mensaje('Renombrado.', 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };
      },
    });
  }

  /* ================================================================ */
  /* Contraseñas olvidadas                                            */
  /* ================================================================ */
  async function claves() {
    let peticiones = [];
    try { peticiones = (await api.recuperaciones()).recuperaciones; }
    catch (e) { return error(e); }

    const gente = (app.catalogo?.usuarios || []);

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Quien no pueda entrar lo pide desde la pantalla de acceso y su petición
        aparece aquí. Le das un código de seis dígitos <strong>en persona o por
        teléfono</strong>, y con él se pone una contraseña nueva.
        <br><br>
        Se hace así a propósito: montar un servidor de correo sólo para esto no
        compensa en un hotel, donde todo el mundo está en el mismo edificio.
      </div></div>

      ${peticiones.length ? `
        <div class="seccion"><h2>Lo han pedido</h2>
          <span class="rotulo">${peticiones.length}</span></div>
        <div class="filas">
          ${peticiones.map((r) => `
            <button type="button" class="fila" data-cod="${r.id_login}">
              ${icono('llave')}
              <span class="fila__texto">${esc(r.persona)}
                <small>${esc(r.correo)} · desde
                  <span style="font-family:var(--mono)">${esc(r.ip_solicita || '?')}</span>
                  · ${esc(haceCuanto(r.creado))}
                  ${r.tiene_codigo ? ' · código ya emitido' : ''}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </button>`).join('')}
        </div>` : `
        <div class="campo__ayuda" style="margin-bottom:16px">
          Nadie ha pedido restablecer su contraseña.</div>`}

      <div class="seccion"><h2>O dáselo directamente</h2></div>
      <button type="button" class="btn btn--contorno btn--bloque" data-cualquiera>
        ${icono('personas')}Elegir a alguien del equipo
      </button>`;

    contenido.onclick = async (e) => {
      const b = e.target.closest('[data-cod]');
      if (b) { emitir(parseInt(b.dataset.cod, 10)); return; }
      if (e.target.closest('[data-cualquiera]')) {
        const v = await elegirBuscando({
          titulo: 'Para quién',
          opciones: gente.map((u) => ({ valor: u.id_login, texto: u.nombre_completo, detalle: u.correo })),
          marcador: 'Buscar persona',
        });
        if (v) emitir(v);
      }
    };
  }

  async function emitir(idLogin) {
    let r;
    try { r = await api.emitirCodigo(idLogin); }
    catch (e) { mensaje(e.message, 'error'); return; }

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <p style="font-size:14px;color:var(--tinta-2);margin-bottom:14px">
        Código para <strong>${esc(r.correo)}</strong>. Caduca ${esc(r.caduca)}.
      </p>
      <div style="font-family:var(--mono);font-size:40px;font-weight:700;letter-spacing:.14em;
                  text-align:center;padding:22px;background:var(--marca-tenue);
                  color:var(--marca);border-radius:var(--r-m);margin-bottom:14px"
           id="el-codigo">${esc(r.codigo)}</div>
      <div class="aviso aviso--senal">${icono('aviso')}<div>
        ${esc(r.aviso)} Con él podrá poner una contraseña nueva desde la pantalla
        de acceso, y todas sus sesiones anteriores se cerrarán.
      </div></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-copiar>${icono('copiar')}Copiar</button>
                     <button type="button" class="btn btn--principal" data-ok>Hecho</button>`;

    hoja({
      titulo: 'Código de un solo uso',
      cuerpo, pie,
      alAbrir: (h) => {
        $('[data-copiar]', h).onclick = async () => {
          try {
            await navigator.clipboard.writeText(r.codigo);
            mensaje('Código copiado.', 'ok');
          } catch { mensaje('Cópialo a mano: ' + r.codigo, 'error'); }
        };
        $('[data-ok]', h).onclick = () => { cerrarHoja(); claves(); };
      },
    });
  }

  /* ================================================================ */
  /* Familias                                                         */
  /* ================================================================ */
  async function familias() {
    let datos;
    try { datos = await api.familias(); }
    catch (e) { return error(e); }

    const clases = { averia: 'Avería', peticion: 'Petición', tarea: 'Tarea' };

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Las familias agrupan los tipos de avería por oficio. Sirven para tres
        cosas: elegir rápido sin buscar entre cientos, repartir el parte solo
        al grupo que lleva ese oficio, y que los informes signifiquen algo.
        <br><br>
        La <strong>clase</strong> separa lo que en un hotel no se cuenta junto:
        una avería es algo roto, una petición es una cama extra, y una tarea es
        un repaso de limpieza.
      </div></div>

      ${datos.sin_familia > 0 ? `<div class="aviso aviso--senal">${icono('aviso')}<div>
        Hay <strong>${datos.sin_familia} tipos sin familia</strong>. Ejecuta
        <code>php tools/clasificar.php --aplicar</code> en el servidor, o
        asígnalos a mano.</div></div>` : ''}

      <button type="button" class="btn btn--principal btn--bloque" data-nueva style="margin-bottom:16px">
        ${icono('mas')}Crear familia
      </button>

      <div class="filas">
        ${datos.familias.map((f) => `
          <button type="button" class="fila" data-f="${f.id_familia}" ${f.activo ? '' : 'style="opacity:.5"'}>
            <span class="avatar" style="background:${esc(f.color)}22;color:${esc(f.color)}">
              ${icono(f.icono)}</span>
            <span class="fila__texto">${esc(f.nombre)}
              <small>${esc(clases[f.clase] || f.clase)} · ${f.n_elementos} tipos
                ${f.departamento ? ' · ' + esc(f.departamento) : ' · sin grupo'}
                ${f.activo ? '' : ' · desactivada'}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>`;

    $('[data-nueva]', contenido).onclick = () => editarFamilia(null, datos, familias);
    $$('[data-f]', contenido).forEach((b) => {
      b.onclick = () => editarFamilia(
        datos.familias.find((f) => f.id_familia === parseInt(b.dataset.f, 10)), datos, familias);
    });
  }

  function editarFamilia(fam, datos, alTerminar) {
    const esNueva = !fam;
    const est = {
      clase: fam?.clase || 'averia',
      icono: fam?.icono || 'llave-inglesa',
      color: fam?.color || '#0B5563',
      id_departamento: fam?.id_departamento ?? null,
      id_prioridad: fam?.id_prioridad ?? null,
    };
    const iconos = ['gota','rayo','viento','martillo','silla','llave','tele','wifi',
                    'herramientas','brillo','persona','aviso','panel','llave-inglesa'];
    const colores = ['#0EA5E9','#F2A007','#38BDF8','#B45309','#7C3AED','#334155',
                     '#6366F1','#0D7C57','#DC2626','#EC4899','#14B8A6','#0B5563','#C7362B','#059669'];

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="f-nombre">Nombre</label>
        <input class="entrada" id="f-nombre" maxlength="80" value="${esc(fam?.nombre || '')}"
               placeholder="Fontanería"></div>

      <div class="campo"><label for="f-desc">Qué incluye</label>
        <input class="entrada" id="f-desc" maxlength="200" value="${esc(fam?.descripcion || '')}"
               placeholder="Agua, grifos, sanitarios y fugas"></div>

      <div class="campo">
        <label>Clase de parte</label>
        <div class="opciones" id="f-clase">
          <button type="button" class="opcion" data-cl="averia" aria-pressed="${est.clase === 'averia'}">
            Avería</button>
          <button type="button" class="opcion" data-cl="peticion" aria-pressed="${est.clase === 'peticion'}">
            Petición</button>
          <button type="button" class="opcion" data-cl="tarea" aria-pressed="${est.clase === 'tarea'}">
            Tarea</button>
        </div>
        <div class="campo__ayuda" id="f-ayuda-clase"></div>
      </div>

      <div class="campo">
        <label>Grupo que lo lleva</label>
        <button type="button" class="fila" id="f-dep"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('personas')}
          <span class="fila__texto" id="f-txt-dep">${esc(nombreDep(est.id_departamento))}
            <small>Los partes de esta familia se le asignan solos</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="campo">
        <label>Prioridad por defecto</label>
        <button type="button" class="fila" id="f-prio"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('banderin')}
          <span class="fila__texto" id="f-txt-prio">${esc(nombrePrio(est.id_prioridad))}
            <small>Marca el plazo del parte</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="campo"><label>Icono</label>
        <div class="opciones" id="f-iconos">
          ${iconos.map((i) => `<button type="button" class="opcion" data-i="${i}"
            aria-pressed="${est.icono === i}" style="min-width:46px;justify-content:center">
            ${icono(i)}</button>`).join('')}
          <!-- Con diez dibujos no había para todas las familias: dos
               acababan con el mismo icono y no se distinguían. -->
          <button type="button" class="opcion" id="f-icono-fa"
                  aria-pressed="${/^fa-/.test(est.icono || '')}"
                  style="min-width:46px;justify-content:center"
                  title="Elegir entre 1.400 iconos">
            ${/^fa-/.test(est.icono || '') ? icono(est.icono) : icono('buscar')}</button>
        </div>
        <div class="campo__ayuda">El último botón abre los 1.400 de Font Awesome.</div>
      </div>

      <div class="campo"><label>Color</label>
        <div class="opciones" id="f-colores">
          ${colores.map((c) => `<button type="button" class="opcion" data-c="${c}"
            aria-pressed="${est.color === c}" style="min-width:42px;justify-content:center">
            <span style="width:17px;height:17px;border-radius:50%;background:${c};display:block"></span>
            </button>`).join('')}
        </div>
      </div>

      ${!esNueva && fam.n_elementos > 0 ? `
        <button type="button" class="btn btn--contorno btn--bloque btn--s" data-ver-tipos
                style="margin-top:6px">
          ${icono('lista')}Ver y mover sus ${fam.n_elementos} tipos
        </button>` : ''}

      <div id="f-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = esNueva
      ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
         <button type="button" class="btn btn--principal" data-si>Crear</button>`
      : `<button type="button" class="btn btn--peligro" data-borrar>Eliminar</button>
         <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: esNueva ? 'Familia nueva' : fam.nombre,
      cuerpo, pie,
      alAbrir: (h) => {
        const ayudas = {
          averia:   'Algo roto que hay que reparar. Cuenta como incidencia.',
          peticion: 'Lo que pide un cliente: cama extra, cuna, salida tardía. No es una avería.',
          tarea:    'Trabajo programado: repasos, segundas limpiezas, revisiones.',
        };
        const pintarAyuda = () => { $('#f-ayuda-clase', h).textContent = ayudas[est.clase]; };
        pintarAyuda();

        $('#f-clase', h).onclick = (e) => {
          const b = e.target.closest('[data-cl]');
          if (!b) return;
          $$('[data-cl]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.clase = b.dataset.cl;
          pintarAyuda();
        };
        $('#f-iconos', h).onclick = async (e) => {
          const fa = e.target.closest('#f-icono-fa');
          if (fa) {
            // Se sugiere por el nombre de la familia: «Lencería» encuentra
            // «shirt», «Limpieza» encuentra «broom».
            const elegido = await elegirIconoFA({
              titulo: 'Icono de la familia',
              // El nombre está en el campo, no en el estado: se lee de ahí
              // para poder sugerir «shirt» a «Lencería» o «broom» a «Limpieza».
              sugeridos: sugerenciasPorNombre($('#f-nombre', h)?.value || fam?.nombre || ''),
            });
            if (!elegido) return;
            est.icono = elegido;
            $$('[data-i]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
            fa.setAttribute('aria-pressed', 'true');
            fa.innerHTML = icono(elegido);
            return;
          }
          const b = e.target.closest('[data-i]');
          if (!b) return;
          $$('[data-i], #f-icono-fa', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.icono = b.dataset.i;
        };
        $('#f-colores', h).onclick = (e) => {
          const b = e.target.closest('[data-c]');
          if (!b) return;
          $$('[data-c]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.color = b.dataset.c;
        };

        $('#f-dep', h).onclick = async () => {
          const v = await elegir({
            titulo: 'Grupo que lo lleva',
            valor: est.id_departamento,
            opciones: [{ valor: 0, texto: 'Sin grupo fijo' },
              ...(app.catalogo?.departamentos || []).map((d) => ({
                valor: d.id_departamento, texto: d.nombre, color: d.color }))],
          });
          if (v === null) return;
          est.id_departamento = v || null;
          $('#f-txt-dep', h).innerHTML =
            `${esc(nombreDep(est.id_departamento))}<small>Los partes de esta familia se le asignan solos</small>`;
        };

        $('#f-prio', h).onclick = async () => {
          const v = await elegir({
            titulo: 'Prioridad por defecto',
            valor: est.id_prioridad,
            opciones: [{ valor: 0, texto: 'La normal' },
              ...(app.catalogo?.prioridades || []).map((p2) => ({
                valor: p2.id_prioridad, texto: p2.nombre, color: p2.color,
                detalle: p2.sla_horas ? plazoLargo(p2.sla_horas) + ' de plazo' : 'sin plazo' }))],
          });
          if (v === null) return;
          est.id_prioridad = v || null;
          $('#f-txt-prio', h).innerHTML =
            `${esc(nombrePrio(est.id_prioridad))}<small>Marca el plazo del parte</small>`;
        };

        const verTipos = $('[data-ver-tipos]', h);
        if (verTipos) verTipos.onclick = () => moverTipos(fam, datos, alTerminar);

        const err = (t) => { $('#f-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

        const no = $('[data-no]', h);
        if (no) no.onclick = () => cerrarHoja();

        const borrar = $('[data-borrar]', h);
        if (borrar) borrar.onclick = async () => {
          if (fam.n_elementos > 0) {
            return err(`Esta familia tiene ${fam.n_elementos} tipos dentro. Muévelos antes.`);
          }
          const ok = await confirmar({
            titulo: 'Eliminar la familia',
            texto: 'Los partes ya creados conservan su historial.',
            aceptar: 'Eliminar', peligro: true,
          });
          if (!ok) return;
          try {
            await api.borrarFamilia(fam.id_familia);
            cerrarHoja();
            mensaje('Familia eliminada.', 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };

        $('[data-si]', h).onclick = async () => {
          const nombre = $('#f-nombre', h).value.trim();
          if (!nombre) return err('Ponle un nombre.');
          const d = {
            nombre, clase: est.clase, icono: est.icono, color: est.color,
            descripcion: $('#f-desc', h).value.trim(),
            id_departamento: est.id_departamento,
            id_prioridad: est.id_prioridad,
          };
          try {
            if (esNueva) await api.crearFamilia(d);
            else await api.editarFamilia(fam.id_familia, d);
            cerrarHoja();
            mensaje('Familia guardada.', 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { err(e.message); }
        };
      },
    });
  }

  /** Mover tipos de una familia a otra, en bloque. */
  async function moverTipos(fam, datos, alTerminar) {
    let lista;
    try { lista = (await api.familiaElementos(fam.id_familia)).elementos; }
    catch (e) { mensaje(e.message, 'error'); return; }

    const marcados = new Set();
    const cuerpo = document.createElement('div');

    const pintar = () => {
      cuerpo.innerHTML = `
        <p class="campo__ayuda" style="margin-bottom:10px">
          Marca los que estén mal clasificados y muévelos de una vez.
        </p>
        <div class="filas">
          ${lista.map((e) => `
            <button type="button" class="fila" data-e="${e.id_elemento}"
                    style="${marcados.has(e.id_elemento) ? 'background:var(--marca-tenue)' : ''}">
              <span class="marca" style="width:20px;text-align:center;color:var(--marca);font-weight:700">
                ${marcados.has(e.id_elemento) ? '✓' : ''}</span>
              <span class="fila__texto">${esc(e.nombre_elemento)}
                ${e.usos ? `<small>${e.usos} partes</small>` : ''}</span>
            </button>`).join('')}
        </div>`;
      cuerpo.querySelectorAll('[data-e]').forEach((b) => {
        b.onclick = () => {
          const id = parseInt(b.dataset.e, 10);
          if (marcados.has(id)) marcados.delete(id); else marcados.add(id);
          pintar();
          actualizarPie();
        };
      });
    };

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cerrar</button>
                     <button type="button" class="btn btn--principal" data-mover disabled>Mover</button>`;

    let refPie = null;
    const actualizarPie = () => {
      if (!refPie) return;
      const b = $('[data-mover]', refPie);
      b.disabled = marcados.size === 0;
      b.textContent = marcados.size ? `Mover ${marcados.size}` : 'Mover';
    };

    pintar();
    hoja({
      titulo: fam.nombre + ' · ' + lista.length + ' tipos',
      cuerpo, pie,
      alAbrir: (h) => {
        refPie = h;
        $('[data-no]', h).onclick = () => cerrarHoja();
        $('[data-mover]', h).onclick = async () => {
          const destino = await elegir({
            titulo: 'Mover a',
            opciones: datos.familias
              .filter((f) => f.id_familia !== fam.id_familia)
              .map((f) => ({ valor: f.id_familia, texto: f.nombre, color: f.color,
                             detalle: f.clase })),
          });
          if (!destino) return;
          try {
            const r = await api.moverElementos([...marcados], destino);
            cerrarHoja();
            cerrarHoja();
            mensaje(`${r.movidos} tipos movidos.`, 'ok');
            await recargarCatalogo();
            alTerminar();
          } catch (e) { mensaje(e.message, 'error'); }
        };
      },
    });
  }

  function nombreDep(id) {
    if (!id) return 'Sin grupo fijo';
    return app.catalogo?.departamentos?.find((d) => d.id_departamento === id)?.nombre || '#' + id;
  }
  function nombrePrio(id) {
    if (!id) return 'La normal';
    return app.catalogo?.prioridades?.find((p2) => p2.id_prioridad === id)?.nombre || '#' + id;
  }
  async function recargarCatalogo() {
    const c = await api.catalogo();
    app.catalogo = c;
    await db.ajuste('catalogo', c);
  }

  /* ================================================================ */
  /* Campos por tipo de avería                                        */
  /* ================================================================ */
  async function campos() {
    let lista;
    try { lista = (await api.campos()).campos; }
    catch (e) { return error(e); }

    const porElemento = {};
    lista.forEach((c) => {
      const k = c.id_elemento === null ? 'general' : c.nombre_elemento || ('#' + c.id_elemento);
      (porElemento[k] = porElemento[k] || []).push(c);
    });

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Un campo propio hace que, al elegir ese tipo de avería, el parte
        pida además esos datos. Así el aire acondicionado puede preguntar
        la temperatura sin que el resto de partes se llenen de casillas.
      </div></div>

      <button type="button" class="btn btn--principal btn--bloque" data-nuevo style="margin-bottom:16px">
        ${icono('mas')}Añadir un campo
      </button>

      ${Object.keys(porElemento).length ? Object.entries(porElemento).map(([nombre, cs]) => `
        <div class="seccion"><h2>${nombre === 'general' ? 'En todos los partes' : esc(nombre)}</h2>
          <span class="rotulo">${cs.length}</span></div>
        <div class="filas">
          ${cs.map((c) => `
            <button type="button" class="fila" data-campo="${c.id_campo}" ${c.activo ? '' : 'style="opacity:.5"'}>
              ${icono(iconoTipo(c.tipo))}
              <span class="fila__texto">${esc(c.etiqueta)}
                <small>${esc(tipoTexto(c.tipo))}${c.unidad ? ' · ' + esc(c.unidad) : ''}
                  ${c.obligatorio ? ' · obligatorio' : ''}${c.activo ? '' : ' · desactivado'}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </button>`).join('')}
        </div>`).join('') : estadoVacio({
          icono: 'nota', titulo: 'Todavía no hay campos propios',
          texto: 'Los partes piden sólo lo básico.',
        })}`;

    $('[data-nuevo]', contenido).onclick = () => editarCampo(null, campos);
    $$('[data-campo]', contenido).forEach((b) => {
      b.onclick = () => editarCampo(lista.find((c) => c.id_campo === parseInt(b.dataset.campo, 10)), campos);
    });
  }

  function iconoTipo(t) {
    return { lista: 'lista', numero: 'panel', temperatura: 'viento',
             interruptor: 'ok', fecha: 'calendario', area: 'nota' }[t] || 'nota';
  }
  function tipoTexto(t) {
    return { texto: 'Texto corto', area: 'Texto largo', numero: 'Número',
             lista: 'Lista de opciones', interruptor: 'Sí o no',
             fecha: 'Fecha', temperatura: 'Temperatura' }[t] || t;
  }

  function editarCampo(campo, alTerminar) {
    const esNuevo = !campo;
    const est = {
      tipo: campo?.tipo || 'texto',
      id_elemento: campo?.id_elemento ?? null,
      opciones: campo?.opciones || [],
    };

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="c-etiqueta">Qué se pregunta</label>
        <input class="entrada" id="c-etiqueta" maxlength="120"
               value="${esc(campo?.etiqueta || '')}" placeholder="Temperatura de la sala"></div>

      ${esNuevo ? `<div class="campo"><label for="c-clave">Nombre interno</label>
        <input class="entrada" id="c-clave" maxlength="40" placeholder="aa_temp_real">
        <div class="campo__ayuda">Sólo letras, números y guión bajo. No se puede cambiar después.</div></div>` : ''}

      <div class="campo">
        <label>Tipo de dato</label>
        <div class="opciones" id="c-tipo">
          ${['texto','area','numero','temperatura','lista','interruptor','fecha'].map((t) => `
            <button type="button" class="opcion" data-t="${t}" aria-pressed="${est.tipo === t}">
              ${esc(tipoTexto(t))}</button>`).join('')}
        </div>
      </div>

      <div class="campo" id="c-opciones-caja" ${est.tipo === 'lista' ? '' : 'hidden'}>
        <label for="c-opciones">Opciones de la lista</label>
        <textarea class="area" id="c-opciones" style="min-height:88px"
                  placeholder="Una por línea">${esc((est.opciones || []).join('\n'))}</textarea>
      </div>

      <div class="campo"><label for="c-unidad">Unidad</label>
        <input class="entrada" id="c-unidad" maxlength="16"
               value="${esc(campo?.unidad || '')}" placeholder="°C, bar, litros… (opcional)"></div>

      <div class="campo">
        <label>Se pregunta en</label>
        <button type="button" class="fila" id="c-elemento"
                style="border-radius:var(--r-m);border:1.5px solid var(--borde)">
          ${icono('llaveinglesa')}
          <span class="fila__texto" id="c-txt-elemento">${esc(nombreElemento(est.id_elemento))}
            <small>Toca para cambiar</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('aviso')}
        <span class="fila__texto">Obligatorio<small>No se puede guardar el parte sin rellenarlo</small></span>
        <button type="button" class="interruptor" id="c-obl" role="switch"
                aria-checked="${campo?.obligatorio ? 'true' : 'false'}"></button>
      </div>

      <div class="campo" style="margin-top:14px"><label for="c-ayuda">Texto de ayuda</label>
        <input class="entrada" id="c-ayuda" maxlength="200" value="${esc(campo?.ayuda || '')}"
               placeholder="Opcional"></div>

      <div id="c-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = esNuevo
      ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
         <button type="button" class="btn btn--principal" data-si>Crear</button>`
      : `<button type="button" class="btn btn--peligro" data-borrar>Desactivar</button>
         <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: esNuevo ? 'Campo nuevo' : campo.etiqueta,
      cuerpo, pie,
      alAbrir: (h) => {
        /* El nombre interno se propone solo a partir del visible: pedirle
           a alguien que se invente «aa_temp_real» para poder guardar es
           una barrera sin motivo. Se puede cambiar antes de guardar. */
        const elEtiqueta = $('#c-etiqueta', h);
        const elClave    = $('#c-clave', h);
        if (elEtiqueta && elClave) {
          let tocadaAMano = false;
          elClave.oninput = () => { tocadaAMano = true; };
          elEtiqueta.oninput = () => {
            if (tocadaAMano) return;
            elClave.value = elEtiqueta.value
              .toLowerCase()
              .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
              .replace(/[^a-z0-9]+/g, '_')
              .replace(/^_+|_+$/g, '')
              .slice(0, 40);
          };
        }
        $('#c-tipo', h).onclick = (e) => {
          const b = e.target.closest('[data-t]');
          if (!b) return;
          $$('[data-t]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.tipo = b.dataset.t;
          $('#c-opciones-caja', h).hidden = est.tipo !== 'lista';
        };

        $('#c-elemento', h).onclick = async () => {
          const opciones = [
            { valor: 0, texto: 'Todos los tipos', detalle: 'Se pregunta siempre' },
            ...(app.catalogo?.elementos || []).map((e) => ({
              valor: e.id_elemento, texto: e.nombre, extra: e.abreviatura,
            })),
          ];
          const v = await elegirBuscando({
            titulo: 'Se pregunta en',
            opciones,
            valor: est.id_elemento ?? 0,
            marcador: 'Buscar tipo de avería',
          });
          if (v === null) return;
          est.id_elemento = v || null;
          $('#c-txt-elemento', h).innerHTML =
            `${esc(nombreElemento(est.id_elemento))}<small>Toca para cambiar</small>`;
        };

        const obl = $('#c-obl', h);
        obl.onclick = () => obl.setAttribute('aria-checked',
          obl.getAttribute('aria-checked') !== 'true');

        const err = (t) => { $('#c-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

        const cerrar = $('[data-no]', h);
        if (cerrar) cerrar.onclick = () => cerrarHoja();

        const borrar = $('[data-borrar]', h);
        if (borrar) borrar.onclick = async () => {
          const ok = await confirmar({
            titulo: 'Desactivar el campo',
            texto: 'Dejará de pedirse en los partes nuevos. Los datos ya registrados se conservan.',
            aceptar: 'Desactivar', peligro: true,
          });
          if (!ok) return;
          await api.borrarCampo(campo.id_campo);
          cerrarHoja();
          mensaje('Campo desactivado.', 'ok');
          alTerminar();
        };

        $('[data-si]', h).onclick = async () => {
          const etiqueta = $('#c-etiqueta', h).value.trim();
          if (!etiqueta) return err('Escribe qué se pregunta.');

          const datos = {
            etiqueta,
            tipo: est.tipo,
            unidad: $('#c-unidad', h).value.trim(),
            ayuda: $('#c-ayuda', h).value.trim(),
            obligatorio: obl.getAttribute('aria-checked') === 'true',
            id_elemento: est.id_elemento,
          };
          if (est.tipo === 'lista') {
            const ops = $('#c-opciones', h).value.split('\n').map((x) => x.trim()).filter(Boolean);
            if (!ops.length) return err('Una lista necesita al menos una opción.');
            datos.opciones = ops;
          }

          try {
            if (esNuevo) {
              const clave = $('#c-clave', h).value.trim();
              if (!clave) return err('Falta el nombre interno.');
              await api.crearCampo({ ...datos, clave });
            } else {
              await api.editarCampo(campo.id_campo, datos);
            }
            cerrarHoja();
            mensaje('Campo guardado.', 'ok');
            const c = await api.catalogo();
            app.catalogo = c;
            await db.ajuste('catalogo', c);
            alTerminar();
          } catch (e) { err(e.message); }
        };
      },
    });
  }

  function nombreElemento(id) {
    if (!id) return 'Todos los tipos';
    return app.catalogo?.elementos?.find((e) => e.id_elemento === id)?.nombre || '#' + id;
  }

  /* ================================================================ */
  /* Atajos                                                           */
  /* ================================================================ */
  async function atajos() {
    let lista;
    try { lista = (await api.atajos()).atajos; }
    catch (e) { return error(e); }

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Un atajo es un filtro guardado con nombre. Aparece en la pantalla
        de inicio, para llegar de un toque a lo que más se consulta.
      </div></div>

      <button type="button" class="btn btn--principal btn--bloque" data-nuevo style="margin-bottom:16px">
        ${icono('mas')}Crear un atajo
      </button>

      ${lista.length ? `<div class="filas">
        ${lista.map((a) => `
          <button type="button" class="fila" data-a="${a.id_atajo}">
            <span class="avatar" style="background:${esc(a.color)}22;color:${esc(a.color)}">
              ${icono(a.icono)}</span>
            <span class="fila__texto">${esc(a.nombre)}
              <small>${esc(describeFiltro(a.filtro))}${a.mio ? ' · sólo tuyo' : ' · para todos'}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>` : estadoVacio({ icono: 'panel', titulo: 'Sin atajos', texto: '' })}`;

    $('[data-nuevo]', contenido).onclick = () => editarAtajo(null, atajos);
    $$('[data-a]', contenido).forEach((b) => {
      b.onclick = () => editarAtajo(lista.find((x) => x.id_atajo === parseInt(b.dataset.a, 10)), atajos);
    });
  }

  function describeFiltro(f) {
    if (!f) return '';
    const p = [];
    if (f.abiertos) p.push('abiertos');
    if (f.mios) p.push('míos');
    if (f.mi_grupo) p.push('de mi grupo');
    if (f.sin_asignar) p.push('sin asignar');
    if (f.vencidos) p.push('fuera de plazo');
    if (f.cerrados_semana) p.push('cerrados esta semana');
    if (f.prioridad?.length) p.push('prioridad concreta');
    if (f.elemento) p.push('un tipo concreto');
    return p.join(', ') || 'todos';
  }

  function editarAtajo(atajo, alTerminar) {
    const esNuevo = !atajo;
    const est = {
      icono: atajo?.icono || 'lista',
      color: atajo?.color || '#0B5563',
      filtro: atajo?.filtro || { abiertos: true },
      para_todos: atajo ? !atajo.mio : true,
    };

    const banderas = [
      ['abiertos', 'Sólo abiertos'],
      ['mios', 'Asignados a mí'],
      ['mi_grupo', 'De mi grupo'],
      ['sin_asignar', 'Sin asignar'],
      ['vencidos', 'Fuera de plazo'],
      ['cerrados_semana', 'Cerrados esta semana'],
    ];
    const colores = ['#0B5563', '#C7362B', '#B45309', '#0D7C57', '#7C3AED', '#0EA5E9'];
    const iconos = ['lista', 'aviso', 'reloj', 'ok', 'personas', 'mios', 'banderin', 'panel'];

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="a-nombre">Nombre</label>
        <input class="entrada" id="a-nombre" maxlength="40"
               value="${esc(atajo?.nombre || '')}" placeholder="Urgentes de mi grupo"></div>

      <div class="campo"><label>Qué muestra</label>
        <div class="opciones" id="a-banderas">
          ${banderas.map(([k, t]) => `
            <button type="button" class="opcion" data-f="${k}"
                    aria-pressed="${!!est.filtro[k]}">${esc(t)}</button>`).join('')}
        </div>
      </div>

      <div class="campo"><label>Icono</label>
        <div class="opciones" id="a-iconos">
          ${iconos.map((i) => `
            <button type="button" class="opcion" data-i="${i}" aria-pressed="${est.icono === i}"
                    style="min-width:46px;justify-content:center">${icono(i)}</button>`).join('')}
        </div>
      </div>

      <div class="campo"><label>Color</label>
        <div class="opciones" id="a-colores">
          ${colores.map((c) => `
            <button type="button" class="opcion" data-c="${c}" aria-pressed="${est.color === c}"
                    style="min-width:46px;justify-content:center;--c:${c}" data-color>
              <span style="width:18px;height:18px;border-radius:50%;background:${c};display:block"></span>
            </button>`).join('')}
        </div>
      </div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('personas')}
        <span class="fila__texto">Para todo el equipo<small>Si no, sólo lo verás tú</small></span>
        <button type="button" class="interruptor" id="a-todos" role="switch"
                aria-checked="${est.para_todos}"></button>
      </div>

      <div id="a-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = esNuevo
      ? `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
         <button type="button" class="btn btn--principal" data-si>Crear</button>`
      : `<button type="button" class="btn btn--peligro" data-borrar>Eliminar</button>
         <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: esNuevo ? 'Atajo nuevo' : atajo.nombre,
      cuerpo, pie,
      alAbrir: (h) => {
        $('#a-banderas', h).onclick = (e) => {
          const b = e.target.closest('[data-f]');
          if (!b) return;
          const k = b.dataset.f;
          if (est.filtro[k]) { delete est.filtro[k]; b.setAttribute('aria-pressed', 'false'); }
          else { est.filtro[k] = true; b.setAttribute('aria-pressed', 'true'); }
        };
        $('#a-iconos', h).onclick = (e) => {
          const b = e.target.closest('[data-i]');
          if (!b) return;
          $$('[data-i]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.icono = b.dataset.i;
        };
        $('#a-colores', h).onclick = (e) => {
          const b = e.target.closest('[data-c]');
          if (!b) return;
          $$('[data-c]', h).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.color = b.dataset.c;
        };
        const todos = $('#a-todos', h);
        todos.onclick = () => {
          const n = todos.getAttribute('aria-checked') !== 'true';
          todos.setAttribute('aria-checked', String(n));
          est.para_todos = n;
        };

        const cerrar = $('[data-no]', h);
        if (cerrar) cerrar.onclick = () => cerrarHoja();

        const borrar = $('[data-borrar]', h);
        if (borrar) borrar.onclick = async () => {
          const ok = await confirmar({
            titulo: 'Eliminar el atajo',
            texto: 'Desaparecerá de la pantalla de inicio. Los partes no se tocan.',
            aceptar: 'Eliminar', peligro: true,
          });
          if (!ok) return;
          await api.borrarAtajo(atajo.id_atajo);
          cerrarHoja();
          mensaje('Atajo eliminado.', 'ok');
          alTerminar();
        };

        $('[data-si]', h).onclick = async () => {
          const nombre = $('#a-nombre', h).value.trim();
          if (!nombre) {
            $('#a-err', h).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>Ponle un nombre.</div></div>`;
            return;
          }
          try {
            const datos = { nombre, icono: est.icono, color: est.color,
                            filtro: est.filtro, para_todos: est.para_todos };
            if (esNuevo) await api.crearAtajo(datos);
            else await api.editarAtajo(atajo.id_atajo, datos);
            cerrarHoja();
            mensaje('Atajo guardado.', 'ok');
            const c = await api.catalogo();
            app.catalogo = c;
            await db.ajuste('catalogo', c);
            alTerminar();
          } catch (e) {
            $('#a-err', h).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  /* ================================================================ */
  /* Copias de seguridad                                              */
  /* ================================================================ */
  /* ================================================================ */
  /* Integridad: ¿está todo en su sitio?                              */
  /* ================================================================ */
  /* Sólo mira. Cada línea dice qué ha comprobado y, si algo está mal,
     qué hacer: arreglar a escondidas al abrir una pantalla no es buena
     idea, y algunas cosas (el servidor, el certificado) ni siquiera se
     pueden arreglar desde aquí. La lógica está en api/lib/Integridad.php,
     la misma que usa php tools/diagnostico.php. */
  async function integridad() {
    contenido.innerHTML = bloqueCarga(5);
    let d;
    try { d = await api.integridad(); }
    catch (e) { return error(e); }

    const ESTADO = {
      falla: { ico: 'aviso',   color: 'var(--peligro, #B42318)', texto: 'Falla' },
      aviso: { ico: 'info',    color: 'var(--senal, #B54708)',   texto: 'Aviso' },
      nd:    { ico: 'info',    color: 'var(--tinta-3)',          texto: 'Sin comprobar' },
      bien:  { ico: 'ok',      color: 'var(--ok, #067647)',      texto: 'Bien' },
    };
    const orden = { falla: 0, aviso: 1, nd: 2, bien: 3 };
    const r = d.resumen || {};
    const grupos = [];
    for (const c of d.comprobaciones || []) {
      if (!grupos.includes(c.grupo)) grupos.push(c.grupo);
    }
    const cuantos = (n, uno, varios) => `${n || 0} ${(n || 0) === 1 ? uno : varios}`;
    /* Lo que falla o avisa, arriba y con su grupo delante: con treinta y
       tantas líneas, «1 cosa falla» sin decir dónde obligaba a buscarla. */
    const mirar = (d.comprobaciones || []).filter((c) => c.estado === 'falla' || c.estado === 'aviso')
      .sort((a, b) => (orden[a.estado] ?? 9) - (orden[b.estado] ?? 9));
    const linea = (c, conGrupo = false) => {
      const e = ESTADO[c.estado] || ESTADO.nd;
      return `
        <div class="fila" style="cursor:default;align-items:flex-start" data-comprobacion="${esc(c.id)}"
             data-estado="${esc(c.estado)}">
          ${icono(e.ico).replace('<svg', `<svg style="color:${e.color}"`)}
          <span class="fila__texto">${conGrupo ? `<span class="rotulo">${esc(c.grupo)}</span> ` : ''}${esc(c.titulo)}
            ${c.detalle && c.estado !== 'bien' ? `<small>${esc(c.detalle)}</small>` : ''}</span>
          <span class="rotulo" style="color:${e.color};white-space:nowrap">${e.texto}</span>
        </div>`;
    };

    contenido.innerHTML = `
      <div class="aviso ${r.falla ? 'aviso--error' : (r.aviso ? 'aviso--senal' : 'aviso--info')}">
        ${icono(r.falla ? 'aviso' : 'info')}<div>
        ${r.falla ? `<strong>${r.falla} ${r.falla === 1 ? 'cosa falla' : 'cosas fallan'}.</strong> `
                  : (r.aviso ? '<strong>Nada falla.</strong> ' : '<strong>Todo en su sitio.</strong> ')}
        ${r.bien || 0} bien · ${cuantos(r.aviso, 'aviso', 'avisos')} · ${cuantos(r.falla, 'fallo', 'fallos')}
        ${r.nd ? ` · ${r.nd} sin comprobar` : ''}.
        Esto sólo mira: no cambia nada.
      </div></div>

      ${mirar.length ? `
        <div class="seccion"><h2>Lo que hay que mirar</h2></div>
        <div class="filas">${mirar.map((c) => linea(c, true)).join('')}</div>` : ''}

      <div class="acciones" style="margin:12px 0 4px">
        <button type="button" class="btn btn--contorno" data-otra-vez>${icono('sync')}Volver a comprobar</button>
      </div>

      ${grupos.map((g) => {
        const suyas = (d.comprobaciones || []).filter((c) => c.grupo === g)
          .sort((a, b) => (orden[a.estado] ?? 9) - (orden[b.estado] ?? 9));
        return `<div class="seccion"><h2>${esc(g)}</h2></div>
                <div class="filas">${suyas.map((c) => linea(c)).join('')}</div>`;
      }).join('')}

      <p class="campo__ayuda" style="margin-top:14px">
        Comprobado ${esc(fechaLarga(d.fecha))}. Desde la consola del servidor,
        <code>php tools/diagnostico.php</code> hace lo mismo y además mira el certificado.
      </p>`;

    contenido.querySelector('[data-otra-vez]').onclick = () => integridad();
  }

  async function copias() {
    let datos;
    try { datos = await api.copias(); }
    catch (e) { return error(e); }

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        La copia incluye partes, historial, personas, catálogos y
        configuración, en un formato que sirve igual para MySQL o SQLite.
        Descárgala y guárdala fuera del servidor: una copia que vive en la
        misma máquina no protege de que esa máquina falle.
      </div></div>

      <div class="acciones" style="margin-bottom:18px">
        <button type="button" class="btn btn--principal" data-crear>${icono('guardar')}Crear copia</button>
        <button type="button" class="btn btn--contorno" data-crear-fotos>${icono('imagen')}Con fotos</button>
      </div>

      ${datos.copias.length ? `
        <div class="seccion"><h2>Copias guardadas</h2>
          <span class="rotulo">se conservan las 10 últimas</span></div>
        <div class="filas">
          ${datos.copias.map((c) => `
            <div class="fila" style="cursor:default">
              ${icono('archivo')}
              <span class="fila__texto">${esc(fechaLarga(c.fecha))}
                <small>${(c.tamano / 1024).toFixed(0)} KB · ${c.registros} registros
                  ${c.incluye_fotos ? ' · con fotos' : ''}
                  ${c.existe ? '' : ' · FALTA EL ARCHIVO'}</small></span>
              ${c.existe ? `<button type="button" class="icono-btn" data-bajar="${c.id_copia}"
                    aria-label="Descargar" style="min-width:40px;height:40px">${icono('descarga')}</button>` : ''}
              <button type="button" class="icono-btn" data-borrar="${c.id_copia}"
                    aria-label="Eliminar" style="min-width:40px;height:40px">${icono('papelera')}</button>
            </div>`).join('')}
        </div>` : `<p class="campo__ayuda">Todavía no hay ninguna copia.</p>`}

      <div class="aviso aviso--senal" style="margin-top:18px">${icono('aviso')}<div>
        <strong>Para restaurar</strong> hace falta acceso al servidor:
        <code>php tools/restaurar.php archivo.json.gz</code>.
        No se puede hacer desde aquí a propósito: es una operación que
        borra los datos actuales y no debe estar a un toque de distancia.
      </div></div>`;

    const crear = async (conFotos) => {
      const b = $(conFotos ? '[data-crear-fotos]' : '[data-crear]', contenido);
      b.disabled = true;
      b.innerHTML = `${icono('sync', 'girando')}Creando…`;
      try {
        const r = await api.crearCopia(conFotos);
        tono('ok');

        /* Si el servidor no puede empaquetar fotos hay que decirlo.
           Antes la copia salía «ok» llevando sólo los datos: al
           restaurar quedaban partes señalando imágenes que ya no
           existen, y eso no se descubre hasta que alguien abre un parte
           viejo buscando justo esa foto. */
        if (conFotos && r.copia?.con_fotos === false) {
          mensaje(r.copia.aviso || 'La copia no lleva las fotos.', 'aviso', 9000);
        } else {
          mensaje(`Copia creada: ${r.copia.registros} registros`
                  + (r.copia?.con_fotos ? ' y las fotos.' : '.'), 'ok');
        }
        copias();
      } catch (e) {
        mensaje(e.message, 'error');
        b.disabled = false;
        b.innerHTML = conFotos ? `${icono('imagen')}Con fotos` : `${icono('guardar')}Crear copia`;
      }
    };

    $('[data-crear]', contenido).onclick = () => crear(false);
    $('[data-crear-fotos]', contenido).onclick = () => crear(true);

    $$('[data-bajar]', contenido).forEach((b) => {
      b.onclick = async () => {
        /* La descarga necesita una navegación de verdad, y una
           navegación no lleva cabecera «Authorization». Antes se metía
           el token de sesión en la dirección, y ahí se quedaba: en el
           registro del servidor, en el historial y en el Referer.
           Ahora se pide un vale de 60 segundos atado a esta copia. */
        try {
          const r = await api.valeCopia(b.dataset.bajar);
          window.open(enlaceCopia(b.dataset.bajar, r.vale), '_blank');
        } catch (e) {
          mensaje(e.message || 'No se ha podido preparar la descarga.', 'mal');
        }
      };
    });

    $$('[data-borrar]', contenido).forEach((b) => {
      b.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Eliminar la copia',
          texto: 'Se borra del servidor. Si ya la has descargado, esa sigue siendo válida.',
          aceptar: 'Eliminar', peligro: true,
        });
        if (!ok) return;
        await api.borrarCopia(parseInt(b.dataset.borrar, 10));
        mensaje('Copia eliminada.', 'ok');
        copias();
      };
    });
  }

  /* ================================================================ */
  function error(e) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
  }

  function pedirTexto(titulo, valor, tipo = 'text') {
    return new Promise((resolve) => {
      let resuelto = false;
      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `<div class="campo"><label for="pt">${esc(titulo)}</label>
        <input class="entrada" id="pt" type="${tipo}" value="${esc(valor)}"></div>`;
      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                       <button type="button" class="btn btn--principal" data-si>Aceptar</button>`;
      hoja({
        titulo, cuerpo, pie,
        alAbrir: (h) => {
          setTimeout(() => $('#pt', h).select(), 250);
          $('[data-no]', h).onclick = () => cerrarHoja();
          $('[data-si]', h).onclick = () => {
            resuelto = true; resolve($('#pt', h).value.trim()); cerrarHoja();
          };
        },
        alCerrar: () => { if (!resuelto) resolve(null); },
      });
    });
  }
}

/* Enlace de descarga de una copia, con un vale de 60 segundos.
 *
 * Antes llevaba aquí el token de sesión, y una dirección no es un sitio
 * discreto: esa línea apareció 134 veces en el registro de Apache con
 * tokens de administrador completos y en claro, que valen 30 días. El
 * vale también queda escrito, pero al minuto siguiente no sirve para
 * nada, y sólo sirve para esta copia y para quien lo pidió. */
function enlaceCopia(id, vale) {
  const base = localStorage.getItem('hurakantick_api') || '';
  return `${base}/index.php?_ruta=copias/${id}&vale=${encodeURIComponent(vale)}`;
}
