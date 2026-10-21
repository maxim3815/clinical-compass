<?php

namespace App\Console\Commands;

use App\Actions\ImportDrugs;
use App\Exceptions\InvalidDrugCatalog;
use Illuminate\Console\Command;

class ImportDrugsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:drugs:import
        {path? : Путь к файлу справочника. По умолчанию database/data/drugs.yaml}
        {--groups= : Путь к файлу групп препаратов. По умолчанию database/data/drug-groups.yaml}';

    /**
     * @var string
     */
    protected $description = 'Загрузить справочник препаратов из файла';

    public function handle(ImportDrugs $importDrugs): int
    {
        $path = $this->argument('path');
        $groups = $this->option('groups');

        try {
            $result = $importDrugs->handle(
                is_string($path) ? $path : base_path(ImportDrugs::DEFAULT_PATH),
                is_string($groups) ? $groups : base_path(ImportDrugs::DEFAULT_GROUPS_PATH),
            );
        } catch (InvalidDrugCatalog $e) {
            $this->components->error('Справочник не загружен, в базе ничего не изменилось. Исправьте файл и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->components->info("Справочник не изменился. Препаратов в файле: {$result->total}.");

            return self::SUCCESS;
        }

        $this->components->info("Справочник обновлён. Препаратов в файле: {$result->total}.");

        foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated] as $title => $codes) {
            if ($codes !== []) {
                $this->components->twoColumnDetail($title, implode(', ', $codes));
            }
        }

        if ($result->groupsChanged) {
            $this->components->twoColumnDetail('Группы препаратов', 'обновлены');
        }

        return self::SUCCESS;
    }
}
