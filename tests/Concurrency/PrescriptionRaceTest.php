<?php

use App\Actions\PrescribeDrug;
use App\Enums\PrescriptionKind;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

test('two simultaneous prescriptions of the same drug create one', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();
    $strengthId = catalogDrug('Гидроксизин')->strengths()->sole()->id;

    $dir = storage_path('framework/testing/prescription-race-'.uniqid());
    File::ensureDirectoryExists($dir);

    // Каждый процесс откроет своё соединение с БД.
    DB::disconnect();

    $children = [];

    foreach (['racer1', 'racer2'] as $racer) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (! file_exists("{$dir}/start")) {
                usleep(200);
            }

            try {
                app(PrescribeDrug::class)->handle($doctor, $patient, new PrescriptionData(
                    strength: DrugStrength::query()->findOrFail($strengthId),
                    kind: PrescriptionKind::Regular,
                    intakes: ['morning' => '1.0'],
                    tablets: null,
                    maxDailyDoses: null,
                    instruction: null,
                    startsOn: CarbonImmutable::parse('2026-10-19'),
                    endsOn: null,
                ));
                $result = 'prescribed';
            } catch (ValidationException) {
                $result = 'rejected';
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

    $results = [file_get_contents("{$dir}/racer1"), file_get_contents("{$dir}/racer2")];
    sort($results);
    File::deleteDirectory($dir);

    expect($results)->toBe(['prescribed', 'rejected'])
        ->and(Prescription::query()->count())->toBe(1);
});
