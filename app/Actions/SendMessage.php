<?php

namespace App\Actions;

use App\Events\MessageSent;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use InvalidArgumentException;

/**
 * Участник действующей связи пишет собеседнику. Текст уже проверен: без пробелов по краям,
 * не пустой и не длиннее Message::MAX_LENGTH.
 */
class SendMessage
{
    public function handle(CareRelationship $careRelationship, User $sender, string $body): Message
    {
        if ($careRelationship->ended_at !== null || ! $careRelationship->involves($sender)) {
            throw new InvalidArgumentException('Писать может только участник действующей связи.');
        }

        $message = $careRelationship->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $recipient = $careRelationship->doctor_id === $sender->id ? $careRelationship->patient : $careRelationship->doctor;

        MessageSent::dispatch($message);
        UnreadMessagesChanged::dispatch($recipient);

        return $message;
    }
}
