/* =====================================================================
   Almacén local (IndexedDB).

   Guarda tres cosas:
     · una copia de los partes y del catálogo, para poder consultarlos
       y buscarlos sin cobertura;
     · la cola de salida con las operaciones pendientes de enviar;
     · las fotos hechas sin conexión, en binario, hasta que se suban.

   Todo lo que la aplicación pinta sale de aquí. La red sólo alimenta
   este almacén: así la pantalla nunca se queda esperando.
   ===================================================================== */

const NOMBRE = 'hurakantick';
const VERSION = 2;

let bd = null;

export function abrir() {
  if (bd) return Promise.resolve(bd);
  return new Promise((resolve, reject) => {
    const pet = indexedDB.open(NOMBRE, VERSION);

    pet.onupgradeneeded = (e) => {
      const d = e.target.result;

      if (!d.objectStoreNames.contains('tickets')) {
        const s = d.createObjectStore('tickets', { keyPath: 'id_ticket' });
        s.createIndex('uuid', 'uuid', { unique: false });
        s.createIndex('actualizado', 'updated_at');
        s.createIndex('estado', 'id_estado');
        s.createIndex('asignado', 'id_persona_asignada');
      }
      if (!d.objectStoreNames.contains('historial')) {
        const s = d.createObjectStore('historial', { keyPath: 'uuid' });
        s.createIndex('ticket', 'id_ticket');
      }
      if (!d.objectStoreNames.contains('adjuntos')) {
        const s = d.createObjectStore('adjuntos', { keyPath: 'uuid' });
        s.createIndex('ticket', 'id_ticket');
      }
      if (!d.objectStoreNames.contains('cola')) {
        const s = d.createObjectStore('cola', { keyPath: 'id_local', autoIncrement: true });
        s.createIndex('estado', 'estado');
      }
      if (!d.objectStoreNames.contains('fotos')) {
        d.createObjectStore('fotos', { keyPath: 'uuid' });
      }
      if (!d.objectStoreNames.contains('ajustes')) {
        d.createObjectStore('ajustes', { keyPath: 'clave' });
      }
      if (!d.objectStoreNames.contains('conflictos')) {
        const s = d.createObjectStore('conflictos', { keyPath: 'id_local' });
        s.createIndex('ticket', 'id_ticket');
      }
    };

    pet.onsuccess = () => { bd = pet.result; resolve(bd); };
    pet.onerror = () => reject(pet.error);
  });
}

function tx(almacenes, modo = 'readonly') {
  return bd.transaction(almacenes, modo);
}

function pedir(peticion) {
  return new Promise((resolve, reject) => {
    peticion.onsuccess = () => resolve(peticion.result);
    peticion.onerror = () => reject(peticion.error);
  });
}

/* ---------------------------------------------------------------- */
/* Operaciones genéricas                                            */
/* ---------------------------------------------------------------- */
export async function poner(almacen, valor) {
  await abrir();
  return pedir(tx([almacen], 'readwrite').objectStore(almacen).put(valor));
}

export async function ponerVarios(almacen, valores) {
  if (!valores || !valores.length) return;
  await abrir();
  const t = tx([almacen], 'readwrite');
  const s = t.objectStore(almacen);
  valores.forEach((v) => s.put(v));
  return new Promise((res, rej) => { t.oncomplete = res; t.onerror = () => rej(t.error); });
}

export async function leer(almacen, clave) {
  await abrir();
  return pedir(tx([almacen]).objectStore(almacen).get(clave));
}

export async function todos(almacen) {
  await abrir();
  return pedir(tx([almacen]).objectStore(almacen).getAll());
}

export async function porIndice(almacen, indice, valor) {
  await abrir();
  return pedir(tx([almacen]).objectStore(almacen).index(indice).getAll(valor));
}

export async function borrar(almacen, clave) {
  await abrir();
  return pedir(tx([almacen], 'readwrite').objectStore(almacen).delete(clave));
}

export async function vaciar(almacen) {
  await abrir();
  return pedir(tx([almacen], 'readwrite').objectStore(almacen).clear());
}

/* ---------------------------------------------------------------- */
/* Ajustes (marca de sincronización, catálogo, sesión…)             */
/* ---------------------------------------------------------------- */
export async function ajuste(clave, valor) {
  if (valor === undefined) {
    const f = await leer('ajustes', clave);
    return f ? f.valor : null;
  }
  return poner('ajustes', { clave, valor });
}

/* ---------------------------------------------------------------- */
/* Cola de salida                                                   */
/* ---------------------------------------------------------------- */
export async function encolar(tipo, datos) {
  const id = await poner('cola', {
    tipo,
    datos,
    estado: 'pendiente',        // pendiente | error
    intentos: 0,
    error: null,
    creado: new Date().toISOString(),
  });
  return id;
}

export async function colaPendiente() {
  const t = await todos('cola');
  return t.filter((o) => o.estado === 'pendiente')
          .sort((a, b) => a.id_local - b.id_local);
}

export async function colaConError() {
  const t = await todos('cola');
  return t.filter((o) => o.estado === 'error');
}

export async function marcarError(idLocal, texto) {
  const o = await leer('cola', idLocal);
  if (!o) return;
  o.estado = 'error';
  o.error = texto;
  o.intentos = (o.intentos || 0) + 1;
  return poner('cola', o);
}

export async function reintentar(idLocal) {
  const o = await leer('cola', idLocal);
  if (!o) return;
  o.estado = 'pendiente';
  o.error = null;
  return poner('cola', o);
}

/* ---------------------------------------------------------------- */
/* Conflictos de sincronización                                     */
/*                                                                  */
/* Cuando el servidor rechaza un cambio porque otra persona movió el */
/* parte, la operación no se pierde: se guarda aquí hasta que quien  */
/* la hizo decide qué versión vale.                                  */
/* ---------------------------------------------------------------- */
export async function guardarConflicto(op, detalle, ticketServidor) {
  return poner('conflictos', {
    id_local: op.id_local,
    id_ticket: detalle.id_ticket || op.datos?.id_ticket || null,
    tipo: op.tipo,
    operacion: op,
    detalle,
    ticket_servidor: ticketServidor || null,
    creado: new Date().toISOString(),
  });
}

export async function conflictos() {
  return todos('conflictos');
}

export async function resolverConflicto(idLocal) {
  await borrar('conflictos', idLocal);
  return borrar('cola', idLocal);
}

/* ---------------------------------------------------------------- */
/* Fotografías pendientes de subir                                  */
/* ---------------------------------------------------------------- */
export async function guardarFoto(uuid, datos, nombre, refTicket) {
  return poner('fotos', { uuid, datos, nombre, ref: refTicket, creado: new Date().toISOString() });
}

export async function fotosDe(refTicket) {
  const t = await todos('fotos');
  return t.filter((f) => f.ref === refTicket);
}

/* ---------------------------------------------------------------- */
/* Consultas locales de partes                                      */
/* ---------------------------------------------------------------- */
export async function ticketsLocales(filtro = {}) {
  const lista = (await todos('tickets')).filter((t) => !t.borrado);
  return filtrarOrdenar(lista, filtro);
}

/**
 * El mismo filtrado que hace el servidor, replicado aquí para que la
 * lista responda igual sin cobertura.
 */
export function filtrarOrdenar(lista, f = {}) {
  let r = lista.slice();

  if (f.abiertos)    r = r.filter((t) => !t.es_final);

  /* Lo aparcado para más adelante fuera: no es trabajo de hoy. Se usa en
     el inicio, donde mezclarlo con lo urgente despista más que ayuda. */
  if (f.sin_programar) {
    const ahora = new Date();
    r = r.filter((t) => !t.visible_desde
      || new Date(String(t.visible_desde).replace(' ', 'T') + 'Z') <= ahora);
  }
  if (f.mios)        r = r.filter((t) => t.id_persona_asignada === f.yo);
  if (f.creados_por_mi) r = r.filter((t) => t.id_realiza_accion === f.yo);
  if (f.sin_asignar) r = r.filter((t) => !t.id_persona_asignada && !t.id_grupo_asignado);
  if (f.mi_grupo && f.grupo) r = r.filter((t) => t.id_grupo_asignado === f.grupo);
  if (f.cerrados_semana) {
    const hace7 = new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 19).replace('T', ' ');
    r = r.filter((t) => t.fecha_cierre && t.fecha_cierre >= hace7);
  }
  if (f.vencidos)    r = r.filter((t) => t.vencido);
  if (f.estado?.length)    r = r.filter((t) => f.estado.includes(t.id_estado));
  if (f.prioridad?.length) r = r.filter((t) => f.prioridad.includes(t.id_prioridad));
  if (f.elemento)    r = r.filter((t) => t.id_elemento === f.elemento);
  if (f.familia)     r = r.filter((t) => t.id_familia === f.familia);
  if (f.clase?.length) r = r.filter((t) => f.clase.includes(t.clase));

  if (f.q) {
    /* Buscar sin cobertura tiene que dar lo mismo que con ella.
     *
     * El servidor ignora los acentos —nadie los escribe en el buscador
     * de un móvil— y aquí también hay que hacerlo. Si no, la misma
     * búsqueda da resultados distintos según haya red o no, y eso hace
     * dudar de si el parte existe o no.
     *
     * «normalize('NFD')» separa la letra de su tilde y el reemplazo se
     * queda con la letra: «avería» pasa a ser «averia». */
    const plano = (x) => String(x || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');

    const q = plano(f.q).trim();

    r = r.filter((t) =>
      plano(t.titulo_ticket).includes(q) ||
      plano(t.detalle_ticket).includes(q) ||
      plano(t.zona_ruta).includes(q) ||
      plano(t.codigo).includes(q) ||
      String(t.id_ticket).includes(q));
  }

  const cmp = {
    reciente:  (a, b) => (b.fecha_creado || '').localeCompare(a.fecha_creado || ''),
    antiguo:   (a, b) => (a.fecha_creado || '').localeCompare(b.fecha_creado || ''),
    prioridad: (a, b) => (b.id_prioridad - a.id_prioridad) || (b.fecha_creado || '').localeCompare(a.fecha_creado || ''),
    limite:    (a, b) => (a.fecha_limite || '9999').localeCompare(b.fecha_limite || '9999'),
    estado:    (a, b) => (a.id_estado - b.id_estado) || (b.fecha_creado || '').localeCompare(a.fecha_creado || ''),
  }[f.orden || 'reciente'];

  return r.sort(cmp);
}

/** Recuento por estado con los datos locales (para el panel sin conexión). */
export async function resumenLocal(yo) {
  const t = (await todos('tickets')).filter((x) => !x.borrado);
  const abiertos = t.filter((x) => !x.es_final);
  return {
    abiertos: abiertos.length,
    mios: abiertos.filter((x) => x.id_persona_asignada === yo).length,
    sin_asignar: abiertos.filter((x) => !x.id_persona_asignada).length,
    vencidos: abiertos.filter((x) => x.vencido).length,
    urgentes: abiertos.filter((x) => x.id_prioridad >= 3).length,
    hoy: t.filter((x) => (x.fecha_creado || '').slice(0, 10) === new Date().toISOString().slice(0, 10)).length,
    cerrados_hoy: 0,
  };
}

/** Borra la copia local (al cerrar sesión). Conserva la cola y los conflictos. */
export async function limpiarCache() {
  await Promise.all([vaciar('tickets'), vaciar('historial'), vaciar('adjuntos')]);
  await ajuste('sync_desde', null);
}

/**
 * Borra todo lo guardado en el aparato.
 *
 * Se usa al cambiar de hotel: si no, quedarían mezclados los partes de
 * los dos en el mismo móvil, y sin cobertura se verían los del anterior
 * — que es justo lo que no puede pasar.
 *
 * La cola de salida se respeta: quien la tenga con cosas dentro no debe
 * llegar hasta aquí, y si llega, es mejor conservarla que perderla.
 */
export async function vaciarTodo({ conservarCola = true } = {}) {
  const bd = await abrir();
  const almacenes = ['tickets', 'historial', 'adjuntos', 'fotos', 'conflictos'];
  if (!conservarCola) almacenes.push('cola');

  await Promise.all(almacenes.map((nombre) => new Promise((res, rej) => {
    try {
      const tx = bd.transaction(nombre, 'readwrite');
      tx.objectStore(nombre).clear();
      tx.oncomplete = () => res();
      tx.onerror = () => rej(tx.error);
    } catch { res(); }          // un almacén que no exista no es un fallo
  })));

  // El catálogo también: las ubicaciones y la gente son de otro hotel.
  await ajuste('catalogo', null);
  await ajuste('ultima_sync', null);
}
