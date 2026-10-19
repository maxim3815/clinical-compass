<?php

namespace App\Models;

use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;
use Database\Factories\PrescriptionIntakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Приём регулярного назначения: время суток и сколько таблеток. Точное время выбирает пациент.
 * У препарата, добавленного пациентом, таблеток нет: доза записана текстом в назначении.
 *
 * @property int $id
 * @property int $prescription_id
 * @property TimeOfDay $time_of_day
 * @property string|null $tablets
 * @property string|null $time Местное время приёма по часовому поясу пациента, «08:00».
 * @property CarbonImmutable|null $time_set_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Prescription $prescription
 */
#[Fillable(['prescription_id', 'time_of_day', 'tablets', 'time', 'time_set_at'])]
class PrescriptionIntake extends Model
{
    /** @use HasFactory<PrescriptionIntakeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Приёмы в порядке в течение дня: утром, днём, вечером, перед сном.
     *
     * @param  Collection<int, self>  $intakes
     * @return Collection<int, self>
     */
    public static function sortByTimeOfDay(Collection $intakes): Collection
    {
        return $intakes->sortBy(fn (self $intake): int => $intake->time_of_day->position())->values();
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
            'time_set_at' => 'datetime',
        ];
    }

    /**
     * PostgreSQL отдаёт время с секундами («08:00:00»), а выбирают его с точностью до минуты.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function time(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }
}
