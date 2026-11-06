SET NAMES utf8mb4;

-- Los índices van con ALTER TABLE ... ADD KEY: MySQL no entiende
-- «CREATE INDEX IF NOT EXISTS», y el instalador se tragaba el error como
-- un aviso. El resultado era una base sin índices: con veinte partes da
-- igual, con veinte mil el listado tarda segundos.

-- =====================================================================
--  SSTT 2.9 — Unidades con número de serie
--
--  La mayoría de los 313 elementos no tienen número de serie: una
--  cerradura, un zócalo o un grifo se cambian y nadie los identifica.
--  Pero un televisor, un aire acondicionado o una máquina de cocina sí,
--  y ahí interesa saber cuál está puesto, desde cuándo y a cuál sustituyó.
--
--  De ahí la pregunta que se hace de verdad: «la 1008 ha tenido cinco
--  televisores, cuatro dados de baja por rotura». Eso no se responde
--  con el historial de partes: hace falta seguir la pista al aparato.
--
--  Por eso es opcional y por elemento: sólo se pide número de serie
--  donde tiene sentido, y el resto de la aplicación no se entera.
--
--    mysql -u USUARIO -p BASE < db/ampliacion.2.9.mysql.sql
-- =====================================================================

-- Sólo estos tipos piden número de serie al dar de alta un parte.
ALTER TABLE `elemento` ADD COLUMN `usa_serie` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `unidades` (
  id_unidad     INT AUTO_INCREMENT PRIMARY KEY,
  numero_serie  VARCHAR(80)    NOT NULL,
  id_elemento   INT NOT NULL REFERENCES elemento(id_elemento),
  id_rel_zona   INT REFERENCES rel_zonas(id_rel),

  estado        VARCHAR(16)    NOT NULL DEFAULT 'activa',
      -- activa | reparacion | almacen | baja
  motivo_baja   VARCHAR(20),
      -- rotura | obsoleto | robo | fin_vida | garantia | otro

  marca         VARCHAR(80),
  modelo        VARCHAR(120),
  garantia_hasta DATE,
  notas         VARCHAR(500),

  -- A qué unidad sustituyó: encadena la historia de un sitio
  id_sustituye  INT REFERENCES unidades(id_unidad),

  fecha_alta    DATE NOT NULL DEFAULT (CURRENT_DATE),
  fecha_baja    DATE,
  id_alta       INT REFERENCES login(id_login),
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `unidades` ADD KEY `ix_unid_serie` (numero_serie);
ALTER TABLE `unidades` ADD KEY `ix_unid_zona` (id_rel_zona, estado);
ALTER TABLE `unidades` ADD KEY `ix_unid_elem` (id_elemento, estado);

-- Movimientos: dónde ha estado y en qué estado.
-- Sin esto, mover un aparato de habitación borraría su paso por la
-- anterior, y la pregunta «qué ha tenido la 1008» quedaría incompleta.
CREATE TABLE IF NOT EXISTS `unidades_mov` (
  id_mov      INT AUTO_INCREMENT PRIMARY KEY,
  id_unidad   INT NOT NULL REFERENCES unidades(id_unidad),
  id_rel_zona INT REFERENCES rel_zonas(id_rel),
  estado      VARCHAR(16)    NOT NULL,
  motivo      VARCHAR(120),
  id_ticket   INT REFERENCES ticket_incidencias(id_ticket),
  id_login    INT REFERENCES login(id_login),
  fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `unidades_mov` ADD KEY `ix_mov_unidad` (id_unidad, fecha);
ALTER TABLE `unidades_mov` ADD KEY `ix_mov_zona` (id_rel_zona);

-- El parte puede referirse a un aparato concreto
ALTER TABLE `ticket_incidencias` ADD COLUMN `id_unidad` INT NULL;
ALTER TABLE `ticket_incidencias` ADD KEY `ix_ticket_unidad` (id_unidad);

INSERT IGNORE INTO ajustes_sistema (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden) VALUES
 ('mod_unidades','1','interruptor','modulos','Números de serie',
  'Seguir la pista a los aparatos que lo tienen: televisores, climatización, maquinaria. El resto de elementos no se entera.',NULL,25);

-- Los tipos que normalmente llevan número de serie en un hotel.
-- Es un punto de partida: se cambia desde Administración › Familias.
--
-- Ojo con las palabras cortas: «%tv%» también casa con «Pared tv
-- desconchon» y con «Revisar tv», que no son aparatos. Por eso los
-- casos ambiguos van por nombre exacto y no por contiene.
UPDATE elemento SET usa_serie = 1
 WHERE LOWER(nombre_elemento) IN (
        'tv', 'tv mando', 'mando tv', 'tv variedad canales',
        'caja fuerte', 'secador', 'minibar', 'cafetera', 'cafetera nespresso',
        'ordenador', 'impresora', 'tpv', 'pda', 'centralita', 'scanner',
        'ipad', 'fotocopiadora', 'comandero'
       )
    OR LOWER(nombre_elemento) LIKE '%aire acondicionado%'
    OR LOWER(nombre_elemento) LIKE '%fan coil%'
    OR LOWER(nombre_elemento) LIKE '%enfriadora%'
    OR LOWER(nombre_elemento) LIKE '%caldera%'
    OR LOWER(nombre_elemento) LIKE '%ascensor%'
    OR LOWER(nombre_elemento) LIKE '%montacargas%'
    OR LOWER(nombre_elemento) LIKE '%horno%'
    OR LOWER(nombre_elemento) LIKE '%lavavajillas%'
    OR LOWER(nombre_elemento) LIKE '%lavaplatos%'
    OR LOWER(nombre_elemento) LIKE '%lavadora%'
    OR LOWER(nombre_elemento) LIKE '%abatidor%'
    OR LOWER(nombre_elemento) LIKE '%máquinas de hielo%'
    OR LOWER(nombre_elemento) LIKE '%maquinas de hielo%'
    OR LOWER(nombre_elemento) LIKE '%vinoteca%'
    OR LOWER(nombre_elemento) LIKE '%microondas%'
    OR LOWER(nombre_elemento) LIKE '%marmita%'
    OR LOWER(nombre_elemento) LIKE '%crepera%'
    OR LOWER(nombre_elemento) LIKE '%corta fiambre%'
    OR LOWER(nombre_elemento) LIKE '%proyector%'
    OR LOWER(nombre_elemento) LIKE '%altavoz%'
    OR LOWER(nombre_elemento) LIKE '%bomba%'
    OR LOWER(nombre_elemento) LIKE '%cinta de andar%'
    OR LOWER(nombre_elemento) LIKE '%máquinas gimnasio%'
    OR LOWER(nombre_elemento) LIKE '%maquinas gimnasio%';
