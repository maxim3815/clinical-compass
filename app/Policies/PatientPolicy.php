<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\User;

/**
 * Доступ к пациенту и его данным.
 */
class PatientPolicy
{
    /**
     * Врач видит пациента только при действующей связи «лечащий врач», пациент видит только себя.
     */
    public function view(User $user, User $patient): bool
    {
        if ($patient->role !== UserRole::Patient) {
            return false;
        }

        if ($user->role === UserRole::Patient) {
            return $user->is($patient);
        }

        return CareRelationship::query()
            ->active()
            ->where('doctor_id', $user->id)
            ->where('patient_id', $patient->id)
            ->exists();
    }
}
