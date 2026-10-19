<?php

use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Тесты с несколькими процессами: данные должны быть закоммичены, поэтому вместо
// транзакции RefreshDatabase таблицы очищаются до и после каждого теста.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->afterEach(fn () => $this->truncateTablesForAllConnections())
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Препарат справочника с дозировками: миллиграммы => можно ли делить таблетку.
 *
 * @param  array<int|string, bool>  $strengths
 * @param  array<string, mixed>  $attributes
 */
function catalogDrug(string $name, array $strengths = [25 => true], array $attributes = []): Drug
{
    $drug = Drug::factory()->create(['name' => $name, ...$attributes]);

    foreach ($strengths as $mg => $divisible) {
        DrugStrength::factory()->for($drug)->create(['mg' => $mg, 'divisible' => $divisible]);
    }

    return $drug;
}

/**
 * Данные формы назначения: самая малая дозировка препарата, регулярно, утром одна таблетка,
 * с сегодняшнего дня по Москве.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prescriptionPayload(Drug $drug, array $overrides = []): array
{
    return [
        'drug_id' => $drug->id,
        'drug_strength_id' => $drug->strengths()->orderBy('mg')->value('id'),
        'kind' => 'regular',
        'intakes' => [['time_of_day' => 'morning', 'tablets' => 1]],
        'tablets' => null,
        'max_daily_doses' => null,
        'instruction' => $drug->patient_instruction,
        'starts_on' => DisplayTime::today()->toDateString(),
        'ends_on' => null,
        ...$overrides,
    ];
}

/**
 * Регулярное назначение врача с приёмами: время суток => таблеток.
 *
 * @param  array<string, string>  $intakes
 * @param  array<string, mixed>  $attributes
 */
function regularPrescription(User $patient, DrugStrength $strength, array $intakes = ['morning' => '1'], array $attributes = []): Prescription
{
    $prescription = Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create($attributes);

    foreach ($intakes as $timeOfDay => $tablets) {
        PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => $timeOfDay, 'tablets' => $tablets]);
    }

    return $prescription;
}

/**
 * Регулярное назначение врача, у которого пациент уже выбрал время: время суток => «08:00»,
 * по таблетке на приём. Время выбрано в момент, с которого действует назначение (effective_at).
 *
 * @param  array<string, string>  $times
 * @param  array<string, mixed>  $attributes
 */
function timedPrescription(User $patient, DrugStrength $strength, array $times = ['morning' => '08:00'], array $attributes = []): Prescription
{
    $prescription = Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create($attributes);

    foreach ($times as $timeOfDay => $time) {
        PrescriptionIntake::factory()->for($prescription)->at($time, $prescription->effective_at)->create(['time_of_day' => $timeOfDay]);
    }

    return $prescription;
}

/**
 * Плановые приёмы пациента по порядку: местная дата, время суток и scheduled_at в UTC — «2026-10-19 morning 2026-10-19 05:00».
 *
 * @return list<string>
 */
function doseSchedule(User $patient): array
{
    return $patient->doseEvents()
        ->orderBy('scheduled_at')
        ->get()
        ->map(fn (DoseEvent $event): string => $event->local_date->toDateString().' '.$event->time_of_day->value.' '.$event->scheduled_at->utc()->format('Y-m-d H:i'))
        ->values()
        ->all();
}
