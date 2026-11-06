-- =====================================================================
--  SSTT 5.4 — Vista smartphone
--
--  En un ordenador o una tableta, la aplicación puede verse dentro de un
--  marco con forma de teléfono, centrado en la pantalla. Sirve para que
--  quien trabaja desde el despacho vea exactamente lo que ve el técnico
--  en el móvil —y para enseñar la aplicación sin tener que prestar uno—.
--
--  Sólo lo gestiona el superadministrador, con cuatro modos:
--    no        · como siempre (por defecto)
--    todos     · todos lo ven en teléfono, sin poder cambiarlo
--    activa    · encendido para todos, cada uno puede quitárselo
--    inactiva  · apagado para todos, cada uno puede ponérselo
--
--  Es reversible por completo: en «no», la aplicación no carga nada
--  nuevo y se comporta igual que antes de existir esto.
--
--    sqlite3 db/hurakantick.sqlite < db/ampliacion.5.4.sqlite.sql
-- =====================================================================

INSERT OR IGNORE INTO ajustes_sistema
  (clave, valor, tipo, categoria, titulo, descripcion, opciones, orden)
VALUES
  ('vista_movil_modo', 'no', 'lista', 'vista',
   'Forzar vista smartphone',
   'En ordenadores y tabletas, la aplicación se ve dentro de un teléfono centrado en la pantalla. En los móviles no cambia nada.',
   '[{"v":"no","t":"No"},{"v":"todos","t":"Sí, para todos (sin opción de cambiar)"},{"v":"activa","t":"Habilitada y activada (cada uno puede quitarla)"},{"v":"inactiva","t":"Habilitada sin activar (cada uno puede ponerla)"}]', 1),

  ('vista_movil_ratio', '9:19.5', 'lista', 'vista',
   'Proporción del teléfono',
   'La forma del marco. Quien pueda activarla también puede elegir la suya.',
   '[{"v":"9:16","t":"9:16 · teléfono clásico"},{"v":"9:19.5","t":"9:19,5 · iPhone"},{"v":"9:20","t":"9:20 · Android actual"}]', 2);

-- Arreglo de la 5.3: los tipos «bool» e «int» no existen. Con ellos el
-- interruptor de los enlaces de auto-entrada no se podía tocar desde
-- ninguna pantalla. Los que son de verdad: «interruptor» y «numero».
UPDATE ajustes_sistema SET tipo = 'interruptor'
 WHERE clave IN ('autologin_global', 'correo_obligatorio') AND tipo = 'bool';
UPDATE ajustes_sistema SET tipo = 'numero'
 WHERE clave = 'autologin_dias' AND tipo = 'int';

-- Arreglo de la 5.3: su reconstrucción de la tabla de personas borraba
-- estos dos índices y no los volvía a crear. Se recuperan aquí.
CREATE INDEX IF NOT EXISTS ix_login_hotel  ON login(id_hotel);
CREATE INDEX IF NOT EXISTS ix_login_correo ON login(correo);
