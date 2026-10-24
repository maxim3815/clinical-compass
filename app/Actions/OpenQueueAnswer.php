<?php

namespace App\Actions;

use App\Events\QueueAnswersChanged;
use App\Models\AuditLog;
use App\Models\QueueAnswer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Терапевт открыл ответы из очереди. Первое открытие гасит красную плашку по 9-му вопросу
 * во всех вкладках и записывается в журнал аудита.
 */
class OpenQueueAnswer
{
    public function handle(User $therapist, QueueAnswer $answer): QueueAnswer
    {
        if ($answer->opened_at !== null) {
            return $answer;
        }

        return DB::transaction(function () use ($therapist, $answer): QueueAnswer {
            $opened = QueueAnswer::query()
                ->whereKey($answer->id)
                ->whereNull('opened_at')
                ->update(['opened_at' => now()]);

            $answer->refresh();

            if ($opened > 0) {
                AuditLog::record($therapist, 'queue_answer.opened', null, $answer, null, ['code' => $answer->code]);

                if ($answer->alert !== null) {
                    QueueAnswersChanged::dispatch($therapist);
                }
            }

            return $answer;
        });
    }
}
