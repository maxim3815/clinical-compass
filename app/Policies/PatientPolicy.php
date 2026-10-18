<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Доступ к пациенту и его данным.
 */
class PatientPolicy
{
    /**
     * Врач видит пациента только при действующей связи «лечащий врач», пациент видит только себя.
     * Отказ выглядит как 404, чтобы по ответу нельзя было узнать, есть ли такой пациент.
     */
    public function view(User $user, User $patient): Response
    {
        return $this->canView($user, $patient) ? Response::allow() : Response::denyAsNotFound();
    }

    private function canView(User $user, User $patient): bool
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
