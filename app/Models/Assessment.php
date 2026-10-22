<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заполненный пациентом опросник: сумма и степень на момент заполнения, ответы на каждый
 * вопрос. Запись не меняется и не удаляется: так требует триггер в БД.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $scale_id
 * @property string $scale_version
 * @property int $total
 * @property string $band
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $submitted_at Когда пациент отправил ответы. У опросников, отправленных вместе, одно время.
 * @property-read Scale $scale
 * @property-read User $patient
 * @property-read Collection<int, AssessmentAnswer> $answers
 */
#[Fillable(['patient_id', 'scale_id', 'scale_version', 'total', 'band', 'local_date', 'submitted_at'])]
class Assessment extends Model
{
    public const ?string CREATED_AT = 'submitted_at';

    public const ?string UPDATED_AT = null;

    /**
     * @return BelongsTo<Scale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * @return HasMany<AssessmentAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class)->orderBy('item');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }
}
