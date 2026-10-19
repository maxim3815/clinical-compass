<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Плановые приёмы на сегодня и завтра. Сразу после изменений их пересчитывают сами действия,
// а раз в час досоздаются приёмы на новый день.
Schedule::command('app:dose-events:sync')->hourly()->withoutOverlapping();
Schedule::command('app:dose-events:mark-missed')->everyMinute();
