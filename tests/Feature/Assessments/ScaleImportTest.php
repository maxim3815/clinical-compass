<?php

use App\Enums\ScaleRater;
use App\Models\Scale;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/**
 * Каталог шкал во временной папке с копиями файлов приложения; $change правит содержимое по коду.
 *
 * @param  array<string, Closure(array<string, mixed>): (array<string, mixed>|null)>  $change  код => правка, null — без файла
 */
function scalesDirectory(array $change = []): string
{
    $directory = sys_get_temp_dir().'/scales-'.uniqid();
    mkdir($directory);

    foreach (['gad7', 'phq9'] as $code) {
        $data = Yaml::parseFile(base_path("database/data/scales/{$code}.yaml"));
        $data = isset($change[$code]) ? $change[$code]($data) : $data;

        if ($data !== null) {
            file_put_contents("{$directory}/{$code}.yaml", Yaml::dump($data, 6));
        }
    }

    return $directory;
}

test('scale files load the patient questionnaires and the doctor scale once', function () {
    $this->artisan('app:scales:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Шкалы обновлены. Шкал в файлах: 3.');

    $gad7 = Scale::query()->where('code', 'gad7')->sole();
    $version = $gad7->version;

    $hars = Scale::query()->where('code', 'hars')->sole();

    expect(Scale::query()->active()->forPatients()->orderBy('position')->pluck('code')->all())->toBe(['gad7', 'phq9'])
        ->and($gad7->rater)->toBe(ScaleRater::Patient)
        ->and($hars->rater)->toBe(ScaleRater::Doctor)
        ->and($hars->patient_title)->toBeNull()
        ->and($hars->schedule_days)->toBeNull()
        ->and($hars->items)->toHaveCount(14)
        ->and($hars->items[0])->toBe(['text' => 'Тревожное настроение', 'hint' => 'озабоченность, ожидание наихудшего, тревожные опасения, раздражительность', 'highlight' => false])
        ->and($hars->maxTotal())->toBe(56)
        ->and($hars->band(25))->toBe('тяжелая степень выраженности тревожного расстройства')
        ->and($gad7->items)->toHaveCount(7)
        ->and($gad7->items[0])->toBe(['text' => 'Чувство тревоги или раздражения', 'highlight' => false])
        ->and($gad7->options[3])->toBe(['score' => 3, 'label' => 'Почти каждый день'])
        ->and($gad7->schedule_days)->toBe([0, 7, 14, 28])
        ->and($gad7->schedule_then_every)->toBe(28)
        ->and($gad7->source_url)->toBe('https://doi.org/10.17759/cpp.2023310402')
        ->and(Scale::query()->where('code', 'phq9')->sole()->items[8]['highlight'])->toBeTrue();

    $this->artisan('app:scales:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Шкалы не изменились. Шкал в файлах: 3.');

    expect(Scale::query()->count())->toBe(3)
        ->and($gad7->refresh()->version)->toBe($version);
});

test('changed scale gets a new version and removed scale is deactivated', function () {
    loadScales();
    $version = Scale::query()->where('code', 'gad7')->sole()->version;

    $code = Artisan::call('app:scales:import', ['path' => scalesDirectory([
        'gad7' => fn (array $data): array => [...$data, 'patient_title' => 'Тревога'],
        'phq9' => fn (): null => null,
    ])]);

    $gad7 = Scale::query()->where('code', 'gad7')->sole();

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Изменены')
        ->and($gad7->patient_title)->toBe('Тревога')
        ->and($gad7->version)->not->toBe($version)
        ->and(Scale::query()->where('code', 'phq9')->sole()->is_active)->toBeFalse();
});

test('broken scale file names the problem and loads nothing', function (Closure $change, string $error) {
    $code = Artisan::call('app:scales:import', ['path' => scalesDirectory(['gad7' => $change])]);

    // Длинные строки списка ошибок команда переносит по ширине консоли, точку в конце убирает.
    $output = preg_replace('/\s+/u', ' ', Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toContain('Шкалы не загружены')->toContain($error)
        ->and(Scale::query()->count())->toBe(0);
})->with([
    'gap between bands' => [
        fn (array $data): array => [...$data, 'bands' => [['min' => 0, 'max' => 4, 'label' => 'a'], ['min' => 6, 'max' => 21, 'label' => 'b']]],
        'gad7.yaml: степень 2: должна начинаться с суммы 5, а начинается с 6',
    ],
    'bands short of the largest sum' => [
        fn (array $data): array => [...$data, 'bands' => [['min' => 0, 'max' => 20, 'label' => 'a']]],
        'последняя степень должна заканчиваться наибольшей суммой 21',
    ],
    'unknown field' => [
        fn (array $data): array => [...$data, 'titel' => 'опечатка'],
        'gad7.yaml: неизвестное поле titel',
    ],
    'item without text' => [
        fn (array $data): array => [...$data, 'items' => [...$data['items'], ['highlight' => true]]],
        'items.7.text',
    ],
    'schedule without day 0' => [
        fn (array $data): array => [...$data, 'schedule' => ['days' => [7, 14], 'then_every' => 28]],
        'schedule.days: сроки должны идти по возрастанию и начинаться с дня 0',
    ],
    'file name differs from code' => [
        fn (array $data): array => [...$data, 'code' => 'gad7x'],
        'gad7.yaml: файл шкалы gad7x должен называться gad7x.yaml',
    ],
]);
