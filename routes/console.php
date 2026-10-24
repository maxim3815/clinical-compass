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
// Напоминания о приёме пушем. Сами пуши отправляет очередь.
Schedule::command('app:dose-events:send-reminders')->everyMinute()->withoutOverlapping();
// Находки проверки безопасности сразу пересчитывают сами действия. Раз в час — на случай, когда
// назначение завершилось по дате или у пациента сменился возраст.
Schedule::command('app:safety:sync')->hourly()->withoutOverlapping();
// Непривязанные ответы из очереди у двери терапевта живут 12 часов.
Schedule::command('app:queue-answers:prune')->hourly()->withoutOverlapping();
