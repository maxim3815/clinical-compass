<?php

use App\Actions\CreatePatient;
use App\Enums\RussianTimezone;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('patient is created with a profile and attached to the doctor', function () {
    $doctor = User::factory()->doctor()->create();
    $consentedAt = now()->startOfSecond();

    $patient = app(CreatePatient::class)->handle(
        doctor: $doctor,
        name: 'Иванов Пётр Алексеевич',
        login: 'patient.new',
        password: 'secret-password',
        birthDate: now()->setDate(1984, 3, 12),
        consentedAt: $consentedAt,
    );

    $patient->refresh();

    expect($patient->role)->toBe(UserRole::Patient)
        ->and($patient->patientProfile?->birth_date?->toDateString())->toBe('1984-03-12')
        ->and($patient->patientProfile?->timezone)->toBe(RussianTimezone::Moscow)
        ->and($patient->patientProfile?->consented_at?->equalTo($consentedAt))->toBeTrue()
        ->and($patient->currentDoctor?->is($doctor))->toBeTrue()
        ->and($doctor->can('view', $patient))->toBeTrue();
});

test('nothing is created when the patient can not be saved', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patient()->create(['login' => 'taken']);

    expect(fn () => app(CreatePatient::class)->handle($doctor, 'Иванов Пётр Алексеевич', 'taken', 'secret-password'))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(User::query()->count())->toBe(2)
        ->and($doctor->patients()->count())->toBe(0);
});
