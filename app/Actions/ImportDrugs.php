<?php

namespace App\Actions;

use App\Exceptions\InvalidDrugCatalog;
use App\Models\Drug;
use App\Models\DrugGroup;
use App\Support\DrugImportResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка справочника препаратов из YAML-файла вместе с группами препаратов для правил
 * взаимодействий. Файлы проверяются целиком до записи в БД, поэтому при ошибке справочник
 * остаётся прежним. Записи сопоставляются по коду, и повторная загрузка тех же файлов ничего
 * не меняет. Препараты и дозировки, которых в файле больше нет, выключаются, а не удаляются:
 * на них ссылаются назначения. Группа, которой больше нет в файле, удаляется: на неё ссылаются
 * только препараты и правила, а их файлы проверяются по списку групп.
 *
 * @phpstan-type StrengthData array{mg: int|float|string, divisible: bool}
 * @phpstan-type SourceData array{trade_name: string, document: string, registration_number: string, url: string, edition: string}
 * @phpstan-type DrugData array{code: string, name: string, inn: string, atc: string, groups: list<string>, dosage_form: string, strengths: list<StrengthData>, regimen: string, patient_instruction: string, missed_dose: string, window_hours: int|string|null, source: SourceData}
 * @phpstan-type GroupData array{code: string, name: string}
 */
class ImportDrugs
{
    public const string DEFAULT_PATH = 'database/data/drugs.yaml';

    public const string DEFAULT_GROUPS_PATH = 'database/data/drug-groups.yaml';

    private const array FIELDS = [
        'code',
        'name',
        'inn',
        'atc',
        'groups',
        'dosage_form',
        'strengths',
        'regimen',
        'patient_instruction',
        'missed_dose',
        'window_hours',
        'source',
    ];

    private const array STRENGTH_FIELDS = ['mg', 'divisible'];

    private const array SOURCE_FIELDS = ['trade_name', 'document', 'registration_number', 'url', 'edition'];

    /**
     * @throws InvalidDrugCatalog
     */
    public function handle(string $path, string $groupsPath): DrugImportResult
    {
        $groups = $this->validateGroups($this->parse($groupsPath));
        $drugs = $this->validate($this->parse($path), array_column($groups, 'code'));

        return DB::transaction(fn (): DrugImportResult => $this->sync($drugs, $this->syncGroups($groups)));
    }

    private function parse(string $path): mixed
    {
        if (! is_file($path)) {
            throw new InvalidDrugCatalog(["Файл {$path} не найден."]);
        }

        try {
            return Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidDrugCatalog(['Ошибка разметки YAML: '.$e->getMessage()]);
        }
    }

    /**
     * @return list<GroupData>
     */
    private function validateGroups(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new InvalidDrugCatalog(['Файл групп должен содержать список групп.']);
        }

        $errors = [];
        $codes = [];

        foreach ($data as $index => $group) {
            $label = is_array($group) && is_string($group['code'] ?? null) ? "Группа {$group['code']}" : 'Группа №'.($index + 1);
            $validator = Validator::make(is_array($group) ? $group : [], [
                'code' => ['required', 'string', 'max:64', 'regex:/^'.DrugGroup::CODE_PATTERN.'$/'],
                'name' => ['required', 'string', 'max:255'],
            ], [], ['code' => 'code', 'name' => 'name']);

            foreach (self::extraKeys(is_array($group) ? $group : [], ['code', 'name'], '') as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "{$label}: {$message}";
            }

            if (is_array($group) && is_string($group['code'] ?? null)) {
                if (in_array($group['code'], $codes, true)) {
                    $errors[] = "{$label}: код повторяется.";
                }

                $codes[] = $group['code'];
            }
        }

        if ($errors !== []) {
            throw new InvalidDrugCatalog($errors);
        }

        /** @var list<GroupData> $data */
        return $data;
    }

    /**
     * @param  list<string>  $groups  коды групп из файла групп
     * @return list<DrugData>
     */
    private function validate(mixed $data, array $groups): array
    {
        if (! is_array($data) || ! array_is_list($data) || $data === []) {
            throw new InvalidDrugCatalog(['Файл должен содержать список препаратов.']);
        }

        $errors = [];
        $positionsByCode = [];

        foreach ($data as $index => $drug) {
            $position = $index + 1;

            if (! is_array($drug) || array_is_list($drug)) {
                $errors[] = "Препарат №{$position}: запись должна состоять из полей препарата.";

                continue;
            }

            $code = $drug['code'] ?? null;
            $label = is_string($code) && $code !== '' ? "Препарат {$code}" : "Препарат №{$position}";

            foreach ($this->unknownFields($drug) as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            foreach ($this->validationErrors($drug) as $message) {
                $errors[] = "{$label}: {$message}";
            }

            foreach (is_array($drug['groups'] ?? null) ? $drug['groups'] : [] as $group) {
                if (is_string($group) && ! in_array($group, $groups, true)) {
                    $errors[] = "{$label}: группы {$group} нет в файле групп.";
                }
            }

            if (is_string($code)) {
                $positionsByCode[$code][] = $position;
            }
        }

        foreach ($positionsByCode as $code => $positions) {
            if (count($positions) > 1) {
                $errors[] = "Препарат {$code}: код повторяется в записях ".implode(', ', $positions).'.';
            }
        }

        if ($errors !== []) {
            throw new InvalidDrugCatalog($errors);
        }

        /** @var list<DrugData> $data */
        return $data;
    }

    /**
     * @param  array<mixed>  $drug
     * @return list<string>
     */
    private function validationErrors(array $drug): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:64', 'regex:/^'.Drug::CODE_PATTERN.'$/'],
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:255'],
            'atc' => ['required', 'string', 'max:16'],
            // Ключ обязателен, как window_hours: препарат без групп записывается явно, [].
            'groups' => ['present', 'list'],
            'groups.*' => ['string', 'distinct'],
            'dosage_form' => ['required', 'string', 'max:255'],
            'strengths' => ['required', 'list'],
            'strengths.*' => ['array'],
            'strengths.*.mg' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,2', 'distinct'],
            'strengths.*.divisible' => ['required', 'boolean:strict'],
            'regimen' => ['required', 'string'],
            'patient_instruction' => ['required', 'string'],
            'missed_dose' => ['required', 'string'],
            // Ключ обязателен, чтобы правило окна по умолчанию было выбрано явно, а не из-за опечатки.
            'window_hours' => ['present', 'nullable', 'integer', 'min:1', 'max:24'],
            'source' => ['required', 'array'],
            'source.trade_name' => ['required', 'string', 'max:255'],
            'source.document' => ['required', 'string', 'max:255'],
            'source.registration_number' => ['required', 'string', 'max:255'],
            'source.url' => ['required', 'string', 'max:2048', 'url:https,http'],
            'source.edition' => ['required', 'string', 'max:255'],
        ];

        // В сообщениях поле называется так же, как в файле. Поля дозировок (strengths.0.mg)
        // валидатор и так показывает по пути.
        $fields = array_values(array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '*')));

        $validator = Validator::make($drug, $rules, [
            'window_hours.present' => 'Поле window_hours обязательно. Если окно в источнике не задано, укажите ~.',
            'groups.present' => 'Поле groups обязательно. Если препарат не входит в группы, укажите [].',
        ], array_combine($fields, $fields));

        return array_values($validator->errors()->all());
    }

    /**
     * Поля, которых нет в формате файла. Обычно это опечатка в имени поля, из-за которой
     * значение молча потерялось бы.
     *
     * @param  array<mixed>  $drug
     * @return list<string>
     */
    private function unknownFields(array $drug): array
    {
        $unknown = self::extraKeys($drug, self::FIELDS, '');

        if (is_array($drug['source'] ?? null)) {
            $unknown = [...$unknown, ...self::extraKeys($drug['source'], self::SOURCE_FIELDS, 'source.')];
        }

        if (is_array($drug['strengths'] ?? null)) {
            foreach ($drug['strengths'] as $index => $strength) {
                if (is_array($strength)) {
                    $unknown = [...$unknown, ...self::extraKeys($strength, self::STRENGTH_FIELDS, "strengths.{$index}.")];
                }
            }
        }

        return $unknown;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function extraKeys(array $data, array $allowed, string $prefix): array
    {
        return array_values(array_map(
            fn (int|string $key): string => $prefix.$key,
            array_diff(array_keys($data), $allowed),
        ));
    }

    /**
     * @param  list<DrugData>  $drugs
     */
    private function sync(array $drugs, bool $groupsChanged): DrugImportResult
    {
        $existing = Drug::query()->with('strengths')->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($drugs as $data) {
            $drug = $existing->get($data['code']) ?? new Drug(['code' => $data['code']]);
            $isNew = ! $drug->exists;

            $drug->fill([
                'name' => $data['name'],
                'inn' => $data['inn'],
                'atc' => $data['atc'],
                'groups' => $data['groups'],
                'dosage_form' => $data['dosage_form'],
                'regimen' => $data['regimen'],
                'patient_instruction' => $data['patient_instruction'],
                'missed_dose' => $data['missed_dose'],
                'window_hours' => $data['window_hours'],
                'source_trade_name' => $data['source']['trade_name'],
                'source_document' => $data['source']['document'],
                'source_registration_number' => $data['source']['registration_number'],
                'source_url' => $data['source']['url'],
                'source_edition' => $data['source']['edition'],
                'is_active' => true,
            ]);

            $changed = $drug->isDirty();
            $drug->save();
            $changed = $this->syncStrengths($drug, $data['strengths']) || $changed;

            if ($isNew) {
                $created[] = $drug->code;
            } elseif ($changed) {
                $updated[] = $drug->code;
            }
        }

        $codes = array_column($drugs, 'code');
        $deactivated = [];

        foreach ($existing as $drug) {
            if ($drug->is_active && ! in_array($drug->code, $codes, true)) {
                $drug->update(['is_active' => false]);
                $deactivated[] = $drug->code;
            }
        }

        return new DrugImportResult(count($drugs), $created, $updated, $deactivated, $groupsChanged);
    }

    /**
     * @param  list<GroupData>  $groups
     * @return bool Изменилась ли хотя бы одна группа.
     */
    private function syncGroups(array $groups): bool
    {
        $codes = array_column($groups, 'code');
        $changed = DrugGroup::query()->whereNotIn('code', $codes)->delete() > 0;

        foreach ($groups as $data) {
            $group = DrugGroup::query()->firstOrNew(['code' => $data['code']]);
            $group->fill(['name' => $data['name']]);
            $changed = $changed || $group->isDirty();
            $group->save();
        }

        return $changed;
    }

    /**
     * @param  list<StrengthData>  $strengths
     * @return bool Изменилась ли хотя бы одна дозировка.
     */
    private function syncStrengths(Drug $drug, array $strengths): bool
    {
        // У нового препарата дозировок ещё нет, у прежних они загружены вместе с препаратом.
        $existing = $drug->relationLoaded('strengths') ? $drug->strengths : new Collection;
        $changed = false;
        $kept = [];

        foreach ($strengths as $data) {
            // В том же виде, что отдаёт каст decimal:2, чтобы найти дозировку по значению.
            $mg = number_format((float) $data['mg'], 2, '.', '');
            $strength = $existing->firstWhere('mg', $mg) ?? $drug->strengths()->make(['mg' => $mg]);

            $strength->fill(['divisible' => $data['divisible'], 'is_active' => true]);
            $changed = $changed || $strength->isDirty();
            $strength->save();

            $kept[] = $strength->id;
        }

        foreach ($existing as $strength) {
            if ($strength->is_active && ! in_array($strength->id, $kept, true)) {
                $strength->update(['is_active' => false]);
                $changed = true;
            }
        }

        return $changed;
    }
}
