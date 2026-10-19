<?php

use App\Models\Drug;
use App\Models\DrugStrength;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/**
 * Запись справочника в формате файла.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function drugRecord(array $overrides = []): array
{
    return [...[
        'code' => 'sertraline',
        'name' => 'Сертралин',
        'inn' => 'сертралин',
        'atc' => 'N06AB06',
        'dosage_form' => 'таблетки, покрытые плёночной оболочкой',
        'strengths' => [
            ['mg' => 50, 'divisible' => true],
            ['mg' => 100, 'divisible' => false],
        ],
        'regimen' => '1 раз в сутки утром или вечером, независимо от еды.',
        'patient_instruction' => 'Независимо от еды.',
        'missed_dose' => 'Пропущенную дозу не принимайте.',
        'window_hours' => null,
        'source' => [
            'trade_name' => 'Золофт',
            'document' => 'Листок-вкладыш из ГРЛС',
            'registration_number' => 'ЛП-№(002851)-(РГ-RU)',
            'url' => 'https://grls.minzdrav.gov.ru/InstrImg/zoloft.pdf',
            'edition' => 'выложен 25.06.2026',
        ],
    ], ...$overrides];
}

/**
 * Записывает справочник во временный файл и возвращает путь к нему.
 *
 * @param  list<mixed>|string  $drugs  Записи или готовый текст файла.
 */
function drugsFile(array|string $drugs): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'drugs');
    file_put_contents($path, is_string($drugs) ? $drugs : Yaml::dump($drugs, 4));

    return $path;
}

/**
 * Загружает справочник из записей и возвращает вывод команды. Строки «Изменены … код»
 * проверяются по выводу целиком: expectsOutputToContain сопоставляет одну строку только
 * с одним ожиданием.
 *
 * @param  list<mixed>  $drugs
 */
function importDrugs(array $drugs): string
{
    expect(Artisan::call('app:drugs:import', ['path' => drugsFile($drugs)]))->toBe(0);

    return Artisan::output();
}

test('catalog file loads five drugs', function () {
    $this->artisan('app:drugs:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Препаратов в файле: 5.');

    expect(Drug::query()->orderBy('id')->pluck('code')->all())
        ->toBe(['maritupirdine', 'escitalopram', 'sertraline', 'paroxetine', 'hydroxyzine'])
        ->and(Drug::query()->where('is_active', false)->exists())->toBeFalse()
        ->and(Drug::query()->whereNotNull('window_hours')->exists())->toBeFalse();

    $aviandr = Drug::query()->where('code', 'maritupirdine')->sole();

    expect($aviandr->name)->toBe('Авиандр')
        ->and($aviandr->inn)->toBe('маритупирдин')
        ->and($aviandr->source_trade_name)->toBe('Авиандр')
        ->and($aviandr->source_registration_number)->toBe('ЛП-№(002445)-(РГ-RU)')
        ->and($aviandr->source_url)->toStartWith('https://grls.minzdrav.gov.ru/')
        ->and($aviandr->strengths->map->only('mg', 'divisible')->all())->toBe([['mg' => '20.00', 'divisible' => false]]);

    $sertraline = Drug::query()->where('code', 'sertraline')->sole();

    expect($sertraline->strengths()->orderBy('mg')->get()->map->only('mg', 'divisible')->all())->toBe([
        ['mg' => '50.00', 'divisible' => true],
        ['mg' => '100.00', 'divisible' => false],
    ]);
});

test('repeated import of the same file changes nothing', function () {
    $this->artisan('app:drugs:import')->assertSuccessful();
    $drugs = Drug::query()->orderBy('id')->get(['id', 'updated_at'])->toArray();
    $strengths = DrugStrength::query()->orderBy('id')->get(['id', 'updated_at'])->toArray();

    $this->travel(1)->hour();

    $this->artisan('app:drugs:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Справочник не изменился. Препаратов в файле: 5.');

    expect(Drug::query()->orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($drugs)
        ->and(DrugStrength::query()->orderBy('id')->get(['id', 'updated_at'])->toArray())->toBe($strengths);
});

test('changed field updates the drug in place', function () {
    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord()])])->assertSuccessful();
    $id = Drug::query()->sole()->id;

    $output = importDrugs([drugRecord(['regimen' => 'Новый режим.', 'window_hours' => 6])]);

    expect($output)->toMatch('/Изменены\W+sertraline\b/u');

    $drug = Drug::query()->sole();

    expect($drug->id)->toBe($id)
        ->and($drug->regimen)->toBe('Новый режим.')
        ->and($drug->window_hours)->toBe(6);
});

test('changed strength updates the drug', function () {
    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord()])])->assertSuccessful();
    $strength = DrugStrength::query()->where('mg', 100)->sole();

    $output = importDrugs([drugRecord(['strengths' => [
        ['mg' => 50, 'divisible' => true],
        ['mg' => 100, 'divisible' => true],
    ]])]);

    expect($output)->toMatch('/Изменены\W+sertraline\b/u')
        ->and($strength->fresh()?->divisible)->toBeTrue()
        ->and(DrugStrength::query()->count())->toBe(2);
});

test('drug removed from the file becomes inactive and returns when added back', function () {
    $escitalopram = drugRecord(['code' => 'escitalopram', 'name' => 'Эсциталопрам']);

    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord(), $escitalopram])])->assertSuccessful();

    expect(importDrugs([drugRecord()]))->toMatch('/Выключены\W+escitalopram\b/u')
        ->and(Drug::query()->where('code', 'escitalopram')->sole()->is_active)->toBeFalse()
        ->and(Drug::query()->where('code', 'sertraline')->sole()->is_active)->toBeTrue();

    expect(importDrugs([drugRecord(), $escitalopram]))->toMatch('/Изменены\W+escitalopram\b/u')
        ->and(Drug::query()->where('code', 'escitalopram')->sole()->is_active)->toBeTrue()
        ->and(Drug::query()->count())->toBe(2);
});

test('strength removed from the file becomes inactive', function () {
    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord()])])->assertSuccessful();
    $strength50 = DrugStrength::query()->where('mg', 50)->sole();

    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord(['strengths' => [['mg' => 50, 'divisible' => true]]])])])
        ->assertSuccessful();

    expect(DrugStrength::query()->where('mg', 100)->sole()->is_active)->toBeFalse()
        ->and($strength50->fresh()?->is_active)->toBeTrue();
});

test('fractional strength is stored', function () {
    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord(['strengths' => [['mg' => 12.5, 'divisible' => false]]])])])
        ->assertSuccessful();

    expect(DrugStrength::query()->sole()->label())->toBe('12,5 мг');
});

test('invalid file stops the import without changes', function (array $drugs, string $message) {
    $this->artisan('app:drugs:import', ['path' => drugsFile([drugRecord(['code' => 'paroxetine'])])])->assertSuccessful();
    $before = Drug::query()->get()->toArray();

    $this->artisan('app:drugs:import', ['path' => drugsFile($drugs)])
        ->assertFailed()
        ->expectsOutputToContain('в базе ничего не изменилось')
        ->expectsOutputToContain($message);

    expect(Drug::query()->get()->toArray())->toBe($before);
})->with([
    'missing required field' => fn () => [
        [Arr::except(drugRecord(), 'name')],
        'Препарат sertraline: Поле name обязательно',
    ],
    'missing source field' => fn () => [
        [drugRecord(['source' => Arr::except(drugRecord()['source'], 'url')])],
        'Препарат sertraline: Поле source.url обязательно',
    ],
    'missing code' => fn () => [
        [Arr::except(drugRecord(), 'code')],
        'Препарат №1: Поле code обязательно',
    ],
    'repeated code' => fn () => [
        [drugRecord(), drugRecord(['name' => 'Ещё сертралин'])],
        'Препарат sertraline: код повторяется в записях 1, 2',
    ],
    'zero strength' => fn () => [
        [drugRecord(['strengths' => [['mg' => 0, 'divisible' => true]]])],
        'Препарат sertraline: Значение поля strengths.0.mg должно быть больше 0',
    ],
    'negative strength' => fn () => [
        [drugRecord(['strengths' => [['mg' => 50, 'divisible' => true], ['mg' => -10, 'divisible' => true]]])],
        'Значение поля strengths.1.mg должно быть больше 0',
    ],
    'no strengths' => fn () => [
        [drugRecord(['strengths' => []])],
        'Препарат sertraline: Поле strengths обязательно',
    ],
    'divisibility is not set' => fn () => [
        [drugRecord(['strengths' => [['mg' => 50]]])],
        'Поле strengths.0.divisible обязательно',
    ],
    'zero window' => fn () => [
        [drugRecord(['window_hours' => 0])],
        'Препарат sertraline: Значение поля window_hours должно быть не меньше 1',
    ],
    'negative window' => fn () => [
        [drugRecord(['window_hours' => -3])],
        'Значение поля window_hours должно быть не меньше 1',
    ],
    'window is not a number' => fn () => [
        [drugRecord(['window_hours' => 'до сна'])],
        'Значение поля window_hours должно быть целым числом',
    ],
    'window key is missing' => fn () => [
        [Arr::except(drugRecord(), 'window_hours')],
        'Поле window_hours обязательно',
    ],
    'misspelled field' => fn () => [
        [[...drugRecord(), 'windows_hours' => 6]],
        'Препарат sertraline: неизвестное поле windows_hours',
    ],
    'invalid code' => fn () => [
        [drugRecord(['code' => 'Sertraline 50'])],
        'Значение поля code имеет некорректный формат',
    ],
]);

test('valid records are not saved when another record is invalid', function () {
    $this->artisan('app:drugs:import', ['path' => drugsFile([
        drugRecord(),
        drugRecord(['code' => 'paroxetine', 'window_hours' => 0]),
    ])])->assertFailed();

    expect(Drug::query()->count())->toBe(0);
});

test('file that can not be read stops the import', function (string $content, string $message) {
    $this->artisan('app:drugs:import', ['path' => drugsFile($content)])
        ->assertFailed()
        ->expectsOutputToContain($message);

    expect(Drug::query()->count())->toBe(0);
})->with([
    'broken yaml' => ["- code: sertraline\n  name: [Сертралин\n", 'Ошибка разметки YAML'],
    'not a list' => ["code: sertraline\nname: Сертралин\n", 'Файл должен содержать список препаратов'],
    'empty file' => ['', 'Файл должен содержать список препаратов'],
]);

test('missing file stops the import', function () {
    $this->artisan('app:drugs:import', ['path' => '/nonexistent/drugs.yaml'])
        ->assertFailed()
        ->expectsOutputToContain('Файл /nonexistent/drugs.yaml не найден');
});
