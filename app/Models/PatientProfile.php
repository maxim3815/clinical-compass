<?php

namespace App\Models;

use App\Enums\RussianTimezone;
use App\Enums\Sex;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property CarbonImmutable|null $birth_date
 * @property Sex|null $sex
 * @property RussianTimezone $timezone
 * @property CarbonImmutable|null $consented_at
 * @property string|null $consent_version
 * @property CarbonImmutable|null $rules_acknowledged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(key: 'user_id', incrementing: false)]
#[Fillable(['birth_date', 'sex', 'timezone', 'consented_at', 'consent_version', 'rules_acknowledged_at'])]
class PatientProfile extends Model
{
    /**
     * Редакция текста согласия на обработку данных (ConsentText.vue): дата, которая стоит под текстом.
     * Меняется вместе с текстом.
     */
    public const string CONSENT_VERSION = '2026-10-25';

    /**
     * Как значение по умолчанию в БД, чтобы оно было у модели и до перечитывания.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Europe/Moscow',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Полных лет на сегодня или null, если дата рождения неизвестна.
     */
    public function age(): ?int
    {
        if ($this->birth_date === null) {
            return null;
        }

        return (int) $this->birth_date->diffInYears(DisplayTime::today());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => Sex::class,
            'timezone' => RussianTimezone::class,
            'consented_at' => 'datetime',
            'rules_acknowledged_at' => 'datetime',
        ];
    }
}
