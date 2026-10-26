<?php

use App\Enums\DoctorKind;
use App\Enums\ScaleRater;
use App\Models\Scale;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/**
 * Каталог шкал во временной папке с копиями файлов приложения; $change правит содержимое по коду.
 *
 * @param  array<string, Closure(array<string, mixed>): (array<string, mixed>|null)>  $change  код => правка, null — без файла
 * @param  list<string>  $codes
 */
function scalesDirectory(array $change = [], array $codes = ['gad7', 'phq9']): string
{
    $directory = sys_get_temp_dir().'/scales-'.uniqid();
    mkdir($directory);

    foreach ($codes as $code) {
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
        ->expectsOutputToContain('Шкалы обновлены. Шкал в файлах: 4.');

    $gad7 = Scale::query()->where('code', 'gad7')->sole();
    $version = $gad7->version;

    $hars = Scale::query()->where('code', 'hars')->sole();
    $hads = Scale::query()->where('code', 'hads')->sole();

    expect(Scale::query()->active()->forPatients()->orderBy('position')->pluck('code')->all())->toBe(['gad7', 'phq9'])
        ->and($gad7->rater)->toBe(ScaleRater::Patient)
        ->and($hars->rater)->toBe(ScaleRater::Doctor)
        ->and($hars->patient_title)->toBeNull()
        ->and($hars->schedule_days)->toBeNull()
        ->and($hars->items)->toHaveCount(14)
        ->and($hars->items[0])->toBe(['text' => 'Тревожное настроение', 'hint' => 'озабоченность, ожидание наихудшего, тревожные опасения, раздражительность', 'highlight' => false])
        ->and($hars->maxTotal())->toBe(56)
        ->and($hars->band(25))->toBe('тяжелая степень выраженности тревожного расстройства')
        // Шкалы врача по виду врача: психиатр заполняет HARS, терапевт — HADS.
        ->and($hars->doctor_kind)->toBe(DoctorKind::Psychiatrist)
        ->and($hads->doctor_kind)->toBe(DoctorKind::Therapist)
        ->and(Scale::query()->active()->forDoctors(DoctorKind::Psychiatrist)->pluck('code')->all())->toBe(['hars'])
        ->and(Scale::query()->active()->forDoctors(DoctorKind::Therapist)->pluck('code')->all())->toBe(['hads'])
        // У HADS варианты свои у каждого утверждения и суммы по подшкалам.
        ->and($hads->options)->toBe([])
        ->and($hads->bands)->toBe([])
        ->and($hads->items)->toHaveCount(14)
        ->and($hads->items[1]['subscale'])->toBe('depression')
        ->and($hads->items[1]['options'][3])->toBe(['score' => 3, 'label' => 'это совсем не так'])
        ->and($hads->optionLabel(3, 1))->toBe('все время')
        ->and($hads->optionLabel(3, 2))->toBe('это совсем не так')
        ->and($hads->maxTotal())->toBe(42)
        ->and($hads->maxTotal('anxiety'))->toBe(21)
        ->and($hads->subscaleName('depression'))->toBe('HADS, депрессия')
        // Тревога: 1, 3, 5, 7, 9, 11, 13-е утверждения — 3 + 3 + 3 + 0 + 0 + 3 + 0; депрессия — остальные.
        ->and($hads->score([3, 0, 3, 0, 3, 0, 0, 0, 0, 3, 3, 0, 0, 2]))->toBe([
            'total' => 17,
            'band' => 'клинически выраженная тревога; норма',
            'subscores' => [
                ['code' => 'anxiety', 'total' => 12, 'band' => 'клинически выраженная тревога'],
                ['code' => 'depression', 'total' => 5, 'band' => 'норма'],
            ],
        ])
        ->and($gad7->items)->toHaveCount(7)
        ->and($gad7->items[0])->toBe(['text' => 'Чувство тревоги или раздражения', 'highlight' => false])
        ->and($gad7->options[3])->toBe(['score' => 3, 'label' => 'Почти каждый день'])
        ->and($gad7->schedule_days)->toBe([0, 7, 14, 28])
        ->and($gad7->schedule_then_every)->toBe(28)
        ->and($gad7->source_url)->toBe('https://doi.org/10.17759/cpp.2023310402')
        ->and(Scale::query()->where('code', 'phq9')->sole()->items[8]['highlight'])->toBeTrue();

    $this->artisan('app:scales:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Шкалы не изменились. Шкал в файлах: 4.');

    expect(Scale::query()->count())->toBe(4)
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

test('broken subscales and own options name the problem and load nothing', function (string $code, Closure $change, string $error) {
    $result = Artisan::call('app:scales:import', ['path' => scalesDirectory([$code => $change], ['gad7', 'phq9', 'hads'])]);
    $output = preg_replace('/\s+/u', ' ', Artisan::output());

    expect($result)->toBe(1)
        ->and($output)->toContain('Шкалы не загружены')->toContain($error)
        ->and(Scale::query()->count())->toBe(0);
})->with([
    'subscale bands short of its largest sum' => ['hads', function (array $data): array {
        $data['subscales'][0]['bands'][2]['max'] = 20;

        return $data;
    }, 'hads.yaml: подшкала anxiety, последняя степень должна заканчиваться наибольшей суммой 21'],
    'item of an unknown subscale' => ['hads', function (array $data): array {
        $data['items'][0]['subscale'] = 'mood';

        return $data;
    }, 'items.0.subscale'],
    'repeated score within an item' => ['hads', function (array $data): array {
        $data['items'][0]['options'][1]['score'] = 3;

        return $data;
    }, 'hads.yaml: вопрос 1: баллы вариантов ответа повторяются'],
    'unknown field in item options' => ['hads', function (array $data): array {
        $data['items'][0]['options'][0]['extra'] = 'опечатка';

        return $data;
    }, 'hads.yaml: неизвестное поле items.1.options.1.extra'],
    'shared options next to own options' => ['hads', fn (array $data): array => [...$data, 'options' => [['score' => 0, 'label' => 'нет'], ['score' => 1, 'label' => 'да']]], 'hads.yaml: Значение поля options запрещено'],
    'subscale without items' => ['hads', function (array $data): array {
        $data['subscales'][] = ['code' => 'stress', 'name' => 'стресс', 'bands' => [['min' => 0, 'max' => 0, 'label' => 'нет']]];

        return $data;
    }, 'hads.yaml: подшкала stress: ни один вопрос в неё не входит'],
    'own options on a patient scale' => ['gad7', function (array $data): array {
        $data['items'][0]['options'] = $data['options'];

        return $data;
    }, 'items.0.options'],
    'subscales on a patient scale' => ['gad7', fn (array $data): array => [...$data, 'subscales' => [['code' => 'a', 'name' => 'a', 'bands' => []], ['code' => 'b', 'name' => 'b', 'bands' => []]]], 'gad7.yaml: Значение поля subscales запрещено'],
    'doctor kind on a patient scale' => ['gad7', fn (array $data): array => [...$data, 'doctor_kind' => 'therapist'], 'gad7.yaml: Значение поля doctor_kind запрещено'],
]);

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
