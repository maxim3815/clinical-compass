<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\AlertDecision;
use App\Enums\ReferralCategory;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\Referral;
use App\Models\Scale;
use App\Models\User;
use App\Support\Alerts\AlertPresenter;
use App\Support\Alerts\AlertSignals;
use App\Support\Assessments\PatientAssessments;
use App\Support\DisplayTime;
use App\Support\PrescriptionPresenter;
use App\Support\Referrals\ReferralEngine;
use App\Support\Referrals\ReferralPresenter;
use App\Support\Safety\ClinicalProfilePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Кабинет терапевта: список пациентов («Ответили сегодня», «Направлены», все) и карточка отбора
 * со шкалами, HARS, подсказкой и направлением к психиатру. Адреса те же, что у психиатра:
 * PatientController передаёт сюда запросы терапевта.
 */
class TherapistPatientController extends Controller
{
    public function __construct(private readonly ReferralEngine $engine) {}

    public function index(Request $request): Response
    {
        /** @var User $therapist */
        $therapist = $request->user();
        $search = trim($request->string('search')->toString());

        $patients = CareRelationship::query()
            ->active()
            ->where('doctor_id', $therapist->id)
            ->join('users', 'users.id', '=', 'care_relationships.patient_id')
            ->when($search !== '', fn (Builder $query) => $query->whereLike('users.name', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%'))
            ->select('care_relationships.*')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->with('patient.patientProfile')
            ->paginate(PatientController::PER_PAGE)
            ->withQueryString();

        $ids = $patients->getCollection()->map(fn (CareRelationship $care): int => $care->patient_id)->all();
        $alerting = array_flip(AlertSignals::patientIds($therapist));
        $diagnoses = ClinicalProfilePresenter::mainDiagnoses($ids);
        $referred = array_flip(Referral::query()->open()->whereIn('patient_id', $ids)->pluck('patient_id')->map(fn (mixed $id): int => (int) $id)->all());

        return Inertia::render('doctor/therapist/Patients', [
            'patients' => $patients->through(fn (CareRelationship $care): array => [
                'id' => $care->patient->id,
                'name' => $care->patient->name,
                'age' => $care->patient->patientProfile?->age(),
                'diagnosis' => $diagnoses[$care->patient_id] ?? null,
                'started_at' => DisplayTime::formatDate($care->started_at),
                'alert' => isset($alerting[$care->patient_id]),
                'referred' => isset($referred[$care->patient_id]),
            ]),
            // Кто ответил на опросники сегодня: их терапевт смотрит на приёме первыми.
            'answered' => fn (): array => $search === '' ? $this->answeredToday($therapist) : [],
            'referrals' => fn (): array => $search === '' ? ReferralPresenter::sentBy($therapist) : [],
            'search' => $search,
        ]);
    }

    public function show(Request $request, User $patient): Response
    {
        Gate::authorize('view', $patient);

        /** @var User $therapist */
        $therapist = $request->user();

        $care = CareRelationship::query()
            ->active()
            ->where('doctor_id', $therapist->id)
            ->where('patient_id', $patient->id)
            ->sole();

        $profile = $patient->patientProfile;
        $today = $patient->localToday();

        return Inertia::render('doctor/therapist/Patient', [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'login' => $patient->login,
                'birth_date' => $profile?->birth_date?->format('d.m.Y'),
                'age' => $profile?->age(),
                'started_at' => DisplayTime::formatDate($care->started_at),
            ],
            'alerts' => AlertPresenter::forPatient($patient),
            'alertDecisions' => AlertDecision::options(),
            'alertCommentLimit' => AlertController::MAX_COMMENT_LENGTH,
            'wellbeing' => PatientAssessments::forDoctor($patient),
            // Шкалы, которые врач заполняет на приёме: кнопка «Заполнить HARS».
            'doctorScales' => Scale::query()->active()->forDoctors()->orderBy('position')->get(['code', 'name'])
                ->map(fn (Scale $scale): array => ['code' => $scale->code, 'name' => $scale->name])
                ->values(),
            'suggestion' => $this->engine->suggest($patient)->toArray(),
            ...ReferralPresenter::forTherapistCard($therapist, $patient),
            'psychiatrists' => ReferralPresenter::psychiatrists(),
            'categories' => ReferralCategory::options(),
            'commentLimit' => Referral::MAX_COMMENT_LENGTH,
            'clinicalProfile' => ClinicalProfilePresenter::present($patient),
            'medications' => PrescriptionPresenter::presentList(
                $patient->prescriptions()->current($today)->with(['drug', 'strength', 'intakes', 'prescriber'])->get(),
                $today,
            ),
        ]);
    }

    /**
     * Пациенты терапевта, которые сегодня отправили опросники: суммы, ответ на 9-й вопрос PHQ-9
     * и подсказка направления. Сначала ответившие последними.
     *
     * @return list<array<string, mixed>>
     */
    private function answeredToday(User $therapist): array
    {
        $since = CarbonImmutable::parse(DisplayTime::today()->toDateString(), DisplayTime::TIMEZONE)->utc();

        /** @var Collection<int, Assessment> $assessments */
        $assessments = Assessment::query()
            ->whereNull('rated_by_id')
            ->where('submitted_at', '>=', $since)
            ->whereIn('patient_id', CareRelationship::query()->active()->where('doctor_id', $therapist->id)->select('patient_id'))
            ->with(['scale', 'answers', 'patient.patientProfile'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        $alerting = array_flip(AlertSignals::patientIds($therapist));
        $patientIds = $assessments->pluck('patient_id')->unique()->values()->all();
        $referred = Referral::query()->open()->whereIn('patient_id', $patientIds)->pluck('category', 'patient_id')->all();
        // «Не направлять» после последних ответов: пациент разобран.
        $declined = AuditLog::query()
            ->where('action', 'referral.declined')
            ->where('actor_id', $therapist->id)
            ->whereIn('patient_id', $patientIds)
            ->where('created_at', '>=', $since)
            ->get(['patient_id', 'created_at'])
            ->groupBy('patient_id')
            ->map(fn (Collection $records): CarbonImmutable => $records->max('created_at'))
            ->all();

        return array_values($assessments
            ->groupBy('patient_id')
            ->map(function (Collection $own) use ($alerting, $referred, $declined): array {
                /** @var Assessment $last */
                $last = $own->last();
                // Последнее заполнение каждой шкалы за сегодня.
                $latest = $own->keyBy(fn (Assessment $assessment): string => $assessment->scale->code)
                    ->sortBy(fn (Assessment $assessment): int => $assessment->scale->position);
                $patient = $last->patient;
                $suggestion = $this->engine->suggest($patient);
                $category = $referred[$patient->id] ?? null;
                $declinedAt = $declined[$patient->id] ?? null;

                return [
                    'id' => $patient->id,
                    'name' => $patient->name,
                    'age' => $patient->patientProfile?->age(),
                    'at' => $last->submitted_at->setTimezone(DisplayTime::TIMEZONE)->format('H:i'),
                    'timestamp' => $last->submitted_at->getTimestamp(),
                    'scales' => $latest
                        ->map(fn (Assessment $assessment): array => [
                            'name' => $assessment->scale->name,
                            'total' => $assessment->total,
                            'max' => $assessment->scale->maxTotal(),
                            'band' => $assessment->band,
                        ])
                        ->values()
                        ->all(),
                    // Ответ на отмеченный вопрос (9-й PHQ-9): мысли о смерти.
                    'highlight' => $latest->contains(fn (Assessment $assessment): bool => $assessment->answers->contains(
                        fn (AssessmentAnswer $answer): bool => ($assessment->scale->items[$answer->item - 1]['highlight'] ?? false) && $answer->score > 0,
                    )),
                    'alert' => isset($alerting[$patient->id]),
                    'suggestion' => $suggestion->category?->value,
                    // Без HARS подсказка может быть ниже, чем станет на приёме.
                    'scales_missing' => array_column($suggestion->missing, 'name'),
                    // Решение терапевта: направлен (категория) или «не направлять» после этих ответов.
                    'decision' => match (true) {
                        $category !== null => ['kind' => 'referred', 'category' => $category instanceof ReferralCategory ? $category->value : (string) $category],
                        $declinedAt !== null && $declinedAt->gte($last->submitted_at) => ['kind' => 'declined', 'category' => null],
                        default => null,
                    },
                ];
            })
            // Сначала неразобранные, внутри — ответившие последними.
            ->sortBy(fn (array $row): array => [$row['decision'] === null ? 0 : 1, -$row['timestamp']])
            ->map(fn (array $row): array => array_diff_key($row, ['timestamp' => true]))
            ->values()
            ->all());
    }
}
