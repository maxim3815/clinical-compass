<?php

namespace App\Http\Controllers\Patient;

use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\AsNeededDose;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Dose;
use App\Support\DoseEventPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Главная пациента — экран «Сегодня».
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $patient->load('patientProfile');

        $doctor = $patient->currentDoctor()->with('doctorProfile')->first();
        $today = $patient->localToday();
        $timezone = $patient->localTimezone();
        $now = CarbonImmutable::now();

        $events = $patient->doseEvents()
            ->whereIn('local_date', [$today->subDay()->toDateString(), $today->toDateString()])
            ->with(['prescription.drug', 'prescription.strength'])
            ->orderBy('scheduled_at')
            ->get();
        [$todayEvents, $yesterdayEvents] = $events->partition(fn (DoseEvent $event): bool => $event->local_date->eq($today));

        // Из вчерашних — неотмеченные и пропущенные, а ещё отмеченные сегодня: отметку можно сразу исправить.
        $startOfToday = CarbonImmutable::parse($today->toDateString(), $timezone);
        $yesterdayEvents = $yesterdayEvents->filter(fn (DoseEvent $event): bool => ! $event->status->isMarked()
            || ($event->marked_at !== null && $event->marked_at->gte($startOfToday)));

        return Inertia::render('patient/Home', [
            'doctor' => $doctor === null ? null : [
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
            'timeSetup' => $this->prescriptionsWithoutTimes($patient, $today),
            'hasPrescriptions' => $patient->prescriptions()
                ->current($today)
                ->where('source', PrescriptionSource::Doctor)
                ->exists(),
            'today' => [
                'date' => $today->isoFormat('D MMMM'),
                'groups' => $this->groups($todayEvents, $timezone, $today, $now),
            ],
            'yesterday' => [
                'groups' => $this->groups($yesterdayEvents, $timezone, $today, $now),
            ],
            'asNeeded' => $this->asNeeded($patient, $today, $timezone),
            'skipReasons' => DoseSkipReason::options(),
        ]);
    }

    /**
     * Приёмы по времени: приёмы на одно время — одна группа.
     *
     * @param  Collection<int, DoseEvent>  $events
     * @return array<int, array{time: string, doses: array<int, array<string, mixed>>}>
     */
    private function groups(Collection $events, string $timezone, CarbonImmutable $today, CarbonImmutable $now): array
    {
        return $events
            ->groupBy(fn (DoseEvent $event): string => DoseEventPresenter::clock($event->scheduled_at, $timezone))
            ->map(fn (Collection $group, string $time): array => [
                'time' => $time,
                'doses' => $group
                    ->sortBy(fn (DoseEvent $event): string => $event->prescription->drug->name)
                    ->map(fn (DoseEvent $event): array => DoseEventPresenter::present($event, $timezone, $today, $now))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Действующие назначения «по необходимости» и записанные приёмы за сегодня и вчера.
     * Приёмы считаются по препарату: после изменения назначения счётчик за сутки не обнуляется.
     *
     * @return array<int, array<string, mixed>>
     */
    private function asNeeded(User $patient, CarbonImmutable $today, string $timezone): array
    {
        $doses = $patient->asNeededDoses()
            ->whereIn('local_date', [$today->subDay()->toDateString(), $today->toDateString()])
            ->with('prescription')
            ->orderByDesc('taken_at')
            ->get()
            ->groupBy(fn (AsNeededDose $dose): int => $dose->prescription->drug_id);

        return $patient->prescriptions()
            ->current($today)
            ->where('starts_on', '<=', $today->toDateString())
            ->where('source', PrescriptionSource::Doctor)
            ->where('kind', PrescriptionKind::AsNeeded)
            ->with(['drug', 'strength'])
            ->get()
            ->sortBy(fn (Prescription $prescription): string => $prescription->drug->name)
            ->map(function (Prescription $prescription) use ($doses, $today, $timezone): array {
                /** @var Collection<int, AsNeededDose> $taken */
                $taken = $doses->get($prescription->drug_id, new Collection);

                return [
                    'id' => $prescription->id,
                    'drug' => $prescription->drug->name,
                    'dose' => Dose::label((string) $prescription->tablets, $prescription->strength->mg),
                    'instruction' => $prescription->instruction,
                    'max_daily_doses' => (int) $prescription->max_daily_doses,
                    'taken_today' => $taken->filter(fn (AsNeededDose $dose): bool => $dose->local_date->eq($today))->count(),
                    'doses' => $taken
                        ->map(fn (AsNeededDose $dose): array => [
                            'id' => $dose->id,
                            'day' => $dose->local_date->eq($today) ? 'Сегодня' : 'Вчера',
                            'time' => DoseEventPresenter::clock($dose->taken_at, $timezone),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Назначения, у которых есть приём без выбранного времени: по ним нет плановых приёмов.
     *
     * @return array<int, array{id: int, title: string}>
     */
    private function prescriptionsWithoutTimes(User $patient, CarbonImmutable $today): array
    {
        return $patient->prescriptions()
            ->current($today)
            ->where('source', PrescriptionSource::Doctor)
            ->where('kind', PrescriptionKind::Regular)
            ->whereHas('intakes', fn (Builder $query) => $query->whereNull('time'))
            ->with(['drug', 'strength'])
            ->get()
            ->sortBy(fn (Prescription $prescription): array => [$prescription->drug->name, $prescription->starts_on->toDateString()])
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'title' => $prescription->drug->name.', '.$prescription->strength->label(),
            ])
            ->values()
            ->all();
    }
}
