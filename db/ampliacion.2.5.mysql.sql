SET NAMES utf8mb4;

-- =====================================================================
--  SSTT 2.5 — Mantenimiento programado
--
--  Un hotel no sólo repara lo que se rompe: tiene trabajos que toca
--  hacer aunque nadie avise, y algunos son de obligado cumplimiento.
--  Revisión de extintores, control de legionela, limpieza de filtros,
--  inspección de ascensores, desinfección de depósitos.
--
--  Sin esto hay que acordarse a mano, y lo que se lleva a mano se
--  olvida justo el mes que viene la inspección.
--
--  Cada mantenimiento genera un parte normal cuando le toca: con su
--  historial, sus fotos y su firma de quien lo hizo. Eso es justo lo
--  que hace falta para demostrar que se hizo.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.2.5.mysql.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `mantenimientos` (
  id_mantenimiento INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120)    NOT NULL,
  descripcion   TEXT,

  -- Qué se revisa y dónde
  id_familia    INT REFERENCES familias(id_familia),
  id_elemento   INT REFERENCES elemento(id_elemento),
  id_rel_zona   INT REFERENCES rel_zonas(id_rel),

  -- Cada cuánto
  intervalo     INT NOT NULL DEFAULT 1,
  unidad        VARCHAR(10)    NOT NULL DEFAULT 'meses',   -- dias | semanas | meses | anios
  proximo       DATE    NOT NULL,                   -- fecha del siguiente
  aviso_dias    INT NOT NULL DEFAULT 3,         -- se crea con antelación

  -- Quién y con qué prioridad
  id_departamento INT REFERENCES departamentos(id_departamento),
  id_persona    INT REFERENCES login(id_login),
  id_prioridad  INT REFERENCES prioridades(id_prioridad),

  -- Control
  obligatorio   INT NOT NULL DEFAULT 0,   -- exigido por normativa
  activo        INT NOT NULL DEFAULT 1,
  ultimo_generado DATETIME,
  ultimo_ticket INT REFERENCES ticket_incidencias(id_ticket),
  veces         INT NOT NULL DEFAULT 0,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `mantenimientos` ADD KEY `ix_mant_proximo` (activo, proximo);

-- El parte sabe de qué revisión viene: así se puede ver el historial
-- completo de «revisión de extintores» y demostrarlo en una inspección.
ALTER TABLE `ticket_incidencias` ADD COLUMN `id_mantenimiento` INT NULL;
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_mant` (id_mantenimiento);

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_mantenimiento','1','interruptor','modulos','Mantenimiento programado',
  'Trabajos que se repiten: revisiones, cambios de filtro, inspecciones obligatorias.',NULL,23);
