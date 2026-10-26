<?php

namespace App\Models;

use App\Enums\Mood;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Настроение после отметки приёма: лицо на время приёма (группу) в местный день пациента.
 * Необязательно; выбор меняется до конца дня.
 *
 * @property int $id
 * @property int $patient_id
 * @property CarbonImmutable $local_date
 * @property string $slot Время приёма по часам пациента без ведущего нуля: «8:30».
 * @property Mood $mood
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $patient
 */
#[Fillable(['patient_id', 'local_date', 'slot', 'mood'])]
class MoodMark extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'mood' => Mood::class,
        ];
    }
}
