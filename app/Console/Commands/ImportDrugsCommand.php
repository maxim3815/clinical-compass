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
        {--known= : Путь к списку препаратов от других врачей. По умолчанию database/data/known-drugs.yaml}
        {--side-effects= : Путь к файлу побочных эффектов. По умолчанию database/data/side-effects.yaml}';

    /**
     * @var string
     */
    protected $description = 'Загрузить справочник препаратов, список препаратов от других врачей и побочные эффекты из файлов';

    public function handle(ImportDrugs $importDrugs, ImportSideEffects $importSideEffects): int
    {
        $path = $this->argument('path');
        $groups = $this->option('groups');
        $known = $this->option('known');
        $sideEffects = $this->option('side-effects');

        // Для своего файла справочника список задают явно, как побочные эффекты: в стандартном
        // списке могли бы оказаться коды из стандартного справочника.
        $knownPath = match (true) {
            is_string($known) => $known,
            is_string($path) => null,
            default => base_path(ImportDrugs::DEFAULT_KNOWN_PATH),
        };

        try {
            $result = $importDrugs->handle(
                is_string($path) ? $path : base_path(ImportDrugs::DEFAULT_PATH),
                is_string($groups) ? $groups : base_path(ImportDrugs::DEFAULT_GROUPS_PATH),
                $knownPath,
            );
        } catch (InvalidDrugCatalog $e) {
            $this->components->error('Справочник не загружен, в базе ничего не изменилось. Исправьте файл и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $totals = "Препаратов в справочнике: {$result->total}"
            .($result->knownTotal === null ? '.' : ", в списке препаратов от других врачей: {$result->knownTotal}.");

        if (! $result->hasChanges()) {
            $this->components->info("Справочник не изменился. {$totals}");
        } else {
            $this->components->info("Справочник обновлён. {$totals}");

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
