<?php

namespace App\Support;

final class Plural
{
    /**
     * Форма слова для числа: Plural::choose(5, ['таблетка', 'таблетки', 'таблеток']) вернёт «таблеток».
     *
     * @param  array{string, string, string}  $forms
     */
    public static function choose(int $count, array $forms): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $forms[0];
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $forms[1];
        }

        return $forms[2];
    }
}
