<?php

namespace App\Events;

use App\Models\User;
use App\Support\UnreadMessages;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * У пользователя изменилось непрочитанное: пришло сообщение или он прочитал диалог (в том числе
 * в другой вкладке). Счётчик считается при трансляции, поэтому приходит актуальным.
 */
class UnreadMessagesChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $user) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->user->id);
    }

    public function broadcastAs(): string
    {
        return 'unread.changed';
    }

    /**
     * @return array{count: int}
     */
    public function broadcastWith(): array
    {
        return ['count' => UnreadMessages::count($this->user)];
    }
}
