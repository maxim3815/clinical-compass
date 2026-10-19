<?php

namespace App\Support;

/**
 * Доза в таблетках и миллиграммах для интерфейса.
 */
final class Dose
{
    /**
     * «½ таблетки», «1 таблетка», «1½ таблетки», «5 таблеток». Таблетка делится только пополам.
     */
    public static function tablets(string $tablets): string
    {
        $halves = (int) round((float) $tablets * 2);
        $whole = intdiv($halves, 2);

        if ($halves % 2 === 1) {
            return ($whole > 0 ? $whole : '').'½ таблетки';
        }

        return $whole.' '.Plural::choose($whole, ['таблетка', 'таблетки', 'таблеток']);
    }

    /**
     * «20 мг», «12,5 мг»: без лишних нулей, дробная часть через запятую.
     */
    public static function mg(string|float $mg): string
    {
        return rtrim(rtrim(number_format((float) $mg, 2, ',', ''), '0'), ',').' мг';
    }

    /**
     * Доза одного приёма: «½ таблетки, 12,5 мг».
     */
    public static function label(string $tablets, string $strengthMg): string
    {
        return self::tablets($tablets).', '.self::mg((float) $tablets * (float) $strengthMg);
    }
}
