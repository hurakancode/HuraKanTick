SET NAMES utf8mb4;
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
  id_error    INT AUTO_INCREMENT PRIMARY KEY,
  origen      VARCHAR(16) NOT NULL,              -- servidor | movil
  mensaje     TEXT NOT NULL,
  detalle     TEXT,                       -- traza o pila
  ruta        VARCHAR(255),                       -- qué se estaba pidiendo
  id_login    INTEGER,
  usuario     VARCHAR(80),                       -- se guarda el nombre: si borran la cuenta, el rastro queda
  version     VARCHAR(32),
  aparato     VARCHAR(255),
  id_hotel    INTEGER,
  veces       INT NOT NULL DEFAULT 1, -- el mismo fallo repetido no llena la tabla
  huella      VARCHAR(40),                       -- para agrupar los repetidos
  visto       TINYINT NOT NULL DEFAULT 0,
  creado      DATETIME NOT NULL,
  ultimo      DATETIME
);

CREATE INDEX ix_errores_fecha  ON errores(creado);
CREATE INDEX ix_errores_huella ON errores(huella);
CREATE INDEX ix_errores_visto  ON errores(visto);
