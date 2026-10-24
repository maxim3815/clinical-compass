<?php

namespace App\Notifications;

use App\Models\QueueAnswer;
use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Пуш терапевту: пациент в очереди ответил на 9-й вопрос PHQ-9 не «совсем нет». Текст нейтральный,
 * как у пуша об алерте: Chrome на Android прячет пуши со словами о самочувствии.
 */
class UrgentQueueAnswer extends Notification
{
    public function __construct(public QueueAnswer $answer) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("Срочно: код {$this->answer->code}")
            ->body('Ответы из очереди требуют внимания')
            ->icon('/pwa-192x192.png')
            ->badge('/notification-badge.png')
            ->tag('queue-answer-'.$this->answer->id)
            ->requireInteraction()
            ->data(['url' => route('doctor.queue-answers.show', $this->answer, absolute: false)])
            ->options([
                'TTL' => QueueAnswer::LIFETIME_HOURS * 60 * 60,
                'urgency' => 'high',
                'topic' => 'queue-answer-'.$this->answer->id,
            ]);
    }
}
