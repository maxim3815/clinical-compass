<?php

namespace App\Events;

use App\Models\User;
use App\Support\Alerts\AlertPresenter;
use App\Support\PatientSignals;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * У пациентов врача появился, принят или закрыт алерт. С событием приходят непринятые алерты
 * для красной плашки и счётчик у пункта меню «Пациенты»: оба считаются при трансляции.
 *
 * Новый алерт транслируется сразу из задачи проверки правил ($now), без ещё одной задачи
 * в очереди: это время доставки врачу. Принятие и закрытие — через очередь, как остальные
 * события: недоступный Reverb не ломает запрос врача.
 */
class AlertsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $doctor, public bool $now = false) {}

    public function shouldBroadcastNow(): bool
    {
        return $this->now;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->doctor->id);
    }

    public function broadcastAs(): string
    {
        return 'alerts.changed';
    }

    /**
     * @return array{alerts: list<array<string, mixed>>, count: int}
     */
    public function broadcastWith(): array
    {
        return [
            'alerts' => AlertPresenter::banner($this->doctor),
            'count' => PatientSignals::count($this->doctor),
        ];
    }
}
