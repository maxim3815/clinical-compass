<?php

use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
});

afterEach(fn () => $this->reverb->stop());

test('patient and doctor exchange messages without reloading the page', function () {
    $doctor = User::factory()->doctor()->create(['login' => 'doctor']);
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov', 'name' => 'Иванов Пётр Алексеевич']);

    $doctorPage = visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Сообщения')
        ->assertPathIs('/doctor/messages')
        ->assertSee('Сообщений пока нет');

    $patientPage = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient')
        ->click('[data-test="tab-chat"]')
        ->assertPathIs('/patient/chat')
        ->assertSee('Чат не для экстренной помощи')
        ->fill('body', 'Здравствуйте! Третью ночь плохо сплю.')
        ->press('Отправить');
    waitForText($patientPage, 'Отправлено')
        ->assertSeeIn('[data-test="chat-messages"]', 'Здравствуйте! Третью ночь плохо сплю.')
        ->assertValue('body', '')
        ->assertScript(FITS_SCREEN);

    // Страницу врач не перезагружал: диалог и счётчик в меню пришли событиями.
    waitForText($doctorPage, 'Иванов Пётр Алексеевич')
        ->assertSeeIn('[data-test="conversation"]', 'Здравствуйте! Третью ночь плохо сплю.')
        ->assertSeeIn('[data-test="unread-count"]', '1')
        ->assertSeeIn('[data-test="nav-badge"]', '1')
        ->click('Иванов Пётр Алексеевич')
        ->assertPathIs("/doctor/patients/{$patient->id}/chat")
        ->assertMissing('[data-test="nav-badge"]')
        ->fill('body', 'Добрый день! Во сколько вы принимаете препарат?')
        ->press('Отправить');

    // Пациент видит ответ, а у своего сообщения — что врач его прочитал.
    waitForText($patientPage, 'Добрый день! Во сколько вы принимаете препарат?')
        ->assertSee('Прочитано')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    // Ответ пришёл в открытый диалог пациента и сразу стал прочитанным.
    waitForText($doctorPage, 'Прочитано')
        ->assertNoJavaScriptErrors();
});
