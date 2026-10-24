<?php

namespace App\Actions;

use App\Enums\DoctorKind;
use App\Enums\UserRole;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт врача вместе с профилем. Данные должны быть уже проверены.
 */
class CreateDoctor
{
    /**
     * @param  string|null  $specialty  подпись для пациента, по умолчанию — по виду врача
     */
    public function handle(string $name, string $login, string $password, ?string $specialty = null, DoctorKind $kind = DoctorKind::Psychiatrist): User
    {
        return DB::transaction(function () use ($name, $login, $password, $specialty, $kind): User {
            $doctor = User::query()->create([
                'name' => $name,
                'login' => $login,
                'password' => $password,
                'role' => UserRole::Doctor,
            ]);

            $doctor->doctorProfile()->create([
                'specialty' => $specialty ?? $kind->specialty(),
                'join_code' => DoctorProfile::newJoinCode(),
                'kind' => $kind,
            ]);

            return $doctor;
        });
    }
}
