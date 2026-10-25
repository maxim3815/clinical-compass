<?php

use App\Events\QueueAnswersChanged;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\QueueController;
use App\Models\AccessLink;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Observation;
use App\Models\QueueAnswer;
use App\Models\Scale;
use App\Models\User;
use App\Notifications\UrgentQueueAnswer;
use App\Support\Assessments\PatientAssessments;
use App\Support\PinAttempts;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadReferralRules();
    loadAlertRules();
    $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00:00', 'Europe/Moscow'));
    $this->therapist = User::factory()->therapist()->create(['name' => 'Кравцова Ирина Олеговна']);
    $this->therapist->doctorProfile?->update(['join_code' => 'kravtsova']);
    $this->psychiatrist = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->psychiatrist->doctorProfile?->update(['join_code' => 'smirnova']);

    // GAD-7 = 21 и PHQ-9 = 9: подсказка «желательно» по GAD-7.
    $this->submit = fn (int $gad7 = 3, int $phq9 = 1, int $item9 = 0, ?string $cookie = null): TestResponse => ($cookie === null ? $this : $this->withCookie(QueueController::COOKIE, $cookie))
        ->post(route('queue.store', 'kravtsova'), ['answers' => assessmentAnswers(gad7: $gad7, phq9: $phq9, phq9Item9: $item9)]);
    $this->harsScores = fn (int $total): array => array_map(fn (int $item): int => max(0, min(4, $total - 4 * $item)), range(0, 13));
    // Пациент вводит PIN на адресе врача и регистрируется: с cookie ответов из очереди или без.
    $this->register = function (string $pin, ?string $cookie = null, string $login = 'zaytseva'): User {
        auth()->logout();
        $response = $this->post(route('join.store', 'kravtsova'), ['pin' => $pin]);
        $token = basename((string) $response->headers->get('Location'));

        ($cookie === null ? $this : $this->withCookie(QueueController::COOKIE, $cookie))
            ->post(route('invitation.store', $token), [
                'name' => 'Зайцева Ольга Петровна',
                'login' => $login,
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'consent' => '1',
            ])
            ->assertRedirect(route('patient.rules.show'));

        auth()->logout();

        return User::query()->where('login', $login)->sole();
    };
    $this->issuePin = fn (?QueueAnswer $answer = null): string => $this->actingAs($this->therapist)
        ->postJson(route('doctor.invitations.appointment.store'), ['queue_answer_id' => $answer?->id])
        ->assertCreated()
        ->json('pin');
});

afterEach(function () {
    auth()->logout();
});

test('the door page asks for answers without signing in', function (string $code) {
    $this->get(route('queue.show', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/Show')
            ->where('joinCode', 'kravtsova')
            ->where('doctor', ['name' => 'Кравцова Ирина Олеговна', 'specialty' => 'Терапевт'])
            ->where('answer', null)
            ->has('scales', 2)
            ->where('scales.0.code', 'gad7')
            ->where('lifetimeHours', 12)
            // Кризисная линия нужна и без входа: экран помощи после ответов.
            ->has('crisisLine.href'),
        );
})->with(['kravtsova', 'KRAVTSOVA']);

test('an unknown code and a psychiatrist code look like an invalid link', function (string $code) {
    $this->get(route('queue.show', $code))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('access-link/Invalid'));

    $this->post(route('queue.store', $code), ['answers' => assessmentAnswers()])
        ->assertRedirect(route('queue.show', $code));

    expect(QueueAnswer::query()->count())->toBe(0);
})->with(['nobody', 'smirnova']);

test('a signed in patient of the therapist answers in the app, others are offered to sign out', function () {
    $own = User::factory()->patientOf($this->therapist)->create();
    $other = User::factory()->patientOf($this->psychiatrist)->create();

    $this->actingAs($own)->get(route('queue.show', 'kravtsova'))
        ->assertRedirect(route('patient.questionnaires.create'));

    foreach ([$other, $this->psychiatrist] as $user) {
        $this->actingAs($user)->get(route('queue.show', 'kravtsova'))
            ->assertInertia(fn (Assert $page) => $page->component('access-link/SignedIn')->where('purpose', 'queue'));
    }

    $this->actingAs($other)->post(route('queue.store', 'kravtsova'), ['answers' => assessmentAnswers()])
        ->assertRedirect(route('queue.show', 'kravtsova'));

    expect(QueueAnswer::query()->count())->toBe(0);
});

test('answers get a four digit code, the phone remembers it and the scores stay hidden', function () {
    $response = ($this->submit)()->assertRedirect(route('queue.show', 'kravtsova'));
    $cookie = $response->getCookie(QueueController::COOKIE);
    $answer = QueueAnswer::query()->sole();

    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime() - now()->getTimestamp())->toBe(12 * 60 * 60)
        ->and($answer->code)->toMatch('/^\d{4}$/')
        ->and($answer->doctor_id)->toBe($this->therapist->id)
        ->and($answer->browser_token_hash)->toBe(QueueAnswer::hashToken($cookie->getValue()))
        ->and(array_column($answer->scales, 'total', 'scale'))->toBe(['gad7' => 21, 'phq9' => 8])
        ->and($answer->alert)->toBeNull()
        ->and($answer->submitted_at->toDateTimeString())->toBe('2026-10-25 07:00:00')
        ->and(Assessment::query()->count())->toBe(0);

    $this->withCookie(QueueController::COOKIE, $cookie->getValue())
        ->get(route('queue.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/Show')
            ->where('answer', ['code' => $answer->code, 'expires_at' => 'сегодня в 22:00', 'help' => false])
            ->where('scales', []),
        );

    // Другой телефон кода не видит.
    $this->defaultCookies = [];
    $this->get(route('queue.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->where('answer', null));
});

test('answers after noon are deleted tomorrow', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 15:20:00', 'Europe/Moscow'));
    $cookie = ($this->submit)()->getCookie(QueueController::COOKIE)->getValue();

    $this->withCookie(QueueController::COOKIE, $cookie)->get(route('queue.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->where('answer.expires_at', 'завтра в 03:20'));
});

test('incomplete answers are not saved', function () {
    $this->from(route('queue.show', 'kravtsova'))
        ->post(route('queue.store', 'kravtsova'), ['answers' => ['gad7' => array_fill(0, 7, 1)]])
        ->assertSessionHasErrors('answers.phq9');

    expect(QueueAnswer::query()->count())->toBe(0);
});

test('a second submit from the same phone shows the same code', function () {
    $cookie = ($this->submit)()->getCookie(QueueController::COOKIE)->getValue();

    ($this->submit)(cookie: $cookie)->assertRedirect(route('queue.show', 'kravtsova'));

    expect(QueueAnswer::query()->count())->toBe(1);
});

test('a code is unique among the pending answers of the therapist', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $copy = fn (array $attributes = []) => QueueAnswer::query()->create([
        ...$answer->only(['doctor_id', 'code', 'browser_token_hash', 'scales', 'submitted_at']),
        ...$attributes,
    ]);

    // Своя точка сохранения: ошибка не обрывает транзакцию теста.
    expect(fn () => DB::transaction(fn () => $copy()))->toThrow(QueryException::class);

    // У привязанных ответов и у другого терапевта код может повториться.
    $patient = User::factory()->patientOf($this->therapist)->create();
    $answer->update(['patient_id' => $patient->id, 'attached_at' => now()]);
    $copy();
    $copy(['doctor_id' => User::factory()->therapist()->create()->id]);

    expect(QueueAnswer::query()->count())->toBe(3);
});

test('one address sends at most ten answers an hour', function () {
    foreach (range(1, 10) as $attempt) {
        ($this->submit)()->assertRedirect(route('queue.show', 'kravtsova'));
    }

    $this->from(route('queue.show', 'kravtsova'))
        ->post(route('queue.store', 'kravtsova'), ['answers' => assessmentAnswers()])
        ->assertRedirect(route('queue.show', 'kravtsova'))
        ->assertSessionHasErrors(['answers' => 'С этого телефона уже много ответов за последний час. Попробуйте позже или скажите терапевту, что не получилось.']);

    expect(QueueAnswer::query()->count())->toBe(10);

    $this->travel(61)->minutes();
    ($this->submit)();

    expect(QueueAnswer::query()->count())->toBe(11);
});

test('the therapist sees the code among the answered in real time', function () {
    Event::fake([QueueAnswersChanged::class]);

    ($this->submit)();
    $answer = QueueAnswer::query()->sole();

    Event::assertDispatched(QueueAnswersChanged::class, fn (QueueAnswersChanged $event) => $event->doctor->is($this->therapist) && ! $event->now);
    expect((new QueueAnswersChanged($this->therapist))->broadcastWith())->toBe(['urgent' => []]);

    // Своих пациентов у терапевта нет: «Ответили сегодня» всё равно видно.
    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/therapist/Patients')
            ->where('patients.total', 0)
            ->has('answered', 1)
            ->where('answered.0', [
                'kind' => 'queue',
                'id' => $answer->id,
                'code' => $answer->code,
                'at' => '10:00',
                'scales' => [
                    ['name' => 'GAD-7', 'total' => 21, 'max' => 21, 'band' => 'выраженная тревога'],
                    ['name' => 'PHQ-9', 'total' => 8, 'max' => 27, 'band' => 'лёгкая депрессия'],
                ],
                'highlight' => false,
                'alert' => false,
                'unopened' => false,
                'suggestion' => 'desirable',
                'scales_missing' => ['HARS'],
                'decision' => null,
                'opened_at' => null,
            ]),
        );
});

test('item nine shows help to the patient and a red banner with sound to the therapist until opened', function () {
    Event::fake([QueueAnswersChanged::class]);
    Notification::fake();
    subscribeToPush($this->therapist);

    $cookie = ($this->submit)(item9: 1)->getCookie(QueueController::COOKIE)->getValue();
    $answer = QueueAnswer::query()->sole();

    expect($answer->alert['evidence'][0])->toMatchArray(['type' => 'answer', 'scale' => 'phq9', 'item' => 9, 'score' => 1]);
    Event::assertDispatched(QueueAnswersChanged::class, fn (QueueAnswersChanged $event) => $event->now);
    Notification::assertSentTo($this->therapist, UrgentQueueAnswer::class, function (UrgentQueueAnswer $notification) use ($answer): bool {
        $message = $notification->toWebPush($this->therapist)->toArray();

        return $message['title'] === "Срочно: код {$answer->code}"
            && $message['data']['url'] === "/doctor/queue/{$answer->id}";
    });

    $this->withCookie(QueueController::COOKIE, $cookie)->get(route('queue.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->where('answer.help', true));

    $banner = [[
        'id' => $answer->id,
        'code' => $answer->code,
        'rule' => 'Суицидальный риск',
        'trigger' => 'PHQ-9, вопрос 9: «в течение нескольких дней»',
        'at' => '25.10.2026 10:00',
    ]];

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('urgentQueue', $banner)
            ->where('answered.0.alert', true)
            ->where('answered.0.unopened', true)
            ->where('answered.0.highlight', true)
            ->where('answered.0.suggestion', 'required'),
        );
    expect((new QueueAnswersChanged($this->therapist))->broadcastWith())->toBe(['urgent' => $banner]);

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/queue/Answer')
            ->where('answer.alert', ['rule' => 'Суицидальный риск', 'trigger' => 'PHQ-9, вопрос 9: «в течение нескольких дней»'])
            ->where('urgentQueue', []),
        );

    expect($answer->fresh()->opened_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'queue_answer.opened')->sole()->after)->toBe(['code' => $answer->code]);
    Event::assertDispatchedTimes(QueueAnswersChanged::class, 2);

    // Повторное открытие ничего не пишет.
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))->assertOk();
    expect(AuditLog::query()->where('action', 'queue_answer.opened')->count())->toBe(1);
});

test('the psychiatrist has no queue banner and no door', function () {
    ($this->submit)(item9: 1);

    $this->actingAs($this->psychiatrist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('urgentQueue', []));
    $this->actingAs($this->psychiatrist)->get(route('doctor.queue-answers.show', QueueAnswer::query()->sole()))->assertNotFound();
    $this->actingAs($this->psychiatrist)->get(route('doctor.queue.poster'))->assertNotFound();
});

test('the code page shows the answers and the suggestion, hars there changes it', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/queue/Answer')
            ->where('answer.code', $answer->code)
            ->where('answer.submitted_at', '10:00')
            ->where('answer.expires_at', 'сегодня в 22:00')
            ->where('answer.alert', null)
            ->where('suggestion.category', 'desirable')
            ->where('suggestion.missing.0.scale', 'hars')
            ->has('fillings', 2)
            ->where('fillings.1.name', 'PHQ-9')
            ->where('fillings.1.answers.8.highlight', true)
            ->where('doctorScales', [['code' => 'hars', 'name' => 'HARS']]),
        );

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.scales.create', [$answer, 'hars']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/ScaleForm')
            ->where('patient', null)
            ->where('queueAnswer', ['id' => $answer->id, 'code' => $answer->code])
            ->where('scale.code', 'hars')
            ->where('facts.0', ['code' => 'mania_signs', 'name' => 'Признаки мании или гипомании', 'value' => null]),
        );

    $this->actingAs($this->therapist)->post(route('doctor.queue-answers.scales.store', [$answer, 'hars']), [
        'scores' => ($this->harsScores)(26),
        'facts' => ['mania_signs' => false, 'psychotic_symptoms' => false],
    ])
        ->assertRedirect(route('doctor.queue-answers.show', $answer))
        ->assertSessionHas('inertia.flash_data.toast.message', 'HARS сохранена: 26 из 56, тяжелая степень выраженности тревожного расстройства. Подсказка изменилась: «обязательно», было «желательно».');

    $answer->refresh();

    expect($answer->doctor_scale)->toMatchArray(['scale' => 'hars', 'total' => 26, 'band' => 'тяжелая степень выраженности тревожного расстройства', 'rated_by_id' => $this->therapist->id])
        ->and($answer->facts)->toBe(['mania_signs' => false, 'psychotic_symptoms' => false])
        ->and(AuditLog::query()->where('action', 'queue_answer.rated')->sole()->after)->toBe(['code' => $answer->code, 'scale' => 'hars'])
        ->and(Assessment::query()->count())->toBe(0);

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('suggestion.category', 'required')
            ->where('suggestion.missing', [])
            ->has('fillings', 3)
            ->where('fillings.2.name', 'HARS')
            ->where('fillings.2.rated_by', 'Кравцова Ирина Олеговна')
            ->where('fillings.2.submitted_at', '25.10.2026 10:00'),
        );

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.scales.create', [$answer, 'hars']))
        ->assertInertia(fn (Assert $page) => $page->where('facts.0.value', false));
});

test('only the therapist of the door opens the answers, and only for 12 hours', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $other = User::factory()->therapist()->create();

    $this->actingAs($other)->get(route('doctor.queue-answers.show', $answer))->assertNotFound();
    $this->actingAs($other)->get(route('doctor.queue-answers.scales.create', [$answer, 'hars']))->assertNotFound();
    $this->actingAs($other)->post(route('doctor.queue-answers.decline', $answer), ['suggested' => 'desirable', 'comment' => 'нет'])->assertNotFound();
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.scales.create', [$answer, 'gad7']))->assertNotFound();

    $this->travel(12)->hours();

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))->assertNotFound();
    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('answered', []));
});

test('not referring needs a comment when the system suggests a referral', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $decline = fn (array $data) => $this->actingAs($this->therapist)
        ->from(route('doctor.queue-answers.show', $answer))
        ->post(route('doctor.queue-answers.decline', $answer), $data);

    $decline(['suggested' => 'desirable'])
        ->assertSessionHasErrors(['comment' => 'Система подсказывает «желательно»: напишите, почему не направляете.']);
    $decline(['suggested' => null, 'comment' => 'Тревога ситуативная'])
        ->assertSessionHasErrors('category');

    expect($answer->fresh()->declined_at)->toBeNull();

    $decline(['suggested' => 'desirable', 'comment' => 'Тревога ситуативная, повторный приём через 2 недели'])
        ->assertRedirect(route('doctor.queue-answers.show', $answer))
        ->assertSessionHas('inertia.flash_data.toast.message', "Решение записано: код {$answer->code} без направления к психиатру.");

    expect($answer->fresh()->declined_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'queue_answer.declined')->sole())
        ->patient_id->toBeNull()
        ->after->toEqual([
            'code' => $answer->code,
            'suggested_category' => 'desirable',
            'basis' => ['gad7_severe'],
            'comment' => 'Тревога ситуативная, повторный приём через 2 недели',
        ]);

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('answered.0.decision', ['kind' => 'declined', 'category' => null]));
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertInertia(fn (Assert $page) => $page->where('answer.declined.comment', 'Тревога ситуативная, повторный приём через 2 недели'));
});

test('a pin from the code page moves the answers, hars and signs to the patient from any phone', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $this->actingAs($this->therapist)->post(route('doctor.queue-answers.scales.store', [$answer, 'hars']), [
        'scores' => ($this->harsScores)(20),
        'facts' => ['mania_signs' => true, 'psychotic_symptoms' => false],
    ]);
    $this->travel(10)->minutes();

    $pin = ($this->issuePin)($answer);
    expect(AccessLink::query()->sole()->queue_answer_id)->toBe($answer->id);
    auth()->logout();

    // Регистрация с другого телефона: cookie ответов нет.
    $patient = ($this->register)($pin);
    $answer->refresh();

    $assessments = Assessment::query()->where('patient_id', $patient->id)->with('scale')->orderBy('id')->get();

    expect($answer->patient_id)->toBe($patient->id)
        ->and($answer->attached_at)->not->toBeNull()
        ->and($assessments->map(fn (Assessment $assessment): array => [
            $assessment->scale->code,
            $assessment->total,
            $assessment->submitted_at->setTimezone('Europe/Moscow')->format('H:i'),
            $assessment->local_date->toDateString(),
            $assessment->rated_by_id,
        ])->all())->toBe([
            ['gad7', 21, '10:00', '2026-10-25', null],
            ['phq9', 8, '10:00', '2026-10-25', null],
            ['hars', 20, '10:00', '2026-10-25', $this->therapist->id],
        ])
        ->and($assessments[1]->answers()->orderBy('item')->pluck('score')->all())->toBe([1, 1, 1, 1, 1, 1, 1, 1, 0])
        ->and(Observation::query()->where('patient_id', $patient->id)->with('type')->get()->mapWithKeys(fn (Observation $observation) => [$observation->type->code => $observation->value_boolean])->all())
        ->toBe(['mania_signs' => true, 'psychotic_symptoms' => false])
        ->and(AuditLog::query()->where('action', 'queue_answer.attached')->sole()->patient_id)->toBe($patient->id)
        ->and(AuditLog::query()->where('action', 'assessment.rated')->sole()->after)->toMatchArray(['total' => 20, 'queue_code' => $answer->code]);

    // Срок дня 0 закрыт ответами из очереди: карточки опросников у пациента нет.
    expect(PatientAssessments::status($patient)['due'])->toBeFalse();

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('answered', 1)
            ->where('answered.0.kind', 'patient')
            ->where('answered.0.name', 'Зайцева Ольга Петровна')
            ->where('answered.0.suggestion', 'required'),
        );

    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertRedirect(route('doctor.patients.show', $patient))
        ->assertSessionHas('inertia.flash_data.toast.message', "Код {$answer->code}: пациент зарегистрирован (Зайцева Ольга Петровна), ответы и HARS в карточке.");

    // Код после регистрации в поиске ведёт к пациенту через страницу кода.
    $this->actingAs($this->therapist)->get(route('doctor.patients.index', ['search' => $answer->code]))
        ->assertRedirect(route('doctor.queue-answers.show', $answer));
});

test('answers from the same phone move with a general pin, another phone does not take them', function () {
    $cookie = ($this->submit)()->getCookie(QueueController::COOKIE)->getValue();
    $answer = QueueAnswer::query()->sole();

    $stranger = ($this->register)(($this->issuePin)(), login: 'stranger');
    expect($answer->fresh()->attached_at)->toBeNull()
        ->and(Assessment::query()->where('patient_id', $stranger->id)->count())->toBe(0);

    $patient = ($this->register)(($this->issuePin)(), $cookie);

    expect($answer->fresh()->patient_id)->toBe($patient->id)
        ->and(Assessment::query()->where('patient_id', $patient->id)->count())->toBe(2);
});

test('the choice of the therapist beats the phone', function () {
    $cookie = ($this->submit)()->getCookie(QueueController::COOKIE)->getValue();
    ($this->submit)(gad7: 0, phq9: 0);
    [$own, $chosen] = QueueAnswer::query()->orderBy('id')->get()->all();

    $patient = ($this->register)(($this->issuePin)($chosen), $cookie);

    expect($chosen->fresh()->patient_id)->toBe($patient->id)
        ->and($own->fresh()->attached_at)->toBeNull();
});

test('after registration the alert is created accepted when the therapist opened the answers', function () {
    ($this->submit)(item9: 2);
    $answer = QueueAnswer::query()->sole();
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer));
    $openedAt = $answer->fresh()->opened_at;

    $patient = ($this->register)(($this->issuePin)($answer));
    $alert = Alert::query()->sole();

    expect($alert->patient_id)->toBe($patient->id)
        ->and($alert->doctor_id)->toBe($this->therapist->id)
        ->and($alert->acknowledged_at?->equalTo($openedAt))->toBeTrue()
        ->and($alert->acknowledged_by_id)->toBe($this->therapist->id)
        ->and($alert->closed_at)->toBeNull();

    $this->actingAs($this->therapist)->get(route('doctor.patients.show', $patient))
        ->assertInertia(fn (Assert $page) => $page->where('urgentAlerts', [])->has('alerts', 1));
});

test('an alert from answers the therapist never opened rings as usual after registration', function () {
    $cookie = ($this->submit)(item9: 1)->getCookie(QueueController::COOKIE)->getValue();

    ($this->register)(($this->issuePin)(), $cookie);

    expect(Alert::query()->sole()->acknowledged_at)->toBeNull();
    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->has('urgentAlerts', 1)->where('urgentQueue', []));
});

test('a pin is bound only to own pending answers', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $other = User::factory()->therapist()->create();

    $this->actingAs($other)
        ->postJson(route('doctor.invitations.appointment.store'), ['queue_answer_id' => $answer->id])
        ->assertConflict();

    $this->travel(12)->hours();

    $this->actingAs($this->therapist)
        ->postJson(route('doctor.invitations.appointment.store'), ['queue_answer_id' => $answer->id])
        ->assertConflict();
    expect(AccessLink::query()->count())->toBe(0);
});

test('unattached answers are deleted after 12 hours, the audit log keeps only codes', function () {
    ($this->submit)();
    ($this->submit)();
    [$kept, $old] = QueueAnswer::query()->orderBy('id')->get()->all();
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $old));
    $kept->update(['submitted_at' => now()->addHour()]);

    $this->travel(12)->hours();
    $this->artisan('app:queue-answers:prune')->expectsOutputToContain('Удалено ответов из очереди: 1.')->assertSuccessful();

    expect(QueueAnswer::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(AuditLog::query()->where('action', 'queue_answer.opened')->sole()->after)->toBe(['code' => $old->code]);
});

test('the poster has the qr code of the door page', function () {
    $this->actingAs($this->therapist)->get(route('doctor.queue.poster'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/QueuePoster')
            ->where('doctor', ['name' => 'Кравцова Ирина Олеговна', 'specialty' => 'Терапевт'])
            ->where('address.url', route('queue.show', 'kravtsova'))
            ->where('address.short', preg_replace('#^https?://#', '', route('queue.show', 'kravtsova')))
            ->where('address.qr', fn (string $svg) => str_starts_with($svg, '<svg')),
        );
});

test('scales for the door are the active patient scales', function () {
    Scale::query()->where('code', 'phq9')->update(['is_active' => false]);

    $this->get(route('queue.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->has('scales', 1)->where('scales.0.code', 'gad7'));
});

test('a decline stops counting when hars raises the suggestion', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $this->actingAs($this->therapist)->post(route('doctor.queue-answers.decline', $answer), [
        'suggested' => 'desirable',
        'comment' => 'Тревога ситуативная',
    ]);

    expect($answer->fresh()->declined_suggestion?->value)->toBe('desirable');

    $this->actingAs($this->therapist)->post(route('doctor.queue-answers.scales.store', [$answer, 'hars']), [
        'scores' => ($this->harsScores)(28),
        'facts' => ['mania_signs' => false, 'psychotic_symptoms' => false],
    ]);

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('answered.0.decision', null)
            ->where('answered.0.suggestion', 'required'),
        );
    $this->actingAs($this->therapist)->get(route('doctor.queue-answers.show', $answer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('answer.declined.suggestion', 'desirable')
            ->where('answer.declined.current', false)
            ->where('answer.facts', [
                ['name' => 'Признаки мании или гипомании', 'value' => false],
                ['name' => 'Бред или галлюцинации', 'value' => false],
            ]),
        );

    // Решить снова можно — уже при «обязательно».
    $this->actingAs($this->therapist)->post(route('doctor.queue-answers.decline', $answer), [
        'suggested' => 'required',
        'comment' => 'Пациент отказался, повторный приём завтра',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->therapist)->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('answered.0.decision', ['kind' => 'declined', 'category' => null]));
});

test('four digits in the search open the answers with this code', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $other = $answer->code === '0000' ? '0001' : '0000';

    $this->actingAs($this->therapist)->get(route('doctor.patients.index', ['search' => $answer->code]))
        ->assertRedirect(route('doctor.queue-answers.show', $answer));

    $this->actingAs($this->therapist)->get(route('doctor.patients.index', ['search' => $other]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('search', $other)->where('patients.total', 0));
});

test('the pin page tells the queue code apart and does not count it as a wrong pin', function () {
    $cookie = ($this->submit)()->getCookie(QueueController::COOKIE)->getValue();
    $answer = QueueAnswer::query()->sole();
    ($this->issuePin)();
    auth()->logout();

    $this->get(route('join.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->where('queueCode', null));

    $this->withCookie(QueueController::COOKIE, $cookie)->get(route('join.show', 'kravtsova'))
        ->assertInertia(fn (Assert $page) => $page->where('queueCode', $answer->code));

    foreach (range(1, 12) as $attempt) {
        $this->withCookie(QueueController::COOKIE, $cookie)
            ->post(route('join.store', 'kravtsova'), ['pin' => $answer->code])
            ->assertSessionHasErrors(['pin' => JoinController::QUEUE_CODE]);
    }

    expect((new PinAttempts('127.0.0.1'))->lockedFor())->toBe(0);
});

test('the patient sees which queue code was saved after registration', function () {
    ($this->submit)();
    $answer = QueueAnswer::query()->sole();
    $pin = ($this->issuePin)($answer);
    auth()->logout();

    $response = $this->post(route('join.store', 'kravtsova'), ['pin' => $pin]);
    $token = basename((string) $response->headers->get('Location'));

    $this->post(route('invitation.store', $token), [
        'name' => 'Зайцева Ольга Петровна',
        'login' => 'zaytseva',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'consent' => '1',
    ])
        ->assertRedirect(route('patient.rules.show'))
        ->assertSessionHas('inertia.flash_data.toast.message', "Ваши ответы из очереди с кодом {$answer->code} сохранены в приложении.");
});
