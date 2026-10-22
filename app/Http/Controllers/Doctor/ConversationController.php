<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Сообщения»: диалоги врача с пациентами, которых он ведёт.
 */
class ConversationController extends Controller
{
    public const int PER_PAGE = 20;

    // Сколько символов последнего сообщения видно в строке списка.
    private const int PREVIEW_LENGTH = 100;

    public function index(Request $request): Response
    {
        /** @var User $doctor */
        $doctor = $request->user();
        $today = DisplayTime::today()->toDateString();

        // Как Message::unreadBy(): scope в withCount() не виден статическому анализу.
        $unread = fn (Builder $query) => $query->where('sender_id', '<>', $doctor->id)->whereNull('read_at');

        // Только диалоги с сообщениями: сначала с непрочитанными, затем по последнему сообщению.
        $conversations = CareRelationship::query()
            ->active()
            ->where('doctor_id', $doctor->id)
            ->whereHas('messages')
            ->withExists(['messages as has_unread' => $unread])
            ->withCount(['messages as unread_count' => $unread])
            ->withMax('messages', 'created_at')
            ->withMax('messages', 'id')
            ->orderByDesc('has_unread')
            // По времени последнего сообщения: id идут в порядке записи, а не переписки.
            ->orderByDesc('messages_max_created_at')
            ->orderByDesc('messages_max_id')
            ->with(['patient', 'latestMessage'])
            ->paginate(self::PER_PAGE)
            ->through(function (CareRelationship $careRelationship) use ($doctor, $today): array {
                /** @var Message $last */
                $last = $careRelationship->latestMessage;
                $sentAt = $last->created_at->setTimezone(DisplayTime::TIMEZONE);

                return [
                    'patient_id' => $careRelationship->patient_id,
                    'name' => $careRelationship->patient->name,
                    'last_message' => Str::limit(Str::squish($last->body), self::PREVIEW_LENGTH),
                    'last_message_mine' => $last->sender_id === $doctor->id,
                    // Сегодняшнее — временем, более раннее — датой.
                    'last_message_at' => $sentAt->toDateString() === $today ? $sentAt->format('H:i') : $sentAt->format('d.m.Y'),
                    'unread_count' => (int) $careRelationship->getAttribute('unread_count'),
                ];
            });

        return Inertia::render('doctor/Conversations', [
            'conversations' => $conversations,
        ]);
    }
}
