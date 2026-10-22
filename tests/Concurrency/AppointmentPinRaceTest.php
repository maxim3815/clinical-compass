<?php

use App\Actions\EnterAppointmentPin;
use App\Actions\IssueAppointmentPin;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\AccessLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('two simultaneous entries of one pin give one registration link', function () {
    $doctor = User::factory()->doctor()->create();
    $pin = app(IssueAppointmentPin::class)->handle($doctor)->pin;

    $dir = storage_path('framework/testing/appointment-pin-race-'.uniqid());
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
                $token = app(EnterAppointmentPin::class)->handle($doctor, $pin)->token;
                $result = "entered {$token}";
            } catch (AccessLinkUnavailable) {
                $result = 'unavailable';
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

    expect($results[0])->toStartWith('entered ')
        ->and(array_slice($results, 1))->toBe(['unavailable', 'unavailable'])
        ->and(AccessLink::query()->sole()->token_hash)->toBe(AccessLink::hashToken(substr($results[0], 8)));
});
