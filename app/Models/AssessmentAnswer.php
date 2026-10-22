<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ответ на вопрос опросника: номер вопроса с 1 и балл выбранного варианта.
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $item
 * @property int $score
 * @property-read Assessment $assessment
 */
#[Fillable(['assessment_id', 'item', 'score'])]
class AssessmentAnswer extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
