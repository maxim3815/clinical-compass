<?php

namespace App\Support\Safety;

use App\Enums\SafetyDecisionKind;
use App\Models\User;

/**
 * Решение врача по красным находкам с обоснованием: при назначении, изменении или «Продолжить».
 */
final readonly class SafetyDecision
{
    /**
     * @param  list<string>  $keys  находки, к которым относится решение (RuleResult::key())
     */
    public function __construct(
        public User $doctor,
        public SafetyDecisionKind $kind,
        public string $text,
        public array $keys,
    ) {}

    public function covers(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /**
     * Неоформленное назначение («new») получило id после сохранения.
     */
    public function forPrescription(int $prescriptionId): self
    {
        return new self(
            $this->doctor,
            $this->kind,
            $this->text,
            array_map(fn (string $key): string => str_replace('@new', '@'.$prescriptionId, $key), $this->keys),
        );
    }
}
