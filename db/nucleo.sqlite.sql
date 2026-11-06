-- =====================================================================
--  NÚCLEO — lo imprescindible para que el sistema arranque
--
--  Sin datos de negocio: ni tipos de avería, ni plantas, ni habitaciones.
--  Una instalación limpia se queda aquí y se define todo desde la propia
--  aplicación o importando plantillas.
-- =====================================================================

INSERT OR IGNORE INTO estados (id_estado, nombre_estado, color, class, svg) VALUES
(1, 'Pendiente Asignar', '#0dcaf0', 'btn btn-info', NULL),
(2, 'Asignado', '#6f42c1', 'btn btn-primary', NULL),
(3, 'En Curso', '#fd7e14', 'btn btn-warning', NULL),
(4, 'Cerrado', '#20c997', 'btn btn-success', NULL),
(5, 'Cancelada', '#d63384', 'btn btn-danger', NULL),
(6, 'Pte. Terceros', '#adb5bd', NULL, NULL),
(7, 'Pte. Usuario', '#adb5bd', NULL, NULL),
(8, 'Programado', '#000', NULL, NULL),
(9, 'Reabierta', '#ffc107', 'btn btn-warning', NULL),
(10, 'Resuelta', '#20c997', 'btn btn-success', NULL),
(100, 'Eliminado', '#d63384', 'btn btn-danger', NULL);
