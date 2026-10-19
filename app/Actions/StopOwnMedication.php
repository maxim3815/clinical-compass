<?php

namespace App\Actions;

use App\Actions\Concerns\WritesOwnMedications;
use App\Enums\PrescriptionCloseReason;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Пациент прекращает приём препарата, который добавил сам, с этого момента. Причина не нужна.
 */
class StopOwnMedication
{
    use WritesOwnMedications;

    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @throws PrescriptionNotChangeable
     */
    public function handle(User $patient, Prescription $prescription): void
    {
        $this->ensurePatient($patient);

        DB::transaction(function () use ($patient, $prescription): void {
            $patient = $this->lockPatient($patient->id);

            $this->lockChangeable($patient, $prescription, $patient->localToday())->update([
                'closed_at' => now(),
                'closed_by_id' => $patient->id,
                'close_reason' => PrescriptionCloseReason::Cancelled,
            ]);

            // Будущие неотмеченные приёмы прекращённого препарата удаляются.
            $this->syncDoseEvents->handle($patient);
        });
    }
}
