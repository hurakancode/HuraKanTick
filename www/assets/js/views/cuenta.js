/* Ajustes: cuenta, apariencia, sincronización y servidor. */

import { api, base, fijarBase, hayRed } from '../api.js';
import * as db from '../db.js';
import * as sync from '../sync.js';
import {
  $, $$, icono, esc, mensaje, hoja, confirmar, cerrarHoja, fechaLarga, haceCuanto,
  buscadorFilas, textoPlano,
} from '../ui.js';
import { bloquesAjustesOrdenados } from '../apariencia.js';
import { htmlDeBusqueda, engancharBusqueda } from '../indice-ajustes.js';
import { pintarCabecera, cabeceraPrincipal, ir, app, cerrarSesion, modulo,
         PESTANAS, menuActual, fijarMenu } from '../app.js';
import {
  sonidoActivo, fijarSonido, vibracionActiva, fijarVibracion,
  permisoNotificaciones, tono,
} from '../sonido.js';

export async function vistaCuenta({ contenido }) {
  pintarCabecera(cabeceraPrincipal('Ajustes'));

  const u = app.usuario;
  const nombre = [u.nombre, u.apellido1, u.apellido2].filter(Boolean).join(' ') || u.correo;
  const rol = { admin: 'Administrador', responsable: 'Responsable', tecnico: 'Técnico', reportador: 'Avisos' }[u.rol] || u.rol;

  const pendientes = await db.colaPendiente();
  const errores = await db.colaConError();
  const conflictos = await db.conflictos();
  const guardados = (await db.todos('tickets')).length;
  const desde = await db.ajuste('sync_desde');

  /* La pantalla se monta por bloques: cada persona elige cuáles ve y en
     qué orden desde Apariencia. Cuenta y Apariencia van marcados como
     fijos — sin el primero no podrías cambiar tu contraseña, y sin el
     segundo no podrías volver a esta pantalla a arreglarlo. */
  contenido.innerHTML = `
    <div class="filas" style="margin-bottom:12px">
      <button type="button" class="fila" data-a="salir">
        ${icono('salir')}
        <span class="fila__texto" style="color:var(--peligro)">Cerrar sesión
          <small>Salir de esta cuenta en este aparato</small></span>
        ${icono('flecha', 'fila__flecha')}
      </button>
    </div>
  <div class="tarjeta" style="margin-bottom:16px"><div class="tarjeta__cuerpo" style="display:flex;gap:13px;align-items:center">
    <span class="avatar avatar--g">${esc((u.nombre?.[0] || '') + (u.apellido1?.[0] || ''))}</span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:650;font-size:15.5px">${esc(nombre)}</div>
      <div style="font-size:13px;color:var(--tinta-3)">${esc(u.correo)}</div>
      <div class="rotulo" style="margin-top:3px">${esc(rol)}${u.departamento ? ' · ' + esc(u.departamento) : ''}</div>
    </div>
  </div></div>
  <div class="seccion" data-bloque="sync"><h2>Sincronización</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="sincronizar">
      ${icono('sync')}
      <span class="fila__texto">Sincronizar ahora
        <small>${desde ? 'Última vez: ' + esc(haceCuanto(desde)) : 'Todavía no se ha sincronizado'}</small></span>
      <span class="fila__valor">${hayRed() ? 'En línea' : 'Sin conexión'}</span>
    </button>

    <button type="button" class="fila" data-a="descargar-todo">
      ${icono('descarga')}
      <span class="fila__texto">Volver a descargar todo
        <small>Vacía lo guardado en este aparato y lo baja de nuevo del servidor</small></span>
    </button>

    <button type="button" class="fila" data-a="cola">
      ${icono('nube')}
      <span class="fila__texto">Cambios por enviar
        <small>${pendientes.length ? 'Se enviarán en cuanto haya conexión' : 'No queda nada pendiente'}</small></span>
      <span class="fila__valor" ${pendientes.length ? 'style="color:var(--aviso);font-weight:700"' : ''}>${pendientes.length}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>

    ${conflictos.length ? `
    <button type="button" class="fila" data-a="conflictos">
      ${icono('aviso')}
      <span class="fila__texto" style="color:var(--peligro)">Cambios pisados por otra persona
        <small>Revísalos y decide qué versión vale</small></span>
      <span class="fila__valor" style="color:var(--peligro);font-weight:700">${conflictos.length}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}

    ${errores.length ? `
    <button type="button" class="fila" data-a="errores">
      ${icono('aviso')}
      <span class="fila__texto" style="color:var(--peligro)">Envíos con problemas
        <small>Revísalos: no se han podido guardar en el servidor</small></span>
      <span class="fila__valor" style="color:var(--peligro);font-weight:700">${Math.max(0, errores.length - conflictos.length)}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}

    <div class="fila" style="cursor:default">
      ${icono('archivo')}
      <span class="fila__texto">Partes guardados en el móvil
        <small>Disponibles sin conexión</small></span>
      <span class="fila__valor">${guardados}</span>
    </div>
  </div>
  <div class="seccion" data-bloque="avisos"><h2>Avisos</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="avisos-ajustes">
      ${icono('campana')}
      <span class="fila__texto">Sonido, vibración y avisos
        <small>Qué llega, qué suena y en qué horario</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>
  <div class="seccion" data-bloque="apariencia"><h2>Apariencia</h2></div>
  <div class="filas">
    ${app.usuario.es_cadena ? `
    <button type="button" class="fila" data-a="hoteles">
      ${icono('panel')}
      <span class="fila__texto">Hoteles
        <small>Crear, cambiar de hotel y copiar ubicaciones</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    <button type="button" class="fila" data-a="apariencia">
      ${icono('luna')}
      <span class="fila__texto">Apariencia
        <small>Letra, espacio, bloques del inicio</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="menu">
      ${icono('panel')}
      <span class="fila__texto">Barra de abajo
        <small>${esc(menuActual().map((k) => PESTANAS[k]?.texto).filter(Boolean).join(' · '))}</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="tema">
      ${icono('luna')}
      <span class="fila__texto">Tema<small>Claro, oscuro o el del sistema</small></span>
      <span class="fila__valor">${esc(nombreTema())}</span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>
  <div class="seccion" data-bloque="cuenta"><h2>Cuenta</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="perfil">
      ${icono('persona')}
      <span class="fila__texto">Mis datos<small>Nombre y teléfono</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="correo">
      ${icono('nota')}
      <span class="fila__texto">Correo
        <small>${esc(app.usuario.correo)}</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="password">
      ${icono('llave')}
      <span class="fila__texto">Cambiar la contraseña<small>Se cerrarán las demás sesiones</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="sesiones">
      ${icono('movil')}
      <span class="fila__texto">Aparatos con la sesión abierta
        <small>Cierra el de un móvil perdido sin tocar los demás</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <button type="button" class="fila" data-a="guia">
      ${icono('libro')}
      <span class="fila__texto">Guía de uso
        <small>Cómo se hace cada cosa, con ejemplos y capturas</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>

  ${modulo('informes') || modulo('mantenimiento') ? `
  <div class="seccion" data-bloque="consultar"><h2>Consultar</h2></div>
  <div class="filas">
    ${modulo('unidades') ? `
    <button type="button" class="fila" data-a="unidades">
      ${icono('herramientas')}
      <span class="fila__texto">Números de serie
        <small>Localizar un aparato y ver su historia</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    ${modulo('instrucciones') ? `
    <button type="button" class="fila" data-a="instrucciones">
      ${icono('nota')}
      <span class="fila__texto">Instrucciones
        <small>Cómo se hacen las cosas · se leen sin cobertura</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}

    ${(app.catalogo?.modulos_menu || []).map((m) => `
    <button type="button" class="fila" data-modulo="${esc(m.id)}">
      ${icono(m.icono || 'enlace')}
      <span class="fila__texto">${esc(m.titulo)}
        <small>${esc(m.detalle || '')}</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>`).join('')}

    <button type="button" class="fila" data-a="conversaciones">
      ${icono('chat')}
      <span class="fila__texto">Conversaciones
        <small>Lo que se ha hablado en los partes</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>

    ${modulo('informes') ? `
    <button type="button" class="fila" data-a="informes">
      ${icono('panel')}
      <span class="fila__texto">Informes
        <small>Cuántas averías, dónde y de qué tipo</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    ${modulo('mantenimiento') && app.usuario.es_responsable ? `
    <button type="button" class="fila" data-a="mantenimiento">
      ${icono('calendario')}
      <span class="fila__texto">Mantenimiento programado
        <small>Revisiones que se repiten y su historial</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
  </div>` : ''}

  ${app.usuario.es_admin ? `
  <div class="seccion" data-bloque="admin"><h2>Administración</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="admin">
      ${icono('ajustes')}
      <span class="fila__texto">Administrar el sistema
        <small>Funciones activas, campos, atajos, equipo y copias</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    ${app.usuario.es_superadmin ? `
    <button type="button" class="fila" data-a="papelera">
      ${icono('papelera')}
      <span class="fila__texto">Papelera de reciclaje
        <small>Todo lo que se ha borrado del sistema</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    ${app.usuario.es_admin ? `
    <button type="button" class="fila" data-a="frases">
      ${icono('libro')}
      <span class="fila__texto">Frases del día
        <small>Las frases que reciben quienes las tengan activadas</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    ${app.usuario.es_admin ? `
    <button type="button" class="fila" data-a="iconografia">
      ${icono('brillo')}
      <span class="fila__texto">Iconografía
        <small>Cambiar los iconos de la aplicación por otros de Font Awesome</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>` : ''}
    <button type="button" class="fila" data-a="gestion-partes">
      ${icono('papelera')}
      <span class="fila__texto">Gestión de partes
        <small>Buscar, mover y eliminar partes en bloque</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>` : (app.usuario.es_responsable ? `
  <div class="seccion" data-bloque="equipo"><h2>Equipo</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="equipo">
      ${icono('personas')}
      <span class="fila__texto">Ver el equipo<small>Quién tiene qué asignado</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>` : '')}
  <div class="seccion" data-bloque="servidor"><h2>Servidor</h2></div>
  <div class="filas">
    <button type="button" class="fila" data-a="servidor">
      ${icono('nube')}
      <span class="fila__texto">Dirección<small style="word-break:break-all">${esc(base())}</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
    <div class="fila" style="cursor:default">
      ${icono('info')}
      <span class="fila__texto">Versión
        <small>Compárala con la del servidor si algo no cuadra</small></span>
      <span class="fila__valor" style="font-family:var(--mono)">
        ${esc(document.querySelector('meta[name=hurakan-tick-version]')?.content || '?')}</span>
    </div>
    <button type="button" class="fila" data-a="diagnostico">
      ${icono('info')}
      <span class="fila__texto">Comprobar conexión<small>Estado del servidor y de la base de datos</small></span>
      ${icono('flecha', 'fila__flecha')}
    </button>
  </div>

  <div style="margin:22px 0 8px">
    <button type="button" class="btn btn--peligro btn--bloque" data-a="salir">${icono('salir')}Cerrar sesión</button>
  </div>
  <div class="rotulo" style="text-align:center;padding:8px 0 20px">
    HuraKan Tick ${esc(document.querySelector('meta[name=hurakan-tick-version]')?.content || '')}
    · <a href="https://hurakancode.com/HuraKanTick" target="_blank" rel="noopener">hurakancode.com/HuraKanTick</a>
  </div>`;


  /**
   * Reordena y oculta las secciones según lo que haya elegido esta
   * persona en Apariencia.
   *
   * Se hace sobre el DOM ya pintado y no partiendo la plantilla: aquí
   * hay secciones que viven dentro de condiciones —«sólo si eres
   * responsable», «sólo si los informes están activos»— y trocear el
   * texto por sus encabezados rompía esas condiciones por la mitad.
   *
   * Cada sección son su encabezado y todo lo que le sigue hasta el
   * siguiente encabezado.
   */
  (function ordenarSecciones() {
    const cabeceras = [...contenido.querySelectorAll('[data-bloque]')];
    if (!cabeceras.length) return;

    const grupos = new Map();
    for (const cab of cabeceras) {
      const piezas = [cab];
      let x = cab.nextElementSibling;
      while (x && !x.hasAttribute('data-bloque')) {
        piezas.push(x);
        x = x.nextElementSibling;
      }
      grupos.set(cab.dataset.bloque, piezas);
    }

    // Lo que va antes de la primera sección (la tarjeta de la persona)
    // se queda donde está: no es un bloque movible.
    const orden = bloquesAjustesOrdenados().map((b) => b.id);
    const visibles = new Set(orden);

    for (const [id, piezas] of grupos) {
      if (!visibles.has(id)) piezas.forEach((el) => el.remove());
    }
    for (const id of orden) {
      const piezas = grupos.get(id);
      if (piezas) piezas.forEach((el) => contenido.appendChild(el));
    }
  }());

  /* ---------------- El buscador de Ajustes ----------------
     Busca en esta pantalla y, además, DENTRO de Configuración y de
     Apariencia: escribir «smartphone» lleva a la opción aunque viva en
     otra pantalla. Sin esto, lo que está al fondo de Configuración no lo
     encontraba nadie. */
  buscadorFilas(contenido, {
    placeholder: 'Buscar en los ajustes',
    // El índice es compartido con Configuración y Administración.
    extra: (q) => htmlDeBusqueda(q, { esAdmin: !!app.usuario?.es_admin }),
  });
  engancharBusqueda(contenido, ir);

  /* Si se llega desde el aviso de «envíos con problemas», se abre la
     lista directamente en vez de dejar a nadie buscándola. */
  try {
    if (sessionStorage.getItem('hurakantick_ver_errores')) {
      sessionStorage.removeItem('hurakantick_ver_errores');
      setTimeout(verErrores, 250);
    }
  } catch { /* sin sessionStorage no pasa nada */ }

  /* Si se llega buscando algo que vive AQUÍ —«mis datos», «cambiar la
     contraseña», «aparatos con la sesión abierta»—, se abre solo.
     Antes esas entradas del buscador apuntaban a /mis-datos y /sesiones,
     que no son pantallas: el enrutador no las reconocía y mandaba al
     inicio, callado. Se buscaba, se pulsaba, y aparecías en la portada. */
  try {
    const aqui = sessionStorage.getItem('hurakantick_ir_ajuste');
    if (aqui) {
      const fila = contenido.querySelector(`[data-a="${CSS.escape(aqui)}"]`);
      if (fila) {
        sessionStorage.removeItem('hurakantick_ir_ajuste');
        setTimeout(() => {
          fila.scrollIntoView({ block: 'center' });
          fila.classList.add('fila--resaltada');
          fila.click();
        }, 180);
      }
      // Si no es de aquí se deja puesto: lo recogerá la pantalla que sea.
    }
  } catch { /* sin sessionStorage se queda arriba */ }

  contenido.addEventListener('click', (e) => {
    const a = e.target.closest('[data-ir-ajuste]');
    if (a) {
      try { sessionStorage.setItem('hurakantick_ir_ajuste', a.dataset.irAjuste); } catch { /* da igual */ }
      ir('/admin/ajustes');
      return;
    }
    const r = e.target.closest('[data-ir-ruta]');
    if (r) ir(r.dataset.irRuta);
  });


  contenido.onclick = async (e) => {
    /* Los módulos opcionales enseñan sus entradas en una hoja. No se
       enlazan a mano porque el núcleo no sabe —ni tiene por qué saber—
       qué pantallas trae cada módulo. */
    const mod = e.target.closest('[data-modulo]');
    if (mod) {
      const ficha = (app.catalogo?.modulos_menu || [])
        .find((x) => String(x.id) === mod.dataset.modulo);
      if (!ficha) return;
      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `<div class="filas">${(ficha.entradas || []).map((x) => `
        <button type="button" class="fila" data-ir-modulo="${esc(x.ruta)}">
          ${icono(x.icono || 'flecha')}
          <span class="fila__texto">${esc(x.titulo)}
            <small>${esc(x.detalle || '')}</small></span>
          ${icono('flecha', 'fila__flecha')}
        </button>`).join('')}</div>`;
      hoja({
        titulo: ficha.titulo,
        cuerpo,
        alAbrir: (h) => {
          h.onclick = (ev) => {
            const ir2 = ev.target.closest('[data-ir-modulo]');
            if (!ir2) return;
            cerrarHoja();
            ir(ir2.dataset.irModulo);
          };
        },
      });
      return;
    }

    const b = e.target.closest('[data-a]');
    if (!b) return;

    switch (b.dataset.a) {
      case 'sincronizar': {
        b.style.opacity = '.6';
        const r = await sync.sincronizar({ forzar: true });
        b.style.opacity = '';
        if (r?.sinRed) mensaje('Sigue sin haber conexión con el servidor.', 'error');
        else { mensaje('Sincronizado.', 'ok'); app.refrescar(); }
        break;
      }
      case 'descargar-todo': {
        /* Para cuando el móvil enseña algo que ya no está en el servidor.
           La aplicación lo detecta sola si el servidor se reinstala, pero
           este botón deja hacerlo a mano sin cerrar el navegador. */
        if (!hayRed()) { mensaje('Hace falta conexión con el servidor.', 'error'); break; }
        const ok = await confirmar({
          titulo: 'Volver a descargar todo',
          texto: 'Se vacía lo que este aparato tiene guardado y se baja de nuevo del servidor. '
               + 'No se pierde nada del servidor.',
          aceptar: 'Descargar',
        });
        if (!ok) break;
        b.style.opacity = '.6';
        try {
          const n = await sync.descargarTodo();
          mensaje(`Descargados ${n} parte${n === 1 ? '' : 's'}.`, 'ok');
          app.refrescar();
        } catch (e) {
          mensaje(e.message, 'error');
        }
        b.style.opacity = '';
        break;
      }
      case 'cola':    verCola(); break;
      case 'conflictos': ir('/conflictos'); break;

      case 'sonido': {
        const nuevo = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', String(nuevo));
        fijarSonido(nuevo);
        break;
      }
      case 'vibrar': {
        const nuevo = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', String(nuevo));
        fijarVibracion(nuevo);
        break;
      }
      case 'probar-sonido':
        if (!sonidoActivo()) { mensaje('El sonido está desactivado.', 'error'); break; }
        tono('asignado');
        mensaje('Así suena un parte asignado.', 'ok');
        break;

      case 'permiso-avisos': {
        const r = await permisoNotificaciones();
        if (r === 'granted') { mensaje('Avisos del sistema activados.', 'ok'); app.refrescar(); }
        else if (r === 'denied') {
          mensaje('El navegador los tiene bloqueados: actívalos en sus ajustes de sitio.', 'error');
        } else if (r === 'no-soportado') {
          mensaje('Este navegador no admite avisos del sistema.', 'error');
        }
        break;
      }
      case 'errores': verErrores(); break;
      case 'tema':    elegirTema(); break;
      case 'menu':    elegirMenu(); break;
      case 'apariencia': ir('/apariencia'); break;
      case 'hoteles': ir('/hoteles'); break;
      case 'perfil':  editarPerfil(); break;
      case 'password':cambiarPassword(); break;
      case 'sesiones': verSesiones(); break;
      case 'guia': location.href = 'guia.html'; break;
      case 'equipo':   ir('/equipo'); break;
      case 'admin':    ir('/admin'); break;
      case 'iconografia': ir('/iconografia'); break;
      case 'frases':      ir('/frases'); break;
      case 'gestion-partes': ir('/gestion-partes'); break;
      case 'papelera': ir('/papelera'); break;
      case 'conversaciones': ir('/conversaciones'); break;
      case 'informes': ir('/informes'); break;
      case 'instrucciones': ir('/instrucciones'); break;
      case 'unidades': ir('/unidades'); break;
      case 'mantenimiento': ir('/mantenimiento'); break;
      case 'avisos-ajustes': ir('/avisos-ajustes'); break;
      case 'correo': cambiarCorreo(); break;
      case 'servidor':configurarServidor(); break;
      case 'diagnostico': diagnostico(); break;
      case 'salir': {
        const pend = (await db.colaPendiente()).length;
        const ok = await confirmar({
          titulo: 'Cerrar sesión',
          texto: pend
            ? `Quedan ${pend} cambios sin enviar. Si cierras sesión se perderán. Conéctate y sincroniza antes de salir.`
            : 'Se borrarán del móvil los partes descargados. Podrás volver a entrar cuando quieras.',
          aceptar: 'Cerrar sesión',
          peligro: true,
        });
        if (ok) cerrarSesion();
        break;
      }
    }
  };

  return {};
}

/* ---------------------------------------------------------------- */
/** Cada persona decide qué avisos quiere, partiendo de lo que fijó
 *  el administrador. Se guarda sólo lo que se aparta del valor común. */
async function ajustarAvisos() {
  let lista = [];
  try { lista = (await api.avisosPref()).avisos; }
  catch (e) { mensaje(e.message, 'error'); return; }

  const cuerpo = document.createElement('div');
  const pintar = () => {
    cuerpo.innerHTML = `
      <p class="campo__ayuda" style="margin-bottom:12px">
        Los que no toques siguen el criterio del administrador.
      </p>
      <div class="filas">
        ${lista.map((a) => `
          <div class="fila" style="cursor:default">
            ${icono('campana')}
            <span class="fila__texto">${esc(a.titulo)}
              <small>${a.personalizado ? 'Ajustado por ti' : 'Por defecto: ' + (a.por_defecto ? 'sí' : 'no')}</small></span>
            <button type="button" class="interruptor" data-ev="${esc(a.evento)}" role="switch"
                    aria-checked="${a.activo}"></button>
          </div>`).join('')}
      </div>
      <button type="button" class="btn btn--contorno btn--bloque btn--s" data-defecto style="margin-top:14px">
        ${icono('sync')}Volver a los valores del administrador
      </button>`;

    cuerpo.querySelectorAll('[data-ev]').forEach((b) => {
      b.onclick = async () => {
        const nuevo = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', String(nuevo));
        try {
          lista = (await api.fijarAviso({ evento: b.dataset.ev, activo: nuevo })).avisos;
          tono('ok');
        } catch (e) { mensaje(e.message, 'error'); }
      };
    });

    cuerpo.querySelector('[data-defecto]').onclick = async () => {
      try {
        lista = (await api.avisosDefecto()).avisos;
        pintar();
        mensaje('Avisos restaurados.', 'ok');
      } catch (e) { mensaje(e.message, 'error'); }
    };
  };
  pintar();
  hoja({ titulo: 'Qué avisos quiero', cuerpo });
}

/**
 * Cambio del propio correo.
 *
 * Pide la contraseña porque el correo es con lo que se entra: cambiarlo
 * sin confirmar la identidad sería regalar la cuenta a quien pille el
 * móvil desbloqueado.
 */
async function cambiarCorreo() {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="c-actual">Ahora</label>
      <input class="entrada" id="c-actual" value="${esc(app.usuario.correo)}" disabled></div>
    <div class="campo"><label for="c-nuevo">Correo nuevo</label>
      <input class="entrada" id="c-nuevo" type="email" autocomplete="email"
             inputmode="email" placeholder="nombre@hotel.com"></div>
    <div class="campo"><label for="c-pwd">Tu contraseña</label>
      <input class="entrada" id="c-pwd" type="password" autocomplete="current-password">
      <div class="campo__ayuda">Se pide para confirmar que eres tú.</div></div>
    <div id="c-err"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Cambiar</button>`;

  hoja({
    titulo: 'Cambiar el correo', cuerpo, pie,
    alAbrir: (h) => {
      setTimeout(() => $('#c-nuevo', h).focus(), 250);
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = async () => {
        const correo = $('#c-nuevo', h).value.trim();
        const password = $('#c-pwd', h).value;
        const err = (t) => { $('#c-err', h).innerHTML =
          `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };
        if (!correo || !password) return err('Rellena los dos campos.');
        try {
          const r = await api.cambiarCorreo({ correo, password });
          app.usuario.correo = r.correo;
          cerrarHoja();
          tono('ok');
          mensaje('Correo actualizado. Úsalo la próxima vez que entres.', 'ok');
          app.refrescar();
        } catch (e) { err(e.message); }
      };
    },
  });
}

function estadoPermiso() {
  if (!('Notification' in window)) return 'No disponible';
  return { granted: 'Activados', denied: 'Bloqueados', default: 'Sin activar' }[Notification.permission] || '—';
}

function nombreTema() {
  const t = localStorage.getItem('hurakantick_tema');
  return t === 'oscuro' ? 'Oscuro' : t === 'claro' ? 'Claro' : 'Automático';
}

/**
 * Qué cuatro pestañas van en la barra de abajo.
 *
 * El botón de alta no se toca: es la acción que justifica la aplicación.
 * Las otras cuatro sí, porque no todos los puestos necesitan lo mismo —
 * recepción no tiene partes asignados y «Míos» le sobra.
 */
function elegirMenu() {
  let elegidas = [...menuActual()];

  const cuerpo = document.createElement('div');

  const pintar = () => {
    cuerpo.innerHTML = `
      <p class="campo__ayuda" style="margin-bottom:12px">
        Elige cuatro. El botón de nuevo parte va siempre en el centro.
      </p>

      <div class="rotulo" style="margin:0 2px 8px">Tu barra quedaría así</div>
      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px;
                  background:var(--superficie);border:1px solid var(--borde);
                  border-radius:var(--r-m);padding:8px 4px;margin-bottom:18px">
        ${['a', 'b', 'mas', 'c', 'd'].map((pos, idx) => {
          if (pos === 'mas') {
            return `<div style="display:grid;place-items:center">
               <span style="display:grid;place-items:center;width:38px;height:38px;
                            background:var(--marca);color:#fff;border-radius:50%">
                 ${icono('mas')}</span></div>`;
          }
          // Los huecos sin rellenar se dibujan vacíos: mientras se
          // reordena hay momentos con menos de cuatro elegidas.
          const k = elegidas[idx > 2 ? idx - 1 : idx];
          const p2 = k ? PESTANAS[k] : null;
          if (!p2) {
            return `<div style="display:grid;place-items:center;height:36px">
               <span style="width:22px;height:22px;border:1.5px dashed var(--borde);
                            border-radius:6px"></span></div>`;
          }
          return `<div style="display:flex;flex-direction:column;align-items:center;gap:3px;
                         color:var(--tinta-2);font-size:10px;font-weight:600">
               ${icono(p2.icono)}<span>${esc(p2.texto)}</span></div>`;
        }).join('')}
      </div>

      <div class="filas">
        ${Object.entries(PESTANAS).map(([k, p]) => {
          const puesta = elegidas.includes(k);
          const pos = elegidas.indexOf(k);
          return `
            <button type="button" class="fila" data-p="${k}" ${puesta ? 'style="background:var(--marca-tenue)"' : ''}>
              ${icono(p.icono)}
              <span class="fila__texto">${esc(p.texto)}
                <small>${esc(p.ruta)}</small></span>
              ${puesta ? `<span class="fila__valor" style="color:var(--marca);font-weight:700">
                ${pos + 1}º</span>` : ''}
            </button>`;
        }).join('')}
      </div>

      <button type="button" class="btn btn--contorno btn--bloque btn--s" data-defecto style="margin-top:14px">
        ${icono('sync')}Volver a la de mi puesto
      </button>`;

    cuerpo.querySelectorAll('[data-p]').forEach((b) => {
      b.onclick = () => {
        const k = b.dataset.p;
        if (elegidas.includes(k)) {
          if (elegidas.length <= 1) {
            mensaje('Deja al menos una.', 'error');
            return;
          }
          elegidas = elegidas.filter((x) => x !== k);
        } else {
          if (elegidas.length >= 4) {
            mensaje('Son cuatro. Quita una antes de añadir otra.', 'error');
            return;
          }
          elegidas.push(k);
        }
        pintar();
      };
    });

    cuerpo.querySelector('[data-defecto]').onclick = () => {
      fijarMenu(null);
      cerrarHoja();
      mensaje('Barra restaurada.', 'ok');
      app.refrescar();
    };
  };

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  pintar();
  hoja({
    titulo: 'Barra de abajo',
    cuerpo, pie,
    alAbrir: (h) => {
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = () => {
        if (elegidas.length !== 4) {
          mensaje(`Elige cuatro; llevas ${elegidas.length}.`, 'error');
          return;
        }
        fijarMenu(elegidas);
        cerrarHoja();
        tono('ok');
        mensaje('Barra actualizada.', 'ok');
        app.refrescar();
      };
    },
  });
}

function elegirTema() {
  const opciones = [
    { v: '', texto: 'Automático', detalle: 'Sigue al sistema' },
    { v: 'claro', texto: 'Claro', detalle: 'Mejor a plena luz' },
    { v: 'oscuro', texto: 'Oscuro', detalle: 'Menos deslumbrante de noche' },
  ];
  const actual = localStorage.getItem('hurakantick_tema') || '';
  const lista = document.createElement('div');
  lista.className = 'eleccion';
  lista.innerHTML = opciones.map((o) => `
    <button type="button" class="eleccion__item" data-t="${o.v}" aria-selected="${o.v === actual}">
      ${icono('luna')}<span>${esc(o.texto)}<small>${esc(o.detalle)}</small></span>
    </button>`).join('');
  lista.onclick = (e) => {
    const b = e.target.closest('[data-t]');
    if (!b) return;
    const v = b.dataset.t;
    if (v) { localStorage.setItem('hurakantick_tema', v); document.documentElement.dataset.tema = v; }
    else { localStorage.removeItem('hurakantick_tema'); delete document.documentElement.dataset.tema; }
    cerrarHoja();
    app.refrescar();
  };
  hoja({ titulo: 'Tema', cuerpo: lista });
}

/* ---------------------------------------------------------------- */
async function verCola() {
  const cola = await db.colaPendiente();
  const nombres = {
    'ticket.crear': 'Alta de parte',
    'ticket.actualizar': 'Cambio en un parte',
    'adjunto.subir': 'Fotografía',
  };
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = cola.length
    ? `<div class="filas">${cola.map((o) => `
        <div class="fila" style="cursor:default">
          ${icono(o.tipo === 'adjunto.subir' ? 'imagen' : 'nota')}
          <span class="fila__texto">${esc(nombres[o.tipo] || o.tipo)}
            <small>${esc(o.datos?.titulo || o.datos?.nota || o.datos?.nombre || '—')}</small></span>
          <span class="fila__valor">${esc(haceCuanto(o.creado))}</span>
        </div>`).join('')}</div>`
    : `<div class="aviso aviso--ok">${icono('ok')}<div>No queda nada por enviar.</div></div>`;

  hoja({ titulo: 'Cambios por enviar', cuerpo });
}

async function verErrores() {
  const errores = await db.colaConError();
  const conflictos = await db.conflictos();
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <p style="font-size:13.5px;color:var(--tinta-2);margin-bottom:12px">
      El servidor ha rechazado estos envíos. Puedes reintentarlos o descartarlos.
    </p>
    <div class="filas">${errores.map((o) => {
      /* En castellano y con el parte delante. Antes ponía «ticket.actualizar»
         y el motivo suelto: había que adivinar de qué parte se hablaba y qué
         se había intentado hacer. */
      const qué = {
        'ticket.crear':      'Dar un parte',
        'ticket.actualizar': 'Cambiar un parte',
        'ticket.movimiento': 'Registrar un movimiento',
        'chat.escribir':     'Enviar un mensaje',
        'foto.subir':        'Subir una foto',
      }[o.tipo] || o.tipo;

      const d = o.datos || {};
      const detalles = [
        d.titulo ? `«${d.titulo}»` : '',
        d.id_ticket ? `parte ${d.id_ticket}` : '',
        d.id_estado ? 'cambio de estado' : '',
        d.id_grupo_asignado ? 'cambio de departamento' : '',
        d.id_persona_asignada ? 'cambio de persona' : '',
        d.nota ? 'nota' : '',
        d.texto ? `«${String(d.texto).slice(0, 40)}»` : '',
      ].filter(Boolean).join(' · ');

      return `
      <div class="fila" ${d.id_ticket ? `data-ir-parte="${esc(d.id_ticket)}" role="button" tabindex="0"` : 'style="cursor:default"'}>
        ${icono('aviso')}
        <span class="fila__texto">${esc(qué)}${detalles ? ` · ${esc(detalles)}` : ''}
          <small style="color:var(--peligro)">${esc(o.error || 'Sin detalle')}</small>
          <small style="color:var(--tinta-3)">${esc(haceCuanto(o.creado))}</small></span>
      </div>`;
    }).join('')}</div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `
    <button type="button" class="btn btn--contorno" data-descartar>Descartar</button>
    <button type="button" class="btn btn--principal" data-reintentar>Reintentar</button>`;

  hoja({
    titulo: 'Envíos con problemas',
    cuerpo, pie,
    alAbrir: (h) => {
      h.onclick = (e) => {
        const p = e.target.closest('[data-ir-parte]');
        if (p) { cerrarHoja(); ir('/ticket/' + p.dataset.irParte); }
      };
      $('[data-reintentar]', h).onclick = async () => {
        await sync.reintentarErrores();
        cerrarHoja();
        mensaje('Reintentando…', 'ok');
        app.refrescar();
      };
      $('[data-descartar]', h).onclick = async () => {
        const ok = await confirmar({
          titulo: 'Descartar envíos',
          texto: 'Los cambios que no llegaron al servidor se perderán. Esto no se puede deshacer.',
          aceptar: 'Descartar', peligro: true,
        });
        if (!ok) return;
        await sync.descartarErrores();
        cerrarHoja();
        app.refrescar();
      };
    },
  });
}

/* ---------------------------------------------------------------- */
function editarPerfil() {
  const u = app.usuario;
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="p-nombre">Nombre</label>
      <input class="entrada" id="p-nombre" value="${esc(u.nombre || '')}"></div>
    <div class="campo"><label for="p-ap1">Primer apellido</label>
      <input class="entrada" id="p-ap1" value="${esc(u.apellido1 || '')}"></div>
    <div class="campo"><label for="p-ap2">Segundo apellido</label>
      <input class="entrada" id="p-ap2" value="${esc(u.apellido2 || '')}"></div>
    <div class="campo"><label for="p-tel">Teléfono</label>
      <input class="entrada" id="p-tel" type="tel" inputmode="tel" value="${esc(u.telefono || '')}"></div>
    <div id="err-perfil"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  hoja({
    titulo: 'Mis datos', cuerpo, pie,
    alAbrir: (h) => {
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = async () => {
        if (!hayRed()) {
          $('#err-perfil', h).innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}<div>Para cambiar tus datos hace falta conexión.</div></div>`;
          return;
        }
        try {
          const datos = {
            nombre: $('#p-nombre', h).value.trim(),
            apellido1: $('#p-ap1', h).value.trim(),
            apellido2: $('#p-ap2', h).value.trim(),
            telefono: $('#p-tel', h).value.trim(),
          };
          await api.editarUsuario(u.id_login, datos);
          Object.assign(app.usuario, datos);
          localStorage.setItem('hurakantick_usuario', JSON.stringify(app.usuario));
          cerrarHoja();
          mensaje('Datos guardados.', 'ok');
          app.refrescar();
        } catch (e) {
          $('#err-perfil', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
        }
      };
    },
  });
}

/**
 * Los aparatos con la sesión abierta.
 *
 * Un móvil perdido es lo que más prisa corre. Hasta ahora sólo se podía
 * «cerrar todas las demás», que obliga a volver a entrar en todos los
 * sitios por haber perdido uno.
 */
async function verSesiones() {
  let datos;
  try { datos = await api.sesiones(); }
  catch (e) { mensaje(e.message, 'error'); return; }

  const vivas = (datos.sesiones || []).filter((x) => !Number(x.revocado));

  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo__ayuda" style="margin:0 2px 12px">
      Cada aparato donde has entrado abre una sesión. Si pierdes uno, ciérralo
      aquí y ese teléfono dejará de tener acceso.
    </div>
    <div class="filas">
      ${vivas.map((x) => `
        <div class="fila" style="cursor:default">
          ${icono('movil')}
          <span class="fila__texto">${esc(x.dispositivo || 'aparato')}
            <small>${esc(x.plataforma || '')} · usado ${esc(haceCuanto(x.ultimo_uso))}</small></span>
          <button type="button" class="btn btn--contorno btn--s" data-cerrar-s="${x.id_token}">Cerrar</button>
        </div>`).join('')}
    </div>
    ${vivas.length > 1 ? `
      <button type="button" class="btn btn--peligro btn--bloque" data-cerrar-todas style="margin-top:14px">
        Cerrar todas menos esta
      </button>` : ''}`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = '<button type="button" class="btn btn--principal" data-no>Cerrar</button>';

  hoja({
    titulo: 'Aparatos con sesión',
    cuerpo, pie,
    alAbrir: (h) => {
      $('[data-no]', h).onclick = () => cerrarHoja();

      $$('[data-cerrar-s]', h).forEach((b) => {
        b.onclick = async () => {
          b.disabled = true;
          try {
            const r = await api.cerrarSesion(parseInt(b.dataset.cerrarS, 10));
            cerrarHoja();
            mensaje(r.mensaje, 'ok');
          } catch (e) { b.disabled = false; mensaje(e.message, 'error'); }
        };
      });

      const bt = $('[data-cerrar-todas]', h);
      if (bt) bt.onclick = async () => {
        const ok = await confirmar({
          titulo: 'Cerrar las demás sesiones',
          texto: 'Todos los aparatos menos este tendrán que volver a entrar.',
          aceptar: 'Cerrarlas',
        });
        if (!ok) return;
        try {
          const r = await api.cerrarOtrasSesiones();
          cerrarHoja();
          mensaje(r.mensaje, 'ok');
        } catch (e) { mensaje(e.message, 'error'); }
      };
    },
  });
}

function cambiarPassword() {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo"><label for="c-act">Contraseña actual</label>
      <input class="entrada" type="password" id="c-act" autocomplete="current-password"></div>
    <div class="campo"><label for="c-new">Nueva contraseña</label>
      <input class="entrada" type="password" id="c-new" autocomplete="new-password">
      <div class="campo__ayuda">Mínimo 8 caracteres.</div></div>
    <div class="campo"><label for="c-rep">Repite la nueva</label>
      <input class="entrada" type="password" id="c-rep" autocomplete="new-password"></div>
    <div id="err-pwd"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Cambiar</button>`;

  hoja({
    titulo: 'Cambiar la contraseña', cuerpo, pie,
    alAbrir: (h) => {
      const err = (t) => { $('#err-pwd', h).innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = async () => {
        const a = $('#c-act', h).value, n = $('#c-new', h).value, r = $('#c-rep', h).value;
        if (!a || !n) return err('Rellena los dos campos de contraseña.');
        if (n.length < 8) return err('La nueva contraseña debe tener al menos 8 caracteres.');
        if (n !== r) return err('Las dos contraseñas nuevas no coinciden.');
        if (!hayRed()) return err('Para cambiar la contraseña hace falta conexión.');
        try {
          await api.password(a, n);
          cerrarHoja();
          mensaje('Contraseña actualizada.', 'ok');
        } catch (e) { err(e.message); }
      };
    },
  });
}

/* ---------------------------------------------------------------- */
function configurarServidor() {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <div class="campo">
      <label for="s-url">Dirección del servidor</label>
      <input class="entrada" type="url" id="s-url" inputmode="url" value="${esc(base())}"
             placeholder="https://partes.miempresa.com/hurakantick/api">
      <div class="campo__ayuda">Cambiarla cierra la sesión actual.</div>
    </div>`;
  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  hoja({
    titulo: 'Servidor', cuerpo, pie,
    alAbrir: (h) => {
      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = async () => {
        const ok = await confirmar({
          titulo: 'Cambiar de servidor',
          texto: 'Se cerrará la sesión y se borrarán los datos guardados en el móvil.',
          aceptar: 'Cambiar', peligro: true,
        });
        if (!ok) return;
        fijarBase($('#s-url', h).value);
        cerrarHoja();
        setTimeout(() => location.reload(), 200);
      };
    },
  });
}

async function diagnostico() {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `<div class="aviso aviso--info">${icono('sync')}<div>Comprobando…</div></div>`;
  hoja({ titulo: 'Estado del sistema', cuerpo });

  try {
    const r = await api.estado();
    cuerpo.innerHTML = `
      <div class="aviso aviso--ok">${icono('ok')}<div>Conexión correcta con el servidor.</div></div>
      <div class="filas">
        <div class="fila" style="cursor:default"><span class="fila__texto">Servicio</span><span class="fila__valor">${esc(r.servicio)} ${esc(r.version)}</span></div>
        ${r.motor !== undefined ? `
        <div class="fila" style="cursor:default"><span class="fila__texto">Base de datos</span><span class="fila__valor">${esc(r.motor)}${r.instalada ? '' : ' · sin instalar'}</span></div>
        <div class="fila" style="cursor:default"><span class="fila__texto">PHP</span><span class="fila__valor">${esc(r.php)}</span></div>
        <div class="fila" style="cursor:default"><span class="fila__texto">Redimensionado de fotos</span><span class="fila__valor">${r.gd ? 'Disponible' : 'No disponible'}</span></div>
        <div class="fila" style="cursor:default"><span class="fila__texto">Usuarios</span><span class="fila__valor">${r.usuarios}</span></div>` : ''}
        <div class="fila" style="cursor:default"><span class="fila__texto">Hora del servidor</span><span class="fila__valor">${esc(fechaLarga(r.hora))}</span></div>
      </div>`;
  } catch (e) {
    cuerpo.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>
      <strong>No se llega al servidor.</strong><br>${esc(e.message)}<br><br>
      Comprueba la dirección en Ajustes › Servidor y que el dispositivo tenga acceso a la red de la empresa.</div></div>`;
  }
}
