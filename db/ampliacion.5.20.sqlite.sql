-- =====================================================================
--  SSTT 5.20 — Copia de los avisos al chat de Teams
--
--  En el hotel la gente está acostumbrada a Teams, así que un aviso que
--  sólo vive dentro de SSTT se queda sin leer. Esto manda una copia al
--  chat privado de cada persona.
--
--  Va por una BANDEJA DE SALIDA, no directo. Tres razones:
--
--   · Un aviso se genera mientras alguien guarda un parte. Si eso
--     esperase a Microsoft, guardar un parte tardaría lo que tarde
--     Microsoft —y si Microsoft no contesta, no se guarda el parte.
--   · Si falla, se reintenta. Un aviso perdido porque la red se cayó
--     dos segundos no se recupera nunca.
--   · Queda el rastro de qué se mandó y qué no, que es lo que hace falta
--     cuando alguien dice «a mí no me llegó».
--
--  Las columnas en «login» guardan lo que cuesta averiguar: el
--  identificador de la persona en Azure y el de su conversación con el
--  bot. Sin esto habría que preguntárselo a Microsoft en cada aviso.
-- =====================================================================

ALTER TABLE login ADD COLUMN teams_id TEXT;              -- objeto de Azure AD
ALTER TABLE login ADD COLUMN teams_conversacion TEXT;    -- chat con el bot
ALTER TABLE login ADD COLUMN teams_avisar INTEGER NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS teams_salida (
  id_salida   INTEGER PRIMARY KEY AUTOINCREMENT,
  id_login    INTEGER NOT NULL REFERENCES login(id_login),
  id_ticket   INTEGER REFERENCES ticket_incidencias(id_ticket),
  titulo      TEXT NOT NULL,
  cuerpo      TEXT,
  ruta        TEXT,
  urgente     INTEGER NOT NULL DEFAULT 0,
  estado      TEXT NOT NULL DEFAULT 'pendiente',   -- pendiente | enviado | fallido
  intentos    INTEGER NOT NULL DEFAULT 0,
  error       TEXT,
  creado      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  enviado     TEXT
);

CREATE INDEX IF NOT EXISTS ix_teams_salida ON teams_salida(estado, creado);
