<?php

use App\Actions\SyncDoseEvents;
use App\Enums\DoseEventStatus;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сбой сервера на пути отправки: прокси отвечает 502, как при упавшем или перезапускаемом
 * приложении. «down» — запрос до приложения не дошёл, «lost» — приложение его обработало,
 * а ответ потерялся.
 */
final class SimulatedOutage
{
    public static ?string $mode = null;

    public static string $path = '';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::$mode === null || ! $request->is(self::$path)) {
            return $next($request);
        }

        if (self::$mode === 'lost') {
            $next($request);
        }

        return response('<html><body><h1>502 Bad Gateway</h1><hr>nginx</body></html>', 502);
    }
}

beforeEach(function () {
    SimulatedOutage::$mode = null;
    app(Kernel::class)->prependMiddleware(SimulatedOutage::class);
});

afterEach(fn () => SimulatedOutage::$mode = null);

test('patient marks a dose while the server is down and again after it is back', function () {
    $day = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
    $this->travelTo($day->setTime(8, 30));
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);
    timedPrescription($patient, catalogDrug('Авиандр', [20 => false])->strengths()->sole(), ['morning' => '08:00'], [
        'prescriber_id' => $doctor->id,
        'starts_on' => $day->subWeek()->toDateString(),
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($patient);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/patient');

    SimulatedOutage::$mode = 'down';
    SimulatedOutage::$path = 'patient/doses/*';

    // Вместо окна с сырой страницей ошибки — понятное сообщение, отметка осталась доступной.
    $page->click('[data-test="take-dose"]')
        ->assertSee('Сервер временно не отвечает. Попробуйте ещё раз через минуту.')
        ->assertScript('document.querySelector("dialog iframe") === null')
        ->assertPresent('[data-test="take-dose"]');
    expect(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Scheduled);

    SimulatedOutage::$mode = null;

    $page->click('[data-test="take-dose"]')
        ->assertSee('Отметка сохранена.')
        ->assertSee('Принят в 8:30')
        ->assertNoJavaScriptErrors();
    expect(doseOn($patient, $day->toDateString(), 'morning')->status)->toBe(DoseEventStatus::Taken);
});

test('message whose answer was lost is sent once on retry', function () {
    $reverb = startReverb();
    $doctor = User::factory()->doctor()->create();
    User::factory()->patientOf($doctor)->create(['login' => 'ivanov']);

    $page = visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'password')
        ->press('Войти')
        ->click('[data-test="tab-chat"]')
        ->assertPathIs('/patient/chat');

    SimulatedOutage::$mode = 'lost';
    SimulatedOutage::$path = 'patient/chat/messages';

    // Сообщение сохранено, но ответа пациент не получил: видит ошибку, текст остался в поле.
    $page->fill('body', 'Третью ночь плохо сплю.')
        ->press('Отправить')
        ->assertSee('Не удалось отправить сообщение. Проверьте соединение и попробуйте ещё раз.')
        ->assertValue('body', 'Третью ночь плохо сплю.');
    expect(Message::query()->count())->toBe(1);

    SimulatedOutage::$mode = null;

    // Повтор с тем же текстом не создаёт второе сообщение.
    $page->press('Отправить');
    waitForText($page, 'Отправлено')
        ->assertValue('body', '')
        ->assertNoJavaScriptErrors();
    expect(Message::query()->count())->toBe(1)
        ->and(substr_count($page->text('[data-test="chat-messages"]'), 'Третью ночь плохо сплю.'))->toBe(1);

    $reverb->stop();
});
