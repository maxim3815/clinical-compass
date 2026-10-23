<?php

namespace App\Actions;

use App\Events\MessageSent;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessage;
use InvalidArgumentException;

/**
 * Участник действующей связи пишет собеседнику. Текст уже проверен: без пробелов по краям,
 * не пустой и не длиннее Message::MAX_LENGTH.
 *
 * Ключ отправки приходит от браузера. Повтор с тем же ключом (ответ на первую отправку
 * потерялся) возвращает уже сохранённое сообщение: второго сообщения, события и пуша нет.
 */
class SendMessage
{
    public function handle(CareRelationship $careRelationship, User $sender, string $body, ?string $clientKey = null): Message
    {
        if ($careRelationship->ended_at !== null || ! $careRelationship->involves($sender)) {
            throw new InvalidArgumentException('Писать может только участник действующей связи.');
        }

        $message = $clientKey === null
            ? $careRelationship->messages()->create(['sender_id' => $sender->id, 'body' => $body])
            : $careRelationship->messages()->createOrFirst(
                ['sender_id' => $sender->id, 'client_key' => $clientKey],
                ['body' => $body],
            );

        if (! $message->wasRecentlyCreated) {
            return $message;
        }

        $recipient = $careRelationship->doctor_id === $sender->id ? $careRelationship->patient : $careRelationship->doctor;

        MessageSent::dispatch($message);
        UnreadMessagesChanged::dispatch($recipient);

        // Пуш уйдёт, только если собеседник не прочитает сообщение в открытом чате.
        if ($recipient->pushSubscriptions()->exists()) {
            $recipient->notify(new NewMessage($message));
        }

        return $message;
    }
}
