<?php

namespace App\Models;

use App\Enums\DrugKind;
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
 * Препарат по МНН. У препарата справочника (catalog) источник данных — инструкция конкретного
 * зарегистрированного препарата (source_*), поля инструкции заполнены. У препарата из списка
 * препаратов от других врачей (known) их нет: только группы, торговые названия, основание
 * (reason) и номер РУ одного препарата из ГРЛС (source_trade_name, source_registration_number).
 *
 * @property int $id
 * @property string $code
 * @property DrugKind $kind
 * @property string $name
 * @property string $inn
 * @property string $atc
 * @property string|null $dosage_form
 * @property string|null $regimen
 * @property string|null $patient_instruction
 * @property string|null $missed_dose
 * @property int|null $window_hours
 * @property string $source_trade_name
 * @property string|null $source_document
 * @property string $source_registration_number
 * @property string|null $source_url
 * @property string|null $source_edition
 * @property bool $is_active
 * @property list<string> $groups
 * @property list<string> $trade_names
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, DrugStrength> $strengths
 * @property-read Collection<int, SafetyRule> $safetyRules
 * @property-read Collection<int, DrugSideEffect> $sideEffects
 */
#[Fillable([
    'code',
    'kind',
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
    'trade_names',
    'reason',
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
     * Почему препарат в списке препаратов от других врачей, для врача: пометка [медик] нужна
     * только для сверки файла.
     */
    public function publicReason(): ?string
    {
        return $this->reason === null ? null : trim(str_replace(' [медик]', '', $this->reason));
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
     * Препараты справочника: их назначает врач, у них есть правила и побочные эффекты.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function catalog(Builder $query): void
    {
        $query->where('kind', DrugKind::Catalog);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DrugKind::class,
            'window_hours' => 'integer',
            'is_active' => 'boolean',
            'groups' => 'array',
            'trade_names' => 'array',
        ];
    }
}
