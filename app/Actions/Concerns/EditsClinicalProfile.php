<?php

namespace App\Actions\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use InvalidArgumentException;

/**
 * Общее для изменений клинического профиля пациента: их вносит только врач, а пациент
 * меняется по очереди с назначениями и синхронизацией находок.
 */
trait EditsClinicalProfile
{
    private function ensureDoctorAndPatient(User $doctor, User $patient): void
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Клинический профиль ведёт только врач.');
        }

        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Клинический профиль есть только у пациента.');
        }
    }

    /**
     * Вызывать внутри транзакции.
     */
    private function lockPatient(User $patient): User
    {
        return User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
    }
}
