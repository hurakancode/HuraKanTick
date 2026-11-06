-- =====================================================================
--  SSTT 5.0 — Avisos push
--
--  Hasta ahora el aviso se generaba cuando la aplicación preguntaba al
--  servidor. Con la aplicación cerrada nadie preguntaba, así que el
--  aviso esperaba a que alguien la abriera.
--
--  Con push es el servidor quien empuja. Dos caminos distintos:
--
--    · navegador  → Web Push (RFC 8291), con claves VAPID
--    · APK        → Firebase, que es lo único que Android admite
--
--  Los dos guardan lo mismo aquí: a quién avisar y por dónde.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.5.0.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS push_destinos (
  id_destino  INTEGER PRIMARY KEY AUTOINCREMENT,
  id_login    INTEGER NOT NULL REFERENCES login(id_login),
  tipo        TEXT NOT NULL,            -- web | android
  -- Web: la dirección que da el navegador. Android: el testigo de Firebase.
  destino     TEXT NOT NULL,
  -- Sólo web: las dos claves que hacen falta para cifrar el mensaje.
  clave_p256  TEXT,
  clave_auth  TEXT,
  aparato     TEXT,                     -- para que se reconozca en la lista
  creado      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  ultimo_uso  TEXT,
  fallos      INTEGER NOT NULL DEFAULT 0,
  activo      INTEGER NOT NULL DEFAULT 1
);

CREATE UNIQUE INDEX IF NOT EXISTS ix_push_destino ON push_destinos(destino);
CREATE INDEX IF NOT EXISTS ix_push_login ON push_destinos(id_login, activo);

INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES

('mod_push','1','interruptor','modulos','Avisos con la aplicación cerrada',
 'Empuja los avisos al móvil aunque nadie tenga la aplicación abierta. Necesita configurarse una vez.',NULL,27),

('push_vapid_publica','','texto','push',
 'Clave pública VAPID',
 'Se genera sola la primera vez. El navegador la usa para reconocer a este servidor.',NULL,1),

('push_vapid_privada','','texto','push',
 'Clave privada VAPID',
 'No se enseña nunca ni sale del servidor. Si se pierde, hay que volver a suscribir todos los navegadores.',NULL,2),

('push_vapid_contacto','','texto','push',
 'Correo de contacto',
 'Los servicios de push lo piden para avisar si algo va mal. Un correo de administración vale.',NULL,3),

('push_fcm_cuenta','','texto','push',
 'Cuenta de servicio de Firebase',
 'El fichero JSON que da Firebase, pegado entero. Hace falta sólo para el APK.',NULL,4),

('push_solo_urgentes','0','interruptor','push',
 'Empujar sólo lo urgente',
 'Con la aplicación cerrada, avisar únicamente de partes urgentes y plazos vencidos.',NULL,5);
