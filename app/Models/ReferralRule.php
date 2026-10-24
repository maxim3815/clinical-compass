<?php

namespace App\Models;

use App\Enums\ReferralCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Правило подсказки направления к психиатру из database/data/referral-rules.yaml: категория,
 * условие на языке выражений (App\Support\Referrals\ReferralLanguage), основание для врача
 * и источник.
 *
 * @property int $id
 * @property string $code
 * @property ReferralCategory $category
 * @property string $condition
 * @property string $text
 * @property array{document: string, section: string, url: string, quote?: string|null} $source
 * @property int $position
 * @property string $version
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'category', 'condition', 'text', 'source', 'position', 'version', 'is_active'])]
class ReferralRule extends Model
{
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

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
            'category' => ReferralCategory::class,
            'source' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
