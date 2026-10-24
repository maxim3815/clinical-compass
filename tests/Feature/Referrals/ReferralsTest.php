<?php

use App\Actions\SubmitAssessments;
use App\Enums\DoctorKind;
use App\Enums\ReferralCategory;
use App\Enums\ScaleRater;
use App\Events\PatientSignalsChanged;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\Referral;
use App\Models\User;
use App\Support\PatientSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadReferralRules();
    loadAlertRules();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 14:05:00', 'Europe/Moscow'));
    $this->therapist = User::factory()->therapist()->create(['name' => 'Кравцова Ирина Олеговна']);
    $this->psychiatrist = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->therapist)->create(['name' => 'Орлов Игорь Сергеевич']);

    // GAD-7 = 21 и PHQ-9 = 9: подсказка «желательно» по GAD-7.
    $this->answer = fn (int $gad7 = 3, int $phq9 = 1, int $item9 = 0) => app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: $gad7, phq9: $phq9, phq9Item9: $item9));
    $this->refer = fn (array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->therapist)->post(route('doctor.patients.referrals.store', $this->patient), [
        'to_doctor_id' => $this->psychiatrist->id,
        'category' => 'desirable',
        'suggested' => 'desirable',
        ...$data,
    ]);
});

test('app:doctor:create makes a therapist', function () {
    $this->artisan('app:doctor:create', ['--name' => 'Орлова Мария Викторовна', '--login' => 'orlova', '--password' => 'secret-password', '--therapist' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Терапевт, логин orlova');

    $doctor = User::query()->where('login', 'orlova')->sole();

    expect($doctor->doctorKind())->toBe(DoctorKind::Therapist)
        ->and($doctor->doctorProfile?->specialty)->toBe('Терапевт');
});

test('the therapist cabinet has no treatment sections', function (string $route) {
    $this->actingAs($this->therapist)->get(route($route, ['patient' => $this->patient]))->assertNotFound();
})->with([
    'doctor.patients.prescriptions.create',
    'doctor.patients.chat.show',
    'doctor.patients.clinical-profile.edit',
    'doctor.conversations.index',
    'doctor.drugs.index',
    'doctor.rules.index',
]);

test('the therapist sees the doctor kind and the psychiatrist cannot refer', function () {
    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->component('doctor/therapist/Patients')->where('doctorKind', 'therapist'));

    $other = User::factory()->patientOf($this->psychiatrist)->create();

    $this->actingAs($this->psychiatrist)
        ->post(route('doctor.patients.referrals.store', $other), ['to_doctor_id' => $this->psychiatrist->id, 'category' => 'required'])
        ->assertNotFound();
});

test('the list shows who answered today with the suggestion, the referrals and every patient', function () {
    ($this->answer)();
    $quiet = User::factory()->patientOf($this->therapist)->create(['name' => 'Белов Иван Максимович']);

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/therapist/Patients')
            ->has('answered', 1)
            ->where('answered.0.name', 'Орлов Игорь Сергеевич')
            ->where('answered.0.at', '14:05')
            ->where('answered.0.suggestion', 'desirable')
            ->where('answered.0.highlight', false)
            ->where('answered.0.scales_missing', ['HARS'])
            ->where('answered.0.decision', null)
            ->where('answered.0.scales.0', ['name' => 'GAD-7', 'total' => 21, 'max' => 21, 'band' => 'выраженная тревога'])
            ->where('referrals', [])
            ->has('patients.data', 2)
            ->where('patients.data.0.name', $quiet->name),
        );
});

test('answered patients show the decision instead of the suggestion and go after the undecided', function () {
    ($this->answer)();
    $other = User::factory()->patientOf($this->therapist)->create(['name' => 'Белов Иван Максимович']);
    $this->travel(1)->minutes();
    app(SubmitAssessments::class)->handle($other, assessmentAnswers(gad7: 3, phq9: 1, phq9Item9: 0));
    ($this->refer)();

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('answered.0.name', 'Белов Иван Максимович')
            ->where('answered.0.decision', null)
            ->where('answered.1.name', 'Орлов Игорь Сергеевич')
            ->where('answered.1.decision', ['kind' => 'referred', 'category' => 'desirable']));

    $this->actingAs($this->therapist)->post(route('doctor.patients.referrals.decline', $other), ['suggested' => 'desirable', 'comment' => 'Наблюдаю сама.'])->assertSessionHasNoErrors();

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('answered.0.decision', ['kind' => 'declined', 'category' => null]));
});

test('a decline stops counting when hars raises the suggestion of the patient', function () {
    ($this->answer)();
    $this->actingAs($this->therapist)->post(route('doctor.patients.referrals.decline', $this->patient), ['suggested' => 'desirable', 'comment' => 'Наблюдаю сама.'])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();

    $this->actingAs($this->therapist)->post(route('doctor.patients.scales.store', [$this->patient, 'hars']), [
        'scores' => array_map(fn (int $item): int => max(0, min(4, 28 - 4 * $item)), range(0, 13)),
        'facts' => ['mania_signs' => false, 'psychotic_symptoms' => false],
    ])->assertSessionHas('inertia.flash_data.toast.message', 'HARS сохранена: 28 из 56, тяжелая степень выраженности тревожного расстройства. Подсказка изменилась: «обязательно», было «желательно».');

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('answered.0.decision', null)
            ->where('answered.0.suggestion', 'required'));
});

test('the card shows the scales, the suggestion with its basis and what is missing', function () {
    ($this->answer)();

    $this->actingAs($this->therapist)->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/therapist/Patient')
            ->where('suggestion.category', 'desirable')
            ->where('suggestion.matches.0.code', 'gad7_severe')
            ->where('suggestion.missing.0.scale', 'hars')
            ->where('doctorScales', [['code' => 'hars', 'name' => 'HARS']])
            ->where('psychiatrists', [['id' => $this->psychiatrist->id, 'name' => 'Смирнова Анна Сергеевна']])
            ->where('referral', null)
            ->where('declined', null)
            ->where('wellbeing.scales.2.code', 'hars'),
        );
});

test('the therapist rates HARS with both signs, the psychiatrist rates it without them', function () {
    $this->actingAs($this->therapist)->get(route('doctor.patients.scales.create', [$this->patient, 'hars']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/ScaleForm')
            ->has('scale.items', 14)
            ->where('scale.items.0.hint', 'озабоченность, ожидание наихудшего, тревожные опасения, раздражительность')
            ->where('facts.0', ['code' => 'mania_signs', 'name' => 'Признаки мании или гипомании', 'value' => null]),
        );

    $this->actingAs($this->therapist)
        ->post(route('doctor.patients.scales.store', [$this->patient, 'hars']), [
            'scores' => [4, 4, 4, 4, 4, 4, 2, 0, 0, 0, 0, 0, 0, 0],
            'facts' => ['mania_signs' => true, 'psychotic_symptoms' => false],
        ])
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast.message', 'HARS сохранена: 26 из 56, тяжелая степень выраженности тревожного расстройства. Подсказка изменилась: «обязательно», раньше направление не подсказывалось.');

    $assessment = Assessment::query()->with('scale')->sole();

    expect($assessment->rated_by_id)->toBe($this->therapist->id)
        ->and($assessment->scale->rater)->toBe(ScaleRater::Doctor)
        ->and($assessment->total)->toBe(26)
        ->and($this->patient->observations()->with('type')->get()->mapWithKeys(fn ($fact) => [$fact->type->code => $fact->value_boolean])->all())
        ->toBe(['mania_signs' => true, 'psychotic_symptoms' => false])
        ->and(AuditLog::query()->where('action', 'assessment.rated')->sole()->after)->toMatchArray(['scale' => 'hars', 'total' => 26]);

    // Психиатр: признаки не его форма, неполные оценки не сохраняются.
    $accepted = User::factory()->patientOf($this->psychiatrist)->create();

    $this->actingAs($this->psychiatrist)
        ->post(route('doctor.patients.scales.store', [$accepted, 'hars']), ['scores' => array_fill(0, 14, 1), 'facts' => ['mania_signs' => true, 'psychotic_symptoms' => true]])
        ->assertSessionHasErrors('facts');

    $this->actingAs($this->psychiatrist)
        ->post(route('doctor.patients.scales.store', [$accepted, 'hars']), ['scores' => array_fill(0, 13, 1)])
        ->assertSessionHasErrors(['scores' => 'Оцените все пункты шкалы.']);

    $this->actingAs($this->psychiatrist)
        ->post(route('doctor.patients.scales.store', [$accepted, 'hars']), ['scores' => array_fill(0, 14, 1)])
        ->assertRedirect(route('doctor.patients.show', $accepted));

    expect(Assessment::query()->where('patient_id', $accepted->id)->sole()->rated_by_id)->toBe($this->psychiatrist->id);
});

test('the patient scale is not a doctor form and the patient never sees HARS', function () {
    $this->actingAs($this->therapist)->get(route('doctor.patients.scales.create', [$this->patient, 'gad7']))->assertNotFound();

    rateHars($this->therapist, $this->patient, 20);

    $this->actingAs($this->patient)->get(route('patient.wellbeing'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('scales', 2)
            ->where('scales.0.code', 'gad7')
            ->where('scales.1.code', 'phq9'));
});

test('the therapist refers to the chosen psychiatrist with the suggestion and its basis', function () {
    Event::fake([PatientSignalsChanged::class]);
    ($this->answer)();

    ($this->refer)(['comment' => 'Просит консультацию.'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Направление отправлено: Смирнова А. С. увидит его в очереди «Требуют внимания».');

    $referral = Referral::query()->sole();

    expect($referral->category)->toBe(ReferralCategory::Desirable)
        ->and($referral->suggested_category)->toBe(ReferralCategory::Desirable)
        ->and($referral->to_doctor_id)->toBe($this->psychiatrist->id)
        ->and(array_column($referral->basis, 'code'))->toBe(['gad7_severe'])
        ->and($referral->comment)->toBe('Просит консультацию.')
        ->and($referral->isOpen())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'referral.created')->sole()->after)->toMatchArray(['category' => 'desirable', 'suggested_category' => 'desirable', 'basis' => ['gad7_severe']]);

    // Пациента ведёт терапевт, пока психиатр не примет.
    expect(CareRelationship::query()->active()->where('patient_id', $this->patient->id)->sole()->doctor_id)->toBe($this->therapist->id);
    Event::assertDispatched(PatientSignalsChanged::class, fn (PatientSignalsChanged $event): bool => $event->doctor->is($this->psychiatrist));
});

test('a decision against the suggestion needs a comment', function () {
    ($this->answer)();

    ($this->refer)(['category' => 'required'])
        ->assertSessionHasErrors(['comment' => 'Система подсказывает «желательно»: напишите, почему выбрали другую категорию.']);

    ($this->refer)(['category' => 'required', 'comment' => 'Со слов жены — бессонница и раздражительность неделю.'])->assertSessionHasNoErrors();

    expect(Referral::query()->sole()->differsFromSuggestion())->toBeTrue();
});

test('a referral without a suggestion needs a comment too', function () {
    ($this->refer)(['suggested' => null])
        ->assertSessionHasErrors(['comment' => 'Система направление не подсказывает: напишите, почему направляете.']);
});

test('a changed suggestion, a second open referral and a non-psychiatrist are refused', function () {
    ($this->answer)();

    // Терапевт видел «желательно», а пациент тем временем ответил на 9-й вопрос.
    ($this->answer)(3, 1, 2);
    ($this->refer)()->assertSessionHasErrors(['category' => 'Пока вы решали, данные пациента изменились: теперь подсказка «обязательно». Карточка обновлена, проверьте решение.']);

    ($this->refer)(['category' => 'required', 'suggested' => 'required'])->assertSessionHasNoErrors();
    ($this->refer)(['category' => 'required', 'suggested' => 'required'])
        ->assertSessionHasErrors(['category' => 'У пациента уже есть направление, которое ждёт психиатра. Карточка обновлена.']);

    ($this->refer)(['to_doctor_id' => User::factory()->therapist()->create()->id])->assertSessionHasErrors(['to_doctor_id' => 'Выберите психиатра.']);

    expect(Referral::query()->count())->toBe(1);
});

test('not referring against the suggestion needs a comment and is shown in the card', function () {
    ($this->answer)();

    $this->actingAs($this->therapist)->post(route('doctor.patients.referrals.decline', $this->patient), ['suggested' => 'desirable'])
        ->assertSessionHasErrors(['comment' => 'Система подсказывает «желательно»: напишите, почему не направляете.']);

    $this->actingAs($this->therapist)->post(route('doctor.patients.referrals.decline', $this->patient), ['suggested' => 'desirable', 'comment' => 'Тревога на фоне экзаменов, наблюдаю сама.'])
        ->assertSessionHasNoErrors();

    expect(Referral::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'referral.declined')->sole()->after)->toMatchArray(['suggested_category' => 'desirable', 'comment' => 'Тревога на фоне экзаменов, наблюдаю сама.']);

    $this->actingAs($this->therapist)->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('declined.comment', 'Тревога на фоне экзаменов, наблюдаю сама.')
            ->where('declined.at', '24.10.2026 14:05'));
});

test('the therapist withdraws an open referral, but not an accepted one', function () {
    ($this->answer)();
    ($this->refer)();
    $referral = Referral::query()->sole();

    // Чужой терапевт направления не видит.
    $this->actingAs(User::factory()->therapist()->create())->post(route('doctor.referrals.withdraw', $referral))->assertNotFound();

    $this->actingAs($this->therapist)->post(route('doctor.referrals.withdraw', $referral))
        ->assertInertiaFlash('toast.message', 'Направление отозвано.');

    expect($referral->refresh()->withdrawn_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'referral.withdrawn')->exists())->toBeTrue();

    $this->actingAs($this->psychiatrist)->get(route('doctor.referrals.show', $referral))->assertNotFound();

    ($this->refer)();
    $second = Referral::query()->open()->sole();
    $this->actingAs($this->psychiatrist)->post(route('doctor.referrals.accept', $second));

    $this->actingAs($this->therapist)->post(route('doctor.referrals.withdraw', $second))
        ->assertInertiaFlash('toast.type', 'error');

    expect($second->refresh()->withdrawn_at)->toBeNull();
});

test('the psychiatrist sees the referral after open alerts, in the counter and on its page', function () {
    ($this->answer)();
    ($this->refer)();
    $referral = Referral::query()->sole();

    // Свой пациент психиатра с открытым алертом стоит выше направления.
    $own = User::factory()->patientOf($this->psychiatrist)->create(['name' => 'Морозова Ольга Павловна']);
    app(SubmitAssessments::class)->handle($own, assessmentAnswers(phq9Item9: 3));

    expect(PatientSignals::count($this->psychiatrist))->toBe(2);

    $this->actingAs($this->psychiatrist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.name', 'Морозова Ольга Павловна')
            ->where('attention.1.name', 'Орлов Игорь Сергеевич')
            ->where('attention.1.referral.id', $referral->id)
            ->where('attention.1.referral.category', 'desirable')
            ->where('attention.1.referral.from', ['name' => 'Кравцова Ирина Олеговна', 'specialty' => 'Терапевт'])
            ->where('attention.1.referral.summary', 'GAD-7 21')
            ->where('attention.1.alerts', []));

    $this->actingAs($this->psychiatrist)->get(route('doctor.referrals.show', $referral))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/Referral')
            ->where('referral.category', 'desirable')
            ->where('referral.basis.0.code', 'gad7_severe')
            ->where('patient.name', 'Орлов Игорь Сергеевич')
            ->where('wellbeing.scales.0.latest.total', 21));

    // Карточку до принятия психиатр не открывает, другой психиатр не видит направление.
    $this->actingAs($this->psychiatrist)->get(route('doctor.patients.show', $this->patient))->assertNotFound();
    $this->actingAs(User::factory()->doctor()->create())->get(route('doctor.referrals.show', $referral))->assertNotFound();
});

test('accepting moves the patient with the open alerts and closes the card for the therapist', function () {
    ($this->answer)(3, 1, 1);
    ($this->refer)(['category' => 'required', 'suggested' => 'required']);
    $referral = Referral::query()->sole();
    $alert = Alert::query()->sole();

    $this->actingAs($this->psychiatrist)->post(route('doctor.referrals.accept', $referral))
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast.message', 'Пациент принят и теперь в вашем списке.');

    $cares = CareRelationship::query()->where('patient_id', $this->patient->id)->orderBy('id')->get();

    expect($cares)->toHaveCount(2)
        ->and($cares[0]->doctor_id)->toBe($this->therapist->id)
        ->and($cares[0]->ended_at)->not->toBeNull()
        ->and($cares[1]->doctor_id)->toBe($this->psychiatrist->id)
        ->and($cares[1]->ended_at)->toBeNull()
        ->and($referral->refresh()->accepted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'referral.accepted')->exists())->toBeTrue()
        ->and(PatientSignals::count($this->psychiatrist))->toBe(1);

    // Открытый алерт виден новому лечащему врачу.
    $this->actingAs($this->psychiatrist)->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alerts.0.id', $alert->id)
            ->where('referral.category', 'required')
            ->where('referral.status', 'accepted')
            ->where('referral.from.name', 'Кравцова Ирина Олеговна'));

    $this->actingAs($this->therapist)->get(route('doctor.patients.show', $this->patient))->assertNotFound();
    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('referrals.0.status', 'accepted')
            ->where('referrals.0.accepted_at', '24.10.2026')
            ->has('patients.data', 0));

    // Повторное принятие и страница принятого направления ведут в карточку.
    $this->actingAs($this->psychiatrist)->post(route('doctor.referrals.accept', $referral))->assertRedirect(route('doctor.patients.show', $this->patient));
    $this->actingAs($this->psychiatrist)->get(route('doctor.referrals.show', $referral))->assertRedirect(route('doctor.patients.show', $this->patient));
});

test('a withdrawn referral cannot be accepted', function () {
    ($this->answer)();
    ($this->refer)();
    $referral = Referral::query()->sole();
    $this->actingAs($this->therapist)->post(route('doctor.referrals.withdraw', $referral));

    $this->actingAs($this->psychiatrist)->post(route('doctor.referrals.accept', $referral))
        ->assertRedirect(route('doctor.patients.index'))
        ->assertInertiaFlash('toast.message', 'Направление отозвано терапевтом. Список обновлён.');

    expect(CareRelationship::query()->active()->where('patient_id', $this->patient->id)->sole()->doctor_id)->toBe($this->therapist->id);
});

test('the patient sees the referral softly and has no chat or side effects while with the therapist', function () {
    $this->actingAs($this->patient)->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('withTherapist', true)
            ->where('referral', null)
            ->where('canReportSideEffects', false));

    $this->actingAs($this->patient)->get(route('patient.chat.show'))->assertRedirect(route('patient.home'));
    $this->actingAs($this->patient)->postJson(route('patient.chat.store'), ['body' => 'Здравствуйте'])->assertNotFound();
    $this->actingAs($this->patient)->get(route('patient.side-effects.create'))->assertRedirect(route('patient.side-effects.index'));

    ($this->answer)();
    ($this->refer)();

    $this->actingAs($this->patient)->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('referral', [
            'to' => ['name' => 'Смирнова Анна Сергеевна', 'specialty' => 'Психиатр'],
            'category' => 'Терапевт рекомендует консультацию психиатра',
            'status' => 'open',
            'accepted_at' => null,
        ]));

    $this->actingAs($this->psychiatrist)->post(route('doctor.referrals.accept', Referral::query()->sole()));

    $this->actingAs($this->patient)->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('withTherapist', false)
            ->where('canReportSideEffects', true)
            ->where('referral.status', 'accepted')
            ->where('referral.accepted_at', '24 октября'));

    $this->actingAs($this->patient)->get(route('patient.chat.show'))->assertOk();
});

test('the help request of a therapist patient alerts the therapist', function () {
    $this->actingAs($this->patient)->post(route('patient.help.report'))->assertRedirect(route('patient.help.show'));

    expect(Alert::query()->sole()->patient_id)->toBe($this->patient->id);

    $this->actingAs($this->therapist)->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->has('alerts', 1));
});
