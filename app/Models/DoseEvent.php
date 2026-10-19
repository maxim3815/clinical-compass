<?php

namespace App\Models;

use App\Enums\DoseEventStatus;
use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;
use Database\Factories\DoseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Плановый приём: одна доза приёма регулярного назначения в конкретный момент.
 * Создаёт и пересчитывает их App\Actions\SyncDoseEvents.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescription_id
 * @property TimeOfDay $time_of_day
 * @property string $tablets
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable $window_closes_at
 * @property DoseEventStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read Prescription $prescription
 */
#[Fillable([
    'patient_id',
    'prescription_id',
    'time_of_day',
    'tablets',
    'local_date',
    'scheduled_at',
    'window_closes_at',
    'status',
])]
class DoseEvent extends Model
{
    /** @use HasFactory<DoseEventFactory> */
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
            'time_of_day' => TimeOfDay::class,
            'tablets' => 'decimal:1',
            'local_date' => 'date',
            'scheduled_at' => 'datetime',
            'window_closes_at' => 'datetime',
            'status' => DoseEventStatus::class,
        ];
    }
}
