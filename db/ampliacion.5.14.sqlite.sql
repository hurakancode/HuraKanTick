-- =====================================================================
--  SSTT 5.14 — Departamentos adicionales y permisos por persona
--
--  En un hotel es normal que informática y servicios técnicos se cubran:
--  un enchufe que no da corriente lo mismo lo arregla uno que otro. Hasta
--  ahora cada persona tenía un departamento y punto, así que o veía los
--  partes del otro (abriendo de más) o no los veía (y no podía ayudar).
--
--  Los permisos son lo mismo pero para cosas sueltas: dar a alguien una
--  capacidad concreta sin convertirlo en administrador.
-- =====================================================================

CREATE TABLE IF NOT EXISTS login_departamento (
  id_login        INTEGER NOT NULL REFERENCES login(id_login),
  id_departamento INTEGER NOT NULL REFERENCES departamentos(id_departamento),
  creado          TEXT,
  PRIMARY KEY (id_login, id_departamento)
);

CREATE INDEX IF NOT EXISTS ix_login_dep ON login_departamento(id_departamento);

CREATE TABLE IF NOT EXISTS login_permisos (
  id_login  INTEGER NOT NULL REFERENCES login(id_login),
  permiso   TEXT NOT NULL,
  creado    TEXT,
  PRIMARY KEY (id_login, permiso)
);
