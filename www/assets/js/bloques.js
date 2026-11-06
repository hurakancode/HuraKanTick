/* =====================================================================
   Dibujar una instrucción escrita por bloques.

   Esto es lo que lee el técnico, así que va APARTE del editor y pesa
   nada: unas pocas decenas de líneas. Editor.js —400 KB— sólo se carga
   cuando alguien va a escribir, que son cuatro responsables. Los 50
   móviles que sólo leen no se descargan nunca el editor.

   ──────────────────────────────────────────────────────────────────────
   POR QUÉ ESTE FICHERO EXISTE EN VEZ DE GUARDAR HTML

   La tentación es guardar el HTML que suelta el editor y pintarlo con
   innerHTML. Es menos código y abre un agujero permanente: cualquiera
   que pueda escribir una instrucción podría meter un <script>, y ese
   script se ejecutaría en el móvil de todo el que abra el manual.
   Sanearlo en PHP sería otra librería más que mantener.

   Así que en la base se guarda la ESTRUCTURA —qué bloques, con qué
   texto— y el HTML se construye aquí, escapando todo lo que venga de
   fuera. Lo que no se guarda no puede ejecutarse.

   El formato en línea («negrita», «cursiva», enlaces) sí llega como
   HTML desde el editor, así que pasa por un filtro de etiquetas
   permitidas: `enLinea()`. Es la única puerta, y es estrecha.
   ===================================================================== */

import { esc, icono } from './ui.js';
import { urlAdjunto } from './api.js';

/** Las etiquetas de formato que se dejan pasar dentro de un párrafo. */
const PERMITIDAS = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'MARK', 'CODE', 'BR', 'A']);

/**
 * Limpia el formato en línea que trae un bloque.
 *
 * Editor.js guarda «<b>así</b>» dentro del texto. Se vuelve a construir
 * nodo a nodo quedándose sólo con lo de PERMITIDAS: un <script>, un
 * onclick o un <iframe> no sobreviven porque no se copian, no porque se
 * intenten borrar. Quitar cosas de una lista negra siempre se queda
 * corto; copiar sólo lo de una lista blanca, no.
 */
export function enLinea(html) {
  /* Se analiza con DOMParser, NO con innerHTML sobre un div.
   *
   * Esto lo cazó una prueba con contenido hostil: asignar innerHTML a un
   * elemento suelto YA ejecuta el payload. Un
   * «<img src=x onerror=...>» empieza a cargar en ese momento, falla, y
   * el onerror se dispara —dentro del propio limpiador, antes de limpiar
   * nada—. El documento que devuelve DOMParser es inerte: no carga
   * imágenes ni ejecuta scripts. */
  const doc = new DOMParser().parseFromString(
    '<body>' + String(html ?? '') + '</body>', 'text/html');
  const sucio = doc.body;

  const limpiar = (origen, destino) => {
    for (const n of [...origen.childNodes]) {
      if (n.nodeType === Node.TEXT_NODE) {
        destino.appendChild(document.createTextNode(n.nodeValue));
        continue;
      }
      if (n.nodeType !== Node.ELEMENT_NODE) continue;

      if (!PERMITIDAS.has(n.tagName)) {
        // No se copia la etiqueta, pero sí lo que lleva dentro: así
        // «<div>texto</div>» no pierde el texto.
        limpiar(n, destino);
        continue;
      }

      const nuevo = document.createElement(n.tagName.toLowerCase());
      if (n.tagName === 'A') {
        /* Sólo http(s). Un enlace «javascript:...» ejecuta código con
           sólo tocarlo, y en un manual nadie desconfía de un enlace. */
        const href = n.getAttribute('href') || '';
        if (/^https?:\/\//i.test(href)) {
          nuevo.setAttribute('href', href);
          nuevo.setAttribute('target', '_blank');
          nuevo.setAttribute('rel', 'noopener noreferrer');
        }
      }
      limpiar(n, nuevo);
      destino.appendChild(nuevo);
    }
  };

  const limpio = document.createElement('div');
  limpiar(sucio, limpio);
  return limpio.innerHTML;
}

/* La dirección de un medio. Se reutiliza urlAdjunto, que es la que ya
   sabe resolver la base de la API en el móvil, en el APK y en el
   navegador: calcularla aquí otra vez saldría mal en alguno de los
   tres. */
function urlMedio(u) {
  const s = String(u || '').trim();

  /* Una foto o un vídeo de un manual SIEMPRE sale de nuestras subidas, y
     eso es una ruta relativa. Una dirección a un servidor de fuera se
     rechaza aunque sea https, por tres razones:

     - delata a quien lee: el servidor de fuera ve la IP y la hora de cada
       técnico que abre el manual;
     - el contenido lo controla otro, y lo que hoy es una foto mañana es
       otra cosa;
     - en el sótano, sin cobertura, no carga y queda un hueco.

     Para un vídeo alojado fuera está el bloque «Vídeo de fuera», que se
     enseña como enlace y se abre aparte, no dentro de la aplicación. */
  if (/^https?:\/\//i.test(s)) return '';

  /* Cualquier cosa con «:» antes de la primera «/» es un esquema, y aquí
     sólo valen http y https. Un «javascript:» en el src de una imagen no
     se ejecuta en los navegadores de hoy, pero dejarlo pasar es confiar
     en eso: basta una etiqueta nueva o un navegador raro para que sí.
     Una ruta nuestra no lleva esquema nunca. */
  if (/^[a-z][a-z0-9+.-]*:/i.test(s)) return '';
  return urlAdjunto(s);
}

/* ------------------------------------------------------------------ */
/* Cada bloque                                                        */
/* ------------------------------------------------------------------ */

const DIBUJAR = {
  paragraph: (d) => `<p class="ins__p">${enLinea(d.text)}</p>`,

  header: (d) => {
    const n = Math.min(4, Math.max(2, parseInt(d.level, 10) || 2));
    return `<h${n} class="ins__h">${enLinea(d.text)}</h${n}>`;
  },

  list: (d) => {
    const et = d.style === 'ordered' ? 'ol' : 'ul';
    const items = (d.items || []).map((i) => {
      // La lista nueva guarda {content, items}; la vieja, texto suelto.
      const texto = typeof i === 'string' ? i : (i.content ?? '');
      const hijos = (typeof i === 'object' && i.items?.length)
        ? DIBUJAR.list({ style: d.style, items: i.items }) : '';
      return `<li>${enLinea(texto)}${hijos}</li>`;
    }).join('');
    return `<${et} class="ins__lista">${items}</${et}>`;
  },

  checklist: (d) => `
    <ul class="ins__lista ins__lista--tareas">
      ${(d.items || []).map((i) => `
        <li class="${i.checked ? 'hecho' : ''}">
          ${icono(i.checked ? 'ok' : 'lista')}<span>${enLinea(i.text)}</span>
        </li>`).join('')}
    </ul>`,

  /* Un paso de un manual. Bloque propio: es la pieza de la que está
     hecho todo procedimiento, y un párrafo suelto no la representa. */
  paso: (d) => `
    <div class="ins__paso">
      <span class="ins__paso-n">${esc(String(d.numero ?? ''))}</span>
      <div class="ins__paso-txt">${enLinea(d.text)}</div>
    </div>`,

  /* El recuadro de aviso. También propio, y por una razón concreta:
     vuestras instrucciones ya lo necesitan —«No purgues con la bomba en
     marcha»— y escrito como un párrafo más se lee y se olvida. */
  aviso: (d) => {
    const nivel = ['info', 'atencion', 'peligro'].includes(d.nivel) ? d.nivel : 'atencion';
    const ic = { info: 'info', atencion: 'aviso', peligro: 'rayo' }[nivel];
    const titulo = { info: 'Ten en cuenta', atencion: 'Atención', peligro: 'Peligro' }[nivel];
    return `
      <div class="ins__aviso ins__aviso--${nivel}">
        ${icono(ic)}
        <div><b>${esc(d.titulo || titulo)}</b><br>${enLinea(d.text)}</div>
      </div>`;
  },

  image: (d) => {
    const u = urlMedio(d.file?.url || d.url);
    if (!u) return '';
    return `
      <figure class="ins__figura">
        <img src="${esc(u)}" alt="${esc(d.caption || '')}" loading="lazy">
        ${d.caption ? `<figcaption>${enLinea(d.caption)}</figcaption>` : ''}
      </figure>`;
  },

  video: (d) => {
    const u = urlMedio(d.file?.url || d.url);
    if (!u) return '';
    return `
      <figure class="ins__figura">
        <video src="${esc(u)}" controls preload="metadata"
               playsinline style="width:100%;border-radius:var(--r-m)"></video>
        ${d.caption ? `<figcaption>${enLinea(d.caption)}</figcaption>` : ''}
      </figure>`;
  },

  /* Un vídeo de fuera. Se enseña como enlace y NO como <iframe>: un
     iframe mete una página ajena dentro de la aplicación, y además en el
     sótano del hotel no cargaría nada y quedaría un hueco negro sin
     explicación. Así al menos se ve de qué es y se puede abrir. */
  embed: (d) => {
    const u = d.source || d.embed;
    if (!u || !/^https?:\/\//i.test(u)) return '';

    /* El editor sólo ofrece YouTube y Vimeo, pero el contenido guardado es
       JSON y alguien con permiso para escribir manuales podría dejar ahí
       cualquier dirección. Un enlace dentro de la aplicación del hotel
       tiene la credibilidad de la aplicación, y eso es justo lo que
       aprovecha una página que pide la contraseña. Así que se comprueba el
       dominio al dibujar, no sólo al escribir. */
    let host = '';
    try { host = new URL(u).hostname.toLowerCase().replace(/^www\./, ''); } catch { return ''; }
    const BUENOS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com',
                    'vimeo.com', 'player.vimeo.com'];
    if (!BUENOS.some((h) => host === h || host.endsWith('.' + h))) {
      return `<div class="ins__aviso">${icono('aviso')}
        <span>Este manual tiene un vídeo alojado en <b>${esc(host)}</b>, que no
        es un sitio admitido. No se enseña el enlace. Pídele a quien lo
        escribió que lo suba o que use YouTube o Vimeo.</span></div>`;
    }
    return `
      <a class="ins__enlace" href="${esc(u)}" target="_blank" rel="noopener noreferrer">
        ${icono('tele')}
        <span>${esc(d.caption || 'Ver el vídeo')}
          <small>Se abre fuera de la aplicación · hace falta internet</small></span>
      </a>`;
  },

  table: (d) => {
    const filas = d.content || [];
    if (!filas.length) return '';
    const cab = d.withHeadings ? filas[0] : null;
    const cuerpo = d.withHeadings ? filas.slice(1) : filas;
    return `
      <div class="ins__tabla-caja"><table class="ins__tabla">
        ${cab ? `<thead><tr>${cab.map((c) => `<th>${enLinea(c)}</th>`).join('')}</tr></thead>` : ''}
        <tbody>${cuerpo.map((f) =>
          `<tr>${f.map((c) => `<td>${enLinea(c)}</td>`).join('')}</tr>`).join('')}</tbody>
      </table></div>`;
  },

  delimiter: () => '<hr class="ins__corte">',
};

/**
 * De los bloques guardados al HTML que se enseña.
 *
 * Un bloque de un tipo que no se conoce no rompe la página: se enseña su
 * texto si lo tiene, y si no se calla. Puede pasar al abrir en un móvil
 * con la versión vieja un manual escrito con la nueva.
 */
export function dibujarBloques(datos) {
  let d = datos;
  if (typeof d === 'string') {
    try { d = JSON.parse(d); } catch { return `<p class="ins__p">${esc(datos)}</p>`; }
  }
  const bloques = d?.blocks || [];
  if (!Array.isArray(bloques)) return '';

  let nPaso = 0;
  return bloques.map((b) => {
    const tipo = b?.type;
    const dat = b?.data || {};
    if (tipo === 'paso') { dat.numero = dat.numero || ++nPaso; }
    const fn = DIBUJAR[tipo];
    if (fn) {
      try { return fn(dat); } catch { return ''; }
    }
    return dat.text ? `<p class="ins__p">${enLinea(dat.text)}</p>` : '';
  }).join('');
}

/** ¿Esto está escrito por bloques? */
export function esBloques(formato, contenido) {
  if (formato === 'bloques') return true;
  // Por si una fila vieja quedó sin formato pero con JSON dentro.
  const t = String(contenido || '').trim();
  return t.startsWith('{') && t.includes('"blocks"');
}

/**
 * De bloques a texto plano.
 *
 * Hace falta al cambiar al editor sencillo, y para el buscador: buscar
 * «purgar» tiene que encontrar un manual aunque esté escrito por
 * bloques.
 */
export function bloquesATexto(datos) {
  let d = datos;
  if (typeof d === 'string') {
    try { d = JSON.parse(d); } catch { return String(datos || ''); }
  }
  /* Con DOMParser, por lo mismo que en enLinea(): un innerHTML sobre un
     div suelto ya dispara el onerror de una imagen. Esta función la usa
     el buscador, así que correría en cada tecla. */
  const limpio = (h) => {
    const doc = new DOMParser().parseFromString(
      '<body>' + String(h ?? '') + '</body>', 'text/html');
    // El texto de un <script> no es texto del manual: fuera.
    doc.body.querySelectorAll('script, style, template').forEach((n) => n.remove());
    return (doc.body.textContent || '').trim();
  };
  let n = 0;
  return (d?.blocks || []).map((b) => {
    const t = b?.type;
    const dd = b?.data || {};
    if (t === 'paso')   { n += 1; return `${n}. ${limpio(dd.text)}`; }
    if (t === 'aviso')  { return `⚠ ${limpio(dd.titulo || 'Atención')}: ${limpio(dd.text)}`; }
    if (t === 'header') { return `\n${limpio(dd.text)}\n`; }
    if (t === 'list' || t === 'checklist') {
      return (dd.items || []).map((i) =>
        '· ' + limpio(typeof i === 'string' ? i : (i.content ?? i.text ?? ''))).join('\n');
    }
    if (t === 'image' || t === 'video') { return dd.caption ? `[${limpio(dd.caption)}]` : ''; }
    if (t === 'embed') { return dd.source || ''; }
    if (t === 'table') {
      return (dd.content || []).map((f) => f.map(limpio).join(' | ')).join('\n');
    }
    return limpio(dd.text);
  }).filter((x) => String(x).trim() !== '').join('\n');
}
