<?php

use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\CareRelationship;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    loadSafetyRules();
    loadAlertRules();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['login' => 'kuznetsova', 'name' => 'Кузнецова Елена Викторовна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()->subMonth()]);
    takes($this->patient, 'maritupirdine')->update(['starts_on' => now()->subDays(14)->toDateString(), 'prescriber_id' => $this->doctor->id]);
});

afterEach(fn () => $this->reverb->stop());

test('patient reports trismus from Today, the doctor gets the banner and the patient marks it passed', function () {
    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients');

    $patientPage = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'kuznetsova')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="report-side-effect-link"]')
        ->assertPathIs('/patient/side-effects/create')
        ->assertSee('Что вас беспокоит?')
        ->assertSeeIn('[data-test="symptoms-urgent"]', 'Сводит челюсти, трудно открыть рот')
        ->assertMissing('[data-test="tab-bar"]')
        ->assertMissing('[data-test="side-effect-urgent-hint"]')
        // Без выбора форма не уходит.
        ->click('[data-test="send-side-effect"]')
        ->assertSee('Выберите, что беспокоит, или напишите своими словами.')
        ->click('[data-test="symptom-trismus"]')
        ->assertSeeIn('[data-test="side-effect-urgent-hint"]', 'Врач получит срочное уведомление.')
        ->click('[data-test="severity-moderate"]')
        ->click('[data-test="started-yesterday"]')
        ->click('[data-test="send-side-effect"]')
        ->assertPathIs('/patient/side-effects')
        ->assertSeeIn('[data-test="side-effect-sent"]', 'Врачу отправлено срочно в')
        ->assertAttribute('[data-test="side-effect-call-112"]', 'href', 'tel:112')
        ->assertSeeIn('[data-test="side-effect-report"]', 'Сводит челюсти, трудно открыть рот')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->sole()->trigger())->toBe('тризм');

    waitForText($doctorPage, 'Срочно: Кузнецова Елена Викторовна')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Срочный побочный эффект — тризм')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Отправлено')
        ->click('[data-test="urgent-alert-open"]')
        ->assertSeeIn('[data-test="alert-symptoms"]', 'Тризм: «Сводит челюсти, трудно открыть рот»')
        ->assertSeeIn('[data-test="adverse-event"]', 'Авиандр — 14-й день курса')
        ->assertSeeIn('[data-test="adverse-event"]', 'Разбирается решением по алерту.')
        ->assertNoJavaScriptErrors();

    // На «Сегодня» — карточка о срочном сообщении, а не карточка с кризисной линией.
    $patientPage->navigate(route('patient.home'))
        ->assertSeeIn('[data-test="side-effect-alert-card"]', 'Вы срочно сообщили врачу сегодня')
        ->assertMissing('[data-test="help-card"]');

    $patientPage->navigate(route('patient.side-effects.index'))
        ->click('[data-test="side-effect-resolve"]')
        ->assertSeeIn('[data-test="side-effect-resolved"]', 'Прошло')
        // Случайное нажатие отменяется в тот же день.
        ->click('[data-test="side-effect-unresolve"]')
        ->assertPresent('[data-test="side-effect-resolve"]')
        ->click('[data-test="side-effect-resolve"]')
        ->assertSeeIn('[data-test="side-effect-resolved"]', 'Прошло')
        ->assertNoJavaScriptErrors();

    expect(AdverseEvent::query()->sole()->resolved_on?->toDateString())->toBe($this->patient->localToday()->toDateString());
});

test('an emergency symptom puts the call to 112 first with the words of the leaflet', function () {
    takes($this->patient, 'hydroxyzine', own: true);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'kuznetsova')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate(route('patient.side-effects.create'))
        ->click('[data-test="symptom-face_swelling"]')
        ->assertSeeIn('[data-test="side-effect-emergency-hint"]', 'Сначала позвоните 112, потом нажмите «Отправить врачу» внизу.')
        ->click('[data-test="send-side-effect"]')
        ->assertSeeIn('[data-test="side-effect-emergency"]', 'Позвоните 112 сейчас')
        ->assertSeeIn('[data-test="side-effect-instruction"]', 'Гидроксизин: «Прекратите приём препарата и немедленно обратитесь за медицинской помощью».')
        ->assertSeeIn('[data-test="side-effect-emergency"]', 'Врачу тоже отправлено в')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();
});

test('the doctor reviews a side effect from the queue and records one from the patient’s words', function () {
    AdverseEvent::query()->create([
        'patient_id' => $this->patient->id,
        'side_effect_id' => null,
        'name' => 'Сонливость днём',
        'severity' => 'severe',
        'started_on' => now()->subDays(11)->toDateString(),
        'reported_by_id' => $this->patient->id,
    ]);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSeeIn('[data-test="attention-adverse-events"]', 'Побочный эффект: Сонливость днём, сильно')
        ->click('Кузнецова Елена Викторовна')
        ->assertSeeIn('[data-test="card-adverse-events"]', 'Побочный эффект: Сонливость днём, сильно')
        ->assertSeeIn('[data-test="adverse-event"]', 'Авиандр — 4-й день курса')
        ->click('[data-test="review-adverse-event"]')
        ->assertMissing('[data-test="card-adverse-events"]')
        ->assertSeeIn('[data-test="adverse-event"]', 'просмотрено')
        ->click('[data-test="comment-adverse-event"]')
        ->fill('#adverse_event_comment', 'Пройдёт к концу 2-й недели')
        ->click('[data-test="save-adverse-event-comment"]')
        ->assertSeeIn('[data-test="adverse-event"]', 'Комментарий: Пройдёт к концу 2-й недели')
        ->click('[data-test="add-adverse-event"]')
        ->select('#adverse_symptom', 'other')
        ->fill('#adverse_other', 'Шум в ушах')
        ->click('[data-test="adverse-severity-mild"]')
        ->fill('#adverse_started_on', now()->toDateString())
        ->click('[data-test="confirm-add-adverse-event"]')
        ->assertSee('Запись добавлена.')
        ->assertSeeIn('[data-test="adverse-event-list"]', 'Шум в ушах')
        ->assertSeeIn('[data-test="adverse-event-list"]', 'Записал Смирнова А. С.')
        ->assertNoJavaScriptErrors();

    expect(AdverseEvent::query()->awaitingReview()->count())->toBe(0);
});
