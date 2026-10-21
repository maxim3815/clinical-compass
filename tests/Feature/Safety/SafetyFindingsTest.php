<?php

use App\Actions\AddOwnMedication;
use App\Actions\RecordObservation;
use App\Actions\SyncSafetyFindings;
use App\Enums\PrescriptionKind;
use App\Enums\SafetyDecisionKind;
use App\Enums\Sex;
use App\Events\PatientSignalsChanged;
use App\Models\Drug;
use App\Models\ObservationType;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\OwnMedicationData;
use App\Support\PatientSignals;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    // Возраст из фабрики случайный, а у гидроксизина есть правило для пожилых.
    $this->patient->patientProfile?->update(['sex' => Sex::Female, 'birth_date' => DisplayTime::today()->subYears(35)]);
    diagnose($this->patient, 'F41.1');
});

/**
 * Пациентка принимает свои эсциталопрам и гидроксизин: сочетание противопоказано (QT).
 *
 * @return array{Prescription, Prescription}
 */
function qtCombination(User $patient): array
{
    $pair = [takes($patient, 'escitalopram', own: true), takes($patient, 'hydroxyzine', own: true)];
    app(SyncSafetyFindings::class)->handle($patient);

    return $pair;
}

test('fact recorded for a patient on a drug raises a contraindication and the list mark', function () {
    $prescription = takes($this->patient, 'maritupirdine');
    $type = ObservationType::query()->where('code', 'pregnancy')->sole();
    Event::fake([PatientSignalsChanged::class]);

    expect(PatientSignals::count($this->doctor))->toBe(0);

    app(RecordObservation::class)->handle($this->doctor, $this->patient, $type, true, '5 недель', DisplayTime::today());

    $finding = SafetyFinding::query()->with('rule')->sole();

    expect($finding->rule->code)->toBe('maritupirdine.pregnancy')
        ->and($finding->prescription_id)->toBe($prescription->id)
        ->and($finding->awaitsDecision())->toBeTrue()
        ->and(PatientSignals::count($this->doctor))->toBe(1);

    Event::assertDispatched(PatientSignalsChanged::class, fn (PatientSignalsChanged $event) => $event->doctor->is($this->doctor));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('patients.data.0.contraindications', 1)
            ->where('patientSignals', 1),
        );
});

test('patient adding a drug that clashes with the doctor prescription raises findings for the doctor', function () {
    $escitalopram = takes($this->patient, 'escitalopram');

    app(AddOwnMedication::class)->handle($this->patient, new OwnMedicationData(
        drug: Drug::query()->where('code', 'hydroxyzine')->sole(),
        drugName: null,
        dose: '1 таблетка 25 мг',
        kind: PrescriptionKind::AsNeeded,
        intakes: [],
        maxDailyDoses: null,
        startsOn: DisplayTime::today(),
        endsOn: null,
        prescribedBy: 'невролог',
    ));

    expect(SafetyFinding::query()->with('rule')->get()->map(fn (SafetyFinding $finding): string => $finding->rule->code)->sort()->values()->all())
        ->toBe(['escitalopram.qt_drugs', 'hydroxyzine.qt_drugs'])
        ->and(SafetyFinding::query()->where('prescription_id', $escitalopram->id)->sole()->evidence)->toBe(['Принимает Гидроксизин (добавил пациент)'])
        ->and(PatientSignals::count($this->doctor))->toBe(1);
});

test('continue with a justification decides both sides of a combination and clears the mark', function () {
    [$escitalopram, $hydroxyzine] = qtCombination($this->patient);
    $finding = SafetyFinding::query()->where('prescription_id', $hydroxyzine->id)->sole();
    Event::fake([PatientSignalsChanged::class]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where("safety.{$hydroxyzine->id}.findings.0.awaits_decision", true)
            ->where("safety.{$hydroxyzine->id}.findings.0.related", ['Эсциталопрам (добавил пациент)'])
            ->where("safety.{$escitalopram->id}.findings.0.rule", 'escitalopram.qt_drugs'),
        );

    expect(PatientSignals::count($this->doctor))->toBe(1);

    $this->post(route('doctor.patients.safety-findings.continue', [$this->patient, $finding]), ['justification' => ''])
        ->assertSessionHasErrors('justification');

    $this->post(route('doctor.patients.safety-findings.continue', [$this->patient, $finding]), ['justification' => 'Написал пациентке прекратить гидроксизин'])
        ->assertRedirect(route('doctor.patients.show', $this->patient));

    expect(SafetyFinding::query()->awaitingDecision()->exists())->toBeFalse()
        ->and(SafetyFinding::query()->pluck('decision_kind')->unique()->all())->toBe([SafetyDecisionKind::Continued])
        ->and(PatientSignals::count($this->doctor))->toBe(0);

    Event::assertDispatched(PatientSignalsChanged::class);

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where("safety.{$hydroxyzine->id}.findings.0.awaits_decision", false)
            ->where("safety.{$hydroxyzine->id}.findings.0.decision.text", 'Написал пациентке прекратить гидроксизин')
            ->where("safety.{$hydroxyzine->id}.findings.0.decision.kind_label", 'Приём продолжен'),
        );
});

test('finding closes when its data goes away and comes back as a new undecided finding', function () {
    $prescription = takes($this->patient, 'maritupirdine');
    observe($this->patient, 'gfr', 50, on: DisplayTime::today()->subDays(2)->toDateString());
    app(SyncSafetyFindings::class)->handle($this->patient);
    $first = SafetyFinding::query()->sole();
    $first->update(['decided_at' => now(), 'decided_by_id' => $this->doctor->id, 'decision_kind' => SafetyDecisionKind::Continued, 'decision_text' => 'Нефролог разрешил']);

    observe($this->patient, 'gfr', 75, on: DisplayTime::today()->subDay()->toDateString());
    app(SyncSafetyFindings::class)->handle($this->patient);

    expect($first->fresh()?->closed_at)->not->toBeNull()
        ->and($first->fresh()?->decision_text)->toBe('Нефролог разрешил');

    observe($this->patient, 'gfr', 48);
    app(SyncSafetyFindings::class)->handle($this->patient);

    $second = SafetyFinding::query()->whereNull('closed_at')->sole();

    expect($second->id)->not->toBe($first->id)
        ->and($second->prescription_id)->toBe($prescription->id)
        ->and($second->awaitsDecision())->toBeTrue();
});

test('cancelled prescription closes its findings', function () {
    observe($this->patient, 'gfr', 50);
    $prescription = takes($this->patient, 'maritupirdine');
    app(SyncSafetyFindings::class)->handle($this->patient);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]))
        ->assertSessionHasNoErrors();

    expect(SafetyFinding::query()->sole()->closed_at)->not->toBeNull()
        ->and(PatientSignals::count($this->doctor))->toBe(0);
});

test('sync repeated without changes changes nothing', function () {
    qtCombination($this->patient);
    $before = SafetyFinding::query()->orderBy('id')->get(['id', 'prescription_id', 'rule_id', 'closed_at'])->toArray();
    Event::fake([PatientSignalsChanged::class]);

    app(SyncSafetyFindings::class)->handle($this->patient);

    expect(SafetyFinding::query()->orderBy('id')->get(['id', 'prescription_id', 'rule_id', 'closed_at'])->toArray())->toBe($before);
    Event::assertNotDispatched(PatientSignalsChanged::class);
});

test('only the treating doctor decides on a finding', function () {
    [, $hydroxyzine] = qtCombination($this->patient);
    $finding = SafetyFinding::query()->where('prescription_id', $hydroxyzine->id)->sole();
    $url = route('doctor.patients.safety-findings.continue', [$this->patient, $finding]);

    $this->actingAs(User::factory()->doctor()->create())->post($url, ['justification' => 'Нет'])->assertNotFound();
    $this->actingAs($this->patient)->post($url, ['justification' => 'Нет'])->assertForbidden();

    $other = User::factory()->patientOf($this->doctor)->create();
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.safety-findings.continue', [$other, $finding]), ['justification' => 'Нет'])
        ->assertNotFound();

    expect(SafetyFinding::query()->awaitingDecision()->count())->toBe(2);
});

test('patient sees nothing of the safety check', function () {
    qtCombination($this->patient);

    $this->actingAs($this->patient);

    foreach (['patient.home', 'patient.medications'] as $route) {
        $props = json_encode($this->get(route($route))->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

        expect($props)->not->toContain('Противопоказ')
            ->not->toContain('С осторожностью')
            ->not->toContain('safety')
            ->not->toContain('justification');
    }
});
