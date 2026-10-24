<?php

namespace App\Enums;

/**
 * Вид врача: от него зависит кабинет. Психиатр ведёт лечение, терапевт только отбирает пациентов
 * на приёме и направляет к психиатру.
 */
enum DoctorKind: string
{
    case Psychiatrist = 'psychiatrist';
    case Therapist = 'therapist';

    /**
     * Специальность по умолчанию — подпись для пациента.
     */
    public function specialty(): string
    {
        return match ($this) {
            self::Psychiatrist => 'Психиатр',
            self::Therapist => 'Терапевт',
        };
    }
}
