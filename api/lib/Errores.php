<?php
/**
 * El registro de errores.
 *
 * Antes, cuando algo fallaba, el mensaje acababa en el log de Apache: un
 * fichero que en un hotel no mira nadie. El técnico decía «no me deja» y
 * no había forma de saber qué había pasado ni a cuánta gente.
 *
 * Aquí se guarda lo que hace falta para entenderlo: qué falló, quién lo
 * sufrió, en qué pantalla y con qué versión. Y los repetidos se agrupan:
 * un fallo que le pasa a treinta personas es UNA línea con un contador,
 * no treinta líneas que tapan todo lo demás.
 *
 * Guardar un error nunca puede tirar la aplicación: si esto falla, se
 * calla y deja seguir. Un sistema de registro que rompe lo que vigila es
 * peor que no tenerlo.
 */
declare(strict_types=1);

final class Errores
{
    /** Cuántos días se conservan antes de limpiarse solos. */
    private const DIAS = 30;

    /**
     * Apuntar un fallo.
     *
     * @param string $origen 'servidor' o 'movil'
     */
    public static function apuntar(
        string $origen,
        string $mensaje,
        string $detalle = '',
        ?string $ruta = null,
        ?array $usuario = null,
        ?string $version = null,
        ?string $aparato = null
    ): void {
        try {
            if (!Db::tablaExiste('errores')) { return; }

            $mensaje = mb_substr(trim($mensaje), 0, 500);
            if ($mensaje === '') { return; }

            /* La huella agrupa el mismo fallo aunque cambie el id del
               parte o la hora: mensaje + primera línea de la traza. */
            $primeraLinea = trim(strtok($detalle, "\n") ?: '');
            $huella = substr(sha1($origen . '|' . $mensaje . '|' . $primeraLinea), 0, 40);

            $ya = Db::one('SELECT id_error, veces FROM errores WHERE huella = ? AND visto = 0', [$huella]);

            if ($ya !== null) {
                Db::run('UPDATE errores SET veces = veces + 1, ultimo = ? WHERE id_error = ?',
                        [Db::now(), (int)$ya['id_error']]);
                return;
            }

            Db::insert('errores', [
                'origen'   => $origen === 'movil' ? 'movil' : 'servidor',
                'mensaje'  => $mensaje,
                'detalle'  => mb_substr($detalle, 0, 4000),
                'ruta'     => $ruta === null ? null : mb_substr($ruta, 0, 255),
                'id_login' => $usuario === null ? null : Http::id($usuario['id_login'] ?? null),
                // El nombre se copia: si luego borran la cuenta, el rastro sigue sirviendo.
                'usuario'  => $usuario === null ? null
                    : mb_substr(trim(($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido1'] ?? ''))
                                ?: (string)($usuario['usuario'] ?? ''), 0, 80),
                'version'  => $version === null ? null : mb_substr($version, 0, 32),
                'aparato'  => $aparato === null ? null : mb_substr($aparato, 0, 255),
                'id_hotel' => $usuario === null ? null : Http::id($usuario['id_hotel'] ?? null),
                'huella'   => $huella,
                'veces'    => 1,
                'visto'    => 0,
                'creado'   => Db::now(),
                'ultimo'   => Db::now(),
            ]);

            self::limpiarViejos();
        } catch (Throwable $e) {
            // Si el propio registro falla, se calla: nunca debe tumbar nada.
            error_log('HuraKan Tick · no se pudo apuntar un error: ' . $e->getMessage());
        }
    }

    /** Lo que hay, lo más repetido y lo más reciente primero. */
    public static function listar(bool $soloNuevos = true, int $limite = 100): array
    {
        if (!Db::tablaExiste('errores')) { return []; }

        return Db::all(
            'SELECT * FROM errores'
            . ($soloNuevos ? ' WHERE visto = 0' : '')
            . ' ORDER BY visto, ultimo DESC LIMIT ' . max(1, min(300, $limite)));
    }

    /** Cuántos hay sin mirar: es lo que se enseña en Administración. */
    public static function sinVer(): array
    {
        if (!Db::tablaExiste('errores')) { return ['grupos' => 0, 'veces' => 0]; }

        $f = Db::one('SELECT COUNT(*) AS grupos, COALESCE(SUM(veces), 0) AS veces
                        FROM errores WHERE visto = 0');
        return ['grupos' => (int)($f['grupos'] ?? 0), 'veces' => (int)($f['veces'] ?? 0)];
    }

    /** Dar por visto un fallo —o todos—, para que no tapen los nuevos. */
    public static function marcarVisto(?int $id = null): int
    {
        if (!Db::tablaExiste('errores')) { return 0; }

        return $id === null
            ? Db::run('UPDATE errores SET visto = 1 WHERE visto = 0')->rowCount()
            : Db::run('UPDATE errores SET visto = 1 WHERE id_error = ?', [$id])->rowCount();
    }

    /** Los de hace más de un mes se van solos: esto no es un archivo. */
    private static function limpiarViejos(): void
    {
        if (random_int(1, 50) !== 1) { return; }   // de vez en cuando, no en cada error
        Db::run('DELETE FROM errores WHERE creado < ?',
                [gmdate('Y-m-d H:i:s', time() - self::DIAS * 86400)]);
    }
}
