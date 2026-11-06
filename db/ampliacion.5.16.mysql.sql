SET NAMES utf8mb4;
-- =====================================================================
--  SSTT 5.16 — Partes programados: visibles a partir de una fecha
--
--  Recepción da el aviso de que la caja fuerte de la 801 no va, pero el
--  cliente tiene el cartel de «no molestar» hasta el día 30. El parte
--  existe y no se puede perder, pero no tiene sentido que esté en la
--  cola de servicios técnicos ensuciando la lista de lo que hay que
--  hacer hoy.
--
--  Es distinto de un mantenimiento programado: aquello se repite, esto
--  es un caso puntual.
-- =====================================================================

ALTER TABLE ticket_incidencias ADD COLUMN visible_desde DATETIME;

CREATE INDEX ix_ticket_visible ON ticket_incidencias(visible_desde);
