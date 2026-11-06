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
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.5.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS mantenimientos (
  id_mantenimiento INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre        TEXT    NOT NULL,
  descripcion   TEXT,

  -- Qué se revisa y dónde
  id_familia    INTEGER REFERENCES familias(id_familia),
  id_elemento   INTEGER REFERENCES elemento(id_elemento),
  id_rel_zona   INTEGER REFERENCES rel_zonas(id_rel),

  -- Cada cuánto
  intervalo     INTEGER NOT NULL DEFAULT 1,
  unidad        TEXT    NOT NULL DEFAULT 'meses',   -- dias | semanas | meses | anios
  proximo       TEXT    NOT NULL,                   -- fecha del siguiente
  aviso_dias    INTEGER NOT NULL DEFAULT 3,         -- se crea con antelación

  -- Quién y con qué prioridad
  id_departamento INTEGER REFERENCES departamentos(id_departamento),
  id_persona    INTEGER REFERENCES login(id_login),
  id_prioridad  INTEGER REFERENCES prioridades(id_prioridad),

  -- Control
  obligatorio   INTEGER NOT NULL DEFAULT 0,   -- exigido por normativa
  activo        INTEGER NOT NULL DEFAULT 1,
  ultimo_generado TEXT,
  ultimo_ticket INTEGER REFERENCES ticket_incidencias(id_ticket),
  veces         INTEGER NOT NULL DEFAULT 0,
  creado        TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_mant_proximo ON mantenimientos(activo, proximo);

-- El parte sabe de qué revisión viene: así se puede ver el historial
-- completo de «revisión de extintores» y demostrarlo en una inspección.
ALTER TABLE ticket_incidencias ADD COLUMN id_mantenimiento INTEGER REFERENCES mantenimientos(id_mantenimiento);
CREATE INDEX IF NOT EXISTS ix_ticket_mant ON ticket_incidencias(id_mantenimiento);

INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_mantenimiento','1','interruptor','modulos','Mantenimiento programado',
  'Trabajos que se repiten: revisiones, cambios de filtro, inspecciones obligatorias.',NULL,23);
