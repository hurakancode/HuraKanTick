<?php
/**
 * Aviso de partes fuera de plazo.
 *
 * Pensado para una tarea programada, una vez por hora:
 *   0 * * * *  php /ruta/hurakantick/tools/vencidos.php >> /var/log/hurakantick-vencidos.log 2>&1
 *
 * Avisa una sola vez por parte: se anota en preferencias para no repetir
 * el aviso cada hora, que es la forma más rápida de que la gente aprenda
 * a ignorarlos.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // También se puede llamar desde un cron web con una clave.
    $config = require __DIR__ . '/../api/config.php';
    $clave = $_GET['clave'] ?? '';
    if ($clave === '' || !hash_equals((string)$config['auth']['app_key'], (string)$clave)) {
        http_response_code(403);
        exit('No autorizado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$config = $config ?? require __DIR__ . '/../api/config.php';
require __DIR__ . '/../api/lib/compat.php';
require __DIR__ . '/../api/lib/Http.php';
require __DIR__ . '/../api/lib/Db.php';
require __DIR__ . '/../api/lib/Auth.php';
require __DIR__ . '/../api/lib/Permisos.php';
require __DIR__ . '/../api/lib/Hoteles.php';
require __DIR__ . '/../api/lib/Registro.php';
require __DIR__ . '/../api/lib/Push.php';
require __DIR__ . '/../api/lib/Repo.php';

Db::init($config['db']);
Auth::init($config['auth']);

$ahora = Db::now();
$log = static function (string $m): void { echo gmdate('[Y-m-d H:i:s] ') . $m . "\n"; };

/* Partes pasados de plazo, todavía abiertos y sin avisar */
$vencidos = Db::all(
    "SELECT t.id_ticket, t.titulo_ticket, t.fecha_limite, t.id_persona_asignada, t.id_hotel,
            t.id_grupo_asignado, t.id_realiza_accion, t.id_prioridad,
            r.ruta AS zona_ruta
       FROM ticket_incidencias t
       LEFT JOIN estados e ON e.id_estado = t.id_estado
       LEFT JOIN rel_zonas r ON r.id_rel = t.id_rel_zona
      WHERE t.borrado = 0
        AND COALESCE(e.es_final, 0) = 0
        AND t.fecha_limite IS NOT NULL
        AND t.fecha_limite < ?
        AND t.aviso_vencido IS NULL
      ORDER BY t.fecha_limite ASC
      LIMIT 200",
    [$ahora]
);

if (!$vencidos) {
    $log('Ningún parte fuera de plazo pendiente de avisar.');
    exit(0);
}

$avisados = 0;
foreach ($vencidos as $t) {
    $id = (int)$t['id_ticket'];
    $horas = max(1, (int)round((strtotime($ahora) - strtotime((string)$t['fecha_limite'])) / 3600));
    $texto = $t['titulo_ticket'] . ($t['zona_ruta'] ? ' · ' . $t['zona_ruta'] : '');
    $titulo = Repo::codigo($id) . ' lleva ' . ($horas < 48 ? "$horas h" : round($horas / 24) . ' días')
            . ' fuera de plazo';

    // A quien lo tiene asignado
    if ($t['id_persona_asignada'] !== null) {
        Repo::notificar((int)$t['id_persona_asignada'], $id, $titulo, $texto, 0, 'vencido');
    }

    /* Y al departamento, saltándose a quien ya lo ha recibido.
     *
     * Casi siempre quien tiene el parte asignado pertenece al
     * departamento al que va, así que sin esta excepción le llegaba dos
     * veces el mismo aviso: una como responsable del trabajo y otra
     * como parte del grupo. Dos avisos del mismo parte hacen dudar de
     * si son dos cosas distintas, y a la tercera se dejan de mirar. */
    if ($t['id_grupo_asignado'] !== null) {
        $yaAvisado = $t['id_persona_asignada'] !== null ? (int)$t['id_persona_asignada'] : 0;
        /* Sólo la gente del hotel del parte.
           El departamento es común a la cadena, así que sin filtrar se
           avisaba a los técnicos de todos los hoteles de un plazo que
           no es suyo. */
        $sqlGrupo = 'SELECT id_login FROM login WHERE id_departamento = ? AND bloqueo = 0';
        $parGrupo = [(int)$t['id_grupo_asignado']];
        if (Db::tablaExiste('hoteles') && $t['id_hotel'] !== null) {
            $sqlGrupo .= ' AND (id_hotel = ? OR id_hotel IS NULL)';
            $parGrupo[] = (int)$t['id_hotel'];
        }

        foreach (Db::all($sqlGrupo, $parGrupo) as $g) {
            if ((int)$g['id_login'] === $yaAvisado) {
                continue;
            }
            Repo::notificar((int)$g['id_login'], $id, $titulo, $texto, 0, 'vencido');
        }
    }

    // Si no hay nadie detrás, se avisa a los responsables: un parte
    // vencido y sin dueño es justo el que no debe pasar desapercibido.
    if ($t['id_persona_asignada'] === null && $t['id_grupo_asignado'] === null) {
        /* Y los responsables, los de ese hotel. Un jefe de mantenimiento
           no necesita enterarse de los plazos de otra casa. */
        $sqlResp = "SELECT id_login FROM login WHERE bloqueo = 0 AND (admin = 1 OR rol = 'responsable')";
        $parResp = [];
        if (Db::tablaExiste('hoteles') && $t['id_hotel'] !== null) {
            $sqlResp .= ' AND (id_hotel = ? OR id_hotel IS NULL)';
            $parResp[] = (int)$t['id_hotel'];
        }

        foreach (Db::all($sqlResp, $parResp) as $r) {
            Repo::notificar((int)$r['id_login'], $id, $titulo . ' y sin asignar', $texto, 0, 'vencido');
        }
    }

    // Marca para no repetir el aviso en la siguiente pasada. Se escribe
    // directamente, sin tocar updated_at: esto no es un cambio del parte
    // y no debe provocar una sincronización a todos los móviles.
    Db::run('UPDATE ticket_incidencias SET aviso_vencido = ? WHERE id_ticket = ?', [$ahora, $id]);
    $avisados++;
}

$log("Avisados $avisados partes fuera de plazo.");
