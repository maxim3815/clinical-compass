<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Диагноз пациента по МКБ-10. Не удаляется, а снимается с датой.
 *
 * @property int $id
 * @property int $patient_id
 * @property string $icd10_code
 * @property bool $is_main
 * @property CarbonImmutable $established_on
 * @property int $created_by_id
 * @property CarbonImmutable|null $resolved_on
 * @property int|null $resolved_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read Icd10Code $icd10
 * @property-read User $createdBy
 * @property-read User|null $resolvedBy
 */
#[Fillable(['patient_id', 'icd10_code', 'is_main', 'established_on', 'created_by_id', 'resolved_on', 'resolved_by_id'])]
class Diagnosis extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Icd10Code, $this>
     */
    public function icd10(): BelongsTo
    {
        return $this->belongsTo(Icd10Code::class, 'icd10_code');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * Диагноз входит в рубрику или подрубрику из правила (см. Icd10Code::covers()).
     */
    public function matches(string $code): bool
    {
        return Icd10Code::covers($code, $this->icd10_code);
    }

    /**
     * Действующие, не снятые диагнозы.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('resolved_on');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'established_on' => 'date',
            'resolved_on' => 'date',
        ];
    }
}
