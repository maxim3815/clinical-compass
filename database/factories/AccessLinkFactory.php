<?php

namespace Database\Factories;

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessLink>
 */
class AccessLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purpose' => AccessLinkPurpose::Invitation,
            'doctor_id' => User::factory()->doctor(),
            'token_hash' => AccessLink::hashToken(Str::random(40)),
            'patient_name' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Ссылка с известным токеном, чтобы тест мог по ней перейти.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => AccessLink::hashToken($token),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
            'patient_id' => User::factory()->patient(),
        ]);
    }
}
