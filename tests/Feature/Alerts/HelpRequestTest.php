<?php

use App\Enums\AlertDecision;
use App\Enums\AlertLevel;
use App\Enums\HelpEventType;
use App\Events\AlertsChanged;
use App\Jobs\DeliverAlert;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\HelpEvent;
use App\Models\User;
use App\Notifications\UrgentAlert;
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

    $this->report = fn (?User $patient = null) => $this->actingAs($patient ?? $this->patient)->post(route('patient.help.report'));

    $this->alertRoute = fn (string $action, Alert $alert): string => route("doctor.patients.alerts.{$action}", [
        'patient' => $this->patient,
        'alert' => $alert,
    ]);
});

test('the help screen offers 112, the crisis line and the doctor, and opening it is recorded once per visit', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Help')
            ->where('doctor', 'Смирнова Анна Сергеевна')
            ->where('canReport', true)
            ->where('lastReport', null)
            ->where('crisisLine', ['label' => '8 800 333-44-34', 'href' => 'tel:88003334434']));

    // Перезагрузка и возврат после «Сообщить врачу» — тот же заход.
    $this->travel(9)->minutes();
    $this->actingAs($this->patient)->get(route('patient.help.show'))->assertOk();

    expect(HelpEvent::query()->count())->toBe(1);

    $this->travel(2)->minutes();
    $this->actingAs($this->patient)->get(route('patient.help.show'))->assertOk();

    expect(HelpEvent::query()->where('type', HelpEventType::Opened)->count())->toBe(2)
        ->and(Alert::query()->count())->toBe(0);
});

test('a patient without a doctor sees only the calls and cannot report', function () {
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);

    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('doctor', null)
            ->where('canReport', false));

    ($this->report)()
        ->assertRedirect(route('patient.help.show'))
        ->assertInertiaFlash('helpReportRefused', true);

    expect(Alert::query()->count())->toBe(0);
});

test('«Report to the doctor» raises an alert without confirmation and the doctor gets a neutral push', function () {
    Notification::fake();
    subscribeToPush($this->doctor);

    ($this->report)()
        ->assertRedirect(route('patient.help.show'))
        ->assertInertiaFlash('helpReportedAt', '19:00');

    $alert = Alert::query()->sole();

    expect($alert->patient_id)->toBe($this->patient->id)
        ->and($alert->doctor_id)->toBe($this->doctor->id)
        ->and($alert->rule->code)->toBe('help_request')
        ->and($alert->level)->toBe(AlertLevel::Critical)
        ->and($alert->assessment_id)->toBeNull()
        ->and($alert->evidence)->toBe([['type' => 'help_request']])
        ->and($alert->isHelpRequest())->toBeTrue()
        ->and($alert->trigger())->toBe('кнопка «Сообщить врачу»')
        ->and($alert->submitted_at->format('H:i:s.v'))->toBe('16:00:00.250')
        ->and($alert->repeat_count)->toBe(0)
        ->and($alert->broadcast_at)->not->toBeNull()
        ->and($alert->pushed_at)->not->toBeNull();

    $push = [];
    Notification::assertSentToTimes($this->doctor, UrgentAlert::class, 1);
    Notification::assertSentTo($this->doctor, UrgentAlert::class, function (UrgentAlert $notification) use (&$push): bool {
        $push = $notification->toWebPush($this->doctor)->toArray();

        return true;
    });

    expect($push['title'])->toBe('Срочно: Морозова О. П.')
        ->and($push['body'])->toBe('Пациент просит связаться')
        ->and($push['data']['url'])->toBe("/doctor/patients/{$this->patient->id}#alerts")
        ->and($push)->not->toHaveKey('renotify');
});

test('a repeated press goes into the same alert, raises the banner again and pushes again, but not more than once a minute', function () {
    Notification::fake();
    subscribeToPush($this->doctor);

    ($this->report)();
    $alert = Alert::query()->sole();
    $this->actingAs($this->doctor)->post(($this->alertRoute)('acknowledge', $alert));

    // Чаще раза в минуту — то же нажатие: врача не дёргаем.
    $this->travel(30)->seconds();
    ($this->report)()->assertInertiaFlash('helpReportedAt', '19:00');

    expect($alert->refresh()->repeat_count)->toBe(0)
        ->and($alert->awaitsAcknowledgement())->toBeFalse();
    Notification::assertSentToTimes($this->doctor, UrgentAlert::class, 1);

    $this->travel(31)->seconds();
    ($this->report)();

    expect(Alert::query()->count())->toBe(1)
        ->and($alert->refresh()->repeat_count)->toBe(1)
        ->and($alert->repeated_at->format('H:i:s'))->toBe('16:01:01')
        ->and($alert->awaitsAcknowledgement())->toBeTrue();

    $push = [];
    Notification::assertSentToTimes($this->doctor, UrgentAlert::class, 2);
    Notification::assertSentTo($this->doctor, UrgentAlert::class, function (UrgentAlert $notification) use (&$push): bool {
        $push = $notification->toWebPush($this->doctor)->toArray();

        return $notification->repeat;
    });

    expect($push['body'])->toBe('Повторно: пациент просит связаться')
        ->and($push['renotify'])->toBeTrue()
        ->and($push['tag'])->toBe("alert-{$alert->id}");

    // Принятый алерт снова в плашке и в очереди как непринятый, с повтором.
    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('urgentAlerts.0.id', $alert->id)
            ->where('urgentAlerts.0.kind', 'help_request')
            ->where('urgentAlerts.0.rule', 'Просьба о помощи')
            ->where('urgentAlerts.0.trigger', 'кнопка «Сообщить врачу»')
            ->where('urgentAlerts.0.repeats', ['count' => 1, 'at' => '01.10.2026 19:01'])
            ->where('attention.0.alerts.0.acknowledged', false)
            ->where('attention.0.alerts.0.repeated', true)
            ->where('patientSignals', 1));

    // Врач принимает ещё раз: время принятия обновляется, в журнале обе записи.
    $this->travel(1)->minutes();
    $this->actingAs($this->doctor)->post(($this->alertRoute)('acknowledge', $alert))->assertRedirect();

    expect($alert->refresh()->awaitsAcknowledgement())->toBeFalse()
        ->and($alert->acknowledged_at->format('H:i'))->toBe('16:02')
        ->and(AuditLog::query()->where('action', 'alert.acknowledged')->count())->toBe(2);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('urgentAlerts', []));
});

test('the doctor closes the button alert with a decision, and the next press opens a new alert', function () {
    ($this->report)();
    $alert = Alert::query()->sole();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alerts.0.kind', 'help_request')
            ->where('alerts.0.rule', 'Просьба о помощи')
            ->where('alerts.0.answers', [])
            ->where('alerts.0.source', null)
            ->where('alerts.0.repeats', null)
            ->where('alerts.0.awaits_acknowledgement', true)
            ->where('alerts.0.submitted_at', '01.10.2026 19:00'));

    $this->actingAs($this->doctor)
        ->post(($this->alertRoute)('close', $alert), ['decision' => AlertDecision::NoThreat->value, 'comment' => 'Нажала случайно, написала в чат.'])
        ->assertRedirect();

    expect($alert->refresh()->closed_at)->not->toBeNull();

    $this->travel(10)->seconds();
    ($this->report)();

    expect(Alert::query()->count())->toBe(2)
        ->and(Alert::query()->open()->sole()->repeat_count)->toBe(0);
});

test('a button alert is separate from an alert on questionnaire answers', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers(gad7: 1, phq9: 1, phq9Item9: 3)]);
    ($this->report)();

    expect(Alert::query()->open()->count())->toBe(2)
        ->and(Alert::query()->whereNull('assessment_id')->count())->toBe(1);
});

test('the press and its delivery are saved in one transaction and delivered from the alerts queue', function () {
    config(['queue.default' => 'database']);
    Event::fake([AlertsChanged::class]);

    ($this->report)()->assertRedirect();

    $job = DB::table('jobs')->sole();
    $alert = Alert::query()->sole();

    expect($job->queue)->toBe('alerts')
        ->and(json_decode($job->payload, true)['displayName'])->toBe(DeliverAlert::class)
        ->and($alert->broadcast_at)->toBeNull();

    Artisan::call('queue:work', ['--queue' => 'alerts', '--stop-when-empty' => true]);

    expect($alert->refresh()->broadcast_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
    Event::assertDispatched(AlertsChanged::class, fn (AlertsChanged $event): bool => $event->doctor->is($this->doctor)
        && $event->shouldBroadcastNow()
        && $event->broadcastWith()['alerts'][0]['kind'] === 'help_request');
});

test('calls from the help screen are quiet lines in the doctor card without an alert', function () {
    $this->actingAs($this->patient)->get(route('patient.help.show'));
    $this->travel(5)->seconds();
    $this->actingAs($this->patient)->postJson(route('patient.help.calls'), ['line' => '112'])->assertNoContent();
    $this->travel(5)->seconds();
    $this->actingAs($this->patient)->postJson(route('patient.help.calls'), ['line' => 'crisis_line'])->assertNoContent();
    $this->actingAs($this->patient)->postJson(route('patient.help.calls'), ['line' => '03'])->assertUnprocessable();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            // События одной минуты — одной строкой, по порядку.
            ->where('helpEvents', [
                ['at' => '01.10.2026 19:00', 'label' => 'Открыт экран помощи, нажата кнопка «Позвонить 112», нажата кнопка кризисной линии'],
            ])
            ->where('alerts', [])
            ->where('patientSignals', 0));

    // Строки старше 30 дней врач не видит.
    $this->travel(31)->days();
    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('helpEvents', []));
});

test('the help screen and its actions are only for the patient', function () {
    $this->actingAs($this->doctor)->get(route('patient.help.show'))->assertForbidden();
    $this->actingAs($this->doctor)->post(route('patient.help.report'))->assertForbidden();
    $this->actingAs($this->doctor)->postJson(route('patient.help.calls'), ['line' => '112'])->assertForbidden();

    // Строки одного пациента не видны в карточке чужого врача: карточка ему недоступна.
    $this->actingAs($this->patient)->get(route('patient.help.show'));
    $stranger = User::factory()->doctor()->create();
    $this->actingAs($stranger)->get(route('doctor.patients.show', $this->patient))->assertNotFound();

    expect(Alert::query()->count())->toBe(0);
});

test('a repeat after the patient moves to another doctor reaches the new doctor', function () {
    Notification::fake();
    ($this->report)();

    $other = User::factory()->doctor()->create();
    subscribeToPush($other);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);
    CareRelationship::query()->create(['patient_id' => $this->patient->id, 'doctor_id' => $other->id, 'started_at' => now()]);

    $this->travel(2)->minutes();
    ($this->report)();

    Notification::assertSentToTimes($other, UrgentAlert::class, 1);
    $this->actingAs($other)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('urgentAlerts.0.repeats.count', 1));
});

test('the help screen reminds the patient that they already reported, in their own time zone', function () {
    $this->patient->patientProfile->update(['timezone' => 'Asia/Yekaterinburg']);

    ($this->report)()->assertInertiaFlash('helpReportedAt', '21:00');

    // Строка — о своём действии: остаётся и после решения врача, поэтому о нём ничего не говорит.
    $this->actingAs($this->doctor)->post(($this->alertRoute)('close', Alert::query()->sole()), ['decision' => 'contacted']);
    $this->travel(30)->minutes();

    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertInertia(fn (Assert $page) => $page->where('lastReport', 'сегодня в 21:00'));

    $this->travel(12)->hours();

    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertInertia(fn (Assert $page) => $page->where('lastReport', null));
});
