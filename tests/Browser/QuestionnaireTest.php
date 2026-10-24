<?php

use App\Actions\SubmitAssessments;
use App\Models\Assessment;
use App\Models\CareRelationship;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;

beforeEach(function () {
    loadScales();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['login' => 'morozova', 'name' => 'Морозова Ольга Павловна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);
});

test('patient answers both questionnaires on a phone and sees the wellbeing chart', function () {
    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSeeIn('[data-test="questionnaire-card"]', 'Пора ответить')
        ->click('[data-test="answer-questions"]')
        ->assertSee('Часть 1 из 2 · 7 вопросов')
        ->assertSee('Беспокойство')
        ->assertSee('Оцените, пожалуйста')
        ->assertScript(FITS_SCREEN);

    // Без ответа на вопрос дальше не пройти: вопрос подсвечен, фокус на нём.
    foreach (range(0, 5) as $item) {
        $page->click("input[name=\"gad7-{$item}\"][value=\"2\"]");
    }

    $page->assertSee('Осталось ответить на 1 вопрос.')
        ->press('Дальше')
        ->assertSee('Ответьте на этот вопрос.')
        ->assertSee('Нет ответа на вопрос 7.')
        ->assertScript('document.activeElement.name', 'gad7-6')
        ->click('input[name="gad7-6"][value="3"]')
        ->press('Дальше')
        ->assertSee('Часть 2 из 2')
        ->assertSee('Настроение');

    // «Назад» браузера со второй части возвращает к первой, ответы на месте.
    $page->back()
        ->assertSee('Часть 1 из 2')
        ->assertRadioSelected('gad7-0', 2)
        ->assertRadioSelected('gad7-6', 3)
        ->press('Дальше')
        ->assertSee('Настроение');

    // Уход с начатых ответов переспрашивает.
    $page->click('Сегодня')
        ->assertSee('Уйти без сохранения?')
        ->click('Остаться')
        ->assertSee('Настроение');

    foreach (range(0, 8) as $item) {
        $page->click("input[name=\"phq9-{$item}\"][value=\"".($item === 8 ? 0 : 1).'"]');
    }

    $page->press('Отправить врачу')
        ->assertSee('Ответы отправлены врачу')
        ->assertSee('Смирнова Анна Сергеевна увидит их в вашей карточке.')
        // «Назад» после отправки не открывает заполненную вторую часть для повторной отправки.
        ->back()
        ->assertSee('Часть 1 из 2')
        ->assertDontSee('Отправить врачу')
        ->forward()
        ->assertSee('Ответы отправлены врачу')
        ->click('Посмотреть прошлые ответы')
        ->assertPathIs('/patient/wellbeing')
        ->assertSee('Чем ниже число, тем лучше.')
        ->assertSeeIn('#scale-gad7-title', 'Беспокойство')
        ->assertSeeIn('[data-test="wellbeing-gad7"] [data-test="score-chart-last"]', '15')
        ->assertSeeIn('[data-test="wellbeing-phq9"] [data-test="score-chart-last"]', '8')
        ->click('[data-test="wellbeing-gad7"] [data-test="score-chart-hit"]')
        ->assertSeeIn('[data-test="wellbeing-gad7"] [data-test="score-chart-tooltip"]', '15 из 21')
        ->assertDontSee('тревога')
        ->assertScript(FITS_SCREEN)
        ->click('[data-test="tab-today"]')
        ->assertMissing('[data-test="questionnaire-card"]')
        ->assertSeeIn('[data-test="wellbeing-link"]', 'Следующие вопросы появятся')
        ->assertNoJavaScriptErrors();

    expect(Assessment::query()->orderBy('id')->pluck('total')->all())->toBe([15, 8]);
});

test('doctor sees the sums, the band and the answers of each filling', function () {
    $this->travelTo(CarbonImmutable::now(DisplayTime::TIMEZONE)->subDays(7));
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 3, phq9: 3, phq9Item9: 0));
    $this->travelBack();
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 2, phq9: 2, phq9Item9: 1));

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate(route('doctor.patients.show', $this->patient))
        ->assertSeeIn('[data-test="card-wellbeing"]', 'GAD-7 14, умеренная тревога · PHQ-9 17, умеренно тяжёлая депрессия')
        ->assertSeeIn('[data-test="wellbeing-gad7"]', 'умеренная тревога')
        ->assertSeeIn('[data-test="wellbeing-gad7"] [data-test="wellbeing-change"]', '−7')
        ->assertSeeIn('[data-test="wellbeing-phq9"]', 'умеренно тяжёлая депрессия')
        ->assertSeeIn('[data-test="wellbeing-highlight"]', 'PHQ-9, вопрос 9: в течение нескольких дней (1)')
        ->click('li:first-child > [data-test="wellbeing-filling"] > summary')
        ->assertSee('Мысли о том, что мне бы хотелось умереть или причинить себе боль')
        ->assertNoJavaScriptErrors();
});
