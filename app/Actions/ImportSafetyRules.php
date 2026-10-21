<?php

namespace App\Actions;

use App\Enums\ObservationValueType;
use App\Enums\SafetyLevel;
use App\Enums\SafetyRuleType;
use App\Exceptions\InvalidSafetyRules;
use App\Models\Drug;
use App\Models\DrugGroup;
use App\Models\Icd10Code;
use App\Models\ObservationType;
use App\Models\SafetyRule;
use App\Support\Safety\RuleImportResult;
use App\Support\Safety\RuleLanguage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка видов фактов и правил проверки назначения из YAML-файлов. Всё проверяется до записи
 * в БД: при ошибке в любом файле не загружается ничего. Записи сопоставляются по коду, повторная
 * загрузка тех же файлов ничего не меняет. Правила и виды фактов, которых в файлах больше нет,
 * выключаются, а не удаляются: на них ссылаются находки, решения врача и внесённые факты.
 *
 * Справочник препаратов и группы должны быть загружены раньше: правила ссылаются на них по коду.
 *
 * @phpstan-type TypeData array{code: string, name: string, value: string, unit?: string|null, min?: int|float|null, max?: int|float|null, options?: list<array{value: string, label: string}>, female_only?: bool}
 * @phpstan-type SourceData array{document: string, section: string, url: string, quote?: string|null}
 * @phpstan-type RuleData array{id: string, type: string, level: string, condition?: string, codes?: list<string>, text: string, advice?: string|null, source: SourceData}
 * @phpstan-type FileData array{drug: string, rules: list<RuleData>}
 */
class ImportSafetyRules
{
    public const string DEFAULT_RULES_PATH = 'database/data/rules';

    public const string DEFAULT_TYPES_PATH = 'database/data/observation-types.yaml';

    private const array TYPE_FIELDS = ['code', 'name', 'value', 'unit', 'min', 'max', 'options', 'female_only'];

    private const array RULE_FIELDS = ['id', 'type', 'level', 'condition', 'codes', 'text', 'advice', 'source'];

    private const array SOURCE_FIELDS = ['document', 'section', 'url', 'quote'];

    public function __construct(private readonly RuleLanguage $language) {}

    /**
     * @throws InvalidSafetyRules
     */
    public function handle(string $rulesPath, string $typesPath): RuleImportResult
    {
        $types = $this->validateTypes($this->parse($typesPath, basename($typesPath)));
        $files = $this->validateRules($this->rulesFiles($rulesPath), array_column($types, 'code'));

        return DB::transaction(fn (): RuleImportResult => $this->sync($files, $types));
    }

    /**
     * @return array<string, mixed> имя файла => содержимое
     */
    private function rulesFiles(string $path): array
    {
        if (! is_dir($path)) {
            throw new InvalidSafetyRules(["Каталог правил {$path} не найден."]);
        }

        $files = glob(rtrim($path, '/').'/*.yaml') ?: [];
        sort($files);

        if ($files === []) {
            throw new InvalidSafetyRules(["В каталоге {$path} нет файлов правил *.yaml."]);
        }

        $parsed = [];

        foreach ($files as $file) {
            $parsed[basename($file)] = $this->parse($file, basename($file));
        }

        return $parsed;
    }

    private function parse(string $path, string $name): mixed
    {
        if (! is_file($path)) {
            throw new InvalidSafetyRules(["Файл {$path} не найден."]);
        }

        try {
            return Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidSafetyRules(["{$name}: ошибка разметки YAML: ".$e->getMessage()]);
        }
    }

    /**
     * @return list<TypeData>
     */
    private function validateTypes(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data) || $data === []) {
            throw new InvalidSafetyRules(['Файл видов фактов должен содержать список видов.']);
        }

        $errors = [];
        $codes = [];

        foreach ($data as $index => $type) {
            $code = is_array($type) ? ($type['code'] ?? null) : null;
            $label = is_string($code) && $code !== '' ? "Вид факта {$code}" : 'Вид факта №'.($index + 1);

            if (! is_array($type) || array_is_list($type)) {
                $errors[] = "{$label}: запись должна состоять из полей вида факта.";

                continue;
            }

            foreach (self::extraKeys($type, self::TYPE_FIELDS) as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            $value = ObservationValueType::tryFrom(is_string($type['value'] ?? null) ? $type['value'] : '');

            $validator = Validator::make($type, [
                'code' => ['required', 'string', 'max:64', 'regex:/^'.ObservationType::CODE_PATTERN.'$/'],
                'name' => ['required', 'string', 'max:255'],
                'value' => ['required', Rule::enum(ObservationValueType::class)],
                'unit' => [Rule::prohibitedIf($value !== ObservationValueType::Number), 'nullable', 'string', 'max:64'],
                'min' => [Rule::prohibitedIf($value !== ObservationValueType::Number), 'nullable', 'numeric'],
                'max' => [Rule::prohibitedIf($value !== ObservationValueType::Number), 'nullable', 'numeric', 'gte:min'],
                'options' => [Rule::requiredIf($value === ObservationValueType::Choice), Rule::prohibitedIf($value !== ObservationValueType::Choice), 'list', 'min:2'],
                'options.*.value' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[a-z0-9_]+$/'],
                'options.*.label' => ['required', 'string', 'max:255'],
                'female_only' => ['sometimes', 'boolean:strict'],
            ], [], array_combine(self::TYPE_FIELDS, self::TYPE_FIELDS));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "{$label}: {$message}";
            }

            if (is_string($code)) {
                if (in_array($code, $codes, true)) {
                    $errors[] = "{$label}: код повторяется.";
                }

                $codes[] = $code;
            }
        }

        if ($errors !== []) {
            throw new InvalidSafetyRules($errors);
        }

        /** @var list<TypeData> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $files
     * @param  list<string>  $facts  коды видов фактов из файла
     * @return array<string, FileData>
     */
    private function validateRules(array $files, array $facts): array
    {
        /** @var list<string> $drugs */
        $drugs = Drug::query()->pluck('code')->values()->all();
        /** @var list<string> $groups */
        $groups = DrugGroup::query()->pluck('code')->values()->all();
        $errors = [];

        foreach ($files as $name => $file) {
            if (! is_array($file) || array_is_list($file)) {
                $errors[] = "{$name}: файл должен содержать поля drug и rules.";

                continue;
            }

            foreach (self::extraKeys($file, ['drug', 'rules']) as $field) {
                $errors[] = "{$name}: неизвестное поле {$field}.";
            }

            $drug = $file['drug'] ?? null;

            if (! is_string($drug) || ! in_array($drug, $drugs, true)) {
                $errors[] = "{$name}: препарата ".(is_string($drug) ? $drug : '(не указан)').' нет в справочнике.';
            } elseif ($name !== $drug.'.yaml') {
                $errors[] = "{$name}: файл правил препарата {$drug} должен называться {$drug}.yaml.";
            }

            $rules = $file['rules'] ?? null;

            if (! is_array($rules) || ! array_is_list($rules) || $rules === []) {
                $errors[] = "{$name}: поле rules должно содержать список правил.";

                continue;
            }

            $ids = [];

            foreach ($rules as $index => $rule) {
                $id = is_array($rule) ? ($rule['id'] ?? null) : null;
                $label = $name.', правило '.(is_string($id) && $id !== '' ? $id : '№'.($index + 1));

                if (! is_array($rule) || array_is_list($rule)) {
                    $errors[] = "{$label}: запись должна состоять из полей правила.";

                    continue;
                }

                $errors = [...$errors, ...array_map(fn (string $message): string => "{$label}: {$message}", $this->ruleErrors($rule, $facts, $drugs, $groups))];

                if (is_string($id)) {
                    if (in_array($id, $ids, true)) {
                        $errors[] = "{$label}: идентификатор повторяется.";
                    }

                    $ids[] = $id;
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidSafetyRules($errors);
        }

        /** @var array<string, FileData> $files */
        return $files;
    }

    /**
     * @param  array<mixed>  $rule
     * @param  list<string>  $facts
     * @param  list<string>  $drugs
     * @param  list<string>  $groups
     * @return list<string>
     */
    private function ruleErrors(array $rule, array $facts, array $drugs, array $groups): array
    {
        $errors = [];

        foreach (self::extraKeys($rule, self::RULE_FIELDS) as $field) {
            $errors[] = "неизвестное поле {$field}.";
        }

        if (is_array($rule['source'] ?? null)) {
            foreach (self::extraKeys($rule['source'], self::SOURCE_FIELDS) as $field) {
                $errors[] = "неизвестное поле source.{$field}.";
            }
        }

        $indication = ($rule['type'] ?? null) === SafetyRuleType::Indication->value;
        $fields = [...self::RULE_FIELDS, 'source.document', 'source.section', 'source.url', 'source.quote'];

        $validator = Validator::make($rule, [
            'id' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => ['required', Rule::enum(SafetyRuleType::class)],
            'level' => ['required', Rule::enum(SafetyLevel::class)],
            'condition' => [Rule::requiredIf(! $indication), Rule::prohibitedIf($indication), 'string'],
            'codes' => [Rule::requiredIf($indication), Rule::prohibitedIf(! $indication), 'list', 'min:1'],
            'codes.*' => ['string', 'distinct', 'regex:/^'.Icd10Code::CODE_PATTERN.'$/'],
            'text' => ['required', 'string'],
            'advice' => ['nullable', 'string'],
            'source' => ['required', 'array'],
            'source.document' => ['required', 'string', 'max:255'],
            'source.section' => ['required', 'string', 'max:255'],
            'source.url' => ['required', 'string', 'max:2048', 'url:https,http'],
            'source.quote' => ['nullable', 'string'],
        ], [
            'codes.*.regex' => 'код МКБ-10 :input записан неверно, нужен вид F41 или F41.1.',
        ], array_combine($fields, $fields));

        $errors = [...$errors, ...array_values($validator->errors()->all())];

        if (! $indication && is_string($rule['condition'] ?? null)) {
            $errors = [...$errors, ...$this->conditionErrors($rule['condition'], $facts, $drugs, $groups)];
        }

        return $errors;
    }

    /**
     * @param  list<string>  $facts
     * @param  list<string>  $drugs
     * @param  list<string>  $groups
     * @return list<string>
     */
    private function conditionErrors(string $condition, array $facts, array $drugs, array $groups): array
    {
        try {
            $arguments = $this->language->arguments($condition);
        } catch (SyntaxError $e) {
            return ['условие не разбирается: '.$e->getMessage()];
        }

        $errors = [];
        $known = ['fact' => $facts, 'drug' => $drugs, 'group' => $groups];
        $messages = [
            'fact' => 'вида факта %s нет в файле видов фактов.',
            'drug' => 'препарата %s нет в справочнике.',
            'group' => 'группы препаратов %s нет в справочнике.',
        ];

        foreach ($arguments as $kind => $values) {
            foreach ($values as $value) {
                if ($kind === 'icd10') {
                    if (preg_match('/^'.Icd10Code::CODE_PATTERN.'$/', $value) !== 1) {
                        $errors[] = "код МКБ-10 {$value} в условии записан неверно, нужен вид F41 или F41.1.";
                    }
                } elseif (! in_array($value, $known[$kind], true)) {
                    $errors[] = 'в условии '.sprintf($messages[$kind], $value);
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, FileData>  $files
     * @param  list<TypeData>  $types
     */
    private function sync(array $files, array $types): RuleImportResult
    {
        $typesChanged = $this->syncTypes($types);
        $drugs = Drug::query()->pluck('id', 'code');
        $existing = SafetyRule::query()->get()->keyBy('code');
        $created = [];
        $updated = [];
        $codes = [];
        $position = 0;

        foreach ($files as $file) {
            foreach ($file['rules'] as $data) {
                $code = $file['drug'].'.'.$data['id'];
                $codes[] = $code;
                $attributes = [
                    'drug_id' => $drugs[$file['drug']],
                    'type' => SafetyRuleType::from($data['type']),
                    'level' => SafetyLevel::from($data['level']),
                    'condition' => $data['condition'] ?? null,
                    'icd10_codes' => $data['codes'] ?? null,
                    'text' => $data['text'],
                    'advice' => $data['advice'] ?? null,
                    'source_document' => $data['source']['document'],
                    'source_section' => $data['source']['section'],
                    'source_url' => $data['source']['url'],
                    'source_quote' => $data['source']['quote'] ?? null,
                ];

                $rule = $existing->get($code) ?? new SafetyRule(['code' => $code]);
                $isNew = ! $rule->exists;

                $rule->fill([
                    ...$attributes,
                    'version' => self::version($code, $attributes),
                    'is_active' => true,
                    'position' => ++$position,
                ]);

                $changed = $rule->isDirty(['version', 'is_active']);
                $rule->save();

                if ($isNew) {
                    $created[] = $code;
                } elseif ($changed) {
                    $updated[] = $code;
                }
            }
        }

        $deactivated = [];

        foreach ($existing as $rule) {
            if ($rule->is_active && ! in_array($rule->code, $codes, true)) {
                $rule->update(['is_active' => false]);
                $deactivated[] = $rule->code;
            }
        }

        return new RuleImportResult(count($codes), $created, $updated, $deactivated, $typesChanged);
    }

    /**
     * @param  list<TypeData>  $types
     * @return list<string> коды изменённых видов
     */
    private function syncTypes(array $types): array
    {
        $existing = ObservationType::query()->get()->keyBy('code');
        $changed = [];

        foreach ($types as $index => $data) {
            $type = $existing->get($data['code']) ?? new ObservationType(['code' => $data['code']]);
            $value = ObservationValueType::from($data['value']);

            $type->fill([
                'name' => $data['name'],
                'value_type' => $value,
                'unit' => $data['unit'] ?? null,
                'min' => isset($data['min']) ? number_format((float) $data['min'], 2, '.', '') : null,
                'max' => isset($data['max']) ? number_format((float) $data['max'], 2, '.', '') : null,
                'options' => $value === ObservationValueType::Choice ? $data['options'] ?? null : null,
                'female_only' => $data['female_only'] ?? false,
                'position' => $index + 1,
                'is_active' => true,
            ]);

            if ($type->isDirty()) {
                $changed[] = $data['code'];
            }

            $type->save();
        }

        $codes = array_column($types, 'code');

        foreach ($existing as $type) {
            if ($type->is_active && ! in_array($type->code, $codes, true)) {
                $type->update(['is_active' => false]);
                $changed[] = $type->code;
            }
        }

        return $changed;
    }

    /**
     * Версия правила — хеш его содержимого: меняется только вместе с тем, что видит врач и проверяет правило.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function version(string $code, array $attributes): string
    {
        $content = [
            ...$attributes,
            'type' => $attributes['type']->value,
            'level' => $attributes['level']->value,
        ];

        return hash('sha256', $code.'|'.json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Поля, которых нет в формате файла: обычно опечатка, из-за которой значение молча потерялось бы.
     *
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function extraKeys(array $data, array $allowed): array
    {
        return array_values(array_map(
            fn (int|string $key): string => (string) $key,
            array_diff(array_keys($data), $allowed),
        ));
    }
}
