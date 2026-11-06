/* =====================================================================
   Registro de seguridad.

   Quién entra, desde dónde y qué cambia. Sirve para dos cosas distintas:
   reconstruir un acceso indebido, y demostrar que algo se hizo cuando
   alguien dice que no.

   Se guarda la dirección IP, que es un dato personal, así que la
   pantalla dice cuánto tiempo se conserva: quien administra debe saber
   qué está guardando.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import {
  $, $$, icono, esc, mensaje, hoja, cerrarHoja, elegir, confirmar,
  fechaLarga, haceCuanto, descargarFichero, aCsv, textoPlano,
} from '../ui.js';
import { cabeceraVolver, estadoVacio, bloqueCarga } from '../piezas.js';
import { pintarCabecera, ir, app } from '../app.js';

export async function vistaSeguridad({ contenido }) {
  pintarCabecera(cabeceraVolver('Registro de seguridad', 'Accesos y cambios'));

  if (!app.usuario.es_admin) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
      <div>Esta pantalla es sólo para administradores.</div></div>`;
    return {};
  }
  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>El registro se consulta en el servidor y necesita conexión.</div></div>`;
    return {};
  }

  const filtros = { limite: 60, offset: 0 };
  let datos = null;
  let busca = '';

  /* Buscar dentro de lo cargado.
     El registro trae una página de entradas, no todas: así que esto
     filtra lo que hay a la vista y se dice con esas palabras, en vez de
     dejar creer que se ha buscado en el registro entero. */
  const visibles = () => {
    const q = textoPlano(busca.trim());
    const todas = datos?.registros || [];
    if (q.length < 2) return todas;
    const palabras = q.split(/\s+/).filter(Boolean);
    return todas.filter((x) => {
      /* Los campos que trae de verdad un registro: correo, ip, tipo y
         detalle. «quien» no existe, y buscar por un campo inexistente
         es un filtro que parece funcionar y no busca por ahí. */
      const heno = textoPlano([x.correo, x.detalle, x.ip, x.tipo, x.agente,
                               datos.tipos?.[x.tipo]].filter(Boolean).join(' '));
      return palabras.every((w) => heno.includes(w));
    });
  };

  async function cargar() {
    contenido.innerHTML = bloqueCarga(4);
    try { datos = await api.seguridad(filtros); }
    catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(e.message)}</div></div>`;
      return;
    }
    pintar();
  }

  const ICONO_TIPO = {
    acceso: 'ok', acceso_fallido: 'aviso', salida: 'salir',
    password: 'llave', correo: 'nota', recuperacion: 'llave',
    bloqueo: 'aviso', desbloqueo: 'ok', alta_usuario: 'personas',
    rol: 'personas', ajuste: 'ajustes', copia: 'archivo',
    restauracion: 'archivo', mantenimiento: 'herramientas', borrado: 'papelera',
  };

  function pintar() {
    const r = datos.resumen || {};
    const sospechosas = r.ips_sospechosas || [];

    contenido.innerHTML = `
      <div class="rejilla-cifras" style="margin-bottom:14px">
        <div class="cifra" style="cursor:default">
          <div class="cifra__valor">${r.accesos_hoy ?? 0}</div>
          <div class="cifra__nombre">Entradas hoy</div>
        </div>
        <button type="button" class="cifra ${r.fallos_hoy ? 'cifra--alerta' : ''}" data-f="fallos">
          <div class="cifra__valor">${r.fallos_hoy ?? 0}</div>
          <div class="cifra__nombre">Intentos fallidos hoy</div>
        </button>
      </div>

      ${sospechosas.length ? `
        <div class="aviso aviso--senal">${icono('aviso')}<div>
          <strong>Direcciones con muchos fallos esta semana.</strong>
          Es lo que delata a alguien probando contraseñas.
          <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
            ${sospechosas.map((s) => `
              <button type="button" data-ip="${esc(s.ip)}"
                      style="text-align:left;background:none;border:0;padding:2px 0;cursor:pointer;
                             color:inherit;font-size:12.5px;font-family:var(--mono)">
                ${esc(s.ip)} — ${s.n} intentos, el último ${esc(haceCuanto(s.ultima))}
              </button>`).join('')}
          </div>
        </div>` : ''}

      <div class="carril" style="margin-bottom:12px">
        <button type="button" class="pastilla" data-f="todo" aria-pressed="${!filtros.tipo && !filtros.fallos && !filtros.ip}">
          ${icono('lista')}Todo</button>
        <button type="button" class="pastilla" data-f="fallos" aria-pressed="${!!filtros.fallos}">
          ${icono('aviso')}Sólo fallos</button>
        <button type="button" class="pastilla" data-f="tipo" aria-pressed="${!!filtros.tipo}">
          ${icono('filtro')}${filtros.tipo ? esc(datos.tipos[filtros.tipo] || filtros.tipo) : 'Por tipo'}</button>
        ${filtros.ip ? `<button type="button" class="pastilla" aria-pressed="true" data-f="quitar-ip">
          ${icono('cerrar')}${esc(filtros.ip)}</button>` : ''}
      </div>

      <div class="lst">
        <div class="lst__buscador">
          ${icono('buscar')}
          <input type="search" class="entrada" id="s-busca" autocomplete="off"
                 value="${esc(busca)}" aria-label="Buscar en el registro"
                 placeholder="Buscar por persona, dirección o detalle…">
          ${busca ? '<button type="button" class="lst__limpiar" data-limpiar aria-label="Limpiar">×</button>' : ''}
        </div>
      </div>

      <div class="rotulo" style="margin:0 2px 8px">
        ${busca
          ? `${visibles().length} de las ${datos.registros.length} que hay cargadas`
          : `${datos.total} ${datos.total === 1 ? 'entrada' : 'entradas'}`
            + (datos.total > datos.registros.length
                ? ` · se muestran ${datos.registros.length}` : '')}
      </div>

      ${visibles().length ? `<div class="filas">
        ${visibles().map((x) => `
          <div class="fila" style="cursor:default;align-items:flex-start">
            <span class="avatar" style="background:${x.exito ? 'var(--ok-tenue)' : 'var(--peligro-tenue)'};
                                        color:${x.exito ? 'var(--ok)' : 'var(--peligro)'}">
              ${icono(ICONO_TIPO[x.tipo] || 'info')}</span>
            <span class="fila__texto">${esc(x.tipo_nombre)}
              ${!x.exito ? '<span class="prio" style="--prio-color:var(--peligro);margin-left:6px">falló</span>' : ''}
              <small>${esc(x.persona || x.correo || 'desconocido')}
                · <span style="font-family:var(--mono)">${esc(x.ip || '—')}</span>
                · ${esc(haceCuanto(x.fecha))}</small>
              ${x.detalle ? `<small style="display:block;margin-top:3px;color:var(--tinta-3)">
                ${esc(x.detalle)}</small>` : ''}</span>
          </div>`).join('')}
      </div>` : estadoVacio({ icono: 'buscar', titulo: 'Nada que mostrar', texto: 'Prueba a quitar los filtros.' })}

      ${datos.total > filtros.offset + datos.registros.length ? `
        <button type="button" class="btn btn--contorno btn--bloque btn--s" data-mas style="margin-top:12px">
          Ver más</button>` : ''}

      <div class="seccion"><h2>Conservación</h2></div>
      <div class="aviso aviso--info">${icono('info')}<div>
        Se guardan <strong>${datos.total}</strong> entradas. Las direcciones IP son
        un dato personal: se borran solas pasados los días configurados en
        Administración → Seguridad, y ahí puedes cambiar el plazo.
      </div></div>

      <div class="acciones" style="margin-top:12px">
        <button type="button" class="btn btn--contorno btn--s" data-csv>${icono('descarga')}Exportar</button>
        <button type="button" class="btn btn--peligro btn--s" data-purgar>${icono('papelera')}Purgar lo caducado</button>
      </div>`;

    enganchar();
  }

  function enganchar() {
    $$('[data-f]', contenido).forEach((b) => {
      b.onclick = async () => {
        switch (b.dataset.f) {
          case 'todo':
            delete filtros.tipo; delete filtros.fallos; delete filtros.ip;
            filtros.offset = 0;
            break;
          case 'fallos':
            if (filtros.fallos) delete filtros.fallos; else filtros.fallos = 1;
            filtros.offset = 0;
            break;
          case 'quitar-ip':
            delete filtros.ip; filtros.offset = 0;
            break;
          case 'tipo': {
            const v = await elegir({
              titulo: 'Tipo de suceso',
              valor: filtros.tipo,
              opciones: [{ valor: null, texto: 'Todos' },
                ...Object.entries(datos.tipos).map(([k, t]) => ({ valor: k, texto: t }))],
            });
            if (v === null) delete filtros.tipo; else filtros.tipo = v;
            filtros.offset = 0;
            break;
          }
        }
        cargar();
      };
    });

    $$('[data-ip]', contenido).forEach((b) => {
      b.onclick = () => { filtros.ip = b.dataset.ip; filtros.offset = 0; cargar(); };
    });

    /* El buscador. Se vuelve a crear en cada repintado, así que se
       engancha aquí y se devuelve el foco donde estaba: sin eso, cada
       letra manda el cursor al principio. */
    const caja = $('#s-busca', contenido);
    if (caja) {
      let reloj = null;
      caja.oninput = () => {
        clearTimeout(reloj);
        reloj = setTimeout(() => {
          busca = caja.value;
          const pos = caja.selectionStart;
          pintar();
          const n = $('#s-busca', contenido);
          if (n) { n.focus(); try { n.setSelectionRange(pos, pos); } catch { /* da igual */ } }
        }, 170);
      };
    }
    const limpiar = $('[data-limpiar]', contenido);
    if (limpiar) limpiar.onclick = () => { busca = ''; pintar(); };

    const mas = $('[data-mas]', contenido);
    if (mas) mas.onclick = () => { filtros.limite += 60; cargar(); };

    $('[data-csv]', contenido).onclick = async () => {
      const csv = aCsv(
        ['Fecha', 'Tipo', 'Persona', 'Correo', 'IP', 'Resultado', 'Detalle'],
        /* Lo que se está viendo, no todo lo cargado: si alguien ha
           filtrado y pulsa «exportar», espera su filtro. */
        visibles().map((x) => [
          x.fecha, x.tipo_nombre, x.persona || '', x.correo || '',
          x.ip || '', x.exito ? 'ok' : 'fallo', x.detalle || '',
        ])
      );
      const ok = await descargarFichero(
        `seguridad-${new Date().toISOString().slice(0, 10)}.csv`, csv, 'text/csv;charset=utf-8');
      if (ok) mensaje('Registro exportado.', 'ok');
    };

    $('[data-purgar]', contenido).onclick = async () => {
      const ok = await confirmar({
        titulo: 'Purgar lo caducado',
        texto: 'Se borran las entradas más antiguas que el plazo configurado. Esto no se puede deshacer.',
        aceptar: 'Purgar', peligro: true,
      });
      if (!ok) return;
      try {
        const r = await api.purgarSeguridad();
        mensaje(`${r.borrados} entradas eliminadas.`, 'ok');
        cargar();
      } catch (e) { mensaje(e.message, 'error'); }
    };
  }

  await cargar();
  return { refrescar: cargar };
}
