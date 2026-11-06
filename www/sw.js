/* =====================================================================
   Trabajador de servicio.

   Guarda el armazón de la aplicación para que abra sin conexión.
   Los datos no se cachean aquí: viven en IndexedDB, que es donde la
   aplicación lee y escribe. Así no hay dos copias que puedan discrepar.
   ===================================================================== */

const VERSION = 'hurakantick-v7.90.0';
const ARMAZON = [
  './',
  './index.html',
  './diagnostico.html',
  './manifest.webmanifest',
  './assets/css/app.css',

  /* La guía se guarda entera, capturas incluidas.
     Se consulta justo cuando algo no va —y a veces lo que no va es la
     cobertura del sótano—, así que tiene que abrir sin red. */
  './entrar.html',
  './marco.html',
  './guia.html',
  './assets/css/guia.css',
  './assets/js/guia.js',
  './assets/guia/01-entrar.png',
  './assets/guia/02-entrar-relleno.png',
  './assets/guia/03-inicio.png',
  './assets/guia/04-partes.png',
  './assets/guia/05-ficha-parte.png',
  './assets/guia/06-ajustes.png',
  './assets/guia/07-barra.png',
  './assets/guia/10-inicio-recepcion.png',
  './assets/guia/11-nuevo-vacio.png',
  './assets/guia/12-que-falla.png',
  './assets/guia/14-ubicacion-inicio.png',
  './assets/guia/14-ubicacion-plantas.png',
  './assets/guia/15-ubicacion-buscando.png',
  './assets/guia/15-ubicacion-zonas.png',
  './assets/guia/16-ubicacion-habitaciones.png',
  './assets/guia/18-nuevo-relleno.png',
  './assets/guia/19-nuevo-abajo.png',
  './assets/guia/20-parte-creado.png',
  './assets/guia/21-mios-recepcion.png',
  './assets/guia/30-avisos.png',
  './assets/guia/31-cola-grupo.png',
  './assets/guia/32-parte-sin-coger.png',
  './assets/guia/33-me-lo-quedo.png',
  './assets/guia/34-escribir-nota.png',
  './assets/guia/35-parte-con-foto.png',
  './assets/guia/36-historial.png',
  './assets/guia/37-cambiar-estado.png',
  './assets/guia/38-resolver.png',
  './assets/guia/39-parte-resuelto.png',
  './assets/guia/40-inicio-responsable.png',
  './assets/guia/41-lista.png',
  './assets/guia/42-sin-asignar.png',
  './assets/guia/43-filtro-estado.png',
  './assets/guia/45-asignar.png',
  './assets/guia/45b-quien-lo-hace.png',
  './assets/guia/46-asignado.png',
  './assets/guia/47-seleccionar.png',
  './assets/guia/48-seleccionados.png',
  './assets/guia/48b-acciones-lote.png',
  './assets/guia/49-gestion-partes.png',
  './assets/guia/50-admin-responsable.png',
  './assets/guia/60-admin.png',
  './assets/guia/61-admin-abajo.png',
  './assets/guia/62-equipo.png',
  './assets/guia/63-alta-persona.png',
  './assets/guia/64-editar-persona.png',
  './assets/guia/65-departamentos.png',
  './assets/guia/66-ubicaciones.png',
  './assets/guia/67-rango-relleno.png',
  './assets/guia/67-rango-vacio.png',
  './assets/guia/68-rango-hecho.png',
  './assets/guia/69-hoteles.png',
  './assets/guia/70-mantenimiento.png',
  './assets/guia/71-mantenimiento-nuevo.png',
  './assets/guia/72-mantenimiento-confirmar.png',
  './assets/guia/72-mantenimiento-generado.png',
  './assets/guia/73-mantenimiento-hecho.png',
  './assets/guia/74-instrucciones.png',
  './assets/guia/75-instruccion-abierta.png',
  './assets/guia/76-instruccion-nueva.png',
  './assets/guia/77-aparatos.png',
  './assets/guia/78-aparato-buscado.png',
  './assets/guia/79-aparato-ficha.png',
  './assets/guia/80-informes.png',
  './assets/guia/81-informe-medida.png',
  './assets/guia/82-informe-agrupar.png',
  './assets/guia/83-informe-montado.png',
  './assets/guia/84-informe-resultado.png',
  './assets/guia/85-copias.png',
  './assets/guia/86-copia-hecha.png',
  './assets/guia/87-papelera.png',
  './assets/guia/88-papelera-devuelto.png',
  './assets/guia/88-papelera-ficha.png',
  './assets/guia/89-registro.png',
  './assets/guia/90-sin-cobertura.png',
  './assets/guia/91-guardado-sin-red.png',
  './assets/guia/92-pendiente-de-subir.png',
  './assets/guia/93-ya-subido.png',
  './assets/guia/94-conflicto-aviso.png',
  './assets/guia/94-conflicto.png',
  './assets/guia/95-conflicto-pantalla.png',
  './assets/guia/96-avisos-ajustes.png',
  './assets/guia/97-apariencia.png',
  './assets/guia/98-vista-smartphone.png',
  './assets/guia/99-vista-ajuste.png',
  './assets/guia/100-mensajes.png',
  './assets/guia/101-mensajes-avisos.png',
  './assets/guia/102-equipo-filtros.png',
  './assets/guia/103-personal.png',
  './assets/guia/104-manual-editor.png',
  './assets/guia/105-integridad.png',
  './assets/js/apariencia.js',
  /* El LECTOR de bloques sí entra en la caché: un técnico abre un manual
     justo cuando está en el sótano sin cobertura. El EDITOR —400 KB en
     assets/vendor— NO entra a propósito: lo usan cuatro responsables con
     cobertura, y precargarlo le costaría a los 50 móviles una descarga
     que no van a usar nunca. Se trae por red cuando se abre el panel. */
  './assets/js/bloques.js',
  './assets/js/listas.js',
  './assets/js/api.js',
  './assets/js/app.js',
  './assets/js/db.js',
  './assets/js/nativo.js',
  './assets/js/piezas.js',
  './assets/js/push.js',
  './assets/js/sonido.js',
  './assets/js/sync.js',
  './assets/js/views/iconografia.js',
  './assets/css/fa.css',
  './assets/iconos/fa-solid-900.woff2',
  './assets/iconos/fontawesome.json',
  './assets/js/views/frases.js',
  './assets/js/indice-ajustes.js',
  './assets/js/ui.js',
  './assets/js/vista-movil.js',
  './assets/js/views/acceso.js',
  './assets/js/views/acciones.js',
  './assets/js/views/admin.js',
  './assets/js/views/mensajes.js',
  './assets/js/views/personal.js',
  './assets/js/editor-bloques.js',
  './assets/js/views/apariencia.js',
  './assets/js/views/avisos-ajustes.js',
  './assets/js/views/avisos.js',
  './assets/js/views/conflictos.js',
  './assets/js/views/cuenta.js',
  './assets/js/views/departamentos.js',
  './assets/js/views/equipo.js',
  './assets/js/views/familia.js',
  './assets/js/views/fotos.js',
  './assets/js/views/gestion-partes.js',
  './assets/js/views/hoteles.js',
  './assets/js/views/informes.js',
  './assets/js/views/instrucciones.js',
  './assets/js/views/lista.js',
  './assets/js/views/mantenimiento.js',
  './assets/js/views/nuevo.js',
  './assets/js/views/panel.js',
  './assets/js/views/chat.js',
  './assets/js/views/conversaciones.js',
  './assets/js/views/errores.js',
  './assets/js/views/guia-api.js',
  './assets/js/views/sap.js',
  './assets/js/views/papelera.js',
  './assets/js/views/seguridad.js',
  './assets/js/views/selectores.js',
  './assets/js/views/ticket.js',
  './assets/js/views/ubicacion.js',
  './assets/js/views/unidades.js',
  './assets/icons/icono.svg',
  './assets/icons/icono-192.png',
  './assets/icons/icono-180.png',
  './assets/icons/icono-512.png',
  './assets/icons/icono-mascara.png',
];

const OPCIONALES = [
  './assets/icons/HuraKan_Tick_Logo.png',
];

/* ---------------------------------------------------------------- */
self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(VERSION)
      .then((c) => c.addAll(ARMAZON)
        /* Lo que puede no estar —el logo, que cada casa pone el suyo— va
           aparte y uno a uno: addAll() falla ENTERO si falta un solo
           fichero, y entonces no se guarda nada para trabajar sin
           cobertura. Si está, se guarda; si no, la pantalla de entrada
           enseña el icono de siempre y aquí no pasa nada. */
        .then(() => Promise.all(OPCIONALES.map((u) => c.add(u).catch(() => null)))))
      .then(() => self.skipWaiting())
      .catch((e) => console.warn('SW instalación:', e))
  );
});

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches.keys()
      .then((claves) => Promise.all(
        claves.filter((k) => k !== VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/* ---------------------------------------------------------------- */
self.addEventListener('fetch', (evento) => {
  const peticion = evento.request;

  if (peticion.method !== 'GET') return;

  const url = new URL(peticion.url);

  // Las llamadas a la API nunca se cachean: la aplicación ya sabe
  // trabajar sin ellas y una respuesta vieja sería peor que ninguna.
  if (url.pathname.includes('/api/')) return;

  // Las fotos ya subidas sí: se guardan la primera vez que se ven.
  if (url.pathname.includes('/uploads/')) {
    evento.respondWith(
      caches.open(VERSION + '-fotos').then(async (cache) => {
        const guardada = await cache.match(peticion);
        if (guardada) return guardada;
        try {
          const red = await fetch(peticion);
          if (red.ok) cache.put(peticion, red.clone());
          return red;
        } catch {
          return new Response('', { status: 504 });
        }
      })
    );
    return;
  }

  // Armazón: primero la red, con la copia local como respaldo.
  //
  // Antes era al revés —copia primero y refresco por detrás— y eso hacía
  // que después de actualizar el servidor la primera visita siguiera
  // viendo la versión vieja. Con una aplicación que se actualiza a
  // menudo, eso confunde a quien la usa y a quien la mantiene.
  //
  // El coste es que sin cobertura hay que esperar a que la petición
  // falle, y por eso se le pone un límite corto.
  evento.respondWith((async () => {
    const conLimite = (ms) => new Promise((resolve) => {
      const reloj = setTimeout(() => resolve(null), ms);
      fetch(peticion).then((red) => { clearTimeout(reloj); resolve(red); })
                     .catch(() => { clearTimeout(reloj); resolve(null); });
    });

    const red = await conLimite(2500);
    if (red && red.ok) {
      if (url.origin === self.location.origin) {
        const copia = red.clone();
        caches.open(VERSION).then((c) => c.put(peticion, copia));
      }
      return red;
    }

    const guardada = await caches.match(peticion);
    return guardada || (await caches.match('./index.html'))
        || new Response('Sin conexión', { status: 503 });
  })());
});

/* ---------------------------------------------------------------- */
/* Sincronización en segundo plano: cuando Android recupera la red,  */
/* despierta la aplicación para que vacíe la cola aunque esté cerrada*/
/* ---------------------------------------------------------------- */
self.addEventListener('sync', (evento) => {
  if (evento.tag === 'hurakantick-cola') {
    evento.waitUntil(avisarClientes({ tipo: 'sincronizar' }));
  }
});

self.addEventListener('periodicsync', (evento) => {
  if (evento.tag === 'hurakantick-refresco') {
    evento.waitUntil(avisarClientes({ tipo: 'sincronizar' }));
  }
});

async function avisarClientes(mensaje) {
  const clientes = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
  clientes.forEach((c) => c.postMessage(mensaje));
}

self.addEventListener('message', (evento) => {
  if (evento.data?.tipo === 'actualizar') self.skipWaiting();
});

/* ---------------------------------------------------------------- */
/* Avisos push (opcional: requiere servidor de notificaciones)       */
/* ---------------------------------------------------------------- */
self.addEventListener('push', (evento) => {
  let datos = { titulo: 'HuraKan Tick', cuerpo: 'Hay novedades.' };
  try { datos = { ...datos, ...evento.data.json() }; } catch { /* texto plano */ }

  evento.waitUntil(
    self.registration.showNotification(datos.titulo, {
      body: datos.cuerpo,
      icon: './assets/icons/icono-192.png',
      badge: './assets/icons/icono-192.png',
      tag: datos.id_ticket ? 'ticket-' + datos.id_ticket : 'hurakantick',
      data: { ruta: datos.id_ticket ? '#/ticket/' + datos.id_ticket : '#/panel' },
      vibrate: [90, 50, 90],
    })
  );
});

self.addEventListener('notificationclick', (evento) => {
  evento.notification.close();
  const ruta = evento.notification.data?.ruta || '#/panel';
  evento.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((lista) => {
      for (const c of lista) {
        if ('focus' in c) { c.navigate(c.url.split('#')[0] + ruta); return c.focus(); }
      }
      return self.clients.openWindow('./' + ruta);
    })
  );
});

/* El navegador renueva la suscripción por su cuenta cada cierto tiempo.
   Si no se le dice al servidor, los avisos dejan de llegar en silencio,
   que es la peor forma de fallar: nadie se entera hasta que hace falta. */
self.addEventListener('pushsubscriptionchange', (evento) => {
  evento.waitUntil((async () => {
    try {
      const nueva = await self.registration.pushManager.subscribe(
        evento.oldSubscription?.options || { userVisibleOnly: true });
      const j = nueva.toJSON();
      const ventanas = await clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const v of ventanas) {
        v.postMessage({ tipo: 'push-renovado', destino: j.endpoint,
                        clave_p256: j.keys.p256dh, clave_auth: j.keys.auth });
      }
    } catch { /* se reintenta al abrir la aplicación */ }
  })());
});
