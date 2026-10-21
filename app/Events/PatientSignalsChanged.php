<?php

namespace App\Events;

use App\Models\User;
use App\Support\PatientSignals;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * У пациентов врача мог измениться сигнал о приверженности или находки проверки безопасности:
 * scheduler отметил пропуск, пациент отметил приём или добавил препарат, врач открыл карточку
 * (в том числе в другой вкладке), внёс факт или решил по находке. Счётчик у пункта меню
 * «Пациенты» считается при трансляции, поэтому приходит актуальным.
 */
class PatientSignalsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'patient-signals.changed';
    }

    /**
     * @return array{count: int}
     */
    public function broadcastWith(): array
    {
        return ['count' => PatientSignals::count($this->doctor)];
    }
}
