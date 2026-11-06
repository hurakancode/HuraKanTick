-- =====================================================================
--  SSTT 2.3 — Familias de elementos
--
--  Al mirar los 313 elementos reales aparece algo que no es evidente
--  desde el esquema: el catálogo no es de averías. Mezcla tres cosas
--  que en un hotel se gestionan de forma distinta:
--
--    · Averías          grifo que gotea, ascensor parado, TV sin señal
--    · Peticiones       cama extra, cuna, juntar camas, salida tarde
--    · Tareas           repaso de limpieza, segunda limpieza, revisión
--
--  Una petición de cama extra no es una incidencia: no tiene «avería»
--  que reparar, la pide recepción, la hace housekeeping y su plazo lo
--  marca la llegada del cliente. Contarlas juntas hace que «tuvimos 400
--  incidencias este mes» no signifique nada.
--
--  Por eso la familia lleva dos datos: a qué oficio pertenece y de qué
--  clase de trabajo se trata.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.3.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS familias (
  id_familia      INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre          TEXT    NOT NULL,
  clase           TEXT    NOT NULL DEFAULT 'averia',   -- averia | peticion | tarea
  icono           TEXT    NOT NULL DEFAULT 'llave-inglesa',
  color           TEXT    NOT NULL DEFAULT '#0B5563',
  id_departamento INTEGER REFERENCES departamentos(id_departamento),
  id_prioridad    INTEGER REFERENCES prioridades(id_prioridad),
  descripcion     TEXT,
  orden           INTEGER NOT NULL DEFAULT 0,
  activo          INTEGER NOT NULL DEFAULT 1,
  updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

ALTER TABLE elemento ADD COLUMN id_familia INTEGER REFERENCES familias(id_familia);
CREATE INDEX IF NOT EXISTS ix_elemento_familia ON elemento(id_familia);

-- El parte guarda su familia: así un informe puede agrupar por familia
-- aunque no se haya llegado a concretar el elemento, que es lo normal
-- cuando el aviso lo da recepción.
ALTER TABLE ticket_incidencias ADD COLUMN id_familia INTEGER REFERENCES familias(id_familia);
CREATE INDEX IF NOT EXISTS ix_ticket_familia ON ticket_incidencias(id_familia);

-- Los departamentos que faltan: las familias los referencian y sin ellos
-- la clave ajena impide la carga.
-- Los departamentos de hotel se han movido a paquetes/hotel/organizacion.sqlite.sql

-- ---------------------------------------------------------------------
-- Familias de un hotel
--
-- Los departamentos: 1 Servicios Técnicos · 2 Pisos/Limpieza
-- 3 Recepción · 4 Cocina/A&B · 5 Informática · 6 Jardinería · 7 Seguridad
-- ---------------------------------------------------------------------
-- Los familias de hotel se han movido a paquetes/hotel/organizacion.sqlite.sql

-- ---------------------------------------------------------------------
-- Ajustes de la función
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_familias','1','interruptor','modulos','Familias de elementos',
  'Agrupa los tipos de avería por oficio. Sin ellas, se elige de la lista completa.',NULL,20),
 ('familia_primero','1','interruptor','comportamiento','Elegir familia antes que elemento',
  'Recomendado: con más de 100 tipos, buscar a ciegas es lento. Recepción y limpieza suelen quedarse en la familia.',NULL,44),
 ('elemento_obligatorio','0','interruptor','comportamiento','Exigir el elemento concreto',
  'Si se apaga, basta con la familia. Quien avisa no siempre sabe si es la cisterna o el fluxor.',NULL,45),
 ('rutar_por_familia','1','interruptor','comportamiento','Repartir por familia',
  'El parte se asigna solo al grupo que lleva esa familia.',NULL,46);
