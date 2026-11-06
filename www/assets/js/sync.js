/* =====================================================================
   Sincronización.

   Regla de oro: la interfaz nunca espera a la red. Cada acción se
   guarda primero en IndexedDB y se pinta al momento; después se manda.
   Si no hay cobertura, queda en la cola y sale sola cuando vuelva.

   El uuid lo genera el móvil, así que reenviar una operación no crea
   duplicados. Y como el historial sólo añade, dos personas trabajando
   a la vez sobre el mismo parte no se pisan.
   ===================================================================== */

import * as db from './db.js';
import { api, hayRed, alCambiarRed, comprobarRed, uuid, ErrorRed } from './api.js';
import { tono, avisoSistema } from './sonido.js';
import { app } from './app.js';

const oyentes = new Set();
let usuarioActual = null;

/** El id de quien ha entrado, para saber qué partes son «míos». */
export function fijarUsuario(id) { usuarioActual = id; }

export const estado = {
  sincronizando: false,
  pendientes: 0,
  errores: 0,
  mantenimiento: false,   // el servidor está cerrado por mantenimiento
  conflictos: 0,
  ultima: null,
  // Quedan datos en el servidor que no cupieron en la sincronización.
  incompleto: false,
  // El administrador ha pedido vaciar y volver a descargar.
  renovando: false,
  // De una sola vez: lo guardado en el móvil se ha vaciado y descargado de
  // nuevo, y cuántos cambios pendientes se descartaron por el camino.
  renovado: false,
  descartados: 0,
};

export function alCambiar(fn) {
  oyentes.add(fn);
  return () => oyentes.delete(fn);
}

function avisar() {
  oyentes.forEach((f) => f({ ...estado }));
  // Los avisos de una sola vez se gastan al terminar, no a mitad: si se
  // repintara con la descarga a medias, se verían datos incompletos.
  if (!estado.sincronizando) {
    estado.renovado = false;
    estado.descartados = 0;
  }
}

export async function recontar() {
  estado.pendientes = (await db.colaPendiente()).length;
  estado.errores    = (await db.colaConError()).length;
  estado.conflictos = (await db.conflictos()).length;
  avisar();
  return estado.pendientes;
}

/* ================================================================= */
/* Escrituras: siempre local primero                                 */
/* ================================================================= */

/**
 * Alta de un parte. Devuelve el parte provisional que se pinta ya,
 * con un id negativo hasta que el servidor asigne el definitivo.
 */
/**
 * ¿Se permite guardar trabajo a la espera de señal?
 *
 * Lo decide la dirección del hotel. Apagado, la aplicación exige conexión
 * para todo: nada se queda en el móvil. Tiene sentido donde hay wifi en
 * todas partes y se prefiere que nadie trabaje a ciegas.
 */
export function colaPermitida() {
  return app.catalogo?.ajustes?.offline !== false;
}

/**
 * Se llama antes de guardar nada a la espera de señal.
 *
 * Si la dirección ha apagado el modo sin cobertura y además no hay red,
 * no se guarda: se avisa y se para. Guardar algo que nunca se iba a
 * enviar es peor que decir «ahora no se puede».
 */
function exigirColaPermitida() {
  if (colaPermitida() || hayRed()) return;
  throw new ErrorRed(
    'Este hotel tiene apagado el trabajo sin cobertura: hace falta conexión '
    + 'para guardar esto. Búscate un punto con señal y vuelve a intentarlo.');
}

export async function crearTicket(datos, fotos = []) {
  const id = uuid();
  const ahora = new Date().toISOString().slice(0, 19).replace('T', ' ');

  const provisional = {
    id_ticket: -Date.now(),          // negativo = todavía no confirmado
    uuid: id,
    codigo: 'T-·····',
    titulo_ticket: datos.titulo || 'Parte sin título',
    detalle_ticket: datos.detalle || '',
    fecha_creado: ahora,
    updated_at: ahora,
    id_estado: datos.id_estado || 1,
    nombre_estado: 'Nuevo',
    estado_color: '#2563EB',
    id_prioridad: datos.id_prioridad || 2,
    prioridad: datos.prioridad_nombre || 'Normal',
    prioridad_color: datos.prioridad_color || '#0B5563',
    id_elemento: datos.id_elemento || null,
    elemento: datos.elemento_nombre || null,
    zona_ruta: datos.zona_ruta || '',
    id_persona_asignada: datos.id_persona_asignada || null,
    asignado_a: datos.asignado_nombre || null,
    creado_por: datos.creado_por || '',
    n_adjuntos: fotos.length,
    n_movimientos: 1,
    es_final: 0,
    borrado: 0,
    vencido: false,
    pendiente_envio: true,
  };

  await db.poner('tickets', provisional);
exigirColaPermitida();
  await db.encolar('ticket.crear', { ...datos, uuid: id, origen: hayRed() ? 'app' : 'offline' });

  for (const foto of fotos) {
    const fid = uuid();
    await db.guardarFoto(fid, foto.datos, foto.nombre || 'foto.jpg', id);
    await db.encolar('adjunto.subir', { uuid: fid, uuid_ticket: id, nombre: foto.nombre || 'foto.jpg' });
  }

  await recontar();

  // Con cobertura se espera al envío para devolver el parte definitivo:
  // aterrizar en una ficha provisional con código «T-·····» que cambia
  // sola dos segundos después parece un error aunque no lo sea.
  if (hayRed()) {
    try {
      await sincronizarIncluyendo();
      const reales = await db.porIndice('tickets', 'uuid', id);
      const real = reales.find((t) => t.id_ticket > 0);
      if (real) return real;
    } catch { /* si falla, se sigue con el provisional: nada se pierde */ }
  } else {
    enSegundoPlano();
  }

  return provisional;
}

/** Cambio de estado, asignación o nota sobre un parte existente. */
/**
 * Escribir en la conversación de un parte.
 *
 * Con cobertura va directo; sin ella entra en la cola y sale sola al
 * recuperar señal. Un técnico en un sótano es justo quien más necesita
 * poder escribir «esto no era avería», y decirle «espera a tener línea»
 * es la forma más segura de que no lo escriba nunca.
 */
export async function escribirEnChat(ticket, texto, importante = false) {
  const uuid = crypto.randomUUID();
  const carga = {
    uuid,
    id_ticket: ticket.id_ticket,
    uuid_ticket: ticket.uuid || null,
    texto,
    importante: !!importante,
  };

  if (hayRed()) {
    try {
      await api.chatEscribir(ticket.id_ticket, { texto, importante, uuid });
      return { enviado: true };
    } catch (e) {
      if (!e.sinRed) throw e;   // un error de verdad se cuenta; la falta de red, no
    }
  }

exigirColaPermitida();
  await db.encolar('chat.escribir', carga);
  return { enviado: false, uuid };
}

export async function actualizarTicket(ticket, cambios) {
  const idMov = uuid();
  const ahora = new Date().toISOString().slice(0, 19).replace('T', ' ');

  // Reflejo inmediato en la copia local
  const local = (await db.leer('tickets', ticket.id_ticket)) || { ...ticket };
  Object.assign(local, {
    id_estado: cambios.id_estado ?? local.id_estado,
    nombre_estado: cambios.nombre_estado ?? local.nombre_estado,
    estado_color: cambios.estado_color ?? local.estado_color,
    es_final: cambios.es_final ?? local.es_final,
    id_persona_asignada: 'id_persona_asignada' in cambios ? cambios.id_persona_asignada : local.id_persona_asignada,
    asignado_a: 'asignado_a' in cambios ? cambios.asignado_a : local.asignado_a,
    // El departamento es una asignación aparte de la persona: si no se
    // recuerda aquí, el cambio se pierde antes de enviarse y parece que
    // el botón no hace nada.
    id_grupo_asignado: 'id_grupo_asignado' in cambios ? cambios.id_grupo_asignado : local.id_grupo_asignado,
    visible_desde: 'visible_desde' in cambios ? cambios.visible_desde : local.visible_desde,
    departamento: 'departamento' in cambios ? cambios.departamento : local.departamento,
    id_prioridad: cambios.id_prioridad ?? local.id_prioridad,
    fecha_limite: 'fecha_limite' in cambios ? cambios.fecha_limite : local.fecha_limite,
    minutos_trabajo: 'minutos_trabajo' in cambios ? cambios.minutos_trabajo : local.minutos_trabajo,
    coste: 'coste' in cambios ? cambios.coste : local.coste,
    prioridad: cambios.prioridad ?? local.prioridad,
    prioridad_color: cambios.prioridad_color ?? local.prioridad_color,
    titulo_ticket: cambios.titulo ?? local.titulo_ticket,
    detalle_ticket: 'detalle' in cambios ? cambios.detalle : local.detalle_ticket,
    id_elemento: 'id_elemento' in cambios ? cambios.id_elemento : local.id_elemento,
    elemento: 'elemento' in cambios ? cambios.elemento : local.elemento,
    elemento_icono: cambios.elemento_icono ?? local.elemento_icono,
    id_rel_zona: 'id_rel_zona' in cambios ? cambios.id_rel_zona : local.id_rel_zona,
    zona_ruta: 'zona_ruta' in cambios ? cambios.zona_ruta : local.zona_ruta,
    updated_at: ahora,
    n_movimientos: (local.n_movimientos || 0) + 1,
    pendiente_envio: true,
  });
  await db.poner('tickets', local);

  // Y en el historial local, para que se vea al instante
  await db.poner('historial', {
    uuid: idMov,
    id_ticket: ticket.id_ticket,
    tipo: cambios.id_estado ? 'estado'
        : (('id_persona_asignada' in cambios || 'id_grupo_asignado' in cambios) ? 'asignacion'
        : (('titulo' in cambios || 'detalle' in cambios || 'id_rel_zona' in cambios) ? 'edicion' : 'nota')),
    detalle_ticket: cambios.nota || null,
    estado_nuevo: cambios.nombre_estado || null,
    estado_anterior: ticket.nombre_estado || null,
    autor: cambios.autor || 'Tú',
    minutos: cambios.minutos || 0,
    fecha_creado: ahora,
    pendiente_envio: true,
  });

  const carga = {
    uuid_movimiento: idMov,
    nota: cambios.nota || '',
    minutos: cambios.minutos || 0,
    fecha: ahora,
    // Lo que este móvil creía saber del parte. El servidor lo compara
    // con la realidad y avisa si otra persona lo movió mientras tanto.
    version_base: ticket.id_version ?? 1,
    estado_base: ticket.id_estado ?? null,
    asignado_base: ticket.id_persona_asignada ?? null,
  };
  if (cambios.id_estado) carga.id_estado = cambios.id_estado;
  if ('id_persona_asignada' in cambios) carga.id_persona_asignada = cambios.id_persona_asignada;
  if ('id_grupo_asignado' in cambios) carga.id_grupo_asignado = cambios.id_grupo_asignado;
  if ('fecha_limite' in cambios) carga.fecha_limite = cambios.fecha_limite;
  // Programar el parte: hasta esa fecha no sale en las listas.
  if ('visible_desde' in cambios) carga.visible_desde = cambios.visible_desde;

  /* Los datos propios del tipo de avería —temperaturas y demás—. Sin
     esto el panel de anotarlos se veía, se rellenaba y no llegaba nada:
     el envío sólo deja pasar los campos que conoce. */
  if ('campos' in cambios) carga.campos = cambios.campos;
  if ('minutos_trabajo' in cambios) carga.minutos_trabajo = cambios.minutos_trabajo;
  if ('coste' in cambios) carga.coste = cambios.coste;
  if (cambios.id_prioridad) carga.id_prioridad = cambios.id_prioridad;
  if ('titulo' in cambios) carga.titulo = cambios.titulo;
  if ('detalle' in cambios) carga.detalle = cambios.detalle;
  if ('id_elemento' in cambios) carga.id_elemento = cambios.id_elemento;
  if ('id_rel_zona' in cambios) carga.id_rel_zona = cambios.id_rel_zona;

  // Si el parte todavía no existe en el servidor se referencia por uuid
  if (ticket.id_ticket > 0) carga.id_ticket = ticket.id_ticket;
  else carga.uuid_ticket = ticket.uuid;

exigirColaPermitida();
  await db.encolar('ticket.actualizar', carga);
  await recontar();
  enSegundoPlano();
  return local;
}

/** Añade una foto a un parte ya existente. */
export async function subirFoto(ticket, datos, nombre = 'foto.jpg') {
  const fid = uuid();
  const ref = ticket.id_ticket > 0 ? ticket.id_ticket : ticket.uuid;
  await db.guardarFoto(fid, datos, nombre, ref);

  const carga = { uuid: fid, nombre };
  if (ticket.id_ticket > 0) carga.id_ticket = ticket.id_ticket;
  else carga.uuid_ticket = ticket.uuid;

exigirColaPermitida();
  await db.encolar('adjunto.subir', carga);

  const local = await db.leer('tickets', ticket.id_ticket);
  if (local) {
    local.n_adjuntos = (local.n_adjuntos || 0) + 1;
    await db.poner('tickets', local);
  }

  await recontar();
  enSegundoPlano();
  return fid;
}

/* ================================================================= */
/* Envío de la cola                                                  */
/* ================================================================= */
async function enviarCola() {
  const cola = await db.colaPendiente();
  if (!cola.length) return { enviadas: 0 };

  // Se envía en tandas para no montar peticiones enormes con fotos.
  const tanda = cola.slice(0, 25);
  const operaciones = [];

  for (const op of tanda) {
    const datos = { ...op.datos };

    // Las fotos viajan en base64; se recuperan del almacén ahora,
    // no antes, para no cargar en memoria lo que no toca.
    if (op.tipo === 'adjunto.subir') {
      const f = await db.leer('fotos', datos.uuid);
      if (!f) { await db.borrar('cola', op.id_local); continue; }
      datos.datos = f.datos;
    }
    operaciones.push({ id_local: op.id_local, tipo: op.tipo, datos });
  }

  if (!operaciones.length) return { enviadas: 0 };

  const respuesta = await api.push(operaciones);
  const mapaUuid = new Map();   // uuid provisional -> id definitivo

  let conflictosNuevos = 0;

  for (const r of respuesta.resultados || []) {
    if (r.ok) {
      await db.borrar('cola', r.id_local);

      /* El parte, tal y como ha quedado en el servidor.
       *
       * Esto faltaba, y era la causa de que un parte siguiera diciendo
       * «Pendiente Asignar» y «Sin enviar» en el inicio después de
       * quedárselo con conexión: el envío salía bien, se borraba de la
       * cola... y la copia local se quedaba con lo de antes y con la
       * marca de pendiente puesta. Sólo se arreglaba cuando una
       * sincronización completa volvía a bajar ese parte.
       *
       * El servidor ya devuelve el parte en la respuesta: se guarda. */
      if (r.ticket && r.tipo !== 'ticket.crear') {
        await db.poner('tickets', { ...r.ticket, pendiente_envio: false });
      }

      if (r.tipo === 'ticket.crear' && r.ticket) {
        mapaUuid.set(r.ticket.uuid, r.ticket);
      }
      if (r.tipo === 'adjunto.subir' && r.adjunto) {
        await db.borrar('fotos', r.adjunto.uuid);
      }
    } else if (r.conflicto) {
      // Otra persona movió el parte mientras este móvil estaba sin red.
      // No se pisa su trabajo: la operación queda apartada, intacta,
      // hasta que quien la hizo decida qué versión vale.
      const op = operaciones.find((o) => o.id_local === r.id_local);
      if (op) {
        await db.guardarConflicto(op, r.conflicto, r.ticket);
        await db.marcarError(r.id_local, r.conflicto.mensaje);
        conflictosNuevos++;
      }
      if (r.ticket) await db.poner('tickets', { ...r.ticket, pendiente_envio: false });
    } else if (r.reintentar) {
      // fallo temporal: se deja en la cola para la próxima vuelta
    } else {
      await db.marcarError(r.id_local, r.error || 'No se ha podido enviar.');
    }
  }

  if (conflictosNuevos > 0) {
    tono('conflicto');
    avisoSistema(
      conflictosNuevos === 1 ? 'Un cambio necesita tu revisión' : `${conflictosNuevos} cambios necesitan tu revisión`,
      'Otra persona modificó el parte mientras estabas sin conexión.',
      { ruta: '#/conflictos', tag: 'conflictos' }
    );
  }

  // El parte provisional (id negativo) se sustituye por el real.
  for (const [uuidTicket, real] of mapaUuid) {
    const locales = await db.porIndice('tickets', 'uuid', uuidTicket);
    for (const l of locales) {
      if (l.id_ticket < 0) await db.borrar('tickets', l.id_ticket);
    }
    real.pendiente_envio = false;
    await db.poner('tickets', real);

    // Y las operaciones que aún apuntaban al uuid pasan al id definitivo
    const restantes = await db.colaPendiente();
    for (const op of restantes) {
      if (op.datos?.uuid_ticket === uuidTicket) {
        op.datos.id_ticket = real.id_ticket;
        await db.poner('cola', op);
      }
    }
  }

  const quedan = (await db.colaPendiente()).length;
  return { enviadas: respuesta.aceptadas || 0, quedan };
}

/* ================================================================= */
/* Descarga de cambios                                               */
/* ================================================================= */
/* ------------------------------------------------------------------ */
/* ¿Es el mismo servidor de siempre?                                   */
/* ------------------------------------------------------------------ */

/**
 * El servidor manda un identificador de su instalación, que cambia al
 * reinstalarlo o restaurar una copia. Si no coincide con el que el móvil
 * recuerda, lo guardado es de una base que ya no existe: partes que no
 * están y números que ahora son de otros partes. Se vacía todo y se
 * descarga de nuevo.
 *
 * Lo pendiente de enviar se descarta en ese caso, y se dice: apunta a
 * partes de la base vieja, y mandar «cambia el T-00005» a una base nueva
 * cambiaría otro parte que no tiene nada que ver.
 *
 * @returns {number|false} false si no hacía falta; si no, cuántos cambios
 *          pendientes se han descartado
 */
async function comprobarInstalacion(inst) {
  if (!inst) return false;
  const antes = await db.ajuste('instalacion');
  if (antes === inst) return false;

  const pendientes = await db.colaPendiente();
  await db.ajuste('instalacion', inst);

  if (!antes) {
    /* La primera vez con esta versión: se aprovecha para quitar los
       partes eliminados que se quedaron en el móvil con las versiones
       anteriores. Sólo si no hay nada pendiente: eso no se toca. */
    if (pendientes.length) return false;
    await db.limpiarCache();
    return 0;
  }

  await db.vaciar('cola');
  await db.vaciar('conflictos');
  await db.vaciar('fotos');
  await db.limpiarCache();
  return pendientes.length;
}

/* ------------------------------------------------------------------ */
/* Renovación pedida por el administrador                              */
/* ------------------------------------------------------------------ */

/**
 * ¿Ha pedido el administrador que este aparato empiece de cero?
 *
 * Pasa al actualizar la aplicación: el móvil sigue con la versión que
 * tiene guardada para funcionar sin cobertura y no se entera de la nueva.
 * Con la orden, cada aparato —en cuanto hable con el servidor— hace
 * cuatro cosas EN ESTE ORDEN:
 *
 *   1. sube lo que tenga pendiente, que si no se pierde;
 *   2. vacía lo guardado, incluidos los ficheros de la aplicación;
 *   3. cierra la sesión, para que haya que identificarse;
 *   4. se recarga y descarga todo de nuevo, ya con la versión nueva.
 *
 * El orden es lo importante: si se cerrara la sesión primero, el parte
 * que un técnico escribió en el sótano no llegaría nunca.
 */
const CLAVE_RENOVACION = 'hurakantick_renovacion';

/** La última orden atendida. En localStorage y no en la base del móvil,
    porque la renovación vacía esa base: la marca tiene que sobrevivir. */
export function marcaRenovacion() {
  try { return localStorage.getItem(CLAVE_RENOVACION) || ''; } catch { return ''; }
}
export function anotarRenovacion(marca) {
  try { localStorage.setItem(CLAVE_RENOVACION, marca || ''); } catch { /* da igual */ }
}

export async function renovacionPendiente(marca) {
  if (!marca) return false;
  /* Sin marca guardada es que este aparato viene de una versión anterior
     —o lleva mucho sin entrar—: se atiende la orden. Al entrar se apunta
     la marca del día, así que un aparato recién identificado no se renueva
     sin motivo. */
  return marcaRenovacion() !== marca;
}

async function hacerRenovacion(marca) {
  estado.renovando = true;
  avisar();

  try { await enviarCola(); } catch { /* sin red: se reintenta luego */ }
  if ((await db.colaPendiente()).length) {
    // Queda trabajo sin subir: no se vacía nada hasta que suba.
    estado.renovando = false;
    avisar();
    return false;
  }

  anotarRenovacion(marca);

  await db.limpiarCache();
  try { await db.vaciar('conflictos'); await db.vaciar('fotos'); } catch { /* da igual */ }
  try {
    if (window.caches) {
      const nombres = await caches.keys();
      await Promise.all(nombres.map((n) => caches.delete(n)));
    }
    if (navigator.serviceWorker) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map((r) => r.unregister()));
    }
  } catch { /* si el navegador no deja, la recarga traerá lo nuevo igual */ }

  try { localStorage.removeItem('hurakantick_token'); localStorage.removeItem('hurakantick_usuario'); } catch { /* nada */ }
  location.replace('index.html?renovada=1');
  return true;
}

/**
 * Vacía lo guardado en el móvil y lo vuelve a descargar entero.
 *
 * El botón de Ajustes. Con cambios sin enviar no se hace: se perderían.
 */
export async function descargarTodo() {
  const pendientes = await db.colaPendiente();
  if (pendientes.length) {
    const e = new Error(`Tienes ${pendientes.length} cambio${pendientes.length === 1 ? '' : 's'} sin enviar. `
      + 'Sincroniza primero, para no perderlos.');
    e.pendientes = pendientes.length;
    throw e;
  }
  await db.limpiarCache();
  await sincronizar({ forzar: true });
  return (await db.todos('tickets')).length;
}

async function traerCambios(yo) {
  let desde = await db.ajuste('sync_desde');
  let vueltas = 0;
  let recibidos = 0;
  const nuevosParaMi = [];

  /* Cuántas vueltas se dan como mucho.
   *
   * Cada una trae 300 partes, así que 40 son 12.000: de sobra para el
   * histórico que se baja al móvil. El tope existe para que un error del
   * servidor no deje al móvil dando vueltas sin fin.
   *
   * Si se agota sin terminar es que hay muchísimo más de lo previsto, y
   * eso hay que decirlo en vez de dar la sincronización por buena: un
   * móvil con datos a medias creyendo que está al día es peor que uno
   * que avisa. */
  const MAX_VUELTAS = 40;

  while (vueltas < MAX_VUELTAS) {
    let r = await api.pull(desde || undefined);

    const descartados = await comprobarInstalacion(r.instalacion);
    if (descartados !== false) {
      // Se empieza de cero con la base que hay ahora.
      desde = null;
      r = await api.pull(undefined);
      estado.renovado = true;
      estado.descartados = descartados;
    }

    if (r.tickets?.length) {
      // No se pisan los partes con cambios locales sin enviar.
      const pendientes = new Set(
        (await db.colaPendiente()).map((o) => o.datos?.id_ticket).filter(Boolean)
      );
      // Uno oculto no se guarda nunca, aunque llegue: se retira abajo.
      const ocultos = r.tickets.filter((t) => Number(t.borrado) === 1).map((t) => t.id_ticket);
      if (ocultos.length) r.retirar = [...(r.retirar || []), ...ocultos];
      const aGuardar = r.tickets.filter((t) => !pendientes.has(t.id_ticket) && Number(t.borrado) !== 1);

      // ¿Alguno es nuevo y me toca a mí? Merece un aviso audible.
      if (yo) {
        for (const t of aGuardar) {
          if (t.id_persona_asignada !== yo || t.es_final) continue;
          const previo = await db.leer('tickets', t.id_ticket);
          const yaEraMio = previo && previo.id_persona_asignada === yo;
          if (!yaEraMio) nuevosParaMi.push(t);
        }
      }

      await db.ponerVarios('tickets', aGuardar);
      recibidos += aGuardar.length;
    }
    // Partes que han dejado de ser visibles para esta persona: se borra
    // su copia local. Un aparato se pierde, y no debe quedarse con datos
    // que su dueño ya no tiene por qué ver.
    if (r.retirar?.length) {
      const pendientes = new Set(
        (await db.colaPendiente()).map((o) => o.datos?.id_ticket).filter(Boolean)
      );
      for (const idT of r.retirar) {
        if (pendientes.has(idT)) continue;      // tiene cambios sin enviar
        await db.borrar('tickets', idT);
        for (const h of (await db.porIndice('historial', 'ticket', idT))) {
          await db.borrar('historial', h.uuid);
        }
        for (const a of (await db.porIndice('adjuntos', 'ticket', idT))) {
          await db.borrar('adjuntos', a.uuid);
        }
      }
    }

    if (r.historial?.length) {
      await db.ponerVarios('historial', r.historial.map((h) => ({ ...h, pendiente_envio: false })));
    }
    if (r.adjuntos?.length) {
      await db.ponerVarios('adjuntos', r.adjuntos);
    }

    desde = r.hasta;
    await db.ajuste('sync_desde', desde);
    estado.noLeidas = r.no_leidas || 0;

    if (r.completo) break;
    vueltas++;

    if (vueltas >= MAX_VUELTAS) {
      estado.incompleto = true;
      console.warn('Sincronización incompleta: quedan más partes en el servidor.');
    }
  }

  if (nuevosParaMi.length) {
    const urgente = nuevosParaMi.some((t) => (t.id_prioridad || 0) >= 4);
    tono(urgente ? 'urgente' : 'asignado');
    const primero = nuevosParaMi[0];
    avisoSistema(
      nuevosParaMi.length === 1
        ? `${primero.codigo} · ${primero.prioridad || ''}`.trim()
        : `${nuevosParaMi.length} partes nuevos para ti`,
      nuevosParaMi.length === 1
        ? `${primero.titulo_ticket}${primero.zona_ruta ? ' · ' + primero.zona_ruta : ''}`
        : 'Ábrelos para verlos.',
      { ruta: nuevosParaMi.length === 1 ? `#/ticket/${primero.id_ticket}` : '#/mios', tag: 'asignados' }
    );
  }

  return recibidos;
}

/* ================================================================= */
/* Ciclo completo                                                    */
/* ================================================================= */
let enCurso = null;

/**
 * Sincroniza asegurándose de incluir lo que se acaba de encolar.
 *
 * `sincronizar()` devuelve la que ya esté en marcha, y eso está bien
 * para el ciclo de fondo. Pero si acabas de crear un parte, esa que ya
 * corría empezó antes y no lo lleva: se espera a que termine y se lanza
 * otra. Sin esto, el parte se quedaba marcado como «no ha salido del
 * móvil» aunque hubiera cobertura, y sólo pasaba cuando el servidor
 * tardaba lo justo para que las dos coincidieran.
 */
export async function sincronizarIncluyendo(opciones = {}) {
  for (let i = 0; i < 3; i++) {
    if (!enCurso) break;
    try { await enCurso; } catch { /* el error se trata dentro */ }
  }
  return sincronizar(opciones);
}

/* Las sincronizaciones que lanza la aplicación sola —al volver la red,
   al volver a la pestaña, cada cierto tiempo— no tienen a nadie esperando
   el resultado. Si fallan, lo dice la tira de estado; el error no debe
   quedarse suelto en el navegador. */
function enSegundoPlano() {
  sincronizar().catch(() => { /* ya lo refleja el estado */ });
}

export function sincronizar({ forzar = false, yo = null } = {}) {
  if (enCurso) return enCurso;
  if (!hayRed() && !forzar) return Promise.resolve({ omitida: true });

  estado.sincronizando = true;
  avisar();

  enCurso = (async () => {
    try {
      /* ¿Ha pedido el administrador que todos empiecen de cero? Se mira
         lo primero: la renovación ya se encarga de subir lo pendiente
         antes de vaciar nada. */
      try {
        const e0 = await api.estado();
        if (await renovacionPendiente(e0.renovacion)) {
          if (await hacerRenovacion(e0.renovacion)) return { renovando: true };
        }
      } catch { /* sin respuesta: se intentará luego */ }

      /* Antes de mandar lo pendiente, ¿es el mismo servidor? Si se ha
         reinstalado, lo pendiente apunta a la base vieja: un parte creado
         sin cobertura se crearía en el hotel nuevo, y una nota podría caer
         en el parte nuevo que tiene el mismo número que el viejo. Sólo se
         pregunta si hay algo que enviar. */
      if ((await db.colaPendiente()).length) {
        try {
          const e = await api.estado();
          const d = await comprobarInstalacion(e.instalacion);
          if (d !== false) { estado.renovado = true; estado.descartados = d; }
        } catch { /* sin respuesta: la cola espera a la próxima */ }
      }

      const salida = await enviarCola();
      const entrada = await traerCambios(yo ?? usuarioActual);
      estado.ultima = new Date();
      await recontar();
      return { ...salida, recibidos: entrada };
    } catch (e) {
      if (e instanceof ErrorRed || e.sinRed) {
        return { sinRed: true };
      }

      /* El servidor cerrado por mantenimiento no es un fallo.
       *
       * Mientras se restaura una copia, el servidor deja subir lo que
       * cada móvil tenga pendiente —para que nadie pierda su trabajo—
       * pero no deja bajar nada. Sin esto, la sincronización reventaba
       * con una excepción sin capturar justo después de haber salvado
       * el trabajo: la aplicación se quedaba en un estado raro y la
       * persona no entendía si su parte se había enviado o no.
       *
       * Se trata como «ahora no se puede», que es lo que es. */
      if (e?.extra?.mantenimiento || e?.codigo === 503) {
        estado.mantenimiento = true;
        avisar();
        return { mantenimiento: true, mensaje: e.message };
      }
      estado.mantenimiento = false;

      throw e;
    } finally {
      estado.sincronizando = false;
      enCurso = null;
      avisar();
    }
  })();

  return enCurso;
}

/**
 * Resuelve un conflicto quedándose con la versión del servidor: la
 * operación local se descarta y el parte se queda como está.
 */
export async function conflictoAceptarServidor(idLocal) {
  await db.resolverConflicto(idLocal);
  await recontar();
  tono('ok');
}

/**
 * Resuelve un conflicto imponiendo el cambio local. Se reenvía con
 * «forzar», así que el servidor lo aplica encima y queda registrado en
 * el historial junto al de la otra persona.
 */
export async function conflictoImponerLocal(idLocal) {
  const c = await db.leer('conflictos', idLocal);
  if (!c) return { ok: false };

  const datos = { ...c.operacion.datos, forzar: true };
  delete datos.version_base;

  try {
    const r = await api.push([{ id_local: idLocal, tipo: c.operacion.tipo, datos }]);
    const res = (r.resultados || [])[0];
    if (res?.ok) {
      await db.resolverConflicto(idLocal);
      if (res.ticket) await db.poner('tickets', { ...res.ticket, pendiente_envio: false });
      await recontar();
      tono('ok');
      return { ok: true };
    }
    return { ok: false, error: res?.error || 'No se ha podido aplicar.' };
  } catch (e) {
    return { ok: false, error: e.message };
  }
}

/** Reintenta las operaciones que quedaron marcadas con error. */
export async function reintentarErrores() {
  const errores = await db.colaConError();
  for (const o of errores) await db.reintentar(o.id_local);
  await recontar();
  return sincronizar({ forzar: true });
}

export async function descartarErrores() {
  const errores = await db.colaConError();
  for (const o of errores) {
    if (o.datos?.uuid) await db.borrar('fotos', o.datos.uuid);
    await db.borrar('cola', o.id_local);
  }
  await recontar();
}

/* ---------------------------------------------------------------- */
/* Disparadores automáticos                                         */
/* ---------------------------------------------------------------- */
export function arrancar() {
  alCambiarRed((hay) => { if (hay) enSegundoPlano(); });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') comprobarRed().then((h) => h && enSegundoPlano());
  });

  /* Sondeo periódico.
   *
   * Dos motivos distintos, y por eso dos ritmos:
   *
   *   · sacar lo que haya en cola cuando vuelve la red
   *   · enterarse de lo que ha pasado en el servidor —un parte que te
   *     asignan, uno urgente que entra— sin tener que tocar nada
   *
   * Lo segundo es lo que hace que el aviso llegue mientras la
   * aplicación está abierta. Antes sólo se sincronizaba si había cola,
   * así que un técnico con la pantalla delante y nada pendiente de
   * enviar no se enteraba de un parte nuevo hasta que tocaba algo.
   *
   * Un minuto es el equilibrio: lo bastante rápido para que un urgente
   * no se quede esperando, y lo bastante lento para no castigar la
   * batería ni el servidor. Con la pantalla apagada no corre, porque
   * el navegador congela los temporizadores. */
  setInterval(async () => {
    if (document.visibilityState !== 'visible') return;
    const hay = await comprobarRed();
    if (hay) enSegundoPlano();
  }, 60000);

  recontar();
}
