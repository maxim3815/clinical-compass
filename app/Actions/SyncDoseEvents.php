<?php

namespace App\Actions;

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionKind;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Плановые приёмы пациента на сегодня и завтра по его местной дате. Создаёт недостающие
 * и пересчитывает будущие неотмеченные под текущие назначения, время приёма и часовой пояс.
 * Отмеченные и уже прошедшие приёмы не трогает. Повторный вызов ничего не меняет.
 */
class SyncDoseEvents
{
    private const int MINUTES_PER_DAY = 1440;

    public function handle(User $patient): void
    {
        DB::transaction(function () use ($patient): void {
            // Синхронизации одного пациента идут по очереди и не пересекаются с изменением назначений.
            $patient = User::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $patient->load('patientProfile');

            $now = CarbonImmutable::now();
            $today = $patient->localToday();
            $planned = $this->plan($patient, $today);

            $existing = $patient->doseEvents()
                ->with('prescription')
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query
                        ->where('status', DoseEventStatus::Scheduled)
                        ->where('scheduled_at', '>', $now))
                    ->orWhereIn('local_date', [$today->toDateString(), $today->addDay()->toDateString()]))
                ->get();

            $kept = $existing->reject(fn (DoseEvent $event): bool => $this->isRecalculable($event, $now));

            foreach ($existing->filter(fn (DoseEvent $event): bool => $this->isRecalculable($event, $now)) as $event) {
                $plan = $planned->pull($this->key($event->prescription_id, $event->time_of_day->value, $event->local_date));

                if ($plan === null) {
                    $event->delete();

                    continue;
                }

                // Время, пояс или доза поменялись: приём переезжает, даже если новое время уже прошло.
                $event->update(collect($plan)->only(['tablets', 'scheduled_at', 'window_closes_at'])->all());
                $kept->push($event);
            }

            $rows = $planned
                ->reject(fn (array $plan): bool => $plan['scheduled_at']->lt($plan['not_before']) || $this->isOccupied($kept, $plan))
                ->map(fn (array $plan): array => [
                    ...collect($plan)->except(['not_before', 'drug'])->all(),
                    'patient_id' => $patient->id,
                    'status' => DoseEventStatus::Scheduled->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            DoseEvent::query()->insertOrIgnore($rows);
        });
    }

    /**
     * Будущий неотмеченный приём пересчитывается. Прошедший остаётся: его ещё можно отметить.
     */
    private function isRecalculable(DoseEvent $event, CarbonImmutable $now): bool
    {
        return $event->status === DoseEventStatus::Scheduled && $event->scheduled_at->gt($now);
    }

    /**
     * Приём этого препарата в то же время суток в этот день уже есть у прежней версии назначения:
     * например, пациент отметил его заранее, а затем назначение изменили. Второй не нужен.
     * Назначение врача и препарат, добавленный пациентом, друг друга не заменяют.
     *
     * @param  Collection<int, DoseEvent>  $kept
     * @param  array<string, mixed>  $plan
     */
    private function isOccupied(Collection $kept, array $plan): bool
    {
        return $kept->contains(fn (DoseEvent $event): bool => $this->drug($event->prescription) === $plan['drug']
            && $event->time_of_day->value === $plan['time_of_day']
            && $event->local_date->toDateString() === $plan['local_date']);
    }

    private function drug(Prescription $prescription): string
    {
        return $prescription->source->value.'|'.$prescription->drugKey();
    }

    /**
     * Какие приёмы должны быть сегодня и завтра: по незакрытым регулярным назначениям и приёмам с выбранным временем.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function plan(User $patient, CarbonImmutable $today): Collection
    {
        $timezone = $patient->localTimezone();
        $dates = [$today, $today->addDay()];

        $prescriptions = $patient->prescriptions()
            ->open()
            ->where('kind', PrescriptionKind::Regular)
            ->where('starts_on', '<=', $today->addDay()->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->with(['drug', 'intakes'])
            ->get();

        $planned = collect();

        foreach ($prescriptions as $prescription) {
            $intakes = $prescription->intakes->filter(fn (PrescriptionIntake $intake): bool => $intake->time !== null);
            $dayMinutes = $intakes->map(fn (PrescriptionIntake $intake): int => $this->minutes((string) $intake->time))->all();

            foreach ($intakes as $intake) {
                $windowMinutes = $this->windowMinutes($prescription, $dayMinutes, $this->minutes((string) $intake->time));
                $notBefore = $intake->time_set_at?->max($prescription->effective_at) ?? $prescription->effective_at;

                foreach ($dates as $date) {
                    if ($date->lt($prescription->starts_on) || ($prescription->ends_on !== null && $date->gt($prescription->ends_on))) {
                        continue;
                    }

                    $scheduledAt = CarbonImmutable::parse($date->toDateString().' '.$intake->time, $timezone)->utc();

                    $planned->put($this->key($prescription->id, $intake->time_of_day->value, $date), [
                        'prescription_id' => $prescription->id,
                        'drug' => $this->drug($prescription),
                        'time_of_day' => $intake->time_of_day->value,
                        'tablets' => $intake->tablets,
                        'local_date' => $date->toDateString(),
                        'scheduled_at' => $scheduledAt,
                        'window_closes_at' => $scheduledAt->addMinutes($windowMinutes),
                        // Приём не создаётся раньше, чем пациент выбрал время и начала действовать версия.
                        'not_before' => $notBefore,
                    ]);
                }
            }
        }

        return $planned;
    }

    /**
     * Окно по window_hours препарата из справочника. Иначе половина интервала до следующего приёма назначения
     * (при одном приёме в сутки — до него же завтра), но не больше максимума из конфига
     * и не меньше интервала «вовремя»: приём не должен стать пропущенным раньше, чем опоздавшим.
     *
     * @param  array<int, int>  $dayMinutes  время всех приёмов назначения в минутах от полуночи
     */
    private function windowMinutes(Prescription $prescription, array $dayMinutes, int $minute): int
    {
        // У «другого» препарата пациента справочника нет: окно по умолчанию.
        if ($prescription->drug?->window_hours !== null) {
            return $prescription->drug->window_hours * 60;
        }

        $later = array_filter($dayMinutes, fn (int $other): bool => $other > $minute);
        // Следующего приёма сегодня нет: следующий — первый завтра.
        $next = $later === [] ? min($minute, ...$dayMinutes) + self::MINUTES_PER_DAY : min($later);

        return max(
            config()->integer('medications.on_time_minutes'),
            min(intdiv($next - $minute, 2), config()->integer('medications.default_window_max_hours') * 60),
        );
    }

    /**
     * «08:30» — 510 минут от полуночи.
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private function key(int $prescriptionId, string $timeOfDay, CarbonImmutable $date): string
    {
        return $prescriptionId.'|'.$timeOfDay.'|'.$date->toDateString();
    }
}
