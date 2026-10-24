<?php

namespace App\Actions;

use App\Enums\ScaleRater;
use App\Exceptions\InvalidScales;
use App\Models\Scale;
use App\Support\Assessments\ScaleImportResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Загрузка шкал из YAML-файлов, по файлу на шкалу. Шкалу пациента он заполняет сам по календарю,
 * у шкалы врача (`rater: doctor`) нет нейтрального названия для пациента и календаря. Всё проверяется до записи в БД:
 * при ошибке в любом файле не загружается ничего. Шкалы сопоставляются по коду, повторная
 * загрузка тех же файлов ничего не меняет. Шкала, которой в файлах больше нет, выключается,
 * а не удаляется: на неё ссылаются заполнения.
 *
 * @phpstan-type ScaleData array{
 *     code: string,
 *     name: string,
 *     title: string,
 *     rater?: 'patient'|'doctor',
 *     patient_title?: string,
 *     position: int,
 *     instruction: string,
 *     options: list<array{score: int, label: string}>,
 *     items: list<array{text: string, hint?: string, highlight?: bool}>,
 *     bands: list<array{min: int, max: int, label: string}>,
 *     schedule?: array{days: list<int>, then_every: int},
 *     source: array{original: string, validation: string, url: string, license: string, note?: string|null},
 * }
 */
class ImportScales
{
    public const string DEFAULT_PATH = 'database/data/scales';

    private const array FIELDS = ['code', 'name', 'title', 'rater', 'patient_title', 'position', 'instruction', 'options', 'items', 'bands', 'schedule', 'source'];

    private const array OPTION_FIELDS = ['score', 'label'];

    private const array ITEM_FIELDS = ['text', 'hint', 'highlight'];

    private const array BAND_FIELDS = ['min', 'max', 'label'];

    private const array SCHEDULE_FIELDS = ['days', 'then_every'];

    private const array SOURCE_FIELDS = ['original', 'validation', 'url', 'license', 'note'];

    /**
     * @throws InvalidScales
     */
    public function handle(string $path): ScaleImportResult
    {
        $scales = $this->validate($this->files($path));

        return DB::transaction(fn (): ScaleImportResult => $this->sync($scales));
    }

    /**
     * @return array<string, mixed> имя файла => содержимое
     */
    private function files(string $path): array
    {
        if (! is_dir($path)) {
            throw new InvalidScales(["Каталог шкал {$path} не найден."]);
        }

        $files = glob(rtrim($path, '/').'/*.yaml') ?: [];
        sort($files);

        if ($files === []) {
            throw new InvalidScales(["В каталоге {$path} нет файлов шкал *.yaml."]);
        }

        $parsed = [];

        foreach ($files as $file) {
            try {
                $parsed[basename($file)] = Yaml::parseFile($file);
            } catch (ParseException $e) {
                throw new InvalidScales([basename($file).': ошибка разметки YAML: '.$e->getMessage()]);
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $files
     * @return list<ScaleData>
     */
    private function validate(array $files): array
    {
        $errors = [];
        $codes = [];

        foreach ($files as $name => $scale) {
            if (! is_array($scale) || array_is_list($scale)) {
                $errors[] = "{$name}: файл должен состоять из полей шкалы.";

                continue;
            }

            $errors = [...$errors, ...array_map(fn (string $message): string => "{$name}: {$message}", $this->errors($scale))];
            $code = $scale['code'] ?? null;

            if (is_string($code)) {
                if ($name !== $code.'.yaml') {
                    $errors[] = "{$name}: файл шкалы {$code} должен называться {$code}.yaml.";
                }

                if (in_array($code, $codes, true)) {
                    $errors[] = "{$name}: код {$code} повторяется.";
                }

                $codes[] = $code;
            }
        }

        if ($errors !== []) {
            throw new InvalidScales($errors);
        }

        /** @var list<ScaleData> $scales */
        $scales = array_values($files);

        return $scales;
    }

    /**
     * @param  array<mixed>  $scale
     * @return list<string>
     */
    private function errors(array $scale): array
    {
        $errors = [];

        foreach ([
            '' => [$scale, self::FIELDS],
            'schedule.' => [$scale['schedule'] ?? null, self::SCHEDULE_FIELDS],
            'source.' => [$scale['source'] ?? null, self::SOURCE_FIELDS],
        ] as $prefix => [$data, $allowed]) {
            if (is_array($data)) {
                foreach (self::extraKeys($data, $allowed) as $field) {
                    $errors[] = "неизвестное поле {$prefix}{$field}.";
                }
            }
        }

        foreach (['options' => self::OPTION_FIELDS, 'items' => self::ITEM_FIELDS, 'bands' => self::BAND_FIELDS] as $list => $allowed) {
            foreach (is_array($scale[$list] ?? null) ? $scale[$list] : [] as $index => $entry) {
                if (is_array($entry)) {
                    foreach (self::extraKeys($entry, $allowed) as $field) {
                        $errors[] = "неизвестное поле {$list}.".($index + 1).".{$field}.";
                    }
                }
            }
        }

        // Шкалу врача пациент не видит и по календарю не заполняет.
        $byDoctor = ($scale['rater'] ?? null) === ScaleRater::Doctor->value;
        $patientOnly = $byDoctor ? 'prohibited' : 'required';

        $validator = Validator::make($scale, [
            'code' => ['required', 'string', 'max:64', 'regex:/^'.Scale::CODE_PATTERN.'$/'],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'rater' => ['sometimes', 'string', 'in:'.ScaleRater::Patient->value.','.ScaleRater::Doctor->value],
            'patient_title' => [$patientOnly, 'string', 'max:255'],
            'position' => ['required', 'integer', 'min:1'],
            'instruction' => ['required', 'string'],
            'options' => ['required', 'list', 'min:2'],
            'options.*' => ['array'],
            'options.*.score' => ['required', 'integer', 'min:0', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'items' => ['required', 'list', 'min:1'],
            'items.*' => ['array'],
            'items.*.text' => ['required', 'string'],
            'items.*.hint' => ['sometimes', 'string'],
            'items.*.highlight' => ['sometimes', 'boolean:strict'],
            'bands' => ['required', 'list', 'min:1'],
            'bands.*' => ['array'],
            'bands.*.min' => ['required', 'integer', 'min:0'],
            'bands.*.max' => ['required', 'integer', 'min:0'],
            'bands.*.label' => ['required', 'string', 'max:255'],
            'schedule' => [$patientOnly, 'array'],
            'schedule.days' => [$byDoctor ? 'exclude' : 'required', 'list', 'min:1'],
            'schedule.days.*' => ['integer', 'min:0', 'distinct'],
            'schedule.then_every' => [$byDoctor ? 'exclude' : 'required', 'integer', 'min:1'],
            'source' => ['required', 'array'],
            'source.original' => ['required', 'string'],
            'source.validation' => ['required', 'string'],
            'source.url' => ['required', 'string', 'max:2048', 'url:https,http'],
            'source.license' => ['required', 'string'],
            'source.note' => ['nullable', 'string'],
        ], [], array_combine(self::FIELDS, self::FIELDS));

        $errors = [...$errors, ...array_values($validator->errors()->all())];

        if ($validator->errors()->isEmpty()) {
            $errors = [...$errors, ...$this->scoringErrors($scale)];
        }

        return $errors;
    }

    /**
     * Степени покрывают все суммы от 0 до наибольшей без пропусков и пересечений, сроки идут
     * по возрастанию с дня 0.
     *
     * @param  array<mixed>  $scale
     * @return list<string>
     */
    private function scoringErrors(array $scale): array
    {
        /** @var ScaleData $scale */
        $errors = [];
        $scores = array_column($scale['options'], 'score');
        $maxTotal = count($scale['items']) * max([0, ...$scores]);
        $expected = 0;

        if ($scores === [] || min($scores) !== 0) {
            $errors[] = 'наименьший балл варианта ответа должен быть 0.';
        }

        foreach ($scale['bands'] as $index => $band) {
            $label = 'степень '.($index + 1);

            if ($band['min'] !== $expected) {
                $errors[] = "{$label}: должна начинаться с суммы {$expected}, а начинается с {$band['min']}.";
            }

            if ($band['max'] < $band['min']) {
                $errors[] = "{$label}: max меньше min.";
            }

            $expected = $band['max'] + 1;
        }

        if ($expected - 1 !== $maxTotal) {
            $errors[] = "последняя степень должна заканчиваться наибольшей суммой {$maxTotal}.";
        }

        if (isset($scale['schedule'])) {
            $days = $scale['schedule']['days'];
            $sorted = $days;
            sort($sorted);

            if ($days !== $sorted || $days[0] !== 0) {
                $errors[] = 'schedule.days: сроки должны идти по возрастанию и начинаться с дня 0.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<ScaleData>  $scales
     */
    private function sync(array $scales): ScaleImportResult
    {
        $existing = Scale::query()->get()->keyBy('code');
        $created = [];
        $updated = [];

        foreach ($scales as $data) {
            $attributes = [
                'name' => $data['name'],
                'title' => $data['title'],
                'rater' => $data['rater'] ?? ScaleRater::Patient->value,
                'patient_title' => $data['patient_title'] ?? null,
                'position' => $data['position'],
                'instruction' => $data['instruction'],
                'options' => $data['options'],
                'items' => array_map(fn (array $item): array => array_filter([
                    'text' => $item['text'],
                    'hint' => $item['hint'] ?? null,
                    'highlight' => $item['highlight'] ?? false,
                ], fn (mixed $value): bool => $value !== null), $data['items']),
                'bands' => $data['bands'],
                'schedule_days' => $data['schedule']['days'] ?? null,
                'schedule_then_every' => $data['schedule']['then_every'] ?? null,
                'source_original' => $data['source']['original'],
                'source_validation' => $data['source']['validation'],
                'source_url' => $data['source']['url'],
                'source_license' => $data['source']['license'],
                'source_note' => $data['source']['note'] ?? null,
            ];

            $scale = $existing->get($data['code']) ?? new Scale(['code' => $data['code']]);
            $isNew = ! $scale->exists;
            // Шкалы пациента появились раньше поля rater: без него их версия не меняется.
            $hashed = $attributes['rater'] === ScaleRater::Patient->value ? array_diff_key($attributes, ['rater' => true]) : $attributes;
            $version = hash('sha256', $data['code'].'|'.json_encode($hashed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $changed = $isNew || $scale->version !== $version || ! $scale->is_active;

            if (! $changed) {
                continue;
            }

            $scale->fill([...$attributes, 'version' => $version, 'is_active' => true])->save();

            if ($isNew) {
                $created[] = $data['code'];
            } else {
                $updated[] = $data['code'];
            }
        }

        $codes = array_column($scales, 'code');
        $deactivated = [];

        foreach ($existing as $scale) {
            if ($scale->is_active && ! in_array($scale->code, $codes, true)) {
                $scale->update(['is_active' => false]);
                $deactivated[] = $scale->code;
            }
        }

        return new ScaleImportResult(count($scales), $created, $updated, $deactivated);
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
