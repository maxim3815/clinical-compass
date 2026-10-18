<?php

namespace App\Enums;

enum UserRole: string
{
    case Doctor = 'doctor';
    case Patient = 'patient';

    /**
     * Имя маршрута кабинета роли.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Doctor => 'doctor.home',
            self::Patient => 'patient.home',
        };
    }
}
