<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;

test('patient on a phone marks doses during the day', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему, а браузер
    // живёт по настоящим часам и не примет cookie, которая уже истекла.
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(8, 30));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    timedPrescription($patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'prescriber_id' => $doctor->id,
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    Prescription::factory()
        ->for($patient, 'patient')
        ->for(catalogDrug('Гидроксизин', [25 => true])->strengths()->sole(), 'strength')
        ->asNeeded('1', 1)
        ->create(['prescriber_id' => $doctor->id, 'starts_on' => '2026-10-12']);
    app(SyncDoseEvents::class)->handle($patient);

    // Утром отмечен утренний приём, вечерний отметить ещё нельзя.
    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->assertSee('Сегодня, '.$day->isoFormat('D MMMM'))
        ->assertSee('Отметить приём можно с 19:00.')
        ->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 8:30')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    // Вечером: вечерний приём не принят, приём «по необходимости» сверх лимита с подтверждением.
    $this->travelTo($day->setTime(19, 30));

    $page->navigate(route('patient.home'))
        ->click('[data-test="skip-dose"]')
        ->assertSee('Отметка приёма')
        ->select('#skip_reason', 'unwell')
        ->fill('#skip_comment', 'Тошнило')
        ->click('[data-test="save-dose-mark"]')
        ->assertSee('Не принят: плохо себя чувствовал или побочные эффекты')
        ->assertSee('Тошнило')
        ->click('[data-test="take-as-needed"]')
        ->assertSee('Приём записан.')
        ->assertSee('Сегодня принято 1 из 1')
        ->click('[data-test="take-as-needed"]')
        ->assertSee('Записать ещё один приём?')
        ->assertSee('Сегодня вы уже приняли препарат 1 раз, а врач разрешил не больше 1 раза в сутки.')
        ->click('[data-test="confirm-as-needed"]')
        ->assertSee('Сегодня принято 2 из 1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();

    $evening = doseOn($patient, $day->toDateString(), 'evening');

    expect(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Taken)
        ->and($evening->status)->toBe(DoseEventStatus::Skipped)
        ->and($evening->skip_reason)->toBe(DoseSkipReason::Unwell)
        ->and($evening->skip_comment)->toBe('Тошнило')
        ->and($patient->asNeededDoses()->count())->toBe(2);
});
