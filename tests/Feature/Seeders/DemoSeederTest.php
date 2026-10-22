<?php

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionSource;
use App\Enums\UserRole;
use App\Models\AccessLink;
use App\Models\Assessment;
use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Notifications\DoseReminder;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Adherence\PatientAdherence;
use App\Support\Assessments\PatientAssessments;
use App\Support\DisplayTime;
use App\Support\PatientSignals;
use App\Support\Safety\SafetyPresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

// История строится до текущего момента, поэтому часы стоят на заданном времени сегодняшнего дня.
// Каждый запуск сидера проигрывает несколько месяцев истории, поэтому тестов с ним немного.

test('demo seeder builds the planned doctors, patients and courses and can be run again', function () {
    $this->travelTo(CarbonImmutable::parse(DisplayTime::today()->toDateString().' 13:00', DisplayTime::TIMEZONE));
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $doctor = User::query()->where('login', 'doctor')->sole();
    $doctor2 = User::query()->where('login', 'doctor2')->sole();
    $patient = fn (string $login): User => User::query()->where('login', $login)->sole();
    $today = DisplayTime::today();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->doctorProfile?->specialty)->toBe('Психиатр')
        ->and($doctor->patients()->count())->toBe(14)
        ->and($doctor2->patients()->pluck('login')->all())->toBe(['patient_4b'])
        ->and(User::query()->count())->toBe(17)
        ->and(User::query()->where('role', UserRole::Patient)->whereDoesntHave('patientProfile')->exists())->toBeFalse()
        ->and(User::query()->get()->every(fn (User $user) => Hash::check('password', $user->password)))->toBeTrue()
        ->and($doctor2->can('view', $patient('patient')))->toBeFalse()
        ->and(Drug::query()->active()->count())->toBe(5)
        ->and(AccessLink::query()->count())->toBe(1)
        ->and(AdherenceSignals::count($doctor))->toBe(3)
        // Плюс Кузнецова с противопоказанием без решения; у второго врача — Николаев.
        ->and(PatientSignals::count($doctor))->toBe(4)
        ->and(PatientSignals::count($doctor2))->toBe(1);

    // Клинический профиль у всех: пол и действующий диагноз.
    $patients = User::query()->where('role', UserRole::Patient)->with('patientProfile')->get();

    expect($patients->every(fn (User $user): bool => $user->patientProfile?->sex !== null && $user->diagnoses()->active()->exists()))->toBeTrue()
        ->and($patient('patient_4b')->diagnoses()->active()->pluck('icd10_code')->all())->toBe(['F31.0'])
        ->and($patient('patient_4b')->diagnoses()->whereNotNull('resolved_on')->pluck('icd10_code')->all())->toBe(['F32.1'])
        ->and($patient('patient_2b')->observations()->with('type')->sole()->describe())->toStartWith('СКФ 52 мл/мин/1,73 м²')
        ->and($patient('patient_2c')->observations()->sole()->comment)->toBe('7 недель');

    // Находки: QT у своих препаратов Кузнецовой и сертралин вне показаний у Николаева ждут
    // решения врача; у «Авиандра» Кузнецовой три жёлтые.
    $findings = SafetyFinding::query()->with(['rule', 'patient'])->get()
        ->map(fn (SafetyFinding $finding): string => $finding->patient->login.' '.$finding->rule->code.' '.$finding->level->value)
        ->sort()->values()->all();

    expect($findings)->toBe([
        'patient2 escitalopram.qt_drugs contraindicated',
        'patient2 hydroxyzine.qt_drugs contraindicated',
        'patient2 maritupirdine.cyp2d6 caution',
        'patient2 maritupirdine.sedative caution',
        'patient2 maritupirdine.serotonergic caution',
        'patient_4b sertraline.indications contraindicated',
        'patient_4b sertraline.mania caution',
    ]);

    // У «Авиандра» Иванова ни находок, ни «Не проверено».
    $ivanov = app(SafetyPresenter::class)->card($patient('patient'));

    expect(array_values($ivanov))->toBe([['findings' => [], 'unchecked' => [], 'unknown_drugs' => []]]);

    // «Авиандр» у трети пациентов, в демо-данных все препараты справочника.
    $prescribed = fn (string $drug): array => Prescription::query()
        ->whereRelation('drug', 'code', $drug)
        ->with('patient')
        ->get()
        ->map(fn (Prescription $prescription): string => $prescription->patient->login)
        ->unique()->sort()->values()->all();

    expect($prescribed('maritupirdine'))->toBe(['patient', 'patient2', 'patient3', 'patient_1d', 'patient_3c'])
        ->and($prescribed('escitalopram'))->toBe(['patient2', 'patient_1b', 'patient_1c'])
        ->and($prescribed('paroxetine'))->toBe(['patient_1d', 'patient_3b', 'patient_norm2'])
        ->and($prescribed('sertraline'))->toBe(['patient_4b'])
        ->and($prescribed('hydroxyzine'))->toBe(['patient2'])
        ->and(User::query()->where('role', UserRole::Patient)->whereDoesntHave('prescriptions')->pluck('login')->sort()->values()->all())
        ->toBe(['patient4', 'patient_2b', 'patient_2c', 'patient_4c', 'patient_norm1']);

    // Курсы идут с нужного дня, у Фёдоровой пароксетин отменён из-за тошноты.
    $card = fn (string $login): array => PatientAdherence::present($patient($login), $today);
    $fedorova = $card('patient_1d');

    expect($card('patient')['courses'][0]['day'])->toBe(112)
        ->and($card('patient_norm2')['courses'][0]['day'])->toBe(252)
        ->and($card('patient_norm2')['courses'][0]['periods'])->toHaveCount(11)
        ->and($fedorova['courses'][0]['drug'])->toContain('Авиандр')
        ->and($fedorova['courses'][0]['day'])->toBe(84)
        ->and($fedorova['finished'])->toHaveCount(1)
        ->and($fedorova['finished'][0]['drug'])->toContain('Пароксетин')
        ->and(Prescription::query()->whereRelation('drug', 'code', 'paroxetine')->whereRelation('patient', 'login', 'patient_1d')->sole()->cancel_reason)
        ->toBe(PrescriptionCancelReason::AdverseEvents);

    // У Кузнецовой свои эсциталопрам и гидроксизин «по необходимости».
    $own = $card('patient2')['own'];

    expect(collect($own)->pluck('kind')->all())->toEqualCanonicalizing(['regular', 'as_needed'])
        ->and(Prescription::query()->where('source', PrescriptionSource::Patient)->whereRelation('patient', 'login', 'patient2')->count())->toBe(2)
        ->and($patient('patient2')->asNeededDoses()->count())->toBe(5);

    // Соколов утром почти всегда принимает, вечером — реже половины.
    $sokolov = collect($card('patient3')['courses'][0]['periods'])->firstWhere('current', true);

    expect(collect($sokolov['by_time_of_day'])->pluck('share', 'label')->all())->toBe(['утром' => 92, 'вечером' => 46]);

    // Иванов: утренний приём отмечен по плану, вечерний ждёт.
    $ivanovToday = DoseEvent::query()
        ->where('patient_id', $patient('patient')->id)
        ->where('local_date', $today->toDateString())
        ->orderBy('scheduled_at')
        ->pluck('status')
        ->all();

    expect($ivanovToday)->toBe([DoseEventStatus::Taken, DoseEventStatus::Scheduled]);

    // Вечером по нему приходит напоминание, если пациент включил пуши.
    Notification::fake();
    subscribeToPush($patient('patient'));
    $this->travelTo(CarbonImmutable::parse($today->toDateString().' 19:30', DisplayTime::TIMEZONE));
    $this->artisan('app:dose-events:send-reminders')->assertSuccessful();

    Notification::assertSentTo($patient('patient'), DoseReminder::class);

    // Последние сообщения Соколова и Николаева врачи ещё не прочитали.
    $unread = Message::query()
        ->whereNull('read_at')
        ->with('sender')
        ->get()
        ->map(fn (Message $message): string => $message->sender->login)
        ->countBy()
        ->all();

    expect($unread)->toBe(['patient3' => 1, 'patient_4b' => 2]);

    // Опросники по профилям у всех, у кого в профиле есть баллы. Морозова ещё не заполняла:
    // оба опросника ждут её на «Сегодня». Ответ на 9-й вопрос PHQ-9 везде 0: демо-алертов нет.
    $filled = Assessment::query()->with('patient')->get()->groupBy(fn (Assessment $assessment): string => $assessment->patient->login);
    $status = fn (string $login): ?array => PatientAssessments::status($patient($login));
    $last = fn (string $login, string $scale): ?int => Assessment::query()
        ->where('patient_id', $patient($login)->id)
        ->whereRelation('scale', 'code', $scale)
        ->orderByDesc('submitted_at')
        ->value('total');

    expect($filled->keys()->sort()->values()->all())->toBe([
        'patient', 'patient2', 'patient3', 'patient_1b', 'patient_1c', 'patient_1d', 'patient_2b', 'patient_2c', 'patient_4c', 'patient_norm1', 'patient_norm2',
    ])
        ->and(Assessment::query()->whereRelation('scale', 'code', 'phq9')->whereHas('answers', fn ($query) => $query->where('item', 9)->where('score', '>', 0))->exists())->toBeFalse()
        ->and($filled['patient']->count())->toBe(14)
        ->and($last('patient', 'gad7'))->toBe(4)
        ->and($last('patient', 'phq9'))->toBe(2)
        ->and($status('patient')['due'] ?? null)->toBeFalse()
        ->and($last('patient_norm2', 'gad7'))->toBe(1)
        ->and($last('patient_2b', 'gad7'))->toBe(14)
        ->and($status('patient4'))->toMatchArray(['due' => true, 'last' => null]);

    $this->get(route('invitation.show', DemoSeeder::INVITATION_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitation/Register')
            ->where('patientName', 'Новиков Артём Сергеевич'),
        );
});

test('only the planned patients have an adherence signal and the counters are the same at any time of day', function (string $time) {
    $this->travelTo(CarbonImmutable::parse(DisplayTime::today()->toDateString().' '.$time, DisplayTime::TIMEZONE));
    $this->seed(DemoSeeder::class);

    $doctor = User::query()->where('login', 'doctor')->sole();
    $doctor2 = User::query()->where('login', 'doctor2')->sole();
    $signals = User::query()
        ->where('role', UserRole::Patient)
        ->get()
        ->sortBy('login')
        ->mapWithKeys(fn (User $patient): array => [$patient->login => AdherenceSignals::forPatient($patient)]);

    expect($signals->filter->isRaised()->keys()->all())->toBe(['patient3', 'patient_3b', 'patient_3c'])
        ->and($signals['patient3']->isLowShare())->toBeTrue()
        ->and($signals['patient_3c']->hasConsecutiveMisses())->toBeTrue()
        ->and(AdherenceSignals::count($doctor))->toBe(3)
        ->and(AdherenceSignals::count($doctor2))->toBe(0)
        ->and(PatientSignals::count($doctor))->toBe(4)
        ->and(PatientSignals::count($doctor2))->toBe(1);
})->with([
    'утром до приёмов' => '07:00',
    'поздно вечером' => '23:30',
]);

test('database seeder adds demo data only in the local environment', function () {
    $this->seed();

    expect(User::query()->count())->toBe(0)
        ->and(Drug::query()->count())->toBe(0);
});
