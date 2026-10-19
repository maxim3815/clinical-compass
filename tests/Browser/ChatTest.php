<?php

use App\Models\User;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Symfony\Component\Process\Process;

// Reverb запускается прямо в контейнере тестов на том же порту, что и в собранных ассетах
// (VITE_REVERB_PORT), поэтому браузер подключается к нему как в окружении разработки.
beforeEach(function () {
    $port = (int) env('VITE_REVERB_PORT', 8080);

    $this->reverb = new Process(['php', 'artisan', 'reverb:start', '--host=0.0.0.0', "--port={$port}"], base_path());
    $this->reverb->start();

    $deadline = microtime(true) + 10;

    while (@file_get_contents("http://127.0.0.1:{$port}/up") === false) {
        if (! $this->reverb->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Reverb не запустился: '.$this->reverb->getErrorOutput());
        }

        usleep(100_000);
    }

    useReverbBroadcaster($port);

    // Тестовый сервер обрабатывает запросы в одном контейнере: вошедший пользователь и данные
    // сессии переходили бы из запроса в запрос. Для врача и пациента в одном тесте перед
    // каждым запросом они забываются, и сессия читается заново по cookie своего браузера.
    Event::listen(RouteMatched::class, function () {
        Auth::forgetGuards();
        Session::driver()->flush();
    });
});

afterEach(fn () => $this->reverb->stop());

/**
 * Ждёт текст на странице: событие из Reverb приходит не сразу, а assertSee проверяет один раз.
 */
function waitForText(mixed $page, string $text): mixed
{
    return $page->assertScript(sprintf(<<<'JS'
        function () {
            const started = Date.now();

            return new Promise((resolve) => {
                const check = () => {
                    const found = document.body.innerText.includes(%s);

                    if (found || Date.now() - started > 10000) {
                        resolve(found);
                    } else {
                        setTimeout(check, 100);
                    }
                };

                check();
            });
        }
        JS, json_encode($text, JSON_UNESCAPED_UNICODE)));
}

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
        ->click('[data-sidebar="trigger"]')
        ->click('Чат с врачом')
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
