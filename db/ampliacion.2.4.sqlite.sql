-- =====================================================================
--  SSTT 2.4 — Estados por clase y acciones rápidas
--
--  Once estados tienen sentido para una avería: pendiente, asignado, en
--  curso, esperando a un tercero, esperando al cliente, programado,
--  reabierta, resuelta, cerrado.
--
--  Para una cama extra no: se pide y se monta. Ofrecer «Pte. Terceros»
--  al montar una cuna es exactamente la clase de estorbo que hace que la
--  gente deje de usar una herramienta.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.4.sqlite.sql
-- =====================================================================

-- Qué clases de parte pueden usar cada estado. NULL o vacío = todas.
ALTER TABLE estados ADD COLUMN clases TEXT;

-- Y cuáles son los dos o tres que se ofrecen de primeras, sin desplegar
-- la lista entera.
ALTER TABLE estados ADD COLUMN rapido INTEGER NOT NULL DEFAULT 0;

-- Averías: el ciclo completo
UPDATE estados SET clases = 'averia,peticion,tarea' WHERE id_estado IN (1, 2, 3, 10, 4, 5);
UPDATE estados SET clases = 'averia'                WHERE id_estado IN (6, 7, 8, 9);
UPDATE estados SET clases = 'averia,peticion,tarea' WHERE id_estado = 100;

-- Los que se ofrecen como acción rápida desde el listado
UPDATE estados SET rapido = 1 WHERE id_estado IN (3, 10);   -- En Curso, Resuelta

INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_acciones_rapidas','1','interruptor','modulos','Acciones rápidas en el listado',
  'Avanzar un parte sin abrirlo. Ahorra mucho a quien cierra varios seguidos.',NULL,21),
 ('panel_perfil','auto','lista','comportamiento','Pantalla de inicio',
  'Cada grupo abre la aplicación por motivos distintos. En automático, recepción y limpieza ven lo suyo y los responsables el panel completo.',
  '[{"v":"auto","t":"Según el puesto"},{"v":"completo","t":"Completo para todos"},{"v":"simple","t":"Sencillo para todos"}]',47);
