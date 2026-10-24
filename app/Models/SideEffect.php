<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Симптом из словаря побочных эффектов (database/data/side-effects.yaml): простыми словами
 * для пациента и термином для врача.
 *
 * @property int $id
 * @property string $code
 * @property string $name «Сводит челюсти, трудно открыть рот»
 * @property string $term «Тризм»
 * @property int $position Порядок в списке у пациента.
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'term', 'position', 'is_active'])]
class SideEffect extends Model
{
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';

    /**
     * @return HasMany<DrugSideEffect, $this>
     */
    public function drugs(): HasMany
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
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
