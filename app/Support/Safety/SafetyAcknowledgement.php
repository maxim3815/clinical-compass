<?php

namespace App\Support\Safety;

use App\Enums\SafetyDecisionKind;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Что врач видел в блоке проверки формы назначения и что написал в обосновании.
 */
final readonly class SafetyAcknowledgement
{
    public const string STALE = 'Данные пациента изменились, посмотрите проверку безопасности ещё раз.';

    public const string JUSTIFICATION_REQUIRED = 'Есть противопоказание: напишите, почему всё равно назначаете препарат.';

    public const int MAX_JUSTIFICATION_LENGTH = 2000;

    /**
     * @param  list<string>  $seen  ключи красных находок, которые были в блоке проверки
     */
    public function __construct(
        public array $seen = [],
        public ?string $justification = null,
    ) {}

    /**
     * Повторная проверка при сохранении. Красная находка, которой врач не видел (кто-то внёс факт
     * после показа формы), не даёт сохранить. Без обоснования красная находка тоже не даёт.
     *
     * @return SafetyDecision|null решение по красным находкам, если они есть
     *
     * @throws ValidationException
     */
    public function confirm(SafetyReport $report, User $doctor): ?SafetyDecision
    {
        $required = $report->requiringKeys();

        if (array_diff($required, $this->seen) !== []) {
            throw ValidationException::withMessages(['safety' => self::STALE]);
        }

        if ($required === []) {
            return null;
        }

        if ($this->justification === null || trim($this->justification) === '') {
            throw ValidationException::withMessages(['justification' => self::JUSTIFICATION_REQUIRED]);
        }

        return new SafetyDecision($doctor, SafetyDecisionKind::Prescribed, trim($this->justification), $required);
    }
}
