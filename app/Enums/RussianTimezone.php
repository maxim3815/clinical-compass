<?php

namespace App\Enums;

/**
 * Часовые пояса России, из которых пациент выбирает свой. Перехода на летнее время
 * в России нет, поэтому смещение в подписи постоянное.
 */
enum RussianTimezone: string
{
    case Kaliningrad = 'Europe/Kaliningrad';
    case Moscow = 'Europe/Moscow';
    case Samara = 'Europe/Samara';
    case Yekaterinburg = 'Asia/Yekaterinburg';
    case Omsk = 'Asia/Omsk';
    case Krasnoyarsk = 'Asia/Krasnoyarsk';
    case Irkutsk = 'Asia/Irkutsk';
    case Yakutsk = 'Asia/Yakutsk';
    case Vladivostok = 'Asia/Vladivostok';
    case Magadan = 'Asia/Magadan';
    case Kamchatka = 'Asia/Kamchatka';

    public function label(): string
    {
        return match ($this) {
            self::Kaliningrad => 'Калининград (UTC+2)',
            self::Moscow => 'Москва (UTC+3)',
            self::Samara => 'Самара (UTC+4)',
            self::Yekaterinburg => 'Екатеринбург (UTC+5)',
            self::Omsk => 'Омск (UTC+6)',
            self::Krasnoyarsk => 'Красноярск, Новосибирск (UTC+7)',
            self::Irkutsk => 'Иркутск (UTC+8)',
            self::Yakutsk => 'Якутск (UTC+9)',
            self::Vladivostok => 'Владивосток (UTC+10)',
            self::Magadan => 'Магадан, Сахалин (UTC+11)',
            self::Kamchatka => 'Камчатка (UTC+12)',
        };
    }

    /**
     * Варианты для выпадающего списка в порядке с запада на восток.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $timezone): array => ['value' => $timezone->value, 'label' => $timezone->label()],
            self::cases(),
        );
    }
}
