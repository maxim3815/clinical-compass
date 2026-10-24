<?php

namespace App\Actions;

use App\Enums\ReferralCategory;
use App\Exceptions\InvalidReferralRules;
use App\Models\ObservationType;
use App\Models\ReferralRule;
use App\Models\Scale;
use App\Support\Referrals\ReferralLanguage;
use App\Support\Referrals\ReferralRuleImportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка правил подсказки направления к психиатру из одного YAML-файла со списком правил.
 * Всё проверяется до записи в БД: при ошибке в любом правиле не загружается ничего. Правила
 * сопоставляются по коду, порядок в файле — порядок в основании подсказки. Правило, которого
 * в файле больше нет, выключается.
 *
 * Шкалы и виды фактов должны быть загружены раньше: условия ссылаются на них по коду.
 *
 * @phpstan-type RuleData array{
 *     code: string,
 *     category: string,
 *     condition: string,
 *     text: string,
 *     source: array{document: string, section: string, url: string, quote?: string|null},
 * }
 */
class ImportReferralRules
{
    public const string DEFAULT_PATH = 'database/data/referral-rules.yaml';

    private const array FIELDS = ['code', 'category', 'condition', 'text', 'source'];

    private const array SOURCE_FIELDS = ['document', 'section', 'url', 'quote'];

    public function __construct(private readonly ReferralLanguage $language) {}

    /**
     * @throws InvalidReferralRules
     */
    public function handle(string $path): ReferralRuleImportResult
    {
        $rules = $this->validate($this->parse($path));

        return DB::transaction(fn (): ReferralRuleImportResult => $this->sync($rules));
    }

    private function parse(string $path): mixed
    {
        if (! is_file($path)) {
            throw new InvalidReferralRules(["Файл правил подсказки {$path} не найден."]);
        }

        try {
            return Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidReferralRules(['Ошибка разметки YAML: '.$e->getMessage()]);
        }
    }

    /**
     * @return list<RuleData>
     */
    private function validate(mixed $rules): array
    {
        if (! is_array($rules) || ! array_is_list($rules) || $rules === []) {
            throw new InvalidReferralRules(['Файл должен быть непустым списком правил.']);
        }

        $scales = Scale::query()->active()->get()->keyBy('code')->all();
        $facts = array_values(array_map(strval(...), ObservationType::query()->pluck('code')->all()));
        $errors = [];
        $codes = [];

        foreach ($rules as $index => $rule) {
            $label = is_array($rule) && is_string($rule['code'] ?? null) ? $rule['code'] : 'правило '.($index + 1);

            if (! is_array($rule) || array_is_list($rule)) {
                $errors[] = "{$label}: правило должно состоять из полей.";

                continue;
            }

            foreach ([...self::extraKeys($rule, self::FIELDS), ...array_map(fn (string $field): string => "source.{$field}", self::extraKeys(is_array($rule['source'] ?? null) ? $rule['source'] : [], self::SOURCE_FIELDS))] as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            $validator = Validator::make($rule, [
                'code' => ['required', 'string', 'max:64', 'regex:/^'.ReferralRule::CODE_PATTERN.'$/'],
                'category' => ['required', Rule::enum(ReferralCategory::class)],
                'condition' => ['required', 'string'],
                'text' => ['required', 'string'],
                'source' => ['required', 'array'],
                'source.document' => ['required', 'string', 'max:255'],
                'source.section' => ['required', 'string', 'max:255'],
                'source.url' => ['required', 'string', 'max:2048', 'url:https,http'],
                'source.quote' => ['nullable', 'string'],
            ], [], array_combine(self::FIELDS, self::FIELDS));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "{$label}: {$message}";
            }

            if (is_string($rule['code'] ?? null)) {
                if (in_array($rule['code'], $codes, true)) {
                    $errors[] = "{$label}: код повторяется.";
                }

                $codes[] = $rule['code'];
            }

            if (is_string($rule['condition'] ?? null)) {
                foreach ($this->conditionErrors($rule['condition'], $scales, $facts) as $message) {
                    $errors[] = "{$label}: condition: {$message}";
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidReferralRules($errors);
        }

        /** @var list<RuleData> $rules */
        return $rules;
    }

    /**
     * Условие разбирается, на что-то смотрит, а шкалы, вопросы и факты в нём есть.
     *
     * @param  array<string, Scale>  $scales
     * @param  list<string>  $facts
     * @return list<string>
     */
    private function conditionErrors(string $condition, array $scales, array $facts): array
    {
        try {
            $references = $this->language->references($condition);
        } catch (SyntaxError $e) {
            return [$e->getMessage()];
        }

        if ($references === []) {
            return ['условие должно смотреть на шкалы или факты: answer(), total() или fact().'];
        }

        $errors = [];

        foreach ($references as $reference) {
            if ($reference[0] === 'fact') {
                if (! in_array($reference[1], $facts, true)) {
                    $errors[] = "вида факта {$reference[1]} нет среди загруженных.";
                }

                continue;
            }

            $scale = $scales[$reference[1]] ?? null;

            if ($scale === null) {
                $errors[] = "шкалы {$reference[1]} нет среди загруженных.";
            } elseif ($reference[0] === 'answer' && ($reference[2] < 1 || $reference[2] > count($scale->items))) {
                $errors[] = "в шкале {$scale->code} нет вопроса {$reference[2]}, вопросов ".count($scale->items).'.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<RuleData>  $rules
     */
    private function sync(array $rules): ReferralRuleImportResult
    {
        $existing = ReferralRule::query()->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($rules as $index => $data) {
            $attributes = [
                'category' => $data['category'],
                'condition' => $data['condition'],
                'text' => $data['text'],
                'source' => array_filter([
                    'document' => $data['source']['document'],
                    'section' => $data['source']['section'],
                    'url' => $data['source']['url'],
                    'quote' => $data['source']['quote'] ?? null,
                ], fn (?string $value): bool => $value !== null),
                'position' => $index + 1,
            ];

            $rule = $existing->get($data['code']) ?? new ReferralRule(['code' => $data['code']]);
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

        return new ReferralRuleImportResult(count($rules), $created, $updated, $deactivated);
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
