<?php

namespace App\Http\Controllers\Patient;

use App\Actions\ReportAdverseEvents;
use App\Actions\ResolveAdverseEvent;
use App\Enums\AdverseEventSeverity;
use App\Enums\SideEffectLevel;
use App\Http\Controllers\Controller;
use App\Models\AdverseEvent;
use App\Models\User;
use App\Support\AdverseEvents\AdverseEventPresenter;
use App\Support\AdverseEvents\SideEffectCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Окно «Побочные эффекты»: что пациент сообщал, форма и «Прошло». Срочный симптом даёт алерт
 * врачу, обычный ждёт врача в очереди «Требуют внимания».
 */
class SideEffectController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        return Inertia::render('patient/SideEffects', [
            // Без лечащего врача сообщать некому: формы нет, только 112.
            'canReport' => $patient->activeCareRelationship()->exists(),
            'reports' => AdverseEventPresenter::forPatient($patient),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        if (! $patient->activeCareRelationship()->exists()) {
            return to_route('patient.side-effects.index');
        }

        $today = $patient->localToday();

        return Inertia::render('patient/SideEffectForm', [
            'groups' => SideEffectCatalog::groups(SideEffectCatalog::forPatient($patient)),
            'severities' => AdverseEventSeverity::options(),
            'today' => $today->toDateString(),
            'minDate' => $today->subDays(AdverseEvent::MAX_DAYS_AGO)->toDateString(),
            'maxLength' => AdverseEvent::MAX_NAME_LENGTH,
        ]);
    }

    /**
     * Отправка без подтверждения: пациенту может быть плохо, лишний шаг он бросит.
     */
    public function store(Request $request, ReportAdverseEvents $reportAdverseEvents): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();
        $today = $patient->localToday();
        $catalog = SideEffectCatalog::forPatient($patient);
        // Если отмечено только опасное («нужна помощь сейчас»), пациенту не до вопросов: сила и начало
        // необязательны, по умолчанию — сильно и сегодня. У остального они нужны врачу.
        $chosen = collect((array) $request->input('symptoms', []));
        $emergency = $chosen->isNotEmpty()
            && trim((string) $request->input('other', '')) === ''
            && $chosen->every(fn (mixed $id): bool => is_numeric($id) && ($catalog[(int) $id]['level'] ?? null) === SideEffectLevel::Emergency);

        $validated = $request->validate([
            'symptoms' => ['array', 'required_without:other'],
            'symptoms.*' => ['integer', 'distinct', Rule::in(array_keys($catalog))],
            'other' => ['nullable', 'string', 'max:'.AdverseEvent::MAX_NAME_LENGTH],
            'severity' => [$emergency ? 'nullable' : 'required', Rule::enum(AdverseEventSeverity::class)],
            'started' => [$emergency ? 'nullable' : 'required', Rule::in(['today', 'yesterday', 'earlier'])],
            'started_on' => [
                'exclude_unless:started,earlier',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->subDays(AdverseEvent::MAX_DAYS_AGO)->toDateString(),
                'before_or_equal:'.$today->toDateString(),
            ],
        ], [
            'symptoms.required_without' => 'Выберите, что беспокоит, или напишите своими словами.',
            'symptoms.*' => 'Список изменился. Выберите, что беспокоит, ещё раз.',
            'other.max' => 'Текст длиннее '.AdverseEvent::MAX_NAME_LENGTH.' символов. Сократите его.',
            'severity' => 'Выберите, насколько сильно.',
            'started' => 'Выберите, когда началось.',
            'started_on.required' => 'Укажите дату.',
            'started_on.date_format' => 'Укажите дату.',
            'started_on.after_or_equal' => 'Дата не раньше чем '.AdverseEvent::MAX_DAYS_AGO.' дней назад.',
            'started_on.before_or_equal' => 'Дата не позже сегодняшней.',
        ]);

        $startedOn = match ($validated['started'] ?? 'today') {
            'yesterday' => $today->subDay(),
            'earlier' => CarbonImmutable::parse($validated['started_on'], 'UTC'),
            default => $today,
        };

        $report = $reportAdverseEvents->handle(
            $patient,
            array_values(array_map('intval', $validated['symptoms'] ?? [])),
            $validated['other'] ?? null,
            isset($validated['severity']) ? AdverseEventSeverity::from($validated['severity']) : AdverseEventSeverity::Severe,
            $startedOn,
        );

        // Врача больше нет: окно само скажет, что сообщить некому.
        if ($report === null) {
            return to_route('patient.side-effects.index');
        }

        Inertia::flash('sideEffectReport', [
            'kind' => $report->kind(),
            'at' => CarbonImmutable::now()->setTimezone($patient->localTimezone())->format('H:i'),
            'instructions' => $report->instructions,
        ]);

        return to_route('patient.side-effects.index');
    }

    public function resolve(Request $request, AdverseEvent $adverseEvent, ResolveAdverseEvent $resolveAdverseEvent): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_if($adverseEvent->patient_id !== $patient->id, 404);

        $resolveAdverseEvent->handle($patient, $adverseEvent);

        return to_route('patient.side-effects.index');
    }

    /**
     * «Не прошло»: случайное «Уже прошло» пациент отменяет в тот же день, иначе врач увидит
     * неверную длительность.
     */
    public function unresolve(Request $request, AdverseEvent $adverseEvent, ResolveAdverseEvent $resolveAdverseEvent): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_if($adverseEvent->patient_id !== $patient->id, 404);

        $resolveAdverseEvent->undo($patient, $adverseEvent);

        return to_route('patient.side-effects.index');
    }
}
