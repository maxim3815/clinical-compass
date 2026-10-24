<?php

namespace App\Models;

use App\Enums\AlertLevel;
use App\Enums\AlertTrigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Правило алертинга из database/data/alerts/<код>.yaml. Правило по ответам опросника задаёт
 * условие на языке выражений над ответами (App\Support\Alerts\AlertLanguage). Правило по кнопке
 * «Сообщить врачу» условия не имеет: его поднимает сам пациент на экране помощи.
 *
 * @property int $id
 * @property string $code
 * @property string $name «Суицидальный риск»
 * @property AlertLevel $level
 * @property AlertTrigger $trigger
 * @property string|null $condition Только у правила по ответам.
 * @property string $text Что сработало и что делать, для врача.
 * @property string|null $source_document
 * @property string|null $source_section
 * @property string|null $source_url
 * @property string|null $source_quote
 * @property string $version
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'code',
    'name',
    'level',
    'trigger',
    'condition',
    'text',
    'source_document',
    'source_section',
    'source_url',
    'source_quote',
    'version',
    'is_active',
])]
class AlertRule extends Model
{
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

    /**
     * Правило в том виде, в каком сработало: пишется в журнал аудита вместе с решением врача.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'code' => $this->code,
            'version' => $this->version,
            'level' => $this->level->value,
            'trigger' => $this->trigger->value,
            'condition' => $this->condition,
            'text' => $this->text,
            'source' => $this->source(),
        ];
    }

    /**
     * Источник правила. У правила по кнопке его может не быть: основание — просьба пациента.
     *
     * @return array{document: string, section: string, url: string, quote: string|null}|null
     */
    public function source(): ?array
    {
        if ($this->source_document === null || $this->source_section === null || $this->source_url === null) {
            return null;
        }

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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function triggeredBy(Builder $query, AlertTrigger $trigger): void
    {
        $query->where('trigger', $trigger->value);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => AlertLevel::class,
            'trigger' => AlertTrigger::class,
            'is_active' => 'boolean',
        ];
    }
}
