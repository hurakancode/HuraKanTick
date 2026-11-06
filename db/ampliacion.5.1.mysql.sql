SET NAMES utf8mb4;

-- =====================================================================
--  SSTT 5.1 — Qué es común y qué es de cada hotel
--
--  La diferencia importa más de lo que parece:
--
--    · COMÚN: los departamentos, los tipos de avería, las familias, los
--      estados y las prioridades. «Servicios Técnicos» significa lo
--      mismo en todos los hoteles, y un informe de la cadena sólo tiene
--      sentido si es así.
--
--    · DE CADA HOTEL: las plantas, las zonas, los lugares, los números
--      de habitación, la gente y los partes. Aquí no hay nada que
--      compartir: el 305 de un hotel no tiene que ver con el 305 de otro.
--
--  Lo que faltaba era el eslabón entre las dos cosas: un hotel de treinta
--  habitaciones no tiene jardinería ni informática, y le sobra verlas en
--  cada lista desplegable. Ahora cada hotel elige del catálogo común los
--  departamentos que de verdad usa.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.5.1.mysql.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS hotel_departamento (
  id_hotel        INT NOT NULL REFERENCES hoteles(id_hotel),
  id_departamento INT NOT NULL REFERENCES departamentos(id_departamento),
  -- El nombre que ese hotel le da. Casi siempre vacío: sólo se usa
  -- cuando en la casa lo llaman de otra forma («Cocina» en vez de
  -- «Cocina / A&B»). El departamento sigue siendo el mismo por dentro,
  -- así que los informes de la cadena siguen cuadrando.
  alias           VARCHAR(80),
  orden           INT NOT NULL DEFAULT 0,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_hotel, id_departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `hotel_departamento` ADD KEY `ix_hotdep_hotel` (id_hotel);

-- Lo que les faltaba a los departamentos para poder gestionarse.
-- «color» y «activo» ya existían desde antes.
ALTER TABLE departamentos ADD COLUMN icono VARCHAR(40);
ALTER TABLE departamentos ADD COLUMN orden INT NOT NULL DEFAULT 0;

-- Los hoteles que ya existen se quedan con todos: es lo que tenían
-- hasta ahora, y quitarles algo sin avisar sería peor.
INSERT IGNORE INTO hotel_departamento (id_hotel, id_departamento, orden)
SELECT h.id_hotel, d.id_departamento, d.id_departamento
  FROM hoteles h CROSS JOIN departamentos d;

-- Cuánto se guarda lo borrado antes de tirarlo de verdad.
INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, orden)
VALUES ('dias_papelera', '180', 'numero', 'mantenimiento', 'Días que se guarda lo borrado',
        'Pasado ese plazo la papelera se vacía sola. Existe para arreglar un borrado por error, y esos se descubren en días, no en meses.', 30);
