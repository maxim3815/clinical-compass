<?php

namespace App\Notifications;

use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use App\Models\User;
use App\Notifications\Concerns\DescribesDoses;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Напоминание о приёмах пациента на одно время с кнопками «Принял» и «Через 15 минут».
 * Кнопки работают по подписанным ссылкам: service worker не может приложить CSRF-токен,
 * а сессии на устройстве может не быть. Ссылки действуют, пока открыто окно приёма.
 */
class DoseReminder extends Notification implements ShouldQueue
{
    use DescribesDoses, Queueable;

    /**
     * @param  Collection<int, DoseEvent>  $doses  приёмы одного пациента на одно время
     */
    public function __construct(public Collection $doses)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Пока напоминание ждало очереди, пациент мог отметить приёмы.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $this->unmarked()->isNotEmpty();
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $doses = $this->sorted($this->unmarked());
        $ids = ['doses' => implode(',', $doses->modelKeys())];
        $expires = $doses->min('window_closes_at');

        return (new WebPushMessage)
            ->title('Приём в '.$this->clock($doses))
            ->body($doses->map(fn (DoseEvent $dose): string => $this->drugLine($dose)
                .($dose->prescription->instruction ? '. '.$dose->prescription->instruction : ''))->implode("\n"))
            ->icon('/pwa-192x192.png')
            ->badge('/notification-badge.png')
            ->tag($this->tag($doses))
            ->renotify()
            ->requireInteraction()
            ->action($this->takeLabel($notifiable, $doses->count()), 'take')
            ->action('Через 15 минут', 'snooze')
            ->data([
                'url' => $this->url($doses),
                'take' => URL::temporarySignedRoute('push.doses.take', $expires, $ids, absolute: false),
                'snooze' => URL::temporarySignedRoute('push.doses.snooze', $expires, $ids, absolute: false),
            ])
            ->options([
                // Недоставленное напоминание не нужно позже следующего повтора.
                'TTL' => config()->integer('medications.reminders.repeat_minutes') * 60,
                'urgency' => 'high',
                'topic' => $this->tag($doses),
            ]);
    }

    /**
     * Кнопка отметки, у пациентки в женском роде: «Приняла всё».
     */
    private function takeLabel(User $notifiable, int $count): string
    {
        $verb = $notifiable->isFemalePatient() ? 'Приняла' : 'Принял';

        return $count > 1 ? $verb.' всё' : $verb;
    }

    /**
     * @return Collection<int, DoseEvent>
     */
    private function unmarked(): Collection
    {
        return $this->doses->filter(fn (DoseEvent $dose): bool => $dose->status === DoseEventStatus::Scheduled)->values();
    }
}
