<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Демо-пользователи для локальной разработки и показа. Повторный запуск ничего не дублирует.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::query()->updateOrCreate(['login' => 'doctor'], [
            'name' => 'Смирнова Анна Сергеевна',
            'password' => 'password',
            'role' => UserRole::Doctor,
        ]);

        User::query()->updateOrCreate(['login' => 'patient'], [
            'name' => 'Иванов Пётр Алексеевич',
            'password' => 'password',
            'role' => UserRole::Patient,
        ]);
    }
}
