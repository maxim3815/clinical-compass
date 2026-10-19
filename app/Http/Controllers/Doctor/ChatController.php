<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\MarkMessagesRead;
use App\Actions\SendMessage;
use App\Http\Controllers\Concerns\ServesChat;
use App\Http\Controllers\Controller;
use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Диалог врача с пациентом, которого он ведёт сейчас.
 */
class ChatController extends Controller
{
    use ServesChat;

    public function show(Request $request, User $patient, MarkMessagesRead $markMessagesRead): Response
    {
        $careRelationship = $this->careRelationship($request, $patient);

        /** @var User $doctor */
        $doctor = $request->user();
        $markMessagesRead->handle($careRelationship, $doctor);

        return Inertia::render('doctor/Chat', [
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
            ],
            'chat' => [
                'id' => $careRelationship->id,
                ...$this->messagesPage($careRelationship),
            ],
        ]);
    }

    public function messages(Request $request, User $patient): JsonResponse
    {
        return $this->earlierMessages($request, $this->careRelationship($request, $patient));
    }

    public function store(Request $request, User $patient, SendMessage $sendMessage): JsonResponse
    {
        return $this->storeMessage($request, $this->careRelationship($request, $patient), $sendMessage);
    }

    public function read(Request $request, User $patient, MarkMessagesRead $markMessagesRead): HttpResponse
    {
        return $this->markRead($request, $this->careRelationship($request, $patient), $markMessagesRead);
    }

    /**
     * Чужой пациент и пациент без действующей связи — 404, как и его карточка.
     */
    private function careRelationship(Request $request, User $patient): CareRelationship
    {
        Gate::authorize('view', $patient);

        /** @var User $doctor */
        $doctor = $request->user();

        return CareRelationship::query()->active()->between($doctor, $patient)->sole();
    }
}
