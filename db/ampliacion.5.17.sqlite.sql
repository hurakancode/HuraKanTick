-- =====================================================================
--  SSTT 5.17 — Registro de errores
--
--  Hasta ahora, cuando algo fallaba, el mensaje acababa en el log de
--  Apache: un fichero que en un hotel no mira nadie y que en XAMPP suele
--  estar en una carpeta que nadie conoce. Resultado: el técnico dice
--  «no me deja» y no hay forma de saber qué pasó.
--
--  Esto guarda los fallos —los del servidor y los del móvil— con lo que
--  hace falta para entenderlos: quién, dónde, qué versión y qué estaba
--  haciendo.
-- =====================================================================

CREATE TABLE IF NOT EXISTS errores (
  id_error    INTEGER PRIMARY KEY AUTOINCREMENT,
  origen      TEXT NOT NULL,              -- servidor | movil
  mensaje     TEXT NOT NULL,
  detalle     TEXT,                       -- traza o pila
  ruta        TEXT,                       -- qué se estaba pidiendo
  id_login    INTEGER,
  usuario     TEXT,                       -- se guarda el nombre: si borran la cuenta, el rastro queda
  version     TEXT,
  aparato     TEXT,
  id_hotel    INTEGER,
  veces       INTEGER NOT NULL DEFAULT 1, -- el mismo fallo repetido no llena la tabla
  huella      TEXT,                       -- para agrupar los repetidos
  visto       INTEGER NOT NULL DEFAULT 0,
  creado      TEXT NOT NULL,
  ultimo      TEXT
);

CREATE INDEX IF NOT EXISTS ix_errores_fecha  ON errores(creado);
CREATE INDEX IF NOT EXISTS ix_errores_huella ON errores(huella);
CREATE INDEX IF NOT EXISTS ix_errores_visto  ON errores(visto);
