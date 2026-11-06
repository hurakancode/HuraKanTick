/* =====================================================================
   El editor avanzado de instrucciones.

   Envuelve Editor.js. Este fichero SÓLO se carga cuando alguien va a
   escribir: la librería son 400 KB y quien lee un manual no tiene por
   qué descargarlos. Por eso se trae con import() dinámico y los scripts
   se inyectan a mano.

   Lo que se guarda es la estructura, nunca HTML (bloques.js explica por
   qué). Aquí sólo se construye el editor y se le añaden los dos bloques
   que un manual necesita y Editor.js no trae: «Paso» y «Aviso».
   ===================================================================== */

import { esc } from './ui.js';
import { base, token } from './api.js';

const RAIZ = './assets/vendor/editorjs/';

/* Qué ficheros hacen falta y qué deja cada uno en window. Se comprueba
   el global en vez de recordar si ya se cargó: así dos paneles abiertos
   seguidos no vuelven a pedir 400 KB. */
const PIEZAS = [
  ['editorjs.js',  'EditorJS'],
  ['header.js',    'Header'],
  ['list.js',      'EditorjsList'],
  ['image.js',     'ImageTool'],
  ['embed.js',     'Embed'],
  ['table.js',     'Table'],
];

function cargarScript(src) {
  return new Promise((resolve, reject) => {
    const ya = document.querySelector(`script[data-ed="${src}"]`);
    if (ya) {
      if (ya.dataset.listo === '1') { resolve(); return; }
      ya.addEventListener('load', () => resolve());
      ya.addEventListener('error', () => reject(new Error(src)));
      return;
    }
    const s = document.createElement('script');
    s.src = RAIZ + src;
    s.dataset.ed = src;
    s.onload = () => { s.dataset.listo = '1'; resolve(); };
    s.onerror = () => reject(new Error('No se ha podido cargar ' + src));
    document.head.appendChild(s);
  });
}

/** Trae la librería. Lanza con un mensaje que se pueda enseñar. */
export async function cargarLibreria() {
  for (const [fichero, global] of PIEZAS) {
    if (window[global]) { continue; }
    try {
      await cargarScript(fichero);
    } catch {
      throw new Error(
        'No se ha podido cargar el editor avanzado. Si acabas de actualizar, '
        + 'cierra y vuelve a abrir la aplicación. Mientras, puedes escribir '
        + 'con el editor sencillo.');
    }
    if (!window[global]) {
      throw new Error('El editor avanzado se ha cargado a medias ('
                      + global + '). Usa el editor sencillo.');
    }
  }
}

/* ================================================================== */
/* Los dos bloques propios                                            */
/* ================================================================== */

/**
 * Un paso del procedimiento.
 *
 * Se numera solo al leer, no al escribir: si el número se guardara,
 * mover un paso al medio dejaría la numeración mal y habría que
 * renumerar a mano los veinte de debajo.
 */
function hacerBloquePaso() {
  return class Paso {
    static get toolbox() {
      return {
        title: 'Paso',
        icon: '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M2 4h12M2 8h12M2 12h7" '
            + 'stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
      };
    }

    static get pasteConfig() { return { tags: [] }; }

    constructor({ data, api }) {
      this.api = api;
      this.data = { text: data?.text || '' };
    }

    render() {
      this.caja = document.createElement('div');
      this.caja.className = 'edb-paso';
      this.caja.innerHTML = '<span class="edb-paso__n"></span>';

      this.campo = document.createElement('div');
      this.campo.className = 'edb-paso__txt';
      this.campo.contentEditable = 'true';
      this.campo.dataset.placeholder = 'Qué hay que hacer en este paso';
      this.campo.innerHTML = this.data.text;

      this.caja.appendChild(this.campo);
      return this.caja;
    }

    save() {
      return { text: this.campo.innerHTML.trim() };
    }

    validate(d) { return String(d.text || '').trim() !== ''; }

    static get sanitize() {
      // Lo que Editor.js deja pasar al guardar. El lector vuelve a
      // filtrar: dos puertas estrechas en vez de una que confía.
      return { text: { b: true, i: true, u: true, mark: true, code: true, br: true,
                       a: { href: true } } };
    }
  };
}

/** El recuadro de aviso: info, atención o peligro. */
function hacerBloqueAviso() {
  return class Aviso {
    static get toolbox() {
      return {
        title: 'Aviso',
        icon: '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M8 1.5 15 14H1z" '
            + 'stroke="currentColor" stroke-width="1.6" fill="none"/>'
            + '<path d="M8 6v4M8 11.5v.5" stroke="currentColor" stroke-width="1.6" '
            + 'stroke-linecap="round"/></svg>',
      };
    }

    constructor({ data }) {
      this.data = {
        nivel: ['info', 'atencion', 'peligro'].includes(data?.nivel) ? data.nivel : 'atencion',
        titulo: data?.titulo || '',
        text: data?.text || '',
      };
    }

    render() {
      this.caja = document.createElement('div');
      this.caja.className = 'edb-aviso edb-aviso--' + this.data.nivel;

      const barra = document.createElement('div');
      barra.className = 'edb-aviso__barra';
      barra.innerHTML = [
        ['info', 'Ten en cuenta'], ['atencion', 'Atención'], ['peligro', 'Peligro'],
      ].map(([v, t]) => `
        <button type="button" data-nivel="${v}"
                class="edb-chip ${this.data.nivel === v ? 'edb-chip--on' : ''}">${t}</button>`
      ).join('');

      barra.onclick = (e) => {
        const b = e.target.closest('[data-nivel]');
        if (!b) return;
        this.data.nivel = b.dataset.nivel;
        this.caja.className = 'edb-aviso edb-aviso--' + this.data.nivel;
        [...barra.querySelectorAll('[data-nivel]')].forEach((x) =>
          x.classList.toggle('edb-chip--on', x.dataset.nivel === this.data.nivel));
      };

      this.campo = document.createElement('div');
      this.campo.className = 'edb-aviso__txt';
      this.campo.contentEditable = 'true';
      this.campo.dataset.placeholder = 'Qué hay que tener cuidado con esto';
      this.campo.innerHTML = this.data.text;

      this.caja.appendChild(barra);
      this.caja.appendChild(this.campo);
      return this.caja;
    }

    save() {
      return { nivel: this.data.nivel, titulo: this.data.titulo,
               text: this.campo.innerHTML.trim() };
    }

    validate(d) { return String(d.text || '').trim() !== ''; }

    static get sanitize() {
      return { text: { b: true, i: true, u: true, mark: true, code: true, br: true,
                       a: { href: true } },
               titulo: {}, nivel: false };
    }
  };
}

/**
 * Un clip de vídeo subido al servidor.
 *
 * Editor.js no trae bloque de vídeo propio, así que va aquí. Dos cosas
 * que importan más que el bloque:
 *
 *  · El tamaño se comprueba ANTES de subir. Mandar 40 MB por la wifi del
 *    hotel para que el servidor diga que no es perder tres minutos de
 *    alguien, y el tope real no es el que pide la aplicación sino el que
 *    permita PHP: viene en el catálogo.
 *  · «preload=metadata»: así el móvil del técnico no se descarga el
 *    vídeo entero al abrir el manual, sólo la primera imagen.
 */
function hacerBloqueVideo(subir, tope) {
  return class Video {
    static get toolbox() {
      return {
        title: 'Vídeo',
        icon: '<svg width="16" height="16" viewBox="0 0 16 16">'
            + '<rect x="1" y="3" width="11" height="10" rx="2" stroke="currentColor" '
            + 'stroke-width="1.6" fill="none"/><path d="M12 7l3-2v6l-3-2z" fill="currentColor"/></svg>',
      };
    }

    constructor({ data }) {
      this.data = { url: data?.url || data?.file?.url || '', caption: data?.caption || '' };
    }

    render() {
      this.caja = document.createElement('div');
      this.caja.className = 'edb-video';
      this.pintar();
      return this.caja;
    }

    pintar() {
      if (this.data.url) {
        this.caja.innerHTML = `
          <video src="${esc(this.data.url)}" controls preload="metadata" playsinline
                 style="width:100%;border-radius:8px;display:block"></video>
          <div class="edb-video__pie" contenteditable="true"
               data-placeholder="Pie del vídeo (opcional)">${esc(this.data.caption)}</div>`;
        this.pie = this.caja.querySelector('.edb-video__pie');
        return;
      }

      this.caja.innerHTML = `
        <button type="button" class="edb-video__elegir">Elegir un vídeo</button>
        <div class="edb-video__ayuda">
          mp4, webm o mov, hasta ${tope.mb} MB.
          Para uno más largo, usa «Vídeo de fuera» con un enlace.
        </div>`;

      this.caja.querySelector('.edb-video__elegir').onclick = () => {
        const inp = document.createElement('input');
        inp.type = 'accept' in inp ? 'file' : 'file';
        inp.accept = 'video/mp4,video/webm,video/quicktime';
        inp.onchange = async () => {
          const f = inp.files?.[0];
          if (!f) return;

          if (f.size > tope.bytes) {
            this.caja.querySelector('.edb-video__ayuda').innerHTML =
              `<b style="color:var(--peligro)">Ese vídeo pesa `
              + `${(f.size / 1048576).toFixed(1)} MB y el máximo son ${tope.mb} MB.</b><br>`
              + 'Córtalo, bájale la resolución a 720p, o usa «Vídeo de fuera» con un enlace.';
            return;
          }

          const ayuda = this.caja.querySelector('.edb-video__ayuda');
          ayuda.textContent = 'Subiendo… no cierres el panel.';
          try {
            const r = await subir(f);
            this.data.url = r.file.url;
            this.pintar();
          } catch (e) {
            ayuda.innerHTML = `<b style="color:var(--peligro)">${esc(e.message)}</b>`;
          }
        };
        inp.click();
      };
    }

    save() {
      return { url: this.data.url, caption: this.pie ? this.pie.innerHTML.trim() : '' };
    }

    validate(d) { return String(d.url || '').trim() !== ''; }

    static get sanitize() { return { url: false, caption: { b: true, i: true, br: true } }; }
  };
}

/* ================================================================== */
/* Montar el editor                                                   */
/* ================================================================== */

/**
 * Abre Editor.js dentro de un elemento.
 *
 * @param destino  el div donde vive
 * @param datos    los bloques guardados, o null
 * @param idInstruccion  para que las fotos cuelguen del manual
 * @returns {Promise<{guardar:Function, destruir:Function}>}
 */
export async function montarEditor(destino, datos, idInstruccion = null, tope = null) {
  await cargarLibreria();

  /* El tope real de subida. Si no lo manda quien abre el editor se usa
     uno prudente: enseñar «hasta 40 MB» cuando PHP admite 2 es peor que
     quedarse corto, porque la persona graba el vídeo y lo pierde. */
  tope = tope || { bytes: 2 * 1024 * 1024, mb: 2 };

  /* La subida va por la API, con su sesión y su validación de tipo. El
     bloque de imagen de Editor.js espera exactamente esta respuesta:
     {success:1, file:{url}}. */
  const subidor = {
    async uploadByFile(fichero) {
      const fd = new FormData();
      fd.append('archivo', fichero);
      if (idInstruccion) { fd.append('id_instruccion', String(idInstruccion)); }

      const r = await fetch(base() + '/instrucciones/medios', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + (token() || '') },
        body: fd,
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.url) {
        throw new Error(d?.error?.mensaje || d?.mensaje || 'No se ha podido subir el archivo.');
      }
      return { success: 1, file: { url: d.url } };
    },
    async uploadByUrl(url) {
      // No se descarga nada desde el servidor: pedirle a nuestro servidor
      // que traiga una URL cualquiera es un agujero (SSRF). Se guarda el
      // enlace tal cual y lo carga el navegador de quien lee.
      return { success: 1, file: { url: String(url) } };
    },
  };

  const ed = new window.EditorJS({
    holder: destino,
    data: datos || { blocks: [] },
    placeholder: 'Escribe aquí, o pulsa + para añadir un paso, una foto o un aviso',
    minHeight: 120,
    i18n: {
      messages: {
        ui: {
          blockTunes: { toggler: { 'Click to tune': 'Opciones', 'or drag to move': 'o arrastra' } },
          inlineToolbar: { converter: { 'Convert to': 'Convertir en' } },
          toolbar: { toolbox: { Add: 'Añadir' } },
          popover: { Filter: 'Buscar', 'Nothing found': 'Nada con eso' },
        },
        toolNames: {
          Text: 'Texto', Heading: 'Título', List: 'Lista', Checklist: 'Tareas',
          'Unordered List': 'Lista con viñetas', 'Ordered List': 'Lista numerada',
          'Unordered list': 'Lista con viñetas', 'Ordered list': 'Lista numerada',
          Image: 'Foto', Table: 'Tabla', Link: 'Enlace', Bold: 'Negrita',
          Italic: 'Cursiva', Marker: 'Resaltar', Code: 'Código', Embed: 'Vídeo de fuera',
        },
        blockTunes: {
          delete: { Delete: 'Borrar', 'Click to delete': 'Pulsa para borrar' },
          moveUp: { 'Move up': 'Subir' },
          moveDown: { 'Move down': 'Bajar' },
        },
      },
    },
    tools: {
      header: {
        class: window.Header,
        config: { placeholder: 'Un título de apartado', levels: [2, 3, 4], defaultLevel: 2 },
      },
      /* Una sola herramienta para listas: el paquete nuevo trae con
         viñetas, numeradas y de tareas. Registrar además @editorjs/
         checklist dejaba «Tareas» dos veces en el menú de añadir, y dos
         entradas iguales hacen dudar de si son cosas distintas. */
      list: {
        class: window.EditorjsList,
        inlineToolbar: true,
        config: { defaultStyle: 'unordered' },
      },
      table: { class: window.Table, inlineToolbar: true },
      paso:  { class: hacerBloquePaso(), inlineToolbar: true },
      video: { class: hacerBloqueVideo(subidor.uploadByFile, tope) },
      aviso: { class: hacerBloqueAviso(), inlineToolbar: true },
      image: {
        class: window.ImageTool,
        config: {
          uploader: subidor,
          captionPlaceholder: 'Pie de foto (opcional)',
          buttonContent: 'Elegir una foto',
        },
      },
      embed: {
        class: window.Embed,
        config: { services: { youtube: true, vimeo: true } },
      },
    },
  });

  await ed.isReady;

  return {
    async guardar() {
      const d = await ed.save();
      // Los bloques vacíos no se guardan: un manual con tres párrafos en
      // blanco al final se ve descuidado y no aporta nada.
      d.blocks = (d.blocks || []).filter((b) => {
        const t = b?.data?.text;
        if (typeof t === 'string' && t.trim() === '') {
          return !['paragraph', 'paso', 'aviso', 'header'].includes(b.type);
        }
        return true;
      });
      return d;
    },
    destruir() {
      try { ed.destroy?.(); } catch { /* ya estaba cerrado */ }
    },
  };
}
