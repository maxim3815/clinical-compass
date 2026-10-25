<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\CareRelationship;
use App\Models\PatientProfile;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Создаёт пациента с профилем и сразу привязывает его к лечащему врачу.
 * Данные должны быть уже проверены. Согласие принято в текущей редакции.
 */
class CreatePatient
{
    public function handle(
        User $doctor,
        string $name,
        string $login,
        string $password,
        ?DateTimeInterface $birthDate = null,
        ?DateTimeInterface $consentedAt = null,
        ?DateTimeInterface $rulesAcknowledgedAt = null,
    ): User {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Пациента можно привязать только к врачу.');
        }

        return DB::transaction(function () use ($doctor, $name, $login, $password, $birthDate, $consentedAt, $rulesAcknowledgedAt): User {
            $patient = User::query()->create([
                'name' => $name,
                'login' => $login,
                'password' => $password,
                'role' => UserRole::Patient,
            ]);

            $patient->patientProfile()->create([
                'birth_date' => $birthDate,
                'consented_at' => $consentedAt,
                'consent_version' => $consentedAt === null ? null : PatientProfile::CONSENT_VERSION,
                'rules_acknowledged_at' => $rulesAcknowledgedAt,
            ]);

            CareRelationship::query()->create([
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'started_at' => now(),
            ]);

            return $patient;
        });
    }
}
