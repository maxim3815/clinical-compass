<?php

namespace App\Support;

use Carbon\CarbonImmutable;
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

    public static function formatDate(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->setTimezone(self::TIMEZONE)->format('d.m.Y');
    }

    /**
     * Сегодняшняя дата в часовом поясе интерфейса как полночь UTC. Так её можно сравнивать
     * с датами без времени, например с датой рождения.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString(), 'UTC');
    }
}
