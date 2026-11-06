SET NAMES utf8mb4;

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
--    mysql -u USUARIO -p BASE < db/ampliacion.5.0.mysql.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS push_destinos (
  id_destino  INT AUTO_INCREMENT PRIMARY KEY,
  id_login    INT NOT NULL REFERENCES login(id_login),
  tipo        VARCHAR(12) NOT NULL,            -- web | android
  -- Web: la dirección que da el navegador. Android: el testigo de Firebase.
  destino     VARCHAR(512) NOT NULL,
  -- Sólo web: las dos claves que hacen falta para cifrar el mensaje.
  clave_p256  VARCHAR(200),
  clave_auth  VARCHAR(64),
  aparato     VARCHAR(120),                     -- para que se reconozca en la lista
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso  DATETIME,
  fallos      INT NOT NULL DEFAULT 0,
  activo      INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `push_destinos` ADD UNIQUE KEY `ix_push_destino` (destino(255));
ALTER TABLE `push_destinos` ADD KEY `ix_push_login` (id_login, activo);

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES

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
