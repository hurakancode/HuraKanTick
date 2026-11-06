SET NAMES utf8mb4;
-- =====================================================================
--  SSTT 5.9 — Arreglo de las categorías de frases repetidas
--
--  La 5.8 sembraba las cuatro categorías de ejemplo con un INSERT a
--  secas, y esa ampliación se ejecuta en CADA actualización: quien
--  actualizó cuatro veces acabó con dieciséis categorías, cuatro de
--  cada nombre.
--
--  Aquí se deja una de cada: las frases de las repetidas pasan a la
--  primera, y después se impide que vuelva a ocurrir.
-- =====================================================================

UPDATE frases
   SET id_categoria = (
        SELECT MIN(c2.id_categoria) FROM frases_categorias c2
         WHERE c2.nombre = (SELECT c1.nombre FROM frases_categorias c1
                             WHERE c1.id_categoria = frases.id_categoria))
 WHERE id_categoria IS NOT NULL;

DELETE FROM frases_categorias
 WHERE id_categoria NOT IN (SELECT MIN(id_categoria) FROM frases_categorias GROUP BY nombre);

ALTER TABLE frases_categorias ADD UNIQUE KEY ix_frases_cat_nombre (nombre);

-- Y la casilla para ver la frase en cada inicio de sesión, no una al día.
ALTER TABLE login ADD COLUMN frases_cada_inicio TINYINT NOT NULL DEFAULT 0;

ALTER TABLE frases_leidas ADD COLUMN cerrada DATETIME NULL;
