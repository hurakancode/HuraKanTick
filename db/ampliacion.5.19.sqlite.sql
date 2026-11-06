-- =====================================================================
--  SSTT 5.19 — Los mantenimientos se generan solos y pueden terminar
--
--  Dos agujeros que iban juntos.
--
--  1) Un mantenimiento programado se repetía para siempre. No había
--     forma de decir «esto son seis revisiones» ni «hasta final de
--     temporada»: había que acordarse de entrar y desactivarlo, y nadie
--     se acuerda. Ahora:
--        repeticiones → cuántas veces en total (NULL = sin tope)
--        hasta        → fecha máxima (NULL = sin fecha final)
--     Al llegar al tope el mantenimiento se desactiva solo y queda ahí,
--     con su historial, en vez de desaparecer.
--
--  2) La generación sólo ocurría si alguien había puesto un cron. El
--     README lo explicaba, pero documentar algo no lo ejecuta: en una
--     instalación sin cron —la normal en un hotel— no se generó nunca
--     un solo parte de mantenimiento.
--
--     «tareas_latido» es el candado que permite hacerlo sin cron: la
--     primera petición del día lo intenta, apunta la hora y las demás
--     treinta personas que entran a la vez pasan de largo. El cron, si
--     existe, sigue funcionando igual y no duplica nada.
-- =====================================================================

ALTER TABLE mantenimientos ADD COLUMN repeticiones INTEGER;
ALTER TABLE mantenimientos ADD COLUMN hasta TEXT;

CREATE TABLE IF NOT EXISTS tareas_latido (
  clave      TEXT PRIMARY KEY,
  ultimo     TEXT,
  en_marcha  TEXT,
  veces      INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
