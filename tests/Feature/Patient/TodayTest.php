<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\RussianTimezone;
use App\Models\AsNeededDose;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    $this->patient = User::factory()->patient()->create();
    $this->weekAgo = ['starts_on' => '2026-10-12', 'effective_at' => now()->subWeek()];
    $this->aviandr = catalogDrug('Авиандр', [20 => false], ['missed_dose' => 'Пропущенную таблетку не принимайте.'])->strengths()->sole();
    $this->sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();
});

test('today shows doses by time and groups doses at the same time', function () {
    $aviandr = timedPrescription($this->patient, $this->aviandr, ['morning' => '08:00', 'evening' => '20:00'], [...$this->weekAgo, 'instruction' => 'За 30 минут до еды.']);
    timedPrescription($this->patient, $this->sertraline, ['morning' => '08:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);
    $morning = doseOn($this->patient, '2026-10-19', 'morning', $aviandr);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Home')
            ->where('hasPrescriptions', true)
            ->where('today.date', '19 октября')
            ->where('today.groups.0.time', '8:00')
            ->where('today.groups.0.doses.0', [
                'id' => $morning->id,
                'drug' => 'Авиандр',
                'dose' => '1 таблетка, 20 мг',
                'instruction' => 'За 30 минут до еды.',
                'day' => 'сегодня',
                'time' => '8:00',
                'status' => 'scheduled',
                'status_text' => null,
                'taken_late' => false,
                'skip_comment' => null,
                'marked_late' => null,
                'missed_dose' => null,
                'due' => true,
                'due_from' => '7:00',
                'taken_time' => '08:00',
                'skip_reason' => null,
            ])
            ->where('today.groups.0.doses.1.drug', 'Сертралин')
            ->where('today.groups.1.time', '20:00')
            ->has('today.groups.1.doses', 1)
            ->where('today.groups.1.doses.0.due', false)
            ->where('today.groups.1.doses.0.due_from', '19:00')
            ->where('yesterday.groups', [])
            ->where('asNeeded', [])
            ->where('skipReasons', DoseSkipReason::options()),
        );
});

test('today follows the timezone of the patient', function () {
    $this->patient->patientProfile?->update(['timezone' => RussianTimezone::Kamchatka]);
    // 12:00 по Москве — 21:00 на Камчатке, а 0:30 — уже следующие сутки.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:30', 'Europe/Moscow'));
    timedPrescription($this->patient, $this->aviandr, ['morning' => '08:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.date', '20 октября')
            ->where('today.groups.0.time', '8:00')
            ->where('today.groups.0.doses.0.due', false)
            ->where('today.groups.0.doses.0.due_from', '7:00'),
        );
});

test('yesterday shows doses without a mark and doses marked today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'Europe/Moscow'));
    $prescription = timedPrescription($this->patient, $this->aviandr, ['morning' => '08:00', 'afternoon' => '14:00', 'evening' => '20:00', 'bedtime' => '23:00'], $this->weekAgo);
    app(SyncDoseEvents::class)->handle($this->patient);
    // Вчера отмечены дневной и вечерний приёмы, сегодня ночью — приём перед сном.
    doseOn($this->patient, '2026-10-18', 'afternoon')->update(['status' => DoseEventStatus::Taken, 'taken_at' => now()->addHours(2), 'marked_at' => now()->addHours(2)]);
    doseOn($this->patient, '2026-10-18', 'evening')->update(['status' => DoseEventStatus::Skipped, 'skip_reason' => DoseSkipReason::Forgot, 'marked_at' => now()->addHours(8)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    doseOn($this->patient, '2026-10-18', 'bedtime')->update(['status' => DoseEventStatus::Taken, 'taken_at' => now()->subHours(13), 'marked_at' => now()->subHours(11)]);
    app(SyncDoseEvents::class)->handle($this->patient);
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('yesterday.groups', 2)
            ->where('yesterday.groups.0.time', '8:00')
            ->where('yesterday.groups.0.doses.0.day', 'вчера')
            ->where('yesterday.groups.0.doses.0.status_text', 'Пропущен')
            ->where('yesterday.groups.0.doses.0.missed_dose', 'Пропущенную таблетку не принимайте.')
            ->where('yesterday.groups.1.time', '23:00')
            ->where('yesterday.groups.1.doses.0.status_text', 'Принят в 23:00')
            // Окно приёма в 23:00 закрылось в 3:30, отметка в 1:00.
            ->where('yesterday.groups.1.doses.0.marked_late', null),
        );

    expect($prescription->doseEvents()->where('local_date', '2026-10-18')->count())->toBe(4);
});

test('patient without prescriptions sees that the doctor has not prescribed anything yet', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPrescriptions', false)
            ->where('today.groups', []),
        );

    // Будущее назначение уже есть, хотя приёмов сегодня нет.
    timedPrescription($this->patient, $this->aviandr, ['morning' => '08:00'], ['starts_on' => '2026-10-25']);

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPrescriptions', true)
            ->where('today.groups', []),
        );
});

test('as-needed shows active prescriptions with doses of today and yesterday', function () {
    $hydroxyzine = Prescription::factory()->for($this->patient, 'patient')->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->asNeeded()->create(['starts_on' => '2026-10-12']);
    $aviandr = Prescription::factory()->for($this->patient, 'patient')->for($this->aviandr, 'strength')->asNeeded('1', 2)->create(['starts_on' => '2026-10-12']);
    // Будущее, отменённое и регулярное в блок не попадают.
    Prescription::factory()->for($this->patient, 'patient')->for($this->sertraline, 'strength')->asNeeded()->create(['starts_on' => '2026-10-20']);
    Prescription::factory()->for($this->patient, 'patient')->for($this->sertraline, 'strength')->asNeeded()->create([
        'starts_on' => '2026-10-12',
        'ends_on' => '2026-10-18',
        'closed_at' => now()->subDay(),
        'close_reason' => PrescriptionCloseReason::Cancelled,
    ]);
    timedPrescription($this->patient, $this->sertraline, ['morning' => '08:00'], $this->weekAgo);
    $recent = AsNeededDose::factory()->for($aviandr)->create(['local_date' => '2026-10-19', 'taken_at' => now()->subHours(2)]);
    $yesterday = AsNeededDose::factory()->for($aviandr)->create(['local_date' => '2026-10-18', 'taken_at' => now()->subHours(13)]);
    AsNeededDose::factory()->for($aviandr)->create(['local_date' => '2026-10-17', 'taken_at' => now()->subDays(2)]);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('asNeeded', 2)
            ->where('asNeeded.0.id', $aviandr->id)
            ->where('asNeeded.0.taken_today', 1)
            ->where('asNeeded.0.doses', [
                ['id' => $recent->id, 'day' => 'Сегодня', 'time' => '10:00'],
                ['id' => $yesterday->id, 'day' => 'Вчера', 'time' => '23:00'],
            ])
            ->where('asNeeded.1.id', $hydroxyzine->id)
            ->where('asNeeded.1.taken_today', 0)
            ->where('asNeeded.1.doses', []),
        );
});

test('today shows doses of medications added by the patient together with prescriptions of the doctor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00', 'Europe/Moscow'));
    timedPrescription($this->patient, $this->sertraline, ['morning' => '08:00'], $this->weekAgo);
    $other = ownPrescription($this->patient, times: ['morning' => '08:00'], attributes: [...$this->weekAgo, 'dose' => '1 таблетка 250 мг']);
    ownPrescription($this->patient, $this->aviandr->drug, ['morning' => '08:00'], [...$this->weekAgo, 'dose' => '20 мг']);
    app(SyncDoseEvents::class)->handle($this->patient);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    app(SyncDoseEvents::class)->handle($this->patient);
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('today.groups.0.time', '8:00')
            ->where('today.groups.0.doses.0.drug', 'Авиандр')
            ->where('today.groups.0.doses.0.dose', '20 мг')
            ->where('today.groups.0.doses.1.drug', 'Сертралин')
            ->where('today.groups.0.doses.1.dose', '1 таблетка, 50 мг')
            ->where('today.groups.0.doses.2.drug', 'Фенибут')
            ->where('today.groups.0.doses.2.dose', '1 таблетка 250 мг')
            // Что делать при пропуске дозы — из справочника, у «другого» препарата текста нет.
            ->where('yesterday.groups.0.doses.0.missed_dose', 'Пропущенную таблетку не принимайте.')
            ->where('yesterday.groups.0.doses.2.status_text', 'Пропущен')
            ->where('yesterday.groups.0.doses.2.missed_dose', null),
        );

    $dose = doseOn($this->patient, '2026-10-19', 'morning', $other);

    $this->put(route('patient.doses.update', $dose), ['status' => 'taken'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Отметка сохранена.']);

    expect($dose->fresh()?->status)->toBe(DoseEventStatus::Taken);
});

test('medications added by the patient count as prescriptions on the home page', function () {
    ownPrescription($this->patient, attributes: ['starts_on' => '2026-10-25']);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPrescriptions', true)
            ->where('today.groups', []),
        );
});
