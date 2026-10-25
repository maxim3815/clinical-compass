<?php

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create();
    // Только что зарегистрировался: правила «Когда приложение не поможет» ещё не прочитаны.
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->patient->patientProfile?->update(['rules_acknowledged_at' => null]);
});

test('new patient sees the rules before any cabinet page and returns there after acknowledging', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.medications'))
        ->assertRedirect(route('patient.rules.show'));

    // Вкладки и логотип не уводят с экрана правил: все разделы вели бы обратно.
    $this->get(route('patient.rules.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Rules')
            ->where('acknowledged', false)
            ->where('rulesPending', true),
        );

    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00'));

    // После «Понятно» — туда, куда пациент шёл, например по пушу.
    $this->post(route('patient.rules.acknowledge'))
        ->assertRedirect(route('patient.medications'));

    expect($this->patient->patientProfile?->refresh()->rules_acknowledged_at?->toDateTimeString())->toBe('2026-10-25 12:00:00')
        ->and(AuditLog::query()->where('action', 'patient.rules_acknowledged')->sole()->patient_id)->toBe($this->patient->id);

    $this->get(route('patient.home'))->assertOk();
});

test('acknowledging the rules goes to today and does not repeat', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.rules.acknowledge'))
        ->assertRedirect(route('patient.home'));

    $acknowledgedAt = $this->patient->patientProfile?->refresh()->rules_acknowledged_at;
    $this->travel(5)->minutes();

    // Вторая вкладка или двойное касание: дата и запись в журнале те же.
    $this->post(route('patient.rules.acknowledge'))->assertRedirect(route('patient.home'));

    expect($this->patient->patientProfile?->refresh()->rules_acknowledged_at?->equalTo($acknowledgedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'patient.rules_acknowledged')->count())->toBe(1);
});

test('help screen and chat open before the rules are read', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('patient/Help'));

    // «Написать врачу» с экрана помощи — тоже путь за помощью.
    $this->get(route('patient.chat.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('patient/Chat'));

    $this->post(route('patient.help.calls'), ['line' => '112'])->assertNoContent();
    $this->post(route('patient.help.report'))->assertRedirect(route('patient.help.show'));
});

test('data requests are not redirected to the rules', function () {
    $this->actingAs($this->patient)
        ->getJson(route('patient.chat.messages', ['before' => 1]))
        ->assertOk()
        ->assertJsonPath('messages', []);
});

test('patient who read the rules opens them again without the button', function () {
    $this->patient->patientProfile?->update(['rules_acknowledged_at' => now()]);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertOk();

    $this->get(route('patient.rules.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('acknowledged', true)
            ->where('fromHelp', false)
            ->where('rulesPending', false),
        );

    // С экрана помощи: ссылка назад ведёт туда.
    $this->get(route('patient.rules.show', ['from' => 'help']))
        ->assertInertia(fn (Assert $page) => $page->where('fromHelp', true));

    // Согласие из настроек открывается в кабинете, с датой по часовому поясу пациента.
    $this->patient->patientProfile?->update(['consented_at' => CarbonImmutable::parse('2026-10-24 22:30', 'UTC')]);

    $this->get(route('patient.consent'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Consent')
            ->where('consentedOn', '25 октября 2026'),
        );
});

test('rules are only for patients', function () {
    $this->actingAs($this->doctor)
        ->get(route('patient.rules.show'))
        ->assertForbidden();

    $this->post(route('patient.rules.acknowledge'))->assertForbidden();
});
