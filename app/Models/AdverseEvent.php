<?php

namespace App\Models;

use App\Enums\AdverseEventSeverity;
use App\Enums\SideEffectLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Побочный эффект: пациент сообщил о нём в окне «Побочные эффекты» или врач записал его со слов
 * пациента. Симптом из словаря или «другое» текстом. Название, термин и группа сохраняются
 * на момент записи. Срочный симптом связан с алертом врачу.
 *
 * Врачу запись нужна, пока он её не просмотрел: обычная — в очереди «Требуют внимания», срочная —
 * пока не закрыт алерт. Запись врача сразу просмотрена. «Прошло» отмечает пациент.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $side_effect_id Пусто у «другого».
 * @property string $name Слова пациента: из словаря или его текст.
 * @property string|null $term Термин для врача, у «другого» пусто.
 * @property SideEffectLevel|null $level Группа среди препаратов пациента на момент записи.
 * @property AdverseEventSeverity $severity
 * @property CarbonImmutable $started_on Местная дата пациента.
 * @property int $reported_by_id Пациент или врач.
 * @property int|null $alert_id
 * @property CarbonImmutable|null $resolved_on
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by_id
 * @property string|null $comment Комментарий врача.
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $patient
 * @property-read User $reportedBy
 * @property-read User|null $reviewedBy
 * @property-read SideEffect|null $sideEffect
 * @property-read Alert|null $alert
 */
#[Fillable([
    'patient_id',
    'side_effect_id',
    'name',
    'term',
    'level',
    'severity',
    'started_on',
    'reported_by_id',
    'alert_id',
    'resolved_on',
    'reviewed_at',
    'reviewed_by_id',
    'comment',
])]
class AdverseEvent extends Model
{
    public const int MAX_NAME_LENGTH = 200;

    public const int MAX_COMMENT_LENGTH = 500;

    /**
     * Насколько давно мог начаться симптом: дату раньше пациент вряд ли вспомнит точно.
     */
    public const int MAX_DAYS_AGO = 90;

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
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * @return BelongsTo<SideEffect, $this>
     */
    public function sideEffect(): BelongsTo
    {
        return $this->belongsTo(SideEffect::class);
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * Запись врача со слов пациента, а не сообщение самого пациента.
     */
    public function isByDoctor(): bool
    {
        return $this->reported_by_id !== $this->patient_id;
    }

    /**
     * Записи, которые врач ещё не просмотрел.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingReview(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => SideEffectLevel::class,
            'severity' => AdverseEventSeverity::class,
            'started_on' => 'date',
            'resolved_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }
}
