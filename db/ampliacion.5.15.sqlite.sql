-- =====================================================================
--  SSTT 5.15 — Observadores de un parte
--
--  A veces un parte lo lleva un departamento pero le interesa a otro: la
--  caja fuerte la arregla servicios técnicos, pero recepción necesita
--  seguirlo porque es quien da la cara con el cliente.
--
--  Hasta ahora la única forma era asignarlo a los dos, que no es verdad
--  —sólo lo hace uno— o dar el departamento adicional a esa persona, que
--  le abre TODOS los partes del otro departamento. Un observador es lo
--  justo: acceso a ESE parte y nada más.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ticket_observadores (
  id_observador   INTEGER PRIMARY KEY AUTOINCREMENT,
  id_ticket       INTEGER NOT NULL REFERENCES ticket_incidencias(id_ticket),
  -- Una de las dos: una persona concreta o un departamento entero.
  id_login        INTEGER REFERENCES login(id_login),
  id_departamento INTEGER REFERENCES departamentos(id_departamento),
  id_puso         INTEGER REFERENCES login(id_login),
  creado          TEXT
);

CREATE INDEX IF NOT EXISTS ix_obs_ticket  ON ticket_observadores(id_ticket);
CREATE INDEX IF NOT EXISTS ix_obs_persona ON ticket_observadores(id_login);
CREATE INDEX IF NOT EXISTS ix_obs_depto   ON ticket_observadores(id_departamento);
