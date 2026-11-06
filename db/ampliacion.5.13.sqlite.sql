-- =====================================================================
--  SSTT 5.13 — Campos obligatorios al crear partes
--
--  Cambia el nombre del ajuste, que era confuso, y pone de fábrica los
--  tres que casi siempre hacen falta: qué falla, dónde es y el resumen.
--  Sólo se tocan las instalaciones donde nadie lo había configurado: si
--  alguien eligió los suyos, se respetan.
-- =====================================================================

UPDATE ajustes_sistema
   SET titulo = 'Campos obligatorios al crear partes'
 WHERE clave = 'alta_obligatorios';

UPDATE ajustes_sistema
   SET valor = '["elemento","ubicacion","titulo"]'
 WHERE clave = 'alta_obligatorios' AND (valor IS NULL OR valor = '' OR valor = '[]');
