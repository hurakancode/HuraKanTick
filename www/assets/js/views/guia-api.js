/* =====================================================================
   Guía de la API.

   Para quien tenga que conectar otro programa con HuraKan Tick: el SAP del
   hotel, una pantalla de recepción, un script que dé partes desde el
   sistema de control. Nada de esto hace falta para usar la aplicación.

   Sólo la ve el superadministrador —de fábrica—, porque enseña cómo
   entrar por la puerta de atrás y no es información para el día a día
   de un hotel. El ajuste «guia_api_para_todos» lo abre a los demás
   administradores si hiciera falta.
   ===================================================================== */

import { api } from '../api.js';
import { $, $$, icono, esc, mensaje, buscadorFilas } from '../ui.js';
import { cabeceraVolver } from '../piezas.js';
import { pintarCabecera, app, ajuste } from '../app.js';

/* Las rutas, agrupadas como se usan. No es la lista entera de lo que
   responde el servidor: es lo que un programa de fuera necesita. */
const GRUPOS = [
  {
    nombre: 'Entrar',
    nota: 'Todo lo demás necesita el testigo que devuelve esto.',
    rutas: [
      ['POST', '/auth/login', 'Usuario y contraseña. Devuelve el testigo.'],
      ['GET', '/auth/yo', 'Quién soy y qué puedo hacer.'],
      ['POST', '/auth/logout', 'Cerrar la sesión y anular el testigo.'],
      ['GET', '/auth/sesiones', 'Desde qué aparatos hay sesión abierta.'],
    ],
  },
  {
    nombre: 'Partes',
    nota: 'El grueso del sistema.',
    rutas: [
      ['GET', '/tickets', 'Listado, con filtros por estado, grupo, fecha…'],
      ['POST', '/tickets', 'Dar un parte.'],
      ['GET', '/tickets/{id}', 'Uno concreto.'],
      ['PATCH', '/tickets/{id}', 'Cambiar estado, asignar, añadir nota.'],
      ['DELETE', '/tickets/{id}', 'Ocultar. Con ?definitivo=1 va a la papelera.'],
      ['POST', '/tickets/lote', 'Actuar sobre varios a la vez.'],
      ['GET', '/tickets/{id}/historial', 'Todo lo que le ha pasado.'],
      ['POST', '/tickets/{id}/movimientos', 'Registrar un movimiento.'],
      ['GET', '/tickets/{id}/adjuntos', 'Sus fotos.'],
      ['POST', '/tickets/{id}/adjuntos', 'Subir una foto (multipart).'],
      ['GET', '/tickets/{id}/repetidos', 'Qué más ha pasado con ese tipo, ahí.'],
      ['GET', '/tickets/{id}/chat', 'La conversación, con quién ha leído cada mensaje.'],
      ['POST', '/tickets/{id}/chat', 'Escribir (texto, importante).'],
      ['POST', '/tickets/{id}/chat/leido', 'Marcar como leídos los pendientes.'],
      ['GET', '/tickets/{id}/observadores', 'Quién sigue el parte sin trabajarlo.'],
      ['POST', '/tickets/{id}/observadores', 'Añadir persona o departamento.'],
    ],
  },
  {
    nombre: 'Catálogo y sitios',
    nota: 'Lo que hace falta para dar un parte desde fuera.',
    rutas: [
      ['GET', '/catalogo', 'Tipos, áreas, departamentos, prioridades y ajustes.'],
      ['GET', '/ubicaciones/buscar?q=214', 'Buscar un sitio por nombre o número.'],
      ['GET', '/ubicaciones/{id}', 'La ficha del sitio.'],
      ['GET', '/ubicaciones/abiertos?zona=N', 'Partes abiertos ahí: evita duplicados.'],
      ['GET', '/campos?elemento=N', 'Los campos propios de ese tipo de avería.'],
      ['GET', '/unidades', 'Aparatos con número de serie.'],
      ['GET', '/unidades/{id}', 'Uno, con su historial.'],
    ],
  },
  {
    nombre: 'Informes',
    nota: 'Para sacar datos a otro sistema.',
    rutas: [
      ['GET', '/informes/definiciones', 'Campos, operadores y medidas disponibles.'],
      ['POST', '/informes/ejecutar', 'Ejecutar uno sin guardarlo.'],
      ['GET', '/informes', 'Los guardados que puede ver quien pregunta.'],
      ['POST', '/informes', 'Guardar uno (alcance: yo, departamento o todos).'],
    ],
  },
  {
    nombre: 'Sincronización',
    nota: 'Lo que usa la aplicación del móvil. Sirve para replicar datos.',
    rutas: [
      ['GET', '/sync/pull?desde=…', 'Lo que ha cambiado desde una fecha.'],
      ['POST', '/sync/push', 'Subir lo hecho sin cobertura.'],
      ['GET', '/estado', 'Sin sesión: si está instalada y su versión.'],
    ],
  },
  {
    nombre: 'Módulo SAP',
    nota: 'Sólo responden si el módulo está encendido. Cada dato se recorta según el alcance de quien pregunta.',
    rutas: [
      ['GET', '/sap/estado', 'Si está configurado y qué puede ver quien pregunta.'],
      ['GET', '/sap/habitacion?numero=214', 'Ocupación, estado, huéspedes y fechas.'],
      ['POST', '/sap/probar', 'Prueba de conexión (administradores).'],
    ],
  },
  {
    nombre: 'Administración',
    nota: 'Requiere ser administrador.',
    rutas: [
      ['GET', '/usuarios', 'El equipo.'],
      ['POST', '/usuarios', 'Alta de una persona.'],
      ['PATCH', '/usuarios/{id}', 'Editar, bloquear, cambiar papel.'],
      ['GET', '/departamentos', 'El catálogo de departamentos.'],
      ['GET', '/hoteles', 'Los hoteles que puede ver quien pregunta.'],
      ['GET', '/papelera', 'Lo eliminado definitivamente.'],
      ['POST', '/papelera/{id}/restaurar', 'Devolverlo.'],
      ['POST', '/copias', 'Hacer una copia de seguridad.'],
      ['GET', '/ajustes', 'La configuración.'],
      ['PATCH', '/ajustes', 'Cambiarla.'],
      ['POST', '/sistema/renovar', 'Que todos los móviles vuelvan a descargar.'],
    ],
  },
];

export async function vistaGuiaApi({ contenido }) {
  pintarCabecera(cabeceraVolver('Guía de la API'));

  const base = location.origin + location.pathname.replace(/\/www\/.*$/, '/api/index.php');

  contenido.innerHTML = `
    <div class="aviso aviso--info">${icono('info')}<div>
      Esto es para conectar OTRO programa con HuraKan Tick. Para usar la aplicación no
      hace falta nada de esto.
    </div></div>

    <div class="seccion"><h2>Cómo se llama</h2></div>
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <p style="margin:0 0 10px">Todo cuelga de una sola dirección, y la ruta va
        en <code>_ruta</code>:</p>
      <pre class="codigo">${esc(base)}?_ruta=tickets</pre>
      <p style="margin:10px 0 0;color:var(--tinta-3);font-size:13.5px">
        Las respuestas son JSON y siempre traen <code>ok</code>. Cuando algo va
        mal, <code>ok: false</code> y <code>error</code> con el motivo en
        castellano, pensado para enseñárselo a una persona.</p>
    </div></div>

    <div class="seccion"><h2>Entrar</h2></div>
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <pre class="codigo">curl -X POST "${esc(base)}?_ruta=auth/login" \\
  -H "Content-Type: application/json" \\
  -d '{"correo":"usuario","password":"la contraseña"}'</pre>
      <p style="margin:10px 0">Devuelve un testigo que vale 30 días. A partir
        de ahí va en la cabecera de cada llamada:</p>
      <pre class="codigo">curl "${esc(base)}?_ruta=tickets" \\
  -H "Authorization: Bearer EL_TESTIGO"</pre>
      <p style="margin:10px 0 0;color:var(--tinta-3);font-size:13.5px">
        Para un programa que trabaje solo, crea una persona con el papel que le
        corresponda —normalmente «sólo avisar»— en vez de usar la cuenta de un
        administrador. Si algo va mal, se ve en el registro de seguridad a
        nombre de quién.</p>
    </div></div>

    <div class="seccion"><h2>Dar un parte desde fuera</h2>
      <span class="rotulo">lo más pedido</span></div>
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <pre class="codigo">curl -X POST "${esc(base)}?_ruta=tickets" \\
  -H "Authorization: Bearer EL_TESTIGO" \\
  -H "Content-Type: application/json" \\
  -d '{
    "titulo": "El aire de la 214 no enfría",
    "id_elemento": 5,
    "id_rel_zona": 1762,
    "id_prioridad": 2,
    "detalle": "Lo ha avisado el cliente",
    "campos": { "temp_sala": 27, "sintoma_aire": "No enfría" }
  }'</pre>
      <p style="margin:10px 0 0;color:var(--tinta-3);font-size:13.5px">
        Los números salen de <code>/catalogo</code> y de
        <code>/ubicaciones/buscar</code>. El departamento no hace falta
        mandarlo: lo decide el sistema según el tipo de avería.</p>
    </div></div>

    <div class="buscador" style="margin:16px 0 4px">
      ${icono('buscar')}
      <input type="search" id="b-rutas" placeholder="Buscar una ruta" autocomplete="off">
    </div>
    <div id="lista-rutas"></div>

    <div class="seccion"><h2>Lo que conviene saber</h2></div>
    <div class="tarjeta"><div class="tarjeta__cuerpo">
      <dl class="datos">
        <div class="datos__celda"><dt>Cuántas llamadas</dt>
          <dd>Hay un límite por minuto y por aparato. Si se pasa, responde 429;
              espera y reintenta.</dd></div>
        <div class="datos__celda"><dt>Fechas</dt>
          <dd>Siempre en UTC, formato <code>2026-09-27 14:30:00</code>.</dd></div>
        <div class="datos__celda"><dt>Varios hoteles</dt>
          <dd>Cada testigo ve su hotel. Un parte cae en el hotel de quien lo
              da, no hace falta indicarlo.</dd></div>
        <div class="datos__celda"><dt>Fotos</dt>
          <dd>Se suben en <code>multipart/form-data</code> con el campo
              <code>archivo</code>. Se descargan por
              <code>/adjuntos/{id}</code>, que comprueba permisos.</dd></div>
        <div class="datos__celda"><dt>Repetir una llamada</dt>
          <dd>Dar un parte con el mismo <code>uuid</code> no lo duplica:
              devuelve el que ya existe. Así puede reintentarse sin miedo.</dd></div>
      </dl>
    </div></div>`;

  /* Las rutas, en una lista que se puede buscar: son setenta y pico y
     leerlas de corrido no ayuda a nadie. */
  const pintarRutas = (filtro = '') => {
    const q = filtro.trim().toLowerCase();
    const caja = $('#lista-rutas', contenido);
    caja.innerHTML = GRUPOS.map((g) => {
      const rutas = g.rutas.filter((r) =>
        !q || (r[1] + ' ' + r[2] + ' ' + g.nombre).toLowerCase().includes(q));
      if (!rutas.length) return '';
      return `
        <div class="seccion"><h2>${esc(g.nombre)}</h2>
          <span class="rotulo">${rutas.length}</span></div>
        ${g.nota ? `<span class="rotulo" style="display:block;margin:-4px 0 8px">${esc(g.nota)}</span>` : ''}
        <div class="tarjeta"><div class="tarjeta__cuerpo" style="padding:4px 0">
          ${rutas.map((r) => `
            <div class="ruta-api">
              <span class="ruta-api__metodo ruta-api__metodo--${r[0].toLowerCase()}">${r[0]}</span>
              <div>
                <code>${esc(r[1])}</code>
                <small>${esc(r[2])}</small>
              </div>
            </div>`).join('')}
        </div></div>`;
    }).join('') || `<div class="tarjeta"><div class="tarjeta__cuerpo">
        <p style="margin:0;color:var(--tinta-3)">Ninguna ruta con eso.</p></div></div>`;
  };

  pintarRutas();
  const buscador = $('#b-rutas', contenido);
  if (buscador) buscador.oninput = () => pintarRutas(buscador.value);

  return {};
}
