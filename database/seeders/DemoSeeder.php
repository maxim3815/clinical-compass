<?php

namespace Database\Seeders;

use App\Actions\CreateDoctor;
use App\Actions\CreatePatient;
use App\Actions\InvitePatient;
use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateInterval;
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

    /**
     * Токен демо-приглашения от `doctor`: ссылка /invite/{токен} приведена в README.
     */
    public const string INVITATION_TOKEN = 'demo-invite-patient-registration-link-01';

    public function run(CreateDoctor $createDoctor, CreatePatient $createPatient): void
    {
        $doctor = $this->doctor($createDoctor, 'doctor', 'Смирнова Анна Сергеевна');
        $doctor2 = $this->doctor($createDoctor, 'doctor2', 'Волков Дмитрий Андреевич');

        $this->patient($createPatient, $doctor, 'patient', 'Иванов Пётр Алексеевич', '1984-03-12');
        $this->patient($createPatient, $doctor, 'patient2', 'Кузнецова Елена Викторовна', '1991-07-25');
        $this->patient($createPatient, $doctor, 'patient3', 'Соколов Михаил Игоревич', '1976-11-02');
        $this->patient($createPatient, $doctor2, 'patient4', 'Морозова Ольга Павловна', '1988-05-19');

        $this->invitation($doctor, 'Новиков Артём Сергеевич');
    }

    private function doctor(CreateDoctor $createDoctor, string $login, string $name): User
    {
        return User::query()->where('login', $login)->first()
            ?? $createDoctor->handle($name, $login, self::PASSWORD);
    }

    private function invitation(User $doctor, string $patientName): void
    {
        $tokenHash = AccessLink::hashToken(self::INVITATION_TOKEN);

        if (AccessLink::query()->where('token_hash', $tokenHash)->exists()) {
            return;
        }

        AccessLink::query()->create([
            'purpose' => AccessLinkPurpose::Invitation,
            'doctor_id' => $doctor->id,
            'token_hash' => $tokenHash,
            'patient_name' => $patientName,
            'expires_at' => now()->add(new DateInterval(InvitePatient::LIFETIME)),
        ]);
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
