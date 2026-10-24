<?php

namespace App\Actions;

use App\Events\AlertsChanged;
use App\Events\PatientSignalsChanged;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Психиатр принимает пациента по направлению: связь с терапевтом закрывается, с психиатром
 * открывается. Вся история пациента остаётся при нём, открытые алерты переходят к психиатру
 * вместе с карточкой.
 */
class AcceptReferral
{
    /**
     * @return bool false, если направление уже принято или отозвано
     */
    public function handle(User $psychiatrist, Referral $referral): bool
    {
        return DB::transaction(function () use ($psychiatrist, $referral): bool {
            // Пациент блокируется первым, как в остальных изменениях его данных.
            User::query()->whereKey($referral->patient_id)->lockForUpdate()->firstOrFail();
            $referral = Referral::query()->with(['patient', 'fromDoctor'])->lockForUpdate()->findOrFail($referral->id);

            if ($referral->to_doctor_id !== $psychiatrist->id) {
                throw new InvalidArgumentException('Принять пациента может только психиатр, к которому он направлен.');
            }

            if (! $referral->isOpen()) {
                return false;
            }

            $now = CarbonImmutable::now();

            CareRelationship::query()->active()
                ->where('patient_id', $referral->patient_id)
                ->update(['ended_at' => $now]);

            CareRelationship::query()->create([
                'doctor_id' => $psychiatrist->id,
                'patient_id' => $referral->patient_id,
                'started_at' => $now,
            ]);

            $referral->update(['accepted_at' => $now]);

            AuditLog::record($psychiatrist, 'referral.accepted', $referral->patient, $referral, null, [
                'category' => $referral->category->value,
                'from_doctor_id' => $referral->from_doctor_id,
            ]);

            foreach ([$psychiatrist, $referral->fromDoctor] as $doctor) {
                PatientSignalsChanged::dispatch($doctor);
                AlertsChanged::dispatch($doctor);
            }

            return true;
        });
    }
}
