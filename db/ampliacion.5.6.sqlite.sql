-- =====================================================================
--  SSTT 5.6 — A qué departamento va cada cosa
--
--  El parte se reparte solo según lo que falla: cada familia lleva su
--  departamento. Aquí se fija el reparto del Hotel Benalma:
--
--    Pisos        · Limpieza y repasos, Lencería y textil,
--                   Peticiones de cliente y Equipamiento habitación
--    Informática  · Informática y redes
--    Servicios Técnicos · todo lo demás
--
--  Y una excepción por tipo concreto: la caja fuerte está dentro de
--  «Equipamiento habitación», que es de Pisos, pero la arregla Servicios
--  Técnicos. Un tipo puede mandar sobre su familia.
--
--  Esto se ejecuta UNA vez. A partir de ahí, el reparto se cambia desde
--  Administración › Familias y no se vuelve a tocar.
-- =====================================================================

UPDATE familias
   SET id_departamento = (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Pisos%' LIMIT 1)
 WHERE nombre IN ('Limpieza y repasos', 'Lencería y textil',
                  'Peticiones de cliente', 'Equipamiento habitación')
   AND (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Pisos%' LIMIT 1) IS NOT NULL;

UPDATE familias
   SET id_departamento = (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Inform%' LIMIT 1)
 WHERE nombre = 'Informática y redes'
   AND (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Inform%' LIMIT 1) IS NOT NULL;

UPDATE familias
   SET id_departamento = (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios%' LIMIT 1)
 WHERE nombre NOT IN ('Limpieza y repasos', 'Lencería y textil',
                      'Peticiones de cliente', 'Equipamiento habitación',
                      'Informática y redes')
   AND (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios%' LIMIT 1) IS NOT NULL;

-- Los tipos siguen a su familia…
UPDATE elemento
   SET id_departamento = (SELECT f.id_departamento FROM familias f WHERE f.id_familia = elemento.id_familia)
 WHERE id_familia IS NOT NULL;

-- …menos la caja fuerte, que es de Servicios Técnicos.
UPDATE elemento
   SET id_departamento = (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios%' LIMIT 1)
 WHERE nombre_elemento LIKE 'Caja fuerte%'
   AND (SELECT id_departamento FROM departamentos WHERE nombre LIKE 'Servicios%' LIMIT 1) IS NOT NULL;

-- El ajuste, con un nombre que se entienda.
UPDATE ajustes_sistema
   SET titulo = 'Asignar los partes según lo que falla',
       descripcion = 'Cada familia de avería lleva su departamento: un aviso de recepción sobre una sábana va a Pisos, y uno de un enchufe a Servicios Técnicos. El reparto se ajusta en Administración › Familias. Si se apaga, todos los partes van al departamento por defecto.',
       valor = '1'
 WHERE clave = 'rutar_por_familia';
