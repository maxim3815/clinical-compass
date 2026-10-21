<?php

use App\Enums\Sex;
use App\Models\Drug;
use App\Models\SafetyRule;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\Safety\RuleResult;
use App\Support\Safety\SafetyCheck;

// Каждое правило из database/data/rules проверяется на трёх пациентах: на своих данных оно
// срабатывает, на нормальных молчит, а без нужных данных попадает в «Не проверено».

const SAFETY_BASE_STATE = [
    'sex' => 'female',
    'age' => 40,
    // F41.1 и F32.1 вместе покрывают показания всех пяти препаратов.
    'diagnoses' => ['F41.1', 'F32.1'],
    'facts' => [
        'pregnancy' => false,
        'lactation' => false,
        'gfr' => 95,
        'hepatic_impairment' => 'none',
        'epilepsy' => false,
        'maoi' => false,
        'qt_prolongation' => false,
        'qt_risk_factors' => false,
        'cardiovascular' => false,
        'diabetes_endocrine' => false,
        'substance_dependence' => false,
    ],
    'taking' => [],
];

/**
 * Проверка назначения препарата пациенту в состоянии: базовое, поверх него изменения. Факт null —
 * факт не внесён, возраст null — дата рождения неизвестна.
 *
 * @param  array<string, mixed>  $changes
 * @return array{list<string>, list<string>} сработавшие правила и «Не проверено»
 */
function checkSafetyCase(string $drug, array $changes): array
{
    $state = [...SAFETY_BASE_STATE, ...$changes, 'facts' => [...SAFETY_BASE_STATE['facts'], ...($changes['facts'] ?? [])]];
    $patient = User::factory()->patientOf(User::factory()->doctor()->create())->create();

    $patient->patientProfile?->update([
        'sex' => Sex::from($state['sex']),
        'birth_date' => $state['age'] === null ? null : DisplayTime::today()->subYears($state['age'])->subDays(10),
    ]);

    foreach ($state['diagnoses'] as $index => $code) {
        diagnose($patient, $code, $index === 0);
    }

    foreach ($state['facts'] as $type => $value) {
        if ($value !== null) {
            observe($patient, $type, $value);
        }
    }

    foreach ($state['taking'] as $code) {
        takes($patient, $code, own: true);
    }

    $report = app(SafetyCheck::class)->forCandidate($patient, Drug::query()->where('code', $drug)->sole());

    return [array_map(fn (RuleResult $result): string => $result->rule->code, $report->results), $report->unchecked];
}

/**
 * Правило => на чём срабатывает, без чего не проверяется и как это называется в «Не проверено».
 * У взаимодействий данных не бывает мало: без препарата правило просто молчит.
 *
 * @return array<string, array{array<string, mixed>, array<string, mixed>|null, string|null}>
 */
function safetyRuleCases(): array
{
    $fact = fn (string $code, mixed $value): array => ['facts' => [$code => $value]];
    $missing = fn (string $code): array => ['facts' => [$code => null]];
    $offLabel = [['diagnoses' => ['Z73.0']], ['diagnoses' => []], 'диагноз'];
    $minor = [['age' => 16], ['age' => null], 'дата рождения'];
    $maoi = [$fact('maoi', true), $missing('maoi'), 'Ингибиторы МАО сейчас или в последние 14 дней'];
    $pregnancy = [$fact('pregnancy', true), $missing('pregnancy'), 'Беременность'];
    $lactation = [$fact('lactation', true), $missing('lactation'), 'Грудное вскармливание'];
    $qt = [$fact('qt_prolongation', true), $missing('qt_prolongation'), 'Удлинение интервала QT, врождённое или приобретённое'];
    $mania = [['diagnoses' => ['F41.1', 'F32.1', 'F31.0']], ['diagnoses' => []], 'диагноз'];
    $taking = fn (string $drug): array => [['taking' => [$drug]], null, null];

    return [
        'maritupirdine.indications' => $offLabel,
        'maritupirdine.age' => $minor,
        'maritupirdine.epilepsy' => [$fact('epilepsy', true), $missing('epilepsy'), 'Эпилепсия или органическое поражение головного мозга'],
        'maritupirdine.maoi' => $maoi,
        'maritupirdine.gfr' => [$fact('gfr', 52), $missing('gfr'), 'СКФ'],
        'maritupirdine.hepatic' => [$fact('hepatic_impairment', 'a'), $missing('hepatic_impairment'), 'Печёночная недостаточность'],
        'maritupirdine.pregnancy' => $pregnancy,
        'maritupirdine.lactation' => $lactation,
        'maritupirdine.cardiovascular' => [$fact('cardiovascular', true), $missing('cardiovascular'), 'Сердечно-сосудистые заболевания'],
        'maritupirdine.endocrine' => [$fact('diabetes_endocrine', true), $missing('diabetes_endocrine'), 'Сахарный диабет или другие эндокринные заболевания'],
        'maritupirdine.dependence' => [$fact('substance_dependence', true), $missing('substance_dependence'), 'Алкогольная или наркотическая зависимость'],
        'maritupirdine.serotonergic' => $taking('sertraline'),
        'maritupirdine.cyp2d6' => $taking('paroxetine'),
        'maritupirdine.sedative' => $taking('hydroxyzine'),

        'escitalopram.indications' => $offLabel,
        'escitalopram.maoi' => $maoi,
        'escitalopram.qt' => $qt,
        'escitalopram.qt_drugs' => $taking('hydroxyzine'),
        'escitalopram.pregnancy' => $pregnancy,
        'escitalopram.lactation' => $lactation,
        'escitalopram.mania' => $mania,
        'escitalopram.serotonergic' => $taking('sertraline'),

        // ГТР у сертралина вне показаний.
        'sertraline.indications' => [['diagnoses' => ['F41.1']], ['diagnoses' => []], 'диагноз'],
        'sertraline.maoi' => $maoi,
        'sertraline.pregnancy' => $pregnancy,
        'sertraline.lactation' => $lactation,
        'sertraline.mania' => $mania,
        'sertraline.qt' => $qt,
        'sertraline.qt_drugs' => $taking('escitalopram'),
        'sertraline.serotonergic' => $taking('paroxetine'),

        'paroxetine.indications' => $offLabel,
        'paroxetine.age' => $minor,
        'paroxetine.maoi' => $maoi,
        'paroxetine.pregnancy' => $pregnancy,
        'paroxetine.lactation' => $lactation,
        'paroxetine.mania' => $mania,
        'paroxetine.qt' => $qt,
        'paroxetine.qt_drugs' => $taking('hydroxyzine'),
        'paroxetine.serotonergic' => $taking('sertraline'),

        'hydroxyzine.indications' => $offLabel,
        'hydroxyzine.age' => [['age' => 2], ['age' => null], 'дата рождения'],
        'hydroxyzine.qt' => $qt,
        'hydroxyzine.qt_risk' => [$fact('qt_risk_factors', true), $missing('qt_risk_factors'), 'Факторы риска удлинения QT (брадикардия, гипокалиемия, гипомагниемия, внезапная сердечная смерть в семье)'],
        'hydroxyzine.cardiovascular' => [$fact('cardiovascular', true), $missing('cardiovascular'), 'Сердечно-сосудистые заболевания'],
        'hydroxyzine.qt_drugs' => $taking('escitalopram'),
        'hydroxyzine.pregnancy' => $pregnancy,
        'hydroxyzine.lactation' => $lactation,
        'hydroxyzine.maoi' => $maoi,
        'hydroxyzine.paroxetine' => $taking('paroxetine'),
        'hydroxyzine.gfr' => [$fact('gfr', 52), $missing('gfr'), 'СКФ'],
        'hydroxyzine.hepatic' => [$fact('hepatic_impairment', 'b'), $missing('hepatic_impairment'), 'Печёночная недостаточность'],
        'hydroxyzine.elderly' => [['age' => 70], ['age' => null], 'дата рождения'],
    ];
}

beforeEach(fn () => loadSafetyRules());

test('every rule in the files is covered here', function () {
    expect(SafetyRule::query()->active()->pluck('code')->sort()->values()->all())
        ->toBe(collect(safetyRuleCases())->keys()->sort()->values()->all());
});

test('normal data gives no findings and nothing unchecked', function (string $drug) {
    [$fired, $unchecked] = checkSafetyCase($drug, []);

    expect($fired)->toBe([])->and($unchecked)->toBe([]);
})->with(['maritupirdine', 'escitalopram', 'sertraline', 'paroxetine', 'hydroxyzine']);

test('rule fires on its data, stays quiet on normal data and is unchecked without data', function (string $code) {
    [$fires, $missing, $label] = safetyRuleCases()[$code];
    $drug = strstr($code, '.', true);

    [$fired] = checkSafetyCase($drug, $fires);
    expect($fired)->toContain($code);

    [$fired, $unchecked] = checkSafetyCase($drug, []);
    expect($fired)->not->toContain($code)->and($unchecked)->toBe([]);

    if ($missing !== null) {
        [$fired, $unchecked] = checkSafetyCase($drug, $missing);
        expect($fired)->not->toContain($code)->and($unchecked)->toContain($label);
    }
})->with(fn () => array_keys(safetyRuleCases()));
