<?php

use App\Actions\CancelPrescription;
use App\Actions\ContinueDespiteFinding;
use App\Actions\SyncSafetyFindings;
use App\Enums\PrescriptionCancelReason;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Safety\SafetySignals;
use Inertia\Testing\AssertableInertia as Assert;

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

/**
 * Подсказка «расскажите врачу» у своих препаратов в «Моих препаратах»: id назначения => есть ли.
 *
 * @return array<int, bool>
 */
function tellDoctor(User $patient): array
{
    app(SyncSafetyFindings::class)->handle($patient);
    $hints = [];

    test()->actingAs($patient)
        ->get(route('patient.medications'))
        ->assertInertia(function (Assert $page) use (&$hints) {
            foreach ($page->toArray()['props']['ownMedications'] as $medication) {
                $hints[$medication['id']] = $medication['tell_doctor'];
            }
        });

    return $hints;
}

test('patient is told to tell the doctor about own drug in a contraindicated combination until the doctor decides', function () {
    takes($this->patient, 'escitalopram');
    $amiodarone = takes($this->patient, 'amiodarone', own: true);
    $amiodarone->update(['trade_name' => 'Кордарон']);

    expect(tellDoctor($this->patient))->toBe([$amiodarone->id => true]);

    $this->get(route('patient.medications'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownMedications.0.drug.name', 'Кордарон')
            ->missing('prescriptions.0.tell_doctor'));

    app(ContinueDespiteFinding::class)->handle($this->doctor, SafetyFinding::query()->sole(), 'Кардиолог контролирует ЭКГ.');

    expect(tellDoctor($this->patient))->toBe([$amiodarone->id => false]);
});

test('hint disappears when the combination is gone', function () {
    $escitalopram = takes($this->patient, 'escitalopram');
    $amiodarone = takes($this->patient, 'amiodarone', own: true);

    expect(tellDoctor($this->patient))->toBe([$amiodarone->id => true]);

    app(CancelPrescription::class)->handle($this->doctor, $escitalopram, PrescriptionCancelReason::Other, 'Заменить препарат');

    expect(tellDoctor($this->patient))->toBe([$amiodarone->id => false]);
});

test('patient gets no hint at a caution combination', function () {
    takes($this->patient, 'escitalopram');
    $aspirin = takes($this->patient, 'acetylsalicylic_acid', own: true);

    expect(openFindings($this->patient))->toBe(['escitalopram.bleeding' => 'caution'])
        ->and(tellDoctor($this->patient))->toBe([$aspirin->id => false]);
});

test('both own catalog drugs of a contraindicated pair get the hint', function () {
    $escitalopram = takes($this->patient, 'escitalopram', own: true);
    $hydroxyzine = takes($this->patient, 'hydroxyzine', own: true);

    expect(tellDoctor($this->patient))->toEqual([$escitalopram->id => true, $hydroxyzine->id => true]);
});

test('patient adding a drug in a contraindicated combination is told at once', function (string $code, array $toast) {
    takes($this->patient, 'escitalopram');
    $drug = Drug::query()->where('code', $code)->sole();

    $this->actingAs($this->patient)
        ->post(route('patient.own-medications.store'), [
            'drug_id' => $drug->id,
            'trade_name' => $drug->trade_names[0],
            'dose' => '1 таблетка',
            'kind' => 'regular',
            'intakes' => [['time_of_day' => 'evening', 'time' => '20:00']],
            'starts_on' => DisplayTime::today()->toDateString(),
        ])
        ->assertRedirect(route('patient.medications'))
        ->assertInertiaFlash('toast', $toast)
        ->assertInertiaFlash('addedMedication', Prescription::query()->where('source', 'patient')->sole()->id);
})->with([
    'amiodarone' => ['amiodarone', ['type' => 'success', 'message' => 'Препарат добавлен. Расскажите врачу, что принимаете его.']],
    'acetylsalicylic acid' => ['acetylsalicylic_acid', ['type' => 'success', 'message' => 'Препарат добавлен.']],
]);

test('doctor sees the drug from the list with the chosen name and the finding at the catalog drug', function () {
    $escitalopram = takes($this->patient, 'escitalopram');
    $amiodarone = takes($this->patient, 'amiodarone', own: true);
    $amiodarone->update(['trade_name' => 'Кордарон']);
    app(SyncSafetyFindings::class)->handle($this->patient);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ownMedications.0.drug.name', 'Амиодарон (Кордарон)')
            ->where('ownMedications.0.drug.kind', 'known')
            ->where('ownMedications.0.drug.reason', fn (string $reason): bool => str_starts_with($reason, 'Назван в ОХЛП «Атаракса» (4.5)'))
            // Своих правил у препарата списка нет: блока проверки у него нет, находка — у эсциталопрама.
            ->missing("safety.{$amiodarone->id}")
            ->where("safety.{$escitalopram->id}.findings.0.rule", 'escitalopram.qt_drugs')
            ->where("safety.{$escitalopram->id}.findings.0.related", ['Амиодарон (Кордарон, добавил пациент)'])
            ->where("safety.{$escitalopram->id}.findings.0.related_ids", [$amiodarone->id])
            ->where("safety.{$escitalopram->id}.findings.0.evidence", ['Принимает Амиодарон (Кордарон, добавил пациент)'])
            ->where("safety.{$escitalopram->id}.unknown_drugs", []),
        );

    // В очереди «Требуют внимания» названа и пара: эсциталопрам назначен давно, изменился амиодарон.
    expect(SafetySignals::awaitingDrugs([$this->patient->id]))->toBe([$this->patient->id => ['Амиодарон (Кордарон)', 'Эсциталопрам']]);
});

test('drugs page lists the drugs from other doctors with groups and the reason', function () {
    $this->actingAs($this->doctor)
        ->get(route('doctor.drugs.index'))
        ->assertInertia(function (Assert $page) {
            $page->has('drugs', 5)->has('known', Drug::query()->where('kind', 'known')->count());
            $known = collect($page->toArray()['props']['known'])->keyBy('code');

            expect($known['amiodarone']['trade_names'])->toBe(['Кордарон'])
                ->and($known['amiodarone']['groups'])->toBe(['Препараты, удлиняющие интервал QT'])
                ->and($known['amiodarone']['reason'])->toStartWith('Назван в ОХЛП «Атаракса» (4.5)')
                ->and($known['rasagiline']['groups'])->toBe(['Ингибиторы МАО', 'Необратимые ингибиторы МАО'])
                // Пометка для сверки медиком врачу не показывается.
                ->and($known->every(fn (array $drug): bool => ! str_contains($drug['reason'], '[медик]')))->toBeTrue()
                ->and($known['acetylsalicylic_acid']['reason'])->toEndWith('магния гидроксидом.');
        });
});
