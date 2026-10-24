<?php

namespace App\Models;

use App\Enums\AccessLinkPurpose;
use App\Enums\AccessLinkStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AccessLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одноразовая ссылка, которую врач выдаёт пациенту.
 *
 * Приглашение на приёме начинается с PIN: пока пациент его не ввёл, токена у записи нет
 * и в списке приглашений её не видно. Верный PIN выдаёт токен, дальше это обычное приглашение.
 *
 * @property int $id
 * @property AccessLinkPurpose $purpose
 * @property int $doctor_id
 * @property int|null $patient_id
 * @property string|null $token_hash
 * @property string|null $patient_name
 * @property string|null $pin_hash
 * @property CarbonImmutable|null $pin_entered_at
 * @property int|null $queue_answer_id Ответы из очереди, к которым привязан PIN со страницы кода.
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $doctor
 * @property-read User|null $patient
 * @property-read QueueAnswer|null $queueAnswer
 */
#[Fillable(['purpose', 'doctor_id', 'patient_id', 'token_hash', 'patient_name', 'pin_hash', 'pin_entered_at', 'queue_answer_id', 'expires_at', 'used_at', 'revoked_at'])]
#[Hidden(['token_hash', 'pin_hash'])]
class AccessLink extends Model
{
    /** @use HasFactory<AccessLinkFactory> */
    use HasFactory;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * PIN хэшируется вместе с врачом и ключом приложения: без ключа 4 цифры из базы не восстановить.
     */
    public static function hashPin(int $doctorId, string $pin): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', "{$doctorId}:{$pin}", is_string($key) ? $key : '');
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
     * Приглашения на приёме, PIN которых ещё ждёт ввода.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingPin(Builder $query): void
    {
        $query->where('purpose', AccessLinkPurpose::Invitation)
            ->whereNotNull('pin_hash')
            ->whereNull('token_hash')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Приглашения со ссылкой: обычные и на приёме, по которым пациент уже ввёл PIN.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withLink(Builder $query): void
    {
        $query->whereNotNull('token_hash');
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

    /**
     * @return BelongsTo<QueueAnswer, $this>
     */
    public function queueAnswer(): BelongsTo
    {
        return $this->belongsTo(QueueAnswer::class);
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
            'pin_entered_at' => 'datetime',
        ];
    }
}
