<?php

namespace App\Console\Commands;

use App\Actions\ImportScales;
use App\Exceptions\InvalidScales;
use Illuminate\Console\Command;

class ImportScalesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:scales:import
        {path? : Каталог файлов шкал. По умолчанию database/data/scales}';

    /**
     * @var string
     */
    protected $description = 'Загрузить опросники пациента (GAD-7, PHQ-9) из файлов';

    public function handle(ImportScales $importScales): int
    {
        $path = $this->argument('path');

        try {
            $result = $importScales->handle(is_string($path) ? $path : base_path(ImportScales::DEFAULT_PATH));
        } catch (InvalidScales $e) {
            $this->components->error('Шкалы не загружены, в базе ничего не изменилось. Исправьте файлы и запустите команду снова.');
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->components->info("Шкалы не изменились. Шкал в файлах: {$result->total}.");

            return self::SUCCESS;
        }

        $this->components->info("Шкалы обновлены. Шкал в файлах: {$result->total}.");

        foreach (['Добавлены' => $result->created, 'Изменены' => $result->updated, 'Выключены' => $result->deactivated] as $title => $codes) {
            if ($codes !== []) {
                $this->components->twoColumnDetail($title, implode(', ', $codes));
            }
        }

        return self::SUCCESS;
    }
}
