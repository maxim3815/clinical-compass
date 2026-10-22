<?php

use App\Actions\SubmitAssessments;
use App\Enums\AlertDecision;
use App\Enums\AlertShownVia;
use App\Models\Alert;
use App\Models\CareRelationship;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    loadAlertRules();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['login' => 'morozova', 'name' => 'Морозова Ольга Павловна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);
});

afterEach(fn () => $this->reverb->stop());

test('patient answer raises a red banner for the open cabinet, the doctor accepts it and closes the alert with a decision', function () {
    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertMissing('[data-test="alert-banner"]')
        ->assertMissing('[data-test="nav-badge"]');

    $patientPage = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="answer-questions"]');

    foreach (range(0, 6) as $item) {
        $patientPage->click("input[name=\"gad7-{$item}\"][value=\"2\"]");
    }

    $patientPage->press('Дальше');

    foreach (range(0, 8) as $item) {
        $patientPage->click("input[name=\"phq9-{$item}\"][value=\"".($item === 8 ? 3 : 2).'"]');
    }

    // Пациентка видит экран помощи: 112 и чат, без обещания звонка врача.
    $patientPage->press('Отправить врачу')
        ->assertSee('Ответы переданы врачу')
        ->assertSee('ответ может прийти не сразу')
        ->assertSeeIn('[data-test="help-card"]', 'Позвонить 112')
        ->assertAttribute('[data-test="call-112"]', 'href', 'tel:112')
        ->assertPresent('[data-test="help-write-doctor"]')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    // У врача красная плашка и счётчик без перезагрузки, пациентка первая в очереди.
    waitForText($doctorPage, 'Срочно: Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Суицидальный риск — PHQ-9, вопрос 9: «почти каждый день»')
        ->assertSeeIn('[data-test="nav-badge"]', '1');

    waitForText($doctorPage, 'не принят')
        ->assertSeeIn('[data-test="attention-row"]', 'Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="attention-alert"]', 'Суицидальный риск — PHQ-9, вопрос 9')
        ->assertPresent('[data-test="alert-mark"]')
        ->assertNoJavaScriptErrors();

    // Отметку «показан» браузер отправляет сам: запрос обрабатывается, пока тест ждёт.
    $doctorPage->wait(1);

    expect(Alert::query()->sole()->shown_via)->toBe(AlertShownVia::Realtime);

    // Пока алерт открыт, на «Сегодня» у пациентки карточка с 112.
    $patientPage->click('[data-test="questionnaire-done"]')
        ->assertPathIs('/patient')
        ->assertSeeIn('[data-test="help-card"]', 'Если вам сейчас тяжело');

    // «Принял»: плашки нет, алерт в очереди и в карточке до решения.
    $doctorPage->click('[data-test="urgent-alert-accept"]')
        ->assertSee('Алерт принят.')
        ->assertMissing('[data-test="alert-banner"]')
        ->assertSeeIn('[data-test="attention-alert"]', 'Суицидальный риск')
        ->assertSeeIn('[data-test="attention-alert"]', 'ждёт решения')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="card-alert"]', 'ждёт решения')
        ->assertSeeIn('[data-test="open-alert"]', 'Принят')
        ->assertSeeIn('[data-test="alert-delivery"]', 'Показан в открытом кабинете через');

    // «Угрозы нет» без комментария не закрывается.
    $doctorPage->click('[data-test="alert-close"]')
        ->click('input[name="decision"][value="no_threat"]')
        ->click('[data-test="confirm-close-alert"]')
        ->assertSee('Напишите, почему угрозы нет.')
        ->fill('comment', 'Позвонил: мысли пассивные, плана нет. Очный приём завтра.')
        ->click('[data-test="confirm-close-alert"]')
        ->assertSee('Алерт закрыт: угрозы нет.')
        ->assertMissing('[data-test="open-alert"]')
        ->assertSeeIn('[data-test="closed-alerts"]', 'Угрозы нет')
        ->assertSeeIn('[data-test="closed-alerts"]', 'Позвонил: мысли пассивные')
        ->assertMissing('[data-test="nav-badge"]')
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->sole()->decision)->toBe(AlertDecision::NoThreat);

    $patientPage->navigate(route('patient.home'))
        ->assertMissing('[data-test="help-card"]')
        ->assertNoJavaScriptErrors();
});

test('doctor who opens the cabinet later sees the banner on any page and opens the card from it', function () {
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(phq9Item9: 1));

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate(route('doctor.drugs.index'))
        ->assertSeeIn('[data-test="urgent-alert"]', 'Срочно: Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="urgent-alert"]', '«в течение нескольких дней»')
        ->assertScript(FITS_SCREEN)
        ->click('[data-test="urgent-alert-open"]')
        ->assertPathIs("/doctor/patients/{$this->patient->id}")
        ->assertSeeIn('[data-test="open-alert"]', 'Не принят')
        // Свой алерт карточка показывает блоком, плашка его не повторяет.
        ->assertMissing('[data-test="alert-banner"]')
        ->assertNoJavaScriptErrors();

    $page->wait(1);

    expect(Alert::query()->sole()->shown_via)->toBe(AlertShownVia::Page);
});
