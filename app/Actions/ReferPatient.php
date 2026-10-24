<?php

namespace App\Actions;

use App\Actions\Concerns\DecidesReferrals;
use App\Actions\Concerns\EditsClinicalProfile;
use App\Enums\DoctorKind;
use App\Enums\ReferralCategory;
use App\Events\PatientSignalsChanged;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Терапевт направляет пациента к психиатру. Решает терапевт: категория может расходиться
 * с подсказкой, тогда нужен комментарий. Подсказка и основание сохраняются в направлении
 * на этот момент. Пациента по-прежнему ведёт терапевт, пока психиатр его не примет.
 */
class ReferPatient
{
    use DecidesReferrals, EditsClinicalProfile;

    public function __construct(private readonly ReferralEngine $engine) {}

    /**
     * @param  ReferralCategory|null  $seenSuggestion  подсказка, которую терапевт видел в карточке
     *
     * @throws ValidationException
     */
    public function handle(User $therapist, User $patient, User $psychiatrist, ReferralCategory $category, ?string $comment, ?ReferralCategory $seenSuggestion): Referral
    {
        $this->ensureTherapistOf($therapist, $patient);

        if ($psychiatrist->doctorKind() !== DoctorKind::Psychiatrist) {
            throw new InvalidArgumentException('Направляют к психиатру.');
        }

        return DB::transaction(function () use ($therapist, $patient, $psychiatrist, $category, $comment, $seenSuggestion): Referral {
            $patient = $this->lockPatient($patient);
            $this->ensureNoOpenReferral($patient);

            $suggestion = $this->engine->suggest($patient);
            $this->ensureSameSuggestion($suggestion, $seenSuggestion);

            if ($category !== $suggestion->category && blank($comment)) {
                throw ValidationException::withMessages([
                    'comment' => $suggestion->category === null
                        ? 'Система направление не подсказывает: напишите, почему направляете.'
                        : "Система подсказывает «{$suggestion->category->label()}»: напишите, почему выбрали другую категорию.",
                ]);
            }

            $referral = Referral::query()->create([
                'patient_id' => $patient->id,
                'from_doctor_id' => $therapist->id,
                'to_doctor_id' => $psychiatrist->id,
                'category' => $category,
                'suggested_category' => $suggestion->category,
                'basis' => $suggestion->matches,
                'comment' => blank($comment) ? null : $comment,
            ]);

            AuditLog::record($therapist, 'referral.created', $patient, $referral, null, [
                'category' => $category->value,
                'suggested_category' => $suggestion->category?->value,
                'to_doctor_id' => $psychiatrist->id,
                'basis' => array_column($suggestion->matches, 'code'),
                'comment' => $referral->comment,
            ]);

            // Направление встаёт в очередь психиатра.
            PatientSignalsChanged::dispatch($psychiatrist);

            return $referral;
        });
    }
}
