<?php

namespace App\Libraries;

/**
 * Normaliza texto para búsquedas tolerantes:
 * sin acentos, sin mayúsculas, sin comas/puntos/guiones y con espacios simples.
 *
 * También repara nombres con codificación dañada ("mojibake") que existen en la BD,
 * p. ej. "SÃNCHEZ" o "MUÃ‘OZ", para que se encuentren escribiendo "sanchez" o "muñoz".
 */
class TextoBusqueda
{
    // Caracteres de Windows-1252 (0x80-0x9F) => su byte original
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85,
        0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A,
        0x2039 => 0x8B, 0x0152 => 0x8C, 0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92,
        0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
        0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B, 0x0153 => 0x9C,
        0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    private const SIN_ACENTOS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * "  Pérez, JUAN  " => "perez juan"
     */
    public static function normalizar(?string $texto): string
    {
        $texto = html_entity_decode((string) $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = self::repararMojibake($texto);
        $texto = strtr(mb_strtolower($texto, 'UTF-8'), self::SIN_ACENTOS);

        // Todo lo que no sea letra o número separa palabras (comas, puntos, @, guiones...)
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim($texto);
    }

    /**
     * Palabras a buscar (sin repetidas). Vacío si no hay nada que buscar.
     */
    public static function palabras(?string $texto): array
    {
        $normal = self::normalizar($texto);

        return $normal === '' ? [] : array_values(array_unique(explode(' ', $normal)));
    }

    /**
     * true si TODAS las palabras aparecen en el texto, en cualquier orden.
     */
    public static function coincide(array $palabras, string $textoNormalizado): bool
    {
        foreach ($palabras as $p) {
            if (strpos($textoNormalizado, $p) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Texto UTF-8 que se guardó leyendo sus bytes como Windows-1252 ("SÃNCHEZ").
     * Si no parece dañado, o no se puede reparar, se regresa igual.
     */
    private static function repararMojibake(string $texto): string
    {
        if (!preg_match('/[ÃÂ]/u', $texto)) {
            return $texto;
        }

        $bytes = '';
        foreach (mb_str_split($texto, 1, 'UTF-8') as $caracter) {
            $cp = mb_ord($caracter, 'UTF-8');
            if ($cp < 0x100) {
                $bytes .= chr($cp);
            } elseif (isset(self::CP1252[$cp])) {
                $bytes .= chr(self::CP1252[$cp]);
            } else {
                return $texto;
            }
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $texto;
    }
}
