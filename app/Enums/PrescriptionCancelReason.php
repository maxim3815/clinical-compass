<?php

namespace App\Enums;

enum PrescriptionCancelReason: string
{
    case CourseCompleted = 'course_completed';
    case AdverseEvents = 'adverse_events';
    case InsufficientEffect = 'insufficient_effect';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CourseCompleted => 'Курс завершён',
            self::AdverseEvents => 'Нежелательные явления',
            self::InsufficientEffect => 'Недостаточный эффект',
            self::Other => 'Другое',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
