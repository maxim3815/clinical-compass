<?php

namespace App\Actions;

use App\Actions\Concerns\WritesOwnMedications;
use App\Enums\PrescriptionCloseReason;
use App\Exceptions\PrescriptionNotChangeable;
use App\Models\AuditLog;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Пациент прекращает приём препарата, который добавил сам, с этого момента. Причина не нужна.
 */
class StopOwnMedication
{
    use WritesOwnMedications;

    public function __construct(
        private readonly SyncDoseEvents $syncDoseEvents,
        private readonly SyncSafetyFindings $syncSafetyFindings,
    ) {}

    /**
     * @throws PrescriptionNotChangeable
     */
    public function handle(User $patient, Prescription $prescription): void
    {
        $this->ensurePatient($patient);

        DB::transaction(function () use ($patient, $prescription): void {
            $patient = $this->lockPatient($patient->id);

            $current = $this->lockChangeable($patient, $prescription, $patient->localToday());
            $before = $current->auditSnapshot();

            $current->update([
                'closed_at' => now(),
                'closed_by_id' => $patient->id,
                'close_reason' => PrescriptionCloseReason::Cancelled,
            ]);

            // Будущие неотмеченные приёмы прекращённого препарата удаляются, его находки закрываются.
            $this->syncDoseEvents->handle($patient);
            $this->syncSafetyFindings->handle($patient);

            AuditLog::record($patient, 'own_medication.stopped', $patient, $current, $before, $current->auditSnapshot());
        });
    }
}
