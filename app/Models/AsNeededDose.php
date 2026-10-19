<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AsNeededDoseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Приём назначения «по необходимости», записанный пациентом. Время записи — created_at.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescription_id
 * @property string|null $tablets У препарата пациента нет: доза текстом в назначении.
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $taken_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read Prescription $prescription
 */
#[Fillable([
    'patient_id',
    'prescription_id',
    'tablets',
    'local_date',
    'taken_at',
])]
class AsNeededDose extends Model
{
    /** @use HasFactory<AsNeededDoseFactory> */
    use HasFactory;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tablets' => 'decimal:1',
            'local_date' => 'date',
            'taken_at' => 'datetime',
        ];
    }
}
