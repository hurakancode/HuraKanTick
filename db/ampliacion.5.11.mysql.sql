SET NAMES utf8mb4;
-- =====================================================================
--  SSTT 5.11 — Informes guardados: para mí, para mi departamento, para todos
--
--  Antes sólo había dos posturas: mío o de todos. Faltaba la de en medio,
--  que es la que más se usa: un informe que sólo tiene sentido para
--  Pisos no le interesa a Recepción y le ensucia la lista.
--
--  «compartido» se conserva para no romper lo que ya hay: 1 pasa a ser
--  alcance «todos».
-- =====================================================================

ALTER TABLE informes ADD COLUMN alcance VARCHAR(16) NOT NULL DEFAULT 'yo';
ALTER TABLE informes ADD COLUMN id_departamento INTEGER;

UPDATE informes SET alcance = 'todos' WHERE compartido = 1;
UPDATE informes
   SET id_departamento = (SELECT l.id_departamento FROM login l WHERE l.id_login = informes.id_login)
 WHERE id_departamento IS NULL;
