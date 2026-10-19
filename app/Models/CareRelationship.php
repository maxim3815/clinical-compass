<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CareRelationshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Связь «лечащий врач»: кто ведёт пациента и в какой период.
 *
 * @property int $id
 * @property int $doctor_id
 * @property int $patient_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $doctor
 * @property-read User $patient
 * @property-read Message|null $latestMessage
 */
#[Fillable(['doctor_id', 'patient_id', 'started_at', 'ended_at'])]
class CareRelationship extends Model
{
    /** @use HasFactory<CareRelationshipFactory> */
    use HasFactory;

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
     * Сообщения чата пациента и врача по этой связи.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Пользователь — врач или пациент этой связи.
     */
    public function involves(User $user): bool
    {
        return $this->doctor_id === $user->id || $this->patient_id === $user->id;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Связь врача с пациентом.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function between(Builder $query, User $doctor, User $patient): void
    {
        $query->where('doctor_id', $doctor->id)->where('patient_id', $patient->id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
