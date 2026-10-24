<?php

namespace App\Console\Commands;

use App\Actions\ImportDrugs;
use App\Actions\ImportSideEffects;
use App\Exceptions\InvalidDrugCatalog;
use App\Exceptions\InvalidSideEffects;
use Illuminate\Console\Command;

class ImportDrugsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:drugs:import
        {path? : Путь к файлу справочника. По умолчанию database/data/drugs.yaml}
        {--groups= : Путь к файлу групп препаратов. По умолчанию database/data/drug-groups.yaml}
        {--side-effects= : Путь к файлу побочных эффектов. По умолчанию database/data/side-effects.yaml}';

    /**
     * @var string
     */
    protected $description = 'Загрузить справочник препаратов и побочные эффекты из файлов';

    public function handle(ImportDrugs $importDrugs, ImportSideEffects $importSideEffects): int
    {
        $path = $this->argument('path');
        $groups = $this->option('groups');
        $sideEffects = $this->option('side-effects');

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
        } else {
            $this->components->info("Справочник обновлён. Препаратов в файле: {$result->total}.");

            foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated] as $title => $codes) {
                if ($codes !== []) {
                    $this->components->twoColumnDetail($title, implode(', ', $codes));
                }
            }

            if ($result->groupsChanged) {
                $this->components->twoColumnDetail('Группы препаратов', 'обновлены');
            }
        }

        // Побочные эффекты ссылаются на препараты по коду, поэтому загружаются после справочника.
        // Для своего файла справочника файл побочных эффектов задают явно: в стандартном другие препараты.
        if (is_string($path) && ! is_string($sideEffects)) {
            return self::SUCCESS;
        }

        try {
            $effects = $importSideEffects->handle(is_string($sideEffects) ? $sideEffects : base_path(ImportSideEffects::DEFAULT_PATH));
        } catch (InvalidSideEffects $e) {
            $this->components->error('Побочные эффекты не загружены, прежний список остался. Исправьте файл и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info(($effects->changed ? 'Побочные эффекты обновлены.' : 'Побочные эффекты не изменились.')
            ." Симптомов: {$effects->symptoms}, препаратов: {$effects->drugs}.");

        return self::SUCCESS;
    }
}
