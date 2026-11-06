SET NAMES utf8mb4;

-- =====================================================================
--  SSTT 3.2 — Ajustes del formulario de alta
--
--  Tres cosas que cambian de un hotel a otro y no deberían estar en el
--  código: qué es obligatorio al dar un parte, quién puede crear
--  ubicaciones nuevas, y a quién van los partes si nadie dice otra cosa.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.3.2.mysql.sql
-- =====================================================================

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES

('alta_obligatorios','["elemento","ubicacion","titulo"]','json','alta',
 'Campos obligatorios al crear partes',
 'Cuantos más campos obligatorios, más tarda en darse un parte y menos se dan. Elige sólo lo que de verdad haga falta.',
 '[{"v":"elemento","t":"Qué falla"},{"v":"ubicacion","t":"Dónde"},{"v":"titulo","t":"Resumen"},{"v":"detalle","t":"Detalle"},{"v":"foto","t":"Al menos una foto"},{"v":"asignado","t":"A quién va"}]',1),

('quien_crea_zona','responsable','lista','alta',
 'Quién puede crear ubicaciones nuevas',
 'Crear ubicaciones desde el formulario es cómodo, pero con mucha gente el catálogo se llena de duplicados.',
 '[{"v":"todos","t":"Cualquiera"},{"v":"tecnico","t":"Técnicos y superiores"},{"v":"responsable","t":"Sólo responsables"},{"v":"admin","t":"Sólo administradores"},{"v":"nadie","t":"Nadie"}]',2),

('alta_departamento_defecto','1','numero','alta',
 'A qué grupo van los partes por defecto',
 'Si quien avisa no elige nada, el parte va a este grupo. Lo habitual es Servicios Técnicos.',NULL,3),

('alta_prioridad_defecto','2','numero','alta',
 'Prioridad por defecto',
 'La que se marca si nadie la cambia. La familia puede tener la suya y manda sobre esta.',NULL,4),

('paneles_area','[]','json','general',
 'Paneles de área en el inicio',
 'Accesos directos al panel de un área concreta. Sin elegir nada sale el de climatización, que es lo que más partes genera.',NULL,8);
