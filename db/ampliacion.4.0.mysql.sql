SET NAMES utf8mb4;

-- Los índices van con ALTER TABLE ... ADD KEY: MySQL no entiende
-- «CREATE INDEX IF NOT EXISTS», y el instalador se tragaba el error como
-- un aviso. El resultado era una base sin índices: con veinte partes da
-- igual, con veinte mil el listado tarda segundos.

-- =====================================================================
--  SSTT 4.0 — Varios hoteles
--
--  Hasta ahora todo pertenecía a un hotel implícito. Ahora hay una
--  tabla de hoteles y cada cosa sabe de cuál es.
--
--  QUÉ ES DE CADA HOTEL Y QUÉ SE COMPARTE
--
--  Propio de cada hotel, porque cambia de uno a otro:
--    · las ubicaciones enteras (plantas, zonas, lugares, sublugares)
--    · los partes, y todo lo que cuelga de ellos
--    · las personas
--    · los aparatos con número de serie
--    · el mantenimiento programado y las instrucciones
--    · los informes guardados y los atajos
--
--  Compartido, porque es igual en todos y duplicarlo sólo daría trabajo:
--    · los 313 tipos de avería y sus 16 familias
--    · estados, prioridades y departamentos
--
--  El aislamiento es lo importante: un parte de un hotel no puede verse
--  desde otro bajo ningún concepto, ni siquiera escribiendo su número
--  en la dirección.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.4.0.mysql.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `hoteles` (
  id_hotel   INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(150) NOT NULL,
  codigo     VARCHAR(12),                 -- abreviatura corta, para los códigos de parte
  direccion  VARCHAR(200),
  ciudad     VARCHAR(80),
  telefono   VARCHAR(40),
  color      VARCHAR(9) DEFAULT '#0B5563',
  activo     INT NOT NULL DEFAULT 1,
  notas      TEXT,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El hotel que ya existía. Todo lo que hay ahora es suyo.
INSERT IGNORE INTO hoteles (id_hotel, nombre, codigo, activo)
VALUES (1, 'Hotel Benalma', 'BEN', 1);

-- ---------------------------------------------------------------------
-- Cada tabla propia de un hotel gana su columna
-- ---------------------------------------------------------------------
ALTER TABLE `login` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `ticket_incidencias` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `rel_zonas` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `zona_1` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `zona_2` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `zona_3` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `zona_4` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `unidades` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `mantenimientos` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `instrucciones` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `informes` ADD COLUMN `id_hotel` INT NULL;
ALTER TABLE `atajos` ADD COLUMN `id_hotel` INT NULL;

-- Todo lo existente es del hotel 1
UPDATE login              SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE ticket_incidencias SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE rel_zonas          SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE zona_1             SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE zona_2             SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE zona_3             SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE zona_4             SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE unidades           SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE mantenimientos     SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE instrucciones      SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE informes           SET id_hotel = 1 WHERE id_hotel IS NULL;
UPDATE atajos             SET id_hotel = 1 WHERE id_hotel IS NULL;

ALTER TABLE `login` ADD KEY `ix_login_hotel` (id_hotel);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_hotel` (id_hotel, borrado);
ALTER TABLE `rel_zonas` ADD KEY `ix_rel_hotel` (id_hotel);
ALTER TABLE `unidades` ADD KEY `ix_unid_hotel` (id_hotel);

-- ---------------------------------------------------------------------
-- Rol de superadministrador
--
-- Se guarda aparte del rol para no tocar los que ya existen: «admin»
-- pasa a significar administrador de SU hotel, que es lo que ya hacía.
-- ---------------------------------------------------------------------
ALTER TABLE `login` ADD COLUMN `superadmin` TINYINT(1) NOT NULL DEFAULT 0;

-- El primer administrador pasa a ser superadministrador: si no, nadie
-- podría crear el segundo hotel.
UPDATE login SET superadmin = 1
 WHERE id_login = (SELECT MIN(id_login) FROM login WHERE admin = 1);

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_hoteles','1','interruptor','modulos','Varios hoteles',
  'Cada hotel con sus ubicaciones, sus partes y su gente. Nada se mezcla.',NULL,26);
