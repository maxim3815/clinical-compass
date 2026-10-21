<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Models\DoseEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->reverb = startReverb();
});

afterEach(fn () => $this->reverb->stop());

test('doctor sees a new signal without reloading the list and opens the card', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему.
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(12, 0));
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov', 'name' => 'Иванов Пётр Алексеевич']);
    User::factory()->patientOf($doctor)->create(['name' => 'Алексеева Анна Сергеевна']);
    $start = $day->subDays(5);
    $prescription = timedPrescription($patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'prescriber_id' => $doctor->id,
        'starts_on' => $start->toDateString(),
        'effective_at' => $start,
    ]);

    // Пять дней принимал оба приёма, вчера вечером пропустил: 90% и один пропуск, сигнала ещё нет.
    foreach (range(0, 4) as $i) {
        $date = $start->addDays($i);

        foreach (['morning' => 8, 'evening' => 20] as $timeOfDay => $hour) {
            $scheduledAt = $date->setTime($hour, 0)->utc();
            $taken = $i < 4 || $timeOfDay === 'morning';

            DoseEvent::factory()->for($prescription)->create([
                'time_of_day' => $timeOfDay,
                'local_date' => $date->toDateString(),
                'scheduled_at' => $scheduledAt,
                'window_closes_at' => $scheduledAt->addHours(6),
                'status' => $taken ? DoseEventStatus::Taken : DoseEventStatus::Missed,
                'taken_at' => $taken ? $scheduledAt : null,
                'marked_at' => $taken ? $scheduledAt : null,
            ]);
        }
    }

    app(SyncDoseEvents::class)->handle($patient);

    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertSee('Приём за 7 дней')
        ->assertSee('90%')
        ->assertMissing('[data-test="nav-badge"]')
        ->assertMissing('[data-signal]')
        ->assertPresent('[data-test="attention-empty"]');

    // Сегодня утром пациент не принял препарат: второй непринятый приём подряд.
    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->click('[data-test="today"] [data-test="skip-dose"]')
        ->assertSee('Почему не получилось принять?')
        ->click('input[name="reason"][value="forgot"]')
        ->click('[data-test="save-dose-mark"]')
        ->assertSee('Не принят: забыл')
        ->assertNoJavaScriptErrors();

    // Список и счётчик обновились событием, без перезагрузки страницы.
    waitForText($doctorPage, 'Пропуски подряд: 2')
        ->assertSeeIn('[data-test="attention-row"]', 'Иванов Пётр Алексеевич')
        ->assertSeeIn('[data-test="attention-row"]', 'новые пропуски')
        ->assertSeeIn('[data-signal]', 'Иванов Пётр Алексеевич')
        ->assertSeeIn('[data-signal]', '81%')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('Иванов Пётр Алексеевич')
        ->assertPathIs("/doctor/patients/{$patient->id}")
        ->assertSeeIn('[data-test="adherence-signal"]', 'Пропуски подряд: 2')
        ->assertMissing('[data-test="nav-badge"]')
        ->navigate(route('doctor.patients.index'))
        // Подсветка остаётся, пока сигнал есть, а счётчик — только для новых пропусков.
        ->assertSeeIn('[data-signal]', 'Пропуски подряд: 2')
        ->assertMissing('[data-test="nav-badge"]')
        ->assertSeeIn('[data-test="patient-row"]:first-child', 'Алексеева Анна Сергеевна')
        // Сначала самые низкие доли, пациентка без приёмов — в конце.
        ->click('[data-test="sort-adherence"]')
        ->assertQueryStringHas('sort', 'adherence')
        ->assertSeeIn('[data-test="patient-row"]:first-child', 'Иванов Пётр Алексеевич')
        ->assertNoJavaScriptErrors();
});
