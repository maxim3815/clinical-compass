<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Enums\Sex;
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
        ->assertSeeIn('[data-test="today-progress"]', 'Отмечено 0 из 2')
        // Лиц настроения до отметки нет: они появляются после неё, по желанию.
        ->assertMissing('[data-test="mood-picker"]')
        ->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 8:30')
        ->assertSeeIn('[data-test="today-progress"]', 'Отмечено 1 из 2')
        ->assertSee('Следующий приём препаратов в 20:00')
        ->assertSeeIn('[data-test="mood-picker"]', 'Как настроение?')
        ->assertSeeIn('[data-test="mood-picker"]', 'Ответ увидит ваш врач. Отвечать не обязательно.')
        ->assertSeeIn('[data-test="mood-sad"]', 'Плохое')
        ->click('[data-test="mood-sad"]')
        ->assertAttribute('[data-test="mood-sad"]', 'aria-pressed', 'true')
        // Двойное касание не снимает только что выбранное лицо…
        ->click('[data-test="mood-sad"]')
        ->assertAttribute('[data-test="mood-sad"]', 'aria-pressed', 'true')
        // …а повторное касание позже снимает: нажатое по ошибке не уходит врачу.
        ->wait(1)
        ->click('[data-test="mood-sad"]')
        ->assertAttribute('[data-test="mood-sad"]', 'aria-pressed', 'false')
        ->click('[data-test="mood-happy"]')
        ->assertAttribute('[data-test="mood-happy"]', 'aria-pressed', 'true')
        // Передумал — выбор меняется до конца дня.
        ->click('[data-test="mood-neutral"]')
        ->assertAttribute('[data-test="mood-neutral"]', 'aria-pressed', 'true')
        ->assertAttribute('[data-test="mood-happy"]', 'aria-pressed', 'false')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    expect($patient->moodMarks()->sole()->mood->value)->toBe('neutral');

    // Вечером: вечерний приём не принят, приём «по необходимости» сверх лимита с подтверждением.
    $this->travelTo($day->setTime(19, 30));

    $page->navigate(route('patient.home'))
        ->click('[data-test="skip-dose"]')
        ->assertSee('Почему не получилось принять?')
        // Причины — крупные варианты на экране, а не выпадающий список.
        ->click('input[name="reason"][value="unwell"]')
        ->fill('#skip_comment', 'Тошнило')
        ->click('[data-test="save-dose-mark"]')
        ->assertSee('Не принят: плохо себя чувствовал или были побочные эффекты')
        ->assertSee('Тошнило')
        ->click('[data-test="take-as-needed"]')
        ->assertSee('Приём отмечен.')
        ->assertSee('Сегодня принято 1 раз, можно не больше 1 раза в сутки')
        ->click('[data-test="take-as-needed"]')
        ->assertSee('Отметить ещё один приём?')
        ->assertSee('Сегодня вы уже приняли препарат 1 раз, а врач разрешил не больше 1 раза в сутки.')
        ->click('[data-test="confirm-as-needed"]')
        ->assertSee('Сегодня принято 2 раза, можно не больше 1 раза в сутки')
        // Лишнюю отметку убирают после вопроса.
        ->click('[data-test="as-needed-dose"]:first-child [data-test="delete-as-needed"]')
        ->assertSee('Убрать отметку?')
        ->click('[data-test="confirm-delete-as-needed"]')
        ->assertSee('Отметка убрана.')
        ->assertSee('Сегодня принято 1 раз, можно не больше 1 раза в сутки')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    $evening = doseOn($patient, $day->toDateString(), 'evening');

    expect(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Taken)
        ->and($evening->status)->toBe(DoseEventStatus::Skipped)
        ->and($evening->skip_reason)->toBe(DoseSkipReason::Unwell)
        ->and($evening->skip_comment)->toBe('Тошнило')
        ->and($patient->asNeededDoses()->count())->toBe(1);
});

test('patient takes doses at the same time at once and marks a missed dose with its time', function () {
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(8, 30));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    $since = ['starts_on' => $day->subWeek()->toDateString(), 'effective_at' => now()->subWeek()];
    $aviandr = timedPrescription($patient, catalogDrug('Авиандр', [20 => false], ['missed_dose' => 'Пропущенную таблетку не принимайте.'])->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'prescriber_id' => $doctor->id,
        ...$since,
    ]);
    ownPrescription($patient, null, ['morning' => '08:00'], $since);
    // Пациентка: кнопки в женском роде.
    $patient->patientProfile?->update(['sex' => Sex::Female]);
    app(SyncDoseEvents::class)->handle($patient);
    $yesterday = adherenceDose($aviandr, $day->subDay()->toDateString(), 'missed', 'evening');

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        // Два приёма на 8:00 отмечаются одной кнопкой, кнопки каждого — по просьбе.
        ->assertSeeIn('[data-test="today-progress"]', 'Отмечено 0 из 3')
        ->assertSeeIn('[data-test="take-all-doses"]', 'Приняла всё')
        ->assertSeeIn('[data-test="mark-separately"]', 'Не приняла или приняла не всё')
        ->assertMissing('[data-test="take-dose"]')
        ->click('[data-test="mark-separately"]')
        ->assertScript('document.querySelectorAll(\'[data-test="take-dose"]\').length === 2')
        ->assertMissing('[data-test="take-all-doses"]')
        ->navigate(route('patient.home'))
        ->click('[data-test="take-all-doses"]')
        ->assertSee('Приёмы отмечены.')
        ->assertSeeIn('[data-test="today-progress"]', 'Отмечено 2 из 3')
        // Вчерашний приём без отметки: «Принял» спрашивает время, а не записывает «сейчас».
        ->assertSeeIn('[data-test="yesterday"]', 'Нет отметки')
        ->assertSeeIn('[data-test="yesterday"]', 'Пропущенную таблетку не принимайте.')
        ->click('[data-test="yesterday"] [data-test="take-dose-at"]')
        ->assertValue('#dose_time', '20:00')
        ->fill('#dose_time', '20:15')
        ->click('[data-test="save-dose-mark"]')
        ->assertSeeIn('[data-test="yesterday"]', 'Принят в 20:15')
        // Отметку убирают из того же окна, где её меняют.
        ->click('[data-test="yesterday"] [data-test="edit-dose-mark"]')
        ->assertSee('Изменить отметку')
        ->click('[data-test="cancel-dose-mark"]')
        // Переспрашивает, как удаление записи «по необходимости».
        ->assertSee('Убрать отметку о приёме?')
        ->click('[data-test="confirm-cancel-dose-mark"]')
        ->assertSee('Отметка убрана.')
        ->assertSeeIn('[data-test="yesterday"]', 'Нет отметки')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    expect(doseOn($patient, $day->toDateString(), 'morning', $aviandr)->status)->toBe(DoseEventStatus::Taken)
        ->and($yesterday->refresh()->status)->toBe(DoseEventStatus::Missed)
        ->and($yesterday->taken_at)->toBeNull();
});
