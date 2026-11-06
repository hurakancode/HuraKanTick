SET NAMES utf8mb4;
-- =====================================================================
--  SSTT 5.12 — Conversación sobre un parte
--
--  El seguimiento de un parte cuenta lo que se ha hecho. Esto es otra
--  cosa: hablar con quien lo abrió. Recepción dice que la caja fuerte de
--  la 801 no va, el técnico sube y resulta que el cliente no sabía
--  usarla. Si eso no vuelve a recepción, el cliente se queja otra vez.
--
--  Dos tablas: los mensajes y quién los ha leído. Lo segundo importa
--  tanto como lo primero: sin acuse de lectura, «ya te lo dije» no vale
--  para nada.
-- =====================================================================

CREATE TABLE IF NOT EXISTS chat_mensajes (
  id_mensaje   INT AUTO_INCREMENT PRIMARY KEY,
  uuid         VARCHAR(64) UNIQUE,
  id_ticket    INTEGER NOT NULL REFERENCES ticket_incidencias(id_ticket),
  id_login     INTEGER NOT NULL REFERENCES login(id_login),
  texto        TEXT NOT NULL,
  -- Marcado como importante: sale en el inicio de quien deba leerlo
  -- aunque no entre en el parte.
  importante   TINYINT NOT NULL DEFAULT 0,
  id_hotel     INTEGER,
  creado       DATETIME NOT NULL,
  updated_at   DATETIME,
  borrado      TINYINT NOT NULL DEFAULT 0
);

CREATE INDEX ix_chat_ticket ON chat_mensajes(id_ticket, borrado, creado);
CREATE INDEX ix_chat_autor  ON chat_mensajes(id_login);

CREATE TABLE IF NOT EXISTS chat_lecturas (
  id_lectura  INT AUTO_INCREMENT PRIMARY KEY,
  id_mensaje  INTEGER NOT NULL REFERENCES chat_mensajes(id_mensaje),
  id_login    INTEGER NOT NULL REFERENCES login(id_login),
  leido       DATETIME NOT NULL,
  UNIQUE (id_mensaje, id_login)
);

CREATE INDEX ix_lectura_persona ON chat_lecturas(id_login);
