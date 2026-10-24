<?php

namespace App\Models;

use App\Enums\HelpEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пациент открыл экран помощи или нажал на нём звонок. Алерта, пуша и счётчика нет:
 * врач видит это тихой строкой в карточке пациента.
 *
 * @property int $id
 * @property int $patient_id
 * @property HelpEventType $type
 * @property CarbonImmutable $created_at
 * @property-read User $patient
 */
#[Fillable(['patient_id', 'type'])]
class HelpEvent extends Model
{
    public const null UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.v';

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
            'type' => HelpEventType::class,
            'created_at' => 'datetime',
        ];
    }
}
