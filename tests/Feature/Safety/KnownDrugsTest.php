<?php

use App\Actions\SyncSafetyFindings;
use App\Models\Drug;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\DisplayTime;

beforeEach(function () {
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->patient->patientProfile?->update(['birth_date' => DisplayTime::today()->subYears(40)]);
    // Показания всех пяти препаратов справочника: депрессивный эпизод и ГТР.
    diagnose($this->patient, 'F32.1');
    diagnose($this->patient, 'F41.1', main: false);
});

/**
 * Открытые находки пациента после синхронизации: код правила → уровень.
 *
 * @return array<string, string>
 */
function openFindings(User $patient): array
{
    app(SyncSafetyFindings::class)->handle($patient);

    return SafetyFinding::query()->open()->with('rule')->get()
        ->mapWithKeys(fn (SafetyFinding $finding): array => [$finding->rule->code => $finding->level->value])
        ->sortKeys()
        ->all();
}

test('list file loads with the catalog and its drugs are in the interaction groups', function () {
    $known = Drug::query()->where('kind', 'known')->get()->keyBy('code');

    expect($known)->not->toBeEmpty()
        ->and($known->every(fn (Drug $drug): bool => $drug->is_active && $drug->strengths()->doesntExist() && $drug->regimen === null && $drug->reason !== null))->toBeTrue()
        ->and($known['amiodarone']->trade_names)->toContain('Кордарон')
        ->and($known['amiodarone']->groups)->toBe(['qt_prolonging'])
        ->and($known['acetylsalicylic_acid']->trade_names)->toContain('Кардиомагнил')
        ->and($known['tramadol']->groups)->toBe(['serotonergic'])
        ->and(Drug::query()->catalog()->where('code', 'escitalopram')->sole()->trade_names)->toContain('Ципралекс');
});

test('own drug from the list raises findings by the rules of the catalog drug', function (string $own, string $prescribed, array $expected) {
    $prescription = takes($this->patient, $prescribed);
    $ownPrescription = takes($this->patient, $own, own: true);

    expect(openFindings($this->patient))->toBe($expected);

    foreach (SafetyFinding::query()->open()->get() as $finding) {
        expect($finding->prescription_id)->toBe($prescription->id)
            ->and($finding->related_prescription_ids)->toBe([$ownPrescription->id]);
    }
})->with([
    'tramadol with escitalopram' => ['tramadol', 'escitalopram', ['escitalopram.serotonergic' => 'caution']],
    'amiodarone with escitalopram' => ['amiodarone', 'escitalopram', ['escitalopram.qt_drugs' => 'contraindicated']],
    'acetylsalicylic acid with sertraline' => ['acetylsalicylic_acid', 'sertraline', ['sertraline.bleeding' => 'caution']],
    'warfarin with escitalopram' => ['warfarin', 'escitalopram', ['escitalopram.bleeding' => 'caution']],
    'ibuprofen with paroxetine' => ['ibuprofen', 'paroxetine', ['paroxetine.bleeding' => 'caution']],
    'omeprazole with escitalopram' => ['omeprazole', 'escitalopram', ['escitalopram.cyp2c19' => 'caution']],
    'linezolid with escitalopram' => ['linezolid', 'escitalopram', ['escitalopram.maoi' => 'contraindicated']],
    'rasagiline with escitalopram' => ['rasagiline', 'escitalopram', ['escitalopram.maoi_b' => 'contraindicated']],
    'rasagiline with sertraline' => ['rasagiline', 'sertraline', ['sertraline.maoi' => 'contraindicated']],
    'pirlindole with sertraline' => ['pirlindole', 'sertraline', ['sertraline.maoi_reversible' => 'contraindicated']],
    'pirlindole with paroxetine' => ['pirlindole', 'paroxetine', ['paroxetine.maoi' => 'contraindicated']],
    'thioridazine with paroxetine' => ['thioridazine', 'paroxetine', ['paroxetine.qt_drugs' => 'caution', 'paroxetine.thioridazine' => 'contraindicated']],
    'tamoxifen with paroxetine' => ['tamoxifen', 'paroxetine', ['paroxetine.tamoxifen' => 'caution']],
    'phenazepam with aviandr' => ['phenazepam', 'maritupirdine', ['maritupirdine.sedative' => 'caution']],
    'fluoxetine with aviandr' => ['fluoxetine', 'maritupirdine', ['maritupirdine.cyp2d6' => 'caution', 'maritupirdine.serotonergic' => 'caution']],
    'phenazepam with hydroxyzine' => ['phenazepam', 'hydroxyzine', ['hydroxyzine.sedative' => 'caution']],
    'rivaroxaban with hydroxyzine' => ['rivaroxaban', 'hydroxyzine', ['hydroxyzine.anticoagulants' => 'caution']],
    'haloperidol with hydroxyzine' => ['haloperidol', 'hydroxyzine', ['hydroxyzine.qt_drugs' => 'contraindicated', 'hydroxyzine.sedative' => 'caution']],
]);

test('own catalog drug of the patient gets the finding with a drug from the list', function () {
    $escitalopram = takes($this->patient, 'escitalopram', own: true);
    takes($this->patient, 'amiodarone', own: true);

    expect(openFindings($this->patient))->toBe(['escitalopram.qt_drugs' => 'contraindicated'])
        ->and(SafetyFinding::query()->sole()->prescription_id)->toBe($escitalopram->id);
});

test('two drugs from the list are not checked against each other', function () {
    takes($this->patient, 'tramadol', own: true);
    takes($this->patient, 'sumatriptan', own: true);

    expect(openFindings($this->patient))->toBe([]);
});

test('drug from the list can not be prescribed or checked as a prescription by the doctor', function () {
    $tramadol = Drug::query()->where('code', 'tramadol')->sole();
    $this->actingAs($this->doctor);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($tramadol))
        ->assertSessionHasErrors(['drug_id' => 'Выберите препарат из справочника.']);

    $this->getJson(route('doctor.patients.safety-check.show', [$this->patient, 'drug_id' => $tramadol->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('drug_id');

    $this->get(route('doctor.patients.prescriptions.create', $this->patient))
        ->assertInertia(fn ($page) => $page->has('drugs', 5));
});
