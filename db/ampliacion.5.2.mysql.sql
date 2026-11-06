SET NAMES utf8mb4;

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
--    mysql -u USUARIO -p BASE < db/ampliacion.5.2.mysql.sql
-- =====================================================================

ALTER TABLE registro_seguridad ADD COLUMN id_autor INT;

ALTER TABLE `registro_seguridad` ADD KEY `ix_reg_autor` (id_autor, fecha);
