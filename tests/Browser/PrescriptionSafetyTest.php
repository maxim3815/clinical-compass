<?php

use App\Actions\SyncSafetyFindings;
use App\Enums\Sex;
use App\Models\Icd10Code;
use App\Models\User;
use App\Support\DisplayTime;

beforeEach(function () {
    loadSafetyRules();
    $this->doctor = User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Григорьев Роман Николаевич']);
    $this->patient->patientProfile?->update(['sex' => Sex::Male, 'birth_date' => DisplayTime::today()->subYears(48)]);
});

/**
 * Врач входит и открывает карточку пациента.
 */
function openPatientCard(User $patient): mixed
{
    return visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click($patient->name)
        ->assertPathIs("/doctor/patients/{$patient->id}");
}

test('doctor prescribes aviandr despite a contraindication only with a justification', function () {
    diagnose($this->patient, 'F41.1');
    observe($this->patient, 'gfr', 52);

    $page = openPatientCard($this->patient)
        ->click('[data-test="prescribe-button"]')
        // Итог проверки виден в списке препаратов ещё до выбора.
        ->assertSeeIn('[data-drug="maritupirdine"]', 'Противопоказано')
        ->click('[data-drug="maritupirdine"]');

    waitForText($page, 'Противопоказан при почечной');

    $page->assertSee('Противопоказан при почечной недостаточности средней и тяжёлой степени')
        ->assertSee('СКФ 52 мл/мин/1,73 м²')
        ->assertSee('Источник: ОХЛП «Авиандр», реестр ЕАЭС, 4.3. Противопоказания')
        ->assertSee('Не проверено')
        ->press('Назначить')
        ->assertSee('Добавьте хотя бы один приём.')
        ->check('[data-test="intake-morning"]')
        ->press('Назначить')
        ->assertSee('Есть противопоказание: напишите обоснование назначения вопреки предупреждению.')
        // Фокус переходит к полю обоснования.
        ->assertScript('document.activeElement.id', 'justification')
        ->fill('justification', 'Альтернативы исчерпаны, согласовано с нефрологом')
        ->press('Назначить')
        ->assertPathIs("/doctor/patients/{$this->patient->id}")
        ->assertSee('Препарат назначен.')
        ->assertSeeIn('[data-test="prescription-safety"]', 'Назначено вопреки предупреждению')
        ->assertSeeIn('[data-test="prescription-safety"]', 'решение принято')
        ->assertSeeIn('[data-test="prescription-safety"]', 'Альтернативы исчерпаны, согласовано с нефрологом')
        ->assertMissing('[data-test="nav-badge"]')
        ->assertNoJavaScriptErrors();
});

test('doctor continues an ongoing combination with a justification and the mark goes away', function () {
    $this->patient->patientProfile?->update(['sex' => Sex::Female]);
    diagnose($this->patient, 'F41.1');
    takes($this->patient, 'escitalopram', own: true);
    takes($this->patient, 'hydroxyzine', own: true);
    app(SyncSafetyFindings::class)->handle($this->patient);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->assertSeeIn('[data-test="contraindication-mark"]', 'Противопоказание без решения')
        // Очередь называет препараты, по которым врач не решил.
        ->assertSeeIn('[data-test="attention-contraindication"]', 'Гидроксизин, Эсциталопрам')
        ->click($this->patient->name)
        ->assertSeeIn('[data-test="card-contraindication"]', 'Гидроксизин, Эсциталопрам')
        ->assertSee('Противопоказан вместе с препаратами, удлиняющими интервал QT')
        // Пара находок — одно решение: у второго препарата ссылка на плашку первого.
        ->assertPresent('[data-test="safety-finding-paired"]')
        ->click('[data-test="own-medications"] [data-test="continue-finding-button"]')
        ->assertSee('Решение относится и к находке у препарата')
        ->fill('justification', 'Написал пациентке прекратить гидроксизин')
        ->click('[data-test="confirm-continue"]')
        ->assertSee('Решение сохранено, приём продолжается.')
        ->assertMissing('[data-test="continue-finding-button"]')
        ->assertSee('Приём продолжен')
        ->assertMissing('[data-test="card-contraindication"]')
        ->assertMissing('[data-test="nav-badge"]')
        ->assertNoJavaScriptErrors();
});

test('doctor fills in the clinical profile and sees it in the card', function () {
    Icd10Code::query()->insert([
        ['code' => 'F41', 'name' => 'Другие тревожные расстройства'],
        ['code' => 'F41.1', 'name' => 'Генерализованное тревожное расстройство'],
    ]);
    $this->patient->patientProfile?->update(['sex' => null]);

    $page = openPatientCard($this->patient)
        ->assertSeeIn('[data-test="clinical-profile"]', 'Пол: не указан')
        ->click('[data-test="edit-clinical-profile"]')
        ->assertSee('Только у женщин: беременность, грудное вскармливание.')
        ->assertMissing('[data-test="save-sex"]')
        ->click('input[name="sex"][value="female"]')
        ->click('[data-test="save-sex"]')
        ->assertSee('Пол сохранён.')
        ->assertDontSee('Только у женщин')
        ->fill('#icd10_search', 'F41.1');

    waitForText($page, 'Генерализованное тревожное расстройство');

    $page->click('[data-test="icd10-results"] [role="option"]')
        ->assertSeeIn('[data-test="chosen-diagnosis"]', 'F41.1')
        ->click('[data-test="save-diagnosis"]')
        ->assertSee('Диагноз добавлен.')
        ->assertSeeIn('[data-test="diagnosis"]', 'основной')
        ->fill('[data-fact="gfr"] input[type="number"]', '52')
        ->click('[data-fact="epilepsy"] input[value="0"]')
        ->assertSeeIn('[data-test="facts-save-bar"]', 'СКФ — 52 мл/мин/1,73 м², Эпилепсия или органическое поражение головного мозга — нет')
        ->click('[data-test="save-observations"]')
        ->assertSee('Факты сохранены.')
        ->assertMissing('[data-test="facts-save-bar"]')
        ->assertSeeIn('[data-fact="epilepsy"] [data-test="observation-current"]', 'нет')
        ->assertSeeIn('[data-fact="gfr"] [data-test="observation-current"]', '52 мл/мин/1,73 м²')
        ->click('К карточке пациента')
        ->assertSeeIn('[data-test="clinical-profile"]', 'Пол: Женский')
        ->assertSeeIn('[data-test="clinical-profile"]', 'F41.1')
        ->assertSeeIn('[data-test="clinical-profile"]', '52 мл/мин/1,73 м²')
        ->assertNoJavaScriptErrors();
});

test('safety check fits a phone screen', function () {
    diagnose($this->patient, 'Z73.0');
    observe($this->patient, 'gfr', 52);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->navigate(route('doctor.patients.prescriptions.create', $this->patient))
        ->click('[data-drug="maritupirdine"]');

    waitForText($page, 'Назначение вне зарегистрированных показаний');

    $page->assertSee('Обоснование (обязательно)')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->navigate(route('doctor.rules.index'))
        ->assertSee('Действующих правил: 52.')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});
