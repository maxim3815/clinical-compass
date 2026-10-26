<?php

namespace Database\Seeders;

use App\Actions\CreateDoctor;
use App\Actions\InvitePatient;
use App\Actions\RateScale;
use App\Actions\ReferPatient;
use App\Enums\AccessLinkPurpose;
use App\Enums\AdverseEventSeverity;
use App\Enums\DoctorKind;
use App\Enums\DoseEventStatus;
use App\Enums\HelpEventType;
use App\Enums\Mood;
use App\Enums\PrescriptionSource;
use App\Enums\ReferralCategory;
use App\Events\AlertsChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\PatientSignalsChanged;
use App\Events\UnreadMessagesChanged;
use App\Jobs\CheckAlertRules;
use App\Models\AccessLink;
use App\Models\AdverseEvent;
use App\Models\Assessment;
use App\Models\DoseEvent;
use App\Models\HelpEvent;
use App\Models\Referral;
use App\Models\Scale;
use App\Models\SideEffect;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\DoseEventPresenter;
use App\Support\Moods\MoodPresenter;
use App\Support\Referrals\ReferralEngine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Database\Seeders\Demo\DemoHistory;
use Database\Seeders\Demo\DemoPatients;
use DateInterval;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Демо-данные для локальной разработки и показа: справочники, правила проверки, опросники,
 * два психиатра, терапевт и 15 пациентов с клиническим профилем, назначениями, историей приёмов,
 * опросников и перепиской (план — в DemoPatients). История строится
 * до текущего момента. У всех пользователей пароль `password`.
 * Второй психиатр со своими пациентами нужен, чтобы показать изоляцию данных, терапевт —
 * отбор и направление к психиатру.
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

    /**
     * Постоянный адрес терапевта `therapist` для подключения на приёме: /join/kravtsova.
     */
    public const string THERAPIST_JOIN_CODE = 'kravtsova';

    /**
     * Смирнов: плохое настроение несколько дней подряд при исправном приёме.
     */
    public const string LOW_MOOD_LOGIN = 'patient_1c';

    /**
     * HADS Зайцевой на приёме у терапевта, утверждения о тревоге и депрессии вперемешку, как в шкале:
     * тревога 14 из 21 (клинически выраженная, «желательно»), депрессия 9 (субклинически
     * выраженная). Выше всего страх, что случится ужасное, и внезапная паника; неусидчивости нет —
     * из дома она не выходит [медик].
     */
    public const array ZAYTSEVA_HADS = [2, 1, 3, 1, 2, 2, 2, 1, 2, 1, 0, 2, 3, 1];

    public function run(CreateDoctor $createDoctor, DemoHistory $history): void
    {
        // Справочники и правила загружаются теми же командами, что и вне локального окружения.
        foreach (['app:drugs:import' => 'Справочник препаратов', 'app:icd10:import' => 'Справочник МКБ-10', 'app:rules:import' => 'Правила проверки', 'app:scales:import' => 'Опросники', 'app:referral-rules:import' => 'Правила подсказки направления', 'app:alerts:import' => 'Правила алертинга'] as $command => $title) {
            if ($this->command->call($command) !== Command::SUCCESS) {
                throw new RuntimeException("Не загружено: {$title}, ошибки выше.");
            }
        }

        $doctors = [
            'doctor' => $this->doctor($createDoctor, 'doctor', 'Смирнова Анна Сергеевна'),
            'doctor2' => $this->doctor($createDoctor, 'doctor2', 'Волков Дмитрий Андреевич'),
            'therapist' => $this->doctor($createDoctor, 'therapist', 'Кравцова Ирина Олеговна', DoctorKind::Therapist),
        ];

        // Карточку с QR для показа печатают заранее, поэтому адрес не меняется от сброса к сбросу.
        $doctors['doctor']->doctorProfile?->update(['join_code' => self::JOIN_CODE]);
        $doctors['therapist']->doctorProfile?->update(['join_code' => self::THERAPIST_JOIN_CODE]);

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
        // Лебедев бросил «Авиандр» из-за сонливости: сообщил о ней, врач ещё не смотрел.
        $this->adverseEvent('patient_3c', 'drowsiness', AdverseEventSeverity::Severe, started: 3, reported: [9, '21:30']);
        // У Иванова головная боль в первые дни прошла сама, как обещает листок.
        $this->adverseEvent('patient', 'headache', AdverseEventSeverity::Mild, started: 2, reported: [2, '19:10'], resolved: 5, reviewed: [3, '10:05']);
        // Зайцеву терапевт направил к Смирновой «желательно»: направление ждёт психиатра.
        $this->quietly(fn () => $this->referral('patient_4c', $doctors['therapist'], $doctors['doctor']));
        // На стенде, выложенном до HADS, у Зайцевой только HARS: терапевт заполняет HADS сегодня.
        $this->quietly(fn () => $this->hads('patient_4c', $doctors['therapist']));
        // Лица настроения после отметок приёма до вчера; у Смирнова последние дни грустные — метка в списке.
        $this->moods();
    }

    /**
     * HADS пациента терапевта сегодня, если её ещё нет ни одной: на стенде, выложенном до HADS,
     * демо-пациентка увидит новую шкалу без сброса базы. Пока психиатр не принял направление,
     * пациента ведёт терапевт, иначе ничего не меняется.
     */
    private function hads(string $login, User $therapist): void
    {
        $patient = User::query()->where('login', $login)->first();
        $scale = Scale::query()->active()->forDoctors(DoctorKind::Therapist)->where('code', 'hads')->first();

        if ($patient === null || $scale === null || $patient->activeCareRelationship()->first()?->doctor_id !== $therapist->id
            || Assessment::query()->where('patient_id', $patient->id)->where('scale_id', $scale->id)->exists()) {
            return;
        }

        app(RateScale::class)->handle($therapist, $patient, $scale, self::ZAYTSEVA_HADS, ['mania_signs' => false, 'psychotic_symptoms' => false]);
    }

    /**
     * Лица настроения у демо-пациентов к их отметкам приёма за две недели до вчера: сегодняшние
     * пациент выбирает на показе сам. Чаще хорошее, иногда обычное, изредка плохое, без серий.
     * У Смирнова плохое последние три дня: при этом он принимает исправно — повод врачу позвонить.
     * Только пациентам без лиц: на стенде без сброса добавляются к уже сыгранной истории,
     * повторная выкладка ничего не меняет.
     */
    private function moods(): void
    {
        foreach (DemoPatients::all() as $index => $plan) {
            $patient = User::query()->where('login', $plan->login)->first();

            if ($patient === null || $plan->doctor === 'therapist' || $patient->moodMarks()->exists()) {
                continue;
            }

            $today = $patient->localToday();
            $timezone = $patient->localTimezone();
            $slots = $patient->doseEvents()
                ->where('local_date', '>=', $today->subDays(MoodPresenter::DAYS - 1)->toDateString())
                ->where('local_date', '<', $today->toDateString())
                ->whereIn('status', [DoseEventStatus::Taken, DoseEventStatus::Skipped])
                ->orderBy('scheduled_at')
                ->get()
                ->map(fn (DoseEvent $event): array => [$event->local_date, DoseEventPresenter::clock($event->scheduled_at, $timezone)])
                ->unique(fn (array $slot): string => $slot[0]->toDateString().' '.$slot[1])
                ->groupBy(fn (array $slot): string => $slot[0]->toDateString());

            foreach ($slots as $day) {
                foreach ($day->values() as $position => [$date, $slot]) {
                    $daysAgo = (int) $date->diffInDays($today);
                    // Раз в девять дней грустное, по разу-два обычное, остальное хорошее; второе лицо
                    // за день не грустное: серии бывают только у Смирнова.
                    $step = ($index * 5 + $daysAgo) % 9;
                    $mood = match (true) {
                        $plan->login === self::LOW_MOOD_LOGIN && $daysAgo <= 3 => Mood::Sad,
                        $step === 0 => $position === 0 ? Mood::Sad : Mood::Neutral,
                        in_array($step, [2, 5], true) => Mood::Neutral,
                        default => Mood::Happy,
                    };

                    $patient->moodMarks()->create(['local_date' => $date->toDateString(), 'slot' => $slot, 'mood' => $mood]);
                }
            }
        }
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

    private function doctor(CreateDoctor $createDoctor, string $login, string $name, DoctorKind $kind = DoctorKind::Psychiatrist): User
    {
        return User::query()->where('login', $login)->first()
            ?? $createDoctor->handle($name, $login, self::PASSWORD, kind: $kind);
    }

    /**
     * Приём у терапевта в вечер, когда пациент подключился и ответил на опросники: через 40 минут
     * терапевт заполняет HADS, ещё через 10 направляет к психиатру. Только если пациента ведёт
     * этот терапевт и направлений у него ещё не было: при выкладке без сброса пациент не меняется.
     */
    private function referral(string $login, User $therapist, User $psychiatrist): void
    {
        $patient = User::query()->where('login', $login)->first();
        $care = $patient?->activeCareRelationship()->first();
        $scale = Scale::query()->active()->forDoctors(DoctorKind::Therapist)->where('code', 'hads')->first();

        if ($patient === null || $care === null || $scale === null || $care->doctor_id !== $therapist->id
            || Referral::query()->where('patient_id', $patient->id)->exists()) {
            return;
        }

        $frozen = CarbonImmutable::getTestNow();

        try {
            $this->travelTo($care->started_at->addMinutes(40));
            app(RateScale::class)->handle($therapist, $patient, $scale, self::ZAYTSEVA_HADS, ['mania_signs' => false, 'psychotic_symptoms' => false]);

            $this->travelTo($care->started_at->addMinutes(50));
            app(ReferPatient::class)->handle(
                $therapist,
                $patient,
                $psychiatrist,
                ReferralCategory::Desirable,
                'Три месяца не выходит из дома из-за панических атак, лечение у терапевта на дому не помогло.',
                app(ReferralEngine::class)->suggest($patient)->category,
            );
        } finally {
            $this->travelTo($frozen);
        }
    }

    private function travelTo(?CarbonInterface $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
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

    /**
     * Побочный эффект для показа, если у пациента их ещё нет. Дни считаются от начала курса
     * «Авиандра» в базе (день начала — первый), а не от сегодняшнего: сидер запускается и при
     * выкладке без сброса, когда курс начался раньше.
     *
     * @param  array{int, string}  $reported  день курса и местное время отправки
     * @param  array{int, string}|null  $reviewed  день курса и время, когда врач просмотрел
     */
    private function adverseEvent(string $login, string $symptom, AdverseEventSeverity $severity, int $started, array $reported, ?int $resolved = null, ?array $reviewed = null): void
    {
        $patient = User::query()->where('login', $login)->first();
        $sideEffect = SideEffect::query()->where('code', $symptom)->first();

        if ($patient === null || $sideEffect === null || $patient->adverseEvents()->exists()) {
            return;
        }

        $course = $patient->prescriptions()
            ->where('source', PrescriptionSource::Doctor)
            ->whereHas('drug', fn ($query) => $query->where('code', 'maritupirdine'))
            ->orderBy('starts_on')
            ->first();
        $doctor = $patient->activeCareRelationship?->doctor;

        if ($course === null || $doctor === null) {
            return;
        }

        $timezone = $patient->localTimezone();
        $day = fn (int $number, string $time): CarbonImmutable => CarbonImmutable::parse($course->starts_on->addDays($number - 1)->toDateString().' '.$time, $timezone)->utc();

        (new AdverseEvent)->forceFill([
            'patient_id' => $patient->id,
            'side_effect_id' => $sideEffect->id,
            'name' => $sideEffect->name,
            'term' => $sideEffect->term,
            'level' => $sideEffect->drugs()->where('drug_id', $course->drug_id)->value('level'),
            'severity' => $severity,
            'started_on' => $course->starts_on->addDays($started - 1),
            'reported_by_id' => $patient->id,
            'resolved_on' => $resolved === null ? null : $course->starts_on->addDays($resolved - 1),
            'reviewed_at' => $reviewed === null ? null : $day(...$reviewed),
            'reviewed_by_id' => $reviewed === null ? null : $doctor->id,
            'created_at' => $day(...$reported),
            'updated_at' => $day(...($reviewed ?? $reported)),
        ])->save();
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
