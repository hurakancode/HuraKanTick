-- =====================================================================
--  SSTT 5.3 — Nombre de usuario y enlace de auto-entrada
--
--  DOS CAMBIOS QUE VAN JUNTOS
--
--  1. No todo el mundo tiene correo. En un hotel hay cuentas de puesto
--     —«recepcion», «pisos»— que las usa quien esté de turno, y pedirles
--     un correo obliga a inventarse uno. A partir de aquí manda el
--     nombre de usuario: obligatorio y único. El correo pasa a ser
--     opcional, y quien lo tenga puede entrar con cualquiera de los dos.
--
--  2. El enlace de auto-entrada deja entrar sin escribir nada. Es cómodo
--     para una tablet fija en recepción y peligroso en un móvil que se
--     pierde, así que:
--       · se guarda cifrado, no en claro;
--       · se puede apagar de golpe para todo el hotel;
--       · se puede apagar persona a persona;
--       · se puede regenerar, y el enlace viejo deja de valer al instante;
--       · cada uso queda anotado en el registro de seguridad.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.5.3.sqlite.sql
-- =====================================================================

-- --- 1. El nombre de usuario ----------------------------------------

ALTER TABLE login ADD COLUMN usuario TEXT;

-- En minúsculas y sin acentos, que es contra lo que se compara al entrar.
ALTER TABLE login ADD COLUMN usuario_plano TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS uq_login_usuario ON login(usuario_plano);

-- --- 2. El enlace de auto-entrada ------------------------------------

-- Del enlace se guarda el resumen, no el enlace: si alguien se lleva la
-- base de datos, no se lleva las llaves.
ALTER TABLE login ADD COLUMN autologin_hash TEXT;

-- El identificador público que viaja en la URL. Sirve para encontrar la
-- fila sin recorrerlas todas comparando resúmenes.
ALTER TABLE login ADD COLUMN autologin_id TEXT;

ALTER TABLE login ADD COLUMN autologin_activo INTEGER NOT NULL DEFAULT 0;
ALTER TABLE login ADD COLUMN autologin_creado TEXT;
ALTER TABLE login ADD COLUMN autologin_ultimo_uso TEXT;
ALTER TABLE login ADD COLUMN autologin_usos INTEGER NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX IF NOT EXISTS uq_login_autologin ON login(autologin_id);

-- --- 2 bis. El correo deja de ser obligatorio ------------------------
--
--  SQLite no sabe quitar un NOT NULL con ALTER TABLE: hay que rehacer la
--  tabla. Eso NO se hace aquí, sino en tools/instalar.php, porque este
--  fichero se ejecuta entero en cada actualización y la reconstrucción
--  sólo debe ocurrir una vez, dentro de una transacción, copiando todas
--  las columnas que tenga la tabla y recreando sus índices.

-- --- 3. Los ajustes --------------------------------------------------

INSERT OR IGNORE INTO ajustes_sistema
  (clave, valor, tipo, categoria, titulo, descripcion, orden)
VALUES
  ('autologin_global', '1', 'interruptor', 'seguridad',
   'Permitir enlaces de auto-entrada',
   'Con esto apagado ningún enlace funciona, aunque esté activo para alguien. '
   || 'Es el interruptor general: sirve para cortar de golpe si hace falta.', 60),

  ('autologin_dias', '0', 'numero', 'seguridad',
   'Días que vale un enlace de auto-entrada',
   'Pasados esos días el enlace caduca y hay que regenerarlo. Con 0 no caduca, '
   || 'que es lo que interesa en una tablet fija de recepción.', 61),

  ('correo_obligatorio', '0', 'interruptor', 'seguridad',
   'Exigir correo a todo el mundo',
   'Normalmente no: hay cuentas de puesto que no tienen correo. Actívalo sólo '
   || 'si en tu hotel todo el mundo debe tener uno.', 62);
