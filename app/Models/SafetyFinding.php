<?php

namespace App\Models;

use App\Enums\SafetyDecisionKind;
use App\Enums\SafetyLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Находка — правило, сработавшее на одном назначении. Открыта, пока условие выполняется;
 * при изменении назначения переходит к новой версии. Решение врача по красной находке
 * остаётся в ней и после закрытия. Исчезнувшая и снова появившаяся находка — новая запись.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescription_id
 * @property int $rule_id
 * @property string $rule_version
 * @property SafetyLevel $level
 * @property list<string> $evidence
 * @property list<int> $related_prescription_ids
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $decided_at
 * @property int|null $decided_by_id
 * @property SafetyDecisionKind|null $decision_kind
 * @property string|null $decision_text
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read Prescription $prescription
 * @property-read SafetyRule $rule
 * @property-read User|null $decidedBy
 */
#[Fillable([
    'patient_id',
    'prescription_id',
    'rule_id',
    'rule_version',
    'level',
    'evidence',
    'related_prescription_ids',
    'opened_at',
    'closed_at',
    'decided_at',
    'decided_by_id',
    'decision_kind',
    'decision_text',
])]
class SafetyFinding extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<SafetyRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SafetyRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * Красная находка, по которой врач ещё не решил: из-за неё пациент отмечен в списке.
     */
    public function awaitsDecision(): bool
    {
        return $this->closed_at === null && $this->decided_at === null && $this->level->requiresJustification();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Открытые красные находки без решения врача.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingDecision(Builder $query): void
    {
        $query->whereNull('closed_at')
            ->whereNull('decided_at')
            ->where('level', SafetyLevel::Contraindicated);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => SafetyLevel::class,
            'evidence' => 'array',
            'related_prescription_ids' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'decided_at' => 'datetime',
            'decision_kind' => SafetyDecisionKind::class,
        ];
    }
}
