<?php

namespace App\Console\Commands;

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionSource;
use App\Events\AdherenceSignalsChanged;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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
                RETURNING patient_id, prescription_id
            )
            SELECT missed.patient_id, prescriptions.source
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

        $this->components->info("Пропущенных приёмов: {$missed->count()}.");

        return self::SUCCESS;
    }
}
