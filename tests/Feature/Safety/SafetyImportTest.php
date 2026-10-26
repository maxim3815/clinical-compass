<?php

use App\Models\Drug;
use App\Models\DrugGroup;
use App\Models\Icd10Code;
use App\Models\ObservationType;
use App\Models\SafetyFinding;
use App\Models\SafetyRule;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/**
 * Каталог правил во временной папке: имя файла => содержимое (массив или готовый текст).
 *
 * @param  array<string, array<string, mixed>|string>  $files
 */
function rulesDirectory(array $files): string
{
    $directory = sys_get_temp_dir().'/rules-'.uniqid();
    mkdir($directory);

    foreach ($files as $name => $content) {
        file_put_contents("{$directory}/{$name}", is_string($content) ? $content : Yaml::dump($content, 6));
    }

    return $directory;
}

/**
 * Правило в формате файла.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ruleRecord(array $overrides = []): array
{
    return [...[
        'id' => 'gfr',
        'type' => 'contraindication',
        'level' => 'contraindicated',
        'condition' => "fact('gfr') < 60",
        'text' => 'Противопоказан при СКФ < 60 мл/мин.',
        'source' => [
            'document' => 'ОХЛП «Авиандр»',
            'section' => '4.3. Противопоказания',
            'url' => 'https://lk.regmed.ru/Register/EAEU_SmPC',
        ],
    ], ...$overrides];
}

/**
 * Загружает правила препарата «Авиандр» из записей и возвращает код возврата команды и вывод.
 *
 * @param  list<array<string, mixed>>  $rules
 * @return array{int, string}
 */
function importRules(array $rules, string $drug = 'maritupirdine', ?string $file = null): array
{
    $code = Artisan::call('app:rules:import', ['path' => rulesDirectory([($file ?? $drug).'.yaml' => ['drug' => $drug, 'rules' => $rules]])]);

    return [$code, Artisan::output()];
}

beforeEach(fn () => expect(Artisan::call('app:drugs:import'))->toBe(0));

test('rule files load all rules, fact types and drug groups', function () {
    $this->artisan('app:rules:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правил в файлах: 62.');

    expect(SafetyRule::query()->active()->count())->toBe(62)
        ->and(ObservationType::query()->orderBy('position')->pluck('code')->first())->toBe('pregnancy')
        ->and(DrugGroup::query()->pluck('code')->sort()->values()->all())->toBe(['anticoagulant', 'bleeding', 'cyp2c19_inhibitor', 'cyp2d6_inhibitor', 'maoi', 'maoi_irreversible', 'maoi_reversible', 'qt_prolonging', 'sedative', 'serotonergic'])
        ->and(Drug::query()->where('code', 'escitalopram')->sole()->groups)->toBe(['serotonergic', 'cyp2d6_inhibitor', 'qt_prolonging']);

    $gfr = SafetyRule::query()->where('code', 'maritupirdine.gfr')->sole();

    expect($gfr->condition)->toBe("fact('gfr') < 60")
        ->and($gfr->source_section)->toBe('4.3. Противопоказания')
        ->and($gfr->source_url)->toStartWith('https://');

    $this->artisan('app:rules:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правила не изменились. Правил в файлах: 62.');
});

test('texts of the rule files are not cut short', function () {
    $this->artisan('app:rules:import')->assertSuccessful();

    // « #» в строке без кавычек YAML читает как начало комментария, и конец текста молча пропадает.
    SafetyRule::query()->active()->get()->each(function (SafetyRule $rule) {
        foreach (array_filter([$rule->text, $rule->advice, $rule->source_quote]) as $text) {
            expect($text)->toMatch('/[.…]$/u', "{$rule->code}: «{$text}»");
        }
    });
});

test('changed rule gets a new version and removed rule is deactivated', function () {
    [$code] = importRules([ruleRecord(), ruleRecord(['id' => 'epilepsy', 'condition' => "fact('epilepsy')"])]);
    expect($code)->toBe(0);
    $version = SafetyRule::query()->where('code', 'maritupirdine.gfr')->value('version');

    [$code, $output] = importRules([ruleRecord(['text' => 'Противопоказан при почечной недостаточности.'])]);

    expect($code)->toBe(0)
        ->and($output)->toContain('maritupirdine.gfr')->toContain('maritupirdine.epilepsy')
        ->and(SafetyRule::query()->where('code', 'maritupirdine.gfr')->value('version'))->not->toBe($version)
        ->and(SafetyRule::query()->where('code', 'maritupirdine.epilepsy')->sole()->is_active)->toBeFalse()
        ->and(SafetyRule::query()->count())->toBe(2);
});

test('condition may name a drug from the list of drugs from other doctors', function () {
    [$code] = importRules([ruleRecord(['condition' => "taking('tamoxifen')"])]);

    expect($code)->toBe(0);
});

test('invalid rule stops the import without changes', function (array $rule, string $message) {
    importRules([ruleRecord()]);
    $before = SafetyRule::query()->get()->toArray();

    [$code, $output] = importRules([ruleRecord(['id' => 'other']), $rule]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Правила не загружены')->toContain($message)
        ->and(SafetyRule::query()->get()->toArray())->toBe($before);
})->with([
    'unknown fact' => [ruleRecord(['condition' => "fact('creatinine') > 100"]), 'вида факта creatinine нет'],
    'unknown drug' => [ruleRecord(['condition' => "taking('agomelatine')"]), 'препарата agomelatine нет в справочнике'],
    'unknown group' => [ruleRecord(['condition' => "taking_group('triptans')"]), 'группы препаратов triptans нет'],
    'syntax error' => [ruleRecord(['condition' => "fact('gfr') <"]), 'условие не разбирается'],
    'variable' => [ruleRecord(['condition' => 'patient.delete()']), 'условие не разбирается'],
    'unknown function' => [ruleRecord(['condition' => "constant('PHP_VERSION')"]), 'условие не разбирается'],
    'argument is not a string' => [ruleRecord(['condition' => 'fact(1 + 1)']), 'одна строка в кавычках'],
    'bad level' => [ruleRecord(['level' => 'red']), 'level'],
    'indication without codes' => [ruleRecord(['type' => 'indication', 'condition' => null]), 'codes'],
    'bad icd10 code' => [ruleRecord(['type' => 'indication', 'condition' => null, 'codes' => ['F41,1']]), 'F41,1'],
    'misspelled field' => [ruleRecord(['advise' => 'Снизить дозу']), 'неизвестное поле advise'],
    'duplicate id' => [ruleRecord(['id' => 'other']), 'идентификатор повторяется'],
]);

test('rules file must belong to a catalog drug and be named after it', function () {
    [$code, $output] = importRules([ruleRecord()], 'agomelatine');
    expect($code)->toBe(1)->and($output)->toContain('препарата agomelatine нет в справочнике');

    [$code, $output] = importRules([ruleRecord()], 'fluoxetine');
    expect($code)->toBe(1)->and($output)->toContain('Правила пишутся только для препаратов справочника, а не списка препаратов от других врачей');

    [$code, $output] = importRules([ruleRecord()], 'maritupirdine', 'aviandr');
    expect($code)->toBe(1)->and($output)->toContain('должен называться maritupirdine.yaml');
});

test('rules import resyncs findings of current prescriptions', function () {
    $patient = User::factory()->patientOf(User::factory()->doctor()->create())->create();
    importRules([ruleRecord()]);
    observe($patient, 'gfr', 50);
    takes($patient, 'maritupirdine');

    expect(SafetyFinding::query()->count())->toBe(0);

    importRules([ruleRecord(['text' => 'СКФ ниже 60.'])]);

    expect(SafetyFinding::query()->sole()->evidence)->toBe(['СКФ 50 мл/мин/1,73 м² на '.DisplayTime::today()->format('d.m.Y')]);
});

test('drug groups are checked against the groups file', function () {
    $drugs = (string) tempnam(sys_get_temp_dir(), 'drugs');
    $catalog = Yaml::parseFile(base_path('database/data/drugs.yaml'));
    $catalog[0]['groups'] = ['triptans'];
    file_put_contents($drugs, Yaml::dump($catalog, 4));

    $this->artisan('app:drugs:import', ['path' => $drugs])
        ->assertFailed()
        ->expectsOutputToContain('группы triptans нет в файле групп');
});

test('icd10 file loads codes and rejects broken lines', function () {
    $file = (string) tempnam(sys_get_temp_dir(), 'icd10');
    file_put_contents($file, "code,name\nF41,Другие тревожные расстройства\nF41.1,Генерализованное тревожное расстройство\n");

    $this->artisan('app:icd10:import', ['path' => $file])
        ->assertSuccessful()
        ->expectsOutputToContain('Кодов в файле: 2.');

    expect(Icd10Code::query()->find('F41.1')?->name)->toBe('Генерализованное тревожное расстройство');

    file_put_contents($file, "code,name\nF41.1,Генерализованное тревожное расстройство\nF41-F48,Блок\n");

    $this->artisan('app:icd10:import', ['path' => $file])
        ->assertFailed()
        ->expectsOutputToContain('Строка 3');
});

test('icd10 file in the repository holds the whole classification', function () {
    $this->artisan('app:icd10:import')->assertSuccessful();

    expect(Icd10Code::query()->count())->toBeGreaterThan(14000)
        ->and(Icd10Code::query()->find('F31.0')?->name)->toBe('Биполярное аффективное расстройство, текущий эпизод гипомании')
        ->and(Icd10Code::query()->find('Z73.0')?->name)->toBe('Переутомление');
});
