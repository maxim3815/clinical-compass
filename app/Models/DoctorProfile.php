<?php

namespace App\Models;

use App\Enums\DoctorKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property string $specialty
 * @property string $join_code
 * @property DoctorKind $kind
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(key: 'user_id', incrementing: false)]
#[Fillable(['specialty', 'join_code', 'kind'])]
class DoctorProfile extends Model
{
    /**
     * Буквы и цифры кода врача без похожих друг на друга (0 и o, 1, l и i): адрес диктуют вслух.
     */
    public const string JOIN_CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const int JOIN_CODE_LENGTH = 6;

    /**
     * Свободный код для адреса /join/<код>.
     */
    public static function newJoinCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::JOIN_CODE_LENGTH; $i++) {
                $code .= self::JOIN_CODE_ALPHABET[random_int(0, strlen(self::JOIN_CODE_ALPHABET) - 1)];
            }
        } while (self::query()->where('join_code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DoctorKind::class,
        ];
    }
}
