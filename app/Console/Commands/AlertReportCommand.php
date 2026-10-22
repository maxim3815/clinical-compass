<?php

namespace App\Console\Commands;

use App\Enums\AlertShownVia;
use App\Models\Alert;
use App\Support\Alerts\AlertPresenter;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Время доставки алертов врачу для технического отчёта (метрика быстродействия): от ответа
 * пациента до показа на экране врача, отдельно для открытого кабинета, и время шагов.
 */
class AlertReportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:alerts:report
        {--since= : Только алерты с этой даты, ГГГГ-ММ-ДД по Москве}';

    /**
     * @var string
     */
    protected $description = 'Сводка по времени доставки алертов врачу: число, медиана и максимум';

    public function handle(): int
    {
        $query = Alert::query()->orderBy('created_at');
        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            try {
                $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $since, DisplayTime::TIMEZONE)?->utc()->format('Y-m-d H:i:s.v'));
            } catch (InvalidFormatException) {
                $this->components->error('Дата в --since — в виде ГГГГ-ММ-ДД, например 2026-10-28.');

                return self::FAILURE;
            }
        }

        $alerts = $query->get();
        $shown = $alerts->filter(fn (Alert $alert): bool => $alert->shown_at !== null);

        $this->components->info("Алертов: {$alerts->count()}. Показаны врачу: {$shown->count()}, ещё не показаны: ".($alerts->count() - $shown->count()).'.');

        if ($alerts->isEmpty()) {
            return self::SUCCESS;
        }

        $this->line('  <fg=gray>От ответа пациента до показа на экране врача</>');
        $this->summary('Все показанные', $shown, fn (Alert $alert): ?float => $alert->deliverySeconds());
        $this->summary('Кабинет был открыт', $shown->filter(fn (Alert $alert): bool => $alert->shown_via === AlertShownVia::Realtime), fn (Alert $alert): ?float => $alert->deliverySeconds());
        $this->summary('Врач открыл кабинет позже', $shown->filter(fn (Alert $alert): bool => $alert->shown_via === AlertShownVia::Page), fn (Alert $alert): ?float => $alert->deliverySeconds());

        $this->newLine();
        $this->line('  <fg=gray>Шаги доставки</>');
        $this->summary('Ответ → алерт создан', $alerts, fn (Alert $alert): float => self::seconds($alert->submitted_at, $alert->created_at));
        $this->summary('Алерт → событие в Reverb', $alerts, fn (Alert $alert): ?float => self::seconds($alert->created_at, $alert->broadcast_at));
        $this->summary('Алерт → пуш отправлен', $alerts, fn (Alert $alert): ?float => self::seconds($alert->created_at, $alert->pushed_at));
        $this->summary('Событие → показ в открытом кабинете', $shown->filter(fn (Alert $alert): bool => $alert->shown_via === AlertShownVia::Realtime), fn (Alert $alert): ?float => self::seconds($alert->broadcast_at, $alert->shown_at));

        return self::SUCCESS;
    }

    /**
     * Строка сводки: число алертов со значением, медиана и максимум.
     *
     * @param  Collection<int, Alert>  $alerts
     * @param  Closure(Alert): ?float  $value
     */
    private function summary(string $title, Collection $alerts, Closure $value): void
    {
        $values = $alerts->map($value)->filter(fn (?float $seconds): bool => $seconds !== null)->sort()->values();

        if ($values->isEmpty()) {
            $this->components->twoColumnDetail("{$title} (0)", '—');

            return;
        }

        $middle = intdiv($values->count(), 2);
        $median = $values->count() % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        $this->components->twoColumnDetail(
            "{$title} ({$values->count()})",
            'медиана '.AlertPresenter::duration($median).' · максимум '.AlertPresenter::duration($values->last()),
        );
    }

    private static function seconds(?CarbonImmutable $from, ?CarbonImmutable $to): ?float
    {
        return $from === null || $to === null ? null : $from->diffInMilliseconds($to) / 1000;
    }
}
