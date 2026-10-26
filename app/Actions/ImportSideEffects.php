<?php

namespace App\Actions;

use App\Enums\SideEffectLevel;
use App\Exceptions\InvalidSideEffects;
use App\Models\Drug;
use App\Models\DrugSideEffect;
use App\Models\SideEffect;
use App\Support\SideEffectImportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка побочных эффектов из YAML: словарь симптомов и группы симптомов у каждого препарата
 * по разделу 4 его листка-вкладыша. Файл проверяется целиком до записи в БД: при ошибке не меняется
 * ничего. Симптомы сопоставляются по коду, повторная загрузка того же файла ничего не меняет.
 * Симптом, которого больше нет в файле, выключается: на него ссылаются записи пациентов.
 * Группы препарата файл задаёт целиком.
 *
 * Препараты должны быть загружены раньше: группы ссылаются на них по коду.
 *
 * @phpstan-type SymptomData array{code: string, name: string, term: string}
 * @phpstan-type GroupData array{level: string, symptoms: list<string>, instruction?: string}
 */
class ImportSideEffects
{
    public const string DEFAULT_PATH = 'database/data/side-effects.yaml';

    private const array FIELDS = ['symptoms', 'drugs'];

    private const array SYMPTOM_FIELDS = ['code', 'name', 'term'];

    private const array GROUP_FIELDS = ['level', 'symptoms', 'instruction'];

    /**
     * @throws InvalidSideEffects
     */
    public function handle(string $path): SideEffectImportResult
    {
        [$symptoms, $drugs] = $this->validate($this->parse($path));

        return DB::transaction(fn (): SideEffectImportResult => $this->sync($symptoms, $drugs));
    }

    private function parse(string $path): mixed
    {
        if (! is_file($path)) {
            throw new InvalidSideEffects(["Файл {$path} не найден."]);
        }

        try {
            return Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new InvalidSideEffects(['Ошибка разметки YAML: '.$e->getMessage()]);
        }
    }

    /**
     * @return array{list<SymptomData>, array<string, list<GroupData>>}
     */
    private function validate(mixed $data): array
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidSideEffects(['Файл должен состоять из разделов symptoms и drugs.']);
        }

        $errors = array_map(fn (string $key): string => "Неизвестный раздел {$key}.", self::extraKeys($data, self::FIELDS));
        $symptoms = $data['symptoms'] ?? null;
        $drugs = $data['drugs'] ?? null;

        if (! is_array($symptoms) || ! array_is_list($symptoms) || $symptoms === []) {
            throw new InvalidSideEffects([...$errors, 'Раздел symptoms должен быть непустым списком симптомов.']);
        }

        if (! is_array($drugs) || array_is_list($drugs)) {
            throw new InvalidSideEffects([...$errors, 'Раздел drugs должен перечислять препараты по коду.']);
        }

        $codes = [];

        foreach ($symptoms as $index => $symptom) {
            $label = 'Симптом '.($index + 1);

            if (! is_array($symptom) || array_is_list($symptom)) {
                $errors[] = "{$label}: должен состоять из полей code, name и term.";

                continue;
            }

            if (is_string($symptom['code'] ?? null)) {
                $label = "Симптом {$symptom['code']}";
            }

            foreach (self::extraKeys($symptom, self::SYMPTOM_FIELDS) as $field) {
                $errors[] = "{$label}: неизвестное поле {$field}.";
            }

            $validator = Validator::make($symptom, [
                'code' => ['required', 'string', 'max:64', 'regex:/^'.SideEffect::CODE_PATTERN.'$/'],
                // Название сохраняется в записи пациента, там же лежит и «другое» текстом.
                'name' => ['required', 'string', 'max:200'],
                'term' => ['required', 'string', 'max:255'],
            ], [], array_combine(self::SYMPTOM_FIELDS, self::SYMPTOM_FIELDS));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "{$label}: {$message}";
            }

            if (is_string($symptom['code'] ?? null)) {
                if (isset($codes[$symptom['code']])) {
                    $errors[] = "{$label}: код повторяется.";
                }

                $codes[$symptom['code']] = true;
            }
        }

        $known = Drug::query()->active()->catalog()->pluck('code')->flip();

        foreach ($drugs as $code => $groups) {
            $label = "Препарат {$code}";

            if (! $known->has($code)) {
                $errors[] = "{$label}: такого препарата нет в справочнике.";
            }

            if (! is_array($groups) || ! array_is_list($groups) || $groups === []) {
                $errors[] = "{$label}: нужен список групп симптомов.";

                continue;
            }

            $seen = [];

            foreach ($groups as $index => $group) {
                $groupLabel = "{$label}, группа ".($index + 1);

                if (! is_array($group) || array_is_list($group)) {
                    $errors[] = "{$groupLabel}: должна состоять из полей level и symptoms.";

                    continue;
                }

                foreach (self::extraKeys($group, self::GROUP_FIELDS) as $field) {
                    $errors[] = "{$groupLabel}: неизвестное поле {$field}.";
                }

                $emergency = ($group['level'] ?? null) === SideEffectLevel::Emergency->value;
                $validator = Validator::make($group, [
                    'level' => ['required', 'string', Rule::enum(SideEffectLevel::class)],
                    'symptoms' => ['required', 'array', 'list'],
                    'symptoms.*' => ['string'],
                    // Слова листка о приёме видит пациент, которому нужна помощь сейчас.
                    'instruction' => $emergency ? ['sometimes', 'required', 'string'] : ['prohibited'],
                ], [], array_combine(self::GROUP_FIELDS, self::GROUP_FIELDS));

                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "{$groupLabel}: {$message}";
                }

                foreach (is_array($group['symptoms'] ?? null) ? $group['symptoms'] : [] as $symptom) {
                    if (! is_string($symptom)) {
                        continue;
                    }

                    if (! isset($codes[$symptom])) {
                        $errors[] = "{$groupLabel}: симптома {$symptom} нет в разделе symptoms.";
                    }

                    if (isset($seen[$symptom])) {
                        $errors[] = "{$label}: симптом {$symptom} входит в несколько групп.";
                    }

                    $seen[$symptom] = true;
                }
            }
        }

        if ($errors !== []) {
            throw new InvalidSideEffects($errors);
        }

        /** @var list<SymptomData> $symptoms */
        /** @var array<string, list<GroupData>> $drugs */
        return [$symptoms, $drugs];
    }

    /**
     * @param  list<SymptomData>  $symptoms
     * @param  array<string, list<GroupData>>  $drugs
     */
    private function sync(array $symptoms, array $drugs): SideEffectImportResult
    {
        $before = $this->snapshot();
        $existing = SideEffect::query()->get()->keyBy('code');

        foreach ($symptoms as $position => $data) {
            $symptom = $existing->get($data['code']) ?? new SideEffect(['code' => $data['code']]);
            $symptom->fill([
                'name' => $data['name'],
                'term' => $data['term'],
                'position' => $position,
                'is_active' => true,
            ]);

            if ($symptom->isDirty()) {
                $symptom->save();
            }
        }

        SideEffect::query()
            ->whereNotIn('code', array_column($symptoms, 'code'))
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $ids = SideEffect::query()->pluck('id', 'code');
        $drugIds = Drug::query()->pluck('id', 'code');
        $rows = [];

        foreach ($drugs as $code => $groups) {
            foreach ($groups as $group) {
                foreach ($group['symptoms'] as $symptom) {
                    $rows[] = [
                        'drug_id' => $drugIds[$code],
                        'side_effect_id' => $ids[$symptom],
                        'level' => $group['level'],
                        'instruction' => $group['instruction'] ?? null,
                    ];
                }
            }
        }

        // Группы препарата файл задаёт целиком: строки ни на что не ссылаются, их проще пересоздать.
        DrugSideEffect::query()->delete();
        DrugSideEffect::query()->insert($rows);

        return new SideEffectImportResult(count($symptoms), count($drugs), $before !== $this->snapshot());
    }

    /**
     * Словарь и группы для сравнения до и после загрузки.
     *
     * @return array{array<mixed>, array<int, array<int, mixed>>}
     */
    private function snapshot(): array
    {
        return [
            SideEffect::query()->orderBy('code')->get(['code', 'name', 'term', 'position', 'is_active'])->toArray(),
            DrugSideEffect::query()
                ->join('drugs', 'drugs.id', '=', 'drug_side_effects.drug_id')
                ->join('side_effects', 'side_effects.id', '=', 'drug_side_effects.side_effect_id')
                ->orderBy('drugs.code')
                ->orderBy('side_effects.code')
                ->get(['drugs.code as drug', 'side_effects.code as symptom', 'drug_side_effects.level', 'drug_side_effects.instruction'])
                ->map(fn (DrugSideEffect $row): array => [$row->getAttribute('drug'), $row->getAttribute('symptom'), $row->getRawOriginal('level'), $row->instruction])
                ->all(),
        ];
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
