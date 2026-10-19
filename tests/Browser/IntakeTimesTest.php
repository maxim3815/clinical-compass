<?php

use App\Models\DoseEvent;
use App\Models\User;
use App\Support\DisplayTime;

test('patient on a phone chooses the time of intakes from the home page', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    $aviandr = catalogDrug('Авиандр', [20 => false])->strengths()->sole();
    regularPrescription($patient, $aviandr, ['morning' => '1', 'evening' => '1'], ['prescriber_id' => $doctor->id]);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Настройте время приёма')
        ->assertSee('Авиандр, 20 мг: выберите, во сколько вы будете принимать препарат.')
        ->click('Выбрать время')
        ->assertPathIs('/patient/medications/'.$patient->prescriptions()->value('id').'/times')
        ->assertSee('Утром: 1 таблетка, 20 мг')
        ->assertValue('#time-morning', '08:00')
        ->assertValue('#time-evening', '20:00')
        ->assertSee('Москва (UTC+3)')
        ->fill('#time-evening', '21:30')
        ->click('[data-test="save-intake-times"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Время приёма сохранено.')
        ->assertSee('Утром, 8:00:')
        ->assertSee('Вечером, 21:30:')
        ->assertSee('Изменить время')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    $page->click('[data-sidebar="trigger"]')
        ->click('Сегодня')
        ->assertPathIs('/patient')
        ->assertDontSee('Настройте время приёма')
        ->assertNoJavaScriptErrors();

    // На завтра запланированы оба приёма.
    expect(DoseEvent::query()->where('local_date', DisplayTime::today()->addDay()->toDateString())->count())->toBe(2);
});
