<?php

namespace App\Exceptions;

use App\Support\Plural;
use RuntimeException;

/**
 * Приём нельзя отметить или отменить отметку: он не за сегодня или вчера по местной дате пациента,
 * его время ещё не подошло или его уже убрал пересчёт. Сообщение показывается пациенту.
 */
class DoseNotMarkable extends RuntimeException
{
    public static function outOfRange(): self
    {
        return new self('Отмечать можно только приёмы за сегодня и вчера.');
    }

    public static function gone(): self
    {
        return new self('Этот приём уже изменился. Список обновлён.');
    }

    public static function notDue(): self
    {
        $minutes = config()->integer('medications.early_minutes');

        return new self('Приём можно отметить не раньше чем за '.$minutes.' '.Plural::choose($minutes, ['минуту', 'минуты', 'минут']).' до планового времени.');
    }
}
