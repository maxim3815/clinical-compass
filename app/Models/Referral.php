<?php

namespace App\Models;

use App\Enums\ReferralCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Направление к психиатру от терапевта. Подсказка и основание сохранены на момент направления:
 * психиатр видит, что подсказала система и что решил терапевт. Открытое направление ждёт, пока
 * психиатр примет пациента или терапевт его отзовёт.
 *
 * @phpstan-import-type ReferralMatchData from \App\Support\Referrals\ReferralSuggestion
 *
 * @property int $id
 * @property int $patient_id
 * @property int $from_doctor_id
 * @property int $to_doctor_id
 * @property ReferralCategory $category
 * @property ReferralCategory|null $suggested_category
 * @property list<ReferralMatchData> $basis
 * @property string|null $comment
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read User $fromDoctor
 * @property-read User $toDoctor
 */
#[Fillable(['patient_id', 'from_doctor_id', 'to_doctor_id', 'category', 'suggested_category', 'basis', 'comment', 'accepted_at', 'withdrawn_at'])]
class Referral extends Model
{
    public const int MAX_COMMENT_LENGTH = 500;

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->withdrawn_at === null;
    }

    /**
     * Решение терапевта расходится с подсказкой системы.
     */
    public function differsFromSuggestion(): bool
    {
        return $this->suggested_category !== $this->category;
    }

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
    public function fromDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_doctor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function toDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_doctor_id');
    }

    /**
     * Ещё не принято и не отозвано.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('withdrawn_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ReferralCategory::class,
            'suggested_category' => ReferralCategory::class,
            'basis' => 'array',
            'accepted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }
}
