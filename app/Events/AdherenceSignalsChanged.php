<?php

namespace App\Events;

use App\Models\User;
use App\Support\Adherence\AdherenceSignals;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * У пациентов врача мог измениться сигнал о приверженности: scheduler отметил пропуск, пациент
 * отметил приём или врач открыл карточку (в том числе в другой вкладке). Счётчик у пункта меню
 * «Пациенты» считается при трансляции, поэтому приходит актуальным.
 */
class AdherenceSignalsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $doctor) {}

    /**
     * Врачу, который сейчас ведёт пациента. Без лечащего врача сообщать некому.
     */
    public static function dispatchForDoctorOf(User $patient): void
    {
        $doctor = $patient->activeCareRelationship?->doctor;

        if ($doctor !== null) {
            self::dispatch($doctor);
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->doctor->id);
    }

    public function broadcastAs(): string
    {
        return 'adherence.changed';
    }

    /**
     * @return array{count: int}
     */
    public function broadcastWith(): array
    {
        return ['count' => AdherenceSignals::count($this->doctor)];
    }
}
