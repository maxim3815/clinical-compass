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

    /**
     * Сколько непрочитанных сообщений врачу от каждого из его пациентов.
     *
     * @param  array<int, int>|null  $patientIds  только эти пациенты; null — все, кого врач ведёт
     * @return array<int, int> id пациента => число; пациента без непрочитанного нет
     */
    public static function byPatient(User $doctor, ?array $patientIds = null): array
    {
        return CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->when($patientIds !== null, fn (Builder $query) => $query->whereIn('patient_id', $patientIds))
            // Как Message::unreadBy(): scope в withCount() не виден статическому анализу.
            ->withCount(['messages as unread_count' => fn (Builder $query) => $query->where('sender_id', '<>', $doctor->id)->whereNull('read_at')])
            ->get()
            ->filter(fn (CareRelationship $care): bool => $care->getAttribute('unread_count') > 0)
            ->mapWithKeys(fn (CareRelationship $care): array => [$care->patient_id => (int) $care->getAttribute('unread_count')])
            ->all();
    }
}
