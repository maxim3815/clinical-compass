<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\User;
use App\Support\PersonName;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Пуш лечащему врачу о новом алерте. Один, без повторов. Уходит сразу из задачи проверки
 * правил, а не отдельной задачей очереди: так на шаг меньше до телефона врача.
 *
 * Текст нейтральный, без слов о самочувствии: Chrome на Android прячет такие пуши как
 * «Подозрение на спам», и уведомление видно на заблокированном экране. Что сработало,
 * врач видит в карточке пациента, куда ведёт нажатие.
 */
class UrgentAlert extends Notification
{
    public function __construct(public Alert $alert) {}

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
            ->title('Срочно: '.PersonName::short($this->alert->patient->name))
            ->body('Ответы опросника требуют внимания')
            ->icon('/pwa-192x192.png')
            ->badge('/notification-badge.png')
            ->tag('alert-'.$this->alert->id)
            // Уведомление не исчезает само, пока врач его не откроет или не смахнёт.
            ->requireInteraction()
            ->data(['url' => route('doctor.patients.show', $this->alert->patient_id, absolute: false).'#alerts'])
            ->options([
                'TTL' => 24 * 60 * 60,
                'urgency' => 'high',
                'topic' => 'alert-'.$this->alert->id,
            ]);
    }
}
