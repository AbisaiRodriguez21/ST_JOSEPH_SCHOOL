<?php

namespace App\Libraries;

/**
 * Alcance de los usuarios Director (nivel 2).
 *
 * Un director con usr.nivelT = 'todosecundaria' (o 'todoprimaria', etc.) solo puede
 * ver y calificar los grados de ese nivel. Si nivelT está vacío o no se reconoce,
 * el director conserva el acceso completo (comportamiento anterior).
 * Admin (nivel 1), titulares y demás niveles no se ven afectados.
 */
class AlcanceDirector
{
    // Valor de usr.nivelT (normalizado) => nivel educativo que puede ver.
    // 50/51/52 son los valores de "Todo ..." del formulario de Asignar titulares.
    private const MAPA_NIVELT = [
        'todokinder'       => 'kinder',
        'todopreescolar'   => 'kinder',
        'todoprimaria'     => 'primaria',
        '50'               => 'primaria',
        'todosecundaria'   => 'secundaria',
        '51'               => 'secundaria',
        'todobachillerato' => 'bachillerato',
        'todopreparatoria' => 'bachillerato',
        'todoprepa'        => 'bachillerato',
        '52'               => 'bachillerato',
    ];

    /**
     * Nivel educativo al que está limitado el usuario en sesión,
     * o null si no tiene restricción.
     */
    public static function nivelAsignado(): ?string
    {
        $session = session();
        if ((int) $session->get('nivel') !== 2) {
            return null;
        }

        // "Todo Secundaria", "todo_secundaria", "TODOSECUNDARIA" => "todosecundaria"
        $clave = strtolower(preg_replace('/[\s_\-]+/', '', (string) $session->get('nivelT')));

        return self::MAPA_NIVELT[$clave] ?? null;
    }

    /**
     * Nivel educativo de un grado según su nombre.
     * Usa la misma clasificación que el dashboard del director.
     */
    public static function categoria(?string $nombreGrado): ?string
    {
        $nombre = strtolower((string) $nombreGrado);

        if (strpos($nombre, 'kinder') !== false) return 'kinder';
        if (strpos($nombre, 'primaria') !== false) return 'primaria';
        if (strpos($nombre, 'secundaria') !== false) return 'secundaria';
        if (strpos($nombre, 'bachiller') !== false || strpos($nombre, 'prepa') !== false) return 'bachillerato';

        return null;
    }

    /**
     * Deja solo los grados que el usuario en sesión puede ver.
     */
    public static function filtrarGrados(array $grados): array
    {
        $nivel = self::nivelAsignado();
        if ($nivel === null) {
            return $grados;
        }

        return array_values(array_filter($grados, static function ($g) use ($nivel) {
            return self::categoria($g['nombreGrado'] ?? null) === $nivel;
        }));
    }

    public static function permiteGrado($idGrado): bool
    {
        $nivel = self::nivelAsignado();
        if ($nivel === null) {
            return true;
        }

        $row = db_connect()->table('grados')
            ->select('nombreGrado')
            ->where('id_grado', (int) $idGrado)
            ->get()->getRow();

        return $row !== null && self::categoria($row->nombreGrado) === $nivel;
    }

    /**
     * Valida el grado en el que está inscrito el alumno (no el de la URL).
     */
    public static function permiteAlumno($idAlumno): bool
    {
        $nivel = self::nivelAsignado();
        if ($nivel === null) {
            return true;
        }

        $row = db_connect()->table('usr')
            ->select('grados.nombreGrado')
            ->join('grados', 'usr.grado = grados.id_grado')
            ->where('usr.id', (int) $idAlumno)
            ->get()->getRow();

        return $row !== null && self::categoria($row->nombreGrado) === $nivel;
    }

    /**
     * Valida el grado de un registro existente de la tabla calificacion.
     */
    public static function permiteCalificacion($idCal): bool
    {
        $nivel = self::nivelAsignado();
        if ($nivel === null) {
            return true;
        }

        $row = db_connect()->table('calificacion')
            ->select('grados.nombreGrado')
            ->join('grados', 'calificacion.id_grado = grados.id_grado')
            ->where('calificacion.Id_cal', (int) $idCal)
            ->get()->getRow();

        return $row !== null && self::categoria($row->nombreGrado) === $nivel;
    }
}
