<?php

namespace App\Models;

use App\Enums\SideEffectLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Группа симптома у препарата по разделу 4 его листка-вкладыша. У группы «нужна помощь сейчас»
 * могут быть слова листка о приёме препарата: их пациент видит после отправки.
 *
 * @property int $id
 * @property int $drug_id
 * @property int $side_effect_id
 * @property SideEffectLevel $level
 * @property string|null $instruction
 * @property-read Drug $drug
 * @property-read SideEffect $sideEffect
 */
#[Fillable(['drug_id', 'side_effect_id', 'level', 'instruction'])]
class DrugSideEffect extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Drug, $this>
     */
    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    /**
     * @return BelongsTo<SideEffect, $this>
     */
    public function sideEffect(): BelongsTo
    {
        return $this->belongsTo(SideEffect::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => SideEffectLevel::class,
        ];
    }
}
