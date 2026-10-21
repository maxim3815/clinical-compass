<?php

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionSource;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;

test('patient on a phone adds another drug and marks it on the today screen', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему, а браузер
    // живёт по настоящим часам и не примет cookie, которая уже истекла.
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(7, 0));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    catalogDrug('Сертралин', [50 => true]);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->click('[data-test="tab-medications"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Добавленные мной')
        ->click('[data-test="add-medication-button"]')
        ->assertPathIs('/patient/medications/create')
        ->select('#drug_id', 'other')
        ->fill('#drug_name', 'Фенибут')
        ->fill('#dose', '1 таблетка 250 мг')
        ->assertValue('#intake_time_0', '08:00')
        ->fill('#intake_time_0', '07:30')
        ->fill('#prescribed_by', 'невролог Петров')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[data-test="save-medication"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Препарат добавлен.')
        ->assertSee('Фенибут')
        ->assertSee('Доза: 1 таблетка 250 мг')
        ->assertSee('Утром, 7:30')
        ->assertSee('кто назначил: невролог Петров')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="tab-today"]')
        ->assertPathIs('/patient')
        ->assertSee('Фенибут')
        ->assertSee('1 таблетка 250 мг')
        ->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 7:00')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    $prescription = Prescription::query()->sole();

    expect($prescription->source)->toBe(PrescriptionSource::Patient)
        ->and($prescription->drug_name)->toBe('Фенибут')
        ->and(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Taken);
});
