<?php

namespace App\Console\Commands;

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionSource;
use App\Events\AdherenceSignalsChanged;
use App\Models\CareRelationship;
use App\Models\DoseEvent;
use App\Models\User;
use App\Notifications\MissedDoses;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MarkMissedDoseEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:dose-events:mark-missed';

    /**
     * @var string
     */
    protected $description = 'Отметить пропущенными плановые приёмы, у которых закрылось окно';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Тем же запросом узнаём, чьи приёмы стали пропущенными и по каким назначениям.
        $missed = collect(DB::select(<<<'SQL'
            WITH missed AS (
                UPDATE dose_events
                SET status = ?, updated_at = ?
                WHERE status = ? AND window_closes_at <= ?
                RETURNING id, patient_id, prescription_id
            )
            SELECT missed.id, missed.patient_id, prescriptions.source
            FROM missed
            JOIN prescriptions ON prescriptions.id = missed.prescription_id
            SQL, [DoseEventStatus::Missed->value, $now, DoseEventStatus::Scheduled->value, $now]));

        // Пропуски по назначениям врача меняют сигнал о приверженности: одно событие на врача,
        // сколько бы его пациентов ни пропустили приём.
        $patientIds = $missed->where('source', PrescriptionSource::Doctor->value)->pluck('patient_id')->unique();

        if ($patientIds->isNotEmpty()) {
            User::query()
                ->whereIn('id', CareRelationship::query()->active()->whereIn('patient_id', $patientIds)->select('doctor_id'))
                ->get()
                ->each(fn (User $doctor) => AdherenceSignalsChanged::dispatch($doctor));
        }

        $this->notifyPatients($missed->pluck('id')->all(), $now);

        $this->components->info("Пропущенных приёмов: {$missed->count()}.");

        return self::SUCCESS;
    }

    /**
     * Пуш с советом, что делать при пропуске дозы: один на приёмы пациента на одно время.
     * Если окно закрылось давно (планировщик стоял), совет уже опоздал и не отправляется.
     *
     * @param  array<int, int>  $ids
     */
    private function notifyPatients(array $ids, CarbonImmutable $now): void
    {
        if ($ids === []) {
            return;
        }

        DoseEvent::query()
            ->whereKey($ids)
            ->where('window_closes_at', '>', $now->subMinutes(config()->integer('medications.reminders.late_minutes')))
            ->whereHas('patient', fn (Builder $query) => $query->has('pushSubscriptions'))
            ->with(['patient.patientProfile', 'prescription.drug', 'prescription.strength'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DoseEvent $event): string => $event->patient_id.'@'.$event->scheduled_at->getTimestamp())
            ->each(fn (Collection $doses) => $doses->firstOrFail()->patient->notify(new MissedDoses($doses)));
    }
}
