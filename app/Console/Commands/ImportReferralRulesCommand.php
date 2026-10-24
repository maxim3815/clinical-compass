<?php

namespace App\Console\Commands;

use App\Actions\ImportReferralRules;
use App\Exceptions\InvalidReferralRules;
use Illuminate\Console\Command;

class ImportReferralRulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:referral-rules:import
        {path? : Файл правил. По умолчанию database/data/referral-rules.yaml}';

    /**
     * @var string
     */
    protected $description = 'Загрузить правила подсказки направления к психиатру из файла (после app:rules:import и app:scales:import)';

    public function handle(ImportReferralRules $importReferralRules): int
    {
        $path = $this->argument('path');

        try {
            $result = $importReferralRules->handle(is_string($path) ? $path : base_path(ImportReferralRules::DEFAULT_PATH));
        } catch (InvalidReferralRules $e) {
            $this->components->error('Правила подсказки направления не загружены, в базе ничего не изменилось. Исправьте файл и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->components->info("Правила подсказки направления не изменились. Правил в файле: {$result->total}.");

            return self::SUCCESS;
        }

        $this->components->info("Правила подсказки направления обновлены. Правил в файле: {$result->total}.");

        foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated] as $title => $codes) {
            if ($codes !== []) {
                $this->components->twoColumnDetail($title, implode(', ', $codes));
            }
        }

        return self::SUCCESS;
    }
}
