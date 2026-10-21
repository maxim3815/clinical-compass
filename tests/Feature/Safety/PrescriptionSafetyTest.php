<?php

use App\Actions\SyncSafetyFindings;
use App\Enums\SafetyDecisionKind;
use App\Enums\Sex;
use App\Models\AuditLog;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\SafetyFinding;
use App\Models\SafetyRule;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Safety\SafetyAcknowledgement;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->patient->patientProfile?->update(['birth_date' => DisplayTime::today()->subYears(40)]);
    $this->aviandr = Drug::query()->where('code', 'maritupirdine')->sole();
});

/**
 * Блок проверки формы назначения для препарата по коду.
 *
 * @return array<string, mixed>
 */
function safetyCheckFor(User $patient, string $drug): array
{
    return test()->getJson(route('doctor.patients.safety-check.show', [$patient, 'drug_id' => Drug::query()->where('code', $drug)->value('id')]))
        ->assertOk()
        ->json();
}

test('contraindication needs a justification and is saved with the decision and the rule snapshot', function () {
    diagnose($this->patient, 'F41.1');
    observe($this->patient, 'gfr', 52);
    $this->actingAs($this->doctor);

    $check = safetyCheckFor($this->patient, 'maritupirdine');

    expect($check['requires_justification'])->toBeTrue()
        ->and($check['findings'][0]['rule'])->toBe('maritupirdine.gfr')
        ->and($check['findings'][0]['level'])->toBe('contraindicated')
        ->and($check['findings'][0]['evidence'])->toBe(['СКФ 52 мл/мин/1,73 м² на '.now()->format('d.m.Y')])
        ->and($check['findings'][0]['source']['section'])->toBe('4.3. Противопоказания');

    $seen = [$check['findings'][0]['key']];

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($this->aviandr, ['safety_seen' => $seen]))
        ->assertSessionHasErrors(['justification' => SafetyAcknowledgement::JUSTIFICATION_REQUIRED]);
    expect(Prescription::query()->exists())->toBeFalse();

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($this->aviandr, [
        'safety_seen' => $seen,
        'justification' => 'Альтернативы исчерпаны, согласовано с нефрологом',
    ]))->assertRedirect(route('doctor.patients.show', $this->patient));

    $finding = SafetyFinding::query()->with('rule')->sole();

    expect($finding->rule->code)->toBe('maritupirdine.gfr')
        ->and($finding->prescription_id)->toBe(Prescription::query()->sole()->id)
        ->and($finding->decision_kind)->toBe(SafetyDecisionKind::Prescribed)
        ->and($finding->decision_text)->toBe('Альтернативы исчерпаны, согласовано с нефрологом')
        ->and($finding->decided_by_id)->toBe($this->doctor->id)
        ->and($finding->awaitsDecision())->toBeFalse();

    $audit = AuditLog::query()->where('action', 'prescription.prescribed')->sole();

    expect($audit->actor_id)->toBe($this->doctor->id)
        ->and($audit->after['justification'])->toBe('Альтернативы исчерпаны, согласовано с нефрологом')
        ->and($audit->after['safety'][0]['rule']['code'])->toBe('maritupirdine.gfr')
        ->and($audit->after['safety'][0]['rule']['version'])->toBe($finding->rule->version)
        ->and($audit->after['safety'][0]['rule']['source']['section'])->toBe('4.3. Противопоказания')
        ->and(AuditLog::query()->where('action', 'safety_finding.decided')->sole()->after['decision_text'])->toBe('Альтернативы исчерпаны, согласовано с нефрологом');
});

test('contraindication that appeared after the form was shown stops the save', function () {
    diagnose($this->patient, 'F41.1');
    $this->actingAs($this->doctor);

    expect(safetyCheckFor($this->patient, 'maritupirdine')['requires_justification'])->toBeFalse();

    // Пока форма открыта, кто-то вносит СКФ.
    observe($this->patient, 'gfr', 45);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($this->aviandr, ['justification' => 'Обоснование']))
        ->assertSessionHasErrors(['safety' => SafetyAcknowledgement::STALE]);

    expect(Prescription::query()->exists())->toBeFalse();
});

test('patient without a profile gets everything unchecked and no justification is needed', function () {
    $this->actingAs($this->doctor);
    $this->patient->patientProfile?->update(['birth_date' => null]);

    $check = safetyCheckFor($this->patient, 'maritupirdine');

    expect($check['findings'])->toBe([])
        ->and($check['requires_justification'])->toBeFalse()
        ->and($check['unchecked'])->toContain('диагноз', 'дата рождения', 'СКФ', 'Печёночная недостаточность', 'пол');

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload($this->aviandr))
        ->assertSessionHasNoErrors();

    expect(SafetyFinding::query()->exists())->toBeFalse();
});

test('pregnancy contraindication quotes the exception from the summary of product characteristics', function () {
    $this->patient->patientProfile?->update(['sex' => Sex::Female]);
    diagnose($this->patient, 'F41.1');
    observe($this->patient, 'pregnancy', true, '7 недель');
    $this->actingAs($this->doctor);

    $finding = safetyCheckFor($this->patient, 'maritupirdine')['findings'][0];

    expect($finding['rule'])->toBe('maritupirdine.pregnancy')
        ->and($finding['evidence'])->toBe(['Беременность: да (7 недель) на '.now()->format('d.m.Y')])
        ->and($finding['advice'])->toContain('польза для матери превышает возможный риск для плода')
        ->and($finding['source']['section'])->toBe('4.6. Фертильность, беременность и лактация');
});

test('drug outside the registered indications is a contraindication', function (string $diagnosis, string $drug) {
    diagnose($this->patient, $diagnosis);
    $this->actingAs($this->doctor);

    $check = safetyCheckFor($this->patient, $drug);

    expect($check['findings'][0]['rule'])->toBe("{$drug}.indications")
        ->and($check['findings'][0]['evidence'][0])->toStartWith("Диагноз {$diagnosis}")
        ->and($check['requires_justification'])->toBeTrue();
})->with([
    'overwork, escitalopram' => ['Z73.0', 'escitalopram'],
    'severe depression, aviandr' => ['F32.2', 'maritupirdine'],
    'gad, sertraline' => ['F41.1', 'sertraline'],
]);

test('new prescription shows the findings it causes for other drugs and one justification covers them', function () {
    diagnose($this->patient, 'F41.1');
    $escitalopram = takes($this->patient, 'escitalopram');
    $this->actingAs($this->doctor);

    $check = safetyCheckFor($this->patient, 'hydroxyzine');

    expect($check['findings'][0]['rule'])->toBe('hydroxyzine.qt_drugs')
        ->and($check['caused'][0]['rule'])->toBe('escitalopram.qt_drugs')
        ->and($check['caused'][0]['target'])->toBe('Эсциталопрам')
        ->and($check['caused'][0]['evidence'])->toBe(['Принимает Гидроксизин (новое назначение)']);

    $this->post(route('doctor.patients.prescriptions.store', $this->patient), prescriptionPayload(Drug::query()->where('code', 'hydroxyzine')->sole(), [
        'safety_seen' => [$check['findings'][0]['key'], $check['caused'][0]['key']],
        'justification' => 'Короткий курс, ЭКГ в норме',
    ]))->assertSessionHasNoErrors();

    expect(SafetyFinding::query()->where('prescription_id', $escitalopram->id)->sole()->decision_text)->toBe('Короткий курс, ЭКГ в норме')
        ->and(SafetyFinding::query()->awaitingDecision()->exists())->toBeFalse();
});

test('change needs a justification only while a contraindication is undecided and keeps the decision', function () {
    diagnose($this->patient, 'F41.1');
    $prescription = takes($this->patient, 'maritupirdine');
    observe($this->patient, 'gfr', 50);
    app(SyncSafetyFindings::class)->handle($this->patient);
    $this->actingAs($this->doctor);

    $update = fn (array $data) => $this->put(
        route('doctor.patients.prescriptions.update', [$this->patient, Prescription::query()->whereNull('closed_at')->sole()]),
        prescriptionPayload($this->aviandr, $data),
    );
    $finding = SafetyFinding::query()->sole();
    $key = $finding->rule_id.'@new';

    $update(['intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 1]], 'safety_seen' => [$key]])
        ->assertSessionHasErrors(['justification' => SafetyAcknowledgement::JUSTIFICATION_REQUIRED]);

    $update(['intakes' => [['time_of_day' => 'morning', 'tablets' => 1], ['time_of_day' => 'evening', 'tablets' => 1]], 'safety_seen' => [$key], 'justification' => 'Нефролог разрешил'])
        ->assertSessionHasNoErrors();

    $finding->refresh();
    $current = Prescription::query()->whereNull('closed_at')->sole();

    expect($current->id)->not->toBe($prescription->id)
        ->and($finding->prescription_id)->toBe($current->id)
        ->and($finding->closed_at)->toBeNull()
        ->and($finding->decision_text)->toBe('Нефролог разрешил');

    // Решение уже есть: следующее изменение без обоснования.
    $update(['intakes' => [['time_of_day' => 'morning', 'tablets' => 1]]])->assertSessionHasNoErrors();

    expect(SafetyFinding::query()->sole()->decision_text)->toBe('Нефролог разрешил');
});

test('edit page shows the check for the drug being changed', function () {
    diagnose($this->patient, 'F41.1');
    observe($this->patient, 'gfr', 50);
    $prescription = takes($this->patient, 'maritupirdine');
    // Ключ «new»: находку увидит новая версия, которая заменит меняемую.
    $key = SafetyRule::query()->where('code', 'maritupirdine.gfr')->value('id').'@new';

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.edit', [$this->patient, $prescription]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('safety.findings.0.rule', 'maritupirdine.gfr')
            ->where('safety.findings.0.key', $key)
            ->where('safety.requires_justification', true),
        );
});

test('check is available only to the treating doctor', function () {
    $url = route('doctor.patients.safety-check.show', [$this->patient, 'drug_id' => $this->aviandr->id]);

    $this->actingAs(User::factory()->doctor()->create())->getJson($url)->assertNotFound();
    $this->actingAs($this->patient)->getJson($url)->assertForbidden();
    $this->actingAs($this->doctor)->getJson(route('doctor.patients.safety-check.show', $this->patient))->assertUnprocessable();
});
