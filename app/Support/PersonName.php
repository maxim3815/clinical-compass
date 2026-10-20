<?php

namespace App\Support;

/**
 * ФИО хранится одной строкой «Фамилия Имя Отчество».
 */
final class PersonName
{
    /**
     * Фамилия с инициалами: «Петрова Анна Ивановна» → «Петрова А. И.».
     */
    public static function short(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        if (count($parts) < 2) {
            return trim($name);
        }

        $initials = array_map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.', array_slice($parts, 1));

        return $parts[0].' '.implode(' ', $initials);
    }
}
