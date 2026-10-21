<?php

namespace App\Actions;

use App\Exceptions\InvalidSafetyRules;
use App\Models\Icd10Code;
use Illuminate\Support\Facades\DB;

/**
 * Загрузка справочника МКБ-10 из CSV (code, name), выгруженного из НСИ Минздрава. Файл проверяется
 * целиком до записи. Коды сопоставляются по значению, названия обновляются. Код, которого больше
 * нет в файле, остаётся: на него могут ссылаться диагнозы пациентов.
 */
class ImportIcd10
{
    public const string DEFAULT_PATH = 'database/data/icd10.csv';

    private const int CHUNK = 1000;

    /**
     * @return int сколько кодов в файле
     *
     * @throws InvalidSafetyRules
     */
    public function handle(string $path): int
    {
        $rows = $this->read($path);

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                Icd10Code::query()->upsert($chunk, ['code'], ['name']);
            }
        });

        return count($rows);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private function read(string $path): array
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new InvalidSafetyRules(["Файл {$path} не найден."]);
        }

        try {
            if (fgetcsv($handle, escape: '') !== ['code', 'name']) {
                throw new InvalidSafetyRules(['Первая строка файла МКБ-10 должна быть «code,name».']);
            }

            $rows = [];
            $errors = [];
            $line = 1;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                [$code, $name] = array_pad(array_map(fn (?string $value): string => trim((string) $value), $row), 2, '');

                if (preg_match('/^'.Icd10Code::CODE_PATTERN.'$/', $code) !== 1 || $name === '') {
                    $errors[] = "Строка {$line}: нужен код вида F41 или F41.1 и название.";
                } elseif (isset($rows[$code])) {
                    $errors[] = "Строка {$line}: код {$code} повторяется.";
                } else {
                    $rows[$code] = ['code' => $code, 'name' => $name];
                }
            }
        } finally {
            fclose($handle);
        }

        if ($errors !== [] || $rows === []) {
            throw new InvalidSafetyRules($errors === [] ? ['В файле МКБ-10 нет кодов.'] : array_slice($errors, 0, 20));
        }

        return array_values($rows);
    }
}
