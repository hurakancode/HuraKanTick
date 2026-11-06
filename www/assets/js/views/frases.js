/* =====================================================================
   Frases del día.

   Un pequeño almacén de frases repartidas en categorías. A cada persona
   se le asignan las categorías que le encajen —en Equipo— y cada día
   recibe una que no haya leído.

   No es una función de trabajo: es para que el turno empiece con algo
   más que una lista de averías. Por eso viene apagada para todos y se
   enciende persona a persona.
   ===================================================================== */

import { api } from '../api.js';
import { panelLista } from '../listas.js';
import { $, icono, esc, mensaje, hoja, cerrarHoja, confirmar, elegir } from '../ui.js';
import { pintarCabecera, app } from '../app.js';
import { cabeceraVolver } from '../piezas.js';

export async function vistaFrases({ contenido }) {
  pintarCabecera(cabeceraVolver('Frases', 'Las frases del día y sus categorías'));

  if (!app.usuario?.es_admin) {
    contenido.innerHTML = `<div class="aviso aviso--info">${icono('candado')}<div>
      Las frases las gestiona un administrador.</div></div>`;
    return;
  }

  await pintar(contenido);
}

/* Traer y dibujar van separados a propósito.
   Antes era una sola función que pedía los datos al servidor y pintaba.
   Con un buscador eso significa una petición por cada letra que se
   escribe: para filtrar una lista que ya está en memoria. */
async function pintar(contenido) {
  contenido.innerHTML = `<div class="hueso" style="height:180px"></div>`;

  let datos;
  try {
    datos = await api.frases();
  } catch (e) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
    return;
  }

  const cats = datos.categorias || [];
  const todas = datos.frases || [];

  /* Las frases se acumulan: cien o doscientas en cuatro categorías. Sin
     buscador no se encuentra una para corregirle una falta, y sin
     acciones en masa desactivar las de una categoría es ir una por una. */
  const L = panelLista({
    datos: () => todas,
    id: (f) => f.id_frase,
    texto: (f) => [f.texto, f.autor, cats.find((c) => c.id_categoria === f.id_categoria)?.nombre],
    placeholder: 'Buscar una frase o un autor…',
    nombreUno: 'frase',
    nombreVarios: 'frases',

    filtros: [
      {
        clave: 'estado', titulo: 'Todas',
        opciones: [{ valor: 'si', texto: 'Activas' }, { valor: 'no', texto: 'Desactivadas' }],
        cumple: (f, v) => (v === 'si' ? !!f.activo : !f.activo),
      },
      {
        clave: 'cat', titulo: 'Cualquier categoría',
        opciones: [...cats.map((c) => ({ valor: c.id_categoria, texto: c.nombre })),
                   { valor: 'sin', texto: 'Sin categoría' }],
        cumple: (f, v) => (v === 'sin' ? !f.id_categoria
                                       : String(f.id_categoria) === String(v)),
      },
    ],

    acciones: [
      { clave: 'activar', texto: 'Activar', icono: 'ok',
        hacer: (ids) => enLote(ids, { activo: true }, 'activadas') },
      { clave: 'desactivar', texto: 'Desactivar', icono: 'candado',
        hacer: (ids) => enLote(ids, { activo: false }, 'desactivadas') },
      { clave: 'borrar', texto: 'Borrar', icono: 'papelera', peligro: true,
        confirmar: (n) => ({
          titulo: `Borrar ${n} frase${n === 1 ? '' : 's'}`,
          texto: 'Esto sí las quita del todo. Si sólo quieres que dejen de salir, '
               + 'usa «Desactivar»: se conservan y se pueden volver a encender.',
          aceptar: 'Borrarlas', peligro: true,
        }),
        hacer: (ids) => enLote(ids, null, 'borradas') },
    ],

    alCambiar: () => dibujar(),
  });

  async function enLote(ids, cambios, comoSeDice) {
    let bien = 0;
    const mal = [];
    for (const id of ids) {
      try {
        if (cambios === null) { await api.borrarFrase(id); }
        else { await api.editarFrase(id, cambios); }
        bien++;
      } catch (e) { mal.push('#' + id + ': ' + e.message); }
    }
    mensaje(bien ? `${bien} frase(s) ${comoSeDice}.` : 'No se ha podido con ninguna.',
            bien ? 'ok' : 'error');
    if (mal.length) { mensaje(mal.join(' · '), 'aviso'); }
    await pintar(contenido);   // aquí sí se vuelve a traer: han cambiado
  }

  dibujar();

  function dibujar() {
  const frases = L.visibles();
  const porCat = new Map(cats.map((c) => [c.id_categoria, c.nombre]));

  // Agrupadas por categoría: así se ve de un vistazo dónde falta material.
  const grupos = new Map();
  for (const f of frases) {
    const k = f.id_categoria ?? 0;
    if (!grupos.has(k)) grupos.set(k, []);
    grupos.get(k).push(f);
  }

  contenido.innerHTML = `
    <div class="aviso aviso--info">${icono('info')}<div>
      Cada persona recibe una frase distinta al día, de las categorías que
      tenga asignadas en <b>Equipo</b>. No se le repite ninguna hasta que se
      acaben las suyas.
    </div></div>

    <div class="filas">
      <button type="button" class="fila" data-nueva>
        ${icono('mas')}
        <span class="fila__texto">Añadir una frase</span>
        ${icono('flecha', 'fila__flecha')}
      </button>
      <button type="button" class="fila" data-nueva-cat>
        ${icono('banderin')}
        <span class="fila__texto">Añadir una categoría
          <small>${cats.length} ${cats.length === 1 ? 'categoría' : 'categorías'}</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>

    ${cats.length ? `
      <div class="seccion"><h2>Categorías</h2>
        <span class="rotulo">toca para cambiar o borrar</span></div>
      <div class="filas">
        ${cats.map((c) => `
          <button type="button" class="fila" data-cat="${c.id_categoria}">
            ${icono('banderin')}
            <span class="fila__texto">${esc(c.nombre)}
              <small>${(() => { const n = frases.filter((f) => f.id_categoria === c.id_categoria).length;
                       return n === 1 ? '1 frase' : n + ' frases'; })()}</small></span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>` : ''}

    ${L.html()}

    ${frases.length === 0 && todas.length === 0 ? `
      <div class="vacio" style="margin-top:18px">${icono('libro')}
        <p>Todavía no hay frases. Añade unas cuantas y repártelas por categorías.</p>
      </div>` : ''}

    ${[...grupos.entries()].map(([idCat, lista]) => `
      <div class="seccion"><h2>${esc(porCat.get(idCat) || 'Sin categoría')}</h2>
        <span class="rotulo">${lista.length}</span></div>
      <div class="filas">
        ${lista.map((f) => `
          <button type="button" class="fila" data-frase="${f.id_frase}" style="align-items:flex-start">
            ${L.marca(f)}
            <span class="fila__texto" style="white-space:normal">${esc(f.texto)}
              ${f.autor ? `<small>${esc(f.autor)}</small>` : ''}
              ${f.activo ? '' : '<small>Desactivada</small>'}</span>
            ${icono('flecha', 'fila__flecha')}
          </button>`).join('')}
      </div>`).join('')}`;

  L.enganchar(contenido);

  /* Un solo manejador para toda la pantalla, mirando qué se ha tocado.
     Con un manejador por botón, un repintado a medias podía dejar filas
     con el de antes. */
  contenido.onclick = (e) => {
    if (e.target.closest('[data-lst-marca]')) return;   // marcar no abre la ficha
    if (e.target.closest('[data-nueva]'))     { fichaFrase(null, cats, contenido); return; }
    if (e.target.closest('[data-nueva-cat]')) { fichaCategoria(null, contenido); return; }
    const bc = e.target.closest('[data-cat]');
    if (bc) {
      fichaCategoria(cats.find((c) => String(c.id_categoria) === bc.dataset.cat), contenido);
      return;
    }
    const bf = e.target.closest('[data-frase]');
    if (bf) fichaFrase(todas.find((x) => String(x.id_frase) === bf.dataset.frase), cats, contenido);
  };
  }
}

/* Crear, renombrar o borrar una categoría. Antes sólo se podía crear:
   quien acabó con categorías repetidas no tenía forma de quitarlas. */
function fichaCategoria(cat, contenido) {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="c-nombre">Nombre de la categoría</label>
      <input class="entrada" id="c-nombre" maxlength="80"
             placeholder="Ánimo, Seguridad, Hostelería…" value="${esc(cat?.nombre || '')}">
    </div>
    ${cat ? `<div class="campo__ayuda">Al borrarla, sus frases se quedan sin categoría: no se pierde ninguna.</div>` : ''}`;
  hoja({
    titulo: cat ? 'Categoría' : 'Nueva categoría',
    cuerpo,
    pie: `${cat ? '<button type="button" class="btn btn--peligro" data-borrar>Borrar</button>' : ''}
          <button type="button" class="btn btn--principal btn--bloque" data-si>${cat ? 'Guardar' : 'Crear'}</button>`,
    alAbrir: (h) => {
      $('[data-si]', h).onclick = async () => {
        const nombre = $('#c-nombre', h).value.trim();
        if (!nombre) { mensaje('Ponle nombre.', 'error'); return; }
        try {
          if (cat) await api.editarCategoriaFrase(cat.id_categoria, { nombre });
          else await api.crearCategoriaFrase({ nombre });
          cerrarHoja();
          mensaje(cat ? 'Categoría guardada.' : 'Categoría creada.', 'ok');
          pintar(contenido);
        } catch (e) { mensaje(e.message, 'error'); }
      };
      const bBorrar = $('[data-borrar]', h);
      if (bBorrar) bBorrar.onclick = async () => {
        const ok = await confirmar({
          titulo: `Borrar «${cat.nombre}»`,
          texto: 'Sus frases se quedan sin categoría, pero no se borran.',
          aceptar: 'Borrar', peligro: true,
        });
        if (!ok) return;
        try {
          const r = await api.borrarCategoriaFrase(cat.id_categoria);
          cerrarHoja();
          mensaje(r.mensaje, 'ok');
          pintar(contenido);
        } catch (e) { mensaje(e.message, 'error'); }
      };
    },
  });
}

function fichaFrase(f, cats, contenido) {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="f-texto">La frase</label>
      <textarea class="entrada" id="f-texto" rows="3" maxlength="400"
        placeholder="Lo que se hace bien, se hace una vez.">${esc(f?.texto || '')}</textarea>
    </div>
    <div class="campo"><label for="f-autor">De quién es <small>opcional</small></label>
      <input class="entrada" id="f-autor" maxlength="120" value="${esc(f?.autor || '')}">
    </div>
    <div class="campo"><label>Categoría</label>
      <div class="opciones" id="f-cats">
        ${cats.map((c) => `
          <button type="button" class="opcion" data-c="${c.id_categoria}"
                  aria-pressed="${f?.id_categoria === c.id_categoria}">${esc(c.nombre)}</button>`).join('')
          || '<span class="campo__ayuda">Crea antes una categoría.</span>'}
      </div>
    </div>
    ${f ? `
      <div class="filas" style="margin-top:12px">
        <button type="button" class="fila" data-borrar>
          ${icono('papelera')}
          <span class="fila__texto" style="color:var(--peligro)">Borrar la frase
            <small>También se olvida quién la había leído</small></span>
        </button>
      </div>` : ''}`;

  let idCat = f?.id_categoria ?? null;

  hoja({
    titulo: f ? 'Editar la frase' : 'Nueva frase',
    cuerpo,
    pie: `<button type="button" class="btn btn--principal btn--bloque" data-si>${f ? 'Guardar' : 'Añadir'}</button>`,
    alAbrir: (h) => {
      $('#f-cats', h).onclick = (e) => {
        const b = e.target.closest('[data-c]');
        if (!b) return;
        h.querySelectorAll('[data-c]').forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        idCat = Number(b.dataset.c);
      };

      $('[data-si]', h).onclick = async () => {
        const texto = $('#f-texto', h).value.trim();
        if (!texto) { mensaje('Escribe la frase.', 'error'); return; }
        const datos = { texto, autor: $('#f-autor', h).value.trim(), id_categoria: idCat };
        try {
          if (f) await api.editarFrase(f.id_frase, datos);
          else await api.crearFrase(datos);
          cerrarHoja();
          mensaje(f ? 'Frase guardada.' : 'Frase añadida.', 'ok');
          pintar(contenido);
        } catch (e) { mensaje(e.message, 'error'); }
      };

      const bBorrar = $('[data-borrar]', h);
      if (bBorrar) bBorrar.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Borrar la frase',
          texto: 'Se quita del almacén y se olvida quién la había leído.',
          aceptar: 'Borrarla',
          peligro: true,
        });
        if (!ok) return;
        try {
          await api.borrarFrase(f.id_frase);
          cerrarHoja();
          mensaje('Frase borrada.', 'ok');
          pintar(contenido);
        } catch (e) { mensaje(e.message, 'error'); }
      };
    },
  });
}
