<?php

use App\Enums\HelpEventType;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\CareRelationship;
use App\Models\HelpEvent;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    loadAlertRules();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['login' => 'morozova', 'name' => 'Морозова Ольга Павловна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);
});

afterEach(fn () => $this->reverb->stop());

test('patient reports to the doctor from the help screen, the doctor gets the banner and a repeat brings it back', function () {
    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertMissing('[data-test="alert-banner"]');

    // Кнопка помощи в шапке, одно касание — экран помощи, врачу ничего не уходит.
    $patientPage = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSeeIn('[data-test="help-button"]', 'Нужна помощь')
        ->click('[data-test="help-button"]')
        ->assertPathIs('/patient/help')
        ->assertSee('Если вам сейчас тяжело')
        ->assertMissing('[data-test="help-button"]')
        ->assertAttribute('[data-test="call-112"]', 'href', 'tel:112')
        ->assertAttribute('[data-test="call-crisis-line"]', 'href', 'tel:88003334434')
        ->assertSeeIn('[data-test="call-crisis-line"]', '8 800 333-44-34')
        ->assertSeeIn('[data-test="help-doctor"]', 'Смирнова Анна Сергеевна')
        ->assertSeeIn('[data-test="help-report-hint"]', 'Врач получит срочное уведомление')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->count())->toBe(0)
        ->and(HelpEvent::query()->sole()->type)->toBe(HelpEventType::Opened);

    // Нажатие на звонок записывается, сам звонок совершает телефон.
    $patientPage->script(<<<'JS'
        const link = document.querySelector('[data-test="call-112"]');
        link.addEventListener('click', (event) => event.preventDefault());
        link.click();
        JS);
    $patientPage->wait(1);

    expect(HelpEvent::query()->where('type', HelpEventType::Call112)->count())->toBe(1);

    // «Сообщить врачу» — без подтверждения.
    $patientPage->click('[data-test="help-report"]')
        ->assertSeeIn('[data-test="help-reported"]', 'Врачу отправлено в')
        ->assertSeeIn('[data-test="help-reported"]', 'Если нажали случайно, напишите врачу')
        ->assertPresent('[data-test="help-reported-chat"]')
        ->assertSeeIn('[data-test="help-report"]', 'Сообщить ещё раз')
        ->assertMissing('[data-test="help-report-hint"]')
        ->assertPresent('[data-test="call-112"]')
        ->assertNoJavaScriptErrors();

    waitForText($doctorPage, 'Срочно: Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Просьба о помощи — кнопка «Сообщить врачу»')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Нажато')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('[data-test="urgent-alert-accept"]')
        ->assertSee('Алерт принят.')
        ->assertMissing('[data-test="alert-banner"]');

    // Через пару минут пациентка нажимает ещё раз: плашка возвращается.
    $this->travel(2)->minutes();
    $patientPage->click('[data-test="help-report"]')
        ->assertSeeIn('[data-test="help-reported"]', 'Врачу отправлено в');

    waitForText($doctorPage, 'Срочно, повторно: Морозова Ольга Павловна')
        ->assertSeeIn('[data-test="urgent-alert"]', 'Просьба о помощи')
        ->click('[data-test="urgent-alert-open"]')
        ->assertSeeIn('[data-test="open-alert"]', 'Повтор не принят')
        ->assertSeeIn('[data-test="card-alert"]', 'повтор не принят')
        ->assertSeeIn('[data-test="alert-repeats"]', 'Нажато повторно')
        ->assertSeeIn('[data-test="card-help-events"]', 'Экран помощи:')
        ->assertSeeIn('[data-test="help-events"]', 'Открыт экран помощи')
        ->assertSeeIn('[data-test="help-events"]', 'нажата кнопка «Позвонить 112»')
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->sole()->repeat_count)->toBe(1);
});

test('the help button opens the help screen from a form without asking to stay, and «Back» returns there', function () {
    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate(route('patient.own-medications.create'))
        ->select('drug_id', 'other')
        ->fill('drug_name', 'Глицин')
        ->click('[data-test="help-button"]')
        ->assertPathIs('/patient/help')
        ->assertSee('Если вам сейчас тяжело')
        ->assertMissing('[data-test="leave-confirm-dialog"]')
        ->assertSeeIn('[data-test="back-link"]', 'Назад')
        ->click('[data-test="back-link"]')
        ->assertPathIs('/patient/medications/create')
        ->assertNoJavaScriptErrors();

    // Открыт не из приложения — «Назад» ведёт на «Сегодня».
    $page->navigate(route('patient.help.show'))
        ->assertSeeIn('[data-test="back-link"]', 'Сегодня');
});

test('when the report does not go through, the patient sees it and keeps 112', function () {
    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="help-button"]');

    // Пока экран был открыт, сообщить стало нельзя: сообщение не уходит.
    AlertRule::query()->where('code', 'help_request')->update(['is_active' => false]);

    $page->click('[data-test="help-report"]')
        ->assertSeeIn('[data-test="help-report-failed"]', 'Врачу не отправлено')
        ->assertSeeIn('[data-test="help-report-failed"]', 'Сообщить врачу отсюда сейчас нельзя')
        ->assertMissing('[data-test="help-report"]')
        ->assertMissing('[data-test="help-reported"]')
        ->assertPresent('[data-test="call-112"]')
        ->assertNoJavaScriptErrors();

    expect(Alert::query()->count())->toBe(0);
});

test('a patient without a doctor sees only the calls on the help screen', function () {
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'morozova')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="help-button"]')
        ->assertPresent('[data-test="call-112"]')
        ->assertPresent('[data-test="call-crisis-line"]')
        ->assertMissing('[data-test="help-report"]')
        ->assertSee('Вы сейчас не подключены к врачу в приложении')
        ->assertNoJavaScriptErrors();
});
