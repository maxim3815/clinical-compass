<?php

use App\Enums\DoseSkipReason;
use App\Models\User;
use Carbon\CarbonImmutable;

test('patient on a phone looks through the intake calendar', function () {
    // 15-е число следующего месяца, 12:00 по Москве: время приложения переводится только вперёд,
    // а дни ниже всегда в одном месяце.
    $today = CarbonImmutable::now('Europe/Moscow')->addMonthNoOverflow()->startOfMonth()->addDays(14);
    $this->travelTo($today->setTime(12, 0));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    $previous = $today->subMonthNoOverflow()->startOfMonth();
    $aviandr = adherenceCourse($doctor, $patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), $previous->toDateString());
    $hydroxyzine = ownPrescription($patient, times: [], attributes: ['kind' => 'as_needed', 'drug_name' => 'Гидроксизин', 'starts_on' => $previous->toDateString()]);
    $date = fn (int $day): string => $today->setDay($day)->toDateString();

    adherenceDose($aviandr, $previous->toDateString());
    adherenceDose($aviandr, $date(12));
    adherenceDose($aviandr, $date(12), 'taken', 'evening');
    asNeededDosesOn($hydroxyzine, $date(12), 1);
    adherenceDose($aviandr, $date(13));
    adherenceDose($aviandr, $date(13), 'skipped', 'evening', ['skip_reason' => DoseSkipReason::Unwell]);
    adherenceDose($aviandr, $date(14), 'missed');
    adherenceDose($aviandr, $date(14), 'missed', 'evening');
    adherenceDose($aviandr, $date(15), 'scheduled', 'evening');

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="calendar-link"]')
        ->assertPathIs('/patient/calendar')
        ->assertAttribute('[data-test="tab-today"]', 'aria-current', 'page')
        ->assertSeeIn('[data-test="calendar-title"]', mb_ucfirst($today->isoFormat('MMMM YYYY')))
        ->assertSeeIn('[data-test="calendar-summary"]', 'вы отметили как принятые 3 из 6 приёмов по расписанию')
        ->assertAttribute("[data-date=\"{$date(12)}\"]", 'data-result', 'taken')
        ->assertAttribute("[data-date=\"{$date(13)}\"]", 'data-result', 'partial')
        ->assertAttribute("[data-date=\"{$date(14)}\"]", 'data-result', 'none')
        ->assertAttribute("[data-date=\"{$date(15)}\"]", 'data-result', 'pending')
        // Сегодня открыто сразу, отметить можно на «Сегодня».
        ->assertSeeIn('[data-test="calendar-day-details"]', 'Сегодня,')
        ->assertSeeIn('[data-test="calendar-day-details"]', 'Ещё не отмечен')
        ->assertVisible('[data-test="calendar-mark"]')
        ->assertScript(FITS_SCREEN);

    $page->click("[data-date=\"{$date(13)}\"]")
        ->assertSeeIn('[data-test="calendar-day-details"]', 'Принято не всё')
        ->assertSeeIn('[data-test="calendar-day-details"]', 'Не принят: плохо себя чувствовал или побочные эффекты')
        ->assertSeeIn('[data-test="calendar-unwell"]', 'Если самочувствие беспокоит, напишите врачу.')
        ->assertMissing('[data-test="calendar-mark"]')
        ->click("[data-date=\"{$date(12)}\"]")
        ->assertSeeIn('[data-test="calendar-day-details"]', 'Всё принято')
        ->assertSeeIn('[data-test="calendar-as-needed"]', 'Гидроксизин: 1 раз, 9:00')
        ->assertMissing('[data-test="calendar-unwell"]')
        // По сетке с клавиатуры — стрелками: вправо на день, вверх на неделю.
        ->keys("[data-date=\"{$date(12)}\"]", 'ArrowRight')
        ->assertScript('document.activeElement.dataset.date', $date(13))
        ->keys("[data-date=\"{$date(13)}\"]", 'ArrowUp')
        ->assertScript('document.activeElement.dataset.date', $date(6));

    // Прошлый месяц: день не выбран, следующий месяц — текущий.
    $page->click('[data-test="calendar-previous"]')
        ->assertSeeIn('[data-test="calendar-title"]', mb_ucfirst($previous->isoFormat('MMMM YYYY')))
        ->assertSeeIn('[data-test="calendar-summary"]', 'вы отметили как принятые 1 из 1 приёма по расписанию')
        ->assertMissing('[data-test="calendar-previous"]')
        ->assertMissing('[data-test="calendar-day-details"]')
        ->assertSee('Нажмите на день, чтобы увидеть приёмы.')
        ->assertSeeIn('[data-test="calendar-next"]', mb_ucfirst($today->isoFormat('MMMM')))
        ->click('[data-test="calendar-next"]')
        ->assertSeeIn('[data-test="calendar-title"]', mb_ucfirst($today->isoFormat('MMMM YYYY')))
        ->click('[data-test="calendar-mark"]')
        ->assertPathIs('/patient')
        // Карточка сегодняшнего приёма подсвечена: ссылка передаёт его в ?doses.
        ->assertPresent('[data-test="dose-group"][data-highlighted="true"]')
        ->assertNoJavaScriptErrors();
});

test('patient without doses sees what the calendar is for', function () {
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertMissing('[data-test="calendar-link"]')
        ->navigate(route('patient.calendar'))
        ->assertSee('Календарь заполнится, когда начнутся приёмы по расписанию.')
        ->assertNoJavaScriptErrors();
});
