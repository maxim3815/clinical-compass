<?php

use App\Actions\InvitePatient;
use App\Actions\RegisterInvitedPatient;
use App\Enums\UserRole;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('two simultaneous registrations by one invitation create one patient', function () {
    $doctor = User::factory()->doctor()->create();
    $token = app(InvitePatient::class)->handle($doctor)->token;

    $dir = storage_path('framework/testing/invitation-race-'.uniqid());
    File::ensureDirectoryExists($dir);

    // Каждый процесс откроет своё соединение с БД.
    DB::disconnect();

    $children = [];

    foreach (['racer1', 'racer2'] as $login) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (! file_exists("{$dir}/start")) {
                usleep(200);
            }

            try {
                app(RegisterInvitedPatient::class)->handle($token, 'Новиков Артём Сергеевич', $login, 'secret-password');
                $result = 'registered';
            } catch (AccessLinkUnavailable) {
                $result = 'unavailable';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$login}", $result);
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

    $results = [file_get_contents("{$dir}/racer1"), file_get_contents("{$dir}/racer2")];
    sort($results);
    File::deleteDirectory($dir);

    expect($results)->toBe(['registered', 'unavailable'])
        ->and(User::query()->where('role', UserRole::Patient)->count())->toBe(1);
});
