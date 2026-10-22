<?php

namespace App\Events;

use App\Models\AccessLink;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Пациент ввёл PIN или зарегистрировался по приглашению: врач видит это в окне приглашения
 * на приёме и в списке приглашений без перезагрузки. В очереди хранится только id приглашения,
 * этап и ФИО берутся при отправке.
 */
class InvitationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public AccessLink $invitation) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->invitation->doctor_id);
    }

    public function broadcastAs(): string
    {
        return 'invitation.changed';
    }

    /**
     * @return array{id: int, stage: 'entered'|'registered', patient: array{id: int, name: string}|null}
     */
    public function broadcastWith(): array
    {
        $patient = $this->invitation->used_at !== null ? $this->invitation->patient : null;

        return [
            'id' => $this->invitation->id,
            'stage' => $patient !== null ? 'registered' : 'entered',
            'patient' => $patient !== null ? ['id' => $patient->id, 'name' => $patient->name] : null,
        ];
    }
}
