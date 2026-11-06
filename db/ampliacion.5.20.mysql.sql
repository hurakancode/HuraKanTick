SET NAMES utf8mb4;
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

ALTER TABLE `login` ADD COLUMN `teams_id` VARCHAR(64) NULL;
ALTER TABLE `login` ADD COLUMN `teams_conversacion` VARCHAR(128) NULL;
ALTER TABLE `login` ADD COLUMN `teams_avisar` TINYINT NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `teams_salida` (
  `id_salida`  INT AUTO_INCREMENT PRIMARY KEY,
  `id_login`   INT NOT NULL,
  `id_ticket`  INT NULL,
  `titulo`     VARCHAR(255) NOT NULL,
  `cuerpo`     TEXT NULL,
  `ruta`       VARCHAR(255) NULL,
  `urgente`    TINYINT NOT NULL DEFAULT 0,
  `estado`     VARCHAR(16) NOT NULL DEFAULT 'pendiente',
  `intentos`   INT NOT NULL DEFAULT 0,
  `error`      TEXT NULL,
  `creado`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado`    DATETIME NULL,
  KEY `ix_teams_salida` (`estado`, `creado`),
  CONSTRAINT `fk_teams_login` FOREIGN KEY (`id_login`) REFERENCES `login`(`id_login`),
  CONSTRAINT `fk_teams_ticket` FOREIGN KEY (`id_ticket`) REFERENCES `ticket_incidencias`(`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
