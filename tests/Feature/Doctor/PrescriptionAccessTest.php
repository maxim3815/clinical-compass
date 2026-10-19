<?php

use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->hydroxyzine = catalogDrug('Гидроксизин');
    $this->prescription = regularPrescription($this->patient, $this->hydroxyzine->strengths()->sole());
});

dataset('prescription routes', [
    'create form' => ['get', 'doctor.patients.prescriptions.create', false],
    'store' => ['post', 'doctor.patients.prescriptions.store', false],
    'edit form' => ['get', 'doctor.patients.prescriptions.edit', true],
    'update' => ['put', 'doctor.patients.prescriptions.update', true],
    'cancel' => ['post', 'doctor.patients.prescriptions.cancel', true],
]);

/**
 * Адрес маршрута назначений для пациента, с назначением или без.
 */
function prescriptionRoute(string $name, bool $withPrescription, User $patient, Prescription $prescription): string
{
    return $withPrescription ? route($name, [$patient, $prescription]) : route($name, $patient);
}

test('patient can not open doctor prescription pages', function (string $method, string $name, bool $withPrescription) {
    $this->actingAs($this->patient)
        ->call($method, prescriptionRoute($name, $withPrescription, $this->patient, $this->prescription), prescriptionPayload($this->hydroxyzine))
        ->assertForbidden();
})->with('prescription routes');

test('guest is sent to the login page', function (string $method, string $name, bool $withPrescription) {
    $this->call($method, prescriptionRoute($name, $withPrescription, $this->patient, $this->prescription))
        ->assertRedirect(route('login'));
})->with('prescription routes');

test('doctor gets not found for a patient who is not theirs', function (string $method, string $name, bool $withPrescription) {
    $stranger = User::factory()->doctor()->create();

    $this->actingAs($stranger)
        ->call($method, prescriptionRoute($name, $withPrescription, $this->patient, $this->prescription), prescriptionPayload($this->hydroxyzine))
        ->assertNotFound();

    expect(Prescription::query()->count())->toBe(1)
        ->and($this->prescription->refresh()->closed_at)->toBeNull();
})->with('prescription routes');

test('doctor gets not found after the observation ended', function () {
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.prescriptions.create', $this->patient))
        ->assertNotFound();
});

test('prescription of another patient is not found through this patient', function (string $method, string $name) {
    $other = User::factory()->patientOf($this->doctor)->create();
    $foreign = regularPrescription($other, $this->hydroxyzine->strengths()->sole());

    $this->actingAs($this->doctor)
        ->call($method, route($name, [$this->patient, $foreign]), prescriptionPayload($this->hydroxyzine))
        ->assertNotFound();

    expect($foreign->refresh()->closed_at)->toBeNull();
})->with([
    'edit form' => ['get', 'doctor.patients.prescriptions.edit'],
    'update' => ['put', 'doctor.patients.prescriptions.update'],
    'cancel' => ['post', 'doctor.patients.prescriptions.cancel'],
]);

test('ids that are not numbers are not found', function (string $method, string $url) {
    $this->actingAs($this->doctor)
        ->call($method, str_replace('{patient}', (string) $this->patient->id, $url))
        ->assertNotFound();
})->with([
    ['get', '/doctor/patients/abc/prescriptions/create'],
    ['post', '/doctor/patients/abc/prescriptions'],
    ['get', '/doctor/patients/{patient}/prescriptions/abc/edit'],
    ['put', '/doctor/patients/{patient}/prescriptions/abc'],
    ['post', '/doctor/patients/{patient}/prescriptions/abc/cancel'],
]);

test('new treating doctor sees and changes prescriptions of the previous doctor', function () {
    $next = User::factory()->doctor()->create(['name' => 'Орлов Игорь Петрович']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);
    CareRelationship::factory()->create(['doctor_id' => $next->id, 'patient_id' => $this->patient->id]);
    $this->prescription->update(['prescriber_id' => $this->doctor->id, 'starts_on' => now()->subWeek()->toDateString()]);

    $this->actingAs($next)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.0.id', $this->prescription->id)
            ->where('prescriptions.0.prescriber', $this->doctor->name)
            ->where('prescriptions.0.can_change', true),
        );

    $this->put(route('doctor.patients.prescriptions.update', [$this->patient, $this->prescription]), prescriptionPayload($this->hydroxyzine, [
        'intakes' => [['time_of_day' => 'evening', 'tablets' => 1]],
    ]))->assertSessionHasNoErrors();

    $new = Prescription::query()->where('previous_id', $this->prescription->id)->sole();

    expect($new->prescriber_id)->toBe($next->id)
        ->and($this->prescription->refresh()->closed_by_id)->toBe($next->id);
});
