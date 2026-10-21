<?php

namespace App\Models;

use App\Enums\ObservationValueType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Вид факта о пациенте из справочника database/data/observation-types.yaml.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ObservationValueType $value_type
 * @property string|null $unit
 * @property string|null $min
 * @property string|null $max
 * @property list<array{value: string, label: string}>|null $options
 * @property bool $female_only
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'value_type', 'unit', 'min', 'max', 'options', 'female_only', 'position', 'is_active'])]
class ObservationType extends Model
{
    /**
     * Код вида факта: латиница в нижнем регистре, цифры и подчёркивание. Код стоит в условиях правил.
     */
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

    /**
     * Значение для врача: «да», «52 мл/мин/1,73 м²», «класс B по Чайлд-Пью».
     */
    public function format(bool|float|string $value): string
    {
        return match ($this->value_type) {
            ObservationValueType::Boolean => $value ? 'да' : 'нет',
            ObservationValueType::Number => trim(self::number((float) $value).' '.$this->unit),
            ObservationValueType::Choice => $this->optionLabel((string) $value) ?? (string) $value,
        };
    }

    public function optionLabel(string $value): ?string
    {
        foreach ($this->options ?? [] as $option) {
            if ($option['value'] === $value) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * Число без лишних нулей и с запятой: «52», «1,5».
     */
    public static function number(float $value): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_type' => ObservationValueType::class,
            'min' => 'decimal:2',
            'max' => 'decimal:2',
            'options' => 'array',
            'female_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
