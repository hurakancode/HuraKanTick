/* =====================================================================
   Hoteles.

   Cada hotel es una instalación distinta que comparte servidor. Un parte
   de uno no se ve desde otro bajo ningún concepto.

   Esta pantalla es sólo para superadministradores: el administrador de
   un hotel manda en el suyo y no necesita saber que hay más.
   ===================================================================== */

import { api, hayRed, hotelActivo, fijarHotelActivo } from '../api.js';
import * as db from '../db.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, confirmar, fechaCorta,
} from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';
import { tono } from '../sonido.js';

export async function vistaHoteles({ contenido }) {
  pintarCabecera(cabeceraVolver('Hoteles', 'Cada uno con lo suyo',
    app.usuario.es_superadmin
      ? `<button type="button" class="icono-btn" data-nuevo aria-label="Crear hotel">${icono('mas')}</button>` : ''));

  if (!app.usuario.es_superadmin) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Sólo un administrador gestiona los hoteles.</div></div>`;
    return {};
  }
  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Esta pantalla necesita conexión.</div></div>`;
    return {};
  }

  let datos = null;

  async function cargar() {
    contenido.innerHTML = bloqueCarga(3);
    try { datos = await api.hoteles(); }
    catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    pintar();
  }

  function pintar() {
    const activo = hotelActivo() || app.usuario.id_hotel;

    contenido.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Estás trabajando sobre <strong>${esc(nombreDe(activo))}</strong>. Todo lo que
        veas y hagas es de ese hotel: partes, personas, ubicaciones y aparatos.
      </div></div>

      <div class="filas">
        ${datos.hoteles.map((h) => `
          <div class="fila" style="cursor:default;align-items:flex-start;
                      ${h.id_hotel === activo ? 'background:var(--marca-tenue)' : ''}
                      ${h.activo ? '' : 'opacity:.55'}">
            <span class="avatar" style="background:${esc(h.color || '#0B5563')}22;
                                        color:${esc(h.color || '#0B5563')};font-weight:700">
              ${esc((h.codigo || h.nombre).slice(0, 3).toUpperCase())}</span>
            <span class="fila__texto">${esc(h.nombre)}
              ${h.id_hotel === activo ? '<span class="prio" style="--prio-color:var(--marca);margin-left:6px">aquí estás</span>' : ''}
              ${h.activo ? '' : '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">desactivado</span>'}
              <small>${esc(h.ciudad || '')}${h.ciudad ? ' · ' : ''}${h.partes} partes ·
                ${h.personas} personas · ${h.ubicaciones} ubicaciones</small>
              <span style="display:flex;gap:6px;margin-top:9px;flex-wrap:wrap">
                ${h.id_hotel !== activo && h.activo ? `
                  <button type="button" class="pastilla" data-entrar="${h.id_hotel}"
                          style="border-color:var(--marca);color:var(--marca)">
                    ${icono('flecha')}Trabajar aquí</button>` : ''}
                <button type="button" class="pastilla" data-editar="${h.id_hotel}">
                  ${icono('editar')}Editar</button>
                <button type="button" class="pastilla" data-copiar="${h.id_hotel}">
                  ${icono('archivo')}Copiar de otro</button>
                <button type="button" class="pastilla" data-restablecer="${h.id_hotel}"
                        style="border-color:var(--peligro);color:var(--peligro)">
                  ${icono('sync')}Restablecer</button>
              </span>
            </span>
          </div>`).join('')}
      </div>

      ${datos.hoteles.length === 1 ? `
        <div class="aviso aviso--info" style="margin-top:16px">${icono('info')}<div>
          Sólo hay un hotel. Al crear el segundo podrás copiarle las ubicaciones
          para no montarlas de cero.
        </div></div>` : ''}`;

    enganchar();
  }

  function enganchar() {
    $$('[data-entrar]', contenido).forEach((b) => {
      b.onclick = () => cambiarHotel(parseInt(b.dataset.entrar, 10));
    });
    $$('[data-editar]', contenido).forEach((b) => {
      b.onclick = () => editar(datos.hoteles.find((h) => h.id_hotel === parseInt(b.dataset.editar, 10)));
    });
    $$('[data-copiar]', contenido).forEach((b) => {
      b.onclick = () => copiarA(parseInt(b.dataset.copiar, 10));
    });
    $$('[data-restablecer]', contenido).forEach((b) => {
      b.onclick = () => restablecer(datos.hoteles.find(
        (h) => h.id_hotel === parseInt(b.dataset.restablecer, 10)));
    });
    const nuevo = document.querySelector('[data-nuevo]');
    if (nuevo) nuevo.onclick = () => crear();
  }

  function nombreDe(id) {
    return datos?.hoteles?.find((h) => h.id_hotel === id)?.nombre || 'este hotel';
  }

  /* ---------------------------------------------------------------- */
  /**
   * Cambia el hotel con el que se trabaja.
   *
   * Se borra lo que hay guardado en el aparato antes de cambiar. Si no,
   * quedarían mezclados los partes de los dos hoteles en el mismo móvil
   * y al mirar sin cobertura se verían los del anterior — que es
   * exactamente lo que no puede pasar.
   */
  async function cambiarHotel(id) {
    const h = datos.hoteles.find((x) => x.id_hotel === id);
    const ok = await confirmar({
      titulo: 'Trabajar en ' + (h?.nombre || 'otro hotel'),
      texto: 'Se borrará de este aparato todo lo descargado del hotel anterior: '
           + 'partes, ubicaciones y avisos. No se pierde nada del servidor, '
           + 'pero si tienes algo sin enviar, envíalo antes.',
      aceptar: 'Cambiar',
    });
    if (!ok) return;

    const pendientes = await db.colaPendiente();
    if (pendientes.length) {
      mensaje(`Tienes ${pendientes.length} cambios sin enviar. Sincroniza antes de cambiar de hotel.`, 'error');
      return;
    }

    try {
      await db.vaciarTodo();
      fijarHotelActivo(id);
      localStorage.removeItem('hurakantick_perfil');
      tono('ok');
      mensaje('Trabajando en ' + h.nombre + '.', 'ok');
      // Recarga entera: el catálogo, los filtros y la pantalla de inicio
      // son distintos, y es más fiable que ir refrescando trozos.
      setTimeout(() => window.location.reload(), 600);
    } catch (e) {
      mensaje('No se ha podido cambiar: ' + e.message, 'error');
    }
  }

  /* ---------------------------------------------------------------- */
  function crear() {
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="h-nombre">Nombre</label>
        <input class="entrada" id="h-nombre" maxlength="150" placeholder="Hotel Marsol"></div>

      <div style="display:flex;gap:10px">
        <div class="campo" style="flex:1"><label for="h-codigo">Código</label>
          <input class="entrada" id="h-codigo" maxlength="12" placeholder="MAR"
                 style="font-family:var(--mono);text-transform:uppercase"></div>
        <div class="campo" style="flex:1.4"><label for="h-ciudad">Ciudad</label>
          <input class="entrada" id="h-ciudad" maxlength="80"></div>
      </div>

      <div class="campo"><label for="h-dir">Dirección</label>
        <input class="entrada" id="h-dir" maxlength="200"></div>

      <div class="seccion"><h2>Punto de partida</h2></div>
      <div class="campo">
        <label>Copiar las ubicaciones de</label>
        <select class="entrada" id="h-copiar">
          <option value="">Empezar de cero</option>
          ${datos.hoteles.filter((h) => h.ubicaciones > 0).map((h) => `
            <option value="${h.id_hotel}">${esc(h.nombre)} · ${h.ubicaciones} ubicaciones</option>`).join('')}
        </select>
        <div class="campo__ayuda">
          Se copian las plantas, zonas, lugares y sublugares con sus
          combinaciones. Después se editan: casi siempre es más rápido que
          montarlas de cero.
        </div>
      </div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('nota')}
        <span class="fila__texto">Copiar también las instrucciones
          <small>Saber purgar un circuito vale igual en los dos</small></span>
        <button type="button" class="interruptor" id="h-instr" role="switch" aria-checked="true"></button>
      </div>

      <div class="aviso aviso--senal" style="margin-top:14px">${icono('info')}<div>
        Los <strong>números de serie no se copian</strong>. Un aparato está donde
        está, y duplicarlo sería inventarse inventario.
      </div></div>

      <div id="h-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--principal" data-si>Crear hotel</button>`;

    hoja({
      titulo: 'Hotel nuevo',
      cuerpo, pie,
      alAbrir: (h) => {
        setTimeout(() => $('#h-nombre', h).focus(), 250);
        const instr = $('#h-instr', h);
        instr.onclick = () => instr.setAttribute('aria-checked',
          instr.getAttribute('aria-checked') !== 'true');

        $('[data-no]', h).onclick = () => cerrarHoja();
        $('[data-si]', h).onclick = async () => {
          const err = (t) => { $('#h-err', h).innerHTML =
            `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };
          const nombre = $('#h-nombre', h).value.trim();
          if (!nombre) return err('Ponle nombre al hotel.');

          const b = $('[data-si]', h);
          b.disabled = true;
          b.textContent = 'Creando…';
          try {
            const copiar = $('#h-copiar', h).value;
            const r = await api.crearHotel({
              nombre,
              codigo: $('#h-codigo', h).value.trim().toUpperCase(),
              ciudad: $('#h-ciudad', h).value.trim(),
              direccion: $('#h-dir', h).value.trim(),
              copiar_de: copiar ? parseInt(copiar, 10) : null,
              copiar_instrucciones: instr.getAttribute('aria-checked') === 'true',
            });
            cerrarHoja();
            tono('ok');
            mensaje(`${r.hotel.nombre} creado con ${r.hotel.ubicaciones} ubicaciones.`, 'ok');
            cargar();
          } catch (e) {
            b.disabled = false;
            b.textContent = 'Crear hotel';
            err(e.message);
          }
        };
      },
    });
  }

  /* ---------------------------------------------------------------- */
  function editar(h) {
    if (!h) return;
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="campo"><label for="e-nombre">Nombre</label>
        <input class="entrada" id="e-nombre" maxlength="150" value="${esc(h.nombre)}"></div>
      <div style="display:flex;gap:10px">
        <div class="campo" style="flex:1"><label for="e-codigo">Código</label>
          <input class="entrada" id="e-codigo" maxlength="12" value="${esc(h.codigo || '')}"
                 style="font-family:var(--mono);text-transform:uppercase"></div>
        <div class="campo" style="flex:1.4"><label for="e-ciudad">Ciudad</label>
          <input class="entrada" id="e-ciudad" maxlength="80" value="${esc(h.ciudad || '')}"></div>
      </div>
      <div class="campo"><label for="e-dir">Dirección</label>
        <input class="entrada" id="e-dir" maxlength="200" value="${esc(h.direccion || '')}"></div>
      <div class="campo"><label for="e-tel">Teléfono</label>
        <input class="entrada" id="e-tel" type="tel" maxlength="40" value="${esc(h.telefono || '')}"></div>

      <div class="fila" style="cursor:default;border-radius:var(--r-m);border:1.5px solid var(--borde)">
        ${icono('ok')}
        <span class="fila__texto">Activo
          <small>Desactivado, nadie puede entrar en él</small></span>
        <button type="button" class="interruptor" id="e-activo" role="switch" aria-checked="${!!h.activo}"></button>
      </div>

      <div class="aviso aviso--info" style="margin-top:14px">${icono('info')}<div>
        ${h.partes} partes · ${h.personas} personas · ${h.ubicaciones} ubicaciones.
      </div></div>
      <div id="e-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

    hoja({
      titulo: h.nombre,
      cuerpo, pie,
      alAbrir: (x) => {
        const act = $('#e-activo', x);
        act.onclick = () => act.setAttribute('aria-checked',
          act.getAttribute('aria-checked') !== 'true');
        $('[data-no]', x).onclick = () => cerrarHoja();
        $('[data-si]', x).onclick = async () => {
          try {
            await api.editarHotel(h.id_hotel, {
              nombre: $('#e-nombre', x).value.trim(),
              codigo: $('#e-codigo', x).value.trim().toUpperCase(),
              ciudad: $('#e-ciudad', x).value.trim(),
              direccion: $('#e-dir', x).value.trim(),
              telefono: $('#e-tel', x).value.trim(),
              activo: act.getAttribute('aria-checked') === 'true',
            });
            cerrarHoja();
            mensaje('Hotel guardado.', 'ok');
            cargar();
          } catch (e) {
            $('#e-err', x).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  /* ---------------------------------------------------------------- */
  async function copiarA(destino) {
    const otros = datos.hoteles.filter((h) => h.id_hotel !== destino && h.ubicaciones > 0);
    if (!otros.length) {
      mensaje('No hay otro hotel con ubicaciones que copiar.', 'error');
      return;
    }
    const desde = await elegir({
      titulo: 'Copiar ubicaciones de',
      opciones: otros.map((h) => ({
        valor: h.id_hotel, texto: h.nombre, detalle: `${h.ubicaciones} ubicaciones`,
      })),
    });
    if (!desde) return;

    const ok = await confirmar({
      titulo: 'Copiar ubicaciones',
      texto: 'Se añaden las que falten. Las que ya existan con el mismo nombre se '
           + 'reutilizan, así que se puede repetir sin duplicar nada. Los números '
           + 'de serie no se copian.',
      aceptar: 'Copiar',
    });
    if (!ok) return;

    try {
      const r = await api.copiarHotel(destino, desde, true);
      tono('ok');
      mensaje(r.mensaje, 'ok');
      cargar();
    } catch (e) { mensaje(e.message, 'error'); }
  }

  /**
   * Vaciar un hotel.
   *
   * Es la operación con la que más daño se puede hacer, así que se pone
   * difícil a propósito: hay que marcar qué se borra, leer cuánto es, y
   * escribir el nombre del hotel. Nada de «¿seguro?» y ya está.
   *
   * Nada se pierde: todo pasa a la papelera antes de borrarse.
   */
  async function restablecer(h) {
    if (!h) return;

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="aviso aviso--error">${icono('aviso')}<div>
        Vas a vaciar <strong>${esc(h.nombre)}</strong>. Ahora mismo tiene
        <strong>${h.partes} partes</strong> y <strong>${h.personas} personas</strong>.
      </div></div>

      <div class="filas">
        <div class="fila" style="cursor:default;align-items:flex-start">
          ${icono('lista')}
          <span class="fila__texto">Borrar todos los partes
            <small>Los ${h.partes} que hay, en cualquier estado: abiertos,
              cerrados y los que estén en la papelera de la aplicación</small></span>
          <button type="button" class="interruptor" id="r-partes" role="switch" aria-checked="false"></button>
        </div>
        <div class="fila" style="cursor:default;align-items:flex-start">
          ${icono('personas')}
          <span class="fila__texto">Borrar todas las personas
            <small>Los administradores se quedan siempre, y tú también:
              si no, nadie podría volver a entrar</small></span>
          <button type="button" class="interruptor" id="r-usuarios" role="switch" aria-checked="false"></button>
        </div>
      </div>

      <div class="seccion" style="margin-top:16px"><h2>Y además</h2>
        <button type="button" class="pastilla" id="r-todo">Marcar todo</button></div>
      <div class="filas">
        ${[
          ['avisos', 'campana', 'Los avisos de todo el mundo',
           'Incluidos los que se quedaron sin parte y no hay forma de quitar: '
           + 'son los que siguen contando en la chapa de «sin leer»'],
          ['mensajes', 'chat', 'Todas las conversaciones',
           'Los mensajes de los partes y las marcas de leído, de todas las personas'],
          ['mantenimientos', 'calendario', 'Las revisiones programadas',
           'El calendario de mantenimiento preventivo: extintores, filtros, legionela'],
          ['errores', 'aviso', 'El registro de errores',
           'Lo que ha fallado. Vaciarlo no arregla nada, sólo deja la lista limpia'],
          ['seguridad', 'candado', 'El registro de seguridad',
           'Quién entró y cuándo. Piénsalo: es la auditoría, y no se puede reconstruir'],
          ['preferencias', 'ajustes', 'Lo que cada uno se ha configurado',
           'Su barra de abajo, sus avisos, sus atajos y sus informes guardados. '
           + 'No se tocan sus permisos ni su departamento'],
          ['papelera', 'papelera', 'Vaciar la papelera de reciclaje',
           'ESTO SÍ ES DEFINITIVO. Es lo único de aquí que no se puede deshacer, '
           + 'y se hace lo último: también se lleva lo que acabas de borrar arriba'],
        ].map(([clave, ic, titulo, detalle]) => `
          <div class="fila" style="cursor:default;align-items:flex-start">
            ${icono(ic)}
            <span class="fila__texto">${esc(titulo)}
              <small${clave === 'papelera' ? ' style="color:var(--peligro)"' : ''}>${esc(detalle)}</small></span>
            <button type="button" class="interruptor" data-extra="${clave}" role="switch"
                    aria-checked="false"></button>
          </div>`).join('')}
      </div>

      <div class="aviso aviso--info" style="margin-top:14px">${icono('info')}<div>
        <strong>No se pierde nada.</strong> Todo pasa a la papelera de reciclaje
        antes de borrarse, y desde ahí se puede consultar.
        <br><br>
        No se tocan las ubicaciones, los tipos de avería, las instrucciones ni
        los aparatos con número de serie: eso es la estructura del hotel y
        montarla otra vez costaría días.
      </div></div>

      <div class="campo" style="margin-top:16px">
        <label for="r-conf">Escribe el nombre del hotel para confirmarlo</label>
        <input class="entrada" id="r-conf" autocomplete="off" autocapitalize="off"
               placeholder="${esc(h.nombre)}">
      </div>

      <div id="r-err"></div>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                     <button type="button" class="btn btn--peligro" data-si disabled>Vaciar el hotel</button>`;

    hoja({
      titulo: 'Restablecer de fábrica',
      cuerpo, pie,
      alAbrir: (x) => {
        const conf = $('#r-conf', x);
        const bSi = $('[data-si]', x);
        const iP = $('#r-partes', x);
        const iU = $('#r-usuarios', x);

        const extras = [...x.querySelectorAll('[data-extra]')];
        const puesto = (i) => i.getAttribute('aria-checked') === 'true';

        const revisar = () => {
          const algo = puesto(iP) || puesto(iU) || extras.some(puesto);
          const bien = conf.value.trim().toLowerCase() === h.nombre.toLowerCase();
          bSi.disabled = !(algo && bien);
        };
        [iP, iU, ...extras].forEach((i) => {
          i.onclick = () => {
            i.setAttribute('aria-checked', puesto(i) ? 'false' : 'true');
            revisar();
          };
        });

        /* «Marcar todo» deja fuera la papelera a propósito: es lo único
           que no se puede deshacer, y un botón de conveniencia no debe
           poder borrar definitivamente nada sin que alguien lo marque. */
        $('#r-todo', x).onclick = () => {
          const faltan = [iP, iU, ...extras.filter((e) => e.dataset.extra !== 'papelera')]
            .some((i) => !puesto(i));
          [iP, iU, ...extras.filter((e) => e.dataset.extra !== 'papelera')]
            .forEach((i) => i.setAttribute('aria-checked', faltan ? 'true' : 'false'));
          revisar();
        };
        conf.oninput = revisar;

        $('[data-no]', x).onclick = () => cerrarHoja();
        $('[data-si]', x).onclick = async () => {
          bSi.disabled = true;
          bSi.textContent = 'Vaciando…';
          try {
            const r = await api.restablecerHotel(h.id_hotel, {
              partes: puesto(iP),
              usuarios: puesto(iU),
              ...Object.fromEntries(extras.map((e) => [e.dataset.extra, puesto(e)])),
              confirmacion: conf.value.trim(),
            });
            cerrarHoja();
            tono('ok');
            mensaje(r.mensaje, 'ok');
            cargar();
          } catch (e) {
            bSi.disabled = false;
            bSi.textContent = 'Vaciar el hotel';
            $('#r-err', x).innerHTML =
              `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
          }
        };
      },
    });
  }

  await cargar();
  return { refrescar: cargar };
}
