<?php

namespace App\Models;

use App\Enums\AlertDecision;
use App\Enums\AlertLevel;
use App\Enums\AlertShownVia;
use App\Enums\AlertTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Алерт: правило сработало на ответах пациента, пациент сам нажал «Сообщить врачу» на экране
 * помощи или сообщил о срочном побочном эффекте. Открыт, пока врач не закрыл его решением;
 * «Принял» только убирает красную плашку. Каждое срабатывание по ответам — новый алерт. Повторное
 * «Сообщить врачу» или новый срочный побочный эффект, пока такой алерт открыт, пишется в тот же
 * алерт и снова поднимает плашку, даже принятую.
 *
 * Метки времени шагов хранятся с миллисекундами: пациент отправил ответы или нажал кнопку, алерт
 * создан, событие ушло в Reverb, пуш отправлен, алерт показан на экране врача, принят, закрыт.
 *
 * @phpstan-type AnswerEvidence array{type: 'answer', scale: string, name: string, item: int, text: string, score: int, label: string}|array{type: 'total', scale: string, name: string, total: int, max: int}
 * @phpstan-type AdverseEventEvidence array{type: 'adverse_event', name: string, term: string, level: 'urgent'|'emergency'}
 * @phpstan-type Evidence AnswerEvidence|array{type: 'help_request'}|AdverseEventEvidence
 *
 * @property int $id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $alert_rule_id
 * @property string $rule_version
 * @property AlertLevel $level
 * @property int|null $assessment_id Есть только у алерта по ответам опросника.
 * @property list<Evidence> $evidence
 * @property CarbonImmutable $submitted_at Пациент отправил ответы, нажал «Сообщить врачу» или отправил побочный эффект.
 * @property int $repeat_count Повторы, пока алерт открыт: «Сообщить врачу» или новый срочный побочный эффект.
 * @property CarbonImmutable|null $repeated_at Последний повтор.
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
 * @property-read Assessment|null $assessment
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
    'repeat_count',
    'repeated_at',
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
     * вопрос назван в строке заполнения у врача. У побочных эффектов — термины без повторов,
     * «нужна помощь сейчас» первыми: «ангионевротический отёк или анафилаксия, тризм».
     */
    public function trigger(): string
    {
        if ($this->kind() === AlertTrigger::AdverseEvent) {
            return implode(', ', array_map(fn (array $entry): string => mb_lcfirst($entry['term']), $this->adverseEvidence()));
        }

        return implode('; ', array_values(array_unique(array_map(fn (array $entry): string => match ($entry['type']) {
            'answer' => "{$entry['name']}, вопрос {$entry['item']}: «".mb_lcfirst($entry['label']).'»',
            'total' => "{$entry['name']}: сумма {$entry['total']} из {$entry['max']}",
            'help_request' => 'кнопка «Сообщить врачу»',
            'adverse_event' => mb_lcfirst($entry['term']),
        }, $this->evidence))));
    }

    /**
     * Срочные побочные эффекты без повторов одного симптома: «нужна помощь сейчас» первыми,
     * дальше в порядке сообщения.
     *
     * @return list<AdverseEventEvidence>
     */
    public function adverseEvidence(): array
    {
        $entries = [];

        foreach ($this->evidence as $entry) {
            if ($entry['type'] === 'adverse_event') {
                $entries[$entry['term']] ??= $entry;
            }
        }

        usort($entries, fn (array $a, array $b): int => ($b['level'] === 'emergency') <=> ($a['level'] === 'emergency'));

        return $entries;
    }

    /**
     * Что подняло алерт: ответы опросника, «Сообщить врачу» или срочный побочный эффект.
     */
    public function kind(): AlertTrigger
    {
        return match ($this->evidence[0]['type'] ?? null) {
            'help_request' => AlertTrigger::HelpRequest,
            'adverse_event' => AlertTrigger::AdverseEvent,
            default => AlertTrigger::Answers,
        };
    }

    /**
     * Пациент сам нажал «Сообщить врачу», а не сработало правило по ответам.
     */
    public function isHelpRequest(): bool
    {
        return $this->kind() === AlertTrigger::HelpRequest;
    }

    /**
     * Нужна красная плашка: алерт ещё не принят или после принятия пациент снова нажал кнопку.
     */
    public function awaitsAcknowledgement(): bool
    {
        return $this->closed_at === null && ($this->acknowledged_at === null
            || ($this->repeated_at !== null && $this->repeated_at->greaterThan($this->acknowledged_at)));
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
     * Алерты, которые ждут «Принять»: врач их ещё не принял или пациент снова нажал «Сообщить
     * врачу» после принятия. По ним красная плашка.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingAcknowledgement(Builder $query): void
    {
        $query->whereNull('closed_at')->where(fn (Builder $query) => $query
            ->whereNull('acknowledged_at')
            ->orWhereColumn('repeated_at', '>', 'acknowledged_at'));
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
            'repeat_count' => 'integer',
            'repeated_at' => 'datetime',
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
