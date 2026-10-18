<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Время хранится в UTC, а показывается в часовом поясе интерфейса.
 */
final class DisplayTime
{
    public const string TIMEZONE = 'Europe/Moscow';

    public static function format(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->setTimezone(self::TIMEZONE)->format('d.m.Y H:i');
    }
}
