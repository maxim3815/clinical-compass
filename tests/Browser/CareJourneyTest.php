<?php

use App\Models\User;
use App\Notifications\DoseReminder;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->reverb = startReverb();
});

afterEach(fn () => $this->reverb->stop());

/**
 * Сквозной сценарий этапа на чистой базе: путь нового пациента от приглашения через опросники
 * и алерт по ответам до сигнала у врача.
 */
test('a new patient goes from the invitation to an adherence signal at the doctor', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему.
    $day = CarbonImmutable::now(DisplayTime::TIMEZONE)->addDay()->startOfDay();
    $this->travelTo($day->setTime(7, 0));
    Notification::fake();
    $this->artisan('app:drugs:import')->assertSuccessful();
    loadAlertRules();
    User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);

    // Врач приглашает пациента, пациент регистрируется по ссылке на телефоне.
    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('Приглашения')
        ->fill('patient_name', 'Новиков Артём Сергеевич')
        ->press('Пригласить по ссылке')
        ->assertSee('Отправьте ссылку пациенту')
        ->assertNoJavaScriptErrors();

    $patientPage = visit($doctorPage->value('[data-test="invitation-link"] input'))
        ->on()->mobile()
        ->assertSee('Регистрация пациента')
        ->fill('login', 'novikov')
        ->fill('password', 'secret-password')
        ->fill('password_confirmation', 'secret-password')
        ->click('#consent')
        ->press('Зарегистрироваться')
        ->assertPathIs('/patient/rules')
        ->click('[data-test="rules-acknowledge"]')
        ->assertPathIs('/patient')
        ->assertSee('Врач пока не назначил препараты.')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'novikov')->sole();

    // Сразу после регистрации пациент отвечает на опросники: сначала GAD-7, потом PHQ-9.
    $patientPage->click('[data-test="answer-questions"]')
        ->assertSee('Беспокойство');

    foreach (range(0, 6) as $item) {
        $patientPage->click("input[name=\"gad7-{$item}\"][value=\"1\"]");
    }

    $patientPage->press('Дальше')->assertSee('Настроение');

    // На 9-й вопрос PHQ-9 — «в течение нескольких дней»: у пациента экран помощи, у врача алерт.
    foreach (range(0, 8) as $item) {
        $patientPage->click("input[name=\"phq9-{$item}\"][value=\"1\"]");
    }

    $patientPage->press('Отправить врачу')
        ->assertSee('Ответы отправлены врачу')
        ->assertSeeIn('[data-test="help-card"]', 'Позвонить 112')
        ->click('[data-test="questionnaire-done"]')
        ->assertPathIs('/patient')
        ->assertPresent('[data-test="help-card"]')
        ->assertMissing('[data-test="questionnaire-card"]')
        ->assertPresent('[data-test="wellbeing-link"]')
        ->assertNoJavaScriptErrors();

    // Врач видит красную плашку, принимает алерт, назначает «Авиандр» утром и вечером,
    // пациент выбирает время приёма.
    $doctorPage->navigate(route('doctor.patients.index'))
        ->assertSeeIn('[data-test="urgent-alert"]', 'Срочно: Новиков Артём Сергеевич')
        ->click('[data-test="urgent-alert-accept"]')
        ->assertSee('Алерт принят.')
        ->assertMissing('[data-test="alert-banner"]')
        ->click('Новиков Артём Сергеевич')
        ->click('[data-test="prescribe-button"]')
        ->click('[data-drug="maritupirdine"]')
        ->check('[data-test="intake-morning"]')
        ->check('[data-test="intake-evening"]')
        ->press('Назначить')
        ->assertSee('Препарат назначен.')
        ->assertSee('пациент ещё не выбрал время')
        ->assertNoJavaScriptErrors();

    $patientPage->navigate(route('patient.home'))
        ->click('Выбрать время')
        ->assertValue('#time-morning', '08:00')
        ->assertValue('#time-evening', '20:00')
        ->click('[data-test="save-intake-times"]')
        ->assertSee('Время приёма сохранено.')
        ->assertNoJavaScriptErrors();

    // В 8:00 приходит напоминание. Пациент открывает его и отмечает приём.
    subscribeToPush($patient);
    $this->travelTo($day->setTime(8, 0));
    $this->artisan('app:dose-events:send-reminders')->assertSuccessful();

    $push = [];
    Notification::assertSentTo($patient, DoseReminder::class, function (DoseReminder $reminder) use ($patient, &$push): bool {
        $push = $reminder->toWebPush($patient)->toArray();

        return true;
    });

    expect($push['title'])->toBe('Приём в 8:00')
        ->and($push['body'])->toContain('Авиандр');

    $patientPage->navigate(url($push['data']['url']))
        ->assertPresent('[data-test="dose-group"][data-highlighted="true"]')
        ->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 8:00')
        ->assertNoJavaScriptErrors();

    // Врач видит отметку и ответы на опросники в карточке, закрывает алерт решением
    // и отвечает пациенту в чате.
    $doctorPage->navigate(route('doctor.patients.show', $patient))
        ->assertSeeIn('[data-test="adherence-course"]', 'принято 1 из 1')
        ->assertSeeIn('[data-test="wellbeing-gad7"]', 'лёгкая тревога')
        ->assertSeeIn('[data-test="wellbeing-phq9"]', 'лёгкая депрессия')
        ->click('[data-test="alert-close"]')
        ->click('input[name="decision"][value="contacted"]')
        ->click('[data-test="confirm-close-alert"]')
        ->assertSee('Алерт закрыт: связь с пациентом установлена.')
        ->assertSeeIn('[data-test="closed-alerts"]', 'Связь с пациентом установлена')
        ->assertNoJavaScriptErrors();

    $patientPage->navigate(route('patient.home'))
        ->assertMissing('[data-test="help-card"]');

    $patientPage->navigate(route('patient.chat.show'))
        ->fill('body', 'Утром принял, пока всё хорошо.')
        ->press('Отправить');
    waitForText($patientPage, 'Отправлено');

    $doctorPage->navigate(route('doctor.patients.chat.show', $patient))
        ->assertSeeIn('[data-test="chat-messages"]', 'Утром принял, пока всё хорошо.')
        ->fill('body', 'Хорошо, продолжайте утром и вечером.')
        ->press('Отправить');
    waitForText($patientPage, 'Хорошо, продолжайте утром и вечером.')
        ->assertNoJavaScriptErrors();

    // Вечером пациент не принял препарат, а утром следующего дня не отметил приём.
    $this->travelTo($day->setTime(20, 30));
    $patientPage->navigate(route('patient.home'))
        ->click('[data-test="skip-dose"]')
        ->click('input[name="reason"][value="forgot"]')
        ->click('[data-test="save-dose-mark"]')
        ->assertSee('Не принят: забыл')
        ->assertNoJavaScriptErrors();

    $this->travelTo($day->addDay()->setTime(15, 0));
    $this->artisan('app:dose-events:mark-missed')->assertSuccessful();

    // У врача сигнал в списке и счётчик у «Пациентов».
    $doctorPage->navigate(route('doctor.patients.index'))
        ->assertSeeIn('[data-signal]', 'Новиков Артём Сергеевич')
        ->assertSeeIn('[data-signal]', 'Пропуски подряд: 2')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('Новиков Артём Сергеевич')
        ->assertSeeIn('[data-test="adherence-signal"]', 'Пропуски подряд: 2')
        ->assertNoJavaScriptErrors();
});
