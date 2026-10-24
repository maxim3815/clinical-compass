<?php

namespace App\Console\Commands;

use App\Models\QueueAnswer;
use Illuminate\Console\Command;

class PruneQueueAnswersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:queue-answers:prune';

    /**
     * @var string
     */
    protected $description = 'Удалить ответы из очереди старше 12 часов вместе с HARS и признаками';

    /**
     * Привязанные ответы уже стали заполнениями пациента: их строка — только ссылка для страницы
     * кода, и она тоже не нужна. В журнале аудита остаются код и решения, без ответов.
     */
    public function handle(): int
    {
        $deleted = QueueAnswer::query()
            ->where('submitted_at', '<=', now()->subHours(QueueAnswer::LIFETIME_HOURS))
            ->delete();

        $this->components->info("Удалено ответов из очереди: {$deleted}.");

        return self::SUCCESS;
    }
}
