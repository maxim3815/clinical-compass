<?php

use App\Enums\DoseEventStatus;
use App\Enums\HelpEventType;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionSource;
use App\Enums\ReferralCategory;
use App\Enums\UserRole;
use App\Models\AccessLink;
use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Assessment;
use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\HelpEvent;
use App\Models\Message;
use App\Models\MoodMark;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\Referral;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Notifications\DoseReminder;
use App\Support\Adherence\AdherenceSignals;
use App\Support\Adherence\PatientAdherence;
use App\Support\Assessments\PatientAssessments;
use App\Support\DisplayTime;
use App\Support\Moods\MoodSignals;
use App\Support\PatientSignals;
use App\Support\Safety\SafetyPresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
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
    $therapist = User::query()->where('login', 'therapist')->sole();
    $patient = fn (string $login): User => User::query()->where('login', $login)->sole();
    $today = DisplayTime::today();

    expect($doctor->role)->toBe(UserRole::Doctor)
        ->and($doctor->doctorProfile?->specialty)->toBe('Психиатр')
        ->and($doctor->patients()->count())->toBe(13)
        ->and($doctor2->patients()->pluck('login')->all())->toBe(['patient_4b'])
        ->and($therapist->isTherapist())->toBeTrue()
        ->and($therapist->doctorProfile?->join_code)->toBe(DemoSeeder::THERAPIST_JOIN_CODE)
        ->and($therapist->patients()->pluck('login')->all())->toBe(['patient_4c'])
        ->and(User::query()->count())->toBe(18)
        ->and(User::query()->where('role', UserRole::Patient)->whereDoesntHave('patientProfile')->exists())->toBeFalse()
        // Демо-пациенты уже прочитали правила: экран правил на показе видит только новый пациент.
        ->and(PatientProfile::query()->whereNull('rules_acknowledged_at')->exists())->toBeFalse()
        ->and(User::query()->get()->every(fn (User $user) => Hash::check('password', $user->password)))->toBeTrue()
        ->and($doctor2->can('view', $patient('patient')))->toBeFalse()
        ->and(Drug::query()->catalog()->active()->count())->toBe(5)
        ->and(AccessLink::query()->count())->toBe(1)
        ->and(AdherenceSignals::count($doctor))->toBe(3)
        // Плюс Кузнецова с противопоказанием без решения и направление Зайцевой; у второго врача — Николаев.
        ->and(PatientSignals::count($doctor))->toBe(5)
        ->and(PatientSignals::count($doctor2))->toBe(1);

    // Зайцеву терапевт направил к Смирновой «желательно» по HADS и опросникам, направление ждёт.
    // Вторая выкладка HADS не добавляет: она уже есть с приёма.
    $referral = Referral::query()->sole();
    $hads = Assessment::query()->whereNotNull('rated_by_id')->sole();

    expect($referral->patient_id)->toBe($patient('patient_4c')->id)
        ->and($referral->from_doctor_id)->toBe($therapist->id)
        ->and($referral->to_doctor_id)->toBe($doctor->id)
        ->and($referral->category)->toBe(ReferralCategory::Desirable)
        ->and(array_column($referral->basis, 'code'))->toBe(['hads_anxiety_moderate', 'gad7_severe', 'phq9_moderate'])
        ->and($referral->isOpen())->toBeTrue()
        ->and($hads->rated_by_id)->toBe($therapist->id)
        ->and(array_column($hads->subscores ?? [], 'total', 'code'))->toBe(['anxiety' => 14, 'depression' => 9]);

    // Клинический профиль у всех: пол и действующий диагноз.
    $patients = User::query()->where('role', UserRole::Patient)->with('patientProfile')->get();

    expect($patients->every(fn (User $user): bool => $user->patientProfile?->sex !== null && $user->diagnoses()->active()->exists()))->toBeTrue()
        ->and($patient('patient_4b')->diagnoses()->active()->pluck('icd10_code')->all())->toBe(['F31.0'])
        ->and($patient('patient_4b')->diagnoses()->whereNotNull('resolved_on')->pluck('icd10_code')->all())->toBe(['F32.1'])
        ->and($patient('patient_2b')->observations()->with('type')->sole()->describe())->toStartWith('СКФ 52 мл/мин/1,73 м²')
        ->and($patient('patient_2c')->observations()->sole()->comment)->toBe('7 недель');

    // Находки: QT у своих препаратов Кузнецовой и сертралин вне показаний у Николаева ждут
    // решения врача; у «Авиандра» Кузнецовой три жёлтые, у эсциталопрама Смирнова с «Кардиомагнилом»
    // от кардиолога — жёлтая.
    $findings = SafetyFinding::query()->with(['rule', 'patient'])->get()
        ->map(fn (SafetyFinding $finding): string => $finding->patient->login.' '.$finding->rule->code.' '.$finding->level->value)
        ->sort()->values()->all();

    expect($findings)->toBe([
        'patient2 escitalopram.qt_drugs contraindicated',
        'patient2 hydroxyzine.qt_drugs contraindicated',
        'patient2 maritupirdine.cyp2d6 caution',
        'patient2 maritupirdine.sedative caution',
        'patient2 maritupirdine.serotonergic caution',
        'patient_1c escitalopram.bleeding caution',
        'patient_4b sertraline.indications contraindicated',
        'patient_4b sertraline.mania caution',
    ]);

    // «Кардиомагнил» Смирнов добавил сам, вторая выкладка его не повторяет; Кузнецовой, у которой
    // свои препараты уже были, ничего не добавлено.
    $cardiomagnyl = Prescription::query()->whereRelation('drug', 'code', 'acetylsalicylic_acid')->sole();

    expect($cardiomagnyl->patient_id)->toBe($patient('patient_1c')->id)
        ->and($cardiomagnyl->source)->toBe(PrescriptionSource::Patient)
        ->and($cardiomagnyl->trade_name)->toBe('Кардиомагнил')
        ->and($cardiomagnyl->doctorDrugName())->toBe('Ацетилсалициловая кислота (Кардиомагнил)')
        ->and($cardiomagnyl->starts_on->toDateString())->toBe($today->toDateString())
        ->and($cardiomagnyl->intakes()->pluck('time')->all())->toBe(['20:00'])
        ->and(SafetyFinding::query()->whereRelation('rule', 'code', 'escitalopram.bleeding')->sole()->related_prescription_ids)->toBe([$cardiomagnyl->id])
        ->and($patient('patient2')->prescriptions()->where('source', PrescriptionSource::Patient)->count())->toBe(2);

    // У «Авиандра» Иванова ни находок, ни «Не проверено».
    $ivanov = app(SafetyPresenter::class)->card($patient('patient'));

    expect(array_values($ivanov))->toBe([['findings' => [], 'unchecked' => [], 'unknown_drugs' => []]]);

    // «Авиандр» у трети пациентов, в демо-данных все препараты справочника и «Кардиомагнил» из списка.
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
        ->and($status('patient4'))->toMatchArray(['due' => true, 'last' => null])
        // Правила алертинга загружены, алерт появляется на показе, когда Морозова отвечает сама
        // или нажимает «Сообщить врачу».
        ->and(AlertRule::query()->active()->orderBy('code')->pluck('code')->all())->toBe(['adverse_event', 'help_request', 'suicide_risk'])
        ->and(Alert::query()->count())->toBe(0)
        // Тихий след на экране помощи у Лебедева: врач видит его в карточке без алерта.
        ->and(HelpEvent::query()->whereRelation('patient', 'login', 'patient_3c')->orderBy('created_at')->pluck('type')->all())
        ->toBe([HelpEventType::Opened, HelpEventType::CallCrisisLine])
        ->and(HelpEvent::query()->count())->toBe(2)
        // Побочные эффекты для показа: сонливость Лебедева ждёт врача, головная боль Иванова прошла.
        ->and(AdverseEvent::query()->with('patient')->orderBy('id')->get()->map(fn (AdverseEvent $event): array => [
            $event->patient->login,
            $event->term,
            $event->severity->value,
            $event->resolved_on === null ? null : (int) $event->started_on->diffInDays($event->resolved_on) + 1,
            $event->reviewed_at !== null,
        ])->all())->toBe([
            ['patient_3c', 'Сонливость', 'severe', null, false],
            ['patient', 'Головная боль', 'mild', 4, true],
        ]);

    $this->get(route('invitation.show', DemoSeeder::INVITATION_TOKEN))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitation/Register')
            ->where('patientName', 'Новиков Артём Сергеевич'),
        );

    // Лица настроения — к отметкам приёма за две недели, у пациентов психиатра. Второй запуск
    // сидера их не добавляет: повтор упёрся бы в уникальный ключ дня и времени.
    expect(MoodMark::query()->count())->toBeGreaterThan(100)
        ->and(MoodMark::query()->whereIn('patient_id', $therapist->patients()->select('users.id'))->exists())->toBeFalse();

    // Адрес doctor для подключения на приёме постоянный: карточку с QR печатают заранее.
    $this->get(route('join.show', DemoSeeder::JOIN_CODE))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('doctor.name', 'Смирнова Анна Сергеевна'));

    // Стенд, выложенный до HADS: у Зайцевой её нет. Выкладка без сброса добавляет HADS сегодня, один раз.
    DB::unprepared('ALTER TABLE assessments DISABLE TRIGGER assessments_append_only; ALTER TABLE assessment_answers DISABLE TRIGGER assessment_answers_append_only;');
    DB::table('assessment_answers')->where('assessment_id', $hads->id)->delete();
    DB::table('assessments')->where('id', $hads->id)->delete();
    DB::unprepared('ALTER TABLE assessments ENABLE TRIGGER assessments_append_only; ALTER TABLE assessment_answers ENABLE TRIGGER assessment_answers_append_only;');

    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $added = Assessment::query()->whereNotNull('rated_by_id')->sole();

    expect($added->patient_id)->toBe($patient('patient_4c')->id)
        ->and($added->rated_by_id)->toBe($therapist->id)
        ->and($added->local_date->toDateString())->toBe($today->toDateString())
        ->and($added->total)->toBe(23);
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
        // Плохое настроение несколько дней подряд — только у Смирнова, в любое время суток.
        ->and(array_keys(MoodSignals::streaks(User::query()->where('role', UserRole::Patient)->get())))
        ->toBe([User::query()->where('login', DemoSeeder::LOW_MOOD_LOGIN)->value('id')])
        ->and(AdherenceSignals::count($doctor))->toBe(3)
        ->and(AdherenceSignals::count($doctor2))->toBe(0)
        ->and(PatientSignals::count($doctor))->toBe(5)
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
