<?php

use App\Support\Dose;
use App\Support\Plural;

test('tablets are shown with halves and the right word form', function (string $tablets, string $label) {
    expect(Dose::tablets($tablets))->toBe($label);
})->with([
    ['0.5', '½ таблетки'],
    ['1.0', '1 таблетка'],
    ['1.5', '1½ таблетки'],
    ['2', '2 таблетки'],
    ['4.0', '4 таблетки'],
    ['5.0', '5 таблеток'],
    ['9.5', '9½ таблетки'],
    ['10.0', '10 таблеток'],
]);

test('milligrams are shown without extra zeros', function (string|float $mg, string $label) {
    expect(Dose::mg($mg))->toBe($label);
})->with([
    ['20.00', '20 мг'],
    ['100.00', '100 мг'],
    ['12.50', '12,5 мг'],
    ['0.25', '0,25 мг'],
    [37.5, '37,5 мг'],
    [1000.0, '1000 мг'],
]);

test('dose label shows tablets and milligrams of one intake', function (string $tablets, string $mg, string $label) {
    expect(Dose::label($tablets, $mg))->toBe($label);
})->with([
    ['0.5', '25.00', '½ таблетки, 12,5 мг'],
    ['1.0', '20.00', '1 таблетка, 20 мг'],
    ['1.5', '50.00', '1½ таблетки, 75 мг'],
    ['2.0', '12.50', '2 таблетки, 25 мг'],
]);

test('plural picks the russian word form', function (int $count, string $form) {
    expect(Plural::choose($count, ['one', 'few', 'many']))->toBe($form);
})->with([
    [1, 'one'],
    [2, 'few'],
    [4, 'few'],
    [5, 'many'],
    [11, 'many'],
    [12, 'many'],
    [14, 'many'],
    [21, 'one'],
    [22, 'few'],
    [25, 'many'],
    [111, 'many'],
    [0, 'many'],
]);
