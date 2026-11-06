-- =====================================================================
--  SSTT 5.5 — Que los móviles se enteren de los partes eliminados
--
--  La sincronización es incremental: el móvil pide lo que ha cambiado
--  desde la última vez. Un parte eliminado del todo deja de existir, así
--  que no aparecía en ningún cambio y el móvil lo conservaba para
--  siempre. Aquí se apunta cada eliminación definitiva para poder
--  decírselo.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.5.5.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS sync_bajas (
  id_ticket INTEGER PRIMARY KEY,
  fecha     TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sync_bajas_fecha ON sync_bajas(fecha);
