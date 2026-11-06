SET NAMES utf8mb4;
-- =====================================================================
--  PAQUETE «HOTEL» — departamentos y áreas de un hotel
--
--  Servicios Técnicos, Pisos, Recepción… y las dieciséis familias de
--  avería. Es lo que espera un hotel; otro negocio define los suyos desde
--  la aplicación o importando una hoja, y por eso esto no está en el
--  núcleo.
-- =====================================================================

INSERT IGNORE INTO departamentos (id_departamento, nombre, color, activo) VALUES
 (1,'Servicios Técnicos','#0B5563',1),
 (2,'Pisos / Limpieza',  '#7C3AED',1),
 (3,'Recepción',         '#0EA5E9',1),
 (4,'Cocina / A&B',      '#F2A007',1),
 (5,'Informática',       '#0D7C57',1),
 (6,'Jardinería',        '#15803D',1),
 (7,'Seguridad',         '#334155',1);

INSERT IGNORE INTO familias
  (id_familia, nombre, clase, icono, color, id_departamento, id_prioridad, descripcion, orden, activo) VALUES

 (1,'Fontanería',            'averia','gota',        '#0EA5E9',1,3,'Agua, grifos, sanitarios, desagües y fugas',1,1),
 (2,'Electricidad',          'averia','rayo',        '#F2A007',1,3,'Luces, enchufes, cuadros y alumbrado',2,1),
 (3,'Climatización',         'averia','viento',      '#38BDF8',1,3,'Aire, calefacción, ventilación y frío',3,1),
 (4,'Carpintería y obra',    'averia','martillo',    '#B45309',1,2,'Puertas, ventanas, paredes, suelos y pintura',4,1),
 (5,'Mobiliario',            'averia','silla',       '#7C3AED',1,2,'Camas, sillas, mesas y armarios',5,1),
 (6,'Cerrajería y accesos',  'averia','llave',       '#334155',1,4,'Cerraduras, llaves, cajas fuertes y accesos',6,1),
 (7,'Televisión y sonido',   'averia','tele',        '#6366F1',5,2,'Televisores, mandos, audio y señalización',7,1),
 (8,'Informática y redes',   'averia','wifi',        '#0D7C57',5,3,'Wi-Fi, equipos, TPV, PDA y telefonía',8,1),
 (9,'Cocina y office',       'averia','herramientas','#DC2626',4,3,'Maquinaria y menaje de cocina',9,1),
 (10,'Lencería y textil',    'averia','brillo',      '#EC4899',2,2,'Toallas, sábanas, cortinas y ropa de cama',10,1),
 (11,'Limpieza y repasos',   'tarea', 'brillo',      '#14B8A6',2,2,'Repasos, segundas limpiezas y revisiones',11,1),
 (12,'Peticiones de cliente','peticion','persona',   '#0B5563',2,3,'Cama extra, cuna, VIP, salidas tardías',12,1),
 (13,'Seguridad y emergencias','averia','aviso',     '#C7362B',7,4,'Contra incendios, detectores, alarmas y cámaras',13,1),
 (14,'Zonas comunes',        'averia','panel',       '#059669',1,2,'Piscina, spa, gimnasio, ascensores y exteriores',14,1),
 (16,'Equipamiento habitación','averia','herramientas','#8B5CF6',2,2,'Cafetera, secador, plancha, minibar y caja fuerte',15,1),
 (15,'Otros',                'averia','llave-inglesa','#64748B',1,2,'Lo que no encaja en ninguna familia',99,1);
