<?php

namespace App\Console\Commands;

use App\Actions\ImportIcd10;
use App\Exceptions\InvalidSafetyRules;
use Illuminate\Console\Command;

class ImportIcd10Command extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:icd10:import
        {path? : Путь к файлу. По умолчанию database/data/icd10.csv}';

    /**
     * @var string
     */
    protected $description = 'Загрузить справочник МКБ-10 (НСИ Минздрава) из файла';

    public function handle(ImportIcd10 $importIcd10): int
    {
        $path = $this->argument('path');

        try {
            $total = $importIcd10->handle(is_string($path) ? $path : base_path(ImportIcd10::DEFAULT_PATH));
        } catch (InvalidSafetyRules $e) {
            $this->components->error('Справочник МКБ-10 не загружен, в базе ничего не изменилось. Исправьте файл и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        $this->components->info("Справочник МКБ-10 загружен. Кодов в файле: {$total}.");

        return self::SUCCESS;
    }
}
