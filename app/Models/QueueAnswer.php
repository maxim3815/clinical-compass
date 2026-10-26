<?php

namespace App\Models;

use App\Enums\ReferralCategory;
use App\Support\DisplayTime;
use App\Support\Referrals\ReferralData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ответы из очереди: пациент у двери терапевта без регистрации ответил на опросники и получил
 * код из 4 цифр. Терапевт открывает ответы по коду, заполняет HADS и признаки. При регистрации
 * по PIN ответы становятся обычными заполнениями пациента, непривязанные удаляются через 12 часов.
 *
 * @phpstan-import-type Evidence from Alert
 * @phpstan-import-type Subscore from Scale
 *
 * @phpstan-type QueueScale array{scale_id: int, scale: string, version: string, scores: list<int>, total: int, band: string, subscores?: list<Subscore>|null}
 * @phpstan-type QueueDoctorScale array{scale_id: int, scale: string, version: string, scores: list<int>, total: int, band: string, subscores?: list<Subscore>|null, rated_by_id: int, rated_at: string}
 * @phpstan-type QueueAlert array{rule_id: int, rule_version: string, evidence: list<Evidence>}
 *
 * @property int $id
 * @property int $doctor_id
 * @property string $code
 * @property string $browser_token_hash
 * @property list<QueueScale> $scales
 * @property QueueDoctorScale|null $doctor_scale
 * @property array<string, bool>|null $facts
 * @property QueueAlert|null $alert
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $declined_at
 * @property ReferralCategory|null $declined_suggestion Подсказка, при которой терапевт решил не направлять.
 * @property string|null $decline_comment
 * @property int|null $patient_id
 * @property CarbonImmutable|null $attached_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $doctor
 * @property-read User|null $patient
 */
#[Fillable([
    'doctor_id',
    'code',
    'browser_token_hash',
    'scales',
    'doctor_scale',
    'facts',
    'alert',
    'submitted_at',
    'opened_at',
    'declined_at',
    'declined_suggestion',
    'decline_comment',
    'patient_id',
    'attached_at',
])]
#[Hidden(['browser_token_hash'])]
class QueueAnswer extends Model
{
    /**
     * Сколько живут непривязанные ответы: приём заканчивается в тот же день.
     */
    public const int LIFETIME_HOURS = 12;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Последние непривязанные ответы этого телефона (токен из cookie) у врача.
     */
    public static function ofBrowser(User $doctor, ?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        return self::query()
            ->pending()
            ->where('doctor_id', $doctor->id)
            ->where('browser_token_hash', self::hashToken($token))
            ->latest('submitted_at')
            ->first();
    }

    /**
     * Местная дата ответа как полночь UTC, как у заполнений пациента. До регистрации часового пояса
     * пациента нет: время приёма — московское.
     */
    public static function localDate(CarbonImmutable $time, string $timezone = DisplayTime::TIMEZONE): CarbonImmutable
    {
        return CarbonImmutable::parse($time->setTimezone($timezone)->toDateString(), 'UTC');
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->submitted_at->addHours(self::LIFETIME_HOURS);
    }

    /**
     * Когда ответы удалятся, для текста: «сегодня в 22:00» или «завтра в 08:39» — по московскому
     * времени, как время приёма.
     */
    public function expiresLabel(): string
    {
        $expires = $this->expiresAt()->setTimezone(DisplayTime::TIMEZONE);
        $day = $expires->isSameDay(CarbonImmutable::now(DisplayTime::TIMEZONE)) ? 'сегодня' : 'завтра';

        return "{$day} в {$expires->format('H:i')}";
    }

    /**
     * Ещё не привязаны к пациенту и не истекли.
     */
    public function isPending(): bool
    {
        return $this->attached_at === null && $this->expiresAt()->isFuture();
    }

    /**
     * Красная плашка у терапевта: сработало правило алертинга, а ответы ещё не открыты.
     */
    public function awaitsOpening(): bool
    {
        return $this->alert !== null && $this->opened_at === null && $this->isPending();
    }

    /**
     * Решение «не направлять» действует, пока подсказка та же, при которой его приняли. Если HADS
     * подняла подсказку, терапевт решает снова.
     */
    public function isDeclinedFor(?ReferralCategory $suggestion): bool
    {
        return $this->declined_at !== null && $this->declined_suggestion === $suggestion;
    }

    /**
     * Опросники как заполнения, без записи в базу: по ним считаются алерт и подсказка направления.
     * Шкала врача — последняя заполненная на приёме, если она есть.
     *
     * @return Collection<int, Assessment>
     */
    public function assessments(bool $withDoctorScale = true): Collection
    {
        $entries = [...$this->scales, ...($withDoctorScale && $this->doctor_scale !== null ? [$this->doctor_scale] : [])];
        $scales = Scale::query()->whereIn('id', array_column($entries, 'scale_id'))->get()->keyBy('id');

        return new Collection(array_map(function (array $entry) use ($scales): Assessment {
            $assessment = new Assessment([
                'patient_id' => $this->patient_id,
                'scale_id' => $entry['scale_id'],
                'scale_version' => $entry['version'],
                'total' => $entry['total'],
                'band' => $entry['band'],
                'subscores' => $entry['subscores'] ?? null,
                'local_date' => self::localDate($this->submitted_at),
                'submitted_at' => isset($entry['rated_at']) ? CarbonImmutable::parse($entry['rated_at']) : $this->submitted_at,
                'rated_by_id' => $entry['rated_by_id'] ?? null,
            ]);

            $assessment->setRelation('scale', $scales[$entry['scale_id']]);
            $assessment->setRelation('answers', new Collection(array_map(
                fn (int $index, int $score): AssessmentAnswer => new AssessmentAnswer(['item' => $index + 1, 'score' => $score]),
                array_keys($entry['scores']),
                $entry['scores'],
            )));

            return $assessment;
        }, $entries));
    }

    /**
     * Данные для подсказки направления: опросники из очереди, HADS и признаки с этого приёма.
     */
    public function referralData(): ReferralData
    {
        $types = ObservationType::query()->whereIn('code', array_keys($this->facts ?? []))->get()->keyBy('code');
        $facts = [];

        foreach ($this->facts ?? [] as $code => $value) {
            if (isset($types[$code])) {
                $facts[$code] = (new Observation([$types[$code]->value_type->column() => $value]))->setRelation('type', $types[$code]);
            }
        }

        return new ReferralData(
            $this->assessments()->keyBy(fn (Assessment $assessment): string => $assessment->scale->code)->all(),
            $facts,
        );
    }

    /**
     * Непривязанные и не истёкшие.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('attached_at')->where('submitted_at', '>', now()->subHours(self::LIFETIME_HOURS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scales' => 'array',
            'doctor_scale' => 'array',
            'facts' => 'array',
            'alert' => 'array',
            'submitted_at' => 'datetime',
            'opened_at' => 'datetime',
            'declined_at' => 'datetime',
            'declined_suggestion' => ReferralCategory::class,
            'attached_at' => 'datetime',
        ];
    }
}
