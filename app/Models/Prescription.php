<?php

namespace App\Models;

use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\PrescriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Версия назначения препарата. Изменение действующего назначения закрывает версию
 * (close_reason = changed) и создаёт следующую со ссылкой previous_id. Даты начала
 * и окончания — по часовому поясу пациента.
 *
 * @property int $id
 * @property int $patient_id
 * @property PrescriptionSource $source
 * @property int|null $prescriber_id
 * @property int|null $previous_id
 * @property int $drug_id
 * @property int $drug_strength_id
 * @property PrescriptionKind $kind
 * @property string|null $tablets
 * @property int|null $max_daily_doses
 * @property string|null $instruction
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by_id
 * @property PrescriptionCloseReason|null $close_reason
 * @property PrescriptionCancelReason|null $cancel_reason
 * @property string|null $cancel_comment
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 * @property-read User|null $prescriber
 * @property-read Prescription|null $previous
 * @property-read Drug $drug
 * @property-read DrugStrength $strength
 * @property-read Collection<int, PrescriptionIntake> $intakes
 * @property-read Collection<int, DoseEvent> $doseEvents
 */
#[Fillable([
    'patient_id',
    'source',
    'prescriber_id',
    'previous_id',
    'drug_id',
    'drug_strength_id',
    'kind',
    'tablets',
    'max_daily_doses',
    'instruction',
    'starts_on',
    'ends_on',
    'effective_at',
    'closed_at',
    'closed_by_id',
    'close_reason',
    'cancel_reason',
    'cancel_comment',
])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /**
     * Больше приёмов в сутки у регулярного назначения не бывает: время суток у приёмов не повторяется.
     */
    public const int MAX_INTAKES = 4;

    /**
     * Защита от опечаток: больше таблеток за приём и приёмов «по необходимости» в сутки не назначают.
     */
    public const int MAX_TABLETS = 10;

    public const int MAX_DAILY_DOSES = 10;

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
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'previous_id');
    }

    /**
     * @return BelongsTo<Drug, $this>
     */
    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    /**
     * @return BelongsTo<DrugStrength, $this>
     */
    public function strength(): BelongsTo
    {
        return $this->belongsTo(DrugStrength::class, 'drug_strength_id');
    }

    /**
     * Приёмы регулярного назначения. Порядок в течение дня — PrescriptionIntake::sortByTimeOfDay().
     *
     * @return HasMany<PrescriptionIntake, $this>
     */
    public function intakes(): HasMany
    {
        return $this->hasMany(PrescriptionIntake::class);
    }

    /**
     * Плановые приёмы этой версии.
     *
     * @return HasMany<DoseEvent, $this>
     */
    public function doseEvents(): HasMany
    {
        return $this->hasMany(DoseEvent::class);
    }

    /**
     * Состояние назначения на дату пациента.
     */
    public function status(CarbonImmutable $today): PrescriptionStatus
    {
        return match (true) {
            $this->close_reason === PrescriptionCloseReason::Changed => PrescriptionStatus::Changed,
            $this->close_reason === PrescriptionCloseReason::Cancelled => PrescriptionStatus::Cancelled,
            $this->ends_on !== null && $this->ends_on->lt($today) => PrescriptionStatus::Completed,
            $this->starts_on->gt($today) => PrescriptionStatus::Upcoming,
            default => PrescriptionStatus::Active,
        };
    }

    /**
     * Врач может изменить или отменить назначение: его ведёт врач, а не пациент, и оно действует или ещё не началось.
     */
    public function isChangeableByDoctor(CarbonImmutable $today): bool
    {
        return $this->source === PrescriptionSource::Doctor && $this->status($today)->isCurrent();
    }

    /**
     * Пациент выбирает время приёмов у регулярного назначения врача, которое действует или ещё не началось.
     */
    public function acceptsPatientTimes(CarbonImmutable $today): bool
    {
        return $this->source === PrescriptionSource::Doctor
            && $this->kind === PrescriptionKind::Regular
            && $this->status($today)->isCurrent();
    }

    /**
     * Незакрытые версии: не изменены и не отменены. Сюда входят и назначения, завершённые по дате.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Действующие и будущие назначения на дату пациента.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query, CarbonImmutable $today): void
    {
        $query->whereNull('closed_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $today->toDateString()));
    }

    /**
     * Незакрытые назначения врача на тот же препарат и вид, даты которых пересекаются с периодом.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(
        Builder $query,
        int $patientId,
        int $drugId,
        PrescriptionKind $kind,
        CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn,
    ): void {
        $query->whereNull('closed_at')
            ->where('source', PrescriptionSource::Doctor)
            ->where('patient_id', $patientId)
            ->where('drug_id', $drugId)
            ->where('kind', $kind)
            ->where(fn (Builder $query) => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $startsOn->toDateString()))
            ->when($endsOn !== null, fn (Builder $query) => $query->where('starts_on', '<=', $endsOn?->toDateString()));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => PrescriptionSource::class,
            'kind' => PrescriptionKind::class,
            'tablets' => 'decimal:1',
            'max_daily_doses' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'effective_at' => 'datetime',
            'closed_at' => 'datetime',
            'close_reason' => PrescriptionCloseReason::class,
            'cancel_reason' => PrescriptionCancelReason::class,
        ];
    }
}
