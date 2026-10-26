<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\AdverseEventSeverity;
use App\Enums\AlertDecision;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionSource;
use App\Events\PatientSignalsChanged;
use App\Http\Controllers\Controller;
use App\Models\AdverseEvent;
use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Adherence\AdherenceSignal;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Adherence\PatientAdherence;
use App\Support\AdverseEvents\AdverseEventPresenter;
use App\Support\AdverseEvents\SideEffectCatalog;
use App\Support\Alerts\AlertPresenter;
use App\Support\Alerts\AlertSignals;
use App\Support\Alerts\HelpEventPresenter;
use App\Support\Assessments\PatientAssessments;
use App\Support\AttentionQueue;
use App\Support\DisplayTime;
use App\Support\PrescriptionPresenter;
use App\Support\Referrals\ReferralPresenter;
use App\Support\Safety\ClinicalProfilePresenter;
use App\Support\Safety\SafetyAcknowledgement;
use App\Support\Safety\SafetyPresenter;
use App\Support\Safety\SafetySignals;
use App\Support\UnreadMessages;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public const int PER_PAGE = 20;

    public function index(Request $request): Response|RedirectResponse
    {
        // У терапевта свой кабинет на тех же адресах.
        if ($request->user()?->isTherapist()) {
            return app(TherapistPatientController::class)->index($request);
        }

        $search = trim($request->string('search')->toString());
        // По доле принятых за 7 дней: adherence — сначала самые низкие, -adherence — самые высокие.
        $sort = $request->string('sort')->toString();
        $sort = in_array($sort, ['adherence', '-adherence'], true) ? $sort : null;

        // Список строим от связей, а не от patients(): так дата начала наблюдения
        // приходит типизированной моделью, а не строкой из pivot.
        $patients = CareRelationship::query()
            ->active()
            ->where('doctor_id', $request->user()?->id)
            ->join('users', 'users.id', '=', 'care_relationships.patient_id')
            ->when($search !== '', fn (Builder $query) => $query->whereLike('users.name', '%'.self::escapeLike($search).'%'))
            ->select('care_relationships.*')
            ->selectSub(AdherenceSignals::shareQuery('care_relationships.patient_id'), 'adherence_share')
            // Пациенты без доли — в конце при любом направлении.
            ->when($sort !== null, fn (Builder $query) => $query->orderByRaw('adherence_share '.($sort === 'adherence' ? 'asc' : 'desc').' nulls last'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->with('patient.patientProfile')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var User $doctor */
        $doctor = $request->user();
        $ids = $patients->getCollection()->map(fn (CareRelationship $care): int => $care->patient_id);
        $misses = AdherenceSignals::consecutiveMisses($patients->getCollection()->map(fn (CareRelationship $care): User => $care->patient));
        $awaiting = SafetySignals::awaitingCounts($ids);
        $alerting = array_flip(AlertSignals::patientIds($doctor));
        $unread = UnreadMessages::byPatient($doctor, $ids->all());
        $diagnoses = ClinicalProfilePresenter::mainDiagnoses($ids->all());

        return Inertia::render('doctor/patients/Index', [
            'patients' => $patients->through(fn (CareRelationship $care): array => [
                'id' => $care->patient->id,
                'name' => $care->patient->name,
                'age' => $care->patient->patientProfile?->age(),
                'diagnosis' => $diagnoses[$care->patient_id] ?? null,
                'started_at' => DisplayTime::formatDate($care->started_at),
                'adherence' => (new AdherenceSignal($care->adherence_share, $misses[$care->patient_id] ?? 0))->toArray(),
                // Открытый алерт: врач ещё не закрыл его решением.
                'alert' => isset($alerting[$care->patient_id]),
                // Красные находки проверки безопасности без решения врача.
                'contraindications' => $awaiting[$care->patient_id] ?? 0,
                'unread' => $unread[$care->patient_id] ?? 0,
            ]),
            // Кого врачу посмотреть первым. При поиске врач ищет конкретного пациента, очередь не нужна.
            'attention' => fn (): array => $search === '' ? AttentionQueue::present($doctor) : [],
            'search' => $search,
            'sort' => $sort,
        ]);
    }

    public function show(Request $request, User $patient, SafetyPresenter $safetyPresenter): Response
    {
        if ($request->user()?->isTherapist()) {
            return app(TherapistPatientController::class)->show($request, $patient);
        }

        Gate::authorize('view', $patient);

        /** @var User $doctor */
        $doctor = $request->user();

        $care = CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->where('patient_id', $patient->id)
            ->sole();

        // Врач видит пропуски пациента: счётчик у пункта меню «Пациенты» их больше не считает.
        $care->update(['adherence_seen_at' => CarbonImmutable::now()]);
        PatientSignalsChanged::dispatch($doctor);

        $profile = $patient->patientProfile;
        $today = $patient->localToday();

        [$current, $history] = $patient->prescriptions()
            ->where('source', PrescriptionSource::Doctor)
            ->with(['drug', 'strength', 'intakes', 'prescriber'])
            ->get()
            ->partition(fn (Prescription $prescription): bool => $prescription->status($today)->isCurrent());

        $own = $patient->prescriptions()
            ->current($today)
            ->where('source', PrescriptionSource::Patient)
            ->with(['drug', 'intakes'])
            ->get();

        return Inertia::render('doctor/patients/Show', [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'login' => $patient->login,
                'birth_date' => $profile?->birth_date?->format('d.m.Y'),
                'age' => $profile?->age(),
                'started_at' => DisplayTime::formatDate($care->started_at),
                'timezone' => $profile?->timezone->label(),
            ],
            'prescriptions' => PrescriptionPresenter::presentList($current, $today),
            // Препараты, которые пациент добавил сам: врач их видит, но не меняет.
            'ownMedications' => PrescriptionPresenter::presentList($own, $today),
            // Сначала то, что закрыто или завершилось последним.
            'prescriptionHistory' => $history
                ->sortByDesc(fn (Prescription $prescription): array => [
                    ($prescription->closed_at ?? $prescription->ends_on)?->getTimestamp(),
                    $prescription->id,
                ])
                ->map(fn (Prescription $prescription): array => PrescriptionPresenter::present($prescription, $today))
                ->values(),
            'adherence' => PatientAdherence::present($patient, $today),
            'adherenceSignal' => AdherenceSignals::forPatient($patient)->toArray(),
            // Непрочитанные сообщения пациента: число на кнопке «Написать».
            'unread' => UnreadMessages::byPatient($doctor, [$patient->id])[$patient->id] ?? 0,
            'cancelReasons' => PrescriptionCancelReason::options(),
            'clinicalProfile' => ClinicalProfilePresenter::present($patient),
            // Алерты по ответам пациента и по «Сообщить врачу»: открытые и история, решения врача для закрытия.
            'alerts' => AlertPresenter::forPatient($patient),
            // Тихие строки: когда пациент открывал экран помощи и нажимал на нём звонки.
            'helpEvents' => HelpEventPresenter::forPatient($patient),
            'alertDecisions' => AlertDecision::options(),
            // Побочные эффекты: сообщения пациента и записи врача, сначала новые.
            'adverseEvents' => AdverseEventPresenter::forDoctor($patient),
            // Для записи со слов пациента: весь словарь симптомов и сила.
            'sideEffects' => SideEffectCatalog::dictionary(),
            'severities' => AdverseEventSeverity::options(),
            'adverseEventLimits' => [
                'name' => AdverseEvent::MAX_NAME_LENGTH,
                'comment' => AdverseEvent::MAX_COMMENT_LENGTH,
                'today' => $today->toDateString(),
                'min_date' => $today->subDays(AdverseEventController::MAX_DAYS_AGO)->toDateString(),
            ],
            'alertCommentLimit' => AlertController::MAX_COMMENT_LENGTH,
            // Опросники пациента и шкалы врача (HARS, HADS терапевта): суммы, степени, изменение и ответы.
            'wellbeing' => PatientAssessments::forDoctor($patient, $doctor->doctorKind()),
            // Шкалы, которые психиатр заполняет на приёме: кнопка «Заполнить HARS».
            'doctorScales' => PatientAssessments::ratableScales($doctor->doctorKind()),
            // По какому направлению терапевта пациент пришёл к врачу.
            'referral' => ReferralPresenter::forPsychiatristCard($doctor, $patient),
            // Находки и «Не проверено» по id назначения, у назначений врача и своих препаратов пациента.
            'safety' => (object) $safetyPresenter->card($patient),
            'justificationLimit' => SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH,
        ]);
    }

    /**
     * Символы шаблона LIKE в запросе ищем как обычные символы.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
