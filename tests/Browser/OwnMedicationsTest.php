<?php

use App\Enums\DoseEventStatus;
use App\Enums\PrescriptionSource;
use App\Models\Prescription;
use App\Models\User;
use Carbon\CarbonImmutable;

test('patient on a phone adds another drug and marks it on the today screen', function () {
    // Время приложения переводится только вперёд: срок cookie сессии считается по нему, а браузер
    // живёт по настоящим часам и не примет cookie, которая уже истекла.
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(7, 0));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    catalogDrug('Сертралин', [50 => true]);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->click('[data-test="tab-medications"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Добавленные мной')
        ->click('[data-test="add-medication-button"]')
        ->assertPathIs('/patient/medications/create')
        // Фенибута в списке нет: предлагается добавить его с набранным названием.
        ->fill('#drug_id', 'Фенибут')
        ->click('[data-test="drug-option-other"]')
        ->assertSee('Проверьте название по упаковке')
        ->fill('#dose', '1 таблетка 250 мг')
        ->assertValue('#intake_time_0', '08:00')
        ->fill('#intake_time_0', '07:30')
        // Необязательные поля свёрнуты.
        ->assertMissing('#prescribed_by')
        ->click('[data-test="optional-fields-button"]')
        ->fill('#prescribed_by', 'невролог Петров')
        ->assertScript(FITS_SCREEN)
        ->click('[data-test="save-medication"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Препарат добавлен.')
        ->assertSee('Фенибут')
        ->assertSee('Доза: 1 таблетка 250 мг')
        ->assertSee('Утром, 7:30')
        ->assertSee('кто назначил: невролог Петров')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="tab-today"]')
        ->assertPathIs('/patient')
        ->assertSee('Фенибут')
        ->assertSee('1 таблетка 250 мг')
        ->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 7:00')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    $prescription = Prescription::query()->sole();

    expect($prescription->source)->toBe(PrescriptionSource::Patient)
        ->and($prescription->drug_name)->toBe('Фенибут')
        ->and(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Taken);
});

test('patient on a phone finds a drug by the trade name and is told to tell the doctor about a contraindicated combination', function () {
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(10, 0));
    loadSafetyRules();
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'smirnov']);
    diagnose($patient, 'F41.1');
    takes($patient, 'escitalopram');

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'smirnov')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="tab-medications"]')
        ->click('[data-test="add-medication-button"]')
        // Пока название набирается, «оставить как написано» не предлагается.
        ->fill('#drug_id', 'трам')
        ->assertMissing('[data-test="drug-option-other"]')
        // Название с коробки в любом регистре: вариант с действующим веществом.
        ->fill('#drug_id', 'трамал')
        ->assertSeeIn('[data-test="drug-options"]', 'Трамал')
        ->assertSeeIn('[data-test="drug-options"]', 'трамадол')
        ->assertAttribute('#drug_id', 'aria-expanded', 'true')
        // С клавиатуры: стрелка выделяет вариант, Enter выбирает его.
        ->keys('#drug_id', ['ArrowDown', 'Enter'])
        ->assertAttribute('#drug_id', 'aria-expanded', 'false')
        ->assertValue('#drug_id', 'Трамал')
        ->assertSee('Действующее вещество: трамадол')
        ->fill('#dose', '1 капсула 50 мг')
        ->assertScript(FITS_SCREEN)
        ->click('[data-test="save-medication"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Препарат добавлен.')
        ->assertSeeIn('[data-test="own-medications"]', 'Трамал')
        // С эсциталопрамом трамадол — «с осторожностью»: пациент ничего не видит.
        ->assertMissing('[data-test="tell-doctor"]')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="add-medication-button"]')
        // Растительный «Корвалол Фито» — не «Корвалол»: можно оставить название как написано.
        ->fill('#drug_id', 'Корвалол Фито')
        ->assertSeeIn('[data-test="drug-option"]', 'Корвалол')
        ->assertSeeIn('[data-test="drug-option-other"]', 'Оставить как написано: «Корвалол Фито»')
        // С опечаткой — похожие названия, а не «другой» препарат без проверки.
        ->fill('#drug_id', 'Кордорон')
        ->assertSee('Точного совпадения нет. Может быть, вы имели в виду:')
        ->assertSeeIn('[data-test="drug-option"]', 'Кордарон')
        ->click('[data-test="drug-option"]')
        ->assertValue('#drug_id', 'Кордарон')
        // Переписанная с упаковки доза поиску не мешает: при уходе из поля выбран «Кордарон».
        ->fill('#drug_id', 'Кордарон 200 мг')
        ->assertMissing('[data-test="drug-option-other"]')
        ->click('#dose')
        ->assertValue('#drug_id', 'Кордарон')
        ->assertSee('Действующее вещество: амиодарон')
        ->fill('#dose', '1 таблетка 200 мг')
        ->click('[data-test="save-medication"]')
        ->assertPathIs('/patient/medications')
        ->assertSee('Препарат добавлен. Расскажите врачу, что принимаете его.')
        ->assertSeeIn('[data-test="tell-doctor"]', 'Расскажите врачу, что принимаете этот препарат: врач посмотрит, как он подходит к вашему лечению. До разговора с врачом принимайте всё как обычно.')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors()
        ->click('[data-test="tell-doctor-chat"]')
        ->assertPathIs('/patient/chat');

    expect(Prescription::query()->where('source', PrescriptionSource::Patient)->orderBy('id')->pluck('trade_name')->all())->toBe(['Трамал', 'Кордарон']);
});
