<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\DeclineQueueReferral;
use App\Actions\OpenQueueAnswer;
use App\Enums\ReferralCategory;
use App\Http\Controllers\Controller;
use App\Models\QueueAnswer;
use App\Models\Referral;
use App\Models\Scale;
use App\Models\User;
use App\Support\JoinAddress;
use App\Support\QueueAnswers\QueueAnswerPresenter;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ответы из очереди у терапевта: страница кода с ответами, HARS и подсказкой, «Не направлять»
 * и плакат с QR-кодом для двери кабинета.
 */
class QueueAnswerController extends Controller
{
    public function show(Request $request, QueueAnswer $queueAnswer, OpenQueueAnswer $openQueueAnswer, ReferralEngine $engine): Response|RedirectResponse
    {
        /** @var User $therapist */
        $therapist = $request->user();
        abort_unless($queueAnswer->doctor_id === $therapist->id, 404);

        // Пациент зарегистрировался: ответы уже в его карточке. Переход объясняется сообщением.
        if ($queueAnswer->attached_at !== null && $queueAnswer->patient_id !== null) {
            $name = $queueAnswer->patient?->name;
            Inertia::flash('toast', ['type' => 'success', 'message' => "Код {$queueAnswer->code}: пациент зарегистрирован".($name === null ? '' : " ({$name})").', ответы и HARS в карточке.']);

            return to_route('doctor.patients.show', $queueAnswer->patient_id);
        }

        abort_unless($queueAnswer->isPending(), 404);

        $queueAnswer = $openQueueAnswer->handle($therapist, $queueAnswer);
        $suggestion = $engine->check($queueAnswer->referralData());

        return Inertia::render('doctor/queue/Answer', [
            'answer' => QueueAnswerPresenter::present($queueAnswer, $suggestion->category),
            'fillings' => QueueAnswerPresenter::fillings($queueAnswer),
            'suggestion' => $suggestion->toArray(),
            // Шкалы, которые врач заполняет на приёме: кнопка «Заполнить HARS».
            'doctorScales' => Scale::query()->active()->forDoctors()->orderBy('position')->get(['code', 'name'])
                ->map(fn (Scale $scale): array => ['code' => $scale->code, 'name' => $scale->name])
                ->values(),
            'commentLimit' => Referral::MAX_COMMENT_LENGTH,
        ]);
    }

    public function decline(Request $request, QueueAnswer $queueAnswer, DeclineQueueReferral $declineQueueReferral): RedirectResponse
    {
        /** @var User $therapist */
        $therapist = $request->user();
        abort_unless($queueAnswer->doctor_id === $therapist->id, 404);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:'.Referral::MAX_COMMENT_LENGTH],
            'suggested' => ['nullable', Rule::enum(ReferralCategory::class)],
        ], [
            'comment' => 'Комментарий длиннее '.Referral::MAX_COMMENT_LENGTH.' символов. Сократите его.',
        ]);

        $declineQueueReferral->handle(
            $therapist,
            $queueAnswer,
            $validated['comment'] ?? null,
            isset($validated['suggested']) ? ReferralCategory::from($validated['suggested']) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Решение записано: код {$queueAnswer->code} без направления к психиатру."]);

        return back();
    }

    /**
     * Плакат для двери кабинета: QR-код страницы с опросниками и подсказка для пациентов в очереди.
     */
    public function poster(Request $request): Response
    {
        /** @var User $therapist */
        $therapist = $request->user();

        return Inertia::render('doctor/QueuePoster', [
            'doctor' => [
                'name' => $therapist->name,
                'specialty' => $therapist->doctorProfile?->specialty,
            ],
            'address' => JoinAddress::queue($therapist)->toArray(),
        ]);
    }
}
