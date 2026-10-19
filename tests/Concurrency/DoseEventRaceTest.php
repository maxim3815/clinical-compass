<?php

use App\Actions\SyncDoseEvents;
use App\Models\DoseEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('simultaneous syncs of one patient do not duplicate dose events', function () {
    $patient = User::factory()->patient()->create();
    timedPrescription($patient, catalogDrug('Гидроксизин')->strengths()->sole(), ['morning' => '08:00', 'evening' => '20:00'], [
        'starts_on' => now()->subWeek()->toDateString(),
        'effective_at' => now()->subWeek(),
    ]);

    $dir = storage_path('framework/testing/dose-event-race-'.uniqid());
    File::ensureDirectoryExists($dir);

    // Каждый процесс откроет своё соединение с БД.
    DB::disconnect();

    $racers = ['racer1', 'racer2', 'racer3'];
    $children = [];

    foreach ($racers as $racer) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (! file_exists("{$dir}/start")) {
                usleep(200);
            }

            try {
                app(SyncDoseEvents::class)->handle($patient);
                $result = 'synced';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$racer}", $result);
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

    $results = array_map(fn (string $racer) => file_get_contents("{$dir}/{$racer}"), $racers);
    File::deleteDirectory($dir);

    // Сегодня и завтра по два приёма.
    expect($results)->toBe(['synced', 'synced', 'synced'])
        ->and(DoseEvent::query()->count())->toBe(4);
});
