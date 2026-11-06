/* Equipo: quién tiene acceso y con qué permisos. */

/* Las capacidades sueltas que se pueden dar a una persona.
 *
 * Son para no tener que hacer administrador a alguien por una sola cosa.
 * Las tres de asignar existen porque pasarle un parte a un compañero, a
 * un responsable o a otro departamento son decisiones distintas: hay
 * hoteles donde lo primero es normal y lo último lo decide el jefe.
 *
 * Lo que se da aquí manda sobre el ajuste global, y sólo para dar más.
 */
const PERMISOS_SUELTOS = [
  ['offline_apagar', 'sinred', 'Puede desactivar el modo sin cobertura',
   'Apagar que la aplicación guarde trabajo en el móvil a la espera de señal'],
  ['asignar_companero', 'personas', 'Puede asignar un parte a un compañero',
   'Pasárselo a alguien de su mismo departamento'],
  ['asignar_responsable', 'persona', 'Puede asignar un parte a un responsable',
   'Subirlo a quien manda cuando se atasca o no le toca'],
  ['asignar_otro_departamento', 'sync', 'Puede asignar a otro departamento',
   'Cuando la avería no era de lo que se pensaba'],
];

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, mensaje, hoja, cerrarHoja, haceCuanto, confirmar,
         elegir } from '../ui.js';
import { tono } from '../sonido.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, app } from '../app.js';
/* El buscador, los filtros y la selección múltiple son una pieza
   compartida: seis pantallas los necesitan y seis copias serían seis
   comportamientos distintos para lo mismo. */
import { panelLista } from '../listas.js';

const ROLES = {
  admin: 'Administrador',
  responsable: 'Responsable',
  tecnico: 'Técnico',
  reportador: 'Sólo avisar',
};

/* Las categorías de frases, a botones: se eligen una o varias.
 *
 * Vive fuera de las fichas porque hay dos —crear y editar— y estaba sólo
 * en la de crear: al editar a alguien, el hueco de categorías se quedaba
 * vacío y se guardaba siempre «ninguna». */
async function pintarCategoriasFrases(h, u = {}) {
  const caja = $('#u-frases-cats', h);
  if (!caja) return;
  let cats = [];
  try { cats = (await api.frases()).categorias || []; } catch { return; }
  const puestas = new Set(Array.isArray(u.frases_categorias)
    ? u.frases_categorias
    : JSON.parse(u.frases_categorias || '[]'));
  caja.innerHTML = cats.map((c) => `
    <button type="button" class="opcion" data-cat="${c.id_categoria}"
            aria-pressed="${puestas.has(c.id_categoria)}">${esc(c.nombre)}</button>`).join('')
    || '<span class="campo__ayuda">Todavía no hay categorías de frases. Se crean en Ajustes › Frases.</span>';
  caja.onclick = (e) => {
    const b = e.target.closest('[data-cat]');
    if (!b) return;
    b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
  };
}

const AYUDA_ROL = {
  admin: 'Todo, incluida la gestión de personas',
  responsable: 'Asignar y cerrar partes de cualquiera',
  tecnico: 'Trabajar en los partes que tenga asignados',
  reportador: 'Abrir partes y seguirlos',
};

export async function vistaEquipo({ contenido }) {
  const puedeCrear = app.usuario.es_admin;

  pintarCabecera(cabeceraVolver('Equipo', '',
    puedeCrear ? `<button type="button" class="icono-btn" data-nuevo aria-label="Añadir persona">${icono('mas')}</button>` : ''));

  contenido.innerHTML = bloqueCarga(5);

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>La gestión del equipo necesita conexión.</div></div>`;
    return {};
  }

  let gente = [];

  /* ------------------------------------------------------------------
     Buscador, filtros y acciones en masa
     ------------------------------------------------------------------ */
  const deptos = () => app.catalogo?.departamentos || [];

  async function enLote(accion, extra = {}) {
    const r = await api.usuariosLote(accion, L.marcadas(), extra);
    tono(r.hechas ? 'ok' : 'error');
    mensaje(r.mensaje, r.hechas ? 'ok' : 'error');
    /* Lo que se ha quedado como estaba se dice con nombre y motivo. Un
       «3 de 5 hechas» sin decir cuáles ni por qué obliga a repasar la
       lista a mano. */
    if ((r.saltadas || []).length) {
      mensaje(r.saltadas.map((s) => `${s.quien}: ${s.por}`).join(' · '), 'aviso');
    }
    await cargar();
  }

  const L = panelLista({
    datos: () => gente,
    id: (u) => u.id_login,
    // Por nombre, usuario, correo, teléfono, papel y departamento: la
    // gente busca por lo que recuerda, no por un campo concreto.
    texto: (u) => [u.nombre_completo, u.nombre, u.apellido1, u.usuario, u.correo,
                   u.telefono, ROLES[u.rol] || u.rol, u.departamento],
    placeholder: 'Buscar por nombre, usuario, correo o teléfono…',
    nombreUno: 'persona',
    nombreVarios: 'personas',

    filtros: [
      {
        clave: 'acceso', titulo: 'Todos',
        opciones: [{ valor: 'si', texto: 'Con acceso' }, { valor: 'no', texto: 'Sin acceso' }],
        cumple: (u, v) => (v === 'si' ? !u.bloqueo : !!u.bloqueo),
      },
      {
        clave: 'rol', titulo: 'Cualquier papel',
        opciones: Object.entries(ROLES).map(([v, t]) => ({ valor: v, texto: t })),
        cumple: (u, v) => u.rol === v,
      },
      {
        clave: 'dep', titulo: 'Cualquier departamento',
        opciones: [
          ...deptos().map((d) => ({ valor: d.id_departamento, texto: d.nombre })),
          { valor: 'sin', texto: 'Sin departamento' },
        ],
        cumple: (u, v) => (v === 'sin'
          ? !u.id_departamento
          : String(u.id_departamento) === String(v)),
      },
      {
        clave: 'entrado', titulo: 'Hayan entrado o no',
        opciones: [{ valor: 'nunca', texto: 'Nunca han entrado' },
                   { valor: 'si', texto: 'Ya han entrado' }],
        cumple: (u, v) => (v === 'nunca' ? !u.ultimo_acceso : !!u.ultimo_acceso),
      },
    ],

    acciones: !app.usuario.es_admin ? [] : [
      {
        clave: 'desbloquear', texto: 'Dar acceso', icono: 'llave',
        hacer: () => enLote('desbloquear'),
      },
      {
        clave: 'departamento', texto: 'Cambiar departamento', icono: 'personas',
        hacer: async () => {
          const d = await elegir({
            titulo: 'A qué departamento pasan',
            opciones: [...deptos().map((x) => ({ valor: x.id_departamento, texto: x.nombre })),
                       { valor: 0, texto: 'Dejarlos sin departamento' }],
          });
          if (d === null || d === undefined) return;
          await enLote('departamento', { id_departamento: d || null });
        },
      },
      {
        clave: 'rol', texto: 'Cambiar papel', icono: 'banderin',
        hacer: async () => {
          const r = await elegir({
            titulo: 'Qué papel les damos',
            opciones: Object.entries(ROLES).map(([v, x]) => ({ valor: v, texto: x })),
          });
          if (!r) return;
          if (!await confirmar({
            titulo: 'Cambiar el papel de ' + L.marcadas().length + ' persona(s)',
            texto: 'El papel decide lo que puede hacer cada una. A «Administrador» se le '
                 + 'da el control de toda la configuración del hotel.',
            aceptar: 'Cambiarlo', peligro: r === 'admin',
          })) return;
          await enLote('rol', { rol: r });
        },
      },
      {
        clave: 'bloquear', texto: 'Quitar acceso', icono: 'candado', peligro: true,
        confirmar: (n) => ({
          titulo: `Quitar el acceso a ${n} persona${n === 1 ? '' : 's'}`,
          texto: 'No se borra a nadie: dejan de poder entrar y su nombre sigue en el '
               + 'historial de los partes. Se les cierran las sesiones abiertas. '
               + 'Se puede devolver el acceso cuando haga falta.',
          aceptar: 'Quitarles el acceso', peligro: true,
        }),
        hacer: () => enLote('bloquear'),
      },
    ],

    alCambiar: () => pintar(),
  });

  async function cargar() {
    let r;
    try {
      r = await api.usuarios();
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    gente = r.usuarios || [];
    // Lo marcado que ya no exista se olvida, o quedaría contando.
    L.olvidar(L.marcadas().filter((x) => !gente.some((u) => u.id_login === x)));
    pintar();
  }

  function pintar() {
    const vis = L.visibles();
    const activos = vis.filter((u) => !u.bloqueo);
    const sinAcceso = vis.filter((u) => u.bloqueo);

    contenido.innerHTML = L.html()
      + bloque('Con acceso', activos)
      + (sinAcceso.length ? bloque('Sin acceso', sinAcceso) : '')
      + (vis.length === 0 && gente.length === 0
          ? `<div class="aviso aviso--info">${icono('info')}<div>
               Todavía no hay nadie. Añade personas con el + de arriba, o muchas de
               una vez desde Administración → Importar / Exportar personas.</div></div>` : '');

    /* La pieza se engancha una vez: ella misma lo comprueba. Y el clic
       de la fila va en «onclick», que se reemplaza en vez de sumarse. */
    L.enganchar(contenido);

    contenido.onclick = (e) => {
      if (e.target.closest('[data-lst-marca]')) return;   // marcar no abre la ficha
      const b = e.target.closest('[data-u]');
      if (b) editar(gente.find((u) => u.id_login === parseInt(b.dataset.u, 10)), cargar);
    };
  }

  function bloque(titulo, lista) {
    if (!lista.length) return '';
    return `
      <div class="seccion"><h2>${esc(titulo)}</h2><span class="rotulo">${lista.length}</span></div>
      <div class="filas">${lista.map((u) => `
        <button type="button" class="fila" data-u="${u.id_login}" ${u.bloqueo ? 'style="opacity:.6"' : ''}>
          ${L.marca(u)}
          <span class="avatar">${esc((u.nombre?.[0] || '') + (u.apellido1?.[0] || ''))}</span>
          <span class="fila__texto">
            ${esc(u.nombre_completo || u.usuario || u.correo)}
            <small>${u.usuario ? '@' + esc(u.usuario) + ' · ' : ''}${esc(ROLES[u.rol] || u.rol)}${u.departamento ? ' · ' + esc(u.departamento) : ''}
              ${u.ultimo_acceso ? ' · ' + esc(haceCuanto(u.ultimo_acceso)) : ' · nunca ha entrado'}</small>
          </span>
          ${u.abiertos ? `<span class="fila__valor">${u.abiertos} abiertos</span>` : ''}
          ${icono('flecha', 'fila__flecha')}
        </button>`).join('')}</div>`;
  }

  const nuevo = document.querySelector('[data-nuevo]');
  if (nuevo) nuevo.onclick = () => crear(cargar);

  await cargar();
  return { refrescar: cargar };
}

/* ---------------------------------------------------------------- */
function camposComunes(u = {}) {
  return `
    <div class="campo"><label for="u-nombre">Nombre</label>
      <input class="entrada" id="u-nombre" value="${esc(u.nombre || '')}"></div>
    <div class="campo"><label for="u-ap1">Primer apellido</label>
      <input class="entrada" id="u-ap1" value="${esc(u.apellido1 || '')}"></div>
    <div class="campo"><label for="u-tel">Teléfono</label>
      <input class="entrada" id="u-tel" type="tel" inputmode="tel" value="${esc(u.telefono || '')}"></div>
    <div class="campo">
      <label>Permisos</label>
      <div class="opciones" id="u-rol">
        ${Object.entries(ROLES).map(([k, v]) => `
          <button type="button" class="opcion" data-rol="${k}" aria-pressed="${(u.rol || 'tecnico') === k}">${esc(v)}</button>`).join('')}
      </div>

      <div class="seccion"><h2>Mensajes para esta persona</h2></div>

      <div class="campo">
        <label class="fila" style="padding:0;cursor:pointer">
          <span class="fila__texto">Mensaje de bienvenida
            <small>Se enseña una sola vez, la primera vez que entra</small></span>
          <input type="checkbox" id="u-bienv-on" ${u.bienvenida_activa ? 'checked' : ''}>
        </label>
        <textarea class="entrada" id="u-bienv" rows="2" maxlength="500"
          placeholder="Bienvenido al equipo. Cualquier duda, pregunta a Carlos.">${esc(u.mensaje_bienvenida || '')}</textarea>
      </div>

      <div class="campo"><label for="u-proximo">Aviso para el próximo inicio</label>
        <textarea class="entrada" id="u-proximo" rows="2" maxlength="500"
          placeholder="Hoy cubres la piscina.">${esc(u.mensaje_proximo || '')}</textarea>
        <div class="campo__ayuda">Se le enseña una vez y desaparece. Al escribir uno nuevo se vuelve a enseñar.</div>
      </div>

      <div class="campo">
        <label class="fila" style="padding:0;cursor:pointer">
          <span class="fila__texto">Frase del día
            <small>Una distinta cada día, sin repetir</small></span>
          <input type="checkbox" id="u-frases-on" ${u.frases_activas ? 'checked' : ''}>
        </label>
        <div id="u-frases-cats" class="opciones" style="margin-top:8px"></div>
        <label class="fila" style="padding:8px 0 0;cursor:pointer">
          <span class="fila__texto">Enseñarla en cada inicio de sesión
            <small>Sin marcar, una al día</small></span>
          <input type="checkbox" id="u-frases-inicio" ${u.frases_cada_inicio ? 'checked' : ''}>
        </label>
        <div class="campo__ayuda">Si no marcas ninguna categoría, valen todas.</div>
      </div>
      <div class="campo__ayuda" id="u-ayuda-rol">${esc(AYUDA_ROL[u.rol || 'tecnico'])}</div>
    </div>
    <div class="campo">
      <label for="u-dep">Departamento</label>
      <select class="lista-desplegable" id="u-dep">
        <option value="">Sin departamento</option>
        ${listaDepartamentos(u).map((d) => `
          <option value="${d.id_departamento}" ${Number(u.id_departamento) === Number(d.id_departamento) ? 'selected' : ''}>${esc(d.nombre)}${d.retirado ? ' (retirado)' : ''}</option>`).join('')}
      </select>
      ${departamentoRetirado(u) ? `
        <div class="campo__ayuda" style="color:var(--aviso)">
          Su departamento está retirado del hotel. Se mantiene si no lo cambias.
        </div>` : ''}
    </div>

    <div class="campo">
      <label>Departamentos adicionales</label>
      <div class="campo__ayuda">Para quien echa una mano en otro sitio: es habitual
        que informática y servicios técnicos se cubran. Verá y podrá trabajar
        también los partes de los que marques.</div>
      <div class="opciones" id="u-deps-extra" style="flex-wrap:wrap;gap:6px;margin-top:6px">
        ${listaDepartamentos(u).map((d) => botonDepartamentoExtra(d, u)).join('')}
      </div>
      ${listaDepartamentos(u).length === 0 ? `
        <div class="campo__ayuda" style="color:var(--aviso)">
          Este hotel no tiene departamentos configurados, así que no hay nada que
          elegir. Se ponen en Ajustes › Departamentos.
        </div>` : ''}
    </div>

    ${app.usuario.es_admin ? `
    <div class="campo">
      <label>Permisos adicionales</label>
      <div class="campo__ayuda">Capacidades sueltas, para no tener que hacer
        administrador a alguien por una sola cosa.</div>
      <div class="filas" style="margin-top:6px">
        ${PERMISOS_SUELTOS.map(([clave, icono_, titulo, detalle]) => `
          <div class="fila" style="cursor:default">
            ${icono(icono_)}
            <span class="fila__texto">${titulo}
              <small>${detalle}</small></span>
            <button type="button" class="interruptor" data-permiso="${clave}" role="switch"
                    aria-checked="${(u.permisos || []).includes(clave) ? 'true' : 'false'}"></button>
          </div>`).join('')}
      </div>
      <div class="campo__ayuda" style="margin-top:8px">
        Lo que se dé aquí manda sobre lo que haya en Configuración: sirve para
        dar más a alguien en concreto, nunca para quitarle.
      </div>
    </div>` : ''}

    ${app.usuario.es_admin && app.catalogo?.ajustes?.teams ? `
    <div class="campo">
      <label>Microsoft Teams</label>
      <div class="filas" style="margin-top:6px">
        <div class="fila" style="cursor:default">
          ${icono('chat')}
          <span class="fila__texto">Mandarle copia de los avisos a Teams
            <small>${u.correo
              ? 'Al chat privado de ' + esc(u.correo)
              : 'No tiene correo en HuraKan Tick: sin correo no se le puede encontrar en Teams'}</small></span>
          <button type="button" class="interruptor" data-teams role="switch"
                  aria-checked="${u.teams_avisar === 0 ? 'false' : 'true'}"
                  ${u.correo ? '' : 'disabled'}></button>
        </div>
      </div>
    </div>` : ''}`;
}

/**
 * Los departamentos que puede tener esta persona.
 *
 * Son los del hotel MÁS los suyos propios aunque estén retirados. Sin esto
 * pasaba algo feo y silencioso: a quien estuviera en un departamento que
 * se retiró del hotel, su ficha le enseñaba «Sin departamento» —porque esa
 * opción no estaba en la lista— y al guardar se le borraba el suyo de
 * verdad. Nadie tocaba ese campo: bastaba con abrir la ficha, cambiar el
 * teléfono y guardar. Y un técnico sin departamento deja de ver los partes
 * de su equipo.
 *
 * Se marcan como «(retirado)» para que se entienda por qué están ahí.
 */
function listaDepartamentos(u = {}) {
  const delHotel = (app.catalogo?.departamentos || []).map((d) => ({ ...d }));
  const tiene = new Set(delHotel.map((d) => Number(d.id_departamento)));
  const suyos = [
    ...(u.id_departamento ? [Number(u.id_departamento)] : []),
    ...(u.departamentos_extra || []).map(Number),
  ];
  for (const id of suyos) {
    if (!id || tiene.has(id)) { continue; }
    tiene.add(id);
    delHotel.push({
      id_departamento: id,
      nombre: u.departamento && Number(u.id_departamento) === id
        ? u.departamento : `Departamento ${id}`,
      retirado: true,
    });
  }
  return delHotel;
}

/**
 * Un botón de «departamento adicional».
 *
 * Va en su propia función y no dentro de la plantilla a propósito: tenía
 * comillas invertidas anidadas dentro de otra plantilla, y eso corta la de
 * fuera por la mitad. El resultado fue que sólo se dibujaba UN botón y los
 * otros seis desaparecían, justo en el campo que había que arreglar. Aquí
 * se construye con comillas normales y no hay nada que anidar.
 */
function botonDepartamentoExtra(d, u = {}) {
  const suyo = Number(u.id_departamento) === Number(d.id_departamento);
  const marcado = suyo
    || (u.departamentos_extra || []).map(Number).includes(Number(d.id_departamento));
  const sufijo = suyo ? ' · el suyo' : (d.retirado ? ' (retirado)' : '');

  /* El suyo propio no es un «adicional»: ya lo tiene. Antes salía como uno
     más, se podía marcar, y el servidor lo descartaba al guardar —con
     razón— sin decir nada. Quien lo marcaba veía que al volver a abrir no
     estaba, y concluía que esto no funciona. */
  const atributos = suyo
    ? 'disabled title="Es su departamento: ya lo tiene"'
    : 'data-dep-extra="' + d.id_departamento + '"';

  return '<button type="button" class="opcion' + (marcado ? ' opcion--activa' : '') + '" '
    + atributos + '>' + esc(d.nombre) + sufijo + '</button>';
}

/** ¿El departamento de esta persona ya no lo usa el hotel? */
function departamentoRetirado(u = {}) {
  if (!u.id_departamento) { return false; }
  return !(app.catalogo?.departamentos || [])
    .some((d) => Number(d.id_departamento) === Number(u.id_departamento));
}

/** Lo marcado en los dos campos nuevos, listo para enviar. */
function extrasDelFormulario(h) {
  return {
    departamentos_extra: [...h.querySelectorAll('[data-dep-extra].opcion--activa')]
      .map((b) => parseInt(b.dataset.depExtra, 10)),
    permisos: [...h.querySelectorAll('[data-permiso]')]
      .filter((b) => b.getAttribute('aria-checked') === 'true')
      .map((b) => b.dataset.permiso),
    ...(h.querySelector('[data-teams]')
      ? { teams_avisar: h.querySelector('[data-teams]').getAttribute('aria-checked') === 'true' }
      : {}),
  };
}

/** Los botones de departamento adicional y el interruptor del permiso. */
function engancharExtras(h) {
  const zona = $('#u-deps-extra', h);
  if (zona) {
    zona.onclick = (e) => {
      const b = e.target.closest('[data-dep-extra]');
      if (b) b.classList.toggle('opcion--activa');
    };
  }
  h.querySelectorAll('[data-permiso], [data-teams]').forEach((sw) => {
    if (sw.disabled) return;
    sw.onclick = () =>
      sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') !== 'true');
  });
}

function engancharRol(h, estado) {
  const grupo = $('#u-rol', h);
  grupo.onclick = (e) => {
    const b = e.target.closest('[data-rol]');
    if (!b) return;
    $$('[data-rol]', grupo).forEach((x) => x.setAttribute('aria-pressed', 'false'));
    b.setAttribute('aria-pressed', 'true');
    estado.rol = b.dataset.rol;
    $('#u-ayuda-rol', h).textContent = AYUDA_ROL[estado.rol];
  };
}

function crear(alTerminar) {
  const estado = { rol: 'tecnico' };
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    ${app.usuario.es_superadmin ? `
      <div class="campo"><label for="u-hotel">Hotel</label>
        <select class="entrada" id="u-hotel">
          ${(app.hoteles || []).map((h) => `
            <option value="${h.id_hotel}"
              ${h.id_hotel === (app.catalogo?.hotel?.id_hotel || app.usuario.id_hotel) ? 'selected' : ''}>
              ${esc(h.nombre)}</option>`).join('')}
        </select>
        <div class="campo__ayuda">
          Esta persona sólo verá los partes de ese hotel.</div>
      </div>` : ''}

    <div class="campo"><label for="u-usuario">Nombre de usuario</label>
      <input class="entrada" id="u-usuario" type="text" autocomplete="off"
             autocapitalize="none" spellcheck="false" placeholder="recepcion">
      <div class="campo__ayuda">Con esto entra. Letras, cifras, punto y guión.</div>
    </div>
    <div class="campo"><label for="u-correo">Correo <span style="color:var(--tinta-3);font-weight:400">· opcional</span></label>
      <input class="entrada" id="u-correo" type="email" inputmode="email" autocomplete="off"
             placeholder="nombre@empresa.com"></div>
    <div class="campo"><label for="u-pwd">Contraseña inicial</label>
      <input class="entrada" id="u-pwd" type="text" autocomplete="off">
      <div class="campo__ayuda">Mínimo 8 caracteres. Se la das a la persona para que la cambie al entrar.</div></div>
    ${camposComunes()}
    <div id="err-u"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Crear</button>`;

  hoja({
    titulo: 'Añadir a alguien', cuerpo, pie,
    alAbrir: (h) => {
      engancharRol(h, estado);
      engancharExtras(h);
      $('#u-pwd', h).value = sugerirClave();
      $('[data-no]', h).onclick = () => cerrarHoja();
      /* Sin «u»: aquí no hay persona todavía, se está creando. Pasarle la
         «u» del formulario de edición —que aquí no existe— reventaba al
         abrir el alta, y como el error se perdía, el botón «Crear» se
         quedaba sin manejador: se pulsaba y no pasaba nada de nada. */
      pintarCategoriasFrases(h);

      $('[data-si]', h).onclick = async () => {
        const err = (t) => { $('#err-u', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };
        const usuario = $('#u-usuario', h).value.trim();
        const correo = $('#u-correo', h).value.trim();
        const pwd = $('#u-pwd', h).value;

        /* El usuario es lo obligatorio; el correo, no. Hay cuentas de
           puesto —«recepcion», «pisos»— que no tienen correo de nadie. */
        if (!usuario) return err('Escribe el nombre de usuario.');
        if (pwd.length < 8) return err('La contraseña debe tener al menos 8 caracteres.');

        try {
          const hotelSel = $('#u-hotel', h)?.value;
          await api.crearUsuario({
            ...(hotelSel ? { id_hotel: parseInt(hotelSel, 10) } : {}),
            usuario,
            ...(correo ? { correo } : {}),
            password: pwd,
            nombre: $('#u-nombre', h).value.trim(),
            apellido1: $('#u-ap1', h).value.trim(),
            telefono: $('#u-tel', h).value.trim(),
            rol: estado.rol,
            id_departamento: $('#u-dep', h).value || null,
            ...extrasDelFormulario(h),
          });
          cerrarHoja();
          mensaje('Persona añadida.', 'ok');
          alTerminar();
        } catch (e) { err(e.message); }
      };
    },
  });
}

function editar(u, alTerminar) {
  if (!u) return;
  const soyAdmin = app.usuario.es_admin;
  const estado = { rol: u.rol };

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="aviso aviso--info">${icono('persona')}<div>
      <strong>${esc(u.usuario || u.correo || '—')}</strong>
      ${u.correo && u.usuario ? `<br><span style="opacity:.8">${esc(u.correo)}</span>` : ''}<br>
      ${u.ultimo_acceso ? 'Última entrada ' + esc(haceCuanto(u.ultimo_acceso)) : 'Todavía no ha entrado'}
      ${u.abiertos ? ` · ${u.abiertos} partes abiertos` : ''}
    </div></div>
    ${soyAdmin ? `
      <div class="campo"><label for="u-usuario2">Nombre de usuario</label>
        <input class="entrada" id="u-usuario2" type="text" autocapitalize="none"
               spellcheck="false" value="${esc(u.usuario || '')}" autocomplete="off">
        <div class="campo__ayuda">Con esto entra. Cambiarlo queda registrado.</div>
      </div>
      <div class="campo"><label for="u-correo2">Correo
        <span style="color:var(--tinta-3);font-weight:400">· opcional</span></label>
        <input class="entrada" id="u-correo2" type="email" inputmode="email"
               value="${esc(u.correo || '')}" autocomplete="off">
        <div class="campo__ayuda">Hace falta para recuperar la contraseña por su cuenta.</div>
      </div>` : ''}
    ${soyAdmin ? camposComunes(u) : ''}
    ${soyAdmin ? `
      <div class="campo">
        <label for="u-pwd2">Nueva contraseña</label>
        <input class="entrada" id="u-pwd2" type="text" autocomplete="off" placeholder="Dejar en blanco para no cambiarla">
        <div class="campo__ayuda">Al cambiarla se cierran todas sus sesiones.</div>
      </div>

      ${app.usuario.es_superadmin ? `
      <details style="margin-top:6px" data-bloque-enlace>
        <summary style="cursor:pointer;font-size:13px;font-weight:640;padding:10px 2px">
          Enlace de auto-entrada${Number(u.autologin_activo) ? ' · activo' : ''}</summary>
        <div style="padding-top:8px" data-enlace-cuerpo>
          <p class="campo__ayuda" style="margin-bottom:10px">
            Un enlace que entra sin escribir nada. Va bien en la tablet fija de
            recepción. <strong>Quien lo tenga es esta persona</strong>: no se manda
            por WhatsApp ni se deja apuntado.
          </p>
          <div data-enlace-estado></div>
          <div class="acciones" style="margin-top:10px;flex-wrap:wrap;gap:8px">
            <button type="button" class="btn btn--contorno btn--s" data-enlace-generar>
              ${u.tiene_enlace ? 'Regenerar' : 'Generar enlace'}</button>
            ${u.tiene_enlace ? `
              <button type="button" class="btn btn--contorno btn--s" data-enlace-activo>
                ${Number(u.autologin_activo) ? 'Deshabilitar' : 'Habilitar'}</button>
              <button type="button" class="btn btn--contorno btn--s" data-enlace-retirar>
                Retirar</button>` : ''}
          </div>
        </div>
      </details>` : ''}

      <details style="margin-top:6px">
        <summary style="cursor:pointer;font-size:13px;font-weight:640;color:var(--peligro);padding:10px 2px">
          Borrar esta persona definitivamente</summary>
        <div style="padding-top:8px">
          <p class="campo__ayuda" style="margin-bottom:10px">
            Lo normal es quitarle el acceso: así deja de entrar pero su nombre
            sigue apareciendo en el historial. Borrarla del todo la elimina de
            la base de datos; sus partes se conservan, pero sin autor.
          </p>
          <button type="button" class="btn btn--peligro btn--bloque btn--s" data-borrar-def>
            ${icono('papelera')}Borrar definitivamente
          </button>
        </div>
      </details>` : ''}
    <div id="err-u2"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = soyAdmin
    ? `<button type="button" class="btn ${u.bloqueo ? 'btn--contorno' : 'btn--peligro'}" data-bloqueo>
         ${u.bloqueo ? 'Dar acceso' : 'Quitar acceso'}</button>
       <button type="button" class="btn btn--principal" data-si>Guardar</button>`
    : `<button type="button" class="btn btn--contorno" data-no>Cerrar</button>`;

  hoja({
    titulo: u.nombre_completo || u.correo, cuerpo, pie,
    alAbrir: (h) => {
      pintarCategoriasFrases(h, u);
      if (!soyAdmin) { $('[data-no]', h).onclick = () => cerrarHoja(); return; }
      engancharRol(h, estado);
      engancharExtras(h);

      const err = (t) => { $('#err-u2', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

      /* ---- El enlace de auto-entrada ---- */
      const zonaEnlace = $('[data-enlace-estado]', h);

      /** Enseña el enlace recién generado, para copiarlo.
       *
       * Sólo se ve una vez: del enlace se guarda el resumen, no el
       * enlace, igual que con una contraseña. Si se cierra la hoja sin
       * copiarlo, hay que regenerar. */
      function pintarEnlace(url, caduca) {
        zonaEnlace.innerHTML = `
          <div class="aviso aviso--ok" style="align-items:flex-start">
            ${icono('candado')}<div style="min-width:0">
              <strong>Cópialo ahora</strong><br>
              <span style="font-size:12px">No se guarda entero: si lo pierdes hay que regenerarlo.
              ${caduca ? `Caduca el ${esc(String(caduca).slice(0, 10))}.` : 'No caduca.'}</span>
              <textarea readonly rows="3" data-enlace-texto
                        style="width:100%;box-sizing:border-box;margin-top:8px;font-size:11.5px;
                               font-family:ui-monospace,monospace;word-break:break-all;
                               border:1px solid var(--borde);border-radius:8px;padding:8px;
                               background:var(--superficie);color:var(--tinta)">${esc(url)}</textarea>
              <div class="acciones" style="margin-top:8px;gap:8px">
                <button type="button" class="btn btn--principal btn--s" data-enlace-copiar>Copiar</button>
                <button type="button" class="btn btn--contorno btn--s" data-enlace-compartir>Compartir</button>
              </div>
            </div>
          </div>`;

        $('[data-enlace-copiar]', h).onclick = async () => {
          const caja = $('[data-enlace-texto]', h);
          try {
            await navigator.clipboard.writeText(url);
            mensaje('Enlace copiado.', 'ok');
          } catch {
            /* Sin permiso de portapapeles —pasa en http:// y en algunos
               navegadores— se selecciona el texto para copiarlo a mano,
               en vez de dejar a la persona sin salida. */
            caja.focus(); caja.select();
            mensaje('Selecciónalo y cópialo a mano.', 'aviso');
          }
        };

        const bComp = $('[data-enlace-compartir]', h);
        if (navigator.share) {
          bComp.onclick = () => navigator.share({
            title: 'Acceso a partes',
            text: `Enlace de entrada para ${u.nombre || u.usuario || ''}`.trim(),
            url,
          }).catch(() => { /* si lo cancela, no pasa nada */ });
        } else {
          bComp.remove();
        }
      }

      const bGen = $('[data-enlace-generar]', h);
      if (bGen) bGen.onclick = async () => {
        if (u.tiene_enlace) {
          const ok = await confirmar({
            titulo: 'Regenerar el enlace',
            texto: 'El enlace actual dejará de valer al instante. Quien lo tenga guardado '
                 + 'no podrá entrar con él.',
            aceptar: 'Regenerar', peligro: true,
          });
          if (!ok) return;
        }
        bGen.disabled = true;
        try {
          // La base es la carpeta desde la que se sirve la aplicación.
          const base = location.href.replace(/\/[^/]*(\?.*)?(#.*)?$/, '');
          const r = await api.enlaceGenerar(u.id_login, base);
          u.tiene_enlace = 1;
          u.autologin_activo = 1;
          pintarEnlace(r.url, r.caduca);
          mensaje('Enlace generado.', 'ok');
        } catch (e) { err(e.message); }
        bGen.disabled = false;
      };

      const bAct = $('[data-enlace-activo]', h);
      if (bAct) bAct.onclick = async () => {
        const encender = !Number(u.autologin_activo);
        bAct.disabled = true;
        try {
          const r = await api.enlaceActivar(u.id_login, encender);
          u.autologin_activo = encender ? 1 : 0;
          bAct.textContent = encender ? 'Deshabilitar' : 'Habilitar';
          mensaje(r.mensaje, 'ok');
        } catch (e) { err(e.message); }
        bAct.disabled = false;
      };

      const bRet = $('[data-enlace-retirar]', h);
      if (bRet) bRet.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Retirar el enlace',
          texto: 'Deja de valer al instante y hay que generar otro si vuelve a hacer falta.',
          aceptar: 'Retirar', peligro: true,
        });
        if (!ok) return;
        try {
          const r = await api.enlaceRetirar(u.id_login);
          u.tiene_enlace = 0;
          u.autologin_activo = 0;
          zonaEnlace.innerHTML = '';
          bAct?.remove(); bRet.remove();
          bGen.textContent = 'Generar enlace';
          mensaje(r.mensaje, 'ok');
        } catch (e) { err(e.message); }
      };

      const bdef = $('[data-borrar-def]', h);
      if (bdef) bdef.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Borrar a ' + (u.nombre_completo || u.correo),
          texto: 'Se elimina de la base de datos. Sus partes y movimientos se '
               + 'conservan, pero aparecerán sin autor. Esto no se puede deshacer.',
          aceptar: 'Borrar definitivamente',
          peligro: true,
        });
        if (!ok) return;
        try {
          const r = await api.borrarUsuarioDef(u.id_login);
          cerrarHoja();
          mensaje(r.mensaje, 'ok');
          alTerminar();
        } catch (e) { err(e.message); }
      };

      $('[data-si]', h).onclick = async () => {
        const correo = $('#u-correo2', h)?.value.trim();
        const datos = {
          nombre: $('#u-nombre', h).value.trim(),
          apellido1: $('#u-ap1', h).value.trim(),
          telefono: $('#u-tel', h).value.trim(),
          rol: estado.rol,
          id_departamento: $('#u-dep', h).value || null,
          ...extrasDelFormulario(h),
        };
        /* El correo se manda aunque se haya vaciado: borrarlo es un
           cambio legítimo, y con la condición de antes —que exigía que
           tuviera algo— no había forma de quitárselo a nadie. */
        if (correo.toLowerCase() !== (u.correo || '').toLowerCase()) {
          datos.correo = correo;
        }

        const usuario2 = $('#u-usuario2', h)?.value.trim() || '';
        if (usuario2 && usuario2 !== (u.usuario || '')) {
          datos.usuario = usuario2;
        }
        /* Los mensajes de esta persona. Se mandan siempre: vaciarlos es
           una forma legítima de quitarlos. */
        datos.mensaje_bienvenida = $('#u-bienv', h).value.trim();
        datos.bienvenida_activa  = $('#u-bienv-on', h).checked;
        datos.frases_activas     = $('#u-frases-on', h).checked;
        datos.frases_cada_inicio = $('#u-frases-inicio', h)?.checked || false;
        datos.frases_categorias  = [...h.querySelectorAll('[data-cat][aria-pressed="true"]')]
          .map((b) => Number(b.dataset.cat));
        const proximo = $('#u-proximo', h).value.trim();
        if (proximo !== (u.mensaje_proximo || '')) { datos.mensaje_proximo = proximo; }

        const pwd = $('#u-pwd2', h).value;
        if (pwd) {
          if (pwd.length < 8) return err('La contraseña debe tener al menos 8 caracteres.');
          datos.password = pwd;
        }
        try {
          await api.editarUsuario(u.id_login, datos);
          cerrarHoja();
          mensaje('Ficha guardada.', 'ok');
          alTerminar();
        } catch (e) { err(e.message); }
      };

      $('[data-bloqueo]', h).onclick = async () => {
        const quitar = !u.bloqueo;
        if (quitar) {
          const ok = await confirmar({
            titulo: 'Quitar el acceso',
            texto: `${u.nombre_completo || u.correo} no podrá entrar en la aplicación. Sus partes se conservan.`,
            aceptar: 'Quitar acceso', peligro: true,
          });
          if (!ok) return;
        }
        try {
          await api.editarUsuario(u.id_login, { bloqueo: quitar });
          cerrarHoja();
          mensaje(quitar ? 'Acceso retirado.' : 'Acceso restablecido.', 'ok');
          alTerminar();
        } catch (e) { err(e.message); }
      };
    },
  });
}

function sugerirClave() {
  const s = 'abcdefghijkmnpqrstuvwxyz23456789';
  return Array.from(crypto.getRandomValues(new Uint8Array(10)), (n) => s[n % s.length]).join('');
}
