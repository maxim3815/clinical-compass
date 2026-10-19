<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Участник диалога прочитал сообщения собеседника до lastReadId включительно: отправитель видит
 * у своих сообщений, что они прочитаны.
 */
class MessagesRead implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $careRelationshipId,
        public int $readerId,
        public int $lastReadId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->careRelationshipId);
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    /**
     * @return array{reader_id: int, last_read_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'reader_id' => $this->readerId,
            'last_read_id' => $this->lastReadId,
        ];
    }
}
