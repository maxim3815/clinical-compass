<?php

use App\Actions\SnoozeDoses;
use App\Actions\SyncDoseEvents;
use App\Actions\TakeAllDoses;
use App\Actions\TakeDose;
use App\Enums\DoseEventStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

test('taking a dose from the push and the app at once with a snooze and the reminder run ends taken', function () {
    Notification::fake();
    $patient = User::factory()->patient()->create();
    timedPrescription($patient, catalogDrug('Эсциталопрам', [10 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => now()->subWeek()->toDateString(),
        'effective_at' => now()->subWeek(),
    ]);
    app(SyncDoseEvents::class)->handle($patient);
    $dose = $patient->doseEvents()->orderBy('scheduled_at')->firstOrFail();
    // Время приёма наступило минуту назад: напоминание и кнопки в нём работают.
    $dose->update(['scheduled_at' => now()->subMinute(), 'window_closes_at' => now()->addHours(6)]);
    subscribeToPush($patient);

    $dir = storage_path('framework/testing/push-dose-race-'.uniqid());
    File::ensureDirectoryExists($dir);

    // Каждый процесс откроет своё соединение с БД.
    DB::disconnect();

    $racers = [
        'push take' => fn () => app(TakeAllDoses::class)->handle($patient, [$dose]),
        'app take' => fn () => app(TakeDose::class)->handle($patient, $dose),
        'push snooze' => fn () => app(SnoozeDoses::class)->handle($patient, [$dose]),
        'reminders' => fn () => Artisan::call('app:dose-events:send-reminders'),
    ];
    $children = [];

    foreach ($racers as $racer => $action) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (! file_exists("{$dir}/start")) {
                usleep(200);
            }

            try {
                $action();
                $result = 'done';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/".md5($racer), $result);
            DB::disconnect();

            // Завершаемся без shutdown-обработчиков PHPUnit, унаследованных от родителя.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    touch("{$dir}/start");

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = array_map(fn (string $racer) => file_get_contents("{$dir}/".md5($racer)), array_keys($racers));
    File::deleteDirectory($dir);

    $dose->refresh();

    // Блокировки идут в одном порядке: ни взаимной блокировки, ни ошибки. Отметка одна,
    // а отсрочка, пришедшая раньше отметки, ею сброшена.
    expect($results)->toBe(['done', 'done', 'done', 'done'])
        ->and($dose->status)->toBe(DoseEventStatus::Taken)
        ->and($dose->taken_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dose->snoozed_until)->toBeNull();
});
