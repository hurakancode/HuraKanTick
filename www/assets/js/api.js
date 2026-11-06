/* =====================================================================
   Cliente de la API.

   La dirección del servidor no se puede fijar en el código: en el
   navegador la app cuelga del mismo dominio, pero dentro del APK se
   sirve desde https://localhost y hay que apuntar al servidor real.
   Por eso se resuelve en este orden:
     1. lo que el usuario haya guardado en Ajustes
     2. la constante HURAKANTICK_API si se ha definido al compilar
     3. la carpeta ../api relativa a la propia página
   ===================================================================== */

import { ajuste } from './db.js';

const CLAVE_TOKEN = 'hurakantick_token';
const CLAVE_BASE  = 'hurakantick_api';

export class ErrorRed extends Error {
  constructor(mensaje = 'Sin conexión con el servidor.') {
    super(mensaje);
    this.nombre = 'ErrorRed';
    this.sinRed = true;
  }
}

export class ErrorApi extends Error {
  constructor(mensaje, codigo, extra = {}) {
    super(mensaje);
    this.nombre = 'ErrorApi';
    this.codigo = codigo;
    this.extra = extra;
  }
}

/* ---------------------------------------------------------------- */
/* Dirección del servidor                                           */
/* ---------------------------------------------------------------- */
function baseAutomatica() {
  const u = new URL(window.location.href);
  // dentro del APK: capacitor sirve la app en https://localhost
  if (u.protocol === 'capacitor:' || u.hostname === 'localhost' && u.port === '') {
    return window.HURAKANTICK_API || '';
  }
  const ruta = u.pathname.replace(/\/(www\/)?[^/]*$/, '');
  return `${u.origin}${ruta}/api`.replace(/\/+$/, '');
}

export function base() {
  return localStorage.getItem(CLAVE_BASE) || window.HURAKANTICK_API || baseAutomatica();
}

export function fijarBase(url) {
  const limpia = String(url || '').trim().replace(/\/+$/, '');
  if (limpia) localStorage.setItem(CLAVE_BASE, limpia);
  else localStorage.removeItem(CLAVE_BASE);
}

/* ---------------------------------------------------------------- */
/* Sesión                                                           */
/* ---------------------------------------------------------------- */
export function token() {
  return localStorage.getItem(CLAVE_TOKEN);
}

export function fijarToken(t) {
  if (t) localStorage.setItem(CLAVE_TOKEN, t);
  else localStorage.removeItem(CLAVE_TOKEN);
}

/* ---------------------------------------------------------------- */
/* Petición                                                         */
/* ---------------------------------------------------------------- */
function url(ruta, parametros) {
  // Se usa ?_ruta= para no depender de la reescritura de direcciones
  // del servidor: funciona igual con y sin .htaccess.
  const u = new URL(base() + '/index.php', window.location.href);
  u.searchParams.set('_ruta', ruta.replace(/^\/+/, ''));
  if (parametros) {
    Object.entries(parametros).forEach(([k, v]) => {
      if (v !== null && v !== undefined && v !== '' && v !== false) {
        u.searchParams.set(k, Array.isArray(v) ? v.join(',') : String(v));
      }
    });
  }
  return u.toString();
}

/**
 * Hotel con el que trabaja un superadministrador.
 *
 * Sólo tiene efecto para ellos: el servidor ignora este parámetro para
 * el resto de la gente, que trabaja siempre en el suyo. Se guarda en el
 * aparato porque cambiar de hotel es una decisión que dura toda la
 * sesión, no una petición suelta.
 */
export function hotelActivo() {
  const v = localStorage.getItem('hurakantick_hotel');
  return v ? parseInt(v, 10) : null;
}

export function fijarHotelActivo(id) {
  if (id) localStorage.setItem('hurakantick_hotel', String(id));
  else localStorage.removeItem('hurakantick_hotel');
}

/* Quién quiere enterarse de que la sesión ha caducado. */
const alExpirar = new Set();

export function alCaducarSesion(fn) {
  alExpirar.add(fn);
  return () => alExpirar.delete(fn);
}

export async function peticion(ruta, { metodo = 'GET', cuerpo, parametros, tiempo = 20000 } = {}) {
  const cabeceras = { Accept: 'application/json' };

  // El hotel viaja en todas las peticiones para que el servidor sepa
  // sobre cuál trabajar. Va en la dirección y no en el cuerpo porque
  // también hace falta en los GET.
  const hotel = hotelActivo();
  if (hotel) parametros = { ...(parametros || {}), hotel };
  const t = token();
  if (t) cabeceras.Authorization = `Bearer ${t}`;

  const opciones = { method: metodo, headers: cabeceras };

  if (cuerpo !== undefined) {
    if (cuerpo instanceof FormData) {
      opciones.body = cuerpo;                      // el navegador pone el Content-Type
    } else {
      cabeceras['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(cuerpo);
    }
  }

  const corte = new AbortController();
  const reloj = setTimeout(() => corte.abort(), tiempo);
  opciones.signal = corte.signal;

  let respuesta;
  try {
    respuesta = await fetch(url(ruta, parametros), opciones);
  } catch (e) {
    if (e.name === 'AbortError') {
      throw new ErrorRed('El servidor ha tardado demasiado en responder.');
    }

    /* Dentro del APK, un servidor por «http://» lo bloquea Android, no la
       cobertura. El error que llega es el mismo que el de estar sin red,
       así que sin esto la pantalla dice «sin conexión» mientras el móvil
       tiene cuatro rayas: se busca en el router, en el cable y en el
       servidor, y el problema está en un fichero de la aplicación.
       Cuesta una comprobación decirlo. */
    const dentroDeApp = typeof window !== 'undefined'
      && (window.Capacitor?.isNativePlatform?.() === true);
    const destino = (() => {
      try { return new URL(base() + '/index.php', window.location.href).protocol; }
      catch { return ''; }
    })();
    const servidorSinCifrar = destino === 'http:';

    if (dentroDeApp && servidorSinCifrar) {
      throw new ErrorRed(
        'Android bloquea las conexiones sin cifrar. El servidor está en «http://», '
        + 'así que hay que ponerle un certificado o autorizar su dirección en '
        + 'android/app/src/main/res/xml/network_security_config.xml y volver a compilar.');
    }

    throw new ErrorRed('Sin conexión con el servidor.');
  } finally {
    clearTimeout(reloj);
  }

  let datos = null;
  const texto = await respuesta.text();
  try { datos = texto ? JSON.parse(texto) : null; } catch { /* respuesta no JSON */ }

  if (!respuesta.ok || (datos && datos.ok === false)) {
    const mensaje = datos?.error || `El servidor ha respondido ${respuesta.status}.`;
    /* Sesión caducada o testigo inválido.
     *
     * Antes sólo se borraba el testigo, pero la aplicación seguía en pie
     * enseñando lo que tenía guardado del móvil. Eso es peor que echar a
     * la persona: parece que todo va bien —los partes están ahí— hasta
     * que intenta cambiar algo y nada se guarda.
     *
     * Se avisa a quien esté escuchando para que lleve al acceso. */
    if (respuesta.status === 401) {
      fijarToken(null);
      for (const fn of alExpirar) {
        try { fn(); } catch { /* nada */ }
      }
    }
    throw new ErrorApi(mensaje, respuesta.status, datos || {});
  }
  return datos;
}

/* ---------------------------------------------------------------- */
/* Atajos                                                           */
/* ---------------------------------------------------------------- */
export const api = {
  estado:      ()               => peticion('estado'),
  entrar:      (correo, password) => peticion('auth/login', { metodo: 'POST', cuerpo: { correo, password } }),
  salir:       ()               => peticion('auth/logout', { metodo: 'POST' }),
  yo:          ()               => peticion('auth/yo'),
  password:    (actual, nueva)  => peticion('auth/password', { metodo: 'POST', cuerpo: { actual, nueva } }),
  /* La entrada por enlace: si no hay testigo, peticion() no manda
     cabecera de sesión, así que no hace falta nada especial. */
  entrarConEnlace: (u, k) => peticion('auth/enlace', { metodo: 'POST', cuerpo: { u, k } }),

  enlaceGenerar:  (id, base) => peticion(`usuarios/${id}/enlace`, { metodo: 'POST', cuerpo: { base } }),
  enlaceActivar:  (id, activo) => peticion(`usuarios/${id}/enlace`, { metodo: 'PATCH', cuerpo: { activo } }),
  enlaceRetirar:  (id) => peticion(`usuarios/${id}/enlace`, { metodo: 'DELETE' }),

  sesiones:    ()               => peticion('auth/sesiones'),
  cerrarSesion:(id)             => peticion(`auth/sesiones/${id}`, { metodo: 'DELETE' }),
  cerrarOtrasSesiones: ()       => peticion('auth/sesiones', { metodo: 'DELETE' }),
  cerrarOtras: ()               => peticion('auth/sesiones', { metodo: 'DELETE' }),

  catalogo:    ()               => peticion('catalogo'),
  panel:       ()               => peticion('panel'),

  tickets:     (f)              => peticion('tickets', { parametros: f }),
  ticket:      (id)             => peticion(`tickets/${id}`),

  /* Módulo SAP. Si el módulo está apagado, el servidor responde «ruta no
     encontrada» y quien llame debe tratarlo como «no hay datos», no como
     un error que enseñar. */
  sapEstado:      ()      => peticion('sap/estado'),
  sapHabitacion:  (n)     => peticion('sap/habitacion', { parametros: { numero: n } }),
  sapProbar:      ()      => peticion('sap/probar', { metodo: 'POST' }),
  repetidosDe: (id)             => peticion(`tickets/${id}/repetidos`),
  conversaciones: ()            => peticion('conversaciones'),
  avisarError: (d)              => peticion('errores', { metodo: 'POST', cuerpo: d }),
  errores:     (todos)          => peticion('errores', { parametros: { todos: todos ? 1 : 0 } }),
  errorVisto:  (id)             => peticion('errores/visto', { metodo: 'POST', cuerpo: { id_error: id } }),
  chat:        (id)             => peticion(`tickets/${id}/chat`),
  observadores:    (id)         => peticion(`tickets/${id}/observadores`),
  ponerObservador: (id, d)      => peticion(`tickets/${id}/observadores`, { metodo: 'POST', cuerpo: d }),
  quitarObservador:(id, n)      => peticion(`tickets/${id}/observadores/${n}`, { metodo: 'DELETE' }),
  chatEscribir:(id, d)          => peticion(`tickets/${id}/chat`, { metodo: 'POST', cuerpo: d }),
  chatLeido:   (id)             => peticion(`tickets/${id}/chat/leido`, { metodo: 'POST' }),
  crear:       (d)              => peticion('tickets', { metodo: 'POST', cuerpo: d }),
  actualizar:  (id, d)          => peticion(`tickets/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarTicket:(id)             => peticion(`tickets/${id}`, { metodo: 'DELETE' }),
  movimiento:  (id, d)          => peticion(`tickets/${id}/movimientos`, { metodo: 'POST', cuerpo: d }),
  subirFoto:   (id, d)          => peticion(`tickets/${id}/adjuntos`, { metodo: 'POST', cuerpo: d, tiempo: 60000 }),
  borrarFoto:  (id)             => peticion(`adjuntos/${id}`, { metodo: 'DELETE' }),

  pull:        (desde)          => peticion('sync/pull', { parametros: { desde }, tiempo: 45000 }),
  push:        (operaciones)    => peticion('sync/push', { metodo: 'POST', cuerpo: { operaciones }, tiempo: 60000 }),

  avisos:      ()               => peticion('notificaciones'),
  leerAvisos:  (ids)            => peticion('notificaciones/leidas', { metodo: 'POST', cuerpo: { ids } }),

  // Informes
  informeDefiniciones: ()       => peticion('informes/definiciones'),
  informeEjecutar: (d)          => peticion('informes/ejecutar', { metodo: 'POST', cuerpo: d, tiempo: 40000 }),
  informes:    ()               => peticion('informes'),
  guardarInforme: (d)           => peticion('informes', { metodo: 'POST', cuerpo: d }),
  borrarInforme: (id)           => peticion(`informes/${id}`, { metodo: 'DELETE' }),

  // Configuración, campos, atajos y copias
  seguridad:   (f)              => peticion('seguridad', { parametros: f }),
  purgarSeguridad: ()           => peticion('seguridad', { metodo: 'DELETE' }),
  recuperaciones: ()            => peticion('usuarios/recuperaciones'),
  emitirCodigo: (id)            => peticion(`usuarios/${id}/codigo`, { metodo: 'POST', cuerpo: {} }),
  recuperar:   (correo)         => peticion('auth/recuperar', { metodo: 'POST', cuerpo: { correo }, sinToken: true }),
  entrarConCodigo: (d)          => peticion('auth/codigo', { metodo: 'POST', cuerpo: d, sinToken: true }),
  cambiarCorreo: (d)            => peticion('auth/correo', { metodo: 'POST', cuerpo: d }),

  ajustes:     ()               => peticion('ajustes'),
  // La misma acción sobre varias personas, en un solo viaje.
  usuariosLote:(accion, ids, extra = {}) => peticion('usuarios/lote',
                                      { metodo: 'POST', cuerpo: { accion, ids, ...extra } }),
  renovarTodos:()               => peticion('renovar', { metodo: 'POST', cuerpo: {} }),

  // Alta y salida de personas por ficheros de texto.
  personal:    ()               => peticion('personal'),
  plantillaPersona: (id = null) => peticion('personal/plantilla' + (id ? '/' + id : '')),
  importarPersonas: (simular)   => peticion('personal/importar',
                                      { metodo: 'POST', cuerpo: { simular: !!simular } }),
  exportarPersonas: (ids, todos = false) => peticion('personal/exportar',
                                      { metodo: 'POST', cuerpo: { ids, todos } }),
  descargarPersona: (f)         => peticion('personal/descargar?f=' + encodeURIComponent(f)),
  borrarExportadas: (ficheros = null) => peticion('personal/exportados',
                                      { metodo: 'DELETE',
                                        cuerpo: ficheros ? { ficheros } : { todos: true } }),
  // Comprobar la copia a Teams. Sin persona prueba sólo Azure; con
  // persona hace el camino entero y le manda un mensaje de prueba.
  probarTeams:(idLogin = null)   => peticion('teams', { metodo: 'POST',
                                      cuerpo: idLogin ? { id_login: idLogin } : {} }),
  misMensajes: ()               => peticion('mensajes'),
  mensajeVisto:(cuerpo)         => peticion('mensajes/visto', { metodo: 'POST', cuerpo }),
  frases:      ()               => peticion('frases'),
  crearFrase:  (cuerpo)         => peticion('frases', { metodo: 'POST', cuerpo }),
  editarFrase: (id, cuerpo)     => peticion(`frases/${id}`, { metodo: 'PATCH', cuerpo }),
  borrarFrase: (id)             => peticion(`frases/${id}`, { metodo: 'DELETE' }),
  crearCategoriaFrase:(cuerpo)  => peticion('frases/categoria', { metodo: 'POST', cuerpo }),
  editarCategoriaFrase:(id, c)  => peticion(`frases/categoria/${id}`, { metodo: 'PATCH', cuerpo: c }),
  borrarCategoriaFrase:(id)     => peticion(`frases/categoria/${id}`, { metodo: 'DELETE' }),
  guardarAjustes: (cambios)     => peticion('ajustes', { metodo: 'PATCH', cuerpo: { cambios } }),
  campos:      (elemento)       => peticion('campos', { parametros: { elemento } }),
  crearCampo:  (d)              => peticion('campos', { metodo: 'POST', cuerpo: d }),
  editarCampo: (id, d)          => peticion(`campos/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarCampo: (id)             => peticion(`campos/${id}`, { metodo: 'DELETE' }),
  unidades:    (f)              => peticion('unidades', { parametros: f }),
  unidad:      (id)             => peticion(`unidades/${id}`),
  crearUnidad: (d)              => peticion('unidades', { metodo: 'POST', cuerpo: d }),
  cambiarUnidad: (id, d)        => peticion(`unidades/${id}`, { metodo: 'PATCH', cuerpo: d }),

  instruccionUtil: (id)         => peticion(`instrucciones/${id}/util`, { metodo: 'POST', cuerpo: {} }),
  crearInstruccion: (d)         => peticion('instrucciones', { metodo: 'POST', cuerpo: d }),
  editarInstruccion: (id, d)    => peticion(`instrucciones/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarInstruccion: (id)       => peticion(`instrucciones/${id}`, { metodo: 'DELETE' }),

  mantenimientos: ()            => peticion('mantenimiento'),
  crearMantenimiento: (d)       => peticion('mantenimiento', { metodo: 'POST', cuerpo: d }),
  editarMantenimiento: (id, d)  => peticion(`mantenimiento/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarMantenimiento: (id)     => peticion(`mantenimiento/${id}`, { metodo: 'DELETE' }),
  historialMantenimiento: (id)  => peticion(`mantenimiento/${id}/historial`),
  generarMantenimiento: (sim)   => peticion('mantenimiento/generar',
                                    { metodo: 'POST', cuerpo: { simular: !!sim }, tiempo: 60000 }),

  loteTickets: (d)              => peticion('tickets/lote',
                                    { metodo: 'POST', cuerpo: d, tiempo: 60000 }),

  purgarPapelera:     (id)      => peticion(`papelera/${id}`, { metodo: 'DELETE' }),
  restaurarPapelera:  (id)      => peticion(`papelera/${id}/restaurar`, { metodo: 'POST', cuerpo: {} }),

  departamentos:      (f)       => peticion('departamentos', { parametros: f }),
  crearDepartamento:  (d)       => peticion('departamentos', { metodo: 'POST', cuerpo: d }),
  editarDepartamento: (id, d)   => peticion(`departamentos/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarDepartamento: (id)      => peticion(`departamentos/${id}`, { metodo: 'DELETE' }),
  departamentoHoteles:(id)      => peticion(`departamentos/${id}/hoteles`),
  repartirDepartamento:(id,h,f) => peticion(`departamentos/${id}/hoteles`,
                                    { metodo: 'PUT', cuerpo: { hoteles: h, forzar: !!f } }),
  crearRangoUbicaciones:(d)     => peticion('ubicaciones-admin/rango',
                                    { metodo: 'POST', cuerpo: d, tiempo: 120000 }),

  pushClave:   ()               => peticion('push/clave'),
  pushRegistrar:(d)             => peticion('push', { metodo: 'POST', cuerpo: d }),
  pushBorrar:  (destino)        => peticion('push', { metodo: 'DELETE', cuerpo: { destino } }),
  pushProbar:  ()               => peticion('push/probar', { metodo: 'POST', tiempo: 30000 }),

  papelera:    (f)              => peticion('papelera', { parametros: f }),
  papeleraFila:(id)             => peticion(`papelera/${id}`),
  restablecerHotel: (id, d)     => peticion(`hoteles/${id}/restablecer`,
                                    { metodo: 'POST', cuerpo: d, tiempo: 120000 }),

  hoteles:     ()               => peticion('hoteles'),
  crearHotel:  (d)              => peticion('hoteles', { metodo: 'POST', cuerpo: d, tiempo: 90000 }),
  editarHotel: (id, d)          => peticion(`hoteles/${id}`, { metodo: 'PATCH', cuerpo: d }),
  copiarHotel: (id, desde, ins) => peticion(`hoteles/${id}/copiar`,
                                    { metodo: 'POST', cuerpo: { desde, instrucciones: !!ins }, tiempo: 90000 }),

  elementos:   (f)              => peticion('elementos', { parametros: f }),
  crearElemento: (d)            => peticion('elementos', { metodo: 'POST', cuerpo: d }),
  editarElemento: (id, d)       => peticion(`elementos/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarElemento: (id, forzar)  => peticion(`elementos/${id}`,
                                    { metodo: 'DELETE', parametros: forzar ? { forzar: 1 } : {} }),

  zonasAdmin:  ()               => peticion('ubicaciones-admin'),
  crearNivelZona: (nivel, nombre) => peticion('ubicaciones-admin/nivel',
                                      { metodo: 'POST', cuerpo: { nivel, nombre } }),
  renombrarNivelZona: (nivel, id, nombre) => peticion('ubicaciones-admin/nivel',
                                      { metodo: 'PATCH', cuerpo: { nivel, id, nombre } }),
  borrarNivelZona: (nivel, id)  => peticion('ubicaciones-admin/nivel',
                                      { metodo: 'DELETE', parametros: { nivel, id } }),

  borrarUsuarioDef: (id)        => peticion(`usuarios/${id}`,
                                    { metodo: 'DELETE', parametros: { definitivo: 1 } }),
  borrarTicketDef: (id)         => peticion(`tickets/${id}`,
                                    { metodo: 'DELETE', parametros: { definitivo: 1 } }),

  familias:    ()               => peticion('familias'),
  familiaElementos: (id)        => peticion(`familias/${id}/elementos`),
  crearFamilia: (d)             => peticion('familias', { metodo: 'POST', cuerpo: d }),
  editarFamilia: (id, d)        => peticion(`familias/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarFamilia: (id)           => peticion(`familias/${id}`, { metodo: 'DELETE' }),
  moverElementos: (elementos, id_familia) =>
      peticion('familias/mover', { metodo: 'POST', cuerpo: { elementos, id_familia } }),

  atajos:      ()               => peticion('atajos'),
  crearAtajo:  (d)              => peticion('atajos', { metodo: 'POST', cuerpo: d }),
  editarAtajo: (id, d)          => peticion(`atajos/${id}`, { metodo: 'PATCH', cuerpo: d }),
  borrarAtajo: (id)             => peticion(`atajos/${id}`, { metodo: 'DELETE' }),
  copias:      ()               => peticion('copias'),
  integridad:  ()               => peticion('integridad', { tiempo: 60000 }),
  crearCopia:  (conFotos)       => peticion('copias', { metodo: 'POST', cuerpo: { con_fotos: conFotos }, tiempo: 120000 }),
  borrarCopia: (id)             => peticion(`copias/${id}`, { metodo: 'DELETE' }),
  /* Un vale de 60 segundos para bajar una copia. La descarga en sí es
     una navegación del navegador, que no puede llevar cabecera de
     sesión; antes se metía el token en la dirección y acababa escrito
     en el registro del servidor. */
  valeCopia:   (id)             => peticion(`copias/${id}/vale`, { metodo: 'POST' }),
  /* ¿Hay una versión nueva publicada? Con forzar, se vuelve a preguntar
     a GitHub aunque se haya preguntado hace poco. */
  versionNueva: (forzar)        => peticion('actualizacion' + (forzar ? '&forzar=1' : ''), { tiempo: 20000 }),

  // Preferencias de aviso
  borrarAviso: (id)             => peticion(`notificaciones/${id}`, { metodo: 'DELETE' }),
  vaciarAvisos: (soloLeidas)    => peticion('notificaciones',
                                    { metodo: 'DELETE', parametros: soloLeidas ? { leidas: 1 } : {} }),

  avisosPref:  ()               => peticion('notificaciones/preferencias'),
  fijarAviso:  (d)              => peticion('notificaciones/preferencias', { metodo: 'POST', cuerpo: d }),
  avisosDefecto: ()             => peticion('notificaciones/preferencias', { metodo: 'DELETE' }),

  usuarios:    ()               => peticion('usuarios'),
  crearUsuario:(d)              => peticion('usuarios', { metodo: 'POST', cuerpo: d }),
  editarUsuario:(id, d)         => peticion(`usuarios/${id}`, { metodo: 'PATCH', cuerpo: d }),

  ubicacion:   (id, lugar)      => peticion(`ubicaciones/${id}`, { parametros: { lugar: lugar ? 1 : 0 } }),
  buscarUbicacion: (q)          => peticion('ubicaciones/buscar', { parametros: { q } }),
  abiertosEn:  (p)              => peticion('ubicaciones/abiertos', { parametros: p }),

  zonas:       ()               => peticion('zonas'),
  crearZona:   (d)              => peticion('zonas', { metodo: 'POST', cuerpo: d }),
};

/** URL absoluta de una foto ya subida. */
export function urlAdjunto(relativa) {
  if (!relativa) return '';
  if (/^(https?:|data:|blob:)/.test(relativa)) return relativa;
  return `${base()}/${relativa.replace(/^\/+/, '')}`;
}

/* ---------------------------------------------------------------- */
/* Estado de la conexión                                            */
/* ---------------------------------------------------------------- */
let enLinea = navigator.onLine;
const oyentes = new Set();

export function hayRed() { return enLinea; }

export function alCambiarRed(fn) {
  oyentes.add(fn);
  return () => oyentes.delete(fn);
}

function avisar(valor) {
  if (enLinea === valor) return;
  enLinea = valor;
  oyentes.forEach((f) => f(valor));
}

window.addEventListener('online',  () => avisar(true));
window.addEventListener('offline', () => avisar(false));

/** Comprobación real: navigator.onLine miente en redes wifi sin salida. */
export async function comprobarRed() {
  if (!navigator.onLine) { avisar(false); return false; }
  try {
    await peticion('estado', { tiempo: 6000 });
    avisar(true);
    return true;
  } catch (e) {
    if (e.sinRed) { avisar(false); return false; }
    avisar(true);            // el servidor responde aunque dé error
    return true;
  }
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
    (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
}
