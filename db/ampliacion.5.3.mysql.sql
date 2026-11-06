SET NAMES utf8mb4;

-- =====================================================================
--  SSTT 5.3 — Nombre de usuario y enlace de auto-entrada  (MySQL)
--
--  Ver db/ampliacion.5.3.sqlite.sql para el porqué de cada cosa.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.5.3.mysql.sql
-- =====================================================================

-- --- 1. El nombre de usuario ----------------------------------------

ALTER TABLE `login` ADD COLUMN `usuario` VARCHAR(60) NULL;
ALTER TABLE `login` ADD COLUMN `usuario_plano` VARCHAR(60) NULL;
ALTER TABLE `login` ADD UNIQUE KEY `uq_login_usuario` (`usuario_plano`);

-- --- 2. El enlace de auto-entrada ------------------------------------

ALTER TABLE `login` ADD COLUMN `autologin_hash` VARCHAR(64) NULL;
ALTER TABLE `login` ADD COLUMN `autologin_id` VARCHAR(40) NULL;
ALTER TABLE `login` ADD COLUMN `autologin_activo` TINYINT NOT NULL DEFAULT 0;
ALTER TABLE `login` ADD COLUMN `autologin_creado` DATETIME NULL;
ALTER TABLE `login` ADD COLUMN `autologin_ultimo_uso` DATETIME NULL;
ALTER TABLE `login` ADD COLUMN `autologin_usos` INT NOT NULL DEFAULT 0;
ALTER TABLE `login` ADD UNIQUE KEY `uq_login_autologin` (`autologin_id`);

-- --- 2 bis. El correo deja de ser obligatorio ------------------------
--  MySQL sí sabe cambiar una columna en sitio.

ALTER TABLE `login` MODIFY `correo` VARCHAR(190) NULL;

-- --- 3. Los ajustes --------------------------------------------------

INSERT IGNORE INTO ajustes_sistema
  (clave, valor, tipo, categoria, titulo, descripcion, orden)
VALUES
  ('autologin_global', '1', 'interruptor', 'seguridad',
   'Permitir enlaces de auto-entrada',
   CONCAT('Con esto apagado ningún enlace funciona, aunque esté activo para ',
          'alguien. Es el interruptor general: sirve para cortar de golpe si hace falta.'),
   60),

  ('autologin_dias', '0', 'numero', 'seguridad',
   'Días que vale un enlace de auto-entrada',
   CONCAT('Pasados esos días el enlace caduca y hay que regenerarlo. Con 0 no ',
          'caduca, que es lo que interesa en una tablet fija de recepción.'),
   61),

  ('correo_obligatorio', '0', 'interruptor', 'seguridad',
   'Exigir correo a todo el mundo',
   CONCAT('Normalmente no: hay cuentas de puesto que no tienen correo. Actívalo ',
          'sólo si en tu hotel todo el mundo debe tener uno.'),
   62);
