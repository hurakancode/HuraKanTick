-- =====================================================================
--  SSTT 5.10 — El departamento de un tipo sigue al de su familia
--
--  Antes la regla era «si el tipo tiene un departamento distinto al de su
--  familia, manda el tipo». Suena razonable y está mal: al cambiar el
--  departamento de una familia en Administración, sus tipos se quedaban
--  con el antiguo, así que «distinto» no significaba «lo han puesto a
--  propósito» sino «se ha quedado atrás». El parte seguía yendo al
--  departamento viejo.
--
--  Ahora la excepción se marca: dep_propio = 1 quiere decir «a este tipo
--  se le ha puesto departamento a mano, respétalo». Lo demás sigue a su
--  familia, y cambiar la familia arrastra a sus tipos.
-- =====================================================================

ALTER TABLE elemento ADD COLUMN dep_propio INTEGER NOT NULL DEFAULT 0;

-- La caja fuerte: está en Equipamiento habitación, que es de Pisos, pero
-- la arregla Servicios Técnicos. Es la excepción que sí es a propósito.
UPDATE elemento SET dep_propio = 1 WHERE nombre_elemento LIKE 'Caja fuerte%';

-- Todo lo demás vuelve a alinearse con su familia.
UPDATE elemento
   SET id_departamento = (SELECT f.id_departamento FROM familias f WHERE f.id_familia = elemento.id_familia)
 WHERE dep_propio = 0 AND id_familia IS NOT NULL;
