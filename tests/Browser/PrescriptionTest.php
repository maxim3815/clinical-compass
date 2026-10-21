<?php

use App\Models\Drug;
use App\Models\User;

test('doctor prescribes aviandr twice a day and the patient sees it on a phone', function () {
    $this->artisan('app:drugs:import')->assertSuccessful();
    $doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov', 'name' => 'Иванов Пётр Алексеевич']);

    $page = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Иванов Пётр Алексеевич')
        ->assertSee('Назначений пока нет.')
        ->click('[data-test="prescribe-button"]')
        ->assertPathIs("/doctor/patients/{$patient->id}/prescriptions/create")
        ->click('[data-drug="maritupirdine"]')
        ->assertSee('По инструкции: 20 мг 2 раза в сутки')
        ->assertSee('20 мг, делить нельзя')
        ->assertValue('#instruction', Drug::query()->where('code', 'maritupirdine')->value('patient_instruction'))
        // Время суток не отмечено заранее: врач выбирает его сам.
        ->assertSeeIn('[data-test="prescription-summary"]', 'время приёма не выбрано')
        ->check('[data-test="intake-morning"]')
        ->check('[data-test="intake-evening"]')
        ->assertValue('[name="intakes.1.tablets"]', '1')
        ->assertSeeIn('[data-test="prescription-summary"]', 'Авиандр 20 мг · утром 1 таблетка, вечером 1 таблетка · 40 мг в сутки')
        ->press('Назначить')
        ->assertPathIs("/doctor/patients/{$patient->id}")
        ->assertSee('Препарат назначен.')
        ->assertSee('Авиандр, 20 мг')
        ->assertSee('Утром: 1 таблетка, 20 мг')
        ->assertSee('Вечером: 1 таблетка, 20 мг')
        ->assertSee('пациент ещё не выбрал время')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="sidebar-menu-button"]')
        ->click('Выйти')
        ->click('[data-test="confirm-logout"]')
        ->assertPathIs('/login');

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->click('[data-test="tab-medications"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Авиандр, 20 мг')
        ->assertSee('Утром: 1 таблетка, 20 мг')
        ->assertSee('Вечером: 1 таблетка, 20 мг')
        ->assertSee('За 30 минут до еды.')
        ->assertSee('назначил врач Смирнова Анна Сергеевна')
        ->assertDontSee('пациент ещё не выбрал время')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

test('doctor changes a prescription and cancels it with a reason', function () {
    $this->artisan('app:drugs:import')->assertSuccessful();
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    $hydroxyzine = Drug::query()->where('code', 'hydroxyzine')->sole();
    regularPrescription($patient, $hydroxyzine->strengths()->sole(), ['evening' => '1'], [
        'prescriber_id' => $doctor->id,
        'starts_on' => now()->subWeek()->toDateString(),
    ]);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('Иванов Пётр Алексеевич')
        ->assertSee('Вечером: 1 таблетка, 25 мг')
        ->click('Изменить')
        ->assertSee('Изменения вступят в силу сразу')
        ->select('[name="intakes.0.tablets"]', '1.5')
        ->press('Сохранить изменения')
        ->assertPathIs("/doctor/patients/{$patient->id}")
        ->assertSee('Назначение изменено.')
        ->assertSee('Вечером: 1½ таблетки, 37,5 мг')
        ->assertSee('История назначений')
        ->assertSee('Изменено')
        ->click('[data-test="cancel-prescription-button"]')
        ->assertSee('Отменить назначение?')
        ->click('[data-test="confirm-cancel-prescription"]')
        ->assertSee('Выберите причину или «Не указывать».')
        ->click('input[name="reason"][value="adverse_events"]')
        ->fill('comment', 'Сонливость днём.')
        ->click('[data-test="confirm-cancel-prescription"]')
        ->assertSee('Назначение отменено.')
        ->assertSee('Назначений пока нет.')
        ->assertSee('Нежелательные явления')
        ->assertSee('Сонливость днём.')
        ->assertNoJavaScriptErrors();
});
