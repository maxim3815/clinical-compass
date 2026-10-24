<?php

use App\Enums\AlertLevel;
use App\Enums\AlertTrigger;
use App\Models\AlertRule;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => loadScales());

test('alert rule files load once', function () {
    $this->artisan('app:alerts:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правила алертинга обновлены. Правил в файлах: 3.');

    $rule = AlertRule::query()->where('code', 'suicide_risk')->sole();
    $help = AlertRule::query()->where('code', 'help_request')->sole();

    expect($rule->name)->toBe('Суицидальный риск')
        ->and($rule->level)->toBe(AlertLevel::Critical)
        ->and($rule->trigger)->toBe(AlertTrigger::Answers)
        ->and($rule->condition)->toBe("answer('phq9', 9) >= 1")
        ->and($rule->source_url)->toStartWith('https://www.phqscreeners.com/')
        ->and($rule->source_quote)->toContain('answer positively to the 9th question of the PHQ-9');

    expect($help->name)->toBe('Просьба о помощи')
        ->and($help->level)->toBe(AlertLevel::Critical)
        ->and($help->trigger)->toBe(AlertTrigger::HelpRequest)
        ->and($help->condition)->toBeNull()
        ->and($help->source())->toBeNull()
        ->and($help->text)->toContain('попросил связаться с экрана помощи');

    $adverse = AlertRule::query()->where('code', 'adverse_event')->sole();

    expect($adverse->name)->toBe('Срочный побочный эффект')
        ->and($adverse->trigger)->toBe(AlertTrigger::AdverseEvent)
        ->and($adverse->condition)->toBeNull()
        ->and($adverse->source())->toBeNull();

    $this->artisan('app:alerts:import')
        ->assertSuccessful()
        ->expectsOutputToContain('Правила алертинга не изменились. Правил в файлах: 3.');

    expect($rule->refresh()->version)->toBe(AlertRule::query()->where('code', 'suicide_risk')->sole()->version);
});

test('changed rule gets a new version, a new rule is added by a file and a removed one is deactivated', function () {
    loadAlertRules();
    $version = AlertRule::query()->where('code', 'suicide_risk')->sole()->version;

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
        ->and(AlertRule::query()->active()->count())->toBe(3);

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
    'unknown trigger' => [
        fn (array $rule): array => [...$rule, 'trigger' => 'chat'],
        'suicide_risk.yaml: Значение поля trigger отсутствует в списке разрешённых',
    ],
    'answers rule without source' => [
        fn (array $rule): array => array_diff_key($rule, ['source' => true]),
        'suicide_risk.yaml: Поле source обязательно',
    ],
]);

test('help request rule has no condition and is the only one', function (array $extra, string $error) {
    $code = Artisan::call('app:alerts:import', ['path' => alertRulesDirectory(extra: $extra)]);
    $output = preg_replace('/\s+/u', ' ', Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toContain($error)
        ->and(AlertRule::query()->count())->toBe(0);
})->with([
    'condition on the button' => [
        ['help_request.yaml' => [
            'code' => 'help_request',
            'name' => 'Просьба о помощи',
            'level' => 'critical',
            'trigger' => 'help_request',
            'condition' => "answer('phq9', 9) >= 1",
            'text' => 'Пациент нажал «Сообщить врачу».',
        ]],
        'help_request.yaml: Значение поля condition запрещено',
    ],
    'second button rule' => [
        ['help_again.yaml' => [
            'code' => 'help_again',
            'name' => 'Ещё одна кнопка',
            'level' => 'critical',
            'trigger' => 'help_request',
            'text' => 'Пациент нажал «Сообщить врачу».',
        ]],
        'Правило по кнопке «Сообщить врачу» должно быть одно, а их 2',
    ],
]);
