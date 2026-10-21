<?php

use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCloseReason;
use App\Models\AsNeededDose;
use App\Models\CareRelationship;
use App\Models\Diagnosis;
use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\DrugStrength;
use App\Models\Icd10Code;
use App\Models\Observation;
use App\Models\ObservationType;
use App\Models\Prescription;
use App\Models\PrescriptionIntake;
use App\Models\User;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\PushSubscription;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Тесты с несколькими процессами: данные должны быть закоммичены, поэтому вместо
// транзакции RefreshDatabase таблицы очищаются до и после каждого теста.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->afterEach(fn () => $this->truncateTablesForAllConnections())
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// Страница помещается в ширину экрана, горизонтальной прокрутки нет (browser-тесты на телефоне).
const FITS_SCREEN = 'document.documentElement.scrollWidth <= window.innerWidth';

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Препарат справочника с дозировками: миллиграммы => можно ли делить таблетку.
 *
 * @param  array<int|string, bool>  $strengths
 * @param  array<string, mixed>  $attributes
 */
function catalogDrug(string $name, array $strengths = [25 => true], array $attributes = []): Drug
{
    $drug = Drug::factory()->create(['name' => $name, ...$attributes]);

    foreach ($strengths as $mg => $divisible) {
        DrugStrength::factory()->for($drug)->create(['mg' => $mg, 'divisible' => $divisible]);
    }

    return $drug;
}

/**
 * Данные формы назначения: самая малая дозировка препарата, регулярно, утром одна таблетка,
 * с сегодняшнего дня по Москве.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prescriptionPayload(Drug $drug, array $overrides = []): array
{
    return [
        'drug_id' => $drug->id,
        'drug_strength_id' => $drug->strengths()->orderBy('mg')->value('id'),
        'kind' => 'regular',
        'intakes' => [['time_of_day' => 'morning', 'tablets' => 1]],
        'tablets' => null,
        'max_daily_doses' => null,
        'instruction' => $drug->patient_instruction,
        'starts_on' => DisplayTime::today()->toDateString(),
        'ends_on' => null,
        ...$overrides,
    ];
}

/**
 * Регулярное назначение врача с приёмами: время суток => таблеток.
 *
 * @param  array<string, string>  $intakes
 * @param  array<string, mixed>  $attributes
 */
function regularPrescription(User $patient, DrugStrength $strength, array $intakes = ['morning' => '1'], array $attributes = []): Prescription
{
    $prescription = Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create($attributes);

    foreach ($intakes as $timeOfDay => $tablets) {
        PrescriptionIntake::factory()->for($prescription)->create(['time_of_day' => $timeOfDay, 'tablets' => $tablets]);
    }

    return $prescription;
}

/**
 * Регулярное назначение врача, у которого пациент уже выбрал время: время суток => «08:00»,
 * по таблетке на приём. Время выбрано в момент, с которого действует назначение (effective_at).
 *
 * @param  array<string, string>  $times
 * @param  array<string, mixed>  $attributes
 */
function timedPrescription(User $patient, DrugStrength $strength, array $times = ['morning' => '08:00'], array $attributes = []): Prescription
{
    $prescription = Prescription::factory()->for($patient, 'patient')->for($strength, 'strength')->create($attributes);

    foreach ($times as $timeOfDay => $time) {
        PrescriptionIntake::factory()->for($prescription)->at($time, $prescription->effective_at)->create(['time_of_day' => $timeOfDay]);
    }

    return $prescription;
}

/**
 * Препарат, который пациент добавил сам, с выбранным временем приёмов: время суток => «08:00».
 * Без препарата справочника — «другой» препарат «Фенибут» или с названием из drug_name.
 * Время выбрано в момент, с которого действует препарат (effective_at).
 *
 * @param  array<string, string>  $times
 * @param  array<string, mixed>  $attributes
 */
function ownPrescription(User $patient, ?Drug $drug = null, array $times = ['morning' => '08:00'], array $attributes = []): Prescription
{
    $prescription = Prescription::factory()
        ->for($patient, 'patient')
        ->addedByPatient()
        ->state(['drug_id' => $drug?->id])
        ->create($attributes);

    foreach ($times as $timeOfDay => $time) {
        PrescriptionIntake::factory()->for($prescription)->at($time, $prescription->effective_at)->create([
            'time_of_day' => $timeOfDay,
            'tablets' => null,
        ]);
    }

    return $prescription;
}

/**
 * Регулярный курс врача с выбранным временем: утром 8:00 и вечером 20:00, если не задано иначе.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, string>  $times
 */
function adherenceCourse(User $doctor, User $patient, DrugStrength $strength, string $startsOn, array $attributes = [], array $times = ['morning' => '08:00', 'evening' => '20:00']): Prescription
{
    return timedPrescription($patient, $strength, $times, [
        'prescriber_id' => $doctor->id,
        'starts_on' => $startsOn,
        'effective_at' => CarbonImmutable::parse($startsOn, 'Europe/Moscow'),
        ...$attributes,
    ]);
}

/**
 * Версия назначения, закрытая отменой или изменением в момент UTC.
 *
 * @return array<string, mixed>
 */
function closedAt(User $doctor, string $moment, PrescriptionCloseReason $reason = PrescriptionCloseReason::Cancelled): array
{
    return ['closed_at' => $moment, 'closed_by_id' => $doctor->id, 'close_reason' => $reason];
}

/**
 * Плановый приём курса на местную дату пациента: утром в 8:00, днём в 14:00, вечером в 20:00,
 * перед сном в 22:00, окно 6 часов. Принятый — в плановое время и отмечен тогда же, не принятый —
 * «Забыл» с отметкой в плановое время.
 *
 * @param  array<string, mixed>  $attributes
 */
function adherenceDose(Prescription $prescription, string $date, string $status = 'taken', string $timeOfDay = 'morning', array $attributes = []): DoseEvent
{
    $time = ['morning' => '08:00', 'afternoon' => '14:00', 'evening' => '20:00', 'bedtime' => '22:00'][$timeOfDay];
    $scheduledAt = CarbonImmutable::parse($date.' '.$time, $prescription->patient->localTimezone())->utc();
    $takenAt = $attributes['taken_at'] ?? $scheduledAt;

    return DoseEvent::factory()->for($prescription)->create([
        'patient_id' => $prescription->patient_id,
        'time_of_day' => $timeOfDay,
        'local_date' => $date,
        'scheduled_at' => $scheduledAt,
        'window_closes_at' => $scheduledAt->addHours(6),
        'status' => $status,
        ...match ($status) {
            'taken' => ['taken_at' => $takenAt, 'marked_at' => $takenAt],
            'skipped' => ['marked_at' => $scheduledAt, 'skip_reason' => DoseSkipReason::Forgot],
            default => [],
        },
        ...$attributes,
    ]);
}

/**
 * Приёмы «по необходимости» на местную дату пациента, с 9:00 каждый час.
 */
function asNeededDosesOn(Prescription $prescription, string $date, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        AsNeededDose::factory()->for($prescription)->create([
            'patient_id' => $prescription->patient_id,
            'tablets' => $prescription->tablets,
            'local_date' => $date,
            'taken_at' => CarbonImmutable::parse($date.' 09:00', $prescription->patient->localTimezone())->addHours($i)->utc(),
        ]);
    }
}

/**
 * Плановые приёмы пациента по порядку: местная дата, время суток и scheduled_at в UTC — «2026-10-19 morning 2026-10-19 05:00».
 *
 * @return list<string>
 */
function doseSchedule(User $patient): array
{
    return $patient->doseEvents()
        ->orderBy('scheduled_at')
        ->get()
        ->map(fn (DoseEvent $event): string => $event->local_date->toDateString().' '.$event->time_of_day->value.' '.$event->scheduled_at->utc()->format('Y-m-d H:i'))
        ->values()
        ->all();
}

/**
 * Плановый приём пациента на местную дату и время суток.
 */
function doseOn(User $patient, string $date, string $timeOfDay, ?Prescription $prescription = null): DoseEvent
{
    return $patient->doseEvents()
        ->where('local_date', $date)
        ->where('time_of_day', $timeOfDay)
        ->when($prescription !== null, fn ($query) => $query->where('prescription_id', $prescription?->id))
        ->sole();
}

/**
 * Трансляция через Reverb вместо null из phpunit.xml: для проверки авторизации каналов
 * и браузерного теста в реальном времени. Каналы из routes/channels.php регистрируются
 * на драйвере по умолчанию при загрузке приложения, поэтому после смены драйвера их нужно
 * зарегистрировать заново. Ключ и секрет приложения Reverb берутся из .env.
 */
function useReverbBroadcaster(int $port = 8080): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => $port,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    require base_path('routes/channels.php');
}

/**
 * Браузерный тест в реальном времени для двух пользователей: Reverb запускается прямо в контейнере
 * тестов на том же порту, что и в собранных ассетах (VITE_REVERB_PORT), поэтому браузер подключается
 * к нему как в окружении разработки. Остановить его нужно после теста.
 *
 * Тестовый сервер обрабатывает запросы в одном контейнере: вошедший пользователь и данные сессии
 * переходили бы из запроса в запрос. Для врача и пациента в одном тесте перед каждым запросом
 * они забываются, и сессия читается заново по cookie своего браузера.
 */
function startReverb(): Process
{
    $port = (int) env('VITE_REVERB_PORT', 8080);

    $reverb = new Process(['php', 'artisan', 'reverb:start', '--host=0.0.0.0', "--port={$port}"], base_path());
    $reverb->start();

    $deadline = microtime(true) + 10;

    while (@file_get_contents("http://127.0.0.1:{$port}/up") === false) {
        if (! $reverb->isRunning() || microtime(true) > $deadline) {
            throw new RuntimeException('Reverb не запустился: '.$reverb->getErrorOutput());
        }

        usleep(100_000);
    }

    useReverbBroadcaster($port);

    Event::listen(RouteMatched::class, function () {
        Auth::forgetGuards();
        Session::driver()->flush();
    });

    return $reverb;
}

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

/**
 * Диалог пациента с лечащим врачом: связь между ними.
 */
function careBetween(User $doctor, User $patient): CareRelationship
{
    return CareRelationship::query()->active()->between($doctor, $patient)->sole();
}

/**
 * Пользователь включил пуши в одном браузере (Chrome: сервис пушей Google).
 */
function subscribeToPush(User $user): PushSubscription
{
    return $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/'.Str::random(40), 'p256dh-key', 'auth-token', 'aes128gcm');
}

/**
 * Справочник препаратов, виды фактов и правила проверки из файлов приложения, как на стенде.
 * Находки у уже существующих назначений после этого пересчитаны.
 */
function loadSafetyRules(): void
{
    expect(Artisan::call('app:drugs:import'))->toBe(0)
        ->and(Artisan::call('app:rules:import'))->toBe(0);
}

/**
 * Диагноз пациента по МКБ-10 сегодняшним числом, поставил его лечащий врач. Справочник МКБ-10
 * в тестах не загружается: код создаётся, если его нет. Находки не пересчитываются.
 */
function diagnose(User $patient, string $code, bool $main = true): Diagnosis
{
    Icd10Code::query()->firstOrCreate(['code' => $code], ['name' => 'Диагноз '.$code]);

    return $patient->diagnoses()->create([
        'icd10_code' => $code,
        'is_main' => $main,
        'established_on' => DisplayTime::today()->toDateString(),
        'created_by_id' => clinician($patient)->id,
    ]);
}

/**
 * Факт о пациенте по коду вида на сегодня, внёс лечащий врач. Нужны загруженные виды фактов
 * (loadSafetyRules). Находки не пересчитываются.
 */
function observe(User $patient, string $type, bool|int|float|string $value, ?string $comment = null, ?string $on = null): Observation
{
    $observationType = ObservationType::query()->where('code', $type)->sole();

    return $patient->observations()->create([
        'observation_type_id' => $observationType->id,
        $observationType->value_type->column() => $value,
        'comment' => $comment,
        'observed_on' => $on ?? DisplayTime::today()->toDateString(),
        'recorded_by_id' => clinician($patient)->id,
    ]);
}

/**
 * Лечащий врач пациента, а если его нет — новый врач.
 */
function clinician(User $patient): User
{
    return $patient->currentDoctor ?? User::factory()->doctor()->create();
}

/**
 * Назначение врача на препарат справочника по коду (самая малая дозировка, утром) или свой
 * препарат пациента. Нужен загруженный справочник. Находки не пересчитываются.
 */
function takes(User $patient, string $drug, bool $own = false): Prescription
{
    $catalog = Drug::query()->where('code', $drug)->sole();

    return $own
        ? ownPrescription($patient, $catalog)
        : timedPrescription($patient, $catalog->strengths()->orderBy('mg')->firstOrFail(), attributes: ['prescriber_id' => clinician($patient)->id]);
}
