<?php

namespace App\Models;

use App\Enums\ObservationValueType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт о пациенте. Записи только добавляются: изменение — новая запись, текущей считается
 * последняя по дате факта.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $observation_type_id
 * @property bool|null $value_boolean
 * @property string|null $value_number
 * @property string|null $value_choice
 * @property string|null $comment
 * @property CarbonImmutable $observed_on
 * @property int $recorded_by_id
 * @property CarbonImmutable|null $created_at
 * @property-read ObservationType $type
 * @property-read User $recordedBy
 */
#[Fillable(['patient_id', 'observation_type_id', 'value_boolean', 'value_number', 'value_choice', 'comment', 'observed_on', 'recorded_by_id'])]
class Observation extends Model
{
    public const ?string UPDATED_AT = null;

    public const int MAX_COMMENT_LENGTH = 500;

    /**
     * @return BelongsTo<ObservationType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ObservationType::class, 'observation_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * Значение в виде, в каком его видят условия правил. Нужен загруженный type.
     */
    public function value(): bool|float|string
    {
        return match ($this->type->value_type) {
            ObservationValueType::Boolean => (bool) $this->value_boolean,
            ObservationValueType::Number => (float) $this->value_number,
            ObservationValueType::Choice => (string) $this->value_choice,
        };
    }

    /**
     * «СКФ 52 мл/мин/1,73 м² на 14.10.2026». Нужен загруженный type.
     */
    public function describe(): string
    {
        $value = $this->type->format($this->value());
        $text = $this->type->value_type === ObservationValueType::Boolean
            ? $this->type->name.': '.$value
            : $this->type->name.' '.$value;

        return $text.($this->comment === null ? '' : ' ('.$this->comment.')').' на '.$this->observed_on->format('d.m.Y');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_boolean' => 'boolean',
            'value_number' => 'decimal:2',
            'observed_on' => 'date',
        ];
    }
}
