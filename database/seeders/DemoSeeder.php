<?php

namespace Database\Seeders;

use App\Actions\CreateDoctor;
use App\Actions\InvitePatient;
use App\Enums\AccessLinkPurpose;
use App\Events\AdherenceSignalsChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\UnreadMessagesChanged;
use App\Models\AccessLink;
use App\Models\User;
use Closure;
use Database\Seeders\Demo\DemoHistory;
use Database\Seeders\Demo\DemoPatients;
use DateInterval;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Демо-данные для локальной разработки и показа: справочник препаратов, два врача и 15 пациентов
 * с назначениями, историей приёмов и перепиской (план — в DemoPatients). История строится
 * до текущего момента. У всех пользователей пароль `password`.
 * Второй врач со своими пациентами нужен, чтобы показать изоляцию данных.
 * Повторный запуск не создаёт дубликатов: пациент, который уже есть, не меняется.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string PASSWORD = 'password';

    /**
     * Токен демо-приглашения от `doctor`: ссылка /invite/{токен} приведена в README.
     */
    public const string INVITATION_TOKEN = 'demo-invite-patient-registration-link-01';

    public function run(CreateDoctor $createDoctor, DemoHistory $history): void
    {
        // Справочник препаратов загружается той же командой, что и вне локального окружения.
        if ($this->command->call('app:drugs:import') !== Command::SUCCESS) {
            throw new RuntimeException('Справочник препаратов не загружен, ошибки выше.');
        }

        $doctors = [
            'doctor' => $this->doctor($createDoctor, 'doctor', 'Смирнова Анна Сергеевна'),
            'doctor2' => $this->doctor($createDoctor, 'doctor2', 'Волков Дмитрий Андреевич'),
        ];

        $this->quietly(function () use ($doctors, $history): void {
            foreach (DemoPatients::all() as $plan) {
                if (! User::query()->where('login', $plan->login)->exists()) {
                    $history->play($plan, $doctors[$plan->doctor], self::PASSWORD);
                }
            }
        });

        // Пропуски на текущий момент отмечает та же команда, что запускает scheduler.
        $this->command->callSilently('app:dose-events:mark-missed');

        $this->invitation($doctors['doctor'], 'Новиков Артём Сергеевич');
    }

    /**
     * История пишется задним числом: событиям для открытых страниц и пушам о ней взяться неоткуда.
     */
    private function quietly(Closure $callback): void
    {
        $notifications = Notification::getFacadeRoot();
        Notification::fake();

        try {
            Event::fakeFor($callback, [
                AdherenceSignalsChanged::class,
                MessageSent::class,
                MessagesRead::class,
                UnreadMessagesChanged::class,
            ]);
        } finally {
            Notification::swap($notifications);
        }
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
}
