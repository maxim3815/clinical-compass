<?php

namespace App\Events;

use App\Models\Message;
use App\Support\MessagePresenter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Новое сообщение в диалоге: оба участника видят его без перезагрузки страницы. Трансляция идёт
 * через очередь, поэтому отправка не зависит от того, работает ли Reverb. В очереди хранится
 * только id сообщения, а не текст.
 */
class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->message->care_relationship_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return MessagePresenter::present($this->message);
    }
}
