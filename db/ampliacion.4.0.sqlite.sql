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
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.4.0.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS hoteles (
  id_hotel   INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre     TEXT NOT NULL,
  codigo     TEXT,                 -- abreviatura corta, para los códigos de parte
  direccion  TEXT,
  ciudad     TEXT,
  telefono   TEXT,
  color      TEXT DEFAULT '#0B5563',
  activo     INTEGER NOT NULL DEFAULT 1,
  notas      TEXT,
  creado     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

-- El hotel que ya existía. Todo lo que hay ahora es suyo.
INSERT OR IGNORE INTO hoteles (id_hotel, nombre, codigo, activo)
VALUES (1, 'Hotel Benalma', 'BEN', 1);

-- ---------------------------------------------------------------------
-- Cada tabla propia de un hotel gana su columna
-- ---------------------------------------------------------------------
ALTER TABLE login              ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE ticket_incidencias ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE rel_zonas          ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE zona_1             ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE zona_2             ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE zona_3             ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE zona_4             ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE unidades           ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE mantenimientos     ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE instrucciones      ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE informes           ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);
ALTER TABLE atajos             ADD COLUMN id_hotel INTEGER REFERENCES hoteles(id_hotel);

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

CREATE INDEX IF NOT EXISTS ix_login_hotel  ON login(id_hotel);
CREATE INDEX IF NOT EXISTS ix_ticket_hotel ON ticket_incidencias(id_hotel, borrado);
CREATE INDEX IF NOT EXISTS ix_rel_hotel    ON rel_zonas(id_hotel);
CREATE INDEX IF NOT EXISTS ix_unid_hotel   ON unidades(id_hotel);

-- ---------------------------------------------------------------------
-- Rol de superadministrador
--
-- Se guarda aparte del rol para no tocar los que ya existen: «admin»
-- pasa a significar administrador de SU hotel, que es lo que ya hacía.
-- ---------------------------------------------------------------------
ALTER TABLE login ADD COLUMN superadmin INTEGER NOT NULL DEFAULT 0;

-- El primer administrador pasa a ser superadministrador: si no, nadie
-- podría crear el segundo hotel.
UPDATE login SET superadmin = 1
 WHERE id_login = (SELECT MIN(id_login) FROM login WHERE admin = 1);

INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_hoteles','1','interruptor','modulos','Varios hoteles',
  'Cada hotel con sus ubicaciones, sus partes y su gente. Nada se mezcla.',NULL,26);
