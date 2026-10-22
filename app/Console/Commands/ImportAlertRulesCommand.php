<?php

namespace App\Console\Commands;

use App\Actions\ImportAlertRules;
use App\Exceptions\InvalidAlertRules;
use Illuminate\Console\Command;

class ImportAlertRulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:alerts:import
        {path? : Каталог файлов правил. По умолчанию database/data/alerts}';

    /**
     * @var string
     */
    protected $description = 'Загрузить правила алертинга по ответам опросников из файлов (после app:scales:import)';

    public function handle(ImportAlertRules $importAlertRules): int
    {
        $path = $this->argument('path');

        try {
            $result = $importAlertRules->handle(is_string($path) ? $path : base_path(ImportAlertRules::DEFAULT_PATH));
        } catch (InvalidAlertRules $e) {
            $this->components->error('Правила алертинга не загружены, в базе ничего не изменилось. Исправьте файлы и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->components->info("Правила алертинга не изменились. Правил в файлах: {$result->total}.");

            return self::SUCCESS;
        }

        $this->components->info("Правила алертинга обновлены. Правил в файлах: {$result->total}.");

        foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated] as $title => $codes) {
            if ($codes !== []) {
                $this->components->twoColumnDetail($title, implode(', ', $codes));
            }
        }

        return self::SUCCESS;
    }
}
