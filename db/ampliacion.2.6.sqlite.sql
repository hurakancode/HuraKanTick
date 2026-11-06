-- =====================================================================
--  SSTT 2.6 — Seguridad, cuenta y avisos
--
--    · Registro de seguridad: quién entra, desde dónde y qué cambia
--    · Modo mantenimiento: cerrar el acceso sin apagar el servidor
--    · Recuperación de contraseña y cambio de correo
--    · Horario de silencio y sonido por tipo de aviso
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.6.sqlite.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- Registro de seguridad
--
-- Se guarda la dirección IP porque es lo que permite reconstruir un
-- acceso indebido, pero es un dato personal: se conserva 90 días y se
-- borra sola. Tenerla para siempre no aporta y sí obliga.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registro_seguridad (
  id_registro INTEGER PRIMARY KEY AUTOINCREMENT,
  tipo        TEXT NOT NULL,
      -- acceso | acceso_fallido | salida | password | correo
      -- bloqueo | desbloqueo | alta_usuario | rol | ajuste
      -- copia | restauracion | mantenimiento | borrado
  id_login    INTEGER REFERENCES login(id_login),
  correo      TEXT,
  ip          TEXT,
  agente      TEXT,
  detalle     TEXT,
  exito       INTEGER NOT NULL DEFAULT 1,
  fecha       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_reg_fecha ON registro_seguridad(fecha);
CREATE INDEX IF NOT EXISTS ix_reg_login ON registro_seguridad(id_login, fecha);
CREATE INDEX IF NOT EXISTS ix_reg_tipo  ON registro_seguridad(tipo, fecha);

-- ---------------------------------------------------------------------
-- Recuperación de contraseña
--
-- Sin servidor de correo configurado, el código lo entrega un
-- administrador en persona. Es lo razonable en un hotel: la gente está
-- en el mismo edificio y así no hay que montar SMTP para esto.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recuperaciones (
  id_recuperacion INTEGER PRIMARY KEY AUTOINCREMENT,
  id_login    INTEGER NOT NULL REFERENCES login(id_login),
  codigo_hash TEXT    NOT NULL,
  creado      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  expira      TEXT    NOT NULL,
  usado       INTEGER NOT NULL DEFAULT 0,
  ip_solicita TEXT,
  id_emisor   INTEGER REFERENCES login(id_login),
  intentos    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_recup_login ON recuperaciones(id_login, usado);

-- ---------------------------------------------------------------------
-- Preferencias de aviso: sonido por evento y horario de silencio
-- ---------------------------------------------------------------------
-- avisos_pref ya tiene la columna «sonido» desde la 2.2.

-- El aviso recuerda de qué fue y si debe sonar en el móvil.
ALTER TABLE notificaciones ADD COLUMN evento TEXT;
ALTER TABLE notificaciones ADD COLUMN silenciosa INTEGER NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------
-- Ajustes nuevos
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES

-- Mantenimiento del sistema
('modo_mantenimiento','0','interruptor','mantenimiento',
 'Cerrar el acceso',
 'Nadie podrá entrar salvo los administradores. Útil para actualizar o restaurar una copia sin que nadie escriba a la vez.',NULL,1),
('mantenimiento_mensaje','Estamos actualizando la aplicación. Volvemos en unos minutos.','texto','mantenimiento',
 'Qué se les dice',
 'El texto que verá quien intente entrar.',NULL,2),

-- Seguridad
('registro_dias','90','numero','seguridad',
 'Días que se guarda el registro de seguridad',
 'Incluye direcciones IP, que son un dato personal: conservarlas más tiempo del necesario no aporta.',NULL,10),
('sesion_dias','30','numero','seguridad',
 'Días que dura la sesión en un aparato',
 'Pasado ese tiempo hay que volver a entrar. Menos días es más seguro y más incómodo.',NULL,11),
('intentos_maximos','8','numero','seguridad',
 'Intentos fallidos antes de bloquear la cuenta',NULL,NULL,12),
('recuperacion_activa','1','interruptor','seguridad',
 'Permitir pedir el restablecimiento de contraseña',
 'Quien la olvide puede pedirlo; un administrador le entrega el código.',NULL,13),
('cambio_correo','1','interruptor','seguridad',
 'Cada persona puede cambiar su correo',
 'Pide la contraseña para confirmarlo.',NULL,14),

-- Avisos
('silencio_activo','0','interruptor','avisos',
 'Horario de silencio por defecto',
 'Fuera del horario de trabajo los avisos llegan pero sin sonido.',NULL,37),
('silencio_desde','22:00','texto','avisos','Silencio desde',NULL,NULL,38),
('silencio_hasta','07:00','texto','avisos','Silencio hasta',NULL,NULL,39);
