/* =====================================================================
   Apariencia.

   Cada persona ve la aplicación en un sitio distinto: un pasillo a media
   luz, una cocina con vapor, un despacho. Y con vista distinta. Por eso
   esto no es cosa del administrador — lo ajusta cada uno en su móvil y
   se queda ahí.

   Lo que se puede cambiar:
     · qué bloques salen en el inicio, en qué orden y qué sitio ocupan
     · el tamaño de la letra
     · cuánto aire hay entre las cosas
     · cuánto detalle lleva cada tarjeta del listado
   ===================================================================== */

const CLAVE = 'hurakantick_apariencia';

/** Los bloques del inicio que se pueden mover, ocultar o agrandar. */
export const BLOQUES = {
  chat:      { nombre: 'Mensajes sin leer',      detalle: 'Lo que alguien te ha contestado en un parte' },
  programados: { nombre: 'Programado',           detalle: 'Partes que vuelven a la cola más adelante' },
  cifras:    { nombre: 'Cifras',                 detalle: 'Asignados, sin asignar, fuera de plazo…' },
  areas:     { nombre: 'Paneles de área',        detalle: 'Accesos directos a climatización y demás' },
  atajos:    { nombre: 'Accesos rápidos',        detalle: 'Filtros con nombre' },
  avisar:    { nombre: 'Botón de avisar',        detalle: 'Sólo en el perfil de recepción' },
  lugares:   { nombre: 'Habitaciones pendientes', detalle: 'Sólo en el perfil de limpieza' },
  urgente:   { nombre: 'Lo más urgente',         detalle: 'Los partes que más corren' },
  reparto:   { nombre: 'Reparto por estado',     detalle: 'Cuántos hay en cada estado' },
  actividad: { nombre: 'Actividad',              detalle: 'Las dos últimas semanas' },
  carga:     { nombre: 'Carga del equipo',       detalle: 'Cuánto lleva cada persona' },
};

/** Los bloques de la ficha de un parte. */
export const BLOQUES_TICKET = {
  aparcado:  { nombre: 'Aviso de programado', detalle: 'Cuando el parte está aparcado para más adelante', fijo: true },
  cabecera:  { nombre: 'Cabecera',        detalle: 'Estado, prioridad, título y plazo', fijo: true },
  guias:     { nombre: 'Cómo se hace',    detalle: 'Instrucciones del área' },
  datos:     { nombre: 'Datos del parte', detalle: 'Ubicación, tipo, asignado, fechas' },
  campos:    { nombre: 'Campos propios',  detalle: 'Los del aire acondicionado y similares' },
  notas:     { nombre: 'Notas',           detalle: 'Lo que ha ido escribiendo el equipo' },
  acciones:  { nombre: 'Botones',         detalle: 'Cambiar estado, asignar, notas', fijo: true },
  fotos:     { nombre: 'Fotografías',     detalle: 'Imágenes del parte' },
  historial: { nombre: 'Seguimiento',     detalle: 'Todo lo que ha pasado' },
  repetidos: { nombre: 'Ya ha pasado aquí', detalle: 'Otros partes del mismo tipo en el mismo sitio' },
  chat:      { nombre: 'Conversación',      detalle: 'Hablar con quien abrió el parte' },
  observadores: { nombre: 'También lo siguen', detalle: 'Quién tiene acceso sin trabajarlo' },
};

/** Los bloques de la pantalla de Ajustes. */
export const BLOQUES_AJUSTES = {
  sync:       { nombre: 'Sincronización',  detalle: 'Cola de envío, última bajada, errores' },
  avisos:     { nombre: 'Avisos',          detalle: 'Sonido, vibración y horarios' },
  apariencia: { nombre: 'Apariencia',      detalle: 'Letra, espacio, bloques y barra', fijo: true },
  cuenta:     { nombre: 'Cuenta',          detalle: 'Nombre, correo y contraseña', fijo: true },
  consultar:  { nombre: 'Consultar',       detalle: 'Informes, series, instrucciones' },
  admin:      { nombre: 'Administración',  detalle: 'Sólo para responsables' },
  equipo:     { nombre: 'Equipo',          detalle: 'Quién hay y qué lleva cada uno' },
  servidor:   { nombre: 'Servidor',        detalle: 'Dirección y diagnóstico' },
};

const POR_DEFECTO = {
  // Orden y tamaño de cada bloque del inicio.
  //   ancho: 1 = media pantalla · 2 = pantalla entera
  bloques: [
    { id: 'chat', visible: true },
    { id: 'programados', visible: true },
    { id: 'observadores', visible: true },
    { id: 'cifras',    visible: true, ancho: 2 },
    { id: 'areas',     visible: true, ancho: 2 },
    { id: 'atajos',    visible: true, ancho: 2 },
    { id: 'avisar',    visible: true, ancho: 2 },
    { id: 'lugares',   visible: true, ancho: 2 },
    { id: 'urgente',   visible: true, ancho: 2 },
    { id: 'reparto',   visible: true, ancho: 2 },
    { id: 'actividad', visible: true, ancho: 2 },
    { id: 'carga',     visible: true, ancho: 2 },
  ],
  // Orden y visibilidad en la ficha de un parte. Los marcados como fijos
  // no se pueden ocultar: sin la cabecera no se sabe qué parte es, y sin
  // los botones no se puede hacer nada con él.
  bloquesTicket: [
    { id: 'aparcado', visible: true },
    { id: 'cabecera',  visible: true },
    { id: 'guias',     visible: true },
    { id: 'datos',     visible: true },
    { id: 'campos',    visible: true },
    { id: 'chat', visible: true },
    { id: 'observadores', visible: true },
    { id: 'notas', visible: true },
    { id: 'acciones',  visible: true },
    { id: 'fotos',     visible: true },
    { id: 'historial', visible: true },
    { id: 'repetidos', visible: true },
  ],
  // Orden y visibilidad en la pantalla de Ajustes.
  /* Administración primero: quien la necesita entra en Ajustes para eso,
     y tenerla en mitad de la lista obliga a buscarla cada vez. Los que
     sólo miran su cuenta no pierden nada, porque a ellos ni les sale. */
  bloquesAjustes: [
    { id: 'admin',      visible: true },
    { id: 'consultar',  visible: true },
    { id: 'equipo',     visible: true },
    { id: 'cuenta',     visible: true },
    { id: 'avisos',     visible: true },
    { id: 'apariencia', visible: true },
    { id: 'sync',       visible: true },
    { id: 'servidor',   visible: true },
  ],
  letra: 'normal',        // pequena | normal | grande | enorme
  aire: 'normal',         // compacto | normal | amplio
  tarjeta: 'normal',      // minima | normal | completa
  cifrasPorFila: 2,       // 1, 2 o 3
  // Vista smartphone. null = lo que diga el hotel; sólo cuenta si el
  // superadministrador deja que cada uno decida.
  vistaMovil: null,       // null | 'si' | 'no'
  vistaMovilRatio: null,  // null | '9:16' | '9:19.5' | '9:20'
};

let cache = null;

export function apariencia() {
  if (cache) return cache;
  try {
    const g = JSON.parse(localStorage.getItem(CLAVE) || '{}');
    cache = {
      ...POR_DEFECTO,
      ...g,
      // Los bloques se fusionan por id: así, al añadir uno nuevo en una
      // versión posterior, aparece en vez de perderse porque la
      // preferencia guardada no lo conocía.
      bloques: fusionarBloques(g.bloques),
      bloquesTicket: fusionarTicket(g.bloquesTicket),
      bloquesAjustes: fusionarGenerico(g.bloquesAjustes, BLOQUES_AJUSTES, POR_DEFECTO.bloquesAjustes),
    };
  } catch {
    cache = { ...POR_DEFECTO };
  }
  return cache;
}

function fusionarBloques(guardados) {
  const previos = Array.isArray(guardados) ? guardados : [];
  const vistos = new Set();
  const salida = [];

  for (const b of previos) {
    if (!BLOQUES[b?.id] || vistos.has(b.id)) continue;
    vistos.add(b.id);
    salida.push({ id: b.id, visible: b.visible !== false, ancho: b.ancho === 1 ? 1 : 2 });
  }
  for (const d of POR_DEFECTO.bloques) {
    if (!vistos.has(d.id)) salida.push({ ...d });
  }
  return salida;
}

function fusionarTicket(guardados) {
  const previos = Array.isArray(guardados) ? guardados : [];
  const vistos = new Set();
  const salida = [];

  for (const b of previos) {
    if (!BLOQUES_TICKET[b?.id] || vistos.has(b.id)) continue;
    vistos.add(b.id);
    // Un bloque fijo se queda visible pase lo que pase en lo guardado.
    salida.push({
      id: b.id,
      visible: BLOQUES_TICKET[b.id].fijo ? true : b.visible !== false,
    });
  }
  for (const d of POR_DEFECTO.bloquesTicket) {
    if (!vistos.has(d.id)) salida.push({ ...d });
  }
  return salida;
}

/** Fusiona por id, conservando lo guardado y añadiendo lo nuevo. */
function fusionarGenerico(guardados, catalogo, defecto) {
  const previos = Array.isArray(guardados) ? guardados : [];
  const vistos = new Set();
  const salida = [];
  for (const b of previos) {
    if (!catalogo[b?.id] || vistos.has(b.id)) continue;
    vistos.add(b.id);
    salida.push({ id: b.id, visible: catalogo[b.id].fijo ? true : b.visible !== false });
  }
  for (const d of defecto) {
    if (!vistos.has(d.id)) salida.push({ ...d });
  }
  return salida;
}

export function fijarApariencia(cambios) {
  const nueva = { ...apariencia(), ...cambios };
  cache = nueva;
  try { localStorage.setItem(CLAVE, JSON.stringify(nueva)); } catch { /* sin sitio */ }
  aplicar();
  return nueva;
}

export function restaurarApariencia() {
  cache = {
    ...POR_DEFECTO,
    bloques: POR_DEFECTO.bloques.map((b) => ({ ...b })),
    bloquesTicket: POR_DEFECTO.bloquesTicket.map((b) => ({ ...b })),
    bloquesAjustes: POR_DEFECTO.bloquesAjustes.map((b) => ({ ...b })),
  };
  try { localStorage.removeItem(CLAVE); } catch { /* nada */ }
  aplicar();
  return cache;
}

/** ¿Se ve este bloque? Lo consultan las vistas antes de pintarlo. */
export function bloqueVisible(id) {
  const b = apariencia().bloques.find((x) => x.id === id);
  return b ? b.visible : true;
}

/** Ancho de un bloque: 1 media pantalla, 2 entera. */
export function bloqueAncho(id) {
  const b = apariencia().bloques.find((x) => x.id === id);
  return b ? b.ancho : 2;
}

/** Los bloques visibles, en el orden elegido. */
export function bloquesOrdenados() {
  return apariencia().bloques.filter((b) => b.visible);
}

/** Lo mismo para la ficha de un parte. */
export function bloquesTicketOrdenados() {
  return apariencia().bloquesTicket.filter((b) => b.visible);
}

/** Y para la pantalla de Ajustes. */
export function bloquesAjustesOrdenados() {
  return apariencia().bloquesAjustes.filter((b) => b.visible);
}

/**
 * Lleva las preferencias al documento.
 *
 * Se hace con atributos y variables CSS en vez de tocar cada pantalla:
 * así vale para toda la aplicación, incluidas las que se escriban
 * después, y no hay que acordarse de nada al añadir una vista.
 */
export function aplicar() {
  const a = apariencia();
  const raiz = document.documentElement;

  raiz.dataset.letra = a.letra;
  raiz.dataset.aire = a.aire;
  raiz.dataset.tarjeta = a.tarjeta;
  raiz.style.setProperty('--cifras-fila', String(a.cifrasPorFila));
}
