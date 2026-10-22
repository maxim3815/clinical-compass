<?php

namespace App\Actions;

use App\Models\Assessment;
use App\Models\Scale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Пациент отправляет ответы на все действующие опросники одним заходом. Каждый опросник —
 * запись с ответами на все вопросы, суммой и степенью; у отправленных вместе одно время отправки.
 */
class SubmitAssessments
{
    /**
     * @param  array<string, list<int>>  $answers  код шкалы => балл на каждый вопрос по порядку
     * @return Collection<int, Assessment>
     */
    public function handle(User $patient, array $answers): Collection
    {
        $scales = Scale::query()->active()->orderBy('position')->get();
        $answers = array_map(array_values(...), $answers);

        if ($scales->isEmpty()) {
            throw new InvalidArgumentException('Опросников для заполнения нет.');
        }

        // Проверка до записи: неполные ответы не сохраняются ни по одному опроснику.
        $totals = $scales->mapWithKeys(fn (Scale $scale): array => [
            $scale->code => $scale->total($answers[$scale->code] ?? []),
        ]);

        return DB::transaction(function () use ($patient, $answers, $scales, $totals): Collection {
            $submittedAt = CarbonImmutable::now();
            $localDate = $patient->localToday();
            $assessments = new Collection;

            foreach ($scales as $scale) {
                $total = $totals[$scale->code];

                $assessment = Assessment::query()->create([
                    'patient_id' => $patient->id,
                    'scale_id' => $scale->id,
                    'scale_version' => $scale->version,
                    'total' => $total,
                    'band' => $scale->band($total),
                    'local_date' => $localDate,
                    'submitted_at' => $submittedAt,
                ]);

                $assessment->answers()->createMany(array_map(
                    fn (int $index, int $score): array => ['item' => $index + 1, 'score' => $score],
                    array_keys($answers[$scale->code]),
                    $answers[$scale->code],
                ));

                $assessments->push($assessment->setRelation('scale', $scale));
            }

            return $assessments;
        });
    }
}
