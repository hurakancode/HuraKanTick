/* =====================================================================
   Avisos.

   Todo lo que suena, vibra o aparece, en un solo sitio. Está separado de
   los ajustes generales porque es lo que más se toca: quien trabaja de
   noche quiere silencio, quien está en cocina quiere que suene fuerte, y
   quien sólo reporta no quiere enterarse de cada nota.

   Cada aviso se controla por separado en tres cosas: si llega, si suena
   y si vibra.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, confirmar,
} from '../ui.js';
import { cabeceraVolver, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import {
  sonidoActivo, fijarSonido, vibracionActiva, fijarVibracion,
  permisoNotificaciones, tono, silencio, fijarSilencio, enSilencio,
} from '../sonido.js';

const NOMBRES_TONO = {
  asignado:       'Parte asignado',
  asignado_grupo: 'Parte para el grupo',
  urgente:        'Urgente',
  cambio_estado:  'Cambio de estado',
  nota:           'Nota nueva',
  vencido:        'Fuera de plazo',
  sync:           'Sincronizado',
  conflicto:      'Conflicto',
};

import { hayPush, estadoPush, activarPush, desactivarPush } from '../push.js';

export async function vistaAvisosAjustes({ contenido }) {
  pintarCabecera(cabeceraVolver('Avisos', 'Qué llega, qué suena y cuándo'));
  contenido.innerHTML = bloqueCarga(3);

  let lista = [];
  if (hayRed()) {
    try { lista = (await api.avisosPref()).avisos; }
    catch (e) { mensaje(e.message, 'error'); }
  }

  function pintar() {
    const sil = silencio();
    const ahoraEnSilencio = enSilencio();

    contenido.innerHTML = `
    <!-- Lo primero, porque es lo que decide si un aviso llega de noche
         o espera a que alguien abra la aplicación. -->
    <div class="seccion"><h2>Con la aplicación cerrada</h2></div>
    <div class="filas" style="margin-bottom:12px">
      <button type="button" class="fila" data-probar-todo>
        ${icono('campana')}
        <span class="fila__texto">Probar sonido y notificaciones
          <small>Suena, vibra y manda una notificación de prueba a este aparato</small></span>
      </button>
    </div>

    <div id="push-caja">
      <div class="tarjeta"><div class="tarjeta__cuerpo">
        <div class="rotulo">Comprobando…</div>
      </div></div>
    </div>

      <div class="seccion"><h2>En este aparato</h2></div>
      <div class="filas">
        <div class="fila" style="cursor:default">
          ${icono('campana')}
          <span class="fila__texto">Sonido
            <small>Si lo apagas, nada sonará en este móvil</small></span>
          <button type="button" class="interruptor" data-a="sonido" role="switch"
                  aria-checked="${sonidoActivo()}" aria-label="Sonido"></button>
        </div>
        <div class="fila" style="cursor:default">
          ${icono('aviso')}
          <span class="fila__texto">Vibración
            <small>Útil con el móvil en el bolsillo o en zona ruidosa</small></span>
          <button type="button" class="interruptor" data-a="vibrar" role="switch"
                  aria-checked="${vibracionActiva()}" aria-label="Vibración"></button>
        </div>
        <button type="button" class="fila" data-a="permiso">
          ${icono('campana')}
          <span class="fila__texto">Avisos del sistema
            <small>Para enterarte con la aplicación cerrada</small></span>
          <span class="fila__valor">${esc(estadoPermiso())}</span>
          ${icono('flecha', 'fila__flecha')}
        </button>
      </div>

      <div class="seccion"><h2>Horario de silencio</h2>
        ${ahoraEnSilencio ? '<span class="rotulo" style="color:var(--marca)">activo ahora</span>' : ''}</div>
      <div class="filas">
        <div class="fila" style="cursor:default">
          ${icono('reloj')}
          <span class="fila__texto">No sonar de noche
            <small>Los avisos siguen llegando, pero en silencio</small></span>
          <button type="button" class="interruptor" data-a="silencio" role="switch"
                  aria-checked="${sil.activo}" aria-label="Horario de silencio"></button>
        </div>
        ${sil.activo ? `
        <button type="button" class="fila" data-a="silencio-desde">
          ${icono('reloj')}
          <span class="fila__texto">Desde</span>
          <span class="fila__valor">${esc(sil.desde)}</span>
          ${icono('flecha', 'fila__flecha')}
        </button>
        <button type="button" class="fila" data-a="silencio-hasta">
          ${icono('reloj')}
          <span class="fila__texto">Hasta</span>
          <span class="fila__valor">${esc(sil.hasta)}</span>
          ${icono('flecha', 'fila__flecha')}
        </button>
        <div class="fila" style="cursor:default">
          ${icono('aviso')}
          <span class="fila__texto">Salvo los urgentes
            <small>Una avería urgente suena aunque sea de madrugada</small></span>
          <button type="button" class="interruptor" data-a="silencio-urgentes" role="switch"
                  aria-checked="${sil.salvo_urgentes}"></button>
        </div>` : ''}
      </div>

      ${lista.length ? `
      <div class="seccion"><h2>Qué avisos quiero</h2>
        <span class="rotulo">y cuáles suenan</span></div>
      <div class="filas">
        ${lista.map((a) => `
          <div class="fila" style="cursor:default;align-items:flex-start;padding-top:12px;padding-bottom:12px">
            ${icono(a.activo ? 'campana' : 'info')}
            <span class="fila__texto">${esc(a.titulo)}
              <small>${a.personalizado ? 'Lo has ajustado tú'
                     : 'Por defecto: ' + (a.por_defecto ? 'sí' : 'no')}</small>
              <span style="display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:5px;
                           max-width:100%">
                <button type="button" data-ev-sonido="${esc(a.evento)}"
                        style="display:flex;align-items:center;gap:5px;background:none;border:0;
                               min-height:44px;padding:0 8px;margin-left:-8px;
                               cursor:${a.activo ? 'pointer' : 'default'};
                               font-size:12.5px;font-weight:640;
                               color:${a.activo && a.sonido ? 'var(--marca)' : 'var(--tinta-3)'};
                               opacity:${a.activo ? 1 : .4}">
                  ${icono(a.sonido ? 'campana' : 'info')}${a.sonido ? 'Suena' : 'Silencioso'}
                </button>
                <button type="button" data-ev-probar="${esc(a.evento)}"
                        style="background:none;border:0;min-height:44px;padding:0 8px;
                               cursor:pointer;font-size:12.5px;color:var(--tinta-3);
                               flex:none">Probar</button>
              </span>
            </span>
            <button type="button" class="interruptor" data-ev="${esc(a.evento)}" role="switch"
                    aria-checked="${a.activo}" aria-label="${esc(a.titulo)}"></button>
          </div>`).join('')}
      </div>

      <button type="button" class="btn btn--contorno btn--bloque btn--s" data-a="defecto" style="margin-top:14px">
        ${icono('sync')}Volver a lo que fijó el administrador
      </button>` : `
      <div class="aviso aviso--senal" style="margin-top:16px">${icono('sinred')}<div>
        Los avisos por tipo necesitan conexión para cargarse.</div></div>`}

      <div class="aviso aviso--info" style="margin-top:18px">${icono('info')}<div>
        Los tonos se generan en el propio móvil, así que funcionan sin cobertura
        y no ocupan nada. Cada aviso suena distinto para poder distinguirlo sin
        sacar el móvil del bolsillo.
      </div></div>`;

    enganchar();
    pintarPush();

    const bProbar = contenido.querySelector('[data-probar-todo]');
    if (bProbar) bProbar.onclick = () => probarTodo(bProbar);
  }

  /**
   * El bloque de avisos con la aplicación cerrada.
   *
   * Se pinta aparte y después, porque consulta al navegador y al
   * servidor: bloquear el resto de la pantalla por esto sería peor.
   */
  /* Una prueba de todo a la vez: suena, vibra y sale una notificación de
     verdad. Es la forma de saber si el navegador las deja pasar sin tener
     que esperar a que ocurra algo en el hotel. */
  async function probarTodo(boton) {
    boton.disabled = true;
    const pasos = [];
    try { tono('aviso'); pasos.push('sonido'); } catch { pasos.push('el sonido no ha salido'); }
    try { if (navigator.vibrate) { navigator.vibrate(120); pasos.push('vibración'); } } catch { /* da igual */ }

    const seguro = location.protocol === 'https:'
                || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (!('Notification' in window)) {
      pasos.push('este navegador no tiene notificaciones');
    } else if (!seguro) {
      pasos.push('el navegador bloquea las notificaciones por http: hacen falta https');
    } else if (Notification.permission === 'denied') {
      pasos.push('las has bloqueado para esta página; se permiten desde el candado de la barra de direcciones');
    } else {
      let permiso = Notification.permission;
      if (permiso !== 'granted') { permiso = await Notification.requestPermission(); }
      if (permiso === 'granted') {
        try {
          const reg = navigator.serviceWorker ? await navigator.serviceWorker.ready : null;
          const cuerpo = { body: 'Si ves esto, las notificaciones llegan bien.', tag: 'hurakantick-prueba' };
          if (reg) await reg.showNotification('Prueba de HuraKan Tick', cuerpo);
          else new Notification('Prueba de HuraKan Tick', cuerpo);
          pasos.push('notificación enviada');
        } catch (e) { pasos.push('notificaciones: ' + e.message); }
      } else {
        pasos.push('no se ha dado permiso para las notificaciones');
      }
    }
    mensaje('Prueba · ' + pasos.join(' · '), 'ok');
    boton.disabled = false;
  }

  async function pintarPush() {
    const caja = document.getElementById('push-caja');
    if (!caja) return;

    const e = await estadoPush();

    if (!e.disponible) {
      const esHttp = location.protocol !== 'https:'
                  && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1';
      caja.innerHTML = `
        <div class="aviso aviso--senal">${icono('info')}<div>
          Este aparato no admite avisos con la aplicación cerrada.
          ${esHttp ? '<br><br>La causa más habitual es servir la aplicación por <strong>http://</strong>. Los navegadores exigen <strong>https://</strong> para esto.' : ''}
        </div></div>`;
      return;
    }

    const bloqueado = e.permiso === 'denied';

    caja.innerHTML = `
      <div class="fila" style="cursor:default;align-items:flex-start;border-radius:var(--r-m);
                  border:1.5px solid ${e.activo ? 'var(--ok)' : 'var(--borde)'}">
        ${icono(e.activo ? 'ok' : 'campana')}
        <span class="fila__texto">Recibir avisos con la aplicación cerrada
          <small>${e.activo
            ? 'Activado. Los avisos llegan aunque la cierres del todo.'
            : 'Ahora los avisos sólo llegan con la aplicación abierta.'}</small></span>
        <button type="button" class="interruptor" id="push-int" role="switch"
                aria-checked="${e.activo}" aria-label="Avisos en segundo plano"></button>
      </div>

      ${bloqueado && !e.activo ? `
        <div class="aviso aviso--senal" style="margin-top:10px">${icono('aviso')}<div>
          Has bloqueado los avisos para esta página. Hay que permitirlos en los
          ajustes del navegador antes de poder activarlos aquí.
        </div></div>` : ''}

      ${e.activo ? `
        <button type="button" class="btn btn--contorno btn--bloque btn--s" id="push-probar" style="margin-top:10px">
          ${icono('campana')}Mandarme un aviso de prueba
        </button>
        <div class="campo__ayuda" style="text-align:center;margin-top:6px">
          Ciérrala del todo y espera unos segundos.
        </div>` : ''}

      <div id="push-msg"></div>`;

    const int = document.getElementById('push-int');
    if (int) int.onclick = async () => {
      int.disabled = true;
      const msg = document.getElementById('push-msg');
      msg.innerHTML = '<div class="rotulo" style="margin-top:10px">Un momento…</div>';
      const r = e.activo ? await desactivarPush() : await activarPush();
      msg.innerHTML = `<div class="aviso aviso--${r.ok ? 'ok' : 'error'}" style="margin-top:10px">
          ${icono(r.ok ? 'ok' : 'aviso')}<div>${esc(r.motivo)}</div></div>`;
      if (r.ok) { tono('ok'); setTimeout(pintarPush, 1800); }
      int.disabled = false;
    };

    const pr = document.getElementById('push-probar');
    if (pr) pr.onclick = async () => {
      try {
        const r = await api.pushProbar();
        mensaje(r.mensaje, r.enviados ? 'ok' : 'error');
      } catch (err) { mensaje(err.message, 'error'); }
    };
  }

  function enganchar() {
    const conmutar = (b, fn) => {
      const nuevo = b.getAttribute('aria-checked') !== 'true';
      b.setAttribute('aria-checked', String(nuevo));
      fn(nuevo);
    };

    $$('[data-a]', contenido).forEach((b) => {
      b.onclick = async () => {
        switch (b.dataset.a) {
          case 'sonido':  conmutar(b, fijarSonido); break;
          case 'vibrar':  conmutar(b, fijarVibracion); break;

          case 'silencio':
            conmutar(b, (v) => { fijarSilencio({ activo: v }); pintar(); });
            break;

          case 'silencio-desde':
          case 'silencio-hasta': {
            const cual = b.dataset.a.endsWith('desde') ? 'desde' : 'hasta';
            const v = await pedirHora(
              cual === 'desde' ? 'Empieza el silencio' : 'Termina el silencio',
              silencio()[cual]);
            if (v) { fijarSilencio({ [cual]: v }); pintar(); }
            break;
          }

          case 'silencio-urgentes':
            conmutar(b, (v) => fijarSilencio({ salvo_urgentes: v }));
            break;

          case 'permiso': {
            const r = await permisoNotificaciones();
            if (r === 'granted') { mensaje('Avisos del sistema activados.', 'ok'); pintar(); }
            else if (r === 'denied') {
              mensaje('El navegador los tiene bloqueados: actívalos en sus ajustes de sitio.', 'error');
            } else if (r === 'no-soportado') {
              mensaje('Este navegador no admite avisos del sistema.', 'error');
            }
            break;
          }

          case 'defecto': {
            const ok = await confirmar({
              titulo: 'Volver a los valores por defecto',
              texto: 'Se descartan tus ajustes de qué avisos recibir y volverás al criterio del administrador.',
              aceptar: 'Restaurar',
            });
            if (!ok) return;
            try {
              lista = (await api.avisosDefecto()).avisos;
              pintar();
              mensaje('Avisos restaurados.', 'ok');
            } catch (e) { mensaje(e.message, 'error'); }
            break;
          }
        }
      };
    });

    // Activar o desactivar un tipo de aviso
    $$('[data-ev]', contenido).forEach((b) => {
      b.onclick = async () => {
        const evento = b.dataset.ev;
        const a = lista.find((x) => x.evento === evento);
        const nuevo = b.getAttribute('aria-checked') !== 'true';
        b.setAttribute('aria-checked', String(nuevo));
        try {
          lista = (await api.fijarAviso({ evento, activo: nuevo, sonido: a?.sonido !== false })).avisos;
          if (nuevo) tono('ok');
          pintar();
        } catch (e) { mensaje(e.message, 'error'); }
      };
    });

    // Que ese aviso suene o llegue callado
    $$('[data-ev-sonido]', contenido).forEach((b) => {
      b.onclick = async () => {
        const evento = b.dataset.evSonido;
        const a = lista.find((x) => x.evento === evento);
        if (!a || !a.activo) return;
        try {
          lista = (await api.fijarAviso({ evento, activo: true, sonido: !a.sonido })).avisos;
          if (!a.sonido) tono(tonoDe(evento));
          pintar();
        } catch (e) { mensaje(e.message, 'error'); }
      };
    });

    // Escuchar cómo suena
    $$('[data-ev-probar]', contenido).forEach((b) => {
      b.onclick = () => {
        if (!sonidoActivo()) { mensaje('El sonido de este aparato está apagado.', 'error'); return; }
        tono(tonoDe(b.dataset.evProbar));
      };
    });
  }

  /** Cada evento tiene su tono; así se distinguen sin mirar. */
  function tonoDe(evento) {
    return {
      asignado: 'asignado', asignado_grupo: 'asignado',
      urgente_nuevo: 'urgente', cambio_estado: 'ok',
      nota: 'guardado', vencido: 'conflicto',
    }[evento] || 'ok';
  }

  function estadoPermiso() {
    if (!('Notification' in window)) return 'No disponible';
    return { granted: 'Activados', denied: 'Bloqueados', default: 'Sin activar' }[Notification.permission] || '—';
  }

  function pedirHora(titulo, valor) {
    return new Promise((resolve) => {
      let resuelto = false;
      const cuerpo = document.createElement('div');
      cuerpo.innerHTML = `<div class="campo"><label for="h">${esc(titulo)}</label>
        <input class="entrada" id="h" type="time" value="${esc(valor)}"></div>`;
      const pie = document.createElement('div');
      pie.style.display = 'contents';
      pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                       <button type="button" class="btn btn--principal" data-si>Aceptar</button>`;
      hoja({
        titulo, cuerpo, pie,
        alAbrir: (h) => {
          $('[data-no]', h).onclick = () => cerrarHoja();
          $('[data-si]', h).onclick = () => {
            resuelto = true; resolve($('#h', h).value || valor); cerrarHoja();
          };
        },
        alCerrar: () => { if (!resuelto) resolve(null); },
      });
    });
  }

  pintar();
  return {};
}
