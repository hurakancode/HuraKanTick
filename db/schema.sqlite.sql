-- =====================================================================
--  HuraKan Tick — Esquema SQLite
--  Motor de desarrollo / demo / instalaciones pequeñas.
--  Todas las fechas se guardan como TEXT ISO-8601 UTC: 'YYYY-MM-DD HH:MM:SS'
-- =====================================================================

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------
-- Organización
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departamentos (
  id_departamento INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre          TEXT    NOT NULL,
  color           TEXT    NOT NULL DEFAULT '#0B5563',
  activo          INTEGER NOT NULL DEFAULT 1,
  updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE IF NOT EXISTS login (
  id_login        INTEGER PRIMARY KEY AUTOINCREMENT,
  correo          TEXT    NOT NULL UNIQUE,
  pwd_php         TEXT    NOT NULL,
  pwd_sha2        TEXT,
  nombre          TEXT    NOT NULL DEFAULT '',
  apellido1       TEXT    NOT NULL DEFAULT '',
  apellido2       TEXT    NOT NULL DEFAULT '',
  telefono        TEXT,
  admin           INTEGER NOT NULL DEFAULT 0,
  rol             TEXT    NOT NULL DEFAULT 'tecnico',   -- admin | responsable | tecnico | reportador
  id_departamento INTEGER REFERENCES departamentos(id_departamento),
  bloqueo         INTEGER NOT NULL DEFAULT 0,
  fecha_alta      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  ultimo_acceso   TEXT,
  intentos        INTEGER NOT NULL DEFAULT 0,
  updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_login_correo ON login(correo);

-- ---------------------------------------------------------------------
-- Catálogos
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estados (
  id_estado    INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_estado TEXT   NOT NULL,
  -- En la instalación real color y class pueden venir vacíos: la
  -- aplicación aplica un valor por defecto al pintarlos.
  color        TEXT,
  class        TEXT,
  svg          TEXT,
  orden        INTEGER NOT NULL DEFAULT 0,
  es_inicial   INTEGER NOT NULL DEFAULT 0,
  es_final     INTEGER NOT NULL DEFAULT 0,
  activo       INTEGER NOT NULL DEFAULT 1,
  updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE IF NOT EXISTS prioridades (
  id_prioridad INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre       TEXT    NOT NULL,
  color        TEXT    NOT NULL DEFAULT '#64748B',
  sla_horas    INTEGER,                      -- NULL = sin compromiso de plazo
  orden        INTEGER NOT NULL DEFAULT 0,
  updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

-- Nombre en singular y columnas de la instalación original: la migración
-- es puramente aditiva y no hay que reescribir datos de producción.
CREATE TABLE IF NOT EXISTS elemento (
  id_elemento          INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_elemento      TEXT    NOT NULL,
  abreviatura_elemento TEXT,
  baja                 INTEGER NOT NULL DEFAULT 0,
  -- añadidos por la versión 2.0
  icono                TEXT    NOT NULL DEFAULT 'llave-inglesa',
  id_departamento      INTEGER REFERENCES departamentos(id_departamento),
  orden                INTEGER NOT NULL DEFAULT 0,
  usos                 INTEGER NOT NULL DEFAULT 0,
  updated_at           TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_elemento_nombre ON elemento(nombre_elemento);

-- ---------------------------------------------------------------------
-- Jerarquía de zonas (4 niveles: edificio > planta/área > unidad > punto)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zona_1 (
  id_zona_1 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_zona_1 TEXT NOT NULL,
  orden INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE TABLE IF NOT EXISTS zona_2 (
  id_zona_2 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_zona_2 TEXT NOT NULL,
  orden INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE TABLE IF NOT EXISTS zona_3 (
  id_zona_3 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_zona_3 TEXT NOT NULL,
  nombre_zona_3_limpio TEXT,
  orden INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE TABLE IF NOT EXISTS zona_4 (
  id_zona_4 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre_zona_4 TEXT NOT NULL,
  orden INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE IF NOT EXISTS rel_zonas (
  id_rel     INTEGER PRIMARY KEY AUTOINCREMENT,
  id_zona_1  INTEGER REFERENCES zona_1(id_zona_1),
  id_zona_2  INTEGER REFERENCES zona_2(id_zona_2),
  id_zona_3  INTEGER REFERENCES zona_3(id_zona_3),
  id_zona_4  INTEGER REFERENCES zona_4(id_zona_4),
  ruta       TEXT,                            -- caché legible: "Edificio A › Planta 2 › 214 › Dormitorio"
  id_realiza_accion INTEGER REFERENCES login(id_login),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_rel_zonas_combo ON rel_zonas(id_zona_1,id_zona_2,id_zona_3,id_zona_4);

-- ---------------------------------------------------------------------
-- Tickets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_incidencias (
  id_ticket           INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid                TEXT    NOT NULL UNIQUE,       -- idempotencia offline
  titulo_ticket       TEXT    NOT NULL,
  detalle_ticket      TEXT,
  fecha_creado        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  fecha_limite        TEXT,
  fecha_cierre        TEXT,
  id_estado           INTEGER NOT NULL DEFAULT 1 REFERENCES estados(id_estado),
  id_prioridad        INTEGER NOT NULL DEFAULT 2 REFERENCES prioridades(id_prioridad),
  id_elemento         INTEGER REFERENCES elemento(id_elemento),
  id_rel_zona         INTEGER REFERENCES rel_zonas(id_rel),
  id_realiza_accion   INTEGER NOT NULL REFERENCES login(id_login),
  id_persona_asignada INTEGER REFERENCES login(id_login),
  id_grupo_asignado   INTEGER REFERENCES departamentos(id_departamento),
  origen              TEXT    NOT NULL DEFAULT 'app', -- app | offline | email | web
  -- columnas heredadas de la versión 1.x que se conservan
  fecha_creado_sin_hora  TEXT,
  ids_grupos_asignados   TEXT,
  ids_personas_asignadas TEXT,
  id_version          INTEGER NOT NULL DEFAULT 1,  -- control de doble asignación
  aviso_vencido       TEXT,          -- cuándo se avisó del retraso
  minutos_trabajo     INTEGER NOT NULL DEFAULT 0,
  coste               REAL    NOT NULL DEFAULT 0,
  borrado             INTEGER NOT NULL DEFAULT 0,
  updated_at          TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_ticket_estado    ON ticket_incidencias(id_estado);
CREATE INDEX IF NOT EXISTS ix_ticket_asignada  ON ticket_incidencias(id_persona_asignada);
CREATE INDEX IF NOT EXISTS ix_ticket_updated   ON ticket_incidencias(updated_at);
CREATE INDEX IF NOT EXISTS ix_ticket_zona      ON ticket_incidencias(id_rel_zona);

CREATE TABLE IF NOT EXISTS ticket_incidencias_historial (
  id_historial      INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid              TEXT    NOT NULL UNIQUE,
  id_ticket         INTEGER NOT NULL REFERENCES ticket_incidencias(id_ticket),
  tipo              TEXT    NOT NULL DEFAULT 'nota',  -- alta | nota | estado | asignacion | edicion | adjunto
  titulo_ticket     TEXT,
  detalle_ticket    TEXT,
  id_estado         INTEGER REFERENCES estados(id_estado),
  id_estado_old     INTEGER REFERENCES estados(id_estado),
  id_login          INTEGER REFERENCES login(id_login),
  id_login_old      INTEGER REFERENCES login(id_login),
  id_grupo_asignado    INTEGER,
  ids_grupos_asignados TEXT,
  id_realiza_accion INTEGER NOT NULL REFERENCES login(id_login),
  minutos           INTEGER NOT NULL DEFAULT 0,
  fecha_creado      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_hist_ticket  ON ticket_incidencias_historial(id_ticket);
CREATE INDEX IF NOT EXISTS ix_hist_updated ON ticket_incidencias_historial(updated_at);

CREATE TABLE IF NOT EXISTS adjuntos (
  id_adjunto        INTEGER PRIMARY KEY AUTOINCREMENT,
  uuid              TEXT    NOT NULL UNIQUE,
  id_ticket         INTEGER NOT NULL REFERENCES ticket_incidencias(id_ticket),
  archivo           TEXT    NOT NULL,
  nombre_original   TEXT,
  mime              TEXT,
  tamano            INTEGER NOT NULL DEFAULT 0,
  hash              TEXT,
  ancho             INTEGER,
  alto              INTEGER,
  id_realiza_accion INTEGER NOT NULL REFERENCES login(id_login),
  borrado           INTEGER NOT NULL DEFAULT 0,
  fecha_creado      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at        TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_adj_ticket ON adjuntos(id_ticket);

-- ---------------------------------------------------------------------
-- Sesiones de API (compatibles con app nativa: sin cookies)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
  id_token    INTEGER PRIMARY KEY AUTOINCREMENT,
  id_login    INTEGER NOT NULL REFERENCES login(id_login),
  token_hash  TEXT    NOT NULL UNIQUE,
  dispositivo TEXT,
  plataforma  TEXT,
  creado      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  ultimo_uso  TEXT,
  expira      TEXT,
  revocado    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_token_login ON api_tokens(id_login);

CREATE TABLE IF NOT EXISTS intentos_acceso (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  huella  TEXT    NOT NULL,          -- hash del origen, nunca la IP en claro
  correo  TEXT,
  fecha   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_intentos ON intentos_acceso(huella, fecha);

CREATE TABLE IF NOT EXISTS notificaciones (
  id_notificacion INTEGER PRIMARY KEY AUTOINCREMENT,
  id_login   INTEGER NOT NULL REFERENCES login(id_login),
  id_ticket  INTEGER REFERENCES ticket_incidencias(id_ticket),
  titulo     TEXT NOT NULL,
  cuerpo     TEXT,
  leida      INTEGER NOT NULL DEFAULT 0,
  fecha      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_notif_login ON notificaciones(id_login, leida);

CREATE TABLE IF NOT EXISTS preferencias (
  id_login INTEGER NOT NULL REFERENCES login(id_login),
  clave    TEXT NOT NULL,
  valor    TEXT,
  PRIMARY KEY (id_login, clave)
);
