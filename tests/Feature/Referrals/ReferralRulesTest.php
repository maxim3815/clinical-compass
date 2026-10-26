<?php

use App\Actions\SubmitAssessments;
use App\Enums\ReferralCategory;
use App\Models\ReferralRule;
use App\Models\User;
use App\Support\Referrals\ReferralEngine;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    loadReferralRules();
    $this->therapist = User::factory()->therapist()->create();
    $this->patient = User::factory()->patientOf($this->therapist)->create();
    $this->engine = app(ReferralEngine::class);
});

/**
 * Файл правил во временной папке: копия файла приложения с правкой.
 *
 * @param  Closure(list<array<string, mixed>>): list<array<string, mixed>>  $change
 */
function referralRulesFile(Closure $change): string
{
    $rules = Yaml::parseFile(base_path('database/data/referral-rules.yaml'));
    $path = sys_get_temp_dir().'/referral-rules-'.uniqid().'.yaml';
    file_put_contents($path, Yaml::dump($change($rules), 4));

    return $path;
}

test('the rule file loads once and a repeat changes nothing', function () {
    expect(ReferralRule::query()->active()->orderBy('position')->pluck('code')->all())->toBe([
        'suicidal_thoughts', 'mania_signs', 'psychotic_symptoms', 'hads_anxiety_severe', 'hads_depression_severe', 'phq9_severe',
        'hads_anxiety_moderate', 'hads_depression_moderate', 'gad7_severe', 'phq9_moderate',
    ]);

    $version = ReferralRule::query()->where('code', 'hads_anxiety_severe')->sole()->version;

    Artisan::call('app:referral-rules:import');

    expect(Artisan::output())->toContain('Правила подсказки направления не изменились. Правил в файле: 10.')
        ->and(ReferralRule::query()->where('code', 'hads_anxiety_severe')->sole()->version)->toBe($version);
});

test('a broken rule file changes nothing and names every problem', function () {
    $path = referralRulesFile(function (array $rules): array {
        $rules[0]['condition'] = "total('sigh') >= 1";
        $rules[1]['condition'] = "fact('nonexistent')";
        $rules[2]['category'] = 'urgent';
        $rules[3]['code'] = $rules[4]['code'];
        $rules[5]['extra'] = 'опечатка';
        $rules[6]['condition'] = "answer('phq9', 12) >= 1";
        $rules[7]['condition'] = "total('hads', 'mood') >= 1";
        $rules[8]['condition'] = "total('hads') >= 1";

        return $rules;
    });

    Artisan::call('app:referral-rules:import', ['path' => $path]);
    $output = Artisan::output();

    expect($output)->toContain('Правила подсказки направления не загружены')
        ->toContain('шкалы sigh нет среди загруженных')
        ->toContain('вида факта nonexistent нет среди загруженных')
        ->toContain('category')
        ->toContain('код повторяется')
        ->toContain('неизвестное поле extra')
        ->toContain('в шкале phq9 нет вопроса 12')
        ->toContain('у шкалы hads нет подшкалы mood')
        ->toContain("у шкалы hads суммы по подшкалам: total('hads', 'код подшкалы')")
        ->and(ReferralRule::query()->where('condition', "total('sigh') >= 1")->exists())->toBeFalse();
});

test('a rule removed from the file is switched off', function () {
    $path = referralRulesFile(fn (array $rules): array => array_values(array_filter($rules, fn (array $rule): bool => $rule['code'] !== 'gad7_severe')));

    Artisan::call('app:referral-rules:import', ['path' => $path]);

    expect(ReferralRule::query()->where('code', 'gad7_severe')->sole()->is_active)->toBeFalse();
});

/**
 * Данные, при которых срабатывает ровно это правило, и при которых оно молчит.
 *
 * @return array<string, array{0: Closure(User, User): void, 1: Closure(User, User): void}>
 */
function referralRuleCases(): array
{
    $answers = fn (User $patient, int $gad7, int $phq9, int $item9 = 0) => app(SubmitAssessments::class)->handle($patient, [
        'gad7' => array_map(fn (int $item): int => max(0, min(3, $gad7 - 3 * $item)), range(0, 6)),
        'phq9' => [...array_map(fn (int $item): int => max(0, min(3, $phq9 - $item9 - 3 * $item)), range(0, 7)), $item9],
    ]);

    return [
        'suicidal_thoughts' => [fn (User $t, User $p) => $answers($p, 0, 1, 1), fn (User $t, User $p) => $answers($p, 0, 9, 0)],
        'mania_signs' => [fn (User $t, User $p) => observe($p, 'mania_signs', true), fn (User $t, User $p) => observe($p, 'mania_signs', false)],
        'psychotic_symptoms' => [fn (User $t, User $p) => observe($p, 'psychotic_symptoms', true), fn (User $t, User $p) => observe($p, 'psychotic_symptoms', false)],
        'hads_anxiety_severe' => [fn (User $t, User $p) => rateHads($t, $p, 15), fn (User $t, User $p) => rateHads($t, $p, 14)],
        'hads_depression_severe' => [fn (User $t, User $p) => rateHads($t, $p, 0, 15), fn (User $t, User $p) => rateHads($t, $p, 0, 14)],
        'phq9_severe' => [fn (User $t, User $p) => $answers($p, 0, 20), fn (User $t, User $p) => $answers($p, 0, 19)],
        'hads_anxiety_moderate' => [fn (User $t, User $p) => rateHads($t, $p, 11), fn (User $t, User $p) => rateHads($t, $p, 10)],
        'hads_depression_moderate' => [fn (User $t, User $p) => rateHads($t, $p, 0, 11), fn (User $t, User $p) => rateHads($t, $p, 0, 10)],
        'gad7_severe' => [fn (User $t, User $p) => $answers($p, 15, 0), fn (User $t, User $p) => $answers($p, 14, 0)],
        'phq9_moderate' => [fn (User $t, User $p) => $answers($p, 0, 10), fn (User $t, User $p) => $answers($p, 0, 9)],
    ];
}

test('every rule in the file is covered here', function () {
    expect(ReferralRule::query()->active()->orderBy('code')->pluck('code')->all())
        ->toBe(collect(array_keys(referralRuleCases()))->sort()->values()->all());
});

test('a rule fires on its threshold', function (string $code) {
    referralRuleCases()[$code][0]($this->therapist, $this->patient);

    $suggestion = $this->engine->suggest($this->patient);
    $rule = ReferralRule::query()->where('code', $code)->sole();

    expect(array_column($suggestion->matches, 'code'))->toBe([$code])
        ->and($suggestion->category)->toBe($rule->category)
        ->and($suggestion->matches[0]['source']['url'])->toStartWith('https://cr.minzdrav.gov.ru/');
})->with(fn (): array => array_keys(referralRuleCases()));

test('a rule stays silent just below its threshold', function (string $code) {
    referralRuleCases()[$code][1]($this->therapist, $this->patient);

    expect(array_column($this->engine->suggest($this->patient)->matches, 'code'))->not->toContain($code);
})->with(fn (): array => array_keys(referralRuleCases()));

test('the strongest rule sets the category and the basis lists every rule, required first', function () {
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 3, phq9: 1, phq9Item9: 1));
    rateHads($this->therapist, $this->patient, 12);

    $suggestion = $this->engine->suggest($this->patient);

    expect($suggestion->category)->toBe(ReferralCategory::Required)
        ->and(array_column($suggestion->matches, 'code'))->toBe(['suicidal_thoughts', 'hads_anxiety_moderate', 'gad7_severe'])
        ->and($suggestion->matches[0]['evidence'][0])->toMatchArray(['type' => 'answer', 'name' => 'PHQ-9', 'item' => 9, 'score' => 1])
        // Сумма подшкалы — с её названием, наибольшей суммой и степенью.
        ->and($suggestion->matches[1]['evidence'][0])->toMatchArray(['type' => 'total', 'scale' => 'hads', 'name' => 'HADS, тревога', 'total' => 12, 'max' => 21, 'band' => 'клинически выраженная тревога'])
        ->and($suggestion->missing)->toBe([]);
});

test('missing scales are named and their rules are not checked', function () {
    $suggestion = $this->engine->suggest($this->patient);

    expect($suggestion->category)->toBeNull()
        ->and($suggestion->matches)->toBe([])
        ->and(array_column($suggestion->missing, 'scale'))->toBe(['gad7', 'phq9', 'hads'])
        ->and(array_column($suggestion->missing, 'rater'))->toBe(['patient', 'patient', 'doctor']);
});

test('patient answers older than 14 days and HADS from another day are not used', function () {
    $this->travelTo(now()->subDays(14));
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 3, phq9: 3));
    $this->travelBack();

    $this->travelTo(now()->subDay());
    rateHads($this->therapist, $this->patient, 18, 18);
    $this->travelBack();

    $suggestion = $this->engine->suggest($this->patient);

    expect($suggestion->category)->toBeNull()
        ->and(array_column($suggestion->missing, 'scale'))->toBe(['gad7', 'phq9', 'hads']);
});
