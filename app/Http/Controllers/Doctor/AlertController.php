<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\AcknowledgeAlert;
use App\Actions\CloseAlert;
use App\Enums\AlertDecision;
use App\Enums\AlertShownVia;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Алерт у врача: отметка «показан» из браузера, «Принял» и закрытие решением.
 */
class AlertController extends Controller
{
    public const int MAX_COMMENT_LENGTH = 2000;

    /**
     * Браузер врача показал красную плашку: событием в открытом кабинете или при открытии
     * страницы. Считается первый показ, время — когда запрос пришёл на сервер.
     */
    public function shown(Request $request, User $patient, Alert $alert): Response
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'via' => ['required', Rule::enum(AlertShownVia::class)],
        ]);

        Alert::query()
            ->whereKey($alert->id)
            ->whereNull('shown_at')
            // Строкой: привязка даты в запросе отбросила бы миллисекунды.
            ->update([
                'shown_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.v'),
                'shown_via' => $validated['via'],
            ]);

        return response()->noContent();
    }

    public function acknowledge(Request $request, User $patient, Alert $alert, AcknowledgeAlert $acknowledgeAlert): RedirectResponse
    {
        Gate::authorize('view', $patient);

        /** @var User $doctor */
        $doctor = $request->user();

        Inertia::flash('toast', $acknowledgeAlert->handle($doctor, $alert)
            ? ['type' => 'success', 'message' => 'Алерт принят. Он остаётся в карточке пациента, пока вы не закроете его решением.']
            : ['type' => 'info', 'message' => 'Этот алерт уже принят.']);

        return back();
    }

    public function close(Request $request, User $patient, Alert $alert, CloseAlert $closeAlert): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(AlertDecision::class)],
            'comment' => [
                Rule::requiredIf($request->input('decision') === AlertDecision::NoThreat->value),
                'nullable',
                'string',
                'max:'.self::MAX_COMMENT_LENGTH,
            ],
        ], [
            'decision.required' => 'Выберите решение.',
            'decision' => 'Выберите решение из списка.',
            'comment.required' => 'Напишите, почему угрозы нет.',
            'comment' => 'Комментарий — не больше '.self::MAX_COMMENT_LENGTH.' символов.',
        ]);

        /** @var User $doctor */
        $doctor = $request->user();
        $decision = AlertDecision::from($validated['decision']);
        $closed = $closeAlert->handle($doctor, $alert, $decision, $validated['comment'] ?? null);

        Inertia::flash('toast', $closed
            ? ['type' => 'success', 'message' => 'Алерт закрыт: '.mb_lcfirst($decision->label()).'.']
            : ['type' => 'info', 'message' => 'Этот алерт уже закрыт.']);

        return to_route('doctor.patients.show', $patient);
    }
}
