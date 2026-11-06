/* Pantalla de entrada. */

import { api, fijarToken, base, fijarBase, ErrorRed } from '../api.js';
import { $, icono, esc, mensaje, hoja, cerrarHoja } from '../ui.js';

export function vistaAcceso({ raiz, alEntrar }) {
  raiz.innerHTML = `
  <div class="acceso">
    <div class="acceso__marca">
      <div class="acceso__logo">
        <img src="assets/icons/HuraKan_Tick_Logo.png" alt="HuraKan Tick Logo"
             onerror="this.parentNode &amp;&amp; this.parentNode.classList.add('acceso__logo--sin-imagen'); this.remove()">
        ${icono('herramientas')}
      </div>
      <h1>HuraKan Tick</h1>
      <p>Ticketing</p>
    </div>

    <form id="f-acceso" novalidate>
      <div id="error-acceso"></div>

      <div class="campo">
        <label for="correo">Usuario o correo</label>
        <!-- type="text", no "email": hay cuentas de puesto —«recepcion»,
             «pisos»— que no tienen correo, y con type=email el navegador
             las rechaza antes de llegar al servidor. Tampoco inputmode
             email, que saca un teclado con la arroba en primer plano. -->
        <input class="entrada" type="text" id="correo" name="correo"
               autocomplete="username" autocapitalize="none" spellcheck="false" required
               placeholder="recepcion  ·  nombre@empresa.com">
      </div>

      <div class="campo">
        <label for="password">Contraseña</label>
        <input class="entrada" type="password" id="password" name="password"
               autocomplete="current-password" required placeholder="········">
        <div class="campo__ayuda">
          <button type="button" class="btn--texto" id="ver-pwd"
                  style="border:0;background:none;padding:0;color:var(--marca);font-weight:640;cursor:pointer;font-size:12.5px">
            Mostrar contraseña
          </button>
        </div>
      </div>

      <button class="btn btn--principal btn--bloque" type="submit" id="b-entrar">Entrar</button>
    </form>

    <div class="acceso__pie">
      <button type="button" id="b-olvide" style="border:0;background:none;color:var(--marca);font-size:13px;
                                   font-weight:600;cursor:pointer;padding:8px;margin-bottom:6px">
        He olvidado la contraseña
      </button>

      <!-- El servidor se destaca cuando no está puesto: en el APK no se
           puede adivinar (Android sirve la app desde https://localhost) y
           sin él la entrada falla sin explicar por qué. -->
      <button type="button" id="b-servidor" class="${base() ? '' : 'acceso__servidor--falta'}"
              style="border:0;cursor:pointer;font-size:${base() ? '12px' : '14px'};
                     min-height:44px;padding:0 12px;border-radius:var(--r-s);
                     ${base()
                       ? 'background:none;color:var(--tinta-3);text-decoration:underline'
                       : 'background:var(--senal-tenue);color:var(--aviso);font-weight:700;width:100%'}">
        ${base()
          ? `Servidor: ${esc(base())}`
          : 'Indica la dirección del servidor para poder entrar'}
      </button>
      <div style="margin-top:6px;font-size:11px;color:var(--tinta-3)">
        Versión ${esc(document.querySelector('meta[name=hurakan-tick-version]')?.content || '?')}
      </div>
    </div>
  </div>`;

  const form = $('#f-acceso', raiz);
  const boton = $('#b-entrar', raiz);
  const cajaError = $('#error-acceso', raiz);

  /* Se llega aquí tras una actualización forzada: conviene decir por qué
     se ha cerrado la sesión, o parece que la aplicación ha fallado. */
  if (new URLSearchParams(location.search).get('renovada') === '1') {
    cajaError.innerHTML = `<div class="aviso aviso--ok">${icono('ok')}<div>
      La aplicación se ha actualizado y ha vuelto a descargarlo todo.
      Escribe otra vez tu usuario y tu contraseña.</div></div>`;
    history.replaceState(null, '', location.pathname + location.hash);
  }

  $('#ver-pwd', raiz).onclick = () => {
    const p = $('#password', raiz);
    const ver = p.type === 'password';
    p.type = ver ? 'text' : 'password';
    $('#ver-pwd', raiz).textContent = ver ? 'Ocultar contraseña' : 'Mostrar contraseña';
  };

  $('#b-servidor', raiz).onclick = () => configurarServidor(raiz);

  /* Sin dirección de servidor no se puede hacer nada, así que se pide
     nada más llegar en vez de esperar a que alguien encuentre el enlace.
     Pasa sobre todo en el APK recién instalado: Android sirve la
     aplicación desde https://localhost y no hay forma de deducirla. */
  if (!base()) {
    setTimeout(() => configurarServidor(raiz), 400);
  }
  $('#b-olvide', raiz).onclick = () => olvide(raiz);

  form.onsubmit = async (e) => {
    e.preventDefault();
    cajaError.innerHTML = '';

    const correo = $('#correo', raiz).value.trim();
    const password = $('#password', raiz).value;

    if (!correo || !password) {
      cajaError.innerHTML = aviso('Escribe tu usuario y la contraseña.');
      return;
    }

    boton.disabled = true;
    boton.textContent = 'Entrando…';

    try {
      const r = await api.entrar(correo, password);
      fijarToken(r.token);
      await alEntrar(r.usuario);
    } catch (err) {
      boton.disabled = false;
      boton.textContent = 'Entrar';
      if (err instanceof ErrorRed || err.sinRed) {
        cajaError.innerHTML = aviso(
          'No se llega al servidor. Comprueba la conexión o la dirección configurada abajo.'
        );
      } else {
        cajaError.innerHTML = aviso(err.message);
        $('#password', raiz).value = '';
        $('#password', raiz).focus();
      }
    }
  };

  setTimeout(() => $('#correo', raiz)?.focus(), 200);
}

function aviso(texto) {
  return `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(texto)}</div></div>`;
}

/** Permite apuntar la app al servidor correcto (imprescindible en el APK). */
/**
 * Contraseña olvidada.
 *
 * Sin servidor de correo, el código lo entrega un administrador en
 * persona. Es lo razonable en un hotel: la gente está en el mismo
 * edificio y montar SMTP sólo para esto no compensa.
 */
function olvide(raiz) {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <p style="font-size:13.5px;color:var(--tinta-2);margin-bottom:14px">
      Pídelo y avisa a tu responsable: verá tu petición y te dará un código
      de seis dígitos.
    </p>
    <div class="campo"><label for="o-correo">Tu correo</label>
      <input class="entrada" id="o-correo" type="email" inputmode="email"
             autocomplete="email" placeholder="nombre@hotel.com"></div>
    <div id="o-paso1"></div>

    <details id="o-tengo" style="margin-top:10px">
      <summary style="cursor:pointer;font-size:13.5px;font-weight:640;color:var(--marca);padding:8px 2px">
        Ya tengo un código</summary>
      <div style="padding-top:10px">
        <div class="campo"><label for="o-codigo">Código de seis dígitos</label>
          <input class="entrada" id="o-codigo" inputmode="numeric" maxlength="6"
                 autocomplete="one-time-code"
                 style="font-family:var(--mono);font-size:22px;letter-spacing:.2em;text-align:center"></div>
        <div class="campo"><label for="o-nueva">Contraseña nueva</label>
          <input class="entrada" id="o-nueva" type="password" autocomplete="new-password">
          <div class="campo__ayuda">Al menos 8 caracteres.</div></div>
        <button type="button" class="btn btn--principal btn--bloque" data-usar>Cambiar la contraseña</button>
      </div>
    </details>

    <div id="o-err"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cerrar</button>
                   <button type="button" class="btn btn--principal" data-pedir>Pedirlo</button>`;

  hoja({
    titulo: 'Contraseña olvidada',
    cuerpo, pie,
    alAbrir: (h) => {
      const correoEntrada = $('#correo', raiz);
      if (correoEntrada?.value) $('#o-correo', h).value = correoEntrada.value;

      const err = (t) => { $('#o-err', h).innerHTML =
        `<div class="aviso aviso--error">${icono('aviso')}<div>${esc(t)}</div></div>`; };

      $('[data-no]', h).onclick = () => cerrarHoja();

      $('[data-pedir]', h).onclick = async () => {
        const correo = $('#o-correo', h).value.trim();
        if (!correo) return err('Escribe tu correo.');
        try {
          const r = await api.recuperar(correo);
          $('#o-err', h).innerHTML = '';
          $('#o-paso1', h).innerHTML =
            `<div class="aviso aviso--ok">${icono('ok')}<div>${esc(r.mensaje)}</div></div>`;
          $('#o-tengo', h).open = true;
        } catch (e) { err(e.message); }
      };

      $('[data-usar]', h).onclick = async () => {
        const correo = $('#o-correo', h).value.trim();
        const codigo = $('#o-codigo', h).value.trim();
        const nueva  = $('#o-nueva', h).value;
        if (!correo || !codigo || !nueva) return err('Rellena los tres campos.');
        if (nueva.length < 8) return err('La contraseña debe tener al menos 8 caracteres.');
        try {
          await api.entrarConCodigo({ correo, codigo, nueva });
          cerrarHoja();
          mensaje('Contraseña cambiada. Ya puedes entrar.', 'ok');
          const c = $('#correo', raiz);
          if (c) c.value = correo;
          $('#password', raiz)?.focus();
        } catch (e) { err(e.message); }
      };
    },
  });
}

function configurarServidor(raiz) {
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `
    <p style="font-size:13.5px;color:var(--tinta-2);margin-bottom:14px">
      La dirección donde está instalado el servidor. Termina en
      <strong>/api</strong>.
    </p>

    <div class="campo"><label for="s-url">Dirección</label>
      <input class="entrada" id="s-url" type="url" inputmode="url"
             autocapitalize="off" autocorrect="off" spellcheck="false"
             placeholder="https://partes.miempresa.com/api"
             value="${esc(base() || '')}">
      <div class="campo__ayuda">
        Si el servidor está en la red del hotel, algo como
        <span style="font-family:var(--mono)">http://192.168.1.50/hurakantick/api</span>
      </div>
    </div>

    <button type="button" class="btn btn--contorno btn--bloque btn--s" data-probar>
      ${icono('sync')}Probar la conexión
    </button>

    <div id="s-res" style="margin-top:12px"></div>`;

  const pie = document.createElement('div');
  pie.style.display = 'contents';
  pie.innerHTML = `<button type="button" class="btn btn--contorno" data-no>Cancelar</button>
                   <button type="button" class="btn btn--principal" data-si>Guardar</button>`;

  hoja({
    titulo: 'Servidor',
    cuerpo, pie,
    alAbrir: (h) => {
      const campo = $('#s-url', h);
      setTimeout(() => campo.focus(), 250);

      const decir = (clase, texto) => {
        $('#s-res', h).innerHTML =
          `<div class="aviso aviso--${clase}">${icono(clase === 'ok' ? 'ok' : 'aviso')}
            <div>${esc(texto)}</div></div>`;
      };

      /* Probar antes de guardar.
         Una dirección mal escrita se descubre si no al intentar entrar,
         y entonces el error habla de credenciales o de red y nadie
         relaciona una cosa con la otra. */
      $('[data-probar]', h).onclick = async () => {
        const url = campo.value.trim().replace(/\/+$/, '');
        if (!url) { decir('error', 'Escribe la dirección primero.'); return; }

        const b = $('[data-probar]', h);
        b.disabled = true;
        b.innerHTML = `${icono('sync', 'girando')}Probando…`;
        try {
          const r = await fetch(`${url}/index.php?_ruta=estado`, {
            headers: { Accept: 'application/json' },
            signal: AbortSignal.timeout(9000),
          });
          const d = await r.json().catch(() => null);
          if (r.ok && d && d.ok !== false) {
            decir('ok', `Responde correctamente${d.version ? ' · versión ' + d.version : ''}.`);
          } else if (r.status === 404) {
            decir('error', 'Ahí no hay un servidor de partes. ¿Falta «/api» al final?');
          } else {
            decir('error', `El servidor responde ${r.status}. Comprueba la dirección.`);
          }
        } catch (e) {
          decir('error', e.name === 'TimeoutError'
            ? 'No contesta. Comprueba que estás en la misma red y que la dirección es correcta.'
            : 'No se llega a esa dirección.');
        }
        b.disabled = false;
        b.innerHTML = `${icono('sync')}Probar la conexión`;
      };

      $('[data-no]', h).onclick = () => cerrarHoja();
      $('[data-si]', h).onclick = () => {
        fijarBase(campo.value.trim());
        cerrarHoja();
        mensaje('Servidor guardado.', 'ok');
        // Se repinta el acceso para que el pie muestre la nueva.
        window.location.reload();
      };
    },
  });
}
