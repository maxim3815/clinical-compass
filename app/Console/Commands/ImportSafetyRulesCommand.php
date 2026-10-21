<?php

namespace App\Console\Commands;

use App\Actions\ImportSafetyRules;
use App\Exceptions\InvalidSafetyRules;
use Illuminate\Console\Command;

class ImportSafetyRulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:rules:import
        {path? : Каталог файлов правил. По умолчанию database/data/rules}
        {--types= : Путь к файлу видов фактов. По умолчанию database/data/observation-types.yaml}';

    /**
     * @var string
     */
    protected $description = 'Загрузить виды фактов и правила проверки назначения из файлов и пересчитать находки';

    public function handle(ImportSafetyRules $importSafetyRules): int
    {
        $path = $this->argument('path');
        $types = $this->option('types');

        try {
            $result = $importSafetyRules->handle(
                is_string($path) ? $path : base_path(ImportSafetyRules::DEFAULT_RULES_PATH),
                is_string($types) ? $types : base_path(ImportSafetyRules::DEFAULT_TYPES_PATH),
            );
        } catch (InvalidSafetyRules $e) {
            $this->components->error('Правила не загружены, в базе ничего не изменилось. Исправьте файлы и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->components->info("Правила не изменились. Правил в файлах: {$result->total}.");

            return self::SUCCESS;
        }

        $this->components->info("Правила обновлены. Правил в файлах: {$result->total}.");

        foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated, 'Виды фактов' => $result->typesChanged] as $title => $codes) {
            if ($codes !== []) {
                $this->components->twoColumnDetail($title, implode(', ', $codes));
            }
        }

        // Находки у идущих назначений пересчитываются по новым правилам.
        return $this->call('app:safety:sync');
    }
}
