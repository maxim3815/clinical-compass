<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Счётчик непрочитанного для меню. Диалоги завершённых связей не считаются: их никто не видит.
 */
final class UnreadMessages
{
    /**
     * У пациента — непрочитанные сообщения от лечащего врача, у врача — диалоги, где есть
     * непрочитанные сообщения пациента.
     */
    public static function count(User $user): int
    {
        if ($user->role === UserRole::Doctor) {
            return CareRelationship::query()
                ->active()
                ->where('doctor_id', $user->id)
                ->whereHas('messages', fn (Builder $query) => $query->unreadBy($user))
                ->count();
        }

        return Message::query()
            ->whereIn('care_relationship_id', CareRelationship::query()->active()->where('patient_id', $user->id)->select('id'))
            ->unreadBy($user)
            ->count();
    }
}
