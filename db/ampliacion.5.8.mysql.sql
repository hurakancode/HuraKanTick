SET NAMES utf8mb4;
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

ALTER TABLE login ADD COLUMN mensaje_bienvenida TEXT NULL;
ALTER TABLE login ADD COLUMN bienvenida_activa    TINYINT NOT NULL DEFAULT 0;
ALTER TABLE login ADD COLUMN bienvenida_vista DATETIME NULL;
ALTER TABLE login ADD COLUMN mensaje_proximo TEXT NULL;
ALTER TABLE login ADD COLUMN mensaje_proximo_visto DATETIME NULL;
ALTER TABLE login ADD COLUMN frases_activas       TINYINT NOT NULL DEFAULT 0;
ALTER TABLE login ADD COLUMN frases_categorias TEXT NULL;

CREATE TABLE IF NOT EXISTS frases_categorias (
  id_categoria INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(80) NOT NULL,
  activo       TINYINT NOT NULL DEFAULT 1,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS frases (
  id_frase     INT AUTO_INCREMENT PRIMARY KEY,
  texto        TEXT NOT NULL,
  autor        VARCHAR(120) NULL,
  id_categoria INT NULL,
  activo       TINYINT NOT NULL DEFAULT 1,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_frases_cat (activo, id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Qué ha leído cada uno, para no repetir.
CREATE TABLE IF NOT EXISTS frases_leidas (
  id_login INT NOT NULL,
  id_frase INT NOT NULL,
  fecha    DATETIME NOT NULL,
  PRIMARY KEY (id_login, id_frase),
  KEY ix_leidas_fecha (id_login, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sólo si faltan: esta ampliación se ejecuta en cada actualización, y
-- con un INSERT a secas se creaban cuatro categorías más cada vez.
INSERT INTO frases_categorias (nombre)
SELECT * FROM (SELECT 'Ánimo') AS t WHERE NOT EXISTS (SELECT 1 FROM frases_categorias c WHERE c.nombre = 'Ánimo');
INSERT INTO frases_categorias (nombre)
SELECT * FROM (SELECT 'Trabajo en equipo') AS t WHERE NOT EXISTS (SELECT 1 FROM frases_categorias c WHERE c.nombre = 'Trabajo en equipo');
INSERT INTO frases_categorias (nombre)
SELECT * FROM (SELECT 'Hostelería') AS t WHERE NOT EXISTS (SELECT 1 FROM frases_categorias c WHERE c.nombre = 'Hostelería');
INSERT INTO frases_categorias (nombre)
SELECT * FROM (SELECT 'Seguridad') AS t WHERE NOT EXISTS (SELECT 1 FROM frases_categorias c WHERE c.nombre = 'Seguridad');
