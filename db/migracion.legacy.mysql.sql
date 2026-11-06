-- =====================================================================
--  HuraKan Tick 1.x  ->  HuraKan Tick
--  Migración escrita contra el volcado real (sstt_app, MariaDB 10.4).
--
--  Ejecutar UNA sola vez, con copia de seguridad previa:
--    mysqldump -u USUARIO -p sstt_app > copia_antes_de_migrar.sql
--    mysql -u USUARIO -p sstt_app < db/migracion.legacy.mysql.sql
--
--  No borra ni renombra nada. Sólo añade tablas y columnas, relaja
--  restricciones que impedían guardar partes incompletos, y convierte
--  los bit(1) a tinyint(1).
--
--  Los errores 1060 «Duplicate column name» y 1061 «Duplicate key name»
--  son esperados si repites la ejecución: puedes ignorarlos.
-- =====================================================================
SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------------------------------
-- 0. Los bit(1) se convierten a tinyint(1)
--
--    PDO devuelve bit(1) como un byte binario ("\0" / "\1"), no como
--    número: (int)"\x01" da 0 y un administrador dejaría de serlo. Con
--    tinyint(1) el valor llega como 0/1 y la aplicación antigua sigue
--    funcionando igual.
-- ---------------------------------------------------------------------
ALTER TABLE `login`    MODIFY `bloqueo` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `login`    MODIFY `admin`   TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `elemento` MODIFY `baja`    TINYINT(1) NOT NULL DEFAULT 0;

-- ---------------------------------------------------------------------
-- 1. Tablas nuevas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departamentos` (
  `id_departamento` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`     VARCHAR(80) NOT NULL,
  `color`      VARCHAR(9)  NOT NULL DEFAULT '#0B5563',
  `activo`     TINYINT(1)  NOT NULL DEFAULT 1,
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `prioridades` (
  `id_prioridad` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`    VARCHAR(40) NOT NULL,
  `color`     VARCHAR(9)  NOT NULL DEFAULT '#64748B',
  `sla_horas` INT NULL,
  `orden`     INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `adjuntos` (
  `id_adjunto` INT AUTO_INCREMENT PRIMARY KEY,
  `uuid`       CHAR(36) NOT NULL,
  `id_ticket`  INT NOT NULL,
  `archivo`    VARCHAR(255) NOT NULL,
  `nombre_original` VARCHAR(255) NULL,
  `mime`       VARCHAR(80) NULL,
  `tamano`     INT NOT NULL DEFAULT 0,
  `hash`       CHAR(64) NULL,
  `ancho`      INT NULL,
  `alto`       INT NULL,
  `id_realiza_accion` INT NOT NULL,
  `borrado`    TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_creado` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_adj_uuid` (`uuid`),
  KEY `ix_adj_ticket` (`id_ticket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id_token`   INT AUTO_INCREMENT PRIMARY KEY,
  `id_login`   INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `dispositivo` VARCHAR(120) NULL,
  `plataforma`  VARCHAR(40)  NULL,
  `creado`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_uso` DATETIME NULL,
  `expira`     DATETIME NULL,
  `revocado`   TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `ix_token_login` (`id_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `intentos_acceso` (
  `id`     INT AUTO_INCREMENT PRIMARY KEY,
  `huella` CHAR(64) NOT NULL,
  `correo` VARCHAR(190) NULL,
  `fecha`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_intentos` (`huella`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id_notificacion` INT AUTO_INCREMENT PRIMARY KEY,
  `id_login`  INT NOT NULL,
  `id_ticket` INT NULL,
  `titulo`    VARCHAR(160) NOT NULL,
  `cuerpo`    VARCHAR(400) NULL,
  `leida`     TINYINT(1) NOT NULL DEFAULT 0,
  `fecha`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_notif_login` (`id_login`, `leida`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `preferencias` (
  `id_login` INT NOT NULL,
  `clave`    VARCHAR(60) NOT NULL,
  `valor`    TEXT NULL,
  PRIMARY KEY (`id_login`, `clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. login
-- ---------------------------------------------------------------------
ALTER TABLE `login`
  ADD COLUMN `telefono`        VARCHAR(32) NULL,
  ADD COLUMN `rol`             VARCHAR(20) NOT NULL DEFAULT 'tecnico',
  ADD COLUMN `id_departamento` INT NULL,
  ADD COLUMN `fecha_alta`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN `ultimo_acceso`   DATETIME NULL,
  ADD COLUMN `intentos`        INT NOT NULL DEFAULT 0,
  ADD COLUMN `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- El correo era varchar(512): demasiado largo para un índice único en
-- utf8mb4, así que se indexa sólo el prefijo.
ALTER TABLE `login` ADD UNIQUE KEY `uq_login_correo` (`correo`(190));

-- Los administradores antiguos pasan al rol correspondiente.
UPDATE `login` SET `rol` = 'admin' WHERE `admin` = 1;

-- ---------------------------------------------------------------------
-- 3. estados
-- ---------------------------------------------------------------------
ALTER TABLE `estados`
  ADD COLUMN `orden`      INT NOT NULL DEFAULT 0,
  ADD COLUMN `es_inicial` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `es_final`   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `activo`     TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Orden de trabajo y significado de cada estado real:
--   1 Pendiente Asignar · 2 Asignado · 3 En Curso · 8 Programado
--   6 Pte. Terceros · 7 Pte. Usuario · 9 Reabierta
--   10 Resuelta · 4 Cerrado · 5 Cancelada · 100 Eliminado
UPDATE `estados` SET `orden` =  1, `es_inicial` = 1 WHERE `id_estado` = 1;
UPDATE `estados` SET `orden` =  2 WHERE `id_estado` = 2;
UPDATE `estados` SET `orden` =  3 WHERE `id_estado` = 3;
UPDATE `estados` SET `orden` =  4 WHERE `id_estado` = 8;
UPDATE `estados` SET `orden` =  5 WHERE `id_estado` = 6;
UPDATE `estados` SET `orden` =  6 WHERE `id_estado` = 7;
UPDATE `estados` SET `orden` =  7 WHERE `id_estado` = 9;
UPDATE `estados` SET `orden` =  8, `es_final` = 1 WHERE `id_estado` = 10;
UPDATE `estados` SET `orden` =  9, `es_final` = 1 WHERE `id_estado` = 4;
UPDATE `estados` SET `orden` = 10, `es_final` = 1 WHERE `id_estado` = 5;
UPDATE `estados` SET `orden` = 11, `es_final` = 1, `activo` = 0 WHERE `id_estado` = 100;

-- Colores con contraste suficiente en tema claro y oscuro.
-- Si prefieres conservar los tuyos de Bootstrap, comenta este bloque.
UPDATE `estados` SET `color` = '#2563EB' WHERE `id_estado` = 1;
UPDATE `estados` SET `color` = '#7C3AED' WHERE `id_estado` = 2;
UPDATE `estados` SET `color` = '#F2A007' WHERE `id_estado` = 3;
UPDATE `estados` SET `color` = '#0D7C57' WHERE `id_estado` = 4;
UPDATE `estados` SET `color` = '#C7362B' WHERE `id_estado` = 5;
UPDATE `estados` SET `color` = '#64748B' WHERE `id_estado` IN (6, 7);
UPDATE `estados` SET `color` = '#0EA5E9' WHERE `id_estado` = 8;
UPDATE `estados` SET `color` = '#B45309' WHERE `id_estado` = 9;
UPDATE `estados` SET `color` = '#0D7C57' WHERE `id_estado` = 10;
UPDATE `estados` SET `color` = '#334155' WHERE `id_estado` = 100;

-- Cualquier estado añadido después queda al final, pero visible.
UPDATE `estados` SET `orden` = `id_estado` + 100 WHERE `orden` = 0;

-- ---------------------------------------------------------------------
-- 4. elemento  (313 filas reales: se conservan tal cual)
-- ---------------------------------------------------------------------
ALTER TABLE `elemento`
  ADD COLUMN `icono`           VARCHAR(40) NOT NULL DEFAULT 'llave-inglesa',
  ADD COLUMN `id_departamento` INT NULL,
  ADD COLUMN `orden`           INT NOT NULL DEFAULT 0,
  ADD COLUMN `usos`            INT NOT NULL DEFAULT 0,
  ADD COLUMN `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `elemento` ADD KEY `ix_elemento_nombre` (`nombre_elemento`);

-- ---------------------------------------------------------------------
-- 5. zonas
-- ---------------------------------------------------------------------
ALTER TABLE `zona_1` ADD COLUMN `orden` INT NOT NULL DEFAULT 0,
                     ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `zona_2` ADD COLUMN `orden` INT NOT NULL DEFAULT 0,
                     ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `zona_3` ADD COLUMN `orden` INT NOT NULL DEFAULT 0,
                     ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `zona_4` ADD COLUMN `orden` INT NOT NULL DEFAULT 0,
                     ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `rel_zonas`
  ADD COLUMN `ruta`       VARCHAR(600) NULL,
  ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- id_zona_1 era obligatorio: eso impedía registrar una avería de la que
-- se conoce la zona pero no la planta.
ALTER TABLE `rel_zonas` MODIFY `id_zona_1` INT NULL;
ALTER TABLE `rel_zonas` ADD KEY `ix_rel_combo` (`id_zona_1`,`id_zona_2`,`id_zona_3`,`id_zona_4`);

-- Caché de la ruta legible: "Planta 01 > Habitaciones > 101 > Baño"
UPDATE `rel_zonas` r
LEFT JOIN `zona_1` z1 ON r.`id_zona_1` = z1.`id_zona_1`
LEFT JOIN `zona_2` z2 ON r.`id_zona_2` = z2.`id_zona_2`
LEFT JOIN `zona_3` z3 ON r.`id_zona_3` = z3.`id_zona_3`
LEFT JOIN `zona_4` z4 ON r.`id_zona_4` = z4.`id_zona_4`
SET r.`ruta` = CONCAT_WS(' > ',
      NULLIF(z1.`nombre_zona_1`,''), NULLIF(z2.`nombre_zona_2`,''),
      NULLIF(z3.`nombre_zona_3`,''), NULLIF(z4.`nombre_zona_4`,''))
WHERE r.`ruta` IS NULL OR r.`ruta` = '';

-- ---------------------------------------------------------------------
-- 6. ticket_incidencias
-- ---------------------------------------------------------------------
ALTER TABLE `ticket_incidencias`
  ADD COLUMN `uuid`            CHAR(36) NULL,
  ADD COLUMN `fecha_limite`    DATETIME NULL,
  ADD COLUMN `fecha_cierre`    DATETIME NULL,
  ADD COLUMN `id_prioridad`    INT NOT NULL DEFAULT 2,
  ADD COLUMN `origen`          VARCHAR(16) NOT NULL DEFAULT 'app',
  ADD COLUMN `minutos_trabajo` INT NOT NULL DEFAULT 0,
  ADD COLUMN `coste`           DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN `borrado`         TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `aviso_vencido`   DATETIME NULL,
  ADD COLUMN `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Restricciones que impedían dar de alta un parte incompleto desde el
-- móvil (sin ubicación exacta o sin tipo de avería identificado).
ALTER TABLE `ticket_incidencias` MODIFY `detalle_ticket` LONGTEXT NULL;
ALTER TABLE `ticket_incidencias` MODIFY `id_rel_zona`    INT NULL;
ALTER TABLE `ticket_incidencias` MODIFY `id_elemento`    INT NULL;
ALTER TABLE `ticket_incidencias` MODIFY `fecha_creado_sin_hora` DATE NULL;
ALTER TABLE `ticket_incidencias` MODIFY `titulo_ticket`  VARCHAR(200) NOT NULL;

-- id_elemento = 0 significaba «sin especificar»: pasa a NULL.
UPDATE `ticket_incidencias` SET `id_elemento` = NULL WHERE `id_elemento` = 0;

-- Cada parte necesita un identificador generado fuera del servidor para
-- que el móvil pueda crearlo sin cobertura sin duplicarlo al enviarlo.
UPDATE `ticket_incidencias` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';
UPDATE `ticket_incidencias` SET `updated_at` = `fecha_creado`
  WHERE `updated_at` IS NULL OR `updated_at` < `fecha_creado`;
UPDATE `ticket_incidencias` SET `fecha_cierre` = `fecha_creado`
  WHERE `fecha_cierre` IS NULL AND `id_estado` IN (4, 5, 10, 100);
UPDATE `ticket_incidencias` SET `borrado` = 1 WHERE `id_estado` = 100;

ALTER TABLE `ticket_incidencias` ADD UNIQUE KEY `uq_ticket_uuid` (`uuid`);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_updated` (`updated_at`);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_estado` (`id_estado`);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_asignada` (`id_persona_asignada`);
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_zona` (`id_rel_zona`);

-- ---------------------------------------------------------------------
-- 7. ticket_incidencias_historial
-- ---------------------------------------------------------------------
ALTER TABLE `ticket_incidencias_historial`
  ADD COLUMN `uuid`       CHAR(36) NULL,
  ADD COLUMN `tipo`       VARCHAR(20) NOT NULL DEFAULT 'nota',
  ADD COLUMN `minutos`    INT NOT NULL DEFAULT 0,
  ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `ticket_incidencias_historial` MODIFY `id_estado_old` INT NULL;
ALTER TABLE `ticket_incidencias_historial` MODIFY `id_realiza_accion` INT NULL;

UPDATE `ticket_incidencias_historial` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = '';

-- El primer movimiento de cada parte es su alta.
UPDATE `ticket_incidencias_historial` h
JOIN (SELECT `id_ticket`, MIN(`id_historial`) AS primero
        FROM `ticket_incidencias_historial` GROUP BY `id_ticket`) p
  ON h.`id_historial` = p.primero
SET h.`tipo` = 'alta';

-- Los que cambiaron de estado se marcan como tales.
UPDATE `ticket_incidencias_historial`
   SET `tipo` = 'estado'
 WHERE `tipo` = 'nota' AND `id_estado_old` IS NOT NULL AND `id_estado` <> `id_estado_old`;

ALTER TABLE `ticket_incidencias_historial` ADD UNIQUE KEY `uq_hist_uuid` (`uuid`);
ALTER TABLE `ticket_incidencias_historial` ADD KEY `ix_hist_ticket` (`id_ticket`);
ALTER TABLE `ticket_incidencias_historial` ADD KEY `ix_hist_updated` (`updated_at`);

-- ---------------------------------------------------------------------
-- 8. Catálogos nuevos
-- ---------------------------------------------------------------------
INSERT INTO `prioridades` (`id_prioridad`, `nombre`, `color`, `sla_horas`, `orden`) VALUES
  (1, 'Baja',    '#64748B', 168, 1),
  (2, 'Normal',  '#0B5563',  72, 2),
  (3, 'Alta',    '#F2A007',  24, 3),
  (4, 'Urgente', '#C7362B',   4, 4)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

INSERT INTO `departamentos` (`id_departamento`, `nombre`, `color`) VALUES
  (1, 'Servicios Técnicos', '#0B5563'),
  (2, 'Pisos / Limpieza',   '#7C3AED'),
  (3, 'Recepción',          '#0EA5E9'),
  (4, 'Cocina / A&B',       '#F2A007'),
  (5, 'Informática',        '#0D7C57'),
  (6, 'Jardinería',         '#15803D'),
  (7, 'Seguridad',          '#334155')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

-- Todo el personal existente queda en Servicios Técnicos; ajústalo
-- después desde Ajustes › Equipo.
UPDATE `login` SET `id_departamento` = 1 WHERE `id_departamento` IS NULL;

-- ---------------------------------------------------------------------
-- 9. Iconos por familia de elemento
--    Son 313: agruparlos por palabras clave evita que la aplicación
--    muestre 313 dibujos iguales. Lo que no encaje se queda con la
--    llave inglesa, que es un valor perfectamente válido.
-- ---------------------------------------------------------------------
UPDATE `elemento` SET `icono` = 'gota' WHERE `nombre_elemento` REGEXP
  'agua|grifo|fuga|lavabo|ducha|bañera|inodoro|cisterna|desagüe|desague|sifón|sifon|fontan|tuber|arqueta|bomba|riego|piscina|alcachofa|bidé|bide';

UPDATE `elemento` SET `icono` = 'rayo' WHERE `nombre_elemento` REGEXP
  'luz|luces|lámpara|lampara|aplique|bombilla|enchufe|interruptor|eléctric|electric|cuadro|diferencial|magnetot|foco|led|fluorescente|halógen|halogen|corriente|generador';

UPDATE `elemento` SET `icono` = 'viento' WHERE `nombre_elemento` REGEXP
  'aire|climat|split|ventilad|extract|calefac|caldera|radiador|termo|refriger|frigorí|frigori|cámara fr|camara fr|congelad|abatidor';

UPDATE `elemento` SET `icono` = 'martillo' WHERE `nombre_elemento` REGEXP
  'puerta|ventana|marco|carpint|madera|mueble|armario|cajón|cajon|estante|bisagra|persiana|mampara|albañil|albanil|baldosa|alicatad|pintura|pared|techo|suelo';

UPDATE `elemento` SET `icono` = 'silla' WHERE `nombre_elemento` REGEXP
  'silla|sillón|sillon|mesa|cama|colchón|colchon|somier|sofá|sofa|butaca|escritorio|tumbona|hamaca|mobiliario|cabecero|mesilla';

UPDATE `elemento` SET `icono` = 'tele' WHERE `nombre_elemento` REGEXP
  'tv|televis|pantalla|mando|canal|proyector|altavoz|bose|audio|sonido|megafon|hilo musical';

UPDATE `elemento` SET `icono` = 'wifi' WHERE `nombre_elemento` REGEXP
  'wifi|wi-fi|internet|router|switch|ordenador|impresora|pms|tpv|informát|informat|teléfono|telefono|cable|datos';

UPDATE `elemento` SET `icono` = 'llave' WHERE `nombre_elemento` REGEXP
  'cerradur|llave|tarjeta|candado|caja fuerte|acceso|barrera|torno|cierre';

UPDATE `elemento` SET `icono` = 'brillo' WHERE `nombre_elemento` REGEXP
  'limpiez|amenit|toalla|sábana|sabana|albornoz|almohada|alfombr|cortina|lencer|ambient|aroma|papeler';

-- Departamento sugerido a partir del icono
UPDATE `elemento` SET `id_departamento` = 5 WHERE `icono` = 'wifi'   AND `id_departamento` IS NULL;
UPDATE `elemento` SET `id_departamento` = 2 WHERE `icono` = 'brillo' AND `id_departamento` IS NULL;
UPDATE `elemento` SET `id_departamento` = 1 WHERE `id_departamento` IS NULL;

-- ---------------------------------------------------------------------
-- 10. Limpieza opcional
-- ---------------------------------------------------------------------
-- La tabla `test` no la usa nada:
-- DROP TABLE IF EXISTS `test`;

-- Comprobaciones después de migrar:
--   SELECT COUNT(*) FROM ticket_incidencias WHERE uuid IS NULL;   -- debe dar 0
--   SELECT id_estado, nombre_estado, orden, es_inicial, es_final FROM estados ORDER BY orden;
--   SELECT icono, COUNT(*) FROM elemento GROUP BY icono ORDER BY 2 DESC;
--   SELECT ruta FROM rel_zonas WHERE ruta IS NOT NULL LIMIT 5;
