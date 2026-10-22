<?php

use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Support\Alerts\AlertLanguage;
use App\Support\Alerts\AlertSubmission;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Yaml\Yaml;

// Условие правила из файла приложения на ответах PHQ-9 без БД.

beforeEach(function () {
    $this->condition = Yaml::parseFile(__DIR__.'/../../../database/data/alerts/suicide_risk.yaml')['condition'];

    // PHQ-9: на первые восемь вопросов — $score, на девятый — $item9.
    $this->phq9 = function (int $score, int $item9): AlertSubmission {
        $scores = [...array_fill(0, 8, $score), $item9];
        $assessment = new Assessment(['total' => array_sum($scores)]);
        $assessment->setRelation('scale', scaleFromFile('phq9'));
        $assessment->setRelation('answers', new Collection(array_map(
            fn (int $index): AssessmentAnswer => new AssessmentAnswer(['item' => $index + 1, 'score' => $scores[$index]]),
            array_keys($scores),
        )));

        return new AlertSubmission(new Collection([$assessment]));
    };
});

test('the suicide risk rule fires on any answer to item 9 except «not at all»', function (int $item9, bool $fires) {
    $language = new AlertLanguage;

    // Сумма остальных ответов роли не играет: и при тяжёлой депрессии без ответа на 9-й вопрос алерта нет.
    expect($language->evaluateFor($this->condition, ($this->phq9)(3, $item9)))->toBe($fires)
        ->and($language->evaluateFor($this->condition, ($this->phq9)(0, $item9)))->toBe($fires);
})->with([
    'not at all' => [0, false],
    'several days' => [1, true],
    'more than half the days' => [2, true],
    'nearly every day' => [3, true],
]);

test('condition names the answers and sums it looks at', function () {
    $language = new AlertLanguage;

    expect($language->references("answer('phq9', 9) >= 1"))->toBe([['answer', 'phq9', 9]])
        ->and($language->references("total('phq9') >= 20 or answer('phq9', 9) > 0 and answer('phq9', 9) < 3"))
        ->toBe([['total', 'phq9'], ['answer', 'phq9', 9]])
        ->and($language->evaluateFor("total('phq9') >= 20", ($this->phq9)(3, 0)))->toBeTrue()
        ->and($language->evaluateFor("total('phq9') >= 20", ($this->phq9)(1, 3)))->toBeFalse();
});

test('condition cannot use variables, methods or functions other than answer() and total()', function (string $condition) {
    expect(fn () => (new AlertLanguage)->references($condition))->toThrow(SyntaxError::class);
})->with([
    'unknown function' => "fact('gfr') > 1",
    'variable' => 'patient.age > 18',
    'answer without item' => "answer('phq9') > 0",
    'item as a string' => "answer('phq9', '9') > 0",
    'scale from a variable' => 'total(scale) > 0',
]);
