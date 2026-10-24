<?php

namespace App\Http\Controllers\Patient;

use App\Enums\AlertTrigger;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\SideEffectLevel;
use App\Http\Controllers\Controller;
use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AsNeededDose;
use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Adherence\IntakeCalendar;
use App\Support\Assessments\PatientAssessments;
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
        $female = $patient->isFemalePatient();

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
            'hasPrescriptions' => $patient->prescriptions()->current($today)->exists(),
            'today' => [
                'date' => $today->isoFormat('D MMMM'),
                'groups' => $this->groups($todayEvents, $timezone, $today, $now, $female),
            ],
            'yesterday' => [
                'groups' => $this->groups($yesterdayEvents, $timezone, $today, $now, $female),
            ],
            'asNeeded' => $this->asNeeded($patient, $today, $timezone),
            'skipReasons' => DoseSkipReason::options($female),
            // Опросники по календарю: карточка «Пора ответить», когда срок наступил.
            'assessments' => PatientAssessments::status($patient),
            // Карточка с 112 и кризисной линией, пока врач не закрыл алерт по ответам или по «Сообщить
            // врачу». Сам алерт пациент не видит.
            'help' => $patient->alerts()->open()
                ->whereIn('alert_rule_id', AlertRule::query()->whereIn('trigger', [AlertTrigger::Answers, AlertTrigger::HelpRequest])->select('id'))
                ->exists(),
            // Пока открыт алерт по срочному побочному эффекту — своя карточка: о чём сообщил, 112 и чат.
            'sideEffectAlert' => $this->sideEffectAlert($patient),
            // Ссылка «Сообщить о побочном эффекте»: сообщать есть кому.
            'canReportSideEffects' => $doctor !== null,
            // Сегодня не принял из-за самочувствия и ещё не рассказал врачу, что беспокоит.
            'unwellSuggestion' => $doctor !== null && $this->unwellWithoutReport($patient, $today, $timezone),
            // Ссылка на календарь приёма, когда в нём уже есть что показать.
            'hasIntakeHistory' => IntakeCalendar::hasHistory($patient),
        ]);
    }

    /**
     * Открытый алерт по побочным эффектам: о чём пациент сообщил, когда и была ли среди этого
     * «нужна помощь сейчас» в последние часы. Когда всё отмечено «Уже прошло», карточка не нужна.
     *
     * @return array{symptoms: list<string>, at: string, emergency: bool}|null
     */
    private function sideEffectAlert(User $patient): ?array
    {
        $alert = $patient->alerts()->open()
            ->whereIn('alert_rule_id', AlertRule::query()->triggeredBy(AlertTrigger::AdverseEvent)->select('id'))
            ->first();

        if (! $alert instanceof Alert) {
            return null;
        }

        // Только то, что ещё не прошло: прошедшее пациент уже отметил сам.
        $events = AdverseEvent::query()->where('alert_id', $alert->id)->whereNull('resolved_on')->orderBy('id')->get(['name', 'level', 'created_at']);

        if ($events->isEmpty()) {
            return null;
        }

        $timezone = $patient->localTimezone();
        $last = ($alert->repeated_at ?? $alert->submitted_at)->setTimezone($timezone);
        $recent = CarbonImmutable::now()->subHours((int) config('help.reported_hours'));

        return [
            'symptoms' => array_values($events->pluck('name')->unique()->all()),
            'at' => ($last->isSameDay(CarbonImmutable::now($timezone)) ? 'сегодня' : $last->isoFormat('D MMMM'))." в\u{00A0}".$last->format('H:i'),
            // Звонок в 112 главной кнопкой — пока сообщение свежее, а не все дни, пока открыт алерт.
            'emergency' => $events->contains(fn (AdverseEvent $event): bool => $event->level === SideEffectLevel::Emergency
                && $event->created_at->greaterThan($recent)),
        ];
    }

    /**
     * Пациент сегодня отметил «Не принял: плохо себя чувствовал или были побочные эффекты» и сегодня
     * ещё ни о чём не сообщил. Предложение висит до конца дня.
     */
    private function unwellWithoutReport(User $patient, CarbonImmutable $today, string $timezone): bool
    {
        $startOfToday = CarbonImmutable::parse($today->toDateString(), $timezone);

        $skipped = $patient->doseEvents()
            ->where('status', DoseEventStatus::Skipped)
            ->where('skip_reason', DoseSkipReason::Unwell)
            ->where('marked_at', '>=', $startOfToday)
            ->exists();

        return $skipped && ! $patient->adverseEvents()
            ->where('reported_by_id', $patient->id)
            ->where('created_at', '>=', $startOfToday)
            ->exists();
    }

    /**
     * Приёмы по времени: приёмы на одно время — одна группа.
     *
     * @param  Collection<int, DoseEvent>  $events
     * @return array<int, array{time: string, doses: array<int, array<string, mixed>>}>
     */
    private function groups(Collection $events, string $timezone, CarbonImmutable $today, CarbonImmutable $now, bool $female): array
    {
        return $events
            ->groupBy(fn (DoseEvent $event): string => DoseEventPresenter::clock($event->scheduled_at, $timezone))
            ->map(fn (Collection $group, string $time): array => [
                'time' => $time,
                'doses' => $group
                    ->sortBy(fn (DoseEvent $event): string => $event->prescription->drugName())
                    ->map(fn (DoseEvent $event): array => DoseEventPresenter::present($event, $timezone, $today, $now, $female))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Действующие назначения «по необходимости» врача и самого пациента и записанные приёмы за сегодня
     * и вчера. Приёмы считаются по препарату: после изменения назначения счётчик за сутки не обнуляется.
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
            ->groupBy(fn (AsNeededDose $dose): string => $dose->prescription->drugKey());

        return $patient->prescriptions()
            ->current($today)
            ->where('starts_on', '<=', $today->toDateString())
            ->where('kind', PrescriptionKind::AsNeeded)
            ->with(['drug', 'strength'])
            ->get()
            ->sortBy(fn (Prescription $prescription): array => [$prescription->drugName(), $prescription->source->value])
            ->map(function (Prescription $prescription) use ($doses, $today, $timezone): array {
                /** @var Collection<int, AsNeededDose> $taken */
                $taken = $doses->get($prescription->drugKey(), new Collection);

                return [
                    'id' => $prescription->id,
                    'drug' => $prescription->drugName(),
                    'dose' => $prescription->doseLabel($prescription->tablets),
                    'instruction' => $prescription->instruction,
                    // У препарата пациента ограничения может не быть.
                    'max_daily_doses' => $prescription->max_daily_doses,
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
            ->sortBy(fn (Prescription $prescription): array => [$prescription->drugName(), $prescription->starts_on->toDateString()])
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'title' => $prescription->drugName().', '.$prescription->strength?->label(),
            ])
            ->values()
            ->all();
    }
}
