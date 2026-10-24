<?php

namespace App\Http\Controllers\Patient;

use App\Actions\MarkMessagesRead;
use App\Actions\SendMessage;
use App\Http\Controllers\Concerns\ServesChat;
use App\Http\Controllers\Controller;
use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Чат с врачом»: пациент видит только диалог с текущим лечащим врачом.
 */
class ChatController extends Controller
{
    use ServesChat;

    public function show(Request $request, MarkMessagesRead $markMessagesRead): Response|RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        // Терапевт только отбирает и направляет: чат появится у психиатра.
        if ($patient->isWithTherapist()) {
            return to_route('patient.home');
        }

        $careRelationship = $patient->activeCareRelationship()->with('doctor.doctorProfile')->first();

        if ($careRelationship !== null) {
            $markMessagesRead->handle($careRelationship, $patient);
        }

        return Inertia::render('patient/Chat', [
            // Без лечащего врача писать некому.
            'chat' => $careRelationship === null ? null : [
                'id' => $careRelationship->id,
                'doctor' => [
                    'name' => $careRelationship->doctor->name,
                    'specialty' => $careRelationship->doctor->doctorProfile?->specialty,
                ],
                ...$this->messagesPage($careRelationship),
            ],
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        return $this->earlierMessages($request, $this->careRelationship($request));
    }

    public function store(Request $request, SendMessage $sendMessage): JsonResponse
    {
        return $this->storeMessage($request, $this->careRelationship($request), $sendMessage);
    }

    public function read(Request $request, MarkMessagesRead $markMessagesRead): HttpResponse
    {
        return $this->markRead($request, $this->careRelationship($request), $markMessagesRead);
    }

    /**
     * Связь пропадает, когда врач завершил наблюдение: диалога больше нет.
     */
    private function careRelationship(Request $request): CareRelationship
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_if($patient->isWithTherapist(), 404);

        return $patient->activeCareRelationship()->firstOrFail();
    }
}
