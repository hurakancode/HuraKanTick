SET NAMES utf8mb4;

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
--    mysql -u USUARIO -p BASE < db/ampliacion.2.8.mysql.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `instrucciones` (
  id_instruccion INT AUTO_INCREMENT PRIMARY KEY,
  titulo      VARCHAR(180)    NOT NULL,
  resumen     VARCHAR(300),
  contenido   TEXT    NOT NULL,

  -- Dónde aparece sola: al abrir un parte de esa familia o ese tipo
  id_familia  INT REFERENCES familias(id_familia),
  id_elemento INT REFERENCES elemento(id_elemento),
  id_zona_1   INT REFERENCES zona_1(id_zona_1),

  etiquetas   VARCHAR(300),                    -- separadas por comas, para buscar
  aviso       VARCHAR(500),                    -- lo que no se debe hacer nunca
  id_autor    INT REFERENCES login(id_login),
  vistas      INT NOT NULL DEFAULT 0,
  util        INT NOT NULL DEFAULT 0,
  orden       INT NOT NULL DEFAULT 0,
  activo      INT NOT NULL DEFAULT 1,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `instrucciones` ADD KEY `ix_instr_familia` (id_familia, activo);
ALTER TABLE `instrucciones` ADD KEY `ix_instr_elem` (id_elemento, activo);

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_instrucciones','1','interruptor','modulos','Instrucciones',
  'Cómo se hacen las cosas, escrito una vez. Se descargan al móvil y se consultan sin cobertura.',NULL,24);
