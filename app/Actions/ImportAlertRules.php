<?php

namespace App\Actions;

use App\Enums\AlertLevel;
use App\Exceptions\InvalidAlertRules;
use App\Models\AlertRule;
use App\Models\Scale;
use App\Support\Alerts\AlertLanguage;
use App\Support\Alerts\AlertRuleImportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка правил алертинга из YAML-файлов, по файлу на правило. Всё проверяется до записи
 * в БД: при ошибке в любом файле не загружается ничего. Правила сопоставляются по коду,
 * повторная загрузка тех же файлов ничего не меняет. Правило, которого в файлах больше нет,
 * выключается, а не удаляется: на него ссылаются алерты.
 *
 * Опросники должны быть загружены раньше: условия ссылаются на них по коду.
 *
 * @phpstan-type RuleData array{
 *     code: string,
 *     name: string,
 *     level: string,
 *     condition: string,
 *     text: string,
 *     source: array{document: string, section: string, url: string, quote?: string|null},
 * }
 */
class ImportAlertRules
{
    public const string DEFAULT_PATH = 'database/data/alerts';

    private const array FIELDS = ['code', 'name', 'level', 'condition', 'text', 'source'];

    private const array SOURCE_FIELDS = ['document', 'section', 'url', 'quote'];

    public function __construct(private readonly AlertLanguage $language) {}

    /**
     * @throws InvalidAlertRules
     */
    public function handle(string $path): AlertRuleImportResult
    {
        $rules = $this->validate($this->files($path));

        return DB::transaction(fn (): AlertRuleImportResult => $this->sync($rules));
    }

    /**
     * @return array<string, mixed> имя файла => содержимое
     */
    private function files(string $path): array
    {
        if (! is_dir($path)) {
            throw new InvalidAlertRules(["Каталог правил алертинга {$path} не найден."]);
        }

        $files = glob(rtrim($path, '/').'/*.yaml') ?: [];
        sort($files);

        if ($files === []) {
            throw new InvalidAlertRules(["В каталоге {$path} нет файлов правил *.yaml."]);
        }

        $parsed = [];

        foreach ($files as $file) {
            try {
                $parsed[basename($file)] = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new InvalidAlertRules([basename($file).': ошибка разметки YAML: '.$e->getMessage()]);
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $files
     * @return list<RuleData>
     */
    private function validate(array $files): array
    {
        $scales = Scale::query()->active()->get()->keyBy('code');
        $errors = [];

        foreach ($files as $name => $rule) {
            if (! is_array($rule) || array_is_list($rule)) {
                $errors[] = "{$name}: файл должен состоять из полей правила.";

                continue;
            }

            foreach ([...self::extraKeys($rule, self::FIELDS), ...array_map(fn (string $field): string => "source.{$field}", self::extraKeys(is_array($rule['source'] ?? null) ? $rule['source'] : [], self::SOURCE_FIELDS))] as $field) {
                $errors[] = "{$name}: неизвестное поле {$field}.";
            }

            $validator = Validator::make($rule, [
                'code' => ['required', 'string', 'max:64', 'regex:/^'.AlertRule::CODE_PATTERN.'$/'],
                'name' => ['required', 'string', 'max:255'],
                'level' => ['required', Rule::enum(AlertLevel::class)],
                'condition' => ['required', 'string'],
                'text' => ['required', 'string'],
                'source' => ['required', 'array'],
                'source.document' => ['required', 'string', 'max:255'],
                'source.section' => ['required', 'string', 'max:255'],
                'source.url' => ['required', 'string', 'max:2048', 'url:https,http'],
                'source.quote' => ['nullable', 'string'],
            ], [], array_combine(self::FIELDS, self::FIELDS));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "{$name}: {$message}";
            }

            $code = $rule['code'] ?? null;

            if (is_string($code) && $name !== $code.'.yaml') {
                $errors[] = "{$name}: файл правила {$code} должен называться {$code}.yaml.";
            }

            if (is_string($rule['condition'] ?? null)) {
                foreach ($this->conditionErrors($rule['condition'], $scales->all()) as $message) {
                    $errors[] = "{$name}: condition: {$message}";
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidAlertRules($errors);
        }

        /** @var list<RuleData> $rules */
        $rules = array_values($files);

        return $rules;
    }

    /**
     * Условие разбирается, ссылается хотя бы на один опросник, а опросники и вопросы в нём есть.
     *
     * @param  array<string, Scale>  $scales
     * @return list<string>
     */
    private function conditionErrors(string $condition, array $scales): array
    {
        try {
            $references = $this->language->references($condition);
        } catch (SyntaxError $e) {
            return [$e->getMessage()];
        }

        if ($references === []) {
            return ['условие должно смотреть на ответы: answer() или total().'];
        }

        $errors = [];

        foreach ($references as $reference) {
            $scale = $scales[$reference[1]] ?? null;

            if ($scale === null) {
                $errors[] = "опросника {$reference[1]} нет среди загруженных.";
            } elseif ($reference[0] === 'answer' && ($reference[2] < 1 || $reference[2] > count($scale->items))) {
                $errors[] = "в опроснике {$scale->code} нет вопроса {$reference[2]}, вопросов ".count($scale->items).'.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<RuleData>  $rules
     */
    private function sync(array $rules): AlertRuleImportResult
    {
        $existing = AlertRule::query()->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($rules as $data) {
            $attributes = [
                'name' => $data['name'],
                'level' => $data['level'],
                'condition' => $data['condition'],
                'text' => $data['text'],
                'source_document' => $data['source']['document'],
                'source_section' => $data['source']['section'],
                'source_url' => $data['source']['url'],
                'source_quote' => $data['source']['quote'] ?? null,
            ];

            $rule = $existing->get($data['code']) ?? new AlertRule(['code' => $data['code']]);
            $isNew = ! $rule->exists;
            $version = hash('sha256', $data['code'].'|'.json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            if (! $isNew && $rule->version === $version && $rule->is_active) {
                continue;
            }

            $rule->fill([...$attributes, 'version' => $version, 'is_active' => true])->save();

            if ($isNew) {
                $created[] = $data['code'];
            } else {
                $updated[] = $data['code'];
            }
        }

        $codes = array_column($rules, 'code');
        $deactivated = [];

        foreach ($existing as $rule) {
            if ($rule->is_active && ! in_array($rule->code, $codes, true)) {
                $rule->update(['is_active' => false]);
                $deactivated[] = $rule->code;
            }
        }

        return new AlertRuleImportResult(count($rules), $created, $updated, $deactivated);
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
        if (array_is_list($data)) {
            return [];
        }

        return array_values(array_map(
            fn (int|string $key): string => (string) $key,
            array_diff(array_keys($data), $allowed),
        ));
    }
}
