<?php

namespace App\Actions;

use App\Models\AdverseEvent;
use App\Models\User;

/**
 * Пациент отметил «Уже прошло»: врач видит, сколько длился побочный эффект. Дата — сегодняшняя
 * по часовому поясу пациента, но не раньше начала. Случайную отметку пациент отменяет в тот же день.
 */
class ResolveAdverseEvent
{
    /**
     * @return bool false, если уже отмечено
     */
    public function handle(User $patient, AdverseEvent $event): bool
    {
        if ($event->patient_id !== $patient->id || $event->resolved_on !== null) {
            return false;
        }

        $today = $patient->localToday();

        return $event->update(['resolved_on' => $event->started_on->greaterThan($today) ? $event->started_on : $today]);
    }

    /**
     * Отмена случайного «Уже прошло»: только в тот же день по часовому поясу пациента.
     *
     * @return bool false, если отметки нет или она не сегодняшняя
     */
    public function undo(User $patient, AdverseEvent $event): bool
    {
        if ($event->patient_id !== $patient->id || ! self::undoable($patient, $event)) {
            return false;
        }

        return $event->update(['resolved_on' => null]);
    }

    /**
     * «Уже прошло» отмечено сегодня: его ещё можно отменить.
     */
    public static function undoable(User $patient, AdverseEvent $event): bool
    {
        return $event->resolved_on !== null && $event->resolved_on->equalTo($patient->localToday());
    }
}
