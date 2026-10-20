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
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Окно приёма закрылось без отметки: что делать при пропуске дозы, по справочнику препарата.
 * Кнопок нет: «Принял» сейчас записал бы неверное время, отметить можно в приложении.
 */
class MissedDoses extends Notification implements ShouldQueue
{
    use DescribesDoses, Queueable;

    /**
     * @param  Collection<int, DoseEvent>  $doses  пропущенные приёмы одного пациента на одно время
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
     * Пациент мог отметить приём, пока пуш ждал очереди.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $this->missed()->isNotEmpty();
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $doses = $this->sorted($this->missed());

        // У «другого» препарата пациента справочника нет: советов о пропуске не даём.
        $lines = $doses->map(fn (DoseEvent $dose): string => $this->drugLine($dose)
            .($dose->prescription->drug?->missed_dose ? '. '.$dose->prescription->drug->missed_dose : ''));

        return (new WebPushMessage)
            ->title('Приём в '.$this->clock($doses).' не отмечен')
            ->body($lines->push('Если приняли, отметьте в приложении.')->implode("\n"))
            ->icon('/pwa-192x192.png')
            ->badge('/notification-badge.png')
            // Заменяет последнее напоминание о тех же приёмах: его кнопки уже не работают.
            ->tag($this->tag($doses))
            ->renotify()
            ->data(['url' => $this->url($doses)])
            ->options([
                // Совет о пропуске пригодится и утром, если телефон ночью был без сети.
                'TTL' => 12 * 60 * 60,
                'urgency' => 'normal',
                'topic' => $this->tag($doses),
            ]);
    }

    /**
     * @return Collection<int, DoseEvent>
     */
    private function missed(): Collection
    {
        return $this->doses->filter(fn (DoseEvent $dose): bool => $dose->status === DoseEventStatus::Missed)->values();
    }
}
