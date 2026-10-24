<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DrugFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Препарат справочника по МНН. Источник данных — инструкция конкретного
 * зарегистрированного препарата (source_*).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $inn
 * @property string $atc
 * @property string $dosage_form
 * @property string $regimen
 * @property string $patient_instruction
 * @property string $missed_dose
 * @property int|null $window_hours
 * @property string $source_trade_name
 * @property string $source_document
 * @property string $source_registration_number
 * @property string $source_url
 * @property string $source_edition
 * @property bool $is_active
 * @property list<string> $groups
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, DrugStrength> $strengths
 * @property-read Collection<int, SafetyRule> $safetyRules
 * @property-read Collection<int, DrugSideEffect> $sideEffects
 */
#[Fillable([
    'code',
    'name',
    'inn',
    'atc',
    'dosage_form',
    'regimen',
    'patient_instruction',
    'missed_dose',
    'window_hours',
    'source_trade_name',
    'source_document',
    'source_registration_number',
    'source_url',
    'source_edition',
    'is_active',
    'groups',
])]
class Drug extends Model
{
    /** @use HasFactory<DrugFactory> */
    use HasFactory;

    /**
     * Код препарата: латиница в нижнем регистре, цифры и подчёркивание. Код стоит в адресе карточки.
     */
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

    /**
     * Все дозировки, в том числе выведенные из справочника.
     *
     * @return HasMany<DrugStrength, $this>
     */
    public function strengths(): HasMany
    {
        return $this->hasMany(DrugStrength::class);
    }

    /**
     * Правила проверки назначения, в том числе выключенные.
     *
     * @return HasMany<SafetyRule, $this>
     */
    public function safetyRules(): HasMany
    {
        return $this->hasMany(SafetyRule::class);
    }

    /**
     * Побочные эффекты по разделу 4 листка-вкладыша с группой срочности.
     *
     * @return HasMany<DrugSideEffect, $this>
     */
    public function sideEffects(): HasMany
    {
        return $this->hasMany(DrugSideEffect::class);
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
            'window_hours' => 'integer',
            'is_active' => 'boolean',
            'groups' => 'array',
        ];
    }
}
