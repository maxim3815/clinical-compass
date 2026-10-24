<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesQueueAnswers;
use App\Enums\ScaleRater;
use App\Events\QueueAnswersChanged;
use App\Models\AuditLog;
use App\Models\ObservationType;
use App\Models\QueueAnswer;
use App\Models\Scale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Терапевт заполняет HARS и признаки по ответам из очереди, до регистрации пациента. Хранятся
 * вместе с ответами: последнее заполнение заменяет прежнее, при регистрации оно становится
 * обычным заполнением шкалы врача и фактами профиля.
 */
class RateQueueScale
{
    use HandlesQueueAnswers;

    /**
     * @param  list<int>  $scores  балл на каждый пункт по порядку
     * @param  array<string, bool>  $facts  код вида факта => значение
     *
     * @throws ValidationException
     */
    public function handle(User $therapist, QueueAnswer $answer, Scale $scale, array $scores, array $facts): QueueAnswer
    {
        if ($scale->rater !== ScaleRater::Doctor || ! $scale->is_active) {
            throw new InvalidArgumentException("{$scale->name}: врач заполняет только действующие шкалы врача.");
        }

        $total = $scale->total($scores);
        $known = ObservationType::query()->whereIn('code', array_keys($facts))->pluck('code')->all();

        if (count($known) !== count($facts)) {
            throw new InvalidArgumentException('Неизвестный вид факта: '.implode(', ', array_diff(array_keys($facts), $known)).'.');
        }

        return DB::transaction(function () use ($therapist, $answer, $scale, $scores, $total, $facts): QueueAnswer {
            $answer = $this->lockPending($therapist, $answer);

            $answer->update([
                'doctor_scale' => [
                    'scale_id' => $scale->id,
                    'scale' => $scale->code,
                    'version' => $scale->version,
                    'scores' => $scores,
                    'total' => $total,
                    'band' => $scale->band($total),
                    'rated_by_id' => $therapist->id,
                    'rated_at' => CarbonImmutable::now()->toIso8601ZuluString('millisecond'),
                ],
                'facts' => $facts,
            ]);

            // В журнале без баллов: ответы из очереди удаляются через 12 часов, журнал остаётся.
            AuditLog::record($therapist, 'queue_answer.rated', null, $answer, null, [
                'code' => $answer->code,
                'scale' => $scale->code,
            ]);

            // Подсказка в «Ответили сегодня» меняется и в других вкладках.
            QueueAnswersChanged::dispatch($therapist);

            return $answer;
        });
    }
}
