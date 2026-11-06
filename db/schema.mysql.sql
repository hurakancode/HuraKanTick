-- =====================================================================
--  HuraKan Tick — Esquema MySQL / MariaDB (instalación nueva)
--  Si ya tienes la base de datos antigua, usa db/migracion.legacy.mysql.sql
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS departamentos (
  id_departamento INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(80)  NOT NULL,
  color      VARCHAR(9)   NOT NULL DEFAULT '#0B5563',
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login (
  id_login   INT AUTO_INCREMENT PRIMARY KEY,
  correo     VARCHAR(512) NOT NULL,
  pwd_php    VARCHAR(512) NOT NULL,
  pwd_sha2   VARCHAR(512) NULL,
  nombre     VARCHAR(150) NOT NULL DEFAULT '',
  apellido1  VARCHAR(150) NOT NULL DEFAULT '',
  apellido2  VARCHAR(150) NULL,
  telefono   VARCHAR(32)  NULL,
  admin      TINYINT(1)   NOT NULL DEFAULT 0,
  rol        VARCHAR(20)  NOT NULL DEFAULT 'tecnico',
  id_departamento INT NULL,
  bloqueo    TINYINT(1)   NOT NULL DEFAULT 0,
  fecha_alta DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso DATETIME  NULL,
  intentos   INT          NOT NULL DEFAULT 0,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_correo (correo(190)),
  KEY ix_login_dep (id_departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estados (
  id_estado     INT AUTO_INCREMENT PRIMARY KEY,
  nombre_estado VARCHAR(50) NOT NULL,
  -- En la instalación real pueden venir vacíos: la aplicación aplica
  -- un valor por defecto al pintarlos.
  color         VARCHAR(9)  NULL,
  class         VARCHAR(50) NULL,
  svg           LONGTEXT NULL,
  orden         INT NOT NULL DEFAULT 0,
  es_inicial    TINYINT(1) NOT NULL DEFAULT 0,
  es_final      TINYINT(1) NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prioridades (
  id_prioridad INT AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(40) NOT NULL,
  color     VARCHAR(9)  NOT NULL DEFAULT '#64748B',
  sla_horas INT NULL,
  orden     INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nombre en singular y columnas de la instalación original, para que la
-- migración sea puramente aditiva sobre una base de datos en producción.
CREATE TABLE IF NOT EXISTS elemento (
  id_elemento INT AUTO_INCREMENT PRIMARY KEY,
  nombre_elemento      VARCHAR(150) NOT NULL,
  abreviatura_elemento VARCHAR(150) NULL,
  baja                 TINYINT(1)   NOT NULL DEFAULT 0,
  -- añadidos por la versión 2.0
  icono           VARCHAR(40) NOT NULL DEFAULT 'llave-inglesa',
  id_departamento INT NULL,
  orden           INT NOT NULL DEFAULT 0,
  usos            INT NOT NULL DEFAULT 0,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_elemento_nombre (nombre_elemento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS zona_1 (
  id_zona_1 INT AUTO_INCREMENT PRIMARY KEY,
  nombre_zona_1 VARCHAR(150) NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS zona_2 (
  id_zona_2 INT AUTO_INCREMENT PRIMARY KEY,
  nombre_zona_2 VARCHAR(150) NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS zona_3 (
  id_zona_3 INT AUTO_INCREMENT PRIMARY KEY,
  nombre_zona_3 VARCHAR(150) NOT NULL,
  nombre_zona_3_limpio VARCHAR(150) NULL,
  orden INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS zona_4 (
  id_zona_4 INT AUTO_INCREMENT PRIMARY KEY,
  nombre_zona_4 VARCHAR(150) NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rel_zonas (
  id_rel    INT AUTO_INCREMENT PRIMARY KEY,
  id_zona_1 INT NULL,
  id_zona_2 INT NULL,
  id_zona_3 INT NULL,
  id_zona_4 INT NULL,
  ruta      VARCHAR(600) NULL,
  id_realiza_accion INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_rel_combo (id_zona_1,id_zona_2,id_zona_3,id_zona_4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_incidencias (
  id_ticket     INT AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36)     NOT NULL,
  titulo_ticket VARCHAR(150) NOT NULL,
  detalle_ticket TEXT NULL,
  fecha_creado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_limite  DATETIME NULL,
  fecha_cierre  DATETIME NULL,
  id_estado     INT NOT NULL DEFAULT 1,
  id_prioridad  INT NOT NULL DEFAULT 2,
  id_elemento   INT NULL,
  id_rel_zona   INT NULL,
  id_realiza_accion   INT NOT NULL,
  id_persona_asignada INT NULL,
  id_grupo_asignado   INT NULL,
  origen        VARCHAR(16) NOT NULL DEFAULT 'app',
  -- columnas heredadas de la versión 1.x que se conservan
  fecha_creado_sin_hora  DATE NULL,
  ids_grupos_asignados   VARCHAR(150) NULL,
  ids_personas_asignadas VARCHAR(150) NULL,
  id_version    INT NOT NULL DEFAULT 1 COMMENT 'Se incrementa en cada cambio: evita el conflicto de doble asignación',
  aviso_vencido DATETIME NULL COMMENT 'Cuándo se avisó del retraso',
  minutos_trabajo INT NOT NULL DEFAULT 0,
  coste         DECIMAL(10,2) NOT NULL DEFAULT 0,
  borrado       TINYINT(1) NOT NULL DEFAULT 0,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_uuid (uuid),
  KEY ix_ticket_estado (id_estado),
  KEY ix_ticket_asignada (id_persona_asignada),
  KEY ix_ticket_updated (updated_at),
  KEY ix_ticket_zona (id_rel_zona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_incidencias_historial (
  id_historial INT AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL,
  id_ticket    INT NOT NULL,
  tipo         VARCHAR(20) NOT NULL DEFAULT 'nota',
  titulo_ticket VARCHAR(150) NULL,
  detalle_ticket TEXT NULL,
  id_estado     INT NULL,
  id_estado_old INT NULL,
  id_login      INT NULL,
  id_login_old  INT NULL,
  id_grupo_asignado    INT NULL,
  ids_grupos_asignados INT NULL,
  id_realiza_accion INT NOT NULL,
  minutos      INT NOT NULL DEFAULT 0,
  fecha_creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hist_uuid (uuid),
  KEY ix_hist_ticket (id_ticket),
  KEY ix_hist_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adjuntos (
  id_adjunto INT AUTO_INCREMENT PRIMARY KEY,
  uuid       CHAR(36) NOT NULL,
  id_ticket  INT NOT NULL,
  archivo    VARCHAR(255) NOT NULL,
  nombre_original VARCHAR(255) NULL,
  mime       VARCHAR(80) NULL,
  tamano     INT NOT NULL DEFAULT 0,
  hash       CHAR(64) NULL,
  ancho      INT NULL,
  alto       INT NULL,
  id_realiza_accion INT NOT NULL,
  borrado    TINYINT(1) NOT NULL DEFAULT 0,
  fecha_creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_adj_uuid (uuid),
  KEY ix_adj_ticket (id_ticket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id_token   INT AUTO_INCREMENT PRIMARY KEY,
  id_login   INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  dispositivo VARCHAR(120) NULL,
  plataforma  VARCHAR(40)  NULL,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME NULL,
  expira     DATETIME NULL,
  revocado   TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY ix_token_login (id_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `intentos_acceso` (
  `id`     INT AUTO_INCREMENT PRIMARY KEY,
  `huella` CHAR(64) NOT NULL,
  `correo` VARCHAR(190) NULL,
  `fecha`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_intentos` (`huella`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notificaciones (
  id_notificacion INT AUTO_INCREMENT PRIMARY KEY,
  id_login  INT NOT NULL,
  id_ticket INT NULL,
  titulo    VARCHAR(160) NOT NULL,
  cuerpo    VARCHAR(400) NULL,
  leida     TINYINT(1) NOT NULL DEFAULT 0,
  fecha     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_notif_login (id_login, leida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preferencias (
  id_login INT NOT NULL,
  clave    VARCHAR(60) NOT NULL,
  valor    TEXT NULL,
  PRIMARY KEY (id_login, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
