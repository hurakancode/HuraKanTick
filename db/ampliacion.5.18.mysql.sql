SET NAMES utf8mb4;
-- =====================================================================
--  SSTT 5.18 — Quién ve cada instrucción
--
--  Las instrucciones son notas técnicas: cómo se purga el circuito, cómo
--  se cambia una cerradura. Se enseñaban a cualquiera que abriera el
--  parte, así que recepción —que sólo da el aviso— veía el procedimiento
--  de climatización. Ni le sirve ni le corresponde.
--
--  «visible_para» dice a quién se le enseña:
--     departamento → al departamento que atiende el parte (de fábrica)
--     asignado     → sólo a quien lo está trabajando
--     todos        → a cualquiera que vea el parte, incluido quien lo abrió
--
--  Los responsables y los administradores las ven siempre: son quienes
--  tienen que poder revisar lo que hay escrito.
-- =====================================================================

ALTER TABLE instrucciones ADD COLUMN visible_para VARCHAR(16) NOT NULL DEFAULT 'departamento';

CREATE INDEX ix_instrucciones_visible ON instrucciones(visible_para);
