<?php

use App\Enums\RussianTimezone;

test('there are 11 timezones from Kaliningrad to Kamchatka', function () {
    expect(RussianTimezone::cases())->toHaveCount(11)
        ->and(RussianTimezone::cases()[0])->toBe(RussianTimezone::Kaliningrad)
        ->and(RussianTimezone::cases()[10])->toBe(RussianTimezone::Kamchatka);
});

test('label shows the real offset of the timezone', function (RussianTimezone $timezone) {
    $hours = (new DateTimeZone($timezone->value))->getOffset(new DateTimeImmutable) / 3600;

    expect($timezone->label())->toEndWith(sprintf('(UTC+%d)', $hours));
})->with(RussianTimezone::cases());

test('options keep the order from west to east', function () {
    expect(array_column(RussianTimezone::options(), 'value'))
        ->toBe(array_column(RussianTimezone::cases(), 'value'))
        ->and(RussianTimezone::options()[1])->toBe(['value' => 'Europe/Moscow', 'label' => 'Москва (UTC+3)']);
});
