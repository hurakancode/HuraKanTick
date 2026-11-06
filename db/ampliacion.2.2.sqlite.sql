-- =====================================================================
--  SSTT 2.2 — Ampliación
--
--  Configuración, campos por tipo de avería, atajos, informes guardados,
--  preferencias de aviso y registro de copias de seguridad.
--
--  Se aplica sobre una instalación 2.x ya existente. No borra nada.
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.2.2.sqlite.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- Configuración del sistema
--
-- Todo lo que se puede encender y apagar vive aquí. La aplicación lee
-- estos valores al entrar y esconde lo que esté desactivado, así que un
-- hotel que sólo quiera partes y fotos no ve nada más.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ajustes_sistema (
  clave       TEXT PRIMARY KEY,
  valor       TEXT,
  tipo        TEXT NOT NULL DEFAULT 'texto',   -- texto | numero | interruptor | lista | json
  categoria   TEXT NOT NULL DEFAULT 'general',
  titulo      TEXT NOT NULL,
  descripcion TEXT,
  opciones    TEXT,                            -- JSON con los valores admitidos
  orden       INTEGER NOT NULL DEFAULT 0,
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

-- ---------------------------------------------------------------------
-- Campos propios por tipo de avería
--
-- En la versión antigua el aire acondicionado tenía su propio formulario
-- (aa.php). Generalizado: cualquier tipo puede pedir los datos que le
-- hagan falta, sin tocar el código.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campos_elemento (
  id_campo     INTEGER PRIMARY KEY AUTOINCREMENT,
  id_elemento  INTEGER REFERENCES elemento(id_elemento),  -- NULL = todos
  clave        TEXT    NOT NULL,
  etiqueta     TEXT    NOT NULL,
  tipo         TEXT    NOT NULL DEFAULT 'texto',
      -- texto | numero | lista | interruptor | fecha | area | temperatura
  opciones     TEXT,                    -- JSON: ["Frío","Calor","Ventilación"]
  unidad       TEXT,                    -- °C, bar, h…
  obligatorio  INTEGER NOT NULL DEFAULT 0,
  ayuda        TEXT,
  orden        INTEGER NOT NULL DEFAULT 0,
  activo       INTEGER NOT NULL DEFAULT 1,
  updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_campos_elem ON campos_elemento(id_elemento, activo);

CREATE TABLE IF NOT EXISTS valores_campo (
  id_valor   INTEGER PRIMARY KEY AUTOINCREMENT,
  id_ticket  INTEGER NOT NULL REFERENCES ticket_incidencias(id_ticket),
  id_campo   INTEGER NOT NULL REFERENCES campos_elemento(id_campo),
  valor      TEXT,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  UNIQUE (id_ticket, id_campo)
);
CREATE INDEX IF NOT EXISTS ix_valores_ticket ON valores_campo(id_ticket);

-- ---------------------------------------------------------------------
-- Atajos de la pantalla de inicio
--
-- Un atajo es un filtro guardado con nombre y color. El administrador
-- decide cuáles ve todo el mundo; cada persona puede añadir los suyos.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS atajos (
  id_atajo    INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre      TEXT    NOT NULL,
  icono       TEXT    NOT NULL DEFAULT 'lista',
  color       TEXT    NOT NULL DEFAULT '#0B5563',
  filtro      TEXT    NOT NULL,          -- JSON con los mismos filtros del listado
  id_login    INTEGER REFERENCES login(id_login),   -- NULL = para todos
  id_departamento INTEGER REFERENCES departamentos(id_departamento),
  solo_rol    TEXT,                      -- NULL = cualquier rol
  orden       INTEGER NOT NULL DEFAULT 0,
  activo      INTEGER NOT NULL DEFAULT 1,
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_atajos_login ON atajos(id_login, activo);

-- ---------------------------------------------------------------------
-- Informes guardados
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS informes (
  id_informe  INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre      TEXT    NOT NULL,
  descripcion TEXT,
  definicion  TEXT    NOT NULL,          -- JSON: condiciones, agrupación, medida
  id_login    INTEGER REFERENCES login(id_login),   -- NULL = compartido
  compartido  INTEGER NOT NULL DEFAULT 0,
  favorito    INTEGER NOT NULL DEFAULT 0,
  ultimo_uso  TEXT,
  creado      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS ix_informes_login ON informes(id_login);

-- ---------------------------------------------------------------------
-- Avisos: qué quiere recibir cada persona
--
-- El administrador fija el valor por defecto en ajustes_sistema; aquí
-- sólo se guardan las diferencias de cada uno.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS avisos_pref (
  id_login   INTEGER NOT NULL REFERENCES login(id_login),
  evento     TEXT    NOT NULL,
      -- asignado | asignado_grupo | cambio_estado | nota | cerrado
      -- vencido | urgente_nuevo | conflicto
  activo     INTEGER NOT NULL DEFAULT 1,
  sonido     INTEGER NOT NULL DEFAULT 1,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  PRIMARY KEY (id_login, evento)
);

-- ---------------------------------------------------------------------
-- Copias de seguridad
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS copias (
  id_copia   INTEGER PRIMARY KEY AUTOINCREMENT,
  archivo    TEXT    NOT NULL,
  tamano     INTEGER NOT NULL DEFAULT 0,
  incluye_fotos INTEGER NOT NULL DEFAULT 0,
  tablas     INTEGER NOT NULL DEFAULT 0,
  registros  INTEGER NOT NULL DEFAULT 0,
  hash       TEXT,
  id_login   INTEGER REFERENCES login(id_login),
  automatica INTEGER NOT NULL DEFAULT 0,
  fecha      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

-- Marca de aviso de retraso: sin ella, la tarea programada repetiría el
-- mismo aviso cada hora y la gente aprendería a ignorarlos.
ALTER TABLE ticket_incidencias ADD COLUMN aviso_vencido TEXT;

-- ---------------------------------------------------------------------
-- Valores iniciales de configuración
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES

-- Visibilidad
('visibilidad_tickets', 'grupo', 'lista', 'visibilidad',
 'Qué partes ve cada persona',
 'Los administradores y responsables ven todo en cualquier caso.',
 '[{"v":"todos","t":"Todos los partes"},{"v":"grupo","t":"Los de su grupo"},{"v":"propios","t":"Sólo los suyos"}]', 1),

('ver_otros_grupos', '0', 'interruptor', 'visibilidad',
 'Ver partes de otros grupos en modo consulta',
 'Permite abrirlos y leerlos, pero no modificarlos.', NULL, 2),

('reportador_ve_propios', '1', 'interruptor', 'visibilidad',
 'Quien sólo avisa ve únicamente lo que ha abierto',
 'Recomendado para personal que reporta pero no repara.', NULL, 3),

-- Funciones que se pueden apagar
('mod_informes', '1', 'interruptor', 'modulos', 'Informes',
 'Consultas con condiciones, agrupaciones y exportación.', NULL, 10),
('mod_atajos', '1', 'interruptor', 'modulos', 'Atajos en el inicio',
 'Filtros guardados con nombre en la pantalla principal.', NULL, 11),
('mod_campos', '1', 'interruptor', 'modulos', 'Campos propios por tipo',
 'Permite que cada tipo de avería pida sus propios datos.', NULL, 12),
('mod_grupos', '1', 'interruptor', 'modulos', 'Asignación a grupos',
 'Además de a personas concretas.', NULL, 13),
('mod_tiempo', '1', 'interruptor', 'modulos', 'Tiempo dedicado',
 'Anotar minutos de trabajo en cada movimiento.', NULL, 14),
('mod_coste', '0', 'interruptor', 'modulos', 'Coste',
 'Registrar el importe de cada reparación.', NULL, 15),
('mod_prioridad', '1', 'interruptor', 'modulos', 'Prioridades y plazos',
 'Si se apaga, todos los partes son iguales y sin plazo.', NULL, 16),
('mod_fotos', '1', 'interruptor', 'modulos', 'Fotografías', NULL, NULL, 17),
('mod_exportar', '1', 'interruptor', 'modulos', 'Exportar a CSV', NULL, NULL, 18),
('mod_copias', '1', 'interruptor', 'modulos', 'Copias de seguridad',
 'Sólo visible para administradores.', NULL, 19),

-- Avisos por defecto
('aviso_asignado', '1', 'interruptor', 'avisos',
 'Avisar cuando me asignan un parte', NULL, NULL, 30),
('aviso_asignado_grupo', '1', 'interruptor', 'avisos',
 'Avisar cuando asignan un parte a mi grupo', NULL, NULL, 31),
('aviso_cambio_estado', '1', 'interruptor', 'avisos',
 'Avisar de cambios en partes que abrí o tengo asignados', NULL, NULL, 32),
('aviso_nota', '1', 'interruptor', 'avisos',
 'Avisar de notas nuevas', NULL, NULL, 33),
('aviso_urgente_nuevo', '1', 'interruptor', 'avisos',
 'Avisar de cualquier parte urgente, aunque no sea mío', NULL, NULL, 34),
('aviso_vencido', '1', 'interruptor', 'avisos',
 'Avisar cuando un parte mío se pasa de plazo', NULL, NULL, 35),
('avisos_sonido_defecto', '1', 'interruptor', 'avisos',
 'Sonido activado para las cuentas nuevas', NULL, NULL, 36),

-- Comportamiento
('cerrar_pide_nota', '1', 'interruptor', 'comportamiento',
 'Exigir una nota al cerrar un parte', NULL, NULL, 40),
('autoasignar_al_coger', '1', 'interruptor', 'comportamiento',
 'Asignarse el parte al empezar a trabajarlo', NULL, NULL, 41),
('permitir_zona_nueva', '1', 'interruptor', 'comportamiento',
 'Los técnicos pueden crear ubicaciones nuevas', NULL, NULL, 42),
('dias_historico', '90', 'numero', 'comportamiento',
 'Días de histórico en el móvil',
 'Cuánto pasado se descarga a cada aparato. Los partes más viejos siguen en el servidor y se consultan en línea. Con 90 días un hotel con mucho movimiento sincroniza en segundos; con un año entero puede no caber.',NULL,7),

-- Identidad
('org_nombre', 'Servicios Técnicos', 'texto', 'general',
 'Nombre de la organización', NULL, NULL, 50),
('org_color', '#0B5563', 'texto', 'general',
 'Color principal', 'En formato #RRGGBB.', NULL, 51);

-- ---------------------------------------------------------------------
-- Campos de ejemplo para el aire acondicionado
--
-- Reproduce el formulario especial que tenía el elemento en la versión
-- anterior. Se puede borrar o cambiar desde Administración.
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO campos_elemento
  (id_campo, id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT 1, e.id_elemento, 'aa_modo', 'Modo del equipo', 'lista',
       '["Frío","Calor","Ventilación","Automático","Apagado"]', NULL, 0,
       'En qué posición estaba el mando al detectar la avería.', 1, 1
  FROM elemento e WHERE LOWER(e.nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1;

INSERT OR IGNORE INTO campos_elemento
  (id_campo, id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT 2, e.id_elemento, 'aa_temp_consigna', 'Temperatura marcada', 'temperatura',
       NULL, '°C', 0, 'La que pedía el mando.', 2, 1
  FROM elemento e WHERE LOWER(e.nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1;

INSERT OR IGNORE INTO campos_elemento
  (id_campo, id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT 3, e.id_elemento, 'aa_temp_real', 'Temperatura de la sala', 'temperatura',
       NULL, '°C', 0, 'La que hacía en realidad.', 3, 1
  FROM elemento e WHERE LOWER(e.nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1;

INSERT OR IGNORE INTO campos_elemento
  (id_campo, id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT 4, e.id_elemento, 'aa_sintoma', 'Qué le pasa', 'lista',
       '["No enfría","No calienta","No arranca","Hace ruido","Gotea","Huele mal","Mando sin respuesta"]',
       NULL, 0, NULL, 4, 1
  FROM elemento e WHERE LOWER(e.nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1;

INSERT OR IGNORE INTO campos_elemento
  (id_campo, id_elemento, clave, etiqueta, tipo, opciones, unidad, obligatorio, ayuda, orden, activo)
SELECT 5, e.id_elemento, 'aa_filtros', 'Filtros revisados', 'interruptor',
       NULL, NULL, 0, 'Marca si ya los has mirado.', 5, 1
  FROM elemento e WHERE LOWER(e.nombre_elemento) LIKE '%aire acondicionado%' LIMIT 1;

-- ---------------------------------------------------------------------
-- Atajos iniciales
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO atajos (id_atajo, nombre, icono, color, filtro, id_login, orden, activo) VALUES
 (1, 'Urgentes abiertos', 'aviso',    '#C7362B', '{"abiertos":true,"prioridad":[4],"orden":"limite"}', NULL, 1, 1),
 (2, 'Fuera de plazo',    'reloj',    '#B45309', '{"abiertos":true,"vencidos":true,"orden":"limite"}',  NULL, 2, 1),
 (3, 'Sin asignar',       'personas', '#0B5563', '{"abiertos":true,"sin_asignar":true}',                NULL, 3, 1),
 (4, 'Cerrados esta semana', 'ok',    '#0D7C57', '{"cerrados_semana":true,"orden":"reciente"}',         NULL, 4, 0);
