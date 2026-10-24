<?php

namespace App\Support\QueueAnswers;

use App\Events\QueueAnswersChanged;
use App\Models\QueueAnswer;
use App\Notifications\UrgentQueueAnswer;
use Throwable;

/**
 * Новые ответы из очереди — терапевту: событие в открытый кабинет, а при сигнале по 9-му вопросу
 * ещё и пуш. Доставка не повторяется: если Reverb или сервис пушей недоступны, терапевт увидит
 * ответы, когда откроет кабинет.
 */
final class QueueAnswerDelivery
{
    public function deliver(QueueAnswer $answer): void
    {
        $doctor = $answer->doctor;
        $urgent = $answer->alert !== null;

        try {
            QueueAnswersChanged::dispatch($doctor, $urgent);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $urgent) {
            return;
        }

        try {
            if ($doctor->pushSubscriptions()->exists()) {
                $doctor->notifyNow(new UrgentQueueAnswer($answer));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
