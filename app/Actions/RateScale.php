<?php

namespace App\Actions;

use App\Actions\Concerns\EditsClinicalProfile;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ObservationType;
use App\Models\Scale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Врач заполняет шкалу на приёме: психиатр — HARS, терапевт — HADS. Заполнение — такая же
 * неизменяемая запись, как опросник пациента, с врачом, который его заполнил. Терапевт вместе
 * с HADS отмечает признаки, которых нет ни в одной шкале: они записываются фактами клинического профиля.
 */
class RateScale
{
    use EditsClinicalProfile;

    public function __construct(private readonly RecordObservation $recordObservation) {}

    /**
     * @param  list<int>  $scores  балл на каждый пункт по порядку
     * @param  array<string, bool>  $facts  код вида факта => значение
     */
    public function handle(User $doctor, User $patient, Scale $scale, array $scores, array $facts = []): Assessment
    {
        $this->ensureDoctorAndPatient($doctor, $patient);

        if (! $scale->isRatableBy($doctor->doctorKind())) {
            throw new InvalidArgumentException("{$scale->name}: врач заполняет только действующие шкалы врача своего вида.");
        }

        $result = $scale->score($scores);
        $types = ObservationType::query()->whereIn('code', array_keys($facts))->get()->keyBy('code');

        if ($types->count() !== count($facts)) {
            throw new InvalidArgumentException('Неизвестный вид факта: '.implode(', ', array_diff(array_keys($facts), $types->keys()->all())).'.');
        }

        return DB::transaction(function () use ($doctor, $patient, $scale, $scores, $result, $facts, $types): Assessment {
            $patient = $this->lockPatient($patient);
            $today = $patient->localToday();

            $assessment = Assessment::query()->create([
                'patient_id' => $patient->id,
                'scale_id' => $scale->id,
                'scale_version' => $scale->version,
                'total' => $result['total'],
                'band' => $result['band'],
                'subscores' => $result['subscores'],
                'local_date' => $today,
                'submitted_at' => CarbonImmutable::now(),
                'rated_by_id' => $doctor->id,
            ]);

            $assessment->answers()->createMany(array_map(
                fn (int $index, int $score): array => ['item' => $index + 1, 'score' => $score],
                array_keys($scores),
                $scores,
            ));

            foreach ($facts as $code => $value) {
                $this->recordObservation->handle($doctor, $patient, $types[$code], $value, null, $today);
            }

            AuditLog::record($doctor, 'assessment.rated', $patient, $assessment, null, [
                'scale' => $scale->code,
                'total' => $result['total'],
                'band' => $assessment->band,
                'subscores' => $result['subscores'],
                'scores' => $scores,
                'facts' => $facts,
            ]);

            return $assessment->setRelation('scale', $scale);
        });
    }
}
