<?php

use App\Enums\PrescriptionCloseReason;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\PrescriptionStatus;
use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('status is counted on the patient date', function (array $attributes, PrescriptionStatus $status) {
    $prescription = Prescription::factory()->make($attributes);

    expect($prescription->status(CarbonImmutable::parse('2026-10-19', 'UTC')))->toBe($status);
})->with([
    'starts today' => [['starts_on' => '2026-10-19'], PrescriptionStatus::Active],
    'started earlier without end' => [['starts_on' => '2026-10-01'], PrescriptionStatus::Active],
    'ends today' => [['starts_on' => '2026-10-01', 'ends_on' => '2026-10-19'], PrescriptionStatus::Active],
    'starts tomorrow' => [['starts_on' => '2026-10-20'], PrescriptionStatus::Upcoming],
    'ended yesterday' => [['starts_on' => '2026-10-01', 'ends_on' => '2026-10-18'], PrescriptionStatus::Completed],
    'changed' => [['starts_on' => '2026-10-01', 'closed_at' => '2026-10-10 09:00', 'close_reason' => PrescriptionCloseReason::Changed], PrescriptionStatus::Changed],
    'cancelled before the start' => [['starts_on' => '2026-11-01', 'closed_at' => '2026-10-10 09:00', 'close_reason' => PrescriptionCloseReason::Cancelled], PrescriptionStatus::Cancelled],
]);

test('only active and upcoming prescriptions of the doctor can be changed by the doctor', function () {
    $today = CarbonImmutable::parse('2026-10-19', 'UTC');

    expect(Prescription::factory()->make(['starts_on' => '2026-10-19'])->isChangeableByDoctor($today))->toBeTrue()
        ->and(Prescription::factory()->make(['starts_on' => '2026-10-25'])->isChangeableByDoctor($today))->toBeTrue()
        ->and(Prescription::factory()->make(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-18'])->isChangeableByDoctor($today))->toBeFalse()
        ->and(Prescription::factory()->make(['source' => PrescriptionSource::Patient])->isChangeableByDoctor($today))->toBeFalse();
});

test('factory keeps the drug of the strength', function () {
    $strength = catalogDrug('Гидроксизин')->strengths()->sole();
    $prescription = Prescription::factory()->for($strength, 'strength')->create();

    expect($prescription->drug_id)->toBe($strength->drug_id);
});

test('database rejects two open prescriptions of the doctor with overlapping dates', function () {
    $patient = User::factory()->patient()->create();
    $strength = catalogDrug('Гидроксизин')->strengths()->sole();
    Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create([
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-31',
    ]);

    // Ошибка прерывает транзакцию теста, поэтому запрос идёт в точке сохранения.
    expect(fn () => DB::transaction(fn () => Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create([
        'starts_on' => '2026-10-31',
    ])))->toThrow(QueryException::class, 'prescriptions_doctor_overlap_excl');

    // Без пересечения, другого вида, закрытое и добавленное пациентом не мешают.
    Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create(['starts_on' => '2026-11-01']);
    Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->asNeeded()->create(['starts_on' => '2026-10-10']);
    Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->cancelled()->create(['starts_on' => '2026-10-10']);
    Prescription::factory()->for($patient, 'patient')->for($strength->drug, 'drug')->addedByPatient()->create(['starts_on' => '2026-10-10']);

    expect(Prescription::query()->count())->toBe(5);
});

test('database keeps prescription fields consistent', function (array $attributes, string $constraint) {
    $strength = catalogDrug('Гидроксизин')->strengths()->sole();

    expect(fn () => Prescription::factory()->for($strength, 'strength')->create($attributes))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'end before start' => [['starts_on' => '2026-10-19', 'ends_on' => '2026-10-18'], 'prescriptions_period_check'],
    'regular with an as needed dose' => [['tablets' => '1'], 'prescriptions_regular_dose_check'],
    'as needed without a daily limit' => [['kind' => PrescriptionKind::AsNeeded, 'tablets' => '1'], 'prescriptions_doctor_as_needed_check'],
    'third of a tablet' => [['kind' => PrescriptionKind::AsNeeded, 'tablets' => '0.3', 'max_daily_doses' => 2], 'prescriptions_tablets_check'],
    'closed without a reason' => [['closed_at' => '2026-10-19 09:00'], 'prescriptions_closed_check'],
    'cancel reason of a changed version' => [
        ['closed_at' => '2026-10-19 09:00', 'close_reason' => PrescriptionCloseReason::Changed, 'cancel_comment' => 'Ошибка'],
        'prescriptions_cancel_reason_check',
    ],
]);

test('database keeps the fields of the doctor and of the patient apart', function (Closure $create) {
    expect(fn () => DB::transaction($create))->toThrow(QueryException::class, 'prescriptions_source_check');
})->with([
    'doctor without a strength' => fn () => Prescription::factory()->create(['drug_strength_id' => null]),
    'doctor with a dose as text' => fn () => Prescription::factory()->create(['dose' => '1 таблетка']),
    'doctor with a prescriber named by the patient' => fn () => Prescription::factory()->create(['prescribed_by' => 'невролог']),
    'patient with a strength' => fn () => Prescription::factory()->addedByPatient()->create(['drug_strength_id' => DrugStrength::factory()]),
    'patient without a drug and a name' => fn () => Prescription::factory()->addedByPatient()->create(['drug_name' => null]),
    'patient with a drug and a name' => fn () => Prescription::factory()->for(Drug::factory())->addedByPatient()->create(['drug_name' => 'Фенибут']),
    'patient without a dose' => fn () => Prescription::factory()->addedByPatient()->create(['dose' => null]),
    'patient with tablets' => fn () => Prescription::factory()->addedByPatient()->asNeeded()->create(['tablets' => '1']),
    'patient with a doctor' => fn () => Prescription::factory()->addedByPatient()->create(['prescriber_id' => User::factory()->doctor()]),
]);

test('database rejects the same time of day twice and parts of a tablet other than a half', function () {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();
    PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => 'morning']);

    // Ошибка прерывает транзакцию теста, поэтому каждый запрос идёт в точке сохранения.
    expect(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => 'morning'])))
        ->toThrow(QueryException::class, 'prescription_intakes_prescription_id_time_of_day_unique')
        ->and(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => 'evening', 'tablets' => '1.3'])))
        ->toThrow(QueryException::class, 'prescription_intakes_tablets_check');
});
