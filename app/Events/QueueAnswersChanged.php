<?php

namespace App\Events;

use App\Models\User;
use App\Support\QueueAnswers\QueueAnswerPresenter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * У терапевта изменились ответы из очереди: пришли новые, их открыли, решили не направлять или
 * привязали к пациенту. С событием приходят ответы для красной плашки: они считаются при трансляции.
 *
 * Ответы с сигналом по 9-му вопросу транслируются сразу из запроса пациента ($now), без задачи
 * в очереди, как новый алерт. Остальное — через очередь: недоступный Reverb не ломает запрос.
 */
class QueueAnswersChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return 'queue-answers.changed';
    }

    /**
     * @return array{urgent: list<array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return ['urgent' => QueueAnswerPresenter::banner($this->doctor)];
    }
}
