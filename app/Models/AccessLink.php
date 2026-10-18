<?php

namespace App\Models;

use App\Enums\AccessLinkPurpose;
use App\Enums\AccessLinkStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AccessLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одноразовая ссылка, которую врач выдаёт пациенту.
 *
 * @property int $id
 * @property AccessLinkPurpose $purpose
 * @property int $doctor_id
 * @property int|null $patient_id
 * @property string $token_hash
 * @property string|null $patient_name
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $doctor
 * @property-read User|null $patient
 */
#[Fillable(['purpose', 'doctor_id', 'patient_id', 'token_hash', 'patient_name', 'expires_at', 'used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class AccessLink extends Model
{
    /** @use HasFactory<AccessLinkFactory> */
    use HasFactory;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Действующая ссылка по токену или null, если её нет, она истекла, использована или отозвана.
     */
    public static function findActive(AccessLinkPurpose $purpose, string $token): ?self
    {
        $link = self::query()
            ->where('purpose', $purpose)
            ->where('token_hash', self::hashToken($token))
            ->first();

        return $link?->isActive() ? $link : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function status(): AccessLinkStatus
    {
        return match (true) {
            $this->used_at !== null => AccessLinkStatus::Used,
            $this->revoked_at !== null => AccessLinkStatus::Revoked,
            $this->expires_at->isPast() => AccessLinkStatus::Expired,
            default => AccessLinkStatus::Active,
        };
    }

    public function isActive(): bool
    {
        return $this->status() === AccessLinkStatus::Active;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => AccessLinkPurpose::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
