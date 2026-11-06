SET NAMES utf8mb4;
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

ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_vivos` (borrado, id_estado, id_grupo_asignado);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_limite` (borrado, fecha_limite);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_elemento` (borrado, id_elemento);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_cierre` (borrado, fecha_cierre);
