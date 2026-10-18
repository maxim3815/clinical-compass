<?php

namespace Database\Seeders;

use App\Actions\CreateDoctor;
use App\Actions\CreatePatient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Демо-данные для локальной разработки и показа. У всех пользователей пароль `password`.
 * Второй врач со своим пациентом нужен, чтобы показать изоляцию данных.
 * Повторный запуск не создаёт дубликатов.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string PASSWORD = 'password';

    public function run(CreateDoctor $createDoctor, CreatePatient $createPatient): void
    {
        $doctor = $this->doctor($createDoctor, 'doctor', 'Смирнова Анна Сергеевна');
        $doctor2 = $this->doctor($createDoctor, 'doctor2', 'Волков Дмитрий Андреевич');

        $this->patient($createPatient, $doctor, 'patient', 'Иванов Пётр Алексеевич', '1984-03-12');
        $this->patient($createPatient, $doctor, 'patient2', 'Кузнецова Елена Викторовна', '1991-07-25');
        $this->patient($createPatient, $doctor, 'patient3', 'Соколов Михаил Игоревич', '1976-11-02');
        $this->patient($createPatient, $doctor2, 'patient4', 'Морозова Ольга Павловна', '1988-05-19');
    }

    private function doctor(CreateDoctor $createDoctor, string $login, string $name): User
    {
        return User::query()->where('login', $login)->first()
            ?? $createDoctor->handle($name, $login, self::PASSWORD);
    }

    private function patient(CreatePatient $createPatient, User $doctor, string $login, string $name, string $birthDate): void
    {
        if (User::query()->where('login', $login)->exists()) {
            return;
        }

        $createPatient->handle(
            doctor: $doctor,
            name: $name,
            login: $login,
            password: self::PASSWORD,
            birthDate: CarbonImmutable::parse($birthDate),
            consentedAt: now(),
        );
    }
}
