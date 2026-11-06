SET NAMES utf8mb4;
-- =====================================================================
--  PAQUETE «HOTEL» — campos propios de algunos tipos de avería
--
--  Hay averías que piden datos que no valen para el resto. En un aire
--  acondicionado importa la temperatura de la sala, si el mando responde
--  o cuándo se limpiaron los filtros; en una avería de carpintería, nada
--  de eso significa nada.
--
--  Estos campos salen en el alta SÓLO cuando se elige ese tipo, y luego
--  se pueden cruzar en Informes: «media de temperatura por planta» o
--  «cuántos partes de aire con el gas bajo».
--
--  Se pueden cambiar desde Administración → Campos por tipo de avería.
-- =====================================================================

INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'temp_sala', 'Temperatura de la sala', 'temperatura', NULL, '°C', 0,
       'La que marca el termómetro al llegar.', 1, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Aire acondicionado', 'Fan coil')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'temp_sala');

INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'temp_consigna', 'Temperatura pedida', 'temperatura', NULL, '°C', 0,
       'La que tiene puesta el mando.', 2, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Aire acondicionado', 'Fan coil')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'temp_consigna');

INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'sintoma_aire', 'Qué hace', 'lista',
       '["No enfría", "No calienta", "No arranca", "Hace ruido", "Gotea", "Huele mal", "El mando no responde"]', NULL, 0,
       NULL, 3, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Aire acondicionado', 'Fan coil')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'sintoma_aire');

INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'filtros', 'Estado de los filtros', 'lista',
       '["Limpios", "Sucios", "Muy sucios", "No se han mirado"]', NULL, 0, NULL, 4, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Aire acondicionado', 'Fan coil')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'filtros');

INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'gas_revisado', 'Se ha mirado el gas', 'interruptor', NULL, NULL, 0, NULL, 5, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Aire acondicionado', 'Fan coil')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'gas_revisado');

-- Y un par más, para que se vea que esto no es sólo del aire.
INSERT INTO campos_elemento (id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT e.id_elemento, 'presion_agua', 'Presión del agua', 'numero', NULL, 'bar', 0, NULL, 1, 1
  FROM elemento e WHERE e.nombre_elemento IN ('Caldera', 'Bomba de agua', 'Grifo')
   AND NOT EXISTS (SELECT 1 FROM campos_elemento c WHERE c.id_elemento = e.id_elemento AND c.clave = 'presion_agua');

-- Corrección de tipos en instalaciones que ya los tenían creados: «si_no»
-- no existe en el sistema y se pintaba como caja de texto libre, y las
-- temperaturas tienen su propio tipo.
UPDATE campos_elemento SET tipo = 'interruptor'  WHERE tipo = 'si_no';
UPDATE campos_elemento SET tipo = 'temperatura'  WHERE clave IN ('temp_sala', 'temp_consigna') AND tipo = 'numero';

-- Las opciones de los campos de lista van en JSON: guardadas separadas por
-- comas, el desplegable salía vacío y sólo ofrecía «Sin indicar». Esto
-- arregla las instalaciones que ya las tuvieran mal.
UPDATE campos_elemento
   SET opciones = '["No enfría", "No calienta", "No arranca", "Hace ruido", "Gotea", "Huele mal", "El mando no responde"]'
 WHERE clave = 'sintoma_aire' AND opciones NOT LIKE '[%';
UPDATE campos_elemento
   SET opciones = '["Limpios", "Sucios", "Muy sucios", "No se han mirado"]'
 WHERE clave = 'filtros' AND opciones NOT LIKE '[%';
