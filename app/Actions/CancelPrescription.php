<?php

namespace App\Actions;

use App\Actions\Concerns\WritesPrescriptions;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionCloseReason;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Врач отменяет назначение с этого момента, в том числе ещё не начавшееся.
 */
class CancelPrescription
{
    use WritesPrescriptions;

    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @throws PrescriptionNotChangeable
     */
    public function handle(User $doctor, Prescription $prescription, ?PrescriptionCancelReason $reason, ?string $comment): void
    {
        $this->ensureDoctor($doctor);

        DB::transaction(function () use ($doctor, $prescription, $reason, $comment): void {
            $patient = $this->lockPatient($prescription->patient_id);

            $this->lockChangeable($prescription, $patient->localToday())->update([
                'closed_at' => now(),
                'closed_by_id' => $doctor->id,
                'close_reason' => PrescriptionCloseReason::Cancelled,
                'cancel_reason' => $reason,
                'cancel_comment' => $comment,
            ]);

            // Будущие неотмеченные приёмы отменённого назначения удаляются.
            $this->syncDoseEvents->handle($patient);
        });
    }
}
