<?php

use App\Models\Scale;
use Symfony\Component\Yaml\Yaml;

// Сумма и степени шкал из файлов приложения, без загрузки в БД.

function scaleFromFile(string $code): Scale
{
    $data = Yaml::parseFile(__DIR__."/../../../database/data/scales/{$code}.yaml");

    return new Scale([
        'code' => $data['code'],
        'name' => $data['name'],
        'options' => $data['options'],
        'items' => $data['items'],
        'bands' => $data['bands'],
    ]);
}

test('scale files give the largest sum of each questionnaire', function () {
    expect(scaleFromFile('gad7')->maxTotal())->toBe(21)
        ->and(scaleFromFile('phq9')->maxTotal())->toBe(27)
        ->and(scaleFromFile('phq9')->items[8])->toMatchArray(['highlight' => true]);
});

test('sum adds the score of every answer', function () {
    expect(scaleFromFile('gad7')->total([0, 1, 2, 3, 3, 2, 1]))->toBe(12)
        ->and(scaleFromFile('phq9')->total([3, 3, 3, 3, 3, 3, 3, 3, 3]))->toBe(27);
});

test('sum needs an answer to every question with a score from the options', function (array $scores) {
    scaleFromFile('gad7')->total($scores);
})->with([
    'missing answer' => [[0, 1, 2, 3, 3, 2]],
    'extra answer' => [[0, 1, 2, 3, 3, 2, 1, 0]],
    'unknown score' => [[0, 1, 2, 3, 3, 2, 4]],
])->throws(InvalidArgumentException::class);

test('GAD-7 band on the boundaries', function (int $total, string $band) {
    expect(scaleFromFile('gad7')->band($total))->toBe($band);
})->with([
    [0, 'минимальная тревога'],
    [4, 'минимальная тревога'],
    [5, 'лёгкая тревога'],
    [9, 'лёгкая тревога'],
    [10, 'умеренная тревога'],
    [14, 'умеренная тревога'],
    [15, 'выраженная тревога'],
    [21, 'выраженная тревога'],
]);

test('PHQ-9 band on the boundaries', function (int $total, string $band) {
    expect(scaleFromFile('phq9')->band($total))->toBe($band);
})->with([
    [0, 'минимальная депрессия'],
    [4, 'минимальная депрессия'],
    [5, 'лёгкая депрессия'],
    [9, 'лёгкая депрессия'],
    [10, 'умеренная депрессия'],
    [14, 'умеренная депрессия'],
    [15, 'умеренно тяжёлая депрессия'],
    [19, 'умеренно тяжёлая депрессия'],
    [20, 'тяжёлая депрессия'],
    [27, 'тяжёлая депрессия'],
]);

test('sum outside the bands is an error', function () {
    scaleFromFile('gad7')->band(22);
})->throws(InvalidArgumentException::class);
