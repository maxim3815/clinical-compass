<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Support\Alerts\AlertDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Доставка алерта по кнопке «Сообщить врачу» и по срочному побочному эффекту. Задача ставится
 * в той же транзакции, что и действие пациента: очередь лежит в PostgreSQL, поэтому алерт
 * и доставка сохраняются вместе или не сохраняются вовсе.
 */
class DeliverAlert implements ShouldQueue
{
    use Queueable;

    /**
     * Ошибки Reverb и пушей AlertDelivery пишет в лог и не бросает: повтор задачи нужен только
     * при сбое до доставки.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5];

    public function __construct(public Alert $alert, public bool $repeat = false)
    {
        // Своя очередь: воркер берёт её раньше остальных, алерт не ждёт напоминаний и событий.
        $this->onQueue('alerts');
    }

    public function handle(AlertDelivery $delivery): void
    {
        DB::afterCommit(fn () => $delivery->deliver($this->alert, $this->repeat));
    }
}
