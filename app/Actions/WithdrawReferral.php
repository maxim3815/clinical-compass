<?php

namespace App\Actions;

use App\Events\PatientSignalsChanged;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Терапевт отзывает направление, пока психиатр не принял пациента.
 */
class WithdrawReferral
{
    /**
     * @return bool false, если направление уже принято или отозвано
     */
    public function handle(User $therapist, Referral $referral): bool
    {
        return DB::transaction(function () use ($therapist, $referral): bool {
            $referral = Referral::query()->with(['patient', 'toDoctor'])->lockForUpdate()->findOrFail($referral->id);

            if ($referral->from_doctor_id !== $therapist->id) {
                throw new InvalidArgumentException('Отозвать направление может только терапевт, который его выписал.');
            }

            if (! $referral->isOpen()) {
                return false;
            }

            $referral->update(['withdrawn_at' => CarbonImmutable::now()]);

            AuditLog::record($therapist, 'referral.withdrawn', $referral->patient, $referral, null, [
                'category' => $referral->category->value,
                'to_doctor_id' => $referral->to_doctor_id,
            ]);

            PatientSignalsChanged::dispatch($referral->toDoctor);

            return true;
        });
    }
}
