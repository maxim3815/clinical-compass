<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DrugStrengthFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Дозировка препарата: сколько миллиграммов в одной таблетке и можно ли её делить.
 *
 * @property int $id
 * @property int $drug_id
 * @property string $mg
 * @property bool $divisible
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Drug $drug
 */
#[Fillable(['drug_id', 'mg', 'divisible', 'is_active'])]
class DrugStrength extends Model
{
    /** @use HasFactory<DrugStrengthFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Drug, $this>
     */
    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    /**
     * Дозировка для интерфейса: «20 мг», «12,5 мг».
     */
    public function label(): string
    {
        $mg = rtrim(rtrim($this->mg, '0'), '.');

        return str_replace('.', ',', $mg).' мг';
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
            'mg' => 'decimal:2',
            'divisible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
