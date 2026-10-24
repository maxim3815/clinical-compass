<?php

namespace App\Support\Alerts;

use App\Models\HelpEvent;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * Тихие строки в карточке пациента: когда он открывал экран помощи и нажимал на нём звонки.
 * Без алерта, пуша и счётчика: врач может спросить об этом на приёме.
 */
final class HelpEventPresenter
{
    public const int DAYS = 30;

    public const int LIMIT = 20;

    /**
     * Последние за 30 дней, сначала новые. События одной минуты — одной строкой по порядку:
     * «Открыт экран помощи, нажата кнопка кризисной линии».
     *
     * @return list<array{at: string, label: string}>
     */
    public static function forPatient(User $patient): array
    {
        return array_values($patient->helpEvents()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::DAYS)->format('Y-m-d H:i:s.v'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (HelpEvent $event): string => DisplayTime::format($event->created_at))
            ->take(self::LIMIT)
            ->map(fn ($events, string $at): array => [
                'at' => $at,
                'label' => mb_ucfirst($events->reverse()->map(fn (HelpEvent $event): string => $event->type->label())->unique()->implode(', ')),
            ])
            ->all());
    }
}
