<?php

use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 12:00 по Москве.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->strength = catalogDrug('Гидроксизин', [25 => true])->strengths()->sole();
});

test('doctor cancels a prescription with a reason and a comment', function () {
    $prescription = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-10']);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), [
            'reason' => 'adverse_events',
            'comment' => 'Сонливость днём.',
        ])
        ->assertRedirect(route('doctor.patients.show', $this->patient))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Назначение отменено.']);

    $prescription->refresh();

    expect($prescription->closed_at?->toDateTimeString())->toBe('2026-10-19 09:00:00')
        ->and($prescription->close_reason)->toBe(PrescriptionCloseReason::Cancelled)
        ->and($prescription->closed_by_id)->toBe($this->doctor->id)
        ->and($prescription->cancel_reason)->toBe(PrescriptionCancelReason::AdverseEvents)
        ->and($prescription->cancel_comment)->toBe('Сонливость днём.');

    $this->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 0)
            ->has('prescriptionHistory', 1)
            ->where('prescriptionHistory.0.status', 'cancelled')
            ->where('prescriptionHistory.0.status_label', 'Отменено')
            ->where('prescriptionHistory.0.closed_at', '19.10.2026 12:00')
            ->where('prescriptionHistory.0.cancel_reason', 'Нежелательные явления')
            ->where('prescriptionHistory.0.cancel_comment', 'Сонливость днём.')
            ->where('cancelReasons', [
                ['value' => 'course_completed', 'label' => 'Курс завершён'],
                ['value' => 'adverse_events', 'label' => 'Нежелательные явления'],
                ['value' => 'insufficient_effect', 'label' => 'Недостаточный эффект'],
                ['value' => 'other', 'label' => 'Другое'],
            ]),
        );
});

test('doctor cancels a prescription without a reason', function () {
    $prescription = regularPrescription($this->patient, $this->strength);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), ['reason' => '', 'comment' => ''])
        ->assertSessionHasNoErrors();

    $prescription->refresh();

    expect($prescription->close_reason)->toBe(PrescriptionCloseReason::Cancelled)
        ->and($prescription->cancel_reason)->toBeNull()
        ->and($prescription->cancel_comment)->toBeNull();
});

test('doctor cancels a prescription that has not started', function () {
    $prescription = Prescription::factory()->for($this->patient, 'patient')->for($this->strength, 'strength')->asNeeded()->create([
        'starts_on' => '2026-11-01',
    ]);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), ['reason' => 'course_completed'])
        ->assertSessionHasNoErrors();

    expect($prescription->refresh()->kind)->toBe(PrescriptionKind::AsNeeded)
        ->and($prescription->close_reason)->toBe(PrescriptionCloseReason::Cancelled)
        ->and($prescription->cancel_reason)->toBe(PrescriptionCancelReason::CourseCompleted);
});

test('reason other needs a comment', function () {
    $prescription = regularPrescription($this->patient, $this->strength);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), ['reason' => 'other', 'comment' => '  '])
        ->assertSessionHasErrors(['comment' => 'Для причины «Другое» напишите комментарий.']);

    expect($prescription->refresh()->closed_at)->toBeNull();

    $this->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), [
        'reason' => 'other',
        'comment' => 'Пациент переходит к другому врачу.',
    ])->assertSessionHasNoErrors();

    expect($prescription->refresh()->cancel_reason)->toBe(PrescriptionCancelReason::Other)
        ->and($prescription->cancel_comment)->toBe('Пациент переходит к другому врачу.');
});

test('invalid cancellation is rejected', function (array $data, string $field, string $message) {
    $prescription = regularPrescription($this->patient, $this->strength);

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.prescriptions.cancel', [$this->patient, $prescription]), $data)
        ->assertSessionHasErrors([$field => $message]);

    expect($prescription->refresh()->closed_at)->toBeNull();
})->with([
    'unknown reason' => [['reason' => 'tired'], 'reason', 'Выберите причину из списка.'],
    'comment too long' => [['reason' => 'other', 'comment' => str_repeat('а', 1001)], 'comment', 'Комментарий — не больше 1000 символов.'],
]);

test('history shows the latest closed prescriptions first', function () {
    $sertraline = catalogDrug('Сертралин', [50 => true])->strengths()->sole();
    $active = regularPrescription($this->patient, $this->strength, ['morning' => '1'], ['starts_on' => '2026-10-01']);
    $changed = regularPrescription($this->patient, $this->strength, ['morning' => '1'], [
        'starts_on' => '2026-09-01',
        'closed_at' => '2026-10-01 07:00:00',
        'close_reason' => PrescriptionCloseReason::Changed,
    ]);
    $cancelled = regularPrescription($this->patient, $sertraline, ['morning' => '1'], [
        'starts_on' => '2026-10-01',
        'closed_at' => '2026-10-15 07:00:00',
        'close_reason' => PrescriptionCloseReason::Cancelled,
    ]);
    $completed = regularPrescription($this->patient, $sertraline, ['morning' => '1'], [
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-30',
    ]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)
            ->where('prescriptions.0.id', $active->id)
            ->has('prescriptionHistory', 3)
            ->where('prescriptionHistory.0.id', $cancelled->id)
            ->where('prescriptionHistory.1.id', $changed->id)
            ->where('prescriptionHistory.2.id', $completed->id)
            ->where('prescriptionHistory.2.status', 'completed')
            ->where('prescriptionHistory.2.ends_on', '30.09.2026'),
        );
});
