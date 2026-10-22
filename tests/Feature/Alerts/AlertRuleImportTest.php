<?php

use App\Enums\AlertLevel;
use App\Models\AlertRule;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => loadScales());

test('alert rule file loads once', function () {
    $this->artisan('app:alerts:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правила алертинга обновлены. Правил в файлах: 1.');

    $rule = AlertRule::query()->sole();

    expect($rule->code)->toBe('suicide_risk')
        ->and($rule->name)->toBe('Суицидальный риск')
        ->and($rule->level)->toBe(AlertLevel::Critical)
        ->and($rule->condition)->toBe("answer('phq9', 9) >= 1")
        ->and($rule->source_url)->toStartWith('https://www.phqscreeners.com/')
        ->and($rule->source_quote)->toContain('answer positively to the 9th question of the PHQ-9');

    $this->artisan('app:alerts:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правила алертинга не изменились. Правил в файлах: 1.');

    expect($rule->refresh()->version)->toBe(AlertRule::query()->sole()->version);
});

test('changed rule gets a new version, a new rule is added by a file and a removed one is deactivated', function () {
    loadAlertRules();
    $version = AlertRule::query()->sole()->version;

    // Порог меняется правкой файла, новое правило — новым файлом, без изменения кода.
    $code = Artisan::call('app:alerts:import', ['path' => alertRulesDirectory(
        fn (array $rule): array => [...$rule, 'condition' => "answer('phq9', 9) >= 2"],
        ['severe_depression.yaml' => [
            'code' => 'severe_depression',
            'name' => 'Тяжёлая депрессия',
            'level' => 'critical',
            'condition' => "total('phq9') >= 20",
            'text' => 'Сумма PHQ-9 в диапазоне тяжёлой депрессии.',
            'source' => ['document' => 'Kroenke et al., 2001', 'section' => 'Table 3', 'url' => 'https://doi.org/10.1046/j.1525-1497.2001.016009606.x'],
        ]],
    )]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Добавлены')->toContain('Изменены')
        ->and(AlertRule::query()->where('code', 'suicide_risk')->sole()->version)->not->toBe($version)
        ->and(AlertRule::query()->active()->count())->toBe(2);

    Artisan::call('app:alerts:import', ['path' => alertRulesDirectory()]);

    expect(AlertRule::query()->where('code', 'severe_depression')->sole()->is_active)->toBeFalse();
});

test('broken rule file names the problem and loads nothing', function (Closure $change, string $error) {
    $code = Artisan::call('app:alerts:import', ['path' => alertRulesDirectory($change)]);

    // Длинные строки списка ошибок команда переносит по ширине консоли, точку в конце убирает.
    $output = preg_replace('/\s+/u', ' ', Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toContain('Правила алертинга не загружены')->toContain($error)
        ->and(AlertRule::query()->count())->toBe(0);
})->with([
    'unknown scale' => [
        fn (array $rule): array => [...$rule, 'condition' => "answer('phq10', 9) >= 1"],
        'suicide_risk.yaml: condition: опросника phq10 нет среди загруженных',
    ],
    'unknown item' => [
        fn (array $rule): array => [...$rule, 'condition' => "answer('phq9', 10) >= 1"],
        'в опроснике phq9 нет вопроса 10, вопросов 9',
    ],
    'unknown function' => [
        fn (array $rule): array => [...$rule, 'condition' => "fact('gfr') < 30"],
        'suicide_risk.yaml: condition:',
    ],
    'no answers' => [
        fn (array $rule): array => [...$rule, 'condition' => '1 > 0'],
        'условие должно смотреть на ответы: answer() или total()',
    ],
    'unknown level' => [
        fn (array $rule): array => [...$rule, 'level' => 'urgent'],
        'suicide_risk.yaml: Значение поля level отсутствует в списке разрешённых',
    ],
    'typo in a field' => [
        fn (array $rule): array => [...$rule, 'txt' => 'опечатка'],
        'suicide_risk.yaml: неизвестное поле txt',
    ],
    'file named not by code' => [
        fn (array $rule): array => [...$rule, 'code' => 'suicide'],
        'файл правила suicide должен называться suicide.yaml',
    ],
    'source without link' => [
        fn (array $rule): array => [...$rule, 'source' => array_diff_key($rule['source'], ['url' => true])],
        'source.url',
    ],
]);
