<?php

namespace App\Actions;

use App\Exceptions\PrescriptionNotChangeable;
use App\Models\AsNeededDose;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Plural;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Пациент записывает приём «по необходимости»: сейчас или в указанное время сегодня или вчера.
 * Если за эти сутки препарат уже принят столько раз, сколько разрешил врач, запись нужно подтвердить.
 */
class RecordAsNeededDose
{
    /**
     * @param  string|null  $time  местное время приёма «08:05»; null — сейчас
     * @param  bool  $yesterday  приём был вчера по местной дате пациента, тогда время обязательно
     * @param  bool  $overLimit  пациент подтвердил приём сверх лимита
     *
     * @throws PrescriptionNotChangeable назначение изменено, отменено или закончилось
     * @throws ValidationException
     */
    public function handle(
        User $patient,
        Prescription $prescription,
        ?string $time = null,
        bool $yesterday = false,
        bool $overLimit = false,
    ): AsNeededDose {
        if ($prescription->patient_id !== $patient->id) {
            throw new InvalidArgumentException('Пациент записывает приёмы своих назначений.');
        }

        if ($yesterday && $time === null) {
            throw new InvalidArgumentException('Для вчерашнего приёма нужно время.');
        }

        return DB::transaction(function () use ($patient, $prescription, $time, $yesterday, $overLimit): AsNeededDose {
            // Записи пациента идут по очереди, поэтому лимит за сутки считается без гонок. Изменение
            // назначения тоже начинается с блокировки пациента: здесь видна уже записанная версия.
            $patient = User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $patient->load('patientProfile');
            $current = Prescription::query()->findOrFail($prescription->id);
            $today = $patient->localToday();

            if (! $current->acceptsAsNeededDoses($today)) {
                throw new PrescriptionNotChangeable;
            }

            $date = $yesterday ? $today->subDay() : $today;
            $now = CarbonImmutable::now();

            if ($date->lt($current->starts_on)) {
                throw ValidationException::withMessages(['day' => 'В этот день назначение ещё не действовало.']);
            }

            $takenAt = $time === null ? $now : CarbonImmutable::parse($date->toDateString().' '.$time, $patient->localTimezone())->utc();

            if ($takenAt->gt($now)) {
                throw ValidationException::withMessages(['time' => 'Это время ещё не наступило.']);
            }

            $taken = $patient->asNeededDoses()
                ->where('local_date', $date->toDateString())
                ->whereHas('prescription', fn (Builder $query) => $query->where('drug_id', $current->drug_id))
                ->count();
            $limit = (int) $current->max_daily_doses;

            if ($taken >= $limit && ! $overLimit) {
                throw ValidationException::withMessages([
                    'confirmed' => ($yesterday ? 'Вчера' : 'Сегодня').' вы уже приняли препарат '
                        .$taken.' '.Plural::choose($taken, ['раз', 'раза', 'раз'])
                        .', а врач разрешил не больше '.$limit.' '.Plural::choose($limit, ['раза', 'раз', 'раз']).' в сутки.',
                ]);
            }

            return $patient->asNeededDoses()->create([
                'prescription_id' => $current->id,
                'tablets' => $current->tablets,
                'local_date' => $date->toDateString(),
                'taken_at' => $takenAt,
            ]);
        });
    }
}
