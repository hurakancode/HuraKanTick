/* =====================================================================
   El índice de búsqueda de los ajustes.

   Hay tres sitios donde alguien busca un ajuste —Ajustes, Administración
   y Configuración— y antes cada uno buscaba por su cuenta: en Ajustes
   encontraba «Departamentos» y en Configuración no, porque allí sólo se
   filtraban las filas de esa pantalla. Aquí está el índice una sola vez y
   lo usan las tres, así que ya no pueden separarse.
   ===================================================================== */

import { textoPlano, icono, esc } from './ui.js';
import { api } from './api.js';
import { app } from './app.js';

/** Las pantallas, con palabras por las que alguien las buscaría. */
export const PANTALLAS = [
  ['Equipo',                    '/equipo',           'personas usuarios altas bajas contraseñas papeles permisos quién trabaja'],
  ['Departamentos',             '/departamentos',    'departamento grupos servicios técnicos pisos recepción cocina informática reparto a quién va'],
  ['Gestión de hoteles',        '/hoteles',          'hotel cadena sedes edificios'],
  ['Tipos de avería',           '/admin/elementos',  'elementos catálogo qué falla averías tipos iconos'],
  ['Familias',                  '/admin/familias',   'áreas oficios agrupar clases familia grupo que lo lleva departamento'],
  ['Ubicaciones',               '/admin/zonas',      'plantas zonas lugares habitaciones sitios dónde'],
  ['Campos por tipo de avería', '/admin/campos',     'preguntas formularios datos propios campos personalizados aire acondicionado temperatura filtros'],
  ['Mensajes',                  '/mensajes',         'chat mensajes conversaciones hablar recientes teams sin leer bandeja'],
  ['Importar / Exportar personas','/personal',
    'importar exportar usuarios personas altas masivo txt fichero plantilla lote '
    + 'dar de alta muchos csv excel'],
  ['Guía de la API',            '/guia-api',         'api rest integrar conectar otro programa desarrollador json token'],
  ['SAP',                       '/sap/habitacion',   'sap pms ocupación habitación huésped recepción idioma país'],
  ['Conexión con SAP',          '/sap/conexion',     'sap probar conexión pms conector integración'],
  ['Atajos del inicio',         '/admin/atajos',     'accesos rápidos portada botones inicio'],
  ['Configuración',             '/admin/ajustes',    'ajustes opciones sistema parámetros'],
  ['Vista smartphone',          '/admin/vista',      'marco móvil escritorio pantalla forzar vista'],
  ['Seguridad',                 '/admin/seguridad',  'contraseñas intentos sesiones bloqueo https certificado'],
  ['Registro de seguridad',     '/seguridad',        'auditoría accesos qué ha pasado registro'],
  ['Contraseñas olvidadas',     '/admin/claves',     'recuperar clave restablecer olvidada'],
  ['Copias de seguridad',       '/admin/copias',     'respaldo backup restaurar copia'],
  ['Integridad',                '/admin/integridad', 'integridad comprobar revisar todo bien salud estado '
    + 'errores huérfanos datos sueltos candados https disco copias versión diagnóstico'],
  ['Papelera de reciclaje',     '/papelera',         'borrado eliminado recuperar papelera'],
  ['Gestión de partes',         '/gestion-partes',   'mover eliminar bloque partes lote'],
  ['Iconografía',               '/iconografia',      'iconos dibujos font awesome símbolos departamento área tipo'],
  ['Frases',                    '/frases',           'frase del día motivación categorías mensajes bienvenida'],
  ['Mantenimiento programado',  '/mantenimiento',    'revisiones preventivo periódico programado'],
  ['Instrucciones',             '/instrucciones',    'cómo se hace guías procedimientos'],
  ['Números de serie',          '/unidades',         'aparatos equipos historial serie unidades'],
  ['Informes',                  '/informes',         'estadísticas cuántas gráficos exportar informes'],
  ['Avisos',                    '/avisos-ajustes',   'notificaciones sonido vibración push campana probar'],
  ['Apariencia',                '/apariencia',       'tema oscuro claro bloques orden pantalla letra'],
  ['Guía de uso',               '/cuenta',           'ayuda manual cómo funciona guía'],
];

/** Lo que se ajusta en Apariencia, que no son filas de Configuración. */
export const DE_APARIENCIA = [
  ['Modo oscuro',            'tema oscuro claro noche brillo'],
  ['Bloques del inicio',     'portada cifras atajos orden inicio'],
  ['Bloques del parte',      'ficha orden notas fotos seguimiento'],
  ['Vista smartphone',       'marco móvil escritorio'],
];

/**
 * Las acciones que viven dentro de una pantalla.
 *
 * Esto faltaba por completo: el buscador conocía las pantallas y los
 * ajustes de Configuración, pero no los botones que hay dentro. Buscar
 * «forzar la actualización» no encontraba nada, aunque esa acción
 * exista, porque vive dentro de Administración.
 *
 * Cada línea es: título que se ve · dónde está · por qué palabras se
 * busca · quién puede.
 *
 * La prueba pruebas/buscador.py recorre las pantallas de verdad y avisa
 * si alguna acción no está aquí: así esta lista no puede quedarse atrás
 * otra vez sin que salte.
 */
export const ACCIONES = [
  ['¿Hay una versión nueva?', '/admin',
    'versión nueva actualización publicada github descargar última novedades hurakan tick',
    'admin', 'version_nueva'],
  ['Forzar la actualización de todos', '/admin',
    'actualizar versión nueva forzar refrescar aplicación obligar recarga poner al día',
    'admin', 'forzar_actualizacion'],
  /* Esto apuntaba a /admin/mantenimiento, que no existe: el enrutador
     tiene un comodín /admin/<algo> y, si la sección no está, pinta el
     menú de Administración sin decir nada. De ahí que pulsar un
     resultado te dejara en «Administrar el sistema» a buscar otra vez.
     Ahora va al ajuste, que ya se pinta en Configuración. */
  ['Cerrar el acceso (modo mantenimiento)', '/admin/ajustes',
    'cerrar aplicación parar mantenimiento bloquear acceso aviso modo', 'admin',
    'modo_mantenimiento'],
  /* La repetición de un mantenimiento se configura dentro de cada
     revisión, no en una pantalla propia: sin esto, quien busque
     «cuántas veces» o «fecha final» no encuentra nada. */
  ['Cuántas veces se repite un mantenimiento', '/mantenimiento',
    'repeticiones veces tope límite fecha final hasta cuándo termina recurrencia '
    + 'dejar de repetir caducar mantenimiento revisión', 'responsable'],
  ['Comprobar la copia a Teams', '/admin',
    'teams microsoft copia avisos chat comprobar probar azure bot no llega '
    + 'notificaciones empresa', 'admin', 'teams_activo'],
  ['Mandar copia de los avisos a Teams', '/admin/ajustes',
    'teams microsoft copia avisos chat integracion empresa activar', 'admin',
    'teams_activo'],
  ['Errores', '/errores',
    'errores fallos registro log problemas incidencias técnicas qué ha fallado', 'admin'],
  ['Registro de seguridad', '/admin/seguridad',
    'accesos intentos entradas quién entró seguridad auditoría', 'admin'],
  ['Papelera', '/papelera',
    'borrados eliminados recuperar restaurar papelera basura', 'admin'],
  ['Guía de la API', '/guia-api',
    'api rutas integración programadores desarrollo conectar', 'admin'],

  ['Sincronizar ahora', '/cuenta',
    'sincronizar enviar subir pendientes cola sin cobertura actualizar datos', 'todos'],
  ['Volver a descargar todo', '/cuenta',
    'descargar recargar vaciar caché limpiar datos locales empezar de cero', 'todos'],
  ['Cambios por enviar', '/cuenta',
    'pendientes cola sin enviar offline sin cobertura atascados', 'todos'],
  ['Partes guardados en el móvil', '/cuenta',
    'memoria espacio guardados local offline cuántos partes', 'todos'],
  ['Sonido, vibración y avisos', '/avisos-ajustes',
    'sonido vibración avisos notificaciones silencio campana', 'todos'],
  ['Mensajes', '/mensajes',
    'chat mensajes conversaciones hablar recientes sin leer teams bandeja', 'todos'],
  ['Cerrar sesión', '/cuenta',
    'salir cerrar sesión desconectar logout cambiar de usuario', 'todos'],
  ['Mis datos', '/cuenta',
    'mi cuenta mis datos nombre correo teléfono cambiar', 'todos', 'perfil'],
  ['Mis sesiones', '/cuenta',
    'sesiones aparatos dispositivos móviles conectados cerrar otros', 'todos', 'sesiones'],
  ['Aparatos con la sesión abierta', '/cuenta',
    'aparatos dispositivos sesiones abiertas conectados cerrar remoto', 'todos', 'sesiones'],
  ['Cambiar la contraseña', '/cuenta',
    'contraseña clave cambiar seguridad acceso', 'todos', 'password'],
  ['Ver el equipo', '/equipo',
    'equipo compañeros personas quién es quién teléfonos contactar', 'todos'],
  ['Administrar el sistema', '/admin',
    'administración administrar sistema configuración avanzada', 'admin'],

  ['Probar sonido y notificaciones', '/avisos-ajustes',
    'probar sonido notificación aviso prueba comprobar funciona', 'todos'],
  ['Recibir avisos con la aplicación cerrada', '/avisos-ajustes',
    'avisos push cerrada fondo notificaciones móvil llegar', 'todos'],
  ['Avisos del sistema', '/avisos-ajustes',
    'avisos sistema notificaciones qué me avisa', 'todos'],
  ['Horas de silencio', '/avisos-ajustes',
    'silencio desde hasta horario noche no molestar descanso turno', 'todos'],
  ['Salvo los urgentes', '/avisos-ajustes',
    'urgentes salvo excepto silencio prioridad alta', 'todos'],

  ['Barra de abajo', '/apariencia',
    'barra abajo pestañas botones navegación inferior atajos', 'todos'],
  ['Frases del día', '/apariencia',
    'frases día mensajes saludo portada inicio', 'todos'],
  ['Redimensionado de fotos', '/cuenta',
    'fotos tamaño redimensionar calidad peso imágenes subir', 'todos'],
  ['Comprobar conexión', '/cuenta',
    'conexión comprobar servidor red diagnóstico probar', 'todos'],
  ['Hora del servidor', '/cuenta',
    'hora servidor reloj desfase fecha sistema', 'todos'],
  ['Dirección del servidor', '/cuenta',
    'dirección servidor url api dónde conecta', 'todos'],
  ['Base de datos', '/cuenta',
    'base datos motor mysql sqlite versión información sistema', 'todos'],

  ['Lleva número de serie', '/admin/elementos',
    'número serie identificador equipo aparato inventario', 'admin'],
  ['Para todo el equipo', '/admin/atajos',
    'atajos accesos rápidos equipo compartido todos', 'admin'],
];

/* Los ajustes de Configuración se piden una vez y se guardan aquí: son
   los mismos para las tres pantallas. */
let deConfiguracion = null;

export async function ajustesDeConfiguracion() {
  if (deConfiguracion) return deConfiguracion;
  try {
    const d = await api.ajustes();
    deConfiguracion = Object.entries(d.ajustes || {}).flatMap(([cat, lista]) =>
      lista.map((a) => ({ ...a, nombreCat: d.categorias?.[cat] || cat })));
  } catch { deConfiguracion = []; }
  return deConfiguracion;
}

export function olvidarAjustes() { deConfiguracion = null; }

/**
 * ¿Coincide el texto con lo que se busca?
 *
 * Por palabras sueltas y en cualquier orden: «departamento partes»
 * encuentra «A qué grupo van los partes por defecto», y buscar en
 * singular encuentra el plural porque se mira si está contenido.
 */
function coincide(texto, consulta) {
  const t = textoPlano(texto);
  return textoPlano(consulta).split(/\s+/).filter(Boolean).every((p) => t.includes(p));
}

/**
 * Todo lo que encaja con lo que se busca, venga de donde venga.
 *
 * @param {string} consulta   lo escrito
 * @param {boolean} esAdmin   para no ofrecer pantallas que no podrá abrir
 * @param {string[]} omitir   claves de ajuste ya visibles en la pantalla
 *                            actual, para no enseñarlas dos veces
 */
export async function buscarEnAjustes(consulta, { esAdmin = false, omitir = [] } = {}) {
  const q = (consulta || '').trim();
  if (q.length < 2) return [];
  const fuera = new Set(omitir);

  /* En tres montones, y se devuelven en este orden:
   *
   *   1. el ajuste concreto     → al pulsar se abre y se señala
   *   2. la acción concreta      → al pulsar se va derecho a ella
   *   3. la pantalla que lo contiene
   *
   * El orden es el arreglo, no un detalle. Antes salían primero las
   * pantallas, así que buscar «copias» ofrecía arriba «Copias de
   * seguridad [pantalla]» y el ajuste quedaba debajo: se pulsaba el
   * primero, se acababa en un panel y había que buscar otra vez dentro.
   * Lo que se busca es la cosa, no el cajón donde está. */
  const deAjustes = [];
  const deAcciones = [];
  const dePantallas = [];
  const filas = dePantallas;

  if (esAdmin) {
    /* Las pantallas de un módulo opcional sólo se ofrecen si el módulo
       está encendido: mandar a alguien a una pantalla que no existe es
       peor que no encontrarla. */
    const modulos = app.catalogo?.modulos_menu || [];
    const rutasDeModulos = modulos.flatMap((m) => (m.entradas || []).map((x) => x.ruta));

    PANTALLAS.forEach(([titulo, ruta, claves]) => {
      const esDeModulo = ruta.startsWith('/sap');
      if (esDeModulo && !rutasDeModulos.includes(ruta) && ruta !== '/sap/conexion') return;
      if (esDeModulo && !modulos.length) return;
      if (coincide(`${titulo} ${claves}`, q)) {
        filas.push({ titulo, donde: 'Pantallas', ruta });
      }
    });
  }

  /* Las acciones de dentro de las pantallas. Las de administrador sólo
     se ofrecen a quien lo sea: encontrar algo que luego no se puede
     abrir es peor que no encontrarlo. */
  const esResponsable = esAdmin || !!app.usuario?.es_responsable;

  ACCIONES.forEach(([titulo, ruta, claves, quien, ancla]) => {
    if (quien === 'admin' && !esAdmin) return;
    if (quien === 'responsable' && !esResponsable) return;
    if (coincide(`${titulo} ${claves}`, q)) {
      /* El «ancla» lleva derecho a la fila concreta dentro de la pantalla.
         Sin ella, buscar «forzar la actualización» te dejaba en
         Administración y había que volver a buscarlo a ojo, que es
         justo lo que el buscador tenía que ahorrarte. */
      (ancla ? deAcciones : dePantallas).push({ titulo, donde: 'Acciones', ruta, ancla });
    }
  });

  DE_APARIENCIA.forEach(([titulo, claves]) => {
    if (coincide(`${titulo} ${claves}`, q)) {
      dePantallas.push({ titulo, donde: 'Apariencia', ruta: '/apariencia' });
    }
  });

  if (esAdmin) {
    (await ajustesDeConfiguracion()).forEach((a) => {
      if (fuera.has(a.clave)) return;
      if (coincide(`${a.titulo} ${a.descripcion || ''} ${a.clave}`, q)) {
        deAjustes.push({
          titulo: a.titulo,
          donde: `Configuración › ${a.nombreCat}`,
          ruta: '/admin/ajustes',
          clave: a.clave,
        });
      }
    });
  }

  /* Sin repetir: ni el mismo destino, ni el mismo título llegado por dos
     caminos. Ver «Copias de seguridad» dos veces hace dudar de si son
     cosas distintas. */
  const vistos = new Set();
  return [...deAjustes, ...deAcciones, ...dePantallas].filter((f) => {
    const porTitulo = textoPlano(f.titulo);
    /* Si ya salió la cosa concreta, la pantalla que la contiene no se
       repite: «Copias de seguridad» dos veces hace dudar de si son dos
       cosas distintas, y la segunda lleva peor sitio. */
    if (vistos.has('t|' + porTitulo)) return false;
    vistos.add('t|' + porTitulo);
    const llave = f.ruta + '|' + (f.clave || f.ancla || f.titulo);
    if (vistos.has(llave)) return false;
    vistos.add(llave);
    return true;
  });
}


/**
 * Lo mismo, ya convertido en filas listas para pintar.
 *
 * El buscador de filas espera HTML, no una lista: devolverle objetos
 * dejaba la zona de resultados vacía sin decir nada.
 */
export async function htmlDeBusqueda(consulta, opciones = {}) {
  /* Lo que la propia pantalla ya está enseñando no se repite abajo.
     Ver «Sincronizar ahora» dos veces, una en la lista y otra en los
     resultados, hace dudar de si son dos cosas distintas. */
  /* Las filas de la propia pantalla, NO las que este buscador acaba de
     pintar.
     Sin ese «:not», los resultados vivían dentro de #contenido y en la
     tecla siguiente se contaban como «ya visibles»: el buscador borraba
     lo que él mismo había puesto. De ahí que «forza» encontrara cosas y
     «forzar» ninguna —o al revés, según la tecla—: no era la palabra,
     era que el segundo filtrado se comía el primero. */
  const yaVisibles = new Set(
    [...document.querySelectorAll('#contenido .fila:not(.oculto)')]
      .filter((f) => !f.closest('.buscador-filas'))
      .map((f) => textoPlano((f.innerText || '').split('\n')[0].trim()))
      .filter(Boolean));

  const filas = await buscarEnAjustes(consulta, opciones);

  const sinRepetir = filas.filter((f) => !yaVisibles.has(textoPlano(f.titulo)));
  if (!sinRepetir.length) return '';

  /* Una sola lista, sin encabezado.
     Antes ponía «En otras pantallas», que no dice nada útil y hacía
     pensar que lo de abajo era otra cosa. Son resultados de la
     búsqueda: van seguidos de los de la propia pantalla. */
  return `
    <div class="filas">
      ${sinRepetir.map((f) => `
        <button type="button" class="fila" data-ir-busqueda="${esc(f.ruta)}"
                ${(f.clave || f.ancla) ? `data-ir-ajuste="${esc(f.clave || f.ancla)}"` : ''}>
          ${icono(f.clave || f.ancla ? 'ajustes' : 'flecha')}
          <span class="fila__texto">${esc(f.titulo)}
            <small>${esc(f.donde)}</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>`).join('')}
    </div>`;
}

/**
 * Engancha los clics de esos resultados: lleva a la pantalla y, si es un
 * ajuste concreto, deja apuntado cuál para que allí se salte a él.
 */
export function engancharBusqueda(contenedor, ir) {
  contenedor.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ir-busqueda]');
    if (!b) return;

    /* Y aquí se acaba el viaje de este clic.
     *
     * La propia pantalla tiene su escuchador de filas, y también lo
     * recogía: buscabas «forzar la actualización», este enviaba a
     * Administración y el de la pantalla, justo después, a Configuración.
     * Acababas en otro sitio y había que volver a buscar. */
    e.preventDefault();
    // Los dos escuchadores están en el MISMO elemento, así que cortar la
    // propagación no basta: hay que parar también a los de al lado.
    e.stopImmediatePropagation();
    if (b.dataset.irAjuste) {
      try { sessionStorage.setItem('hurakantick_ir_ajuste', b.dataset.irAjuste); } catch { /* da igual */ }
    }
    ir(b.dataset.irBusqueda);
  });
}
