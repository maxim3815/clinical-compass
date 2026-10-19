<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\MarkMessagesRead;
use App\Actions\SendMessage;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use App\Support\MessagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Чат пациента и врача, общее для обеих сторон: страница отдаёт последние сообщения, а более
 * ранние, отправка и прочтение работают JSON-запросами, чтобы не перезагружать ленту.
 */
trait ServesChat
{
    /**
     * Сообщения по порядку: последние или, если задан before, предшествующие этому сообщению.
     *
     * @return array{messages: array<int, array<string, mixed>>, has_earlier: bool}
     */
    protected function messagesPage(CareRelationship $careRelationship, ?int $before = null): array
    {
        $query = $careRelationship->messages();

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $messages = $query->orderByDesc('id')->limit(Message::PAGE_SIZE + 1)->get();

        return [
            'messages' => $messages
                ->take(Message::PAGE_SIZE)
                ->reverse()
                ->map(fn (Message $message): array => MessagePresenter::present($message))
                ->values()
                ->all(),
            'has_earlier' => $messages->count() > Message::PAGE_SIZE,
        ];
    }

    protected function earlierMessages(Request $request, CareRelationship $careRelationship): JsonResponse
    {
        $validated = $request->validate(['before' => ['required', 'integer', 'min:1']]);

        return response()->json($this->messagesPage($careRelationship, (int) $validated['before']));
    }

    protected function storeMessage(Request $request, CareRelationship $careRelationship, SendMessage $sendMessage): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
        ], [
            'body.required' => 'Напишите сообщение.',
            'body.string' => 'Напишите сообщение.',
            'body.max' => 'Сообщение длиннее '.Message::MAX_LENGTH.' символов. Сократите его или разбейте на несколько.',
        ]);

        $message = $sendMessage->handle($careRelationship, $sender, $validated['body']);

        return response()->json(MessagePresenter::present($message), Response::HTTP_CREATED);
    }

    /**
     * Сообщение пришло в открытый диалог: прочитано всё, что собеседник прислал до up_to включительно.
     */
    protected function markRead(Request $request, CareRelationship $careRelationship, MarkMessagesRead $markMessagesRead): Response
    {
        /** @var User $reader */
        $reader = $request->user();

        $validated = $request->validate(['up_to' => ['required', 'integer', 'min:1']]);

        $markMessagesRead->handle($careRelationship, $reader, (int) $validated['up_to']);

        return response()->noContent();
    }
}
