-- =====================================================================
--  SSTT 5.7 — Que el inicio siga siendo rápido con el hotel lleno
--
--  Con 28 partes el inicio tardaba 3 ms; con 20.000, 200. No era una
--  consulta lenta sino dieciséis medianas: los contadores de portada
--  —abiertos, míos, de mi grupo, fuera de plazo, por estado— recorrían
--  la tabla entera cada vez.
--
--  Estos índices los resuelven leyendo sólo lo que hace falta.
-- =====================================================================

CREATE INDEX IF NOT EXISTS ix_ticket_vivos    ON ticket_incidencias(borrado, id_estado, id_grupo_asignado);
CREATE INDEX IF NOT EXISTS ix_ticket_limite   ON ticket_incidencias(borrado, fecha_limite);
CREATE INDEX IF NOT EXISTS ix_ticket_elemento ON ticket_incidencias(borrado, id_elemento);
CREATE INDEX IF NOT EXISTS ix_ticket_cierre   ON ticket_incidencias(borrado, fecha_cierre);
