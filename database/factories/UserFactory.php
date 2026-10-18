<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'login' => 'user'.fake()->unique()->numerify('######'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Patient,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Вместе с пользователем создаётся профиль его роли.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            match ($user->role) {
                UserRole::Doctor => $user->doctorProfile()->create([
                    'specialty' => 'Психиатр',
                ]),
                UserRole::Patient => $user->patientProfile()->create([
                    'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years'),
                    'consented_at' => now(),
                ]),
            };
        });
    }

    public function doctor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Doctor,
        ]);
    }

    public function patient(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Patient,
        ]);
    }

    /**
     * Пациент с действующей связью с указанным врачом.
     */
    public function patientOf(User $doctor): static
    {
        return $this->patient()->afterCreating(function (User $patient) use ($doctor): void {
            CareRelationship::factory()->create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
            ]);
        });
    }
}
