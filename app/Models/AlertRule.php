<?php

namespace App\Models;

use App\Enums\AlertLevel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Правило алертинга из database/data/alerts/<код>.yaml. Условие пишется на языке выражений
 * над ответами опросников (App\Support\Alerts\AlertLanguage).
 *
 * @property int $id
 * @property string $code
 * @property string $name «Суицидальный риск»
 * @property AlertLevel $level
 * @property string $condition
 * @property string $text Что сработало и что делать, для врача.
 * @property string $source_document
 * @property string $source_section
 * @property string $source_url
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
            'condition' => $this->condition,
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
            'level' => AlertLevel::class,
            'is_active' => 'boolean',
        ];
    }
}
