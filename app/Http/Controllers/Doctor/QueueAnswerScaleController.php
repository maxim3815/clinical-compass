<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\RateQueueScale;
use App\Http\Controllers\Controller;
use App\Models\QueueAnswer;
use App\Models\Scale;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HADS и признаки по ответам из очереди, до регистрации пациента: та же форма, что в карточке.
 */
class QueueAnswerScaleController extends Controller
{
    public function create(Request $request, QueueAnswer $queueAnswer, Scale $scale): Response|RedirectResponse
    {
        $this->authorizeAnswer($request, $queueAnswer, $scale);

        if ($queueAnswer->attached_at !== null) {
            return to_route('doctor.queue-answers.show', $queueAnswer);
        }

        return Inertia::render('doctor/ScaleForm', [
            'patient' => null,
            'queueAnswer' => ['id' => $queueAnswer->id, 'code' => $queueAnswer->code],
            'scale' => DoctorScaleController::form($scale),
            // Признаки, отмеченные по этим ответам раньше: форма начинает с них.
            'facts' => DoctorScaleController::facts($queueAnswer->facts ?? []),
        ]);
    }

    public function store(Request $request, QueueAnswer $queueAnswer, Scale $scale, RateQueueScale $rateQueueScale, ReferralEngine $engine): RedirectResponse
    {
        $this->authorizeAnswer($request, $queueAnswer, $scale);

        /** @var User $therapist */
        $therapist = $request->user();

        $validated = $request->validate(...DoctorScaleController::rules($scale, withFacts: true));
        $before = $engine->check($queueAnswer->referralData())->category;

        $queueAnswer = $rateQueueScale->handle(
            $therapist,
            $queueAnswer,
            $scale,
            array_map(intval(...), array_values($validated['scores'])),
            array_map(boolval(...), $validated['facts']),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => DoctorScaleController::savedMessage(
            $scale,
            $queueAnswer->doctor_scale['total'] ?? 0,
            $queueAnswer->doctor_scale['band'] ?? '',
            $queueAnswer->doctor_scale['subscores'] ?? null,
            $before,
            $engine->check($queueAnswer->referralData())->category,
        )]);

        return to_route('doctor.queue-answers.show', $queueAnswer);
    }

    private function authorizeAnswer(Request $request, QueueAnswer $queueAnswer, Scale $scale): void
    {
        /** @var User $therapist */
        $therapist = $request->user();

        abort_unless($queueAnswer->doctor_id === $therapist->id && $scale->isRatableBy($therapist->doctorKind()), 404);
        abort_unless($queueAnswer->attached_at !== null || $queueAnswer->isPending(), 404);
    }
}
