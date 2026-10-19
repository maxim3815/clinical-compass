<?php

namespace App\Models;

use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Enums\TimeOfDay;
use Carbon\CarbonImmutable;
use Database\Factories\DoseEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Плановый приём: одна доза приёма регулярного назначения в конкретный момент.
 * Создаёт и пересчитывает их App\Actions\SyncDoseEvents, отмечает пациент.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescription_id
 * @property TimeOfDay $time_of_day
 * @property string|null $tablets Доза на момент планирования. У препарата пациента её нет: доза текстом в назначении.
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable $window_closes_at
 * @property DoseEventStatus $status
 * @property CarbonImmutable|null $taken_at
 * @property CarbonImmutable|null $marked_at
 * @property DoseSkipReason|null $skip_reason
 * @property string|null $skip_comment
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
    'taken_at',
    'marked_at',
    'skip_reason',
    'skip_comment',
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
     * С какого момента приём можно отметить: незадолго до планового времени.
     */
    public function dueFrom(): CarbonImmutable
    {
        return $this->scheduled_at->subMinutes(config()->integer('medications.early_minutes'));
    }

    /**
     * Принят позже интервала «вовремя» после планового времени.
     */
    public function isTakenLate(): bool
    {
        return $this->taken_at !== null
            && $this->taken_at->gt($this->scheduled_at->addMinutes(config()->integer('medications.on_time_minutes')));
    }

    /**
     * Отметку сделали, когда окно приёма уже закрылось.
     */
    public function isMarkedAfterWindow(): bool
    {
        return $this->marked_at !== null && $this->marked_at->gt($this->window_closes_at);
    }

    /**
     * Статус приёма без отметки: запланирован, пока окно открыто, потом пропущен.
     */
    public function unmarkedStatus(CarbonImmutable $now): DoseEventStatus
    {
        return $this->window_closes_at->gt($now) ? DoseEventStatus::Scheduled : DoseEventStatus::Missed;
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
            'taken_at' => 'datetime',
            'marked_at' => 'datetime',
            'skip_reason' => DoseSkipReason::class,
        ];
    }
}
