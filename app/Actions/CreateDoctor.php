<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт врача вместе с профилем. Данные должны быть уже проверены.
 */
class CreateDoctor
{
    public function handle(string $name, string $login, string $password, string $specialty = 'Психиатр'): User
    {
        return DB::transaction(function () use ($name, $login, $password, $specialty): User {
            $doctor = User::query()->create([
                'name' => $name,
                'login' => $login,
                'password' => $password,
                'role' => UserRole::Doctor,
            ]);

            $doctor->doctorProfile()->create([
                'specialty' => $specialty,
                'join_code' => DoctorProfile::newJoinCode(),
            ]);

            return $doctor;
        });
    }
}
