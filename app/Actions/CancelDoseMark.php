<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Events\AdherenceSignalsChanged;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Пациент отменяет отметку приёма: приём снова запланирован, а если окно уже закрылось — пропущен.
 */
class CancelDoseMark
{
    use MarksDoses;

    public function __construct(private readonly SyncDoseEvents $syncDoseEvents) {}

    /**
     * @throws DoseNotMarkable
     */
    public function handle(User $patient, DoseEvent $dose): void
    {
        DB::transaction(function () use ($patient, $dose): void {
            $patient = $this->lockPatient($patient);
            $dose = $this->lockDoses($patient, [$dose])->sole();

            $this->ensureRecent($patient, $dose->local_date);

            if (! $dose->status->isMarked()) {
                return;
            }

            $dose->update([
                'status' => $dose->unmarkedStatus(CarbonImmutable::now()),
                'taken_at' => null,
                'marked_at' => null,
                'snoozed_until' => null,
                'skip_reason' => null,
                'skip_comment' => null,
            ]);

            // Приём, отмеченный заранее, снова будущий неотмеченный: пока он был отмечен, назначение
            // могли изменить, и тогда его место займёт приём новой версии.
            $this->syncDoseEvents->handle($patient);

            AdherenceSignalsChanged::dispatchForDoctorOf($patient);
        });
    }
}
