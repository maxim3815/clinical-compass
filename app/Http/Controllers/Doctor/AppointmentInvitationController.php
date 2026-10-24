<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\IssueAppointmentPin;
use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\QueueAnswer;
use App\Models\User;
use App\Support\JoinAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Приглашение на приёме: PIN для окна врача и карточка с QR для печати.
 */
class AppointmentInvitationController extends Controller
{
    /**
     * Новый PIN. Ответ JSON: окно показывает код, не перезагружая страницу под собой. Со страницы
     * ответов из очереди PIN привязывается к ним.
     */
    public function store(Request $request, IssueAppointmentPin $issueAppointmentPin): JsonResponse
    {
        /** @var User $doctor */
        $doctor = $request->user();

        $validated = $request->validate([
            'queue_answer_id' => ['nullable', 'integer'],
        ]);

        $queueAnswer = isset($validated['queue_answer_id'])
            ? QueueAnswer::query()->pending()->where('doctor_id', $doctor->id)->whereKey($validated['queue_answer_id'])->first()
            : null;

        // Ответы привязали к пациенту или они истекли, пока окно открывалось: страница обновится.
        abort_if(isset($validated['queue_answer_id']) && $queueAnswer === null, 409);

        $issued = $issueAppointmentPin->handle($doctor, $queueAnswer);

        return response()->json([
            'id' => $issued->link->id,
            'pin' => $issued->pin,
            'expires_in' => IssueAppointmentPin::LIFETIME_MINUTES * 60,
            'address' => JoinAddress::of($doctor)->toArray(),
        ], 201);
    }

    /**
     * Что сейчас с выданным PIN: окно, открытое снова на другой странице, показывает тот же
     * код, только если его ещё никто не ввёл и он не истёк.
     */
    public function show(AccessLink $invitation): JsonResponse
    {
        Gate::authorize('revoke', $invitation);

        $patient = $invitation->used_at !== null ? $invitation->patient : null;

        return response()->json([
            'id' => $invitation->id,
            'stage' => match (true) {
                $patient !== null => 'registered',
                $invitation->pin_entered_at !== null => 'entered',
                $invitation->isActive() => 'waiting',
                default => 'closed',
            },
            'patient' => $patient !== null ? ['id' => $patient->id, 'name' => $patient->name] : null,
            'expires_in' => $invitation->pin_entered_at === null && $invitation->isActive()
                ? (int) ceil(max(0, now()->diffInSeconds($invitation->expires_at)))
                : 0,
        ]);
    }

    public function card(Request $request): Response
    {
        /** @var User $doctor */
        $doctor = $request->user();

        return Inertia::render('doctor/JoinCard', [
            'doctor' => [
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
            'address' => JoinAddress::of($doctor)->toArray(),
        ]);
    }
}
