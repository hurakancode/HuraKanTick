/* =====================================================================
   Guía de uso — buscador, animaciones y vuelta a la aplicación

   Sin dependencias: la guía tiene que abrir aunque el resto de la
   aplicación esté a medio cargar, porque a veces se abre justo cuando
   algo no funciona.
   ===================================================================== */

/* ------------------------------------------------------------------ */
/* Volver                                                              */
/* ------------------------------------------------------------------ */

document.getElementById('b-volver')?.addEventListener('click', () => {
  /* Si se llegó desde la aplicación, atrás devuelve al sitio exacto —y
     al estado de la lista, los filtros y el desplazamiento—. Si se abrió
     la guía directamente (un enlace por WhatsApp, un favorito), atrás no
     lleva a ninguna parte y hay que ir al inicio a mano. */
  if (history.length > 1 && document.referrer.includes(location.host)) {
    history.back();
  } else {
    location.href = 'index.html#/panel';
  }
});

/* ------------------------------------------------------------------ */
/* Volver arriba                                                       */
/* ------------------------------------------------------------------ */

const bArriba = document.getElementById('b-arriba');

addEventListener('scroll', () => {
  bArriba?.classList.toggle('se-ve', scrollY > 420);
}, { passive: true });

bArriba?.addEventListener('click', () => {
  const suave = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  scrollTo({ top: 0, behavior: suave ? 'smooth' : 'auto' });
});

/* ------------------------------------------------------------------ */
/* Las secciones entran al llegar                                      */
/* ------------------------------------------------------------------ */

const entradas = document.querySelectorAll('.guia-entra');

if ('IntersectionObserver' in window) {
  const vigia = new IntersectionObserver((filas) => {
    filas.forEach((f) => {
      if (f.isIntersecting) {
        f.target.classList.add('dentro');
        vigia.unobserve(f.target);   // una vez dentro, se queda
      }
    });
  }, { rootMargin: '0px 0px -60px 0px', threshold: 0.06 });

  entradas.forEach((e) => vigia.observe(e));
} else {
  // Navegador viejo: se ve todo desde el principio, que es lo importante.
  entradas.forEach((e) => e.classList.add('dentro'));
}

/* ------------------------------------------------------------------ */
/* Buscador                                                            */
/* ------------------------------------------------------------------ */

const campo   = document.getElementById('q');
const caja    = document.getElementById('caja-buscar');
const limpiar = document.getElementById('b-limpiar');
const cuenta  = document.getElementById('cuenta');
const sinNada = document.getElementById('sinnada');
const secciones = [...document.querySelectorAll('.guia-seccion')];

/* ------------------------------------------------------------------ */
/* Filtro por puesto                                                   */
/* ------------------------------------------------------------------ */

/* La guía entera son 29 apartados y a nadie le tocan todos: recepción
   necesita doce y ninguno de administración. Enseñarle los 29 hace que
   no lea ninguno. */

const cajaPuesto = document.getElementById('puesto');
const notaPuesto = document.getElementById('puesto-nota');
const entradasIndice = [...document.querySelectorAll('.guia-indice li')];

const NOTAS = {
  todo:        '',
  recepcion:   'Lo que hace falta para dar partes y seguirlos.',
  tecnico:     'Dar partes, recibirlos, trabajarlos y cerrarlos.',
  responsable: 'Todo lo del técnico, más repartir el trabajo e informes.',
  admin:       'La guía entera, incluida la administración del sistema.',
};

/* Se recuerda entre visitas: quien es de recepción lo es todos los días.
   Si el navegador no deja guardar —modo privado, permisos—, se sigue
   usando igual: sólo se pierde la memoria entre visitas. */
let puesto = 'todo';
try { puesto = localStorage.getItem('guia-puesto') || 'todo'; } catch { /* da igual */ }

function aplicarPuesto(nuevo) {
  puesto = nuevo;
  try { localStorage.setItem('guia-puesto', nuevo); } catch { /* da igual */ }

  cajaPuesto?.querySelectorAll('[data-p]').forEach((b) => {
    b.setAttribute('aria-pressed', String(b.dataset.p === nuevo));
  });

  // Se vuelve a filtrar con lo que hubiera escrito en el buscador.
  buscar(campo?.value || '');

  const vistos = secciones.filter((s) => !s.classList.contains('guia-oculto')).length;
  notaPuesto.textContent = nuevo === 'todo'
    ? `Los ${secciones.length} apartados.`
    : `${NOTAS[nuevo]} ${vistos} de ${secciones.length} apartados.`;
}

cajaPuesto?.addEventListener('click', (e) => {
  const b = e.target.closest('[data-p]');
  if (b) aplicarPuesto(b.dataset.p);
});

/** Quita tildes y baja a minúsculas: buscar «averia» tiene que encontrar
    «avería», igual que en la aplicación. */
const plano = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/* Se guarda el HTML original de cada sección para poder deshacer el
   resaltado sin reconstruir la página. */
const original = new Map();
secciones.forEach((s) => original.set(s, s.innerHTML));

let reloj = null;

function resaltarEn(nodo, aguja) {
  const paseo = document.createTreeWalker(nodo, NodeFilter.SHOW_TEXT, {
    acceptNode(t) {
      // Ni dentro de <script>/<style>, ni en trozos en blanco.
      const p = t.parentElement;
      if (!p || p.closest('script, style')) return NodeFilter.FILTER_REJECT;
      return t.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
    },
  });

  const textos = [];
  while (paseo.nextNode()) textos.push(paseo.currentNode);

  let encontrados = 0;

  textos.forEach((t) => {
    const llano = plano(t.nodeValue);
    if (!llano.includes(aguja)) return;

    const trozos = document.createDocumentFragment();
    let desde = 0;
    let pos = llano.indexOf(aguja);

    while (pos !== -1) {
      if (pos > desde) {
        trozos.appendChild(document.createTextNode(t.nodeValue.slice(desde, pos)));
      }
      const marca = document.createElement('mark');
      marca.className = 'guia-marca';
      // Se recorta del texto ORIGINAL, así se conservan tildes y mayúsculas.
      marca.textContent = t.nodeValue.slice(pos, pos + aguja.length);
      trozos.appendChild(marca);
      encontrados += 1;

      desde = pos + aguja.length;
      pos = llano.indexOf(aguja, desde);
    }
    if (desde < t.nodeValue.length) {
      trozos.appendChild(document.createTextNode(t.nodeValue.slice(desde)));
    }
    t.parentNode.replaceChild(trozos, t);
  });

  return encontrados;
}

/** ¿Le toca este apartado a quien está mirando la guía? */
function leToca(el) {
  if (puesto === 'todo') return true;
  return (el.dataset.papeles || '').split(' ').includes(puesto);
}

function buscar(texto) {
  const aguja = plano(texto.trim());

  // Se deshace siempre lo anterior antes de volver a marcar.
  secciones.forEach((s) => {
    s.innerHTML = original.get(s);
    s.classList.toggle('guia-oculto', !leToca(s));
  });
  entradasIndice.forEach((li) => li.classList.toggle('guia-oculto', !leToca(li)));

  caja.classList.toggle('tiene', aguja.length > 0);

  if (aguja.length < 2) {
    cuenta.textContent = '';
    sinNada.classList.remove('se-ve');
    return;
  }

  let total = 0;
  let conAlgo = 0;

  secciones.forEach((s) => {
    /* Lo que no le toca a este puesto no se busca: sale confuso encontrar
       algo y que no se pueda leer, o peor, leer instrucciones de una
       pantalla que no se tiene. */
    if (!leToca(s)) return;

    const n = resaltarEn(s, aguja);
    if (n === 0) {
      s.classList.add('guia-oculto');
    } else {
      total += n;
      conAlgo += 1;
      s.classList.add('dentro');   // visible aunque no se haya llegado con el scroll
    }
  });

  sinNada.classList.toggle('se-ve', conAlgo === 0);

  cuenta.textContent = conAlgo === 0
    ? (puesto === 'todo' ? 'Nada con esa palabra'
                         : 'Nada con esa palabra en lo que te toca')
    : `${total} ${total === 1 ? 'resultado' : 'resultados'} en ` +
      `${conAlgo} ${conAlgo === 1 ? 'apartado' : 'apartados'}`;
}

campo?.addEventListener('input', () => {
  /* Se espera un poco a que deje de escribir: marcar en cada tecla hace
     saltar la página entera mientras se teclea. */
  clearTimeout(reloj);
  reloj = setTimeout(() => buscar(campo.value), 170);
});

campo?.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { campo.value = ''; buscar(''); campo.blur(); }
});

limpiar?.addEventListener('click', () => {
  campo.value = '';
  buscar('');
  campo.focus();
});

/* Al abrir, se aplica el puesto recordado. */
aplicarPuesto(puesto);

/* Se puede llegar buscando algo desde fuera: guia.html?q=papelera */
const pedido = new URLSearchParams(location.search).get('q');
if (pedido && campo) {
  campo.value = pedido;
  buscar(pedido);
}
