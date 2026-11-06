-- =====================================================================
--  SSTT 5.8 — Mensajes para cada persona y frases del día
--
--  Tres cosas distintas que salen en el inicio, cada una con su aspa:
--
--    · Bienvenida — una sola vez, la primera vez que entra. Viene
--      desactivada: no se le suelta un mensaje a nadie sin quererlo.
--    · Mensaje del próximo inicio — lo escribe un administrador para una
--      persona y se enseña una vez. Sirve para «pasa por administración»
--      o «hoy cubres a Fany».
--    · Frase del día — una frase distinta cada día, sin repetir hasta
--      agotar las de sus categorías. Se apunta cuál ha leído cada uno.
-- =====================================================================

ALTER TABLE login ADD COLUMN mensaje_bienvenida   TEXT;
ALTER TABLE login ADD COLUMN bienvenida_activa    INTEGER NOT NULL DEFAULT 0;
ALTER TABLE login ADD COLUMN bienvenida_vista     TEXT;
ALTER TABLE login ADD COLUMN mensaje_proximo      TEXT;
ALTER TABLE login ADD COLUMN mensaje_proximo_visto TEXT;
ALTER TABLE login ADD COLUMN frases_activas       INTEGER NOT NULL DEFAULT 0;
ALTER TABLE login ADD COLUMN frases_categorias    TEXT;

CREATE TABLE IF NOT EXISTS frases_categorias (
  id_categoria INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre       TEXT    NOT NULL,
  activo       INTEGER NOT NULL DEFAULT 1,
  updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE IF NOT EXISTS frases (
  id_frase     INTEGER PRIMARY KEY AUTOINCREMENT,
  texto        TEXT    NOT NULL,
  autor        TEXT,
  id_categoria INTEGER REFERENCES frases_categorias(id_categoria),
  activo       INTEGER NOT NULL DEFAULT 1,
  updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_frases_cat ON frases(activo, id_categoria);

-- Qué ha leído cada uno, para no repetir.
CREATE TABLE IF NOT EXISTS frases_leidas (
  id_login  INTEGER NOT NULL,
  id_frase  INTEGER NOT NULL,
  fecha     TEXT    NOT NULL,
  PRIMARY KEY (id_login, id_frase)
);
CREATE INDEX IF NOT EXISTS ix_leidas_fecha ON frases_leidas(id_login, fecha);

-- Sólo si faltan: esta ampliación se ejecuta en cada actualización, y
-- con un INSERT a secas se creaban cuatro categorías más cada vez.
INSERT INTO frases_categorias (nombre)
SELECT 'Ánimo' WHERE NOT EXISTS (SELECT 1 FROM frases_categorias WHERE nombre = 'Ánimo');
INSERT INTO frases_categorias (nombre)
SELECT 'Trabajo en equipo' WHERE NOT EXISTS (SELECT 1 FROM frases_categorias WHERE nombre = 'Trabajo en equipo');
INSERT INTO frases_categorias (nombre)
SELECT 'Hostelería' WHERE NOT EXISTS (SELECT 1 FROM frases_categorias WHERE nombre = 'Hostelería');
INSERT INTO frases_categorias (nombre)
SELECT 'Seguridad' WHERE NOT EXISTS (SELECT 1 FROM frases_categorias WHERE nombre = 'Seguridad');
