<?php

use App\Actions\RecordAsNeededDose;
use App\Models\AsNeededDose;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

test('simultaneous as-needed doses do not exceed the daily limit without confirmation', function () {
    $patient = User::factory()->patient()->create();
    $prescription = Prescription::factory()
        ->for($patient, 'patient')
        ->for(catalogDrug('Гидроксизин')->strengths()->sole(), 'strength')
        ->asNeeded('1', 1)
        ->create(['starts_on' => now()->subWeek()->toDateString()]);

    $dir = storage_path('framework/testing/as-needed-race-'.uniqid());
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
                app(RecordAsNeededDose::class)->handle($patient, $prescription);
                $result = 'recorded';
            } catch (ValidationException) {
                $result = 'limit';
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
    sort($results);

    expect($results)->toBe(['limit', 'limit', 'recorded'])
        ->and(AsNeededDose::query()->count())->toBe(1);
});
