/* =====================================================================
   Iconografía.

   Cambiar cualquier icono de la aplicación por uno de Font Awesome, que
   va empaquetado: 1.353 iconos sin depender de internet.

   Cada icono tiene un nombre interno («papelera», «aviso»…) y aquí se
   enseña con un nombre en castellano y dónde se usa, porque «banderin»
   no le dice nada a nadie. Para cada uno se sugieren los de Font Awesome
   que mejor encajan, y además se puede buscar entre todos.

   Lo que se guarda es un mapa pequeño: nombre interno → código del
   icono. Mientras esté vacío, la aplicación dibuja los suyos de siempre,
   así que esto es reversible del todo: basta con «Conservar el actual»
   —o «Dejarlo todo como estaba»— para volver.
   ===================================================================== */

import { api } from '../api.js';
import {
  $, icono, esc, mensaje, hoja, cerrarHoja, confirmar,
  nombresDeIconos, fijarIconosPersonalizados, iconoPersonalizado, textoPlano, enfocarBuscador } from '../ui.js';
import { pintarCabecera, app } from '../app.js';
import { cabeceraVolver } from '../piezas.js';

/* Nombre en castellano, dónde se ve, y qué sugerir de Font Awesome.
   Las sugerencias van de más a menos parecido al dibujo actual. */
const CATALOGO = [
  ['Moverse por la aplicación', [
    ['panel',     'Inicio',              'La barra de abajo',            ['house', 'table-columns', 'gauge']],
    ['lista',     'Partes',              'La barra de abajo',            ['list', 'list-check', 'rectangle-list']],
    ['mios',      'Míos',                'La barra de abajo',            ['user', 'user-check', 'circle-user']],
    ['ajustes',   'Ajustes',             'La barra de abajo',            ['gear', 'sliders', 'screwdriver-wrench']],
    ['mas',       'Dar un parte',        'El botón grande del centro',   ['plus', 'circle-plus', 'square-plus']],
    ['atras',     'Volver',              'Arriba a la izquierda',        ['chevron-left', 'arrow-left', 'angle-left']],
    ['cerrar',    'Cerrar',              'Paneles y ventanas',           ['xmark', 'circle-xmark', 'x']],
    ['flecha',    'Entrar en algo',      'Al final de cada fila',        ['chevron-right', 'angle-right', 'arrow-right']],
    ['mas-puntos','Más opciones',        'Arriba a la derecha',          ['ellipsis-vertical', 'ellipsis', 'bars']],
  ]],
  ['Partes y trabajo', [
    ['aviso',     'Aviso',               'Urgencias y errores',          ['triangle-exclamation', 'circle-exclamation', 'bell']],
    ['ok',        'Correcto',            'Confirmaciones',               ['circle-check', 'check', 'square-check']],
    ['info',      'Información',         'Notas y avisos suaves',        ['circle-info', 'lightbulb', 'comment']],
    ['banderin',  'Estado',              'Filtros y cambios de estado',  ['flag', 'bookmark', 'tag']],
    ['reloj',     'Plazo',               'Fuera de plazo y tiempos',     ['clock', 'hourglass-half', 'stopwatch']],
    ['calendario','Fecha',               'Plazos y mantenimiento',       ['calendar-days', 'calendar', 'calendar-check']],
    ['nota',      'Nota',                'Notas del parte',              ['pen', 'note-sticky', 'comment-dots']],
    ['editar',    'Editar',              'Modificar cosas',              ['pen-to-square', 'pencil', 'file-pen']],
    ['guardar',   'Guardar',             'Botones de guardar',           ['floppy-disk', 'check', 'download']],
    ['papelera',  'Papelera',            'Eliminar y papelera',          ['trash-can', 'trash', 'dumpster']],
    ['ojo-no',    'Ocultar',             'Ocultar de las listas',        ['eye-slash', 'eye', 'ban']],
    ['filtro',    'Ordenar y filtrar',   'La fila de filtros',           ['filter', 'arrow-down-short-wide', 'sliders']],
    ['buscar',    'Buscar',              'Todos los buscadores',         ['magnifying-glass', 'search', 'binoculars']],
  ]],
  ['Sitios y cosas', [
    ['ubicacion', 'Ubicación',           'Dónde está la avería',         ['location-dot', 'map-pin', 'map-location-dot']],
    ['edificio',  'Hotel',               'Hoteles de la cadena',         ['hotel', 'building', 'city']],
    ['llaveinglesa','Tipo de avería',    'Tipos y familias',             ['wrench', 'screwdriver-wrench', 'toolbox']],
    ['herramientas','Herramientas',      'Mantenimiento',                ['screwdriver-wrench', 'toolbox', 'hammer']],
    ['gota',      'Fontanería',          'Familia de avería',            ['droplet', 'faucet', 'shower']],
    ['rayo',      'Electricidad',        'Familia de avería',            ['bolt', 'plug', 'lightbulb']],
    ['martillo',  'Carpintería y obra',  'Familia de avería',            ['hammer', 'trowel-bricks', 'screwdriver']],
    ['silla',     'Mobiliario',          'Familia de avería',            ['chair', 'couch', 'bed']],
    ['viento',    'Climatización',       'Familia de avería',            ['fan', 'wind', 'temperature-half']],
    ['tele',      'Televisión',          'Familia de avería',            ['tv', 'display', 'satellite-dish']],
    ['wifi',      'Redes',               'Familia de avería',            ['wifi', 'network-wired', 'tower-broadcast']],
    ['llave',     'Cerrajería',          'Familia de avería y accesos',  ['key', 'lock', 'door-closed']],
    ['brillo',    'Iluminación',         'Familia de avería',            ['lightbulb', 'sun', 'bolt']],
  ]],
  ['Personas y seguridad', [
    ['persona',   'Persona',             'Equipo y asignaciones',        ['user', 'circle-user', 'id-badge']],
    ['personas',  'Departamento',        'Grupos y reparto',             ['users', 'user-group', 'people-roof']],
    ['candado',   'Seguridad',           'Ajustes protegidos',           ['lock', 'shield-halved', 'user-shield']],
    ['salir',     'Cerrar sesión',       'Salir de la aplicación',       ['right-from-bracket', 'power-off', 'door-open']],
    ['campana',   'Avisos',              'La campana de arriba',         ['bell', 'bell-concierge', 'envelope']],
  ]],
  ['Archivos y fotos', [
    ['camara',    'Hacer una foto',      'Añadir foto al parte',         ['camera', 'camera-retro', 'image']],
    ['imagen',    'Foto',                'Fotos del parte',              ['image', 'images', 'photo-film']],
    ['adjunto',   'Adjunto',             'Ficheros del parte',           ['paperclip', 'file-arrow-up', 'link']],
    ['fichero',   'Fichero',             'Documentos',                   ['file', 'file-lines', 'file-pdf']],
    ['archivo',   'Archivo',             'Copias y papelera',            ['box-archive', 'boxes-stacked', 'folder']],
    ['libro',     'Guía de uso',         'La guía',                      ['book', 'book-open', 'circle-question']],
    ['descarga',  'Descargar',           'Exportar e informes',          ['download', 'file-export', 'share-nodes']],
    ['copiar',    'Copiar',              'Copiar al portapapeles',       ['copy', 'clipboard', 'paste']],
  ]],
  ['Estado del sistema', [
    ['sync',      'Sincronizar',         'La tira de sincronización',    ['rotate', 'arrows-rotate', 'cloud-arrow-up']],
    ['nube',      'Pendiente de enviar', 'Cambios sin subir',            ['cloud', 'cloud-arrow-up', 'inbox']],
    ['sinred',    'Sin conexión',        'Cuando no hay cobertura',      ['plug-circle-xmark', 'wifi', 'signal']],
    ['movil',     'Móvil',               'Sesiones y vista smartphone',  ['mobile-screen', 'mobile', 'tablet-screen-button']],
    ['luna',      'Modo oscuro',         'Apariencia',                   ['moon', 'circle-half-stroke', 'sun']],
    ['flecha-arriba', 'Subir',           'Ordenar bloques',              ['chevron-up', 'arrow-up', 'caret-up']],
    ['flecha-abajo',  'Bajar',           'Ordenar bloques',              ['chevron-down', 'arrow-down', 'caret-down']],
  ]],
];

let iconosFA = null;      // el índice, se pide una vez
let aliasFA = {};         // nombres antiguos → nombre actual
let mapa = {};            // lo que hay guardado ahora
let sucio = false;

async function indiceFA() {
  if (iconosFA) return iconosFA;
  const r = await fetch('assets/iconos/fontawesome.json');
  const d = await r.json();
  iconosFA = d.iconos || d;
  aliasFA = d.alias || {};
  return iconosFA;
}

/* Font Awesome mantiene nombres antiguos: «zap» es «bolt», «home» es
   «house». Se resuelven, para que una sugerencia escrita con cualquiera
   de los dos nombres encuentre su icono en vez de desaparecer. */
function resolver(nombre, todos) {
  if (todos[nombre]) return nombre;
  const c = aliasFA[nombre];
  return c && todos[c] ? c : null;
}

/** Dibuja un icono de Font Awesome por su código. */
function dibujo(codigo) {
  return `<span class="fa" aria-hidden="true">&#x${esc(codigo)};</span>`;
}

/**
 * Elegir un icono de Font Awesome, para usar desde cualquier pantalla.
 *
 * Lo usa la ficha de cada familia: antes sólo se podía elegir entre diez
 * dibujos, y por eso «Lencería y textil» y «Limpieza y repasos» acababan
 * con el mismo. Devuelve «fa-XXXX», que es lo que entiende icono().
 */
/* Qué sugerir según lo que diga el nombre: «sábana» encuentra «bed»,
   «cerradura» encuentra «lock». Es lo que evita que alguien tenga que
   pensar en inglés para poner un icono. */
const PISTAS = [
  [/lencer|textil|s[áa]bana|toalla|colch/i, ['bed', 'shirt', 'basket-shopping']],
  [/limpieza|repaso|fregona|aspir/i,        ['broom', 'spray-can-sparkles', 'soap']],
  [/petici|cliente|hu[ée]sped|conserj/i,    ['bell-concierge', 'user', 'hand-holding-heart']],
  [/inform[áa]tica|red|wifi|ordenador|tpv/i,['wifi', 'network-wired', 'computer']],
  [/fontaner|agua|grifo|lavabo|ducha|wc|cisterna|inodoro/i, ['droplet', 'faucet', 'toilet']],
  [/electric|enchufe|luz|l[áa]mpara|bombill/i, ['bolt', 'plug', 'lightbulb']],
  [/climatiz|aire|fan\s*coil|extractor|caldera|radiador/i, ['fan', 'temperature-half', 'fire-flame-simple']],
  [/carpinter|puerta|ventana|obra|pared|techo/i, ['door-closed', 'hammer', 'trowel-bricks']],
  [/mobiliario|silla|mesa|armario|sof[áa]|cama/i, ['chair', 'couch', 'bed']],
  [/cerrajer|cerradura|llave|acceso|caja fuerte/i, ['lock', 'key', 'vault']],
  [/televis|tv|sonido|m[úu]sica|altavoz/i,  ['tv', 'volume-high', 'music']],
  [/cocina|office|horno|nevera|c[áa]mara|abatidor|lavavajillas/i, ['kitchen-set', 'utensils', 'blender']],
  [/seguridad|emergencia|incendio|extintor|alarma/i, ['fire-extinguisher', 'shield-halved', 'bell']],
  [/ascensor|montacarg/i,                   ['elevator', 'arrows-up-down', 'building']],
  [/piscina|spa|jacuzzi|sauna/i,            ['person-swimming', 'hot-tub-person', 'water-ladder']],
  [/jardin|planta|riego|c[ée]sped|[áa]rbol/i, ['seedling', 'tree', 'leaf']],
  [/persiana|cortina|toldo/i,               ['window-maximize', 'blinds', 'umbrella-beach']],
  [/parking|garaje|coche|veh[íi]cul/i,      ['square-parking', 'car', 'car-side']],
  [/gimnasio|fitness|m[áa]quina de correr/i,['dumbbell', 'person-running', 'heart-pulse']],
  [/lavander|secadora|lavadora|plancha/i,   ['jug-detergent', 'shirt', 'temperature-arrow-up']],
];

export function sugerenciasPorNombre(nombre) {
  for (const [patron, iconos] of PISTAS) {
    if (patron.test(nombre || '')) return iconos;
  }
  return ['screwdriver-wrench', 'wrench', 'toolbox'];
}

export async function elegirIconoFA({ titulo = 'Elegir icono', sugeridos = [] } = {}) {
  const todos = await indiceFA();
  return new Promise((resolve) => {
    let resuelto = false;
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo">
        <input class="entrada" type="search" id="fa-q" autocomplete="off"
               placeholder="Buscar entre ${Object.keys(todos).length} iconos">
      </div>
      <div id="fa-cuenta" class="rotulo" style="padding:10px 4px 6px"></div>
      <div id="fa-rejilla" class="rejilla-iconos"></div>`;

    const rejilla = cuerpo.querySelector('#fa-rejilla');
    const cuenta = cuerpo.querySelector('#fa-cuenta');

    function pintar(q) {
      const busca = (q || '').trim().toLowerCase();
      let lista;
      if (!busca) {
        lista = sugeridos.map((n) => resolver(n, todos)).filter(Boolean);
        cuenta.textContent = lista.length ? 'Sugerencias' : 'Escribe para buscar';
      } else {
        const porAlias = new Set(Object.entries(aliasFA)
          .filter(([a]) => a.includes(busca)).map(([, c]) => c));
        lista = Object.keys(todos).filter((n) =>
          n.includes(busca) || porAlias.has(n) || (todos[n].g || '').includes(busca)).slice(0, 120);
        cuenta.textContent = `${lista.length}${lista.length === 120 ? '+' : ''} iconos`;
      }
      rejilla.innerHTML = lista.map((n) => `
        <button type="button" class="icono-opcion" data-fa="${esc(todos[n].c)}" title="${esc(n)}">
          ${dibujo(todos[n].c)}<span>${esc(n)}</span>
        </button>`).join('');
      rejilla.querySelectorAll('[data-fa]').forEach((b) => {
        b.onclick = () => { resuelto = true; resolve('fa-' + b.dataset.fa); cerrarHoja(); };
      });
    }

    hoja({ titulo, cuerpo, alCerrar: () => { if (!resuelto) resolve(null); } });
    const campo = cuerpo.querySelector('#fa-q');
    let reloj = null;
    campo.addEventListener('input', () => { clearTimeout(reloj); reloj = setTimeout(() => pintar(campo.value), 130); });
    pintar('');
  });
}

export async function vistaIconografia({ contenido }) {
  // La cabecera se arma con cabeceraVolver: pasarle un objeto pintaba
  // literalmente «[object Object]» en el título.
  pintarCabecera(cabeceraVolver('Iconografía', 'Los iconos de la aplicación'));

  /* Sólo administradores: el cambio vale para todo el hotel. El servidor
     lo impide igualmente, pero enseñar una pantalla que no se puede usar
     sólo sirve para dar un error al guardar. */
  if (!app.usuario?.es_admin) {
    contenido.innerHTML = `<div class="aviso aviso--info">${icono('candado')}<div>
      Los iconos los cambia un administrador, y valen para todo el hotel.
    </div></div>`;
    return;
  }

  mapa = { ...(app.catalogo?.ajustes?.iconos || {}) };
  sucio = false;

  // Los que no estén en el catálogo de arriba, al final: así nunca queda
  // un icono fuera, aunque se añada uno nuevo al código y se olvide aquí.
  const listados = new Set(CATALOGO.flatMap(([, f]) => f.map((x) => x[0])));
  const sueltos = nombresDeIconos().filter((n) => !listados.has(n));
  const grupos = sueltos.length
    ? [...CATALOGO, ['Otros iconos', sueltos.map((n) => [n, n, '', []])]]
    : CATALOGO;

  contenido.innerHTML = `
    <div class="aviso aviso--info">${icono('info')}<div>
      Cambia el icono que quieras por uno de Font Awesome. Lo verá todo el
      hotel. Se puede volver al de siempre en cualquier momento.
    </div></div>

    ${grupos.map(([titulo, filas]) => `
      <div class="seccion"><h2>${esc(titulo)}</h2></div>
      <div class="filas">
        ${filas.map(([nombre, etiqueta, donde]) => `
          <button type="button" class="fila" data-icono="${esc(nombre)}">
            <span class="icono-muestra" data-muestra="${esc(nombre)}">${icono(nombre)}</span>
            <span class="fila__texto">${esc(etiqueta)}
              ${donde ? `<small>${esc(donde)}</small>` : ''}</span>
            <span class="fila__valor" data-estado="${esc(nombre)}"></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>`).join('')}

    <div class="seccion"><h2>Departamentos</h2></div>
    <div class="filas" id="filas-departamentos"></div>

    <div class="seccion"><h2>Áreas</h2>
      <span class="rotulo">familias de avería</span></div>
    <div class="filas" id="filas-familias"></div>

    <div class="seccion"><h2>Tipos de avería</h2>
      <span class="rotulo" id="cuenta-tipos"></span></div>
    <div class="campo" style="margin-bottom:8px">
      <input class="entrada" type="search" id="q-tipos" autocomplete="off"
             placeholder="Buscar un tipo para cambiarle el icono">
    </div>
    <div class="filas" id="filas-tipos"></div>

    <div class="filas" style="margin-top:14px">
      <button type="button" class="fila" data-todo-como-estaba>
        ${icono('sync')}
        <span class="fila__texto">Dejarlo todo como estaba
          <small>Devuelve todos los iconos a los de la aplicación</small></span>
      </button>
    </div>

    <div id="guardar-iconos" class="oculto"
         style="position:sticky;bottom:calc(var(--barra) + 12px);margin-top:18px">
      <button type="button" class="btn btn--principal btn--bloque" data-guardar>Guardar los iconos</button>
    </div>`;

  refrescarFilas(contenido);
  pintarDepartamentos(contenido);
  pintarFamilias(contenido);
  pintarTipos(contenido);

  const campoTipos = $('#q-tipos', contenido);
  let relojTipos = null;
  campoTipos.addEventListener('input', () => {
    clearTimeout(relojTipos);
    relojTipos = setTimeout(() => pintarTipos(contenido, campoTipos.value), 140);
  });

  contenido.querySelectorAll('[data-icono]').forEach((b) => {
    b.onclick = () => elegirIcono(b.dataset.icono, contenido);
  });

  $('[data-todo-como-estaba]', contenido).onclick = async () => {
    if (!Object.keys(mapa).length) { mensaje('Ya están todos los de la aplicación.', 'ok'); return; }
    const ok = await confirmar({
      titulo: 'Dejarlo todo como estaba',
      texto: `Se quitan los ${Object.keys(mapa).length} iconos cambiados y vuelven los de siempre.`,
      aceptar: 'Dejarlo como estaba',
    });
    if (!ok) return;
    mapa = {};
    sucio = true;
    refrescarFilas(contenido);
  };

  $('[data-guardar]', contenido).onclick = () => guardar(contenido);
}

/* ------------------------------------------------------------------ */
/* Áreas y tipos de avería                                             */
/* ------------------------------------------------------------------ */

/* Los 55 iconos de la aplicación son los botones y las pantallas. Pero lo
   que más se mira son las áreas —dieciséis— y los tipos de avería, que
   pasan de trescientos: son los que salen al dar un parte y en cada
   listado. Se cambian aquí mismo, uno a uno, y se guardan al momento: no
   tiene sentido acumular trescientos cambios sin guardar. */

function filaCosa(clase, id, nombre, iconoActual, detalle) {
  return `
    <button type="button" class="fila" data-${clase}="${esc(id)}" data-nombre="${esc(nombre)}">
      <span class="icono-muestra">${icono(iconoActual || 'llaveinglesa')}</span>
      <span class="fila__texto">${esc(nombre)}
        ${detalle ? `<small>${esc(detalle)}</small>` : ''}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>`;
}

function pintarDepartamentos(contenido) {
  const caja = $('#filas-departamentos', contenido);
  if (!caja) return;
  const deps = app.catalogo?.departamentos || [];
  caja.innerHTML = deps.map((d) =>
    filaCosa('departamento', d.id_departamento, d.nombre, d.icono, '')).join('');
  caja.onclick = async (e) => {
    const b = e.target.closest('[data-departamento]');
    if (!b) return;
    const elegido = await elegirIconoFA({
      titulo: b.dataset.nombre, sugeridos: sugerenciasPorNombre(b.dataset.nombre),
    });
    if (!elegido) return;
    try {
      await api.editarDepartamento(Number(b.dataset.departamento), { icono: elegido });
      const d = deps.find((x) => x.id_departamento === Number(b.dataset.departamento));
      if (d) d.icono = elegido;
      b.querySelector('.icono-muestra').innerHTML = icono(elegido);
      mensaje(`Icono de ${b.dataset.nombre} cambiado.`, 'ok');
    } catch (err) { mensaje(err.message, 'error'); }
  };
}

function pintarFamilias(contenido) {
  const caja = $('#filas-familias', contenido);
  if (!caja) return;
  const fams = app.catalogo?.familias || [];
  caja.innerHTML = fams.map((f) =>
    filaCosa('familia', f.id_familia, f.nombre, f.icono,
             `${(app.catalogo?.elementos || []).filter((e) => e.id_familia === f.id_familia).length} tipos`)).join('');
  caja.onclick = async (e) => {
    const b = e.target.closest('[data-familia]');
    if (!b) return;
    const elegido = await elegirIconoFA({
      titulo: b.dataset.nombre, sugeridos: sugerenciasPorNombre(b.dataset.nombre),
    });
    if (!elegido) return;
    try {
      await api.editarFamilia(Number(b.dataset.familia), { icono: elegido });
      const f = (app.catalogo?.familias || []).find((x) => x.id_familia === Number(b.dataset.familia));
      if (f) f.icono = elegido;
      b.querySelector('.icono-muestra').innerHTML = icono(elegido);
      mensaje(`Icono de ${b.dataset.nombre} cambiado.`, 'ok');
    } catch (err) { mensaje(err.message, 'error'); }
  };
}

function pintarTipos(contenido, consulta = '') {
  const caja = $('#filas-tipos', contenido);
  const cuenta = $('#cuenta-tipos', contenido);
  if (!caja) return;
  const todos = app.catalogo?.elementos || [];
  const q = textoPlano(consulta.trim());
  /* Trescientos botones de golpe no ayudan a nadie y van lentos en un
     móvil: sin buscar se enseñan los más usados, y al escribir, lo que
     coincida. */
  const lista = q
    ? todos.filter((e) => textoPlano(e.nombre).includes(q)).slice(0, 40)
    : [...todos].sort((a, b) => (b.usos || 0) - (a.usos || 0)).slice(0, 12);
  cuenta.textContent = q
    ? `${lista.length}${lista.length === 40 ? '+' : ''} de ${todos.length}`
    : `los ${lista.length} más usados de ${todos.length} · busca para ver el resto`;
  caja.innerHTML = lista.map((e) => {
    const fam = (app.catalogo?.familias || []).find((f) => f.id_familia === e.id_familia);
    return filaCosa('tipo', e.id_elemento, e.nombre, e.icono, fam?.nombre || '');
  }).join('');
  caja.onclick = async (ev) => {
    const b = ev.target.closest('[data-tipo]');
    if (!b) return;
    const elegido = await elegirIconoFA({
      titulo: b.dataset.nombre, sugeridos: sugerenciasPorNombre(b.dataset.nombre),
    });
    if (!elegido) return;
    try {
      await api.editarElemento(Number(b.dataset.tipo), { icono: elegido });
      const el = todos.find((x) => x.id_elemento === Number(b.dataset.tipo));
      if (el) el.icono = elegido;
      b.querySelector('.icono-muestra').innerHTML = icono(elegido);
      mensaje(`Icono de ${b.dataset.nombre} cambiado.`, 'ok');
    } catch (err) { mensaje(err.message, 'error'); }
  };
}

/** Pone al día la muestra y el texto de cada fila sin repintar la pantalla. */
function refrescarFilas(contenido) {
  contenido.querySelectorAll('[data-muestra]').forEach((el) => {
    const n = el.dataset.muestra;
    el.innerHTML = mapa[n] ? dibujo(mapa[n]) : icono(n);
    el.classList.toggle('icono-muestra--cambiado', !!mapa[n]);
  });
  contenido.querySelectorAll('[data-estado]').forEach((el) => {
    el.textContent = mapa[el.dataset.estado] ? 'Cambiado' : '';
  });
  $('#guardar-iconos', contenido)?.classList.toggle('oculto', !sucio);
}

/** El panel para elegir: conservar el actual, las sugerencias, o buscar. */
async function elegirIcono(nombre, contenido) {
  const fila = CATALOGO.flatMap(([, f]) => f).find((x) => x[0] === nombre);
  const etiqueta = fila ? fila[1] : nombre;
  const sugeridos = fila ? fila[3] : [];
  const todos = await indiceFA();

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo">
      <input class="entrada" type="search" id="ic-q" autocomplete="off"
             placeholder="Buscar entre ${Object.keys(todos).length} iconos">
    </div>
    <div id="ic-actual"></div>
    <div id="ic-cuenta" class="rotulo" style="padding:10px 4px 6px"></div>
    <div id="ic-rejilla" class="rejilla-iconos"></div>`;

  const actual = cuerpo.querySelector('#ic-actual');
  actual.innerHTML = `
    <button type="button" class="fila" data-conservar>
      <span class="icono-muestra">${icono(nombre)}</span>
      <span class="fila__texto">Conservar el actual
        <small>El icono que trae la aplicación</small></span>
      ${mapa[nombre] ? '' : '<span class="fila__valor">En uso</span>'}
    </button>`;

  const rejilla = cuerpo.querySelector('#ic-rejilla');
  const cuenta = cuerpo.querySelector('#ic-cuenta');

  function pintar(q) {
    const busca = (q || '').trim().toLowerCase();
    let lista;
    if (!busca) {
      // Sin escribir nada: lo que sugiere el sistema para este icono.
      lista = sugeridos.map((n) => resolver(n, todos)).filter(Boolean);
      cuenta.textContent = lista.length ? 'Sugerencias para este icono' : 'Escribe para buscar';
    } else {
      const porAlias = new Set(Object.entries(aliasFA)
        .filter(([a]) => a.includes(busca)).map(([, c]) => c));
      lista = Object.keys(todos).filter((n) =>
        n.includes(busca) || porAlias.has(n) || (todos[n].g || '').includes(busca)
      ).slice(0, 120);
      cuenta.textContent = `${lista.length}${lista.length === 120 ? '+' : ''} iconos`;
    }
    rejilla.innerHTML = lista.map((n) => `
      <button type="button" class="icono-opcion${mapa[nombre] === todos[n].c ? ' icono-opcion--puesto' : ''}"
              data-fa="${esc(todos[n].c)}" title="${esc(n)}">
        ${dibujo(todos[n].c)}<span>${esc(n)}</span>
      </button>`).join('');
    rejilla.querySelectorAll('[data-fa]').forEach((b) => {
      b.onclick = () => {
        mapa[nombre] = b.dataset.fa;
        sucio = true;
        refrescarFilas(contenido);
        cerrarHoja();
        mensaje(`«${etiqueta}» cambiado. Recuerda guardar.`, 'ok');
      };
    });
  }

  hoja({ titulo: etiqueta, cuerpo });

  actual.querySelector('[data-conservar]').onclick = () => {
    delete mapa[nombre];
    sucio = true;
    refrescarFilas(contenido);
    cerrarHoja();
  };

  const campo = cuerpo.querySelector('#ic-q');
  // El panel se abre para buscar un icono: el foco va puesto.
  enfocarBuscador(campo);
  let reloj = null;
  campo.addEventListener('input', () => {
    clearTimeout(reloj);
    reloj = setTimeout(() => pintar(campo.value), 130);
  });
  pintar('');
}

async function guardar(contenido) {
  const b = $('[data-guardar]', contenido);
  b.disabled = true;
  try {
    // El mapa tal cual: el servidor ya lo guarda como JSON. Con
    // JSON.stringify quedaba codificado dos veces y no lo reconocía nadie.
    await api.guardarAjustes({ iconos_personalizados: mapa });
    // Se aplica al momento, sin recargar.
    fijarIconosPersonalizados(mapa);
    if (app.catalogo?.ajustes) app.catalogo.ajustes.iconos = { ...mapa };
    sucio = false;
    refrescarFilas(contenido);
    const n = Object.keys(mapa).length;
    mensaje(n
      ? `${n} icono${n === 1 ? '' : 's'} cambiado${n === 1 ? '' : 's'} para todo el hotel.`
      : 'Todos los iconos vuelven a ser los de la aplicación.', 'ok');
    app.refrescar?.();
  } catch (e) {
    mensaje(e.message, 'error');
  }
  b.disabled = false;
}

export { iconoPersonalizado };
