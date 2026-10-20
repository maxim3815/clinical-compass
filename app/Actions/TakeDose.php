<?php

namespace App\Actions;

use App\Actions\Concerns\MarksDoses;
use App\Enums\DoseEventStatus;
use App\Events\AdherenceSignalsChanged;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use App\Support\Plural;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Пациент отмечает плановый приём принятым: сейчас или в указанное время. Прежняя отметка,
 * в том числе «не принял», заменяется.
 */
class TakeDose
{
    use MarksDoses;

    /**
     * @param  string|null  $time  местное время приёма «08:05»; null — сейчас
     *
     * @throws DoseNotMarkable
     * @throws ValidationException время не подходит
     */
    public function handle(User $patient, DoseEvent $dose, ?string $time = null): void
    {
        DB::transaction(function () use ($patient, $dose, $time): void {
            $patient = $this->lockPatient($patient);
            $dose = $this->lockDoses($patient, [$dose])->sole();
            $now = CarbonImmutable::now();

            $this->ensureDue($patient, $dose, $now);

            $dose->update([
                'status' => DoseEventStatus::Taken,
                'taken_at' => $time === null ? $now : $this->takenAt($patient, $dose, $time, $now),
                'marked_at' => $now,
                'skip_reason' => null,
                'skip_comment' => null,
            ]);

            AdherenceSignalsChanged::dispatchForDoctorOf($patient);
        });
    }

    /**
     * Местное время — момент в день приёма, не раньше чем приём можно отметить, и не позже текущего.
     * Если в день приёма такое время не подходит, берётся соседний день: приём в 23:00 приняли
     * в 0:30 следующего дня, а приём в 0:30 — в 23:45 накануне.
     *
     * @throws ValidationException
     */
    private function takenAt(User $patient, DoseEvent $dose, string $time, CarbonImmutable $now): CarbonImmutable
    {
        $candidates = array_map(
            fn (int $days): CarbonImmutable => CarbonImmutable::parse($dose->local_date->addDays($days)->toDateString().' '.$time, $patient->localTimezone())->utc(),
            [0, 1, -1],
        );

        foreach ($candidates as $candidate) {
            if ($candidate->gte($dose->dueFrom()) && $candidate->lte($now)) {
                return $candidate;
            }
        }

        $minutes = config()->integer('medications.early_minutes');

        throw ValidationException::withMessages([
            'time' => $candidates[0]->gt($now)
                ? 'Это время ещё не наступило.'
                : 'Укажите время не раньше '.$dose->dueFrom()->setTimezone($patient->localTimezone())->format('G:i')
                    .': за '.$minutes.' '.Plural::choose($minutes, ['минуту', 'минуты', 'минут']).' до планового.',
        ]);
    }
}
