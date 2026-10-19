<?php

use App\Models\DrugStrength;

test('strength label drops trailing zeros and uses a decimal comma', function (string|int|float $mg, string $label) {
    expect((new DrugStrength(['mg' => $mg]))->label())->toBe($label);
})->with([
    [20, '20 мг'],
    [100, '100 мг'],
    ['12.5', '12,5 мг'],
    [0.25, '0,25 мг'],
    ['10.00', '10 мг'],
]);
