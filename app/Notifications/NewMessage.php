<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\Message;
use App\Models\User;
use App\Support\PersonName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Пуш собеседнику о новом сообщении. Уходит с задержкой и только если сообщение так и не
 * прочитали: собеседник, который видит открытый чат, читает его сразу и пуша не получает.
 */
class NewMessage extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Сколько ждать прочтения в открытом чате.
     */
    public const int DELAY_SECONDS = 15;

    /**
     * Сколько символов сообщения видно в пуше.
     */
    public const int PREVIEW_LENGTH = 100;

    public function __construct(public Message $message)
    {
        $this->afterCommit();
        $this->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Сообщение прочитали, пока пуш ждал, или связь с врачом закончилась.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        $message = $this->message->fresh(['careRelationship']);

        return $message !== null && $message->read_at === null && $message->careRelationship->ended_at === null;
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $careRelationship = $this->message->careRelationship;
        $sender = $this->message->sender;
        $toPatient = $notifiable->role === UserRole::Patient;

        return (new WebPushMessage)
            ->title($toPatient ? 'Врач '.PersonName::short($sender->name) : $sender->name)
            ->body(Str::limit($this->message->body, self::PREVIEW_LENGTH))
            ->icon('/pwa-192x192.png')
            ->badge('/notification-badge.png')
            // Сообщения одного диалога сменяют друг друга в одном пуше.
            ->tag('chat-'.$careRelationship->id)
            ->renotify()
            ->data(['url' => $toPatient
                ? route('patient.chat.show', absolute: false)
                : route('doctor.patients.chat.show', $careRelationship->patient_id, absolute: false)])
            ->options([
                'TTL' => 24 * 60 * 60,
                'urgency' => 'normal',
                'topic' => 'chat-'.$careRelationship->id,
            ]);
    }
}
