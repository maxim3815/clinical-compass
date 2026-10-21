<?php

namespace App\Models;

use App\Enums\SafetyLevel;
use App\Enums\SafetyRuleType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Правило проверки назначения из database/data/rules/<код препарата>.yaml. Условие пишется
 * на языке выражений над данными пациента (App\Support\Safety\RuleLanguage), у правила
 * показаний вместо условия — коды МКБ-10.
 *
 * @property int $id
 * @property string $code
 * @property int $drug_id
 * @property SafetyRuleType $type
 * @property SafetyLevel $level
 * @property string|null $condition
 * @property list<string>|null $icd10_codes
 * @property string $text
 * @property string|null $advice
 * @property string $source_document
 * @property string $source_section
 * @property string $source_url
 * @property string|null $source_quote
 * @property string $version
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Drug $drug
 */
#[Fillable([
    'code',
    'drug_id',
    'type',
    'level',
    'condition',
    'icd10_codes',
    'text',
    'advice',
    'source_document',
    'source_section',
    'source_url',
    'source_quote',
    'version',
    'is_active',
    'position',
])]
class SafetyRule extends Model
{
    /**
     * Код правила: код препарата и имя правила через точку, «maritupirdine.gfr».
     */
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*';

    /**
     * @return BelongsTo<Drug, $this>
     */
    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    /**
     * Правило в том виде, в каком его увидел врач: текст, уровень и источник. Пишется в журнал аудита.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'code' => $this->code,
            'version' => $this->version,
            'type' => $this->type->value,
            'level' => $this->level->value,
            'condition' => $this->condition,
            'icd10_codes' => $this->icd10_codes,
            'text' => $this->text,
            'source' => $this->source(),
        ];
    }

    /**
     * @return array{document: string, section: string, url: string, quote: string|null}
     */
    public function source(): array
    {
        return [
            'document' => $this->source_document,
            'section' => $this->source_section,
            'url' => $this->source_url,
            'quote' => $this->source_quote,
        ];
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
            'type' => SafetyRuleType::class,
            'level' => SafetyLevel::class,
            'icd10_codes' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
