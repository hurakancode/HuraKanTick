-- =====================================================================
--  SSTT 5.2 — Quién lo hizo, aparte de a quién
--
--  El registro guardaba a QUIÉN se le cambió el rol, el correo o la
--  contraseña, y quién lo hizo iba escrito dentro del texto. Eso deja
--  sin respuesta la pregunta más útil de un registro de seguridad:
--  «¿qué hizo esta persona?» — porque sus cambios sobre los demás
--  aparecen bajo el nombre del otro.
--
--  Con una columna aparte, la pregunta se contesta con una consulta.
--  El texto sigue igual: se lee bien y no se pierde nada.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.5.2.sqlite.sql
-- =====================================================================

ALTER TABLE registro_seguridad ADD COLUMN id_autor INTEGER;

CREATE INDEX IF NOT EXISTS ix_reg_autor ON registro_seguridad(id_autor, fecha);
