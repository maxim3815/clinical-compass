<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\AddAdverseEvent;
use App\Actions\CommentAdverseEvent;
use App\Actions\ReviewAdverseEvent;
use App\Enums\AdverseEventSeverity;
use App\Http\Controllers\Controller;
use App\Models\AdverseEvent;
use App\Models\SideEffect;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Нежелательные явления в карточке врача: «Просмотрено», комментарий и запись со слов пациента.
 */
class AdverseEventController extends Controller
{
    /**
     * Насколько давно врач может записать начало со слов пациента.
     */
    public const int MAX_DAYS_AGO = 365;

    public function store(Request $request, User $patient, AddAdverseEvent $addAdverseEvent): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $today = $patient->localToday();
        $validated = $request->validate([
            'symptom_id' => ['nullable', 'required_without:other', 'prohibits:other', 'integer', Rule::exists(SideEffect::class, 'id')->where('is_active', true)],
            'other' => ['nullable', 'string', 'max:'.AdverseEvent::MAX_NAME_LENGTH],
            'severity' => ['required', Rule::enum(AdverseEventSeverity::class)],
            'started_on' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$today->subDays(self::MAX_DAYS_AGO)->toDateString(),
                'before_or_equal:'.$today->toDateString(),
            ],
            'comment' => ['nullable', 'string', 'max:'.AdverseEvent::MAX_COMMENT_LENGTH],
        ], [
            'symptom_id.required_without' => 'Выберите симптом или опишите его.',
            'symptom_id.prohibits' => 'Выберите симптом из списка или опишите свой, одно из двух.',
            'symptom_id' => 'Выберите симптом из списка.',
            'other.max' => 'Описание длиннее '.AdverseEvent::MAX_NAME_LENGTH.' символов. Сократите его.',
            'severity' => 'Выберите, насколько мешает.',
            'started_on.required' => 'Укажите дату начала.',
            'started_on.date_format' => 'Укажите дату начала.',
            'started_on.after_or_equal' => 'Дата начала — не раньше чем год назад.',
            'started_on.before_or_equal' => 'Дата начала не позже сегодняшней.',
            'comment' => 'Комментарий длиннее '.AdverseEvent::MAX_COMMENT_LENGTH.' символов. Сократите его.',
        ]);

        /** @var User $doctor */
        $doctor = $request->user();
        $symptom = isset($validated['symptom_id']) ? SideEffect::query()->findOrFail((int) $validated['symptom_id']) : null;

        $addAdverseEvent->handle(
            $doctor,
            $patient,
            $symptom,
            $validated['other'] ?? null,
            AdverseEventSeverity::from($validated['severity']),
            CarbonImmutable::parse($validated['started_on'], 'UTC'),
            $validated['comment'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Запись добавлена. Пациент увидит её в своём списке побочных эффектов.']);

        return back();
    }

    public function review(Request $request, User $patient, AdverseEvent $adverseEvent, ReviewAdverseEvent $reviewAdverseEvent): RedirectResponse
    {
        Gate::authorize('view', $patient);

        /** @var User $doctor */
        $doctor = $request->user();

        if (! $reviewAdverseEvent->handle($doctor, $adverseEvent)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Эту запись уже просмотрели или она ждёт решения по алерту. Список обновлён.']);
        }

        return back();
    }

    public function comment(Request $request, User $patient, AdverseEvent $adverseEvent, CommentAdverseEvent $commentAdverseEvent): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:'.AdverseEvent::MAX_COMMENT_LENGTH],
        ], [
            'comment' => 'Комментарий длиннее '.AdverseEvent::MAX_COMMENT_LENGTH.' символов. Сократите его.',
        ]);

        /** @var User $doctor */
        $doctor = $request->user();
        $commentAdverseEvent->handle($doctor, $adverseEvent, $validated['comment'] ?? null);

        return back();
    }
}
