<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Events\PatientSignalsChanged;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\MoodMark;
use App\Models\User;
use App\Support\DoseEventPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Пациент отменяет отметку приёма: приём снова запланирован, а если окно уже закрылось — пропущен.
 * Если в это время приёма отметок больше нет, уходит и лицо настроения: лица пропали с карточки,
 * и врач не должен видеть настроение «после приёма», которого нет.
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

            $this->forgetMood($patient, $dose);

            // Приём, отмеченный заранее, снова будущий неотмеченный: пока он был отмечен, назначение
            // могли изменить, и тогда его место займёт приём новой версии.
            $this->syncDoseEvents->handle($patient);

            PatientSignalsChanged::dispatchForDoctorOf($patient);
        });
    }

    private function forgetMood(User $patient, DoseEvent $dose): void
    {
        $timezone = $patient->localTimezone();
        $slot = DoseEventPresenter::clock($dose->scheduled_at, $timezone);
        $stillMarked = $patient->doseEvents()
            ->where('local_date', $dose->local_date->toDateString())
            ->get()
            ->contains(fn (DoseEvent $event): bool => $event->status->isMarked()
                && DoseEventPresenter::clock($event->scheduled_at, $timezone) === $slot);

        if (! $stillMarked) {
            MoodMark::query()
                ->where('patient_id', $patient->id)
                ->where('local_date', $dose->local_date->toDateString())
                ->where('slot', $slot)
                ->delete();
        }
    }
}
