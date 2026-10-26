<?php

namespace App\Actions;

use App\Enums\DrugKind;
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
 * взаимодействий и списком препаратов от других врачей. Файлы проверяются целиком до записи
 * в БД, поэтому при ошибке справочник остаётся прежним. Записи сопоставляются по коду, и повторная
 * загрузка тех же файлов ничего не меняет. Препараты и дозировки, которых в файле больше нет,
 * выключаются, а не удаляются: на них ссылаются назначения. Загрузка одного файла выключает только
 * препараты своего вида. Группа, которой больше нет в файле, удаляется: на неё ссылаются только
 * препараты и правила, а их файлы проверяются по списку групп.
 *
 * @phpstan-type StrengthData array{mg: int|float|string, divisible: bool}
 * @phpstan-type SourceData array{trade_name: string, document: string, registration_number: string, url: string, edition: string}
 * @phpstan-type DrugData array{code: string, name: string, inn: string, atc: string, trade_names: list<string>, groups: list<string>, dosage_form: string, strengths: list<StrengthData>, regimen: string, patient_instruction: string, missed_dose: string, window_hours: int|string|null, source: SourceData}
 * @phpstan-type RegistrationData array{trade_name: string, number: string}
 * @phpstan-type KnownDrugData array{code: string, name: string, inn: string, atc: string, trade_names: list<string>, groups: list<string>, reason: string, registration: RegistrationData}
 * @phpstan-type GroupData array{code: string, name: string}
 */
class ImportDrugs
{
    public const string DEFAULT_PATH = 'database/data/drugs.yaml';

    public const string DEFAULT_GROUPS_PATH = 'database/data/drug-groups.yaml';

    public const string DEFAULT_KNOWN_PATH = 'database/data/known-drugs.yaml';

    private const array FIELDS = [
        'code',
        'name',
        'inn',
        'atc',
        'trade_names',
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

    private const array KNOWN_FIELDS = ['code', 'name', 'inn', 'atc', 'trade_names', 'groups', 'reason', 'registration'];

    private const array REGISTRATION_FIELDS = ['trade_name', 'number'];

    private const array NAMES_AND_GROUPS_MESSAGES = [
        'groups.present' => 'Поле groups обязательно. Если препарат не входит в группы, укажите [].',
        'trade_names.present' => 'Поле trade_names обязательно. Если торговых названий для поиска нет, укажите [].',
    ];

    /**
     * @param  string|null  $knownPath  Файл списка препаратов от других врачей. Без него список не меняется.
     *
     * @throws InvalidDrugCatalog
     */
    public function handle(string $path, string $groupsPath, ?string $knownPath = null): DrugImportResult
    {
        $groups = $this->validateGroups($this->parse($groupsPath));
        $groupCodes = array_column($groups, 'code');
        /** @var list<DrugData> $drugs */
        $drugs = $this->validate($this->parse($path), DrugKind::Catalog, $groupCodes);
        /** @var list<KnownDrugData>|null $known */
        $known = $knownPath === null ? null : $this->validate($this->parse($knownPath), DrugKind::Known, $groupCodes);
        $this->validateAcrossFiles($drugs, $known);

        return DB::transaction(function () use ($groups, $drugs, $known): DrugImportResult {
            $groupsChanged = $this->syncGroups($groups);
            $catalog = $this->sync($drugs);
            $list = $known === null ? null : $this->syncKnown($known);

            return new DrugImportResult(
                total: count($drugs),
                created: [...$catalog['created'], ...$list['created'] ?? []],
                updated: [...$catalog['updated'], ...$list['updated'] ?? []],
                deactivated: [...$catalog['deactivated'], ...$list['deactivated'] ?? []],
                groupsChanged: $groupsChanged,
                knownTotal: $known === null ? null : count($known),
            );
        });
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
     * @return list<DrugData>|list<KnownDrugData>
     */
    private function validate(mixed $data, DrugKind $kind, array $groups): array
    {
        if (! is_array($data) || ! array_is_list($data) || $data === []) {
            throw new InvalidDrugCatalog([$kind === DrugKind::Known
                ? 'Файл списка препаратов от других врачей должен содержать список препаратов.'
                : 'Файл должен содержать список препаратов.']);
        }

        $errors = [];
        $positionsByCode = [];

        foreach ($data as $index => $drug) {
            $position = $index + 1;

            if (! is_array($drug) || array_is_list($drug)) {
                $errors[] = self::label($kind, "№{$position}").': запись должна состоять из полей препарата.';

                continue;
            }

            $code = $drug['code'] ?? null;
            $label = self::label($kind, is_string($code) && $code !== '' ? $code : "№{$position}");

            foreach ($kind === DrugKind::Known ? $this->unknownKnownFields($drug) : $this->unknownFields($drug) as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            foreach ($kind === DrugKind::Known ? $this->knownValidationErrors($drug) : $this->validationErrors($drug) as $message) {
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
                $errors[] = self::label($kind, $code).': код повторяется в записях '.implode(', ', $positions).'.';
            }
        }

        if ($errors !== []) {
            throw new InvalidDrugCatalog($errors);
        }

        /** @var list<DrugData>|list<KnownDrugData> $data */
        return $data;
    }

    /**
     * Код и торговое название указывают на один препарат: код не повторяется в справочнике и списке
     * и не меняет вид препарата в БД, торговое название не встречается у двух препаратов. Без файла
     * списка препараты справочника сверяются с препаратами списка в БД.
     *
     * @param  list<DrugData>  $drugs
     * @param  list<KnownDrugData>|null  $known
     *
     * @throws InvalidDrugCatalog
     */
    private function validateAcrossFiles(array $drugs, ?array $known): void
    {
        $errors = [];
        $catalogCodes = array_column($drugs, 'code');
        $knownCodes = $known === null ? [] : array_column($known, 'code');
        $repeated = array_intersect($knownCodes, $catalogCodes);

        foreach ($repeated as $code) {
            $errors[] = "Препарат {$code}: код есть и в справочнике, и в списке препаратов от других врачей. Препарат справочника в список не добавляют.";
        }

        /** @var \Illuminate\Support\Collection<string, DrugKind> $stored */
        $stored = Drug::query()->pluck('kind', 'code');

        foreach ([[DrugKind::Catalog, $catalogCodes], [DrugKind::Known, $knownCodes]] as [$kind, $codes]) {
            foreach (array_diff($codes, $repeated) as $code) {
                $was = $stored->get($code);

                if ($was !== null && $was !== $kind) {
                    $errors[] = self::label($kind, $code).': код уже занят '
                        .($was === DrugKind::Known ? 'препаратом из списка препаратов от других врачей' : 'препаратом справочника').'. Возьмите другой код.';
                }
            }
        }

        $owners = [];

        foreach ([...$drugs, ...$known ?? []] as $drug) {
            foreach ([$drug['name'], ...$drug['trade_names']] as $name) {
                $owners[self::searchKey($name)][$drug['code']] = $name;
            }
        }

        foreach ($owners as $codes) {
            if (count($codes) > 1) {
                $errors[] = 'Название «'.reset($codes).'» есть у нескольких препаратов: '.implode(', ', array_keys($codes)).'.';
            }
        }

        if ($errors !== []) {
            throw new InvalidDrugCatalog($errors);
        }
    }

    private static function label(DrugKind $kind, string $code): string
    {
        return ($kind === DrugKind::Known ? 'Препарат списка ' : 'Препарат ').$code;
    }

    /**
     * Название для сравнения: без регистра, «ё» и значков ® и ™.
     */
    private static function searchKey(string $name): string
    {
        return trim(str_replace(['ё', '®', '™'], ['е', '', ''], mb_strtolower($name)));
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
            ...self::namesAndGroupsRules(),
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
            ...self::NAMES_AND_GROUPS_MESSAGES,
        ], array_combine($fields, $fields));

        return array_values($validator->errors()->all());
    }

    /**
     * @return array<string, list<string>>
     */
    private static function namesAndGroupsRules(): array
    {
        return [
            // Ключи обязательны, как window_hours: пустой список записывается явно, [].
            'trade_names' => ['present', 'list'],
            'trade_names.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'groups' => ['present', 'list'],
            'groups.*' => ['string', 'distinct'],
        ];
    }

    /**
     * Запись списка препаратов от других врачей: без инструкции, дозировок и окна приёма, с основанием
     * и номером РУ одного препарата из ГРЛС.
     *
     * @param  array<mixed>  $drug
     * @return list<string>
     */
    private function knownValidationErrors(array $drug): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:64', 'regex:/^'.Drug::CODE_PATTERN.'$/'],
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['required', 'string', 'max:255'],
            'atc' => ['required', 'string', 'max:16'],
            ...self::namesAndGroupsRules(),
            'reason' => ['required', 'string'],
            'registration' => ['required', 'array'],
            'registration.trade_name' => ['required', 'string', 'max:255'],
            'registration.number' => ['required', 'string', 'max:255'],
        ];

        $fields = array_values(array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '*')));

        $validator = Validator::make($drug, $rules, self::NAMES_AND_GROUPS_MESSAGES, array_combine($fields, $fields));

        return array_values($validator->errors()->all());
    }

    /**
     * @param  array<mixed>  $drug
     * @return list<string>
     */
    private function unknownKnownFields(array $drug): array
    {
        $unknown = self::extraKeys($drug, self::KNOWN_FIELDS, '');

        if (is_array($drug['registration'] ?? null)) {
            $unknown = [...$unknown, ...self::extraKeys($drug['registration'], self::REGISTRATION_FIELDS, 'registration.')];
        }

        return $unknown;
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
     * @return array{created: list<string>, updated: list<string>, deactivated: list<string>}
     */
    private function sync(array $drugs): array
    {
        $existing = Drug::query()->catalog()->with('strengths')->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($drugs as $data) {
            $drug = $existing->get($data['code']) ?? new Drug(['code' => $data['code'], 'kind' => DrugKind::Catalog]);
            $isNew = ! $drug->exists;

            $drug->fill([
                'name' => $data['name'],
                'inn' => $data['inn'],
                'atc' => $data['atc'],
                'trade_names' => $data['trade_names'],
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

        return ['created' => $created, 'updated' => $updated, 'deactivated' => self::deactivateMissing($existing, array_column($drugs, 'code'))];
    }

    /**
     * @param  list<KnownDrugData>  $drugs
     * @return array{created: list<string>, updated: list<string>, deactivated: list<string>}
     */
    private function syncKnown(array $drugs): array
    {
        $existing = Drug::query()->where('kind', DrugKind::Known)->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($drugs as $data) {
            $drug = $existing->get($data['code']) ?? new Drug(['code' => $data['code'], 'kind' => DrugKind::Known]);
            $isNew = ! $drug->exists;

            $drug->fill([
                'name' => $data['name'],
                'inn' => $data['inn'],
                'atc' => $data['atc'],
                'trade_names' => $data['trade_names'],
                'groups' => $data['groups'],
                'reason' => $data['reason'],
                'source_trade_name' => $data['registration']['trade_name'],
                'source_registration_number' => $data['registration']['number'],
                'is_active' => true,
            ]);

            $changed = $drug->isDirty();
            $drug->save();

            if ($isNew) {
                $created[] = $drug->code;
            } elseif ($changed) {
                $updated[] = $drug->code;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'deactivated' => self::deactivateMissing($existing, array_column($drugs, 'code'))];
    }

    /**
     * Выключает препараты, которых больше нет в файле.
     *
     * @param  Collection<string, Drug>  $existing  препараты того же вида из БД
     * @param  list<string>  $codes  коды из файла
     * @return list<string>
     */
    private static function deactivateMissing(Collection $existing, array $codes): array
    {
        $deactivated = [];

        foreach ($existing as $drug) {
            if ($drug->is_active && ! in_array($drug->code, $codes, true)) {
                $drug->update(['is_active' => false]);
                $deactivated[] = $drug->code;
            }
        }

        return $deactivated;
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
