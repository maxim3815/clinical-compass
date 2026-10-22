<?php

namespace App\Support\Alerts;

use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Опросники, которые пациент отправил вместе: по ним вычисляются условия правил алертинга.
 */
final readonly class AlertSubmission
{
    /**
     * @var array<string, Assessment> код шкалы => заполнение
     */
    private array $assessments;

    /**
     * @param  Collection<int, Assessment>  $assessments  с загруженными шкалой и ответами
     */
    public function __construct(Collection $assessments)
    {
        $this->assessments = $assessments->keyBy(fn (Assessment $assessment): string => $assessment->scale->code)->all();
    }

    public function has(string $scale): bool
    {
        return isset($this->assessments[$scale]);
    }

    public function assessment(string $scale): Assessment
    {
        return $this->assessments[$scale] ?? throw new InvalidArgumentException("Опросника {$scale} нет среди отправленных.");
    }

    public function answer(string $scale, int $item): int
    {
        $answer = $this->assessment($scale)->answers->first(fn (AssessmentAnswer $answer): bool => (int) $answer->item === $item);

        if ($answer === null) {
            throw new InvalidArgumentException("В опроснике {$scale} нет вопроса {$item}.");
        }

        return (int) $answer->score;
    }

    public function total(string $scale): int
    {
        return $this->assessment($scale)->total;
    }
}
