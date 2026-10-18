<?php

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $login
 * @property string $password
 * @property UserRole $role
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DoctorProfile|null $doctorProfile
 * @property-read PatientProfile|null $patientProfile
 * @property-read User|null $currentDoctor
 */
#[Fillable(['name', 'login', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return HasOne<DoctorProfile, $this>
     */
    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    /**
     * @return HasOne<PatientProfile, $this>
     */
    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    /**
     * Пациенты врача с действующей связью «лечащий врач».
     *
     * @return BelongsToMany<User, $this>
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'care_relationships', 'doctor_id', 'patient_id')
            ->wherePivotNull('ended_at')
            ->withPivot('started_at')
            ->withTimestamps();
    }

    /**
     * Лечащий врач пациента по действующей связи.
     *
     * @return HasOneThrough<User, CareRelationship, $this>
     */
    public function currentDoctor(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, CareRelationship::class, 'patient_id', 'id', 'id', 'doctor_id')
            ->whereNull('care_relationships.ended_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Логин хранится в нижнем регистре, поэтому уникален без учёта регистра.
     *
     * @return Attribute<string, string>
     */
    protected function login(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower($value),
        );
    }
}
