SET NAMES utf8mb4;
-- SSTT 5.5 — Que los móviles se enteren de los partes eliminados.
-- Ver db/ampliacion.5.5.sqlite.sql para el porqué.
--    mysql -u USUARIO -p BASE < db/ampliacion.5.5.mysql.sql

CREATE TABLE IF NOT EXISTS sync_bajas (
  id_ticket INT      NOT NULL PRIMARY KEY,
  fecha     DATETIME NOT NULL,
  KEY ix_sync_bajas_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
