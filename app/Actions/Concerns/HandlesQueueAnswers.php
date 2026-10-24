<?php

namespace App\Actions\Concerns;

use App\Models\QueueAnswer;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Общее для действий терапевта с ответами из очереди: только его ответы, под блокировкой строки
 * и пока они не привязаны к пациенту и не истекли.
 */
trait HandlesQueueAnswers
{
    /**
     * Вызывать внутри транзакции.
     *
     * @throws ValidationException
     */
    private function lockPending(User $therapist, QueueAnswer $answer): QueueAnswer
    {
        if (! $therapist->isTherapist() || $answer->doctor_id !== $therapist->id) {
            throw new InvalidArgumentException('Ответы из очереди видит только терапевт, у двери которого на них ответили.');
        }

        $answer = QueueAnswer::query()->whereKey($answer->id)->lockForUpdate()->firstOrFail();

        if (! $answer->isPending()) {
            throw ValidationException::withMessages([
                'answer' => $answer->attached_at !== null
                    ? 'Ответы уже в карточке пациента.'
                    : 'Ответы удалены: прошло 12 часов.',
            ]);
        }

        return $answer;
    }
}
