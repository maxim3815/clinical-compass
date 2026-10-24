<?php

namespace Database\Seeders;

use App\Actions\CreateDoctor;
use App\Actions\InvitePatient;
use App\Enums\AccessLinkPurpose;
use App\Enums\HelpEventType;
use App\Events\AlertsChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\PatientSignalsChanged;
use App\Events\UnreadMessagesChanged;
use App\Jobs\CheckAlertRules;
use App\Models\AccessLink;
use App\Models\HelpEvent;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\DemoHistory;
use Database\Seeders\Demo\DemoPatients;
use DateInterval;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Демо-данные для локальной разработки и показа: справочники, правила проверки, опросники,
 * два врача и 15 пациентов с клиническим профилем, назначениями, историей приёмов, опросников
 * и перепиской (план — в DemoPatients). История строится
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

    /**
     * Постоянный адрес `doctor` для подключения на приёме: /join/smirnova.
     */
    public const string JOIN_CODE = 'smirnova';

    public function run(CreateDoctor $createDoctor, DemoHistory $history): void
    {
        // Справочники и правила загружаются теми же командами, что и вне локального окружения.
        foreach (['app:drugs:import' => 'Справочник препаратов', 'app:icd10:import' => 'Справочник МКБ-10', 'app:rules:import' => 'Правила проверки', 'app:scales:import' => 'Опросники', 'app:alerts:import' => 'Правила алертинга'] as $command => $title) {
            if ($this->command->call($command) !== Command::SUCCESS) {
                throw new RuntimeException("Не загружено: {$title}, ошибки выше.");
            }
        }

        $doctors = [
            'doctor' => $this->doctor($createDoctor, 'doctor', 'Смирнова Анна Сергеевна'),
            'doctor2' => $this->doctor($createDoctor, 'doctor2', 'Волков Дмитрий Андреевич'),
        ];

        // Карточку с QR для показа печатают заранее, поэтому адрес не меняется от сброса к сбросу.
        $doctors['doctor']->doctorProfile?->update(['join_code' => self::JOIN_CODE]);

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
        $this->helpTrace('patient_3c');
    }

    /**
     * История пишется задним числом: событиям для открытых страниц и пушам о ней взяться неоткуда.
     * Правила алертинга по истории опросников не проверяются: демо-алертов нет, алерт появляется
     * на показе, когда пациент отвечает сам.
     */
    private function quietly(Closure $callback): void
    {
        $notifications = Notification::getFacadeRoot();
        $bus = Bus::getFacadeRoot();
        Notification::fake();
        Bus::fake([CheckAlertRules::class]);

        try {
            Event::fakeFor($callback, [
                PatientSignalsChanged::class,
                AlertsChanged::class,
                MessageSent::class,
                MessagesRead::class,
                UnreadMessagesChanged::class,
            ]);
        } finally {
            Notification::swap($notifications);
            Bus::swap($bus);
        }
    }

    private function doctor(CreateDoctor $createDoctor, string $login, string $name): User
    {
        return User::query()->where('login', $login)->first()
            ?? $createDoctor->handle($name, $login, self::PASSWORD);
    }

    /**
     * Тихий след на экране помощи для показа: Лебедев бросил приём и молчит, но позапрошлой
     * ночью открывал экран помощи и нажимал звонок на кризисную линию, не сообщив врачу.
     * Врач видит это строками в карточке, алерта нет.
     */
    private function helpTrace(string $login): void
    {
        $patient = User::query()->where('login', $login)->first();

        if ($patient === null || $patient->helpEvents()->exists()) {
            return;
        }

        $night = CarbonImmutable::now(DisplayTime::TIMEZONE)->subDays(2)->setTime(2, 14);

        foreach ([[HelpEventType::Opened, 0], [HelpEventType::CallCrisisLine, 40]] as [$type, $seconds]) {
            (new HelpEvent)->forceFill([
                'patient_id' => $patient->id,
                'type' => $type,
                'created_at' => $night->addSeconds($seconds)->utc(),
            ])->save();
        }
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
