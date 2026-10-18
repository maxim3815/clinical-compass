<?php

use App\Enums\RussianTimezone;
use App\Models\PatientProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

test('age counts full years by the Moscow date', function (string $nowUtc, int $age) {
    $this->travelTo(CarbonImmutable::parse($nowUtc, 'UTC'));

    $profile = new PatientProfile(['birth_date' => '1984-10-18']);

    expect($profile->age())->toBe($age);
})->with([
    'the day before the birthday' => ['2026-10-17 20:30', 41],
    'birthday has started in Moscow' => ['2026-10-17 21:30', 42],
    'after the birthday' => ['2027-03-01 12:00', 42],
]);

test('age is unknown without a birth date', function () {
    expect((new PatientProfile)->age())->toBeNull();
});

test('timezone is Moscow by default', function () {
    $patient = User::factory()->patient()->create();

    expect($patient->patientProfile?->timezone)->toBe(RussianTimezone::Moscow)
        ->and($patient->patientProfile?->refresh()->timezone)->toBe(RussianTimezone::Moscow);
});
