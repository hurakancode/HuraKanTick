<?php
/**
 * Servicio de tickets.
 *
 * Lo usan por igual las rutas REST y el motor de sincronización, de modo que
 * un parte creado sin cobertura pasa exactamente por las mismas reglas que
 * uno creado en línea. La clave es el uuid: lo genera el móvil, así que
 * reenviar la misma operación nunca duplica nada.
 */
declare(strict_types=1);

final class Tickets
{
    /** Crea un ticket. Idempotente por uuid. */
    public static function crear(array $d, array $usuario): array
    {
        $uuid = Http::esUuid($d['uuid'] ?? null) ? $d['uuid'] : Http::uuid();

        $ya = Repo::ticketPorUuid($uuid);
        if ($ya !== null) {
            $ya['duplicado'] = true;
            return $ya;
        }

        /* Una lista o un objeto donde iba el título se quedan en vacío,
           no en la palabra «Array». */
        $tituloBruto = $d['titulo'] ?? $d['titulo_ticket'] ?? '';
        $titulo = is_scalar($tituloBruto) ? trim((string)$tituloBruto) : '';
        $detalle = trim((string)($d['detalle'] ?? $d['detalle_ticket'] ?? ''));

        $idElemento  = Http::id($d['id_elemento'] ?? null);
        $idFamilia   = Http::id($d['id_familia'] ?? null);
        /* Los valores por defecto salen de la configuración, no escritos
           a mano aquí. Antes el «2» estaba fijo: el administrador podía
           cambiar la prioridad por defecto en Configuración y no pasaba
           nada, porque sólo la aplicaba la pantalla de alta. Un parte
           creado desde la cola offline o desde fuera se saltaba el
           ajuste sin que nadie lo notara. */
        $idPrioridad = Http::id($d['id_prioridad'] ?? null)
                    ?? Http::id(Permisos::ajuste('alta_prioridad_defecto', 2))
                    ?? 2;

        // Si viene el elemento pero no la familia, se deduce. Al revés no:
        // quien avisa puede quedarse en «Fontanería» sin saber si es la
        // cisterna o el fluxor, y eso es información suficiente.
        if ($idFamilia === null && $idElemento !== null && Db::tablaExiste('familias')) {
            $idFamilia = Http::id(Db::value('SELECT id_familia FROM elemento WHERE id_elemento = ?', [$idElemento]));
        }

        /* Si no hay texto pero sí tipo de avería, se compone el título
           con él. Antes esto se rechazaba, y como la pantalla sí dejaba
           dar el parte con sólo el tipo, el parte salía del móvil y el
           servidor lo devolvía: acababa en «envíos con problemas» sin
           que nadie entendiera por qué. */
        if ($titulo === '') {
            $nombreTipo = $idElemento !== null
                ? (string)Db::value('SELECT nombre_elemento FROM elemento WHERE id_elemento = ?', [$idElemento])
                : ($idFamilia !== null
                    ? (string)Db::value('SELECT nombre FROM familias WHERE id_familia = ?', [$idFamilia])
                    : '');
            if ($nombreTipo !== '') {
                $titulo = $nombreTipo;
            } elseif ($detalle !== '') {
                $titulo = mb_substr($detalle, 0, 80);
            }
        }

        if ($titulo === '' && $detalle === '') {
            throw new ApiError('Escribe al menos un título o una descripción.', 422, ['campo' => 'titulo']);
        }


        // La prioridad por defecto la marca la familia: una avería de
        // cerrajería no espera lo mismo que un cuadro torcido.
        if (!isset($d['id_prioridad']) && $idFamilia !== null) {
            $pf = Http::id(Db::value('SELECT id_prioridad FROM familias WHERE id_familia = ?', [$idFamilia]));
            if ($pf !== null) { $idPrioridad = $pf; }
        }

        // Zona: por id_rel directo, o por los cuatro niveles.
        $idRel = Http::id($d['id_rel_zona'] ?? null);
        if ($idRel === null) {
            $idRel = Repo::resolverZona(
                Http::id($d['zona1'] ?? null),
                Http::id($d['zona2'] ?? null),
                Http::id($d['zona3'] ?? null),
                Http::id($d['zona4'] ?? null),
                (int)$usuario['id_login']
            );
        }

        // Título automático a partir de elemento + ubicación si viene vacío.
        if ($titulo === '') {
            $elemento = $idElemento
                ? (string)Db::value('SELECT nombre_elemento FROM elemento WHERE id_elemento = ?', [$idElemento])
                : ($idFamilia
                    ? (string)Db::value('SELECT nombre FROM familias WHERE id_familia = ?', [$idFamilia])
                    : 'Incidencia');
            $ruta = $idRel ? (string)Db::value('SELECT ruta FROM rel_zonas WHERE id_rel = ?', [$idRel]) : '';
            $titulo = trim($elemento . ($ruta !== '' ? ' · ' . $ruta : ''));
        }

        $estadoInicial = (int)(Db::value('SELECT id_estado FROM estados WHERE es_inicial = 1 ORDER BY orden LIMIT 1')
            ?? Db::value('SELECT MIN(id_estado) FROM estados') ?? 1);

        $fechaCreado = self::fecha($d['fecha_creado'] ?? null) ?? Db::now();
        $asignado    = Http::id($d['id_persona_asignada'] ?? null);
        /* El departamento: primero el que venga en el parte. Si no viene,
           lo decide más abajo lo que falla —la familia, o el tipo si manda
           sobre ella— y sólo si tampoco hay nada, el departamento por
           defecto de la configuración.
           Antes el valor por defecto se ponía AQUÍ, y como ya no era nulo,
           el reparto por lo que falla no llegaba a ejecutarse nunca: todos
           los partes acababan en el mismo departamento. */
        $grupo       = Http::id($d['id_grupo_asignado'] ?? null);

        /* La ubicación y el departamento tienen que ser de este hotel.
         *
         * El formulario ya sólo ofrece los suyos, pero la petición se
         * puede escribir a mano —y la cola de un móvil que cambió de
         * hotel manda lo que tenía guardado. Sin comprobarlo aquí, un
         * parte acababa apuntando a la habitación 305 del hotel de al
         * lado, o a un departamento que en esta casa no existe: nadie lo
         * ve en su listado y el parte se queda muerto. */
        $hotelActual = class_exists('Hoteles') && Db::tablaExiste('hoteles')
                     ? Hoteles::actual($usuario) : null;

        if ($hotelActual !== null && $idRel !== null) {
            $suyo = Db::value('SELECT id_hotel FROM rel_zonas WHERE id_rel = ?', [$idRel]);
            if ($suyo !== null && (int)$suyo !== $hotelActual) {
                throw new ApiError('Esa ubicación es de otro hotel.', 422, ['campo' => 'id_rel_zona']);
            }
        }

        /* Un parte SIN hotel no lo ve nadie.
         *
         * Si quien lo abre no tiene hotel puesto —pasa con una cuenta
         * recién creada, o traída de una instalación de un solo hotel—
         * el parte se guardaba con el hotel vacío. Se creaba bien, el
         * móvil decía «parte T-00484 creado», y a partir de ahí no
         * aparecía en ninguna lista de ninguna persona: el filtro por
         * hotel lo dejaba fuera siempre. Nadie recibía el aviso y nadie
         * podía buscarlo, ni por su código. En esta base había 338 así.
         *
         * Así que se busca un hotel por donde se pueda, en el orden que
         * menos se equivoca, y si no hay ninguno se dice en vez de
         * guardar algo que nadie va a volver a ver. */
        if ($hotelActual === null && Db::tablaExiste('hoteles')) {
            // 1. El de la ubicación elegida: el parte está físicamente ahí.
            if ($idRel !== null) {
                $deLaZona = Db::value('SELECT id_hotel FROM rel_zonas WHERE id_rel = ?', [$idRel]);
                if ($deLaZona !== null) { $hotelActual = (int)$deLaZona; }
            }
            // 2. Si sólo hay un hotel, no hay dónde equivocarse.
            if ($hotelActual === null) {
                $activos = Db::all('SELECT id_hotel FROM hoteles WHERE COALESCE(activo,1) = 1');
                if (count($activos) === 1) {
                    $hotelActual = (int)$activos[0]['id_hotel'];
                }
            }
            if ($hotelActual === null) {
                throw new ApiError(
                    'Tu cuenta no tiene hotel asignado, y hay varios. Sin hotel el '
                  . 'parte no lo vería nadie. Pide que te asignen uno.',
                    422, ['campo' => 'id_hotel']);
            }
        }

        if ($hotelActual !== null && $grupo !== null && Db::tablaExiste('hotel_departamento')) {
            $tiene = Db::value(
                'SELECT 1 FROM hotel_departamento WHERE id_hotel = ? AND id_departamento = ?',
                [$hotelActual, $grupo]);
            // Sin ninguno asignado el hotel los usa todos: no se estorba.
            $cuantos = (int)Db::value('SELECT COUNT(*) FROM hotel_departamento WHERE id_hotel = ?',
                                      [$hotelActual]);
            if ($cuantos > 0 && $tiene === null) {
                throw new ApiError('Ese departamento no se usa en este hotel.', 422,
                                   ['campo' => 'id_grupo_asignado']);
            }
        }

        // Reparto automático: la familia sabe qué grupo lleva ese oficio,
        // así un aviso de recepción llega solo a quien le toca.
        if ($grupo === null && $asignado === null && $idFamilia !== null
            && Permisos::ajuste('rutar_por_familia', true)) {
            $grupo = Http::id(Db::value('SELECT id_departamento FROM familias WHERE id_familia = ?', [$idFamilia]));
        }
        /* Un tipo concreto puede mandar sobre su familia: la caja fuerte
           está en «Equipamiento habitación», que es de Pisos, pero la
           arregla Servicios Técnicos. Sólo cuando difiere de su familia:
           si coincide, ya lo ha decidido la familia arriba. */
        if ($idElemento !== null) {
            /* Un tipo manda sobre su familia sólo si está MARCADO como
               excepción. Antes bastaba con que su departamento fuera
               distinto, y eso incluía a los que se habían quedado atrás al
               cambiar el departamento de la familia: el parte seguía
               yéndose al departamento viejo. */
            $hayMarca = in_array('dep_propio', Db::columnas('elemento'), true);
            $el = Db::one('SELECT id_departamento' . ($hayMarca ? ', dep_propio' : '')
                        . ' FROM elemento WHERE id_elemento = ?', [$idElemento]);
            $dEl = Http::id($el['id_departamento'] ?? null);
            $propio = $hayMarca ? ((int)($el['dep_propio'] ?? 0) === 1) : false;
            if ($dEl !== null && ($grupo === null || $propio)) {
                $grupo = $dEl;
            }
        }

        // Y si nada lo ha decidido, el departamento por defecto: un parte
        // sin departamento no aparece en la cola de nadie.
        if ($grupo === null) {
            $grupo = Http::id(Permisos::ajuste('alta_departamento_defecto', null));
        }

        /* Lo que el administrador ha marcado como obligatorio en
           «Qué hay que rellenar por fuerza». La pantalla ya lo comprueba,
           pero un parte puede llegar por la API o desde la cola de un
           móvil: si sólo lo mirase la pantalla, el ajuste se saltaría
           sin más. */
        /* Un parte sin hotel no es de nadie: no sale en ninguna lista y
           no hay quien lo reparta. Si la persona no tiene hotel y hay
           varios, se le dice en vez de crearlo a ciegas. */
        if (Db::tablaExiste('hoteles')
            && (int)(Db::value('SELECT COUNT(*) FROM hoteles') ?? 0) > 1
            && Hoteles::actual($usuario) === null) {
            throw new ApiError('Tu cuenta no tiene hotel asignado: pídeselo a un administrador.', 422);
        }

        $exigidos = Permisos::ajuste('alta_obligatorios', []);
        $exigidos = is_array($exigidos) ? $exigidos : (json_decode((string)$exigidos, true) ?: []);
        $falta = [
            'elemento'  => $idElemento === null && $idFamilia === null,
            'ubicacion' => $idRel === null && ($d['id_zona_3'] ?? null) === null,
            'detalle'   => $detalle === '',
            'asignado'  => $asignado === null && $grupo === null,
        ];
        $comoSeLlama = [
            'elemento'  => 'Indica qué falla.',
            'ubicacion' => 'Indica dónde es.',
            'detalle'   => 'Escribe el detalle.',
            'asignado'  => 'Indica a quién va el parte.',
        ];
        foreach ($exigidos as $campo) {
            if (!empty($falta[$campo])) {
                throw new ApiError($comoSeLlama[$campo] ?? 'Falta un dato obligatorio.', 422,
                                   ['campo' => $campo]);
            }
        }

        Db::begin();
        try {
            $id = Db::insert('ticket_incidencias', [
                'uuid'                => $uuid,
                'titulo_ticket'       => mb_substr($titulo, 0, 200),
                'detalle_ticket'      => $detalle !== '' ? $detalle : null,
                'fecha_creado'        => $fechaCreado,
                'fecha_limite'        => self::fecha($d['fecha_limite'] ?? null) ?? Repo::fechaLimite($idPrioridad, $fechaCreado),
                'id_estado'           => $estadoInicial,
                'id_prioridad'        => $idPrioridad,
                /* La variable es $usuario, no $u.
                 *
                 * Con $u —que no existe aquí— PHP avisa y pasa null, así
                 * que «a qué hotel va este parte» se decidía sin saber
                 * quién lo daba: se caía al hotel de la sesión, que casi
                 * siempre es el mismo y por eso no se notaba. Con varios
                 * hoteles y alguien de otra casa, el parte acabaría donde
                 * no es. */
                /* El de arriba, no Hoteles::actual() otra vez: ahí está
                   ya resuelto el respaldo para cuando la persona no
                   tiene hotel. Llamándola de nuevo aquí se perdía el
                   respaldo y el parte volvía a guardarse sin hotel. */
                'id_hotel'            => $hotelActual,
                'id_elemento'         => $idElemento,
                'id_familia'          => $idFamilia,
                'id_unidad'           => Http::id($d['id_unidad'] ?? null),
                'id_rel_zona'         => $idRel,
                'id_realiza_accion'   => (int)$usuario['id_login'],
                'id_persona_asignada' => $asignado,
                'id_grupo_asignado'   => $grupo,
                // Si se programa para más adelante, no aparece en las
                // listas hasta esa fecha.
                'visible_desde'       => self::fecha($d['visible_desde'] ?? null),
                'origen'              => in_array($d['origen'] ?? '', ['app','offline','email','web'], true) ? $d['origen'] : 'app',
                // Columna heredada de la versión 1.x: se mantiene al día
                // para que los informes antiguos sigan cuadrando.
                'fecha_creado_sin_hora' => substr($fechaCreado, 0, 10),
                'id_version'          => 1,
                'borrado'             => 0,
                'updated_at'          => Db::now(),
            ]);

            // Los elementos más usados suben en el buscador del formulario.
            if ($idElemento !== null) {
                Db::run('UPDATE elemento SET usos = usos + 1 WHERE id_elemento = ?', [$idElemento]);
            }

            Repo::anotar([
                'id_ticket' => $id,
                'tipo'      => 'alta',
                'detalle'   => $detalle !== '' ? $detalle : null,
                'id_estado' => $estadoInicial,
                'id_estado_old' => $estadoInicial,
                'id_login'  => $asignado,
                'autor'     => (int)$usuario['id_login'],
                'fecha'     => $fechaCreado,
            ]);

            // Campos propios del tipo de avería (el formulario especial
            // que antes estaba escrito a mano para el aire acondicionado).
            $anotados = self::guardarCampos($id, $d['campos'] ?? null);
            if ($anotados !== []) {
                $notas[] = 'Datos anotados · ' . implode(' · ', $anotados);
            }

            if ($asignado !== null) {
                Repo::notificar($asignado, $id, 'Parte asignado ' . Repo::codigo($id),
                                $titulo, (int)$usuario['id_login'], 'asignado');
            } elseif ($grupo !== null) {
                // Sin persona concreta: se avisa a todo el grupo, que para
                // eso se le ha asignado a ellos.
                self::avisarGrupo($grupo, $id, 'Parte nuevo para tu grupo', $titulo,
                                  (int)$usuario['id_login'], 'asignado_grupo');
            }

            // Un urgente lo tiene que ver el turno entero, esté o no asignado.
            if ($idPrioridad >= 4) {
                self::avisarUrgente($id, $titulo, (int)$usuario['id_login']);
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return Repo::ticket($id) ?? [];
    }

    /**
     * Modifica un ticket. Cada cambio deja rastro en el historial.
     * $d admite: id_estado, id_persona_asignada, id_grupo_asignado,
     * id_prioridad, titulo, detalle, fecha_limite, minutos, nota.
     */
    public static function actualizar(int $id, array $d, array $usuario, int $intento = 0): array
    {
        $t = Repo::ticket($id);
        if ($t === null || (int)$t['borrado'] === 1) {
            throw new ApiError('El parte no existe o ha sido eliminado.', 404);
        }
        if (!Permisos::puedeEditar($usuario, $t)) {
            throw new ApiError('No tienes permiso para modificar este parte.', 403);
        }

        // ------------------------------------------------------------------
        // Control de conflictos
        //
        // El móvil manda lo que creía saber del parte cuando se quedó sin
        // cobertura (version_base y el estado/asignación de entonces). Si
        // mientras tanto otra persona lo movió, no se pisa su trabajo: se
        // devuelve un conflicto y decide quien lo envió.
        //
        // Las notas y las fotos nunca entran aquí: sólo añaden, así que dos
        // personas a la vez no se estorban.
        // ------------------------------------------------------------------
        if (!Http::bool($d['forzar'] ?? false) && isset($d['version_base'])) {
            $conflicto = self::detectaConflicto($t, $d);
            if ($conflicto !== null) {
                throw new ApiError($conflicto['mensaje'], 409, ['conflicto' => $conflicto]);
            }
        }

        $cambios = [];
        $notas   = [];
        $nuevoEstado = null;
        $nuevoAsignado = null;
        $huboEdicion = false;

        if (array_key_exists('id_estado', $d) && Http::id($d['id_estado']) !== null) {
            $e = (int)$d['id_estado'];
            if ($e !== (int)$t['id_estado']) {
                $existe = Db::one('SELECT * FROM estados WHERE id_estado = ?', [$e]);
                if ($existe === null) {
                    throw new ApiError('El estado indicado no existe.', 422, ['campo' => 'id_estado']);
                }
                // Un estado puede estar limitado a cierta clase de parte:
                // «Pte. Terceros» tiene sentido en una avería, no al montar
                // una cama extra.
                if (!empty($existe['clases']) && !empty($t['clase'])) {
                    $permitidas = array_map('trim', explode(',', (string)$existe['clases']));
                    if ($permitidas && !in_array((string)$t['clase'], $permitidas, true)) {
                        throw new ApiError(
                            'El estado «' . $existe['nombre_estado'] . '» no se usa en este tipo de parte.',
                            422, ['campo' => 'id_estado']);
                    }
                }
                $cambios['id_estado'] = $e;
                $nuevoEstado = $e;
                $cambios['fecha_cierre'] = (int)$existe['es_final'] === 1 ? Db::now() : null;
                $notas[] = 'Estado: ' . $t['nombre_estado'] . ' → ' . $existe['nombre_estado'];
            }
        }

        if (array_key_exists('id_persona_asignada', $d)) {
            $a = Http::id($d['id_persona_asignada']);
            if ($a !== (int)($t['id_persona_asignada'] ?? 0) && !($a === null && $t['id_persona_asignada'] === null)) {
                /* Pasarle el parte a otra persona depende de quién sea esa
                   otra: un compañero, un responsable o alguien de otro
                   departamento son tres decisiones distintas, y cada hotel
                   abre las que quiera. */
                if (!Permisos::puedeAsignarA($usuario, $a, $t)) {
                    $quien = (string)Db::value(
                        'SELECT ' . Db::nombreCompleto('l') . ' FROM login l WHERE id_login = ?', [$a]);
                    throw new ApiError(
                        'No puedes pasarle partes a ' . ($quien ?: 'esa persona')
                        . '. Tu responsable puede abrirlo en Configuración → Comportamiento, '
                        . 'o dártelo a ti en tu ficha.', 403);
                }
                $cambios['id_persona_asignada'] = $a;
                $nuevoAsignado = $a;
                $notas[] = 'Asignado a ' . ($a === null ? 'nadie'
                    : (string)Db::value('SELECT ' . Db::nombreCompleto('l') . ' FROM login l WHERE id_login = ?', [$a]));
            }
        }

        foreach ([
            'id_grupo_asignado' => 'id_grupo_asignado',

            'id_prioridad'      => 'id_prioridad',
            'id_elemento'       => 'id_elemento',
            'id_familia'        => 'id_familia',
            'id_unidad'         => 'id_unidad',
            'id_rel_zona'       => 'id_rel_zona',
        ] as $entrada => $col) {
            if (array_key_exists($entrada, $d)) {
                $v = Http::id($d[$entrada]);
                if ($v !== ($t[$col] === null ? null : (int)$t[$col])) {
                    $cambios[$col] = $v;
                    $notas[] = self::describeCambio($col, $t, $v);
                }
            }
        }

        if (isset($cambios['id_elemento']) && !array_key_exists('id_familia', $d)) {
            $cambios['id_familia'] = Http::id(
                Db::value('SELECT id_familia FROM elemento WHERE id_elemento = ?', [$cambios['id_elemento']]));
        }
        /* Plazo y tiempo dedicado.
           Se tratan aparte de los ids porque no lo son: uno es fecha y
           otro un número de minutos. Un administrador los corrige a
           mano cuando el automático se queda corto. */
        /* Programar el parte para más adelante: hasta esa fecha no sale
           en las listas de quien lo tiene que atender. */
        if (array_key_exists('visible_desde', $d)) {
            $nueva = self::fecha($d['visible_desde'] ?? null);
            $antes = $t['visible_desde'] ?? null;

            /* Sólo se apunta si de verdad cambia: abrir el panel y
               aceptar sin tocar nada no tiene que ensuciar el
               seguimiento con una línea que no dice nada. */
            if ($nueva !== $antes) {
                $cambios['visible_desde'] = $nueva;
                /* Con la hora: «el día 30» y «el día 30 a las 18:00» no
                   son lo mismo para quien espera el parte. */
                $notas[] = $nueva === null
                    ? 'Vuelve a estar visible'
                    : ($antes === null ? 'Programado para el ' : 'Fecha cambiada al ')
                      . self::enHoraLocal($nueva);
            }
        }

        if (array_key_exists('fecha_limite', $d)) {
            $lim = $d['fecha_limite'];
            $lim = ($lim === null || $lim === '') ? null : substr((string)$lim, 0, 19);
            if ($lim !== ($t['fecha_limite'] ?? null)) {
                $cambios['fecha_limite'] = $lim;
                $notas[] = $lim === null ? 'Plazo quitado' : 'Plazo cambiado';
            }
        }
        if (array_key_exists('coste', $d)) {
            $cos = ($d['coste'] === null || $d['coste'] === '') ? null : round((float)$d['coste'], 2);
            if ($cos !== ($t['coste'] === null ? null : (float)$t['coste'])) {
                $cambios['coste'] = $cos;
                $notas[] = $cos === null ? 'Coste quitado' : 'Coste: ' . number_format($cos, 2, ',', '.') . ' €';
            }
        }
        if (array_key_exists('minutos_trabajo', $d)) {
            $min = max(0, min(100000, (int)$d['minutos_trabajo']));
            if ($min !== (int)($t['minutos_trabajo'] ?? 0)) {
                $cambios['minutos_trabajo'] = $min;
                $notas[] = 'Tiempo dedicado: ' . $min . ' min';
            }
        }

        if (isset($cambios['id_prioridad']) && !array_key_exists('fecha_limite', $d)) {
            $cambios['fecha_limite'] = Repo::fechaLimite((int)$cambios['id_prioridad'], (string)$t['fecha_creado']);
        }
        if (array_key_exists('fecha_limite', $d)) {
            $cambios['fecha_limite'] = self::fecha($d['fecha_limite']);
        }
        if (array_key_exists('titulo', $d) && trim((string)$d['titulo']) !== '') {
            $nuevoTitulo = mb_substr(trim((string)$d['titulo']), 0, 200);
            if ($nuevoTitulo !== (string)$t['titulo_ticket']) {
                $cambios['titulo_ticket'] = $nuevoTitulo;
                $notas[] = 'Resumen corregido';
                $huboEdicion = true;
            }
        }
        if (array_key_exists('detalle', $d)) {
            $nuevoDetalle = trim((string)$d['detalle']) ?: null;
            if ($nuevoDetalle !== ($t['detalle_ticket'] ?: null)) {
                $cambios['detalle_ticket'] = $nuevoDetalle;
                $notas[] = 'Detalle corregido';
                $huboEdicion = true;
            }
        }
        if (array_key_exists('minutos', $d) && (int)$d['minutos'] > 0) {
            $cambios['minutos_trabajo'] = (int)$t['minutos_trabajo'] + (int)$d['minutos'];
        }
        if (array_key_exists('coste', $d)) {
            $cambios['coste'] = round((float)$d['coste'], 2);
        }

        /* Al editar valen las mismas reglas que al crear: no se puede
           mover un parte a una ubicación o un departamento de otro
           hotel. */
        if (Db::tablaExiste('hoteles') && $t['id_hotel'] !== null) {
            $hotelT = (int)$t['id_hotel'];

            if (isset($cambios['id_rel_zona'])) {
                $suyo = Db::value('SELECT id_hotel FROM rel_zonas WHERE id_rel = ?',
                                  [$cambios['id_rel_zona']]);
                if ($suyo !== null && (int)$suyo !== $hotelT) {
                    throw new ApiError('Esa ubicación es de otro hotel.', 422,
                                       ['campo' => 'id_rel_zona']);
                }
            }
            if (isset($cambios['id_grupo_asignado']) && Db::tablaExiste('hotel_departamento')) {
                $cuantos = (int)Db::value(
                    'SELECT COUNT(*) FROM hotel_departamento WHERE id_hotel = ?', [$hotelT]);
                $tiene = Db::value(
                    'SELECT 1 FROM hotel_departamento WHERE id_hotel = ? AND id_departamento = ?',
                    [$hotelT, $cambios['id_grupo_asignado']]);
                if ($cuantos > 0 && $tiene === null) {
                    throw new ApiError('Ese departamento no se usa en este hotel.', 422,
                                       ['campo' => 'id_grupo_asignado']);
                }
            }
        }

        /* Al asignar a alguien, el parte deja de estar «Pendiente
         * Asignar».
         *
         * Si no, quien pulsa «me lo quedo» ve que sigue en la lista de
         * pendientes de asignar —el parte ya es suyo, pero el inicio dice
         * lo contrario— y acaba pensando que el botón no ha funcionado.
         *
         * Sólo cuando estaba en el estado inicial y nadie ha pedido otro
         * estado a la vez: si alguien asigna y cierra en el mismo gesto,
         * manda lo que ha pedido. */
        /* Y al revés: si se deja sin asignar y el parte estaba
         * justamente en «Asignado», vuelve a «Pendiente Asignar».
         *
         * Si no, queda un parte sin dueño que dice «Asignado», que es
         * mentira, y no aparece en la cola de lo que hay que repartir:
         * se queda en tierra de nadie hasta que alguien lo encuentra.
         *
         * Sólo desde ese estado: si ya estaba «En Curso» o resuelto,
         * quitar a la persona no deshace el trabajo hecho. */
        if (array_key_exists('id_persona_asignada', $cambios)
            && $cambios['id_persona_asignada'] === null
            && !array_key_exists('id_estado', $cambios)) {

            $estadoAsignado = Http::id(Db::value(
                'SELECT id_estado FROM estados
                  WHERE activo = 1 AND es_inicial = 0 AND es_final = 0
                  ORDER BY orden LIMIT 1'));

            if ($estadoAsignado !== null && (int)($t['id_estado'] ?? 0) === $estadoAsignado) {
                $inicial = Http::id(Db::value(
                    'SELECT id_estado FROM estados WHERE es_inicial = 1 ORDER BY orden LIMIT 1'));
                if ($inicial !== null) {
                    $cambios['id_estado'] = $inicial;
                }
            }
        }

        if (array_key_exists('id_persona_asignada', $cambios)
            && $cambios['id_persona_asignada'] !== null
            && !array_key_exists('id_estado', $cambios)) {

            $eraInicial = (int)Db::value(
                'SELECT COALESCE(es_inicial, 0) FROM estados WHERE id_estado = ?',
                [(int)($t['id_estado'] ?? 0)]) === 1;

            if ($eraInicial) {
                $siguiente = Http::id(Db::value(
                    'SELECT id_estado FROM estados
                      WHERE activo = 1 AND es_inicial = 0 AND es_final = 0
                      ORDER BY orden LIMIT 1'));
                if ($siguiente !== null) {
                    $cambios['id_estado'] = $siguiente;
                }
            }
        }

        /* Si alguien se pone con un parte aparcado, deja de estarlo.
         *
         * Quedárselo o ponerlo en marcha antes de su fecha es una
         * decisión consciente —el cliente se ha ido antes, la pieza llegó
         * pronto—: lo que no puede quedar es un parte que alguien está
         * trabajando y sigue diciendo que está aparcado. */
        if (!empty($t['visible_desde'])
            && !array_key_exists('visible_desde', $cambios)
            && in_array('visible_desde', Db::columnas('ticket_incidencias'), true)
            && (!empty($cambios['id_persona_asignada'])
                || array_key_exists('id_estado', $cambios))) {

            $sigueAparcado = false;
            if (array_key_exists('id_estado', $cambios)) {
                $programado = Http::id(Db::value(
                    "SELECT id_estado FROM estados WHERE LOWER(nombre_estado) LIKE 'programad%' LIMIT 1"));
                $sigueAparcado = $programado !== null && (int)$cambios['id_estado'] === $programado;
            }

            if (!$sigueAparcado) {
                $cambios['visible_desde'] = null;
                $notas[] = 'Se adelanta: deja de estar programado';

                /* Y sale del estado «Programado»: un parte que alguien se
                   acaba de quedar no puede seguir diciendo que está
                   esperando a una fecha que ya no existe. */
                if (!array_key_exists('id_estado', $cambios)) {
                    $programado = Http::id(Db::value(
                        "SELECT id_estado FROM estados WHERE LOWER(nombre_estado) LIKE 'programad%' LIMIT 1"));
                    if ($programado !== null && (int)($t['id_estado'] ?? 0) === $programado) {
                        $siguiente = Http::id(Db::value(
                            'SELECT id_estado FROM estados
                              WHERE activo = 1 AND es_inicial = 0 AND es_final = 0
                              ORDER BY orden LIMIT 1'));
                        if ($siguiente !== null) { $cambios['id_estado'] = $siguiente; }
                    }
                }
            }
        }

        /* Sacar el parte de «Programado» le quita la fecha de aparcado.
         *
         * Si no, quedaba un parte «En Curso» que seguía contando como
         * programado: salía en el aviso del inicio y en la lista de lo
         * que viene, cuando ya se está trabajando en él. La fecha sólo
         * tiene sentido mientras está aparcado. */
        if (array_key_exists('id_estado', $cambios)
            && !array_key_exists('visible_desde', $cambios)
            && in_array('visible_desde', Db::columnas('ticket_incidencias'), true)
            && !empty($t['visible_desde'])) {

            $programado = Http::id(Db::value(
                "SELECT id_estado FROM estados WHERE LOWER(nombre_estado) LIKE 'programad%' LIMIT 1"));

            if ($programado === null || (int)$cambios['id_estado'] !== $programado) {
                $cambios['visible_desde'] = null;
                $notas[] = 'Deja de estar aparcado';
            }
        }

        /* El parte que ya lleva otra persona.
         *
         * Que un técnico pueda quitarle de las manos un parte a un
         * compañero no es trabajo en equipo, es desorden: el otro se
         * queda sin saber que ya no es suyo. Reasignar es repartir, y
         * repartir lo hacen los responsables.
         *
         * Se puede apagar en Configuración para los equipos que se
         * cubren entre ellos y prefieren cogerse los partes libremente. */
        if (array_key_exists('id_persona_asignada', $cambios)
            && Permisos::ajuste('respetar_asignado', true)
            && !Auth::esResponsable($usuario)) {

            $teniaDueno = Http::id($t['id_persona_asignada'] ?? null);
            if ($teniaDueno !== null && $teniaDueno !== (int)$usuario['id_login']) {
                $quien = (string)Db::value(
                    'SELECT ' . Db::nombreCompleto('login') . ' FROM login WHERE id_login = ?',
                    [$teniaDueno]);
                throw new ApiError(
                    'Este parte lo lleva ' . ($quien ?: 'otra persona')
                    . '. Habla con tu responsable si tiene que cambiar de manos.', 403);
            }
        }

        /* Reescribir un parte cerrado.
         *
         * Un parte cerrado es un registro de lo que pasó. Corregirlo sin
         * más deja el historial diciendo una cosa y el texto otra; si hay
         * que arreglarlo, se reabre y queda constancia. */
        if (!Permisos::ajuste('editar_cerrado', false)
            && !Auth::esResponsable($usuario)
            && !array_key_exists('id_estado', $cambios)) {

            $esFinal = (int)Db::value(
                'SELECT COALESCE(es_final, 0) FROM estados WHERE id_estado = ?',
                [(int)($t['id_estado'] ?? 0)]) === 1;

            $tocaTexto = array_key_exists('titulo_ticket', $cambios)
                      || array_key_exists('detalle_ticket', $cambios);

            if ($esFinal && $tocaTexto) {
                throw new ApiError(
                    'Este parte está cerrado. Para cambiar su texto hay que reabrirlo, '
                    . 'y así queda constancia de la corrección.', 403);
            }
        }

        /* Quien sólo avisa, avisa: no resuelve.
         *
         * Hasta aquí podía cerrar su propio parte o mandarlo a otro
         * departamento, simplemente por haberlo abierto. Y eso cuadra
         * mal: recepción da el aviso de que la caja fuerte no va, pero
         * quien dice si está arreglada es quien la arregla.
         *
         * Corregir el texto de su parte y añadir notas sí: eso es suyo. */
        if (($usuario['rol'] ?? '') === 'reportador') {
            foreach (['id_estado' => 'el estado',
                      'id_grupo_asignado' => 'el departamento'] as $campo => $comoSeLlama) {
                if (array_key_exists($campo, $cambios)) {
                    throw new ApiError(
                        'Con el papel «sólo avisar» no se puede cambiar ' . $comoSeLlama
                        . ' de un parte. Puedes corregir el texto y añadir notas.', 403);
                }
            }
        }

        /* Coherencia al cambiar de departamento.
         *
         * Si el parte pasa a otro departamento y quien lo tenía asignado
         * no pertenece a él, la asignación deja de tener sentido: esa
         * persona no va a hacer ese trabajo, y encima el parte aparecía
         * en su lista mientras su departamento ya no lo veía.
         *
         * Se suelta la asignación y queda apuntado, que es lo honesto:
         * el parte pasa a «sin asignar» en el departamento nuevo, que es
         * exactamente donde tiene que estar para que alguien lo coja. */
        if (isset($cambios['id_grupo_asignado'])) {
            $nuevoGrupo = Http::id($cambios['id_grupo_asignado']);
            $asignado   = Http::id($cambios['id_persona_asignada'] ?? $t['id_persona_asignada'] ?? null);

            if ($nuevoGrupo !== null && $asignado !== null
                && !array_key_exists('id_persona_asignada', $cambios)) {

                if (!Permisos::perteneceA($asignado, $nuevoGrupo)) {
                    $cambios['id_persona_asignada'] = null;
                    $quien = (string)Db::value(
                        'SELECT ' . Db::nombreCompleto('login') . ' FROM login WHERE id_login = ?',
                        [$asignado]);
                    $aviso = 'Queda sin asignar: ' . ($quien ?: 'quien lo tenía')
                           . ' no es de ese departamento.';
                    $nota = trim((string)($d['nota'] ?? ''));
                    $d['nota'] = $nota === '' ? $aviso : $nota . ' · ' . $aviso;
                }
            }
        }

        $nota = trim((string)($d['nota'] ?? ''));

        /* Salida temprana cuando de verdad no hay nada que hacer.
         *
         * Los campos propios se comprueban aparte: llegan en «campos» y
         * no se mezclan con el resto, así que un envío que sólo los
         * traiga daba «ok» y no guardaba nada. El parte parecía
         * actualizado y la lectura del contador se perdía. */
        $hayCampos = !empty($d['campos']) && is_array($d['campos']);

        if ($cambios === [] && $nota === '' && !$hayCampos) {
            return $t;   // nada que hacer
        }

        if ($cambios === [] && $nota === '' && $hayCampos) {
            /* Sólo se han anotado datos del tipo de avería: temperaturas,
             * presiones, lo que se mide al revisar.
             *
             * Antes esto se guardaba «sin apuntar un movimiento que no ha
             * existido», y era justo al revés: medir y anotar ES el
             * trabajo. Sin esa línea, dentro de un mes nadie sabe quién
             * tomó la temperatura ni cómo estaba antes, que es la razón
             * de anotarla. */
            $anotados = self::guardarCampos($id, $d['campos']);

            if ($anotados !== []) {
                Repo::anotar([
                    'id_ticket' => $id,
                    'tipo'      => 'nota',
                    'detalle'   => 'Datos anotados · ' . implode(' · ', $anotados),
                    'autor'     => (int)$usuario['id_login'],
                ]);
                Db::run('UPDATE ticket_incidencias SET updated_at = ? WHERE id_ticket = ?',
                        [Db::now(), $id]);
            }
            return Repo::ticket($id) ?? $t;
        }

        Db::begin();
        try {
            if ($cambios !== []) {
                // id_version se incrementa en cada cambio para detectar la
                // doble asignación simultánea. Y se incrementa SÓLO si
                // sigue siendo la que se leyó arriba: la comprobación de
                // conflictos se hace antes de la transacción, y dos
                // «me lo quedo» llegados a la vez la pasaban los dos (los
                // dos técnicos recibían un sí). Si otra petición ha
                // escrito entre medias, se deshace y se vuelve a empezar
                // con el parte tal como ha quedado: entonces el conflicto
                // sí se ve.
                $leida = (int)($t['id_version'] ?? 1);
                $movida = Db::run(
                    'UPDATE ticket_incidencias SET id_version = COALESCE(id_version, 1) + 1
                      WHERE id_ticket = ? AND COALESCE(id_version, 1) = ?',
                    [$id, $leida]
                )->rowCount();
                if ($movida === 0) {
                    Db::rollback();
                    if ($intento >= 3) {
                        // 503 y no 409: un 409 sin el detalle del conflicto
                        // la cola del móvil lo da por resuelto y lo tira; un
                        // 5xx lo vuelve a mandar en la siguiente pasada.
                        throw new ApiError('Otra persona está cambiando este parte ahora mismo. '
                                         . 'Vuelve a intentarlo en un momento.', 503);
                    }
                    return self::actualizar($id, $d, $usuario, $intento + 1);
                }
                $cambios['updated_at'] = Db::now();
                Db::update('ticket_incidencias', $cambios, 'id_ticket = :id', ['id' => $id]);
            }

            $tipo = $nuevoEstado !== null ? 'estado'
                  : ($nuevoAsignado !== null ? 'asignacion'
                  : (($huboEdicion || isset($cambios['id_rel_zona']) || isset($cambios['id_elemento']))
                        ? 'edicion' : 'nota'));
            $detalleMov = trim($nota . ($notas ? ($nota !== '' ? "\n" : '') . implode(' · ', $notas) : ''));

            Repo::anotar([
                'uuid'          => Http::esUuid($d['uuid_movimiento'] ?? null) ? $d['uuid_movimiento'] : null,
                'id_ticket'     => $id,
                'tipo'          => $tipo,
                'detalle'       => $detalleMov !== '' ? $detalleMov : null,
                'id_estado'     => $nuevoEstado ?? (int)$t['id_estado'],
                'id_estado_old' => (int)$t['id_estado'],
                'id_login'      => array_key_exists('id_persona_asignada', $cambios)
                                    ? $cambios['id_persona_asignada'] : $t['id_persona_asignada'],
                'id_login_old'  => $t['id_persona_asignada'],
                'autor'         => (int)$usuario['id_login'],
                'minutos'       => (int)($d['minutos'] ?? 0),
                'fecha'         => self::fecha($d['fecha'] ?? null) ?? Db::now(),
            ]);

            self::guardarCampos($id, $d['campos'] ?? null);

            // Avisos a las personas implicadas
            $titulo = (string)$t['titulo_ticket'];
            if ($nuevoAsignado !== null) {
                Repo::notificar($nuevoAsignado, $id, 'Parte asignado ' . Repo::codigo($id),
                                $titulo, (int)$usuario['id_login'], 'asignado');
            }
            if (array_key_exists('id_grupo_asignado', $cambios) && $cambios['id_grupo_asignado']) {
                self::avisarGrupo((int)$cambios['id_grupo_asignado'], $id,
                                  'Parte para tu grupo', $titulo, (int)$usuario['id_login'], 'asignado_grupo');
            }
            Repo::notificar(
                $t['id_realiza_accion'] === null ? null : (int)$t['id_realiza_accion'],
                $id, 'Actualización en ' . Repo::codigo($id),
                $detalleMov !== '' ? mb_substr($detalleMov, 0, 200) : $titulo,
                (int)$usuario['id_login'],
                $nuevoEstado !== null ? 'cambio_estado' : 'nota'
            );
            if ($t['id_persona_asignada'] !== null) {
                Repo::notificar((int)$t['id_persona_asignada'], $id, 'Actualización en ' . Repo::codigo($id),
                    $detalleMov !== '' ? mb_substr($detalleMov, 0, 200) : $titulo,
                    (int)$usuario['id_login'],
                    $nuevoEstado !== null ? 'cambio_estado' : 'nota');
            }

            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return Repo::ticket($id) ?? [];
    }

    /** Añade sólo un movimiento (nota, parte de trabajo). */
    public static function movimiento(int $id, array $d, array $usuario): array
    {
        $d['nota'] = $d['nota'] ?? $d['detalle'] ?? '';
        if (trim((string)$d['nota']) === '' && !isset($d['id_estado']) && !array_key_exists('id_persona_asignada', $d)) {
            throw new ApiError('Escribe una nota o indica un cambio.', 422, ['campo' => 'nota']);
        }
        return self::actualizar($id, $d, $usuario);
    }

    public static function eliminar(int $id, array $usuario): void
    {
        $t = Repo::ticket($id);
        if ($t === null) {
            throw new ApiError('El parte no existe.', 404);
        }
        if (!Auth::esAdmin($usuario)) {
            throw new ApiError('Sólo un administrador puede eliminar partes.', 403);
        }
        /* Y que sea de su hotel. Ser administrador da poder DENTRO del
           hotel de uno: sin esta comprobación, la administradora de un
           hotel podía borrar los partes de otro escribiendo su número,
           aunque no pudiera ni leerlos. */
        if (Db::tablaExiste('hoteles') && ($t['id_hotel'] ?? null) !== null
            && !Hoteles::puede((int)$t['id_hotel'], $usuario)) {
            throw new ApiError('El parte no existe.', 404);
        }

        /* Y que sea de su hotel. Ser administrador da poder DENTRO del
           hotel de uno: sin esta comprobación, la administradora de un
           hotel podía borrar los partes de otro escribiendo su número,
           aunque no pudiera ni leerlos. */
        if (Db::tablaExiste('hoteles') && ($t['id_hotel'] ?? null) !== null
            && !Hoteles::puede((int)$t['id_hotel'], $usuario)) {
            throw new ApiError('El parte no existe.', 404);
        }

        Db::update('ticket_incidencias', ['borrado' => 1, 'updated_at' => Db::now()], 'id_ticket = :id', ['id' => $id]);

        // Que conste quién lo ocultó y cuándo: la guía promete que todo
        // queda en el historial, y ocultar no dejaba nada.
        Repo::anotar([
            'id_ticket' => $id, 'tipo' => 'nota',
            'detalle'   => 'Parte ocultado de las listas',
            'id_estado' => (int)$t['id_estado'], 'id_estado_old' => (int)$t['id_estado'],
            'autor'     => (int)$usuario['id_login'],
        ]);
    }

    /**
     * Borrado definitivo: sale de la base viva y entra en la papelera.
     *
     * El orden importa. Primero se copia, después se borra, y las dos
     * cosas dentro de la misma transacción: si la copia falla, el
     * borrado no llega a ocurrir. Borrar sin copia sería perderlo.
     */
    public static function eliminarDefinitivo(int $id, array $usuario, string $motivo = 'borrado'): void
    {
        $tH = Repo::ticket($id);
        if ($tH !== null && Db::tablaExiste('hoteles') && ($tH['id_hotel'] ?? null) !== null
            && !Hoteles::puede((int)$tH['id_hotel'], $usuario)) {
            // Igual que arriba: administrar es dentro del hotel de uno.
            throw new ApiError('El parte no existe.', 404);
        }
        $t = Db::one('SELECT * FROM ticket_incidencias WHERE id_ticket = ?', [$id]);
        if ($t === null) {
            throw new ApiError('El parte no existe.', 404);
        }
        if (!Auth::esAdmin($usuario)) {
            throw new ApiError('Sólo un administrador puede eliminar partes definitivamente.', 403);
        }
        if (Db::tablaExiste('hoteles') && $t['id_hotel'] !== null) {
            Hoteles::exigir((int)$t['id_hotel'], $usuario);
        }

        Db::begin();
        try {
            Archivo::guardarTicket($id, $motivo);
            // Para que los móviles lo quiten: si no, lo conservaban para
            // siempre, porque un parte que no existe no sale en ningún cambio.
            if (Db::tablaExiste('sync_bajas')) {
                Db::run('DELETE FROM sync_bajas WHERE id_ticket = ?', [$id]);
                Db::insert('sync_bajas', ['id_ticket' => $id, 'fecha' => Db::now()]);
            }

            self::borrarDependencias($id);
            Db::run('DELETE FROM ticket_incidencias WHERE id_ticket = ?', [$id]);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw new ApiError('No se ha podido eliminar: ' . $e->getMessage(), 500);
        }

        Registro::anotar('borrado', (int)$usuario['id_login'],
            'Parte eliminado: ' . ($t['codigo'] ?? $id) . ' (queda en la papelera)');
    }

    /**
     * Devuelve la descripción del conflicto, o null si el cambio se puede
     * aplicar sin pisar a nadie.
     */
    private static function detectaConflicto(array $t, array $d): ?array
    {
        $versionBase = (int)$d['version_base'];
        $versionReal = (int)($t['id_version'] ?? 1);

        // Nadie ha tocado el parte desde entonces: adelante.
        if ($versionBase >= $versionReal) {
            return null;
        }

        // Quién lo tocó de verdad: el autor del último movimiento, no quien
        // lo abrió ni quien lo tiene asignado.
        $quien = (string)(Db::value(
            'SELECT ' . Db::nombreCompleto('l') . '
               FROM ticket_incidencias_historial h
               JOIN login l ON l.id_login = h.id_realiza_accion
              WHERE h.id_ticket = ?
              ORDER BY h.id_historial DESC LIMIT 1',
            [(int)$t['id_ticket']]
        ) ?: ($t['asignado_a'] ?: $t['creado_por'] ?: 'otra persona'));

        // Cambio de estado sobre un estado que ya no es el que el móvil creía
        if (isset($d['id_estado']) && isset($d['estado_base'])) {
            $base   = (int)$d['estado_base'];
            $actual = (int)$t['id_estado'];
            $quiere = (int)$d['id_estado'];
            if ($actual !== $base && $actual !== $quiere) {
                return [
                    'tipo'          => 'estado',
                    'campo'         => 'Estado',
                    'valor_servidor'=> (string)$t['nombre_estado'],
                    'valor_local'   => (string)(Db::value('SELECT nombre_estado FROM estados WHERE id_estado = ?', [$quiere]) ?? '—'),
                    'valor_base'    => (string)(Db::value('SELECT nombre_estado FROM estados WHERE id_estado = ?', [$base]) ?? '—'),
                    'quien'         => $quien,
                    'cuando'        => (string)$t['updated_at'],
                    'mensaje'       => 'Mientras estabas sin conexión, este parte pasó a «'
                                     . $t['nombre_estado'] . '». Revisa el cambio antes de aplicarlo.',
                ];
            }
        }

        // Reasignación sobre alguien distinto del que el móvil creía
        if (array_key_exists('id_persona_asignada', $d) && array_key_exists('asignado_base', $d)) {
            $base   = Http::id($d['asignado_base']);
            $actual = $t['id_persona_asignada'] === null ? null : (int)$t['id_persona_asignada'];
            $quiere = Http::id($d['id_persona_asignada']);
            if ($actual !== $base && $actual !== $quiere) {
                return [
                    'tipo'          => 'asignacion',
                    'campo'         => 'Asignación',
                    'valor_servidor'=> (string)($t['asignado_a'] ?: 'sin asignar'),
                    'valor_local'   => $quiere
                        ? (string)Db::value('SELECT ' . Db::nombreCompleto('l') . ' FROM login l WHERE id_login = ?', [$quiere])
                        : 'sin asignar',
                    'valor_base'    => $base
                        ? (string)Db::value('SELECT ' . Db::nombreCompleto('l') . ' FROM login l WHERE id_login = ?', [$base])
                        : 'sin asignar',
                    'quien'         => $quien,
                    'cuando'        => (string)$t['updated_at'],
                    'mensaje'       => 'Mientras estabas sin conexión, este parte se asignó a '
                                     . ($t['asignado_a'] ?: 'nadie') . '. Revisa el cambio antes de aplicarlo.',
                ];
            }
        }

        // Reabrir algo que otro dio por cerrado merece una confirmación.
        if (isset($d['id_estado']) && (int)($t['es_final'] ?? 0) === 1) {
            $esFinalDestino = (int)(Db::value('SELECT es_final FROM estados WHERE id_estado = ?', [(int)$d['id_estado']]) ?? 0);
            if ($esFinalDestino === 0) {
                return [
                    'tipo'          => 'cerrado',
                    'campo'         => 'Estado',
                    'valor_servidor'=> (string)$t['nombre_estado'],
                    'valor_local'   => (string)(Db::value('SELECT nombre_estado FROM estados WHERE id_estado = ?', [(int)$d['id_estado']]) ?? '—'),
                    'valor_base'    => (string)$t['nombre_estado'],
                    'quien'         => $quien,
                    'cuando'        => (string)$t['updated_at'],
                    'mensaje'       => 'Este parte ya se había cerrado como «' . $t['nombre_estado']
                                     . '». Confirma si quieres reabrirlo.',
                ];
            }
        }

        return null;
    }

    /** Describe un cambio de catálogo en lenguaje llano para el historial. */
    private static function describeCambio(string $col, array $t, ?int $nuevo): string
    {
        switch ($col) {
            case 'id_prioridad':
                $n = $nuevo ? Db::value('SELECT nombre FROM prioridades WHERE id_prioridad = ?', [$nuevo]) : null;
                return 'Prioridad: ' . ($t['prioridad'] ?? '—') . ' → ' . ($n ?? '—');

            case 'id_elemento':
                $n = $nuevo ? Db::value('SELECT nombre_elemento FROM elemento WHERE id_elemento = ?', [$nuevo]) : null;
                return 'Tipo: ' . ($t['elemento'] ?? '—') . ' → ' . ($n ?? '—');

            case 'id_familia':
                $n = $nuevo ? Db::value('SELECT nombre FROM familias WHERE id_familia = ?', [$nuevo]) : null;
                return 'Familia: ' . ($t['familia'] ?? '—') . ' → ' . ($n ?? '—');

            case 'id_rel_zona':
                $n = $nuevo ? Db::value('SELECT ruta FROM rel_zonas WHERE id_rel = ?', [$nuevo]) : null;
                return 'Ubicación: ' . ($t['zona_ruta'] ?: '—') . ' → ' . ($n ?: '—');

            case 'id_grupo_asignado':
                $n = $nuevo ? Db::value('SELECT nombre FROM departamentos WHERE id_departamento = ?', [$nuevo]) : null;
                return 'Departamento: ' . ($t['departamento'] ?? '—') . ' → ' . ($n ?? '—');
        }
        return 'Datos actualizados';
    }

    /**
     * A quién se avisa, dentro del hotel del parte.
     *
     * Los departamentos son comunes a la cadena: «Servicios Técnicos»
     * existe en todos los hoteles y tiene gente distinta en cada uno.
     * Sin filtrar por hotel, un parte urgente del hotel de Málaga
     * despertaba a los técnicos del de Benalmádena a las tres de la
     * mañana, por una avería que no pueden ni ver.
     */
    private static function hotelDe(int $idTicket): ?int
    {
        if (!Db::tablaExiste('hoteles')) {
            return null;
        }
        $h = Db::value('SELECT id_hotel FROM ticket_incidencias WHERE id_ticket = ?', [$idTicket]);
        return $h === null ? null : (int)$h;
    }

    /** Avisa a las personas de un grupo, sólo las del hotel del parte. */
    private static function avisarGrupo(int $idDepartamento, int $idTicket, string $titulo, string $cuerpo, int $excepto, string $evento): void
    {
        $hotel = self::hotelDe($idTicket);

        $sql = 'SELECT id_login FROM login WHERE id_departamento = ? AND bloqueo = 0';
        $par = [$idDepartamento];
        if ($hotel !== null) {
            $sql .= ' AND (id_hotel = ? OR id_hotel IS NULL)';
            $par[] = $hotel;
        }

        foreach (Db::all($sql, $par) as $g) {
            Repo::notificar((int)$g['id_login'], $idTicket, $titulo, $cuerpo, $excepto, $evento);
        }
    }

    /** Un parte urgente se avisa a quien lo tenga activado, en su hotel. */
    private static function avisarUrgente(int $idTicket, string $titulo, int $excepto): void
    {
        $hotel = self::hotelDe($idTicket);

        $sql = "SELECT id_login FROM login WHERE bloqueo = 0 AND rol <> 'reportador'";
        $par = [];
        if ($hotel !== null) {
            $sql .= ' AND (id_hotel = ? OR id_hotel IS NULL)';
            $par[] = $hotel;
        }
        $gente = Db::all($sql, $par);
        foreach ($gente as $g) {
            Repo::notificar((int)$g['id_login'], $idTicket, 'Parte URGENTE ' . Repo::codigo($idTicket),
                            $titulo, $excepto, 'urgente_nuevo');
        }
    }

    /**
     * Guarda los valores de los campos propios. Acepta {clave: valor} o
     * {id_campo: valor}; se resuelve contra la definición para no confiar
     * en lo que llega.
     */
    public static function guardarCampos(int $idTicket, $campos): array
    {
        if (!is_array($campos) || !$campos || !Db::tablaExiste('campos_elemento')) {
            return [];
        }

        /* Lo que cambia se devuelve para poder dejarlo en el seguimiento.
         *
         * Que la temperatura de impulsión pasara de 18 a 12 es la prueba
         * de que el trabajo se hizo: si sólo se guarda el último valor,
         * dentro de un mes nadie sabe cómo estaba antes. */
        $cambios = [];
        /* Sólo los campos del tipo de avería DE ESTE parte.
         *
         * El mismo nombre existe para varios tipos —«temp_sala» la tienen
         * el aire acondicionado y el fancoil—, y al buscar por nombre en
         * todo el catálogo se quedaba con cualquiera de ellos. Resultado:
         * la temperatura del aire de la 801 se guardaba en el campo del
         * fancoil, y la ficha la enseñaba vacía porque miraba el suyo.
         * Se veía como «no se ha guardado», y sí se guardaba: en el sitio
         * equivocado. */
        $definidos = Db::all(
            'SELECT c.id_campo, c.clave, c.tipo, c.opciones, c.etiqueta, c.unidad
               FROM campos_elemento c
               JOIN ticket_incidencias t ON t.id_ticket = ?
              WHERE c.activo = 1 AND c.id_elemento = t.id_elemento',
            [$idTicket]);
        $porClave = [];
        $porId    = [];
        foreach ($definidos as $d) {
            $porClave[$d['clave']]      = $d;
            $porId[(int)$d['id_campo']] = $d;
        }

        foreach ($campos as $k => $v) {
            $def = $porClave[$k] ?? ($porId[(int)$k] ?? null);
            if ($def === null) {
                continue;                       // campo desconocido: se ignora
            }
            $anterior = Db::value('SELECT valor FROM valores_campo WHERE id_ticket = ? AND id_campo = ?',
                                  [$idTicket, (int)$def['id_campo']]);

            if ($v === null || $v === '') {
                if ($anterior !== null) {
                    $cambios[] = $def['etiqueta'] . ': ' . $anterior . ' → sin anotar';
                }
                Db::run('DELETE FROM valores_campo WHERE id_ticket = ? AND id_campo = ?',
                        [$idTicket, (int)$def['id_campo']]);
                continue;
            }

            // Una lista sólo admite los valores que tiene declarados.
            if ($def['tipo'] === 'lista' && $def['opciones']) {
                $ok = json_decode((string)$def['opciones'], true) ?: [];
                if ($ok && !in_array((string)$v, array_map('strval', $ok), true)) {
                    continue;
                }
            }
            $texto = is_bool($v) ? ($v ? '1' : '0') : (string)$v;

            if ((string)$anterior !== $texto) {
                $comoSeLee = static fn(?string $x): string => $x === null || $x === ''
                    ? 'sin anotar'
                    : ($def['tipo'] === 'interruptor' ? ($x === '1' ? 'sí' : 'no') : $x)
                      . ($def['unidad'] ? ' ' . $def['unidad'] : '');
                $cambios[] = $def['etiqueta'] . ': '
                           . ($anterior === null ? '' : $comoSeLee($anterior) . ' → ')
                           . $comoSeLee($texto);
            }

            $existe = Db::value('SELECT id_valor FROM valores_campo WHERE id_ticket = ? AND id_campo = ?',
                                [$idTicket, (int)$def['id_campo']]);
            if ($existe !== null) {
                Db::update('valores_campo', ['valor' => mb_substr($texto, 0, 500), 'updated_at' => Db::now()],
                           'id_valor = :id', ['id' => (int)$existe]);
            } else {
                Db::insert('valores_campo', [
                    'id_ticket' => $idTicket,
                    'id_campo'  => (int)$def['id_campo'],
                    'valor'     => mb_substr($texto, 0, 500),
                    'updated_at'=> Db::now(),
                ]);
            }
        }

        return $cambios;
    }

    /** Normaliza fechas entrantes (ISO-8601 o 'Y-m-d H:i:s') a UTC. */
    public static function fecha($v): ?string
    {
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Una fecha universal escrita en la hora del hotel.
     *
     * El servidor guarda y calcula en universal, que es lo correcto.
     * Pero el seguimiento lo lee una persona, y a esa persona «las
     * 06:00» cuando quiso decir las 08:00 le parece —con razón— un
     * fallo. Así que lo que se escribe en el texto va en la hora de
     * aquí, la de `app.zona_horaria`.
     */
    public static function enHoraLocal(string $utc, string $formato = 'd/m/Y H:i'): string
    {
        global $config;
        $zona = (string)($config['app']['zona_horaria'] ?? 'UTC');
        try {
            $d = new DateTime($utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($zona));
            return $d->format($formato);
        } catch (Throwable $e) {
            // Una zona mal escrita en la configuración no puede tirar un parte.
            return substr($utc, 0, 16);
        }
    }

    /**
     * Despertar los partes cuya fecha ya ha llegado.
     *
     * Un parte aparcado hasta el día 30 tiene que volver solo a la cola
     * ese día. Si hiciera falta que alguien lo cambiara a mano, se nos
     * olvidaría y el cliente volvería a quejarse: justo lo que se quería
     * evitar al aparcarlo.
     *
     * Se llama al pedir listas —es un UPDATE con índice, cuesta nada— en
     * vez de montar una tarea programada, que en un hotel es una cosa más
     * que puede dejar de funcionar sin que nadie se entere.
     */
    public static function despertarProgramados(): int
    {
        if (!in_array('visible_desde', Db::columnas('ticket_incidencias'), true)) {
            return 0;
        }

        $programado = Http::id(Db::value(
            "SELECT id_estado FROM estados WHERE LOWER(nombre_estado) LIKE 'programad%' LIMIT 1"));
        $inicial = Http::id(Db::value(
            'SELECT id_estado FROM estados WHERE es_inicial = 1 ORDER BY orden LIMIT 1'));
        if ($programado === null || $inicial === null) {
            return 0;
        }

        /* Los que toca despertar, uno a uno: a cada uno hay que darle
           un plazo nuevo. El plazo viejo se contaba desde que se dio el
           aviso, así que un parte aparcado diez días despertaría ya
           «fuera de plazo» sin que nadie haya hecho nada mal. */
        $toca = Db::all(
            'SELECT id_ticket, id_prioridad FROM ticket_incidencias
              WHERE id_estado = ? AND borrado = 0
                AND visible_desde IS NOT NULL AND visible_desde <= ?',
            [$programado, Db::now()]);

        foreach ($toca as $t) {
            Db::update('ticket_incidencias', [
                'id_estado'     => $inicial,
                'visible_desde' => null,
                'fecha_limite'  => Repo::fechaLimite(Http::id($t['id_prioridad']), Db::now()),
                'updated_at'    => Db::now(),
            ], 'id_ticket = :id', ['id' => (int)$t['id_ticket']]);
        }

        return count($toca);
    }

    /**
     * Quitar todo lo que cuelga de un parte, antes de borrarlo.
     *
     * Está en un solo sitio a propósito: antes esta lista estaba copiada
     * en dos lugares —borrar un parte y restablecer un hotel— y las dos
     * se quedaron cortas cuando se añadieron la conversación y los
     * observadores. Los partes con mensajes NO se podían borrar, y en
     * «restablecer» el fallo se tragaba en silencio, así que el hotel se
     * quedaba a medio limpiar sin decir nada.
     */
    /**
     * Lo que muere con el parte aunque su columna admita nulo.
     *
     * El esquema sabe si una referencia es obligatoria, pero no sabe si la
     * fila SIGNIFICA algo sin su parte. Un aviso que dice «te han asignado
     * el parte T-00034» no significa nada cuando ese parte ya no existe:
     * quedaba ahí, sin poder abrirse, y contando en la chapa de «sin
     * leer» para siempre. Es el fallo de los «2 mensajes» que no se iban.
     *
     * Lo mismo con lo que está esperando a salir hacia Teams: avisar de un
     * parte borrado es mandar a alguien a una pantalla vacía.
     */
    /* Lo que no tiene sentido sin su parte, nombrado A MANO y no sólo
     * preguntado al esquema.
     *
     * El historial y las fotos estaban fuera de esta lista porque la
     * limpieza por esquema (limpiarHijos) los encontraba por su clave
     * foránea… en SQLite. En el esquema de MySQL esas dos tablas no
     * declaran la clave, así que allí el borrado definitivo los dejaba
     * huérfanos: 18.947 líneas de historial de partes inexistentes en la
     * base de pruebas de MySQL, que es la de producción. Lo encontró el
     * panel de integridad (lib/Integridad.php). La papelera ya guarda
     * copia de las dos antes de borrar (Archivo::guardarTicket). */
    private const MUEREN_CON_EL_PARTE = ['notificaciones', 'teams_salida',
                                         'ticket_incidencias_historial', 'adjuntos'];

    public static function borrarDependencias(int $id): void
    {
        /* Esto va ANTES de la limpieza por esquema. Si fuera después, la
           limpieza ya les habría puesto el id_ticket a NULL y no habría
           forma de saber de qué parte eran. */
        foreach (self::MUEREN_CON_EL_PARTE as $tabla) {
            if (!Db::tablaExiste($tabla)) { continue; }
            if (!in_array('id_ticket', Db::columnas($tabla), true)) { continue; }
            try {
                Db::run("DELETE FROM $tabla WHERE id_ticket = ?", [$id]);
            } catch (Throwable $e) {
                /* Que no se pueda limpiar un aviso no puede impedir borrar
                   el parte: el aviso huérfano se puede quitar luego desde
                   «Restablecer», y el parte atascado no. */
                if (class_exists('Errores')) {
                    Errores::apuntar('servidor',
                        "No se pudo limpiar $tabla del parte $id: " . $e->getMessage(),
                        'Tickets::borrarDependencias');
                }
            }
        }

        self::limpiarHijos('ticket_incidencias', 'id_ticket', $id);
    }

    /**
     * Quitar todo lo que depende de una fila, y lo que depende de eso.
     *
     * Lo que cuelga se pregunta al esquema en vez de recordarlo: antes era
     * una lista escrita a mano, copiada en dos sitios, y se quedaba corta
     * cada vez que se añadía una tabla.
     *
     * Y va en profundidad, que es lo que faltaba: las lecturas del chat
     * apuntan al MENSAJE, no al parte. Al borrar los mensajes sin quitar
     * antes sus lecturas, la base lo rechazaba con un «FOREIGN KEY
     * constraint failed» y el parte no había manera de borrarlo.
     *
     *   · columna obligatoria → esa fila no existe sin su padre: se borra
     *     (y antes, lo que colgara de ella);
     *   · columna que admite nulo → la fila vale por sí sola: sólo se
     *     suelta la referencia.
     */
    private static function limpiarHijos(string $tabla, string $columna, int $id, int $nivel = 0): void
    {
        // Tope de seguridad: un esquema con un ciclo no debe colgar esto.
        if ($nivel > 4) { return; }

        foreach (Db::dependenciasDe($tabla) as $dep) {
            $hija    = $dep['tabla'];
            $columnaHija = $dep['columna'];

            if (!$dep['obligatoria']) {
                Db::run("UPDATE $hija SET $columnaHija = NULL WHERE $columnaHija = ?", [$id]);
                continue;
            }

            /* Antes de borrar estas filas, lo que cuelgue de ellas. Se
               hace por su clave primaria, una a una: son pocas y así vale
               para cualquier tabla sin saber nada de ella. */
            $clave = Db::clavePrimaria($hija);
            if ($clave !== null) {
                foreach (Db::all("SELECT $clave FROM $hija WHERE $columnaHija = ?", [$id]) as $fila) {
                    self::limpiarHijos($hija, $clave, (int)$fila[$clave], $nivel + 1);
                }
            }

            Db::run("DELETE FROM $hija WHERE $columnaHija = ?", [$id]);
        }
    }
}
