<?php

declare(strict_types=1);

/**
 * Dar de baja a una persona de verdad: borrarla, sin dejar huecos.
 *
 * Esto vivía dentro de la ruta DELETE /api/usuarios/{id}, y
 * tools/equipo_benalma.php —que retira a la gente de ejemplo para poner
 * al equipo real— llevaba su propia copia, escrita a mano. La copia se
 * quedó corta: le faltaban siete de las tablas que apuntan a una
 * persona (el chat, quién lee qué, los departamentos de apoyo, los
 * permisos sueltos, la cola de Teams, los observadores, los medios de
 * las instrucciones). Con una persona de ejemplo recién instalada no se
 * notaba, porque todavía no había tocado nada de eso; con una base usada,
 * el borrado chocaba con una clave foránea y se deshacía la operación
 * entera.
 *
 * El mismo fallo ya lo había tenido la ruta —le faltaban nueve tablas—.
 * Dos listas a mano de lo mismo acaban siendo dos listas distintas. Ahora
 * hay una.
 *
 * Lo que la persona hizo no se borra: se desliga o se traspasa a
 * `$heredero`. Un parte sin autor es recuperable; un historial con
 * huecos, no.
 *
 * Hay que llamarla DENTRO de una transacción: si algo falla, no debe
 * quedar a medias.
 */
final class Bajas
{
    /** Las tablas que se BORRAN: suyas y sin valor para nadie más. */
    public const BORRAR = [
        'api_tokens', 'notificaciones', 'avisos_pref', 'preferencias', 'recuperaciones',
        'push_destinos',        // los móviles donde recibía avisos
        'chat_lecturas',        // qué mensajes había leído
        'login_departamento',   // sus departamentos de apoyo
        'login_permisos',       // sus permisos sueltos
        'teams_salida',         // lo que quedaba por mandar a Teams
        'ticket_observadores',  // los partes que seguía
    ];

    /** Las columnas que pasan a `$heredero`: no admiten vacío, y con razón. */
    public const TRASPASAR = [
        'ticket_incidencias'           => ['id_realiza_accion'],
        'ticket_incidencias_historial' => ['id_realiza_accion'],
        'adjuntos'                     => ['id_realiza_accion'],
        'copias'                       => ['id_login'],
        /* Un mensaje de chat no se borra: la conversación quedaría con
           huecos y con respuestas a nada. */
        'chat_mensajes'                => ['id_login'],
    ];

    /** Las columnas que se quedan vacías. */
    public const VACIAR = [
        /* Un parte sin dueño vuelve a la cola y alguien lo coge. */
        'ticket_incidencias'           => ['id_persona_asignada'],
        'ticket_incidencias_historial' => ['id_login', 'id_login_old'],
        'informes'                     => ['id_login'],
        'atajos'                       => ['id_login'],
        'registro_seguridad'           => ['id_login'],
        'mantenimientos'               => ['id_persona'],
        'instrucciones'                => ['id_autor'],
        'instrucciones_medios'         => ['id_autor'],
        'unidades'                     => ['id_alta'],
        'unidades_mov'                 => ['id_login'],
        'rel_zonas'                    => ['id_realiza_accion'],
        /* Las filas en que ERA observador se borran arriba; aquí sólo se
           olvida a quién puso él de observador. */
        'ticket_observadores'          => ['id_puso'],
    ];

    public static function borrarDefinitivo(int $id, int $heredero, string $motivo): void
    {
        // A la papelera antes de tocar nada.
        Archivo::guardarUsuario($id, $motivo);

        foreach (self::BORRAR as $t) {
            if (Db::tablaExiste($t)) { Db::run("DELETE FROM $t WHERE id_login = ?", [$id]); }
        }
        // El emisor de un código de recuperación puede ser otra persona.
        if (Db::tablaExiste('recuperaciones')) {
            Db::run('UPDATE recuperaciones SET id_emisor = NULL WHERE id_emisor = ?', [$id]);
        }
        foreach (self::TRASPASAR as $t => $cols) {
            if (!Db::tablaExiste($t)) { continue; }
            foreach ($cols as $c) {
                if (in_array($c, Db::columnas($t), true)) {
                    Db::run("UPDATE $t SET $c = ? WHERE $c = ?", [$heredero, $id]);
                }
            }
        }
        foreach (self::VACIAR as $t => $cols) {
            if (!Db::tablaExiste($t)) { continue; }
            foreach ($cols as $c) {
                if (in_array($c, Db::columnas($t), true)) {
                    Db::run("UPDATE $t SET $c = NULL WHERE $c = ?", [$id]);
                }
            }
        }
        Db::run('DELETE FROM login WHERE id_login = ?', [$id]);
    }
}
