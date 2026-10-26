<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заполненный опросник пациента или шкала врача: сумма и степень на момент заполнения, ответы
 * на каждый вопрос. Запись не меняется и не удаляется: так требует триггер в БД.
 *
 * @phpstan-import-type Subscore from Scale
 *
 * @property int $id
 * @property int $patient_id
 * @property int $scale_id
 * @property string $scale_version
 * @property int $total
 * @property string $band
 * @property list<Subscore>|null $subscores Суммы и степени подшкал (HADS, тревога, депрессия).
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $submitted_at Когда пациент отправил ответы. У опросников, отправленных вместе, одно время.
 * @property int|null $rated_by_id Врач, который заполнил шкалу врача на приёме. У шкал пациента пусто.
 * @property-read Scale $scale
 * @property-read User|null $ratedBy
 * @property-read User $patient
 * @property-read Collection<int, AssessmentAnswer> $answers
 */
#[Fillable(['patient_id', 'scale_id', 'scale_version', 'total', 'band', 'subscores', 'local_date', 'submitted_at', 'rated_by_id'])]
class Assessment extends Model
{
    public const ?string CREATED_AT = 'submitted_at';

    public const ?string UPDATED_AT = null;

    /**
     * Время отправки хранится с миллисекундами: от него считается время доставки алерта.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

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
     * @return BelongsTo<User, $this>
     */
    public function ratedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by_id');
    }

    /**
     * @return HasMany<AssessmentAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class)->orderBy('item');
    }

    /**
     * Сумма и степень подшкалы на момент заполнения; null — такой подшкалы в заполнении нет.
     *
     * @return Subscore|null
     */
    public function subscore(string $code): ?array
    {
        foreach ($this->subscores ?? [] as $subscore) {
            if ($subscore['code'] === $code) {
                return $subscore;
            }
        }

        return null;
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
            'subscores' => 'array',
        ];
    }
}
