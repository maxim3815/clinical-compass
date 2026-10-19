<?php

namespace App\Broadcasting;

use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Канал диалога: новые сообщения и прочтение. Подписаться могут только врач и пациент связи,
 * пока она действует.
 */
class ChatChannel
{
    public function join(User $user, string $careRelationshipId): bool
    {
        // Без проверки нечисловой id дошёл бы до PostgreSQL и дал 500.
        if (! ctype_digit($careRelationshipId)) {
            return false;
        }

        return CareRelationship::query()
            ->active()
            ->whereKey($careRelationshipId)
            ->where(fn (Builder $query) => $query->where('doctor_id', $user->id)->orWhere('patient_id', $user->id))
            ->exists();
    }
}
