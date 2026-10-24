<?php

namespace App\Actions;

use App\Actions\Concerns\DecidesReferrals;
use App\Actions\Concerns\HandlesQueueAnswers;
use App\Enums\ReferralCategory;
use App\Events\QueueAnswersChanged;
use App\Models\AuditLog;
use App\Models\QueueAnswer;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Терапевт решил не направлять пациента по ответам из очереди, как в карточке: если система
 * подсказывала направление, нужен комментарий. Ответы живут до 12 часов, поэтому пациента ещё
 * можно зарегистрировать и направить.
 */
class DeclineQueueReferral
{
    use DecidesReferrals, HandlesQueueAnswers;

    public function __construct(private readonly ReferralEngine $engine) {}

    /**
     * @param  ReferralCategory|null  $seenSuggestion  подсказка, которую терапевт видел на странице кода
     *
     * @throws ValidationException
     */
    public function handle(User $therapist, QueueAnswer $answer, ?string $comment, ?ReferralCategory $seenSuggestion): QueueAnswer
    {
        return DB::transaction(function () use ($therapist, $answer, $comment, $seenSuggestion): QueueAnswer {
            $answer = $this->lockPending($therapist, $answer);

            $suggestion = $this->engine->check($answer->referralData());
            $this->ensureSameSuggestion($suggestion, $seenSuggestion);

            if ($suggestion->category !== null && blank($comment)) {
                throw ValidationException::withMessages([
                    'comment' => "Система подсказывает «{$suggestion->category->label()}»: напишите, почему не направляете.",
                ]);
            }

            $answer->update([
                'declined_at' => now(),
                'declined_suggestion' => $suggestion->category,
                'decline_comment' => blank($comment) ? null : $comment,
            ]);

            AuditLog::record($therapist, 'queue_answer.declined', null, $answer, null, [
                'code' => $answer->code,
                'suggested_category' => $suggestion->category?->value,
                'basis' => array_column($suggestion->matches, 'code'),
                'comment' => $answer->decline_comment,
            ]);

            QueueAnswersChanged::dispatch($therapist);

            return $answer;
        });
    }
}
