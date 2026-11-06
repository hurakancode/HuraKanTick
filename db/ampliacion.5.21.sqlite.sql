-- =====================================================================
--  SSTT 5.21 — Instrucciones por bloques
--
--  Hasta ahora una instrucción era texto plano y se pintaba tal cual.
--  Para un manual —pasos, fotos, avisos de peligro, vídeo— eso se queda
--  corto, así que se añade un segundo formato.
--
--  «formato» dice cómo hay que leer la columna «contenido»:
--
--     texto    → como siempre, texto plano. Lo que ya está escrito.
--     bloques  → JSON de Editor.js, que la aplicación dibuja.
--
--  Lo ya escrito NO se toca ni se convierte: se queda en «texto» y se
--  sigue viendo igual. Una migración automática de cuarenta
--  instrucciones a un formato nuevo es la clase de cosa que se descubre
--  rota tres semanas después.
--
--  IMPORTANTE: en «bloques» NUNCA se guarda HTML. Se guarda la
--  estructura, y el HTML lo construye la aplicación escapando todo. Así
--  no hay nada que sanear: lo que no se guarda no puede ejecutarse.
--
--  «instrucciones_medios» es donde viven las fotos y los vídeos de los
--  manuales. No van en «adjuntos» porque allí cada fila cuelga de un
--  parte, y una foto de un manual no pertenece a ninguna avería.
-- =====================================================================

ALTER TABLE instrucciones ADD COLUMN formato TEXT NOT NULL DEFAULT 'texto';

CREATE TABLE IF NOT EXISTS instrucciones_medios (
  id_medio     INTEGER PRIMARY KEY AUTOINCREMENT,
  id_instruccion INTEGER REFERENCES instrucciones(id_instruccion),
  archivo      TEXT NOT NULL,
  url          TEXT NOT NULL,
  mime         TEXT,
  tamano       INTEGER,
  ancho        INTEGER,
  alto         INTEGER,
  hash         TEXT,
  nombre_original TEXT,
  id_autor     INTEGER REFERENCES login(id_login),
  id_hotel     INTEGER REFERENCES hoteles(id_hotel),
  creado       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE INDEX IF NOT EXISTS ix_medios_instr ON instrucciones_medios(id_instruccion);
CREATE INDEX IF NOT EXISTS ix_medios_hash  ON instrucciones_medios(hash);
