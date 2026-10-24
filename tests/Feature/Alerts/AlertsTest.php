<?php

use App\Enums\AlertDecision;
use App\Enums\AlertLevel;
use App\Enums\AlertShownVia;
use App\Events\AlertsChanged;
use App\Jobs\CheckAlertRules;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\User;
use App\Notifications\UrgentAlert;
use App\Support\Alerts\AlertDelivery;
use App\Support\Alerts\AlertEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadAlertRules();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 19:00:00.250', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Морозова Ольга Павловна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);

    // Пациент отправляет оба опросника, на 9-й вопрос PHQ-9 — $item9.
    $this->answer = fn (int $item9, int $phq9 = 1) => $this->actingAs($this->patient)
        ->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers(gad7: 1, phq9: $phq9, phq9Item9: $item9)]);

    $this->alertRoute = fn (string $action, Alert $alert, ?User $patient = null): string => route("doctor.patients.alerts.{$action}", [
        'patient' => $patient ?? $this->patient,
        'alert' => $alert,
    ]);
});

test('any answer to item 9 except «not at all» raises an alert for the treating doctor', function (int $item9, string $label) {
    Notification::fake();

    ($this->answer)($item9)->assertRedirect();

    $alert = Alert::query()->sole();
    $assessment = Assessment::query()->whereRelation('scale', 'code', 'phq9')->sole();

    expect($alert->patient_id)->toBe($this->patient->id)
        ->and($alert->doctor_id)->toBe($this->doctor->id)
        ->and($alert->level)->toBe(AlertLevel::Critical)
        ->and($alert->assessment_id)->toBe($assessment->id)
        ->and($alert->evidence)->toBe([[
            'type' => 'answer',
            'scale' => 'phq9',
            'name' => 'PHQ-9',
            'item' => 9,
            'text' => 'Мысли о том, что мне бы хотелось умереть или причинить себе боль',
            'score' => $item9,
            'label' => $label,
        ]])
        ->and($alert->trigger())->toBe('PHQ-9, вопрос 9: «'.mb_lcfirst($label).'»')
        // Метки шагов с миллисекундами: ответ, алерт, событие. Пуша нет — врач не включал уведомления.
        ->and($alert->submitted_at->format('H:i:s.v'))->toBe('16:00:00.250')
        ->and($alert->submitted_at->equalTo($assessment->submitted_at))->toBeTrue()
        ->and($alert->created_at)->not->toBeNull()
        ->and($alert->broadcast_at)->not->toBeNull()
        ->and($alert->pushed_at)->toBeNull()
        ->and($alert->shown_at)->toBeNull();

    Notification::assertNothingSent();
})->with([
    'several days' => [1, 'В течение нескольких дней'],
    'more than half the days' => [2, 'Более, чем половину этого времени'],
    'nearly every day' => [3, 'Почти каждый день'],
]);

test('«not at all» on item 9 raises no alert even with severe depression', function () {
    Notification::fake();

    ($this->answer)(0, phq9: 3)->assertRedirect();

    expect(Assessment::query()->count())->toBe(2)
        ->and(Alert::query()->count())->toBe(0);
});

test('doctor gets one neutral push that opens the patient card', function () {
    Notification::fake();
    subscribeToPush($this->doctor);

    ($this->answer)(2);

    $alert = Alert::query()->sole();
    $push = [];
    $options = [];

    Notification::assertSentToTimes($this->doctor, UrgentAlert::class, 1);
    Notification::assertSentTo($this->doctor, UrgentAlert::class, function (UrgentAlert $notification) use (&$push, &$options): bool {
        $push = $notification->toWebPush($this->doctor)->toArray();
        $options = $notification->toWebPush($this->doctor)->getOptions();

        return true;
    });

    expect($push['title'])->toBe('Срочно: Морозова О. П.')
        ->and($push['body'])->toBe('Ответы опросника требуют внимания')
        ->and(mb_strtolower(json_encode($push, JSON_UNESCAPED_UNICODE)))->not->toContain('суицид')
        ->and($push['data']['url'])->toBe("/doctor/patients/{$this->patient->id}#alerts")
        ->and($push['requireInteraction'])->toBeTrue()
        ->and($options['urgency'])->toBe('high')
        ->and($alert->pushed_at)->not->toBeNull();
});

test('the event about a new alert goes to the doctor right away, accepting and closing go through the queue', function () {
    Event::fake([AlertsChanged::class]);

    ($this->answer)(3);

    Event::assertDispatched(AlertsChanged::class, fn (AlertsChanged $event): bool => $event->doctor->is($this->doctor)
        && $event->shouldBroadcastNow()
        && $event->broadcastAs() === 'alerts.changed'
        && $event->broadcastOn()->name === "private-App.Models.User.{$this->doctor->id}"
        && $event->broadcastWith()['count'] === 1
        && $event->broadcastWith()['alerts'][0]['patient'] === 'Морозова Ольга Павловна');

    $alert = Alert::query()->sole();

    $this->actingAs($this->doctor)->post(($this->alertRoute)('acknowledge', $alert));
    $this->actingAs($this->doctor)->post(($this->alertRoute)('close', $alert), ['decision' => 'contacted']);

    Event::assertDispatchedTimes(AlertsChanged::class, 3);
    Event::assertDispatched(AlertsChanged::class, fn (AlertsChanged $event): bool => ! $event->shouldBroadcastNow()
        && $event->broadcastWith() === ['alerts' => [], 'count' => 0]);
});

test('the rule check is queued in the same transaction as the answers', function () {
    config(['queue.default' => 'database']);

    // Ответы не сохранились — задачи проверки тоже нет.
    expect(fn () => DB::transaction(function () {
        ($this->answer)(3);

        throw new RuntimeException('Сбой после записи ответов');
    }))->toThrow(RuntimeException::class);

    expect(Assessment::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);

    ($this->answer)(3)->assertRedirect();

    $job = DB::table('jobs')->sole();

    expect($job->queue)->toBe('alerts')
        ->and(json_decode($job->payload, true)['displayName'])->toBe(CheckAlertRules::class)
        ->and(Alert::query()->count())->toBe(0);

    Artisan::call('queue:work', ['--queue' => 'alerts', '--stop-when-empty' => true]);

    expect(Alert::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a repeated check does not raise a second alert for the same answers', function () {
    ($this->answer)(3);

    (new CheckAlertRules(Assessment::query()->get()))->handle(app(AlertEngine::class), app(AlertDelivery::class));

    expect(Alert::query()->count())->toBe(1);

    // Новые ответы — новый алерт, даже если прежний не закрыт.
    $this->travel(1)->hours();
    ($this->answer)(1);

    expect(Alert::query()->count())->toBe(2);
});

test('doctor sees the banner, the queue and the counter, accepts and closes the alert with a decision', function () {
    ($this->answer)(3);
    $alert = Alert::query()->sole();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patientSignals', 1)
            ->where('urgentAlerts', [[
                'id' => $alert->id,
                'patient_id' => $this->patient->id,
                'patient' => 'Морозова Ольга Павловна',
                'rule' => 'Суицидальный риск',
                'trigger' => 'PHQ-9, вопрос 9: «почти каждый день»',
                'kind' => 'answers',
                'at' => '01.10.2026 19:00',
                'repeats' => null,
                'shown' => false,
            ]])
            ->where('patients.data.0.alert', true)
            ->where('attention.0.id', $this->patient->id)
            ->where('attention.0.alerts', [['rule' => 'Суицидальный риск', 'trigger' => 'PHQ-9, вопрос 9: «почти каждый день»', 'acknowledged' => false, 'repeated' => false]]));

    // Браузер врача показал плашку: время доставки считается от ответа пациента.
    $this->travel(1500)->milliseconds();
    $this->actingAs($this->doctor)->postJson(($this->alertRoute)('shown', $alert), ['via' => 'realtime'])->assertNoContent();
    $this->travel(10)->seconds();
    $this->actingAs($this->doctor)->postJson(($this->alertRoute)('shown', $alert), ['via' => 'page'])->assertNoContent();

    expect($alert->refresh()->shown_via)->toBe(AlertShownVia::Realtime)
        ->and($alert->deliverySeconds())->toBe(1.5);

    // «Принял»: плашки нет, алерт в очереди и в счётчике до решения.
    $this->actingAs($this->doctor)
        ->from(route('doctor.patients.index'))
        ->post(($this->alertRoute)('acknowledge', $alert))
        ->assertRedirect(route('doctor.patients.index'));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('urgentAlerts', [])
            ->where('patientSignals', 1)
            ->where('alerts.0.shown', ['at' => '01.10.2026 19:00', 'after' => '1,5 с', 'realtime' => true])
            ->where('alerts.0.acknowledged', ['at' => '01.10.2026 19:00', 'by' => 'Смирнова А. С.'])
            ->where('alerts.0.closed', null)
            ->where('alertDecisions.3', ['value' => 'no_threat', 'label' => 'Угрозы нет', 'comment_required' => true]));

    expect(AuditLog::query()->where('action', 'alert.acknowledged')->sole()->after['rule']['code'])->toBe('suicide_risk');

    // «Угрозы нет» без комментария не закрывается.
    $this->actingAs($this->doctor)
        ->post(($this->alertRoute)('close', $alert), ['decision' => 'no_threat', 'comment' => '  '])
        ->assertSessionHasErrors(['comment' => 'Напишите, почему угрозы нет.']);

    expect($alert->refresh()->closed_at)->toBeNull();

    $this->actingAs($this->doctor)
        ->post(($this->alertRoute)('close', $alert), ['decision' => 'no_threat', 'comment' => 'Позвонил: мысли пассивные, плана нет.'])
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    $alert->refresh();
    $audit = AuditLog::query()->where('action', 'alert.closed')->sole();

    expect($alert->decision)->toBe(AlertDecision::NoThreat)
        ->and($alert->decision_comment)->toBe('Позвонил: мысли пассивные, плана нет.')
        ->and($alert->closed_by_id)->toBe($this->doctor->id)
        ->and($alert->closed_at)->not->toBeNull()
        ->and($audit->actor_id)->toBe($this->doctor->id)
        ->and($audit->patient_id)->toBe($this->patient->id)
        ->and($audit->subject_type)->toBe('alerts')
        ->and($audit->after)->toMatchArray(['decision' => 'no_threat', 'decision_comment' => 'Позвонил: мысли пассивные, плана нет.'])
        ->and($audit->after['evidence'][0]['score'])->toBe(3);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patientSignals', 0)
            ->where('attention', []));
});

test('closing needs a decision from the list', function (array $payload, string $field) {
    ($this->answer)(3);

    $this->actingAs($this->doctor)
        ->post(($this->alertRoute)('close', Alert::query()->sole()), $payload)
        ->assertSessionHasErrors($field);

    expect(Alert::query()->sole()->closed_at)->toBeNull();
})->with([
    'no decision' => [[], 'decision'],
    'unknown decision' => [['decision' => 'called_police'], 'decision'],
    'too long comment' => [['decision' => 'contacted', 'comment' => str_repeat('а', 2001)], 'comment'],
]);

test('closing an alert that was not accepted accepts it first', function () {
    ($this->answer)(3);
    $alert = Alert::query()->sole();

    $this->actingAs($this->doctor)->post(($this->alertRoute)('close', $alert), ['decision' => 'appointment'])->assertRedirect();

    $alert->refresh();

    expect($alert->acknowledged_by_id)->toBe($this->doctor->id)
        ->and($alert->decision)->toBe(AlertDecision::Appointment)
        ->and($alert->decision_comment)->toBeNull()
        ->and(AuditLog::query()->pluck('action')->all())->toBe(['alert.acknowledged', 'alert.closed']);

    // Повторное закрытие ничего не меняет.
    $this->actingAs($this->doctor)
        ->post(($this->alertRoute)('close', $alert), ['decision' => 'emergency'])
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    expect($alert->refresh()->decision)->toBe(AlertDecision::Appointment);
});

test('another doctor does not see the alert and cannot accept or close it', function () {
    ($this->answer)(3);
    $alert = Alert::query()->sole();
    $stranger = User::factory()->doctor()->create();

    $this->actingAs($stranger)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('urgentAlerts', [])->where('patientSignals', 0));

    $this->actingAs($stranger)->postJson(($this->alertRoute)('shown', $alert), ['via' => 'page'])->assertNotFound();
    $this->actingAs($stranger)->post(($this->alertRoute)('acknowledge', $alert))->assertNotFound();
    $this->actingAs($stranger)->post(($this->alertRoute)('close', $alert), ['decision' => 'contacted'])->assertNotFound();

    // Через карточку другого своего пациента чужой алерт тоже не найти.
    $own = User::factory()->patientOf($this->doctor)->create();
    $this->actingAs($this->doctor)->post(($this->alertRoute)('acknowledge', $alert, $own))->assertNotFound();

    expect($alert->refresh()->shown_at)->toBeNull()
        ->and($alert->acknowledged_at)->toBeNull()
        ->and($alert->closed_at)->toBeNull();
});

test('patient sees the help screen after such answers and the card with 112 until the doctor closes the alert', function () {
    $sent = ($this->answer)(1)->headers->get('Location');

    $this->actingAs($this->patient)
        ->get($sent)
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/QuestionnaireSent')
            ->where('help', true));

    // Самого алерта и решения врача пациент не видит: только карточку помощи.
    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('help', true)
            ->where('urgentAlerts', [])
            ->missing('alerts'));

    $this->actingAs($this->doctor)->post(($this->alertRoute)('close', Alert::query()->sole()), ['decision' => 'contacted']);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('help', false));

    // Ответ «совсем нет» — обычный экран «Ответы отправлены».
    $this->travel(1)->days();
    $sent = ($this->answer)(0)->headers->get('Location');

    $this->actingAs($this->patient)
        ->get($sent)
        ->assertInertia(fn (Assert $page) => $page->where('help', false));
});

test('the report gives the number of alerts with the median and the longest delivery time', function () {
    // Три алерта: показан через 0,8 с и 1,2 с в открытом кабинете и через 5 минут, когда врач открыл кабинет.
    foreach ([[0.8, 'realtime'], [1.2, 'realtime'], [300, 'page']] as [$seconds, $via]) {
        ($this->answer)(3);
        $this->travel((int) ($seconds * 1000))->milliseconds();
        $this->actingAs($this->doctor)->postJson(($this->alertRoute)('shown', Alert::query()->latest('id')->firstOrFail()), ['via' => $via]);
        $this->travel(1)->days();
    }

    ($this->answer)(2);

    $this->artisan('app:alerts:report')
        ->assertSuccessful()
        ->expectsOutputToContain('Алертов: 4. Показаны врачу: 3, ещё не показаны: 1.')
        ->expectsOutputToContain('медиана 1,2 с · максимум 5 мин')
        ->expectsOutputToContain('медиана 1,0 с · максимум 1,2 с');

    $this->artisan('app:alerts:report', ['--since' => '2026-10-03'])
        ->assertSuccessful()
        ->expectsOutputToContain('Алертов: 2. Показаны врачу: 1, ещё не показаны: 1.');

    $this->artisan('app:alerts:report', ['--since' => '03.10.2026'])->assertFailed();
});
