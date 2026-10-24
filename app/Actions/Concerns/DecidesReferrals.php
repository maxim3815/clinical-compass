<?php

namespace App\Actions\Concerns;

use App\Enums\ReferralCategory;
use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\Referral;
use App\Models\User;
use App\Support\Referrals\ReferralSuggestion;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Общее для решения терапевта по пациенту: решает только его лечащий терапевт, под блокировкой
 * пациента, и по той подсказке, которую он видел. Если подсказка изменилась, пока терапевт решал
 * (пациент отправил опросники), решение не сохраняется.
 */
trait DecidesReferrals
{
    private function ensureTherapistOf(User $therapist, User $patient): void
    {
        if (! $therapist->isTherapist()) {
            throw new InvalidArgumentException('Направляет к психиатру терапевт.');
        }

        if ($patient->role !== UserRole::Patient) {
            throw new InvalidArgumentException('Направляют пациента.');
        }

        $treats = CareRelationship::query()->active()
            ->where('doctor_id', $therapist->id)
            ->where('patient_id', $patient->id)
            ->exists();

        if (! $treats) {
            throw new InvalidArgumentException('Направляет только лечащий терапевт пациента.');
        }
    }

    /**
     * Вызывать внутри транзакции, под блокировкой пациента.
     *
     * @throws ValidationException
     */
    private function ensureNoOpenReferral(User $patient): void
    {
        if (Referral::query()->open()->where('patient_id', $patient->id)->exists()) {
            throw ValidationException::withMessages([
                'category' => 'У пациента уже есть направление, которое ждёт психиатра. Карточка обновлена.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureSameSuggestion(ReferralSuggestion $suggestion, ?ReferralCategory $seen): void
    {
        if ($suggestion->category !== $seen) {
            $now = $suggestion->category === null ? 'направление не подсказывается' : "подсказка «{$suggestion->category->label()}»";

            throw ValidationException::withMessages([
                'category' => "Пока вы решали, данные пациента изменились: теперь {$now}. Карточка обновлена, проверьте решение.",
            ]);
        }
    }
}
