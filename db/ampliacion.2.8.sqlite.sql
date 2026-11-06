-- =====================================================================
--  SSTT 2.8 — Instrucciones
--
--  En un hotel la plantilla rota cada temporada. Lo que sabe hacer el
--  técnico que lleva ocho años se pierde cuando se va, y el siguiente
--  vuelve a aprenderlo a base de equivocarse.
--
--  Una instrucción es eso escrito una vez: cómo se purga el circuito
--  del aire de la planta 7, qué llave hace falta para el cuadro de la
--  piscina, cuál es el orden correcto para arrancar la cocina.
--
--  Lo importante: se descargan al móvil. Quien las necesita está en un
--  sótano sin cobertura, que es justo donde una base de conocimiento
--  que exige conexión no sirve de nada.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.8.sqlite.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS instrucciones (
  id_instruccion INTEGER PRIMARY KEY AUTOINCREMENT,
  titulo      TEXT    NOT NULL,
  resumen     TEXT,
  contenido   TEXT    NOT NULL,

  -- Dónde aparece sola: al abrir un parte de esa familia o ese tipo
  id_familia  INTEGER REFERENCES familias(id_familia),
  id_elemento INTEGER REFERENCES elemento(id_elemento),
  id_zona_1   INTEGER REFERENCES zona_1(id_zona_1),

  etiquetas   TEXT,                    -- separadas por comas, para buscar
  aviso       TEXT,                    -- lo que no se debe hacer nunca
  id_autor    INTEGER REFERENCES login(id_login),
  vistas      INTEGER NOT NULL DEFAULT 0,
  util        INTEGER NOT NULL DEFAULT 0,
  orden       INTEGER NOT NULL DEFAULT 0,
  activo      INTEGER NOT NULL DEFAULT 1,
  creado      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_instr_familia ON instrucciones(id_familia, activo);
CREATE INDEX IF NOT EXISTS ix_instr_elem ON instrucciones(id_elemento, activo);

INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_instrucciones','1','interruptor','modulos','Instrucciones',
  'Cómo se hacen las cosas, escrito una vez. Se descargan al móvil y se consultan sin cobertura.',NULL,24);
