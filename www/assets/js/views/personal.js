/* =====================================================================
   Importar y exportar personas.

   Para cuando hay que dar de alta a cuarenta a la vez y hacerlo una por
   una son dos mañanas. Se rellena un fichero de texto por persona, se
   dejan todos en una carpeta y esto los recorre.

   La idea que sostiene la pantalla: quien rellena la plantilla NO tiene
   que saber nada. La plantilla se descarga ya con los departamentos y
   los papeles de ESTE hotel escritos, y sólo hay que marcar con [x]. Por
   eso el primer botón es «Descargar la plantilla» y no una ayuda.
   ===================================================================== */

import { api, hayRed } from '../api.js';
import { $, $$, icono, esc, mensaje, haceCuanto, confirmar, hoja, cerrarHoja,
         textoPlano } from '../ui.js';
import { cabeceraVolver, bloqueCarga, estadoVacio } from '../piezas.js';
import { pintarCabecera, app } from '../app.js';
import { tono } from '../sonido.js';

export async function vistaPersonal({ contenido }) {
  pintarCabecera(cabeceraVolver('Importar / Exportar personas', 'Administración'));
  contenido.innerHTML = bloqueCarga(3);

  /* Sólo administradores. El servidor ya lo impide, pero un 403 en
     crudo no le dice nada a nadie: se explica. */
  if (!app.usuario.es_admin) {
    contenido.innerHTML = `<div class="aviso aviso--error">${icono('candado')}
      <div>Esta pantalla es sólo para administradores: da de alta personas y
      saca los datos de toda la plantilla.</div></div>`;
    return {};
  }

  if (!hayRed()) {
    contenido.innerHTML = `<div class="aviso aviso--senal">${icono('sinred')}
      <div>Esto necesita conexión con el servidor.</div></div>`;
    return {};
  }

  let datos = null;
  let gente = [];
  let busca = '';
  const marcadas = new Set();

  /** Lo que encaja con lo escrito, por palabras y en cualquier orden. */
  function visibles() {
    const q = textoPlano(busca.trim());
    if (q.length < 2) return gente;
    const palabras = q.split(/\s+/).filter(Boolean);
    return gente.filter((g) => {
      const heno = textoPlano([g.nombre, g.apellido1, g.usuario, g.correo,
                               g.departamento, g.rol].filter(Boolean).join(' '));
      return palabras.every((w) => heno.includes(w));
    });
  }

  /* Lo marcado que sigue a la vista: si alguien marca diez, busca
     «cocina» y pulsa «Sacar», debe sacar las de cocina marcadas y no
     las diez que ya no está viendo. */
  function marcadasVisibles() {
    const vis = new Set(visibles().map((g) => g.id_login));
    return [...marcadas].filter((x) => vis.has(x));
  }

  /** Guardar un texto como fichero, desde el navegador. */
  function bajar(nombre, texto) {
    try {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([texto], { type: 'text/plain;charset=utf-8' }));
      a.download = nombre;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      return true;
    } catch {
      /* En una tablet con el navegador capado no siempre se puede. Se
         enseña el texto para copiarlo a mano antes que no dar nada. */
      return false;
    }
  }

  async function cargar() {
    try {
      datos = await api.personal();
      gente = (await api.usuarios()).usuarios || [];
    } catch (e) {
      contenido.innerHTML = `<div class="aviso aviso--error">${icono('aviso')}
        <div>${esc(e.message)}</div></div>`;
      return;
    }
    pintar();
  }

  function pintar() {
    const c = datos.carpetas || {};
    const pendientes = datos.pendientes || [];
    const exportados = datos.exportados || [];

    const problema = [c.entrada, c.salida].filter((x) => x && (!x.existe || !x.escribe));

    contenido.innerHTML = `
      ${problema.length ? `
        <div class="aviso aviso--error">${icono('aviso')}<div>
          No se puede escribir en ${problema.map((x) => `<code>${esc(x.ruta)}</code>`).join(' ni ')}.
          Dale permiso de escritura a esa carpeta o no se podrá ni importar ni exportar.
        </div></div>` : ''}

      <!-- ─────────────  IMPORTAR  ───────────── -->
      <div class="seccion"><h2>Dar de alta desde ficheros</h2></div>

      <div class="aviso aviso--info">${icono('info')}<div>
        Descarga la plantilla, rellena una copia por persona y déjalas en
        <code>bak_config/upload/user</code>. Vienen con los departamentos y
        los papeles de este hotel ya escritos: sólo hay que marcar con [x].
        <br><br>
        Al dar de alta, <b>el fichero se borra</b>: lleva dentro la
        contraseña inicial de esa persona.
      </div></div>

      <div class="acciones" style="margin-bottom:14px">
        <button type="button" class="btn btn--principal" data-plantilla>
          ${icono('descarga')}Descargar la plantilla</button>
        <button type="button" class="btn btn--contorno" data-refrescar>
          ${icono('sync')}Mirar la carpeta</button>
      </div>

      ${pendientes.length === 0 ? `
        <p style="color:var(--tinta-3);font-size:14px;margin:4px 2px 16px">
          No hay ningún fichero esperando en <code>bak_config/upload/user</code>.</p>` : `
        <div class="filas">
          ${pendientes.map((f) => `
            <div class="fila" style="cursor:default">
              ${icono('nota')}
              <span class="fila__texto">${esc(f.fichero)}
                <small>${f.bytes} bytes · ${esc(haceCuanto(f.fecha))}</small></span>
            </div>`).join('')}
        </div>
        <div class="acciones" style="margin:12px 0 18px">
          <button type="button" class="btn btn--contorno" data-probar>
            ${icono('ok')}Probar sin crear nada</button>
          <button type="button" class="btn btn--principal" data-importar>
            ${icono('personas')}Dar de alta ${pendientes.length}</button>
        </div>`}

      <!-- ─────────────  EXPORTAR  ───────────── -->
      <div class="seccion" style="margin-top:22px"><h2>Sacar las fichas a ficheros</h2>
        <span class="rotulo">${marcadas.size} de ${gente.length}</span></div>

      <div class="aviso aviso--info">${icono('info')}<div>
        Se escriben en <code>bak_config/download/user</code>, una por persona.
        <b>No llevan contraseña</b>: en su lugar pone <code>****</code>.
        Se pueden corregir y volver a meter por la carpeta de entrada para
        actualizar a esa gente.
      </div></div>

      <!-- Buscador. Con cincuenta personas, marcar a mano a las ocho de
           cocina era bajar rodando por la lista. -->
      <div class="lst">
        <div class="lst__buscador">
          ${icono('buscar')}
          <input type="search" class="entrada" id="p-busca" autocomplete="off"
                 value="${esc(busca)}" aria-label="Buscar una persona"
                 placeholder="Buscar por nombre, usuario o correo…">
          ${busca ? '<button type="button" class="lst__limpiar" data-limpiar aria-label="Limpiar">×</button>' : ''}
        </div>
        ${busca ? `<span class="lst__cuenta">${visibles().length} de ${gente.length}</span>` : ''}
      </div>

      <div class="acciones" style="margin-bottom:10px">
        <button type="button" class="btn btn--contorno btn--s" data-todos>
          ${marcadasVisibles().length === visibles().length && visibles().length
              ? 'Desmarcar' : (busca ? 'Marcar los ' + visibles().length : 'Marcar todos')}</button>
        <button type="button" class="btn btn--principal btn--s" data-exportar
                ${marcadasVisibles().length ? '' : 'disabled'}>
          ${icono('descarga')}Sacar ${marcadasVisibles().length || ''}</button>
      </div>

      <div class="filas">
        ${visibles().map((g) => {
          const nom = [g.nombre, g.apellido1].filter(Boolean).join(' ') || g.usuario;
          return `
          <button type="button" class="fila" data-persona="${g.id_login}">
            ${icono(marcadas.has(g.id_login) ? 'ok' : 'persona')}
            <span class="fila__texto">${esc(nom)}
              <small>${esc(g.usuario || '')}${g.correo ? ' · ' + esc(g.correo) : ''}</small></span>
            <span class="interruptor" role="switch"
                  aria-checked="${marcadas.has(g.id_login) ? 'true' : 'false'}"></span>
          </button>`;
        }).join('')}
      </div>

      ${exportados.length ? `
        <div class="seccion" style="margin-top:22px"><h2>Ya sacadas</h2>
          <span class="rotulo">${exportados.length}</span></div>
        <div class="acciones" style="margin-bottom:10px">
          <button type="button" class="btn btn--contorno btn--s" data-limpiar>
            ${icono('papelera')}Quitarlas todas de la carpeta</button>
        </div>
        <div class="filas">
          ${exportados.map((f) => `
            <button type="button" class="fila" data-bajar="${esc(f.fichero)}">
              ${icono('descarga')}
              <span class="fila__texto">${esc(f.fichero)}
                <small>${f.bytes} bytes · ${esc(haceCuanto(f.fecha))}</small></span>
              ${icono('flecha', 'fila__flecha')}
            </button>`).join('')}
        </div>` : ''}

      <div class="aviso aviso--senal" style="margin-top:20px">${icono('candado')}<div>
        Esas dos carpetas no se sirven por web: llevan nombres, correos y
        contraseñas dentro. Si cambias la configuración de Apache, comprueba
        que siguen sin verse desde el navegador.
      </div></div>`;

    enganchar();
  }

  function enganchar() {
    const caja = $('#p-busca', contenido);
    if (caja) {
      let reloj = null;
      caja.oninput = () => {
        clearTimeout(reloj);
        reloj = setTimeout(() => {
          busca = caja.value;
          const pos = caja.selectionStart;
          pintar();
          const nueva = $('#p-busca', contenido);
          if (nueva) { nueva.focus(); try { nueva.setSelectionRange(pos, pos); } catch { /* da igual */ } }
        }, 170);
      };
    }

    contenido.onclick = async (e) => {
      const p = e.target.closest('[data-persona]');
      if (p) {
        const id = parseInt(p.dataset.persona, 10);
        if (marcadas.has(id)) { marcadas.delete(id); } else { marcadas.add(id); }
        pintar();
        return;
      }

      if (e.target.closest('[data-todos]')) {
        const vis = visibles().map((g) => g.id_login);
        if (marcadasVisibles().length === vis.length && vis.length) {
          vis.forEach((x) => marcadas.delete(x));
        } else {
          vis.forEach((x) => marcadas.add(x));
        }
        pintar();
        return;
      }

      if (e.target.closest('[data-limpiar]')) { busca = ''; pintar(); return; }

      if (e.target.closest('[data-refrescar]')) { await cargar(); return; }

      if (e.target.closest('[data-plantilla]')) {
        try {
          const r = await api.plantillaPersona();
          if (!bajar(r.nombre, r.texto)) { verTexto(r.nombre, r.texto); }
        } catch (err) { mensaje(err.message, 'error'); }
        return;
      }

      const bajarF = e.target.closest('[data-bajar]');
      if (bajarF) {
        try {
          const r = await api.descargarPersona(bajarF.dataset.bajar);
          if (!bajar(r.nombre, r.texto)) { verTexto(r.nombre, r.texto); }
        } catch (err) { mensaje(err.message, 'error'); }
        return;
      }

      if (e.target.closest('[data-probar]')) { await importar(true); return; }
      if (e.target.closest('[data-importar]')) { await importar(false); return; }

      if (e.target.closest('[data-exportar]')) {
        try {
          const r = await api.exportarPersonas(marcadasVisibles());
          tono('ok');
          mensaje(r.mensaje, 'ok');
          marcadas.clear();
          await cargar();
        } catch (err) { mensaje(err.message, 'error'); }
        return;
      }

      if (e.target.closest('[data-limpiar]')) {
        if (!await confirmar({
          titulo: 'Quitar las fichas sacadas',
          texto: 'Se borran los ficheros de bak_config/download/user. Las personas '
               + 'no se tocan: esto sólo limpia la carpeta.',
          aceptar: 'Quitarlas',
        })) return;
        try {
          const r = await api.borrarExportadas();
          mensaje(r.mensaje, 'ok');
          await cargar();
        } catch (err) { mensaje(err.message, 'error'); }
      }
    };
  }

  /** Importar, de prueba o de verdad, enseñando qué ha pasado con cada uno. */
  async function importar(simular) {
    if (!simular) {
      if (!await confirmar({
        titulo: `Dar de alta ${datos.pendientes.length} persona(s)`,
        texto: 'Se crearán —o se actualizarán, si ya existen— y los ficheros se '
             + 'borrarán de la carpeta: llevan la contraseña inicial dentro. '
             + 'Si prefieres ver antes qué pasaría, usa «Probar sin crear nada».',
        aceptar: 'Darlas de alta',
      })) return;
    }

    let r;
    try { r = await api.importarPersonas(simular); }
    catch (e) { mensaje(e.message, 'error'); return; }

    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="aviso aviso--${r.malas.length ? 'senal' : 'ok'}">
        ${icono(r.malas.length ? 'aviso' : 'ok')}<div>${esc(r.mensaje)}</div></div>

      ${r.hechas.length ? `
        <div class="seccion"><h2>${simular ? 'Se darían de alta' : 'Hechas'}</h2></div>
        <div class="filas">
          ${r.hechas.map((h) => `
            <div class="fila" style="cursor:default">${icono('ok')}
              <span class="fila__texto">${esc(h.usuario || h.fichero)}
                <small>${esc(h.fichero)} · ${esc(h.estado || '')}</small>
                ${h.aviso ? `<small style="color:var(--peligro)">${esc(h.aviso)}</small>` : ''}
                ${(h.errores || []).length
                  ? `<small style="color:var(--peligro)">${esc(h.errores.join(' '))}</small>` : ''}
              </span></div>`).join('')}
        </div>` : ''}

      ${r.malas.length ? `
        <div class="seccion"><h2 style="color:var(--peligro)">Con problemas</h2>
          <span class="rotulo">${r.malas.length}</span></div>
        <div class="filas">
          ${r.malas.map((m) => `
            <div class="fila" style="cursor:default;align-items:flex-start">${icono('aviso')}
              <span class="fila__texto">${esc(m.fichero)}
                <small style="color:var(--peligro)">${esc(m.motivo)}</small></span>
            </div>`).join('')}
        </div>
        <p style="font-size:13px;color:var(--tinta-3);margin:10px 2px">
          Esos ficheros no se han borrado: están en la carpeta con
          <code>.error</code> al final y un <code>.motivo.txt</code> al lado.
          Corrígelos, quítales el <code>.error</code> y vuelve a importar.</p>` : ''}`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = '<button type="button" class="btn btn--principal" data-ok>Entendido</button>';

    hoja({
      titulo: simular ? 'Prueba' : 'Alta por ficheros',
      cuerpo, pie,
      alAbrir: (h) => { $('[data-ok]', h).onclick = cerrarHoja; },
      alCerrar: () => { cargar(); },
    });
  }

  /** Si el navegador no deja guardar, al menos que se pueda copiar. */
  function verTexto(nombre, texto) {
    const cuerpo = document.createElement('div');
    cuerpo.innerHTML = `
      <div class="aviso aviso--info">${icono('info')}<div>
        Este navegador no ha dejado guardar el fichero. Copia el texto y
        pégalo en un archivo llamado <code>${esc(nombre)}</code>.</div></div>
      <pre class="codigo" style="max-height:50vh;overflow:auto">${esc(texto)}</pre>`;

    const pie = document.createElement('div');
    pie.style.display = 'contents';
    pie.innerHTML = `<button type="button" class="btn btn--contorno" data-copiar>Copiar</button>
                     <button type="button" class="btn btn--principal" data-ok>Cerrar</button>`;

    hoja({
      titulo: nombre, cuerpo, pie,
      alAbrir: (h) => {
        $('[data-ok]', h).onclick = cerrarHoja;
        $('[data-copiar]', h).onclick = async () => {
          try { await navigator.clipboard.writeText(texto); mensaje('Copiado.', 'ok'); }
          catch { mensaje('El navegador no ha dejado copiar.', 'aviso'); }
        };
      },
    });
  }

  await cargar();
  return { refrescar: cargar };
}
