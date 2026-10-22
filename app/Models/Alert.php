<?php

namespace App\Models;

use App\Enums\AlertDecision;
use App\Enums\AlertLevel;
use App\Enums\AlertShownVia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Алерт: правило сработало на ответах пациента. Открыт, пока врач не закрыл его решением;
 * «Принял» только убирает красную плашку. Каждое срабатывание — новый алерт.
 *
 * Метки времени шагов хранятся с миллисекундами: пациент отправил ответы, алерт создан,
 * событие ушло в Reverb, пуш отправлен, алерт показан на экране врача, принят, закрыт.
 *
 * @phpstan-type Evidence array{type: 'answer', scale: string, name: string, item: int, text: string, score: int, label: string}|array{type: 'total', scale: string, name: string, total: int, max: int}
 *
 * @property int $id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $alert_rule_id
 * @property string $rule_version
 * @property AlertLevel $level
 * @property int $assessment_id
 * @property list<Evidence> $evidence
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $broadcast_at
 * @property CarbonImmutable|null $pushed_at
 * @property CarbonImmutable|null $shown_at
 * @property AlertShownVia|null $shown_via
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by_id
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by_id
 * @property AlertDecision|null $decision
 * @property string|null $decision_comment
 * @property CarbonImmutable $updated_at
 * @property-read User $patient
 * @property-read User $doctor
 * @property-read AlertRule $rule
 * @property-read Assessment $assessment
 * @property-read User|null $acknowledgedBy
 * @property-read User|null $closedBy
 */
#[Fillable([
    'patient_id',
    'doctor_id',
    'alert_rule_id',
    'rule_version',
    'level',
    'assessment_id',
    'evidence',
    'submitted_at',
    'broadcast_at',
    'pushed_at',
    'shown_at',
    'shown_via',
    'acknowledged_at',
    'acknowledged_by_id',
    'closed_at',
    'closed_by_id',
    'decision',
    'decision_comment',
])]
class Alert extends Model
{
    /**
     * Метки времени с миллисекундами: по ним считается время доставки.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * @return BelongsTo<AlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /**
     * Что сработало, одной строкой: «PHQ-9, вопрос 9: «почти каждый день»». Так же ответ на этот
     * вопрос назван в строке заполнения у врача.
     */
    public function trigger(): string
    {
        return implode('; ', array_map(fn (array $entry): string => $entry['type'] === 'answer'
            ? "{$entry['name']}, вопрос {$entry['item']}: «".mb_lcfirst($entry['label']).'»'
            : "{$entry['name']}: сумма {$entry['total']} из {$entry['max']}", $this->evidence));
    }

    /**
     * Сколько секунд прошло от ответа пациента до показа на экране врача.
     */
    public function deliverySeconds(): ?float
    {
        return $this->shown_at === null ? null : $this->submitted_at->diffInMilliseconds($this->shown_at) / 1000;
    }

    /**
     * Алерты, которые врач ещё не закрыл решением.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Алерты, которые врач ещё не принял: по ним красная плашка.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unacknowledged(Builder $query): void
    {
        $query->whereNull('acknowledged_at')->whereNull('closed_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AlertLevel::class,
            'evidence' => 'array',
            'submitted_at' => 'datetime',
            'broadcast_at' => 'datetime',
            'pushed_at' => 'datetime',
            'shown_at' => 'datetime',
            'shown_via' => AlertShownVia::class,
            'acknowledged_at' => 'datetime',
            'closed_at' => 'datetime',
            'decision' => AlertDecision::class,
        ];
    }
}
