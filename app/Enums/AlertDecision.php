<?php

namespace App\Enums;

/**
 * Чем врач закрыл алерт [медик]. Комментарий можно добавить к любому решению,
 * у «Угрозы нет» он обязателен.
 */
enum AlertDecision: string
{
    case Contacted = 'contacted';
    case Appointment = 'appointment';
    case Emergency = 'emergency';
    case NoThreat = 'no_threat';

    public function label(): string
    {
        return match ($this) {
            self::Contacted => 'Связь с пациентом установлена',
            self::Appointment => 'Назначен очный приём',
            self::Emergency => 'Экстренная помощь или госпитализация',
            self::NoThreat => 'Угрозы нет',
        };
    }

    public function requiresComment(): bool
    {
        return $this === self::NoThreat;
    }

    /**
     * @return list<array{value: string, label: string, comment_required: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $decision): array => [
            'value' => $decision->value,
            'label' => $decision->label(),
            'comment_required' => $decision->requiresComment(),
        ], self::cases());
    }
}
