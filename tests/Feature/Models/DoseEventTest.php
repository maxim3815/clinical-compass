<?php

use App\Models\DoseEvent;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('database keeps one dose event of an intake a day with an open window', function () {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();
    DoseEvent::factory()->for($prescription)->create(['time_of_day' => 'morning', 'local_date' => '2026-10-19']);

    // Ошибка прерывает транзакцию теста, поэтому каждый запрос идёт в точке сохранения.
    expect(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['time_of_day' => 'morning', 'local_date' => '2026-10-19'])))
        ->toThrow(QueryException::class, 'dose_events_prescription_id_time_of_day_local_date_unique')
        ->and(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['local_date' => '2026-10-20', 'window_closes_at' => now()->addHour()])))
        ->toThrow(QueryException::class, 'dose_events_window_check')
        ->and(fn () => DB::transaction(fn () => DoseEvent::factory()->for($prescription)->create(['local_date' => '2026-10-21', 'tablets' => '0.3'])))
        ->toThrow(QueryException::class, 'dose_events_tablets_check');
});

test('database keeps the time of an intake together with the moment it was chosen', function () {
    $prescription = Prescription::factory()->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')->create();

    expect(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time' => '08:00'])))
        ->toThrow(QueryException::class, 'prescription_intakes_time_check')
        ->and(fn () => DB::transaction(fn () => PrescriptionIntake::factory()->for($prescription)->create(['time_set_at' => now()])))
        ->toThrow(QueryException::class, 'prescription_intakes_time_check');
});

test('intake time is read without seconds', function () {
    $intake = PrescriptionIntake::factory()->at('07:05')->create();

    expect($intake->fresh()?->time)->toBe('07:05');
});
