<?php

namespace App\Actions;

use App\Actions\Concerns\DecidesReferrals;
use App\Actions\Concerns\EditsClinicalProfile;
use App\Enums\ReferralCategory;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Терапевт решил не направлять пациента. Если система подсказывала направление, нужен
 * комментарий. Решение — запись журнала аудита по пациенту: направления не появляется.
 */
class DeclineReferral
{
    use DecidesReferrals, EditsClinicalProfile;

    public function __construct(private readonly ReferralEngine $engine) {}

    /**
     * @param  ReferralCategory|null  $seenSuggestion  подсказка, которую терапевт видел в карточке
     *
     * @throws ValidationException
     */
    public function handle(User $therapist, User $patient, ?string $comment, ?ReferralCategory $seenSuggestion): void
    {
        $this->ensureTherapistOf($therapist, $patient);

        DB::transaction(function () use ($therapist, $patient, $comment, $seenSuggestion): void {
            $patient = $this->lockPatient($patient);
            $this->ensureNoOpenReferral($patient);

            $suggestion = $this->engine->suggest($patient);
            $this->ensureSameSuggestion($suggestion, $seenSuggestion);

            if ($suggestion->category !== null && blank($comment)) {
                throw ValidationException::withMessages([
                    'comment' => "Система подсказывает «{$suggestion->category->label()}»: напишите, почему не направляете.",
                ]);
            }

            AuditLog::record($therapist, 'referral.declined', $patient, $patient, null, [
                'suggested_category' => $suggestion->category?->value,
                'basis' => array_column($suggestion->matches, 'code'),
                'comment' => blank($comment) ? null : $comment,
            ]);
        });
    }
}
