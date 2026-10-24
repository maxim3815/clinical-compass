<?php

namespace App\Http\Requests\Patient;

use App\Models\Scale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ответы на все действующие опросники: answers.<код шкалы>.<номер вопроса с 0> — балл варианта.
 * Отправить можно только с ответом на каждый вопрос.
 */
class AssessmentRequest extends FormRequest
{
    /**
     * @var Collection<int, Scale>|null
     */
    private ?Collection $scales = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['answers' => ['required', 'array:'.$this->scales()->pluck('code')->implode(',')]];

        foreach ($this->scales() as $scale) {
            $rules["answers.{$scale->code}"] = ['required', 'list', 'size:'.count($scale->items)];

            foreach (array_keys($scale->items) as $index) {
                $rules["answers.{$scale->code}.{$index}"] = ['required', 'integer', Rule::in(array_column($scale->options, 'score'))];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->scales() as $scale) {
            $messages["answers.{$scale->code}.required"] = "Ответьте на вопросы {$scale->name}.";
            $messages["answers.{$scale->code}.size"] = "Ответьте на все вопросы {$scale->name}.";

            foreach (array_keys($scale->items) as $index) {
                foreach (['required', 'integer', 'in'] as $rule) {
                    $messages["answers.{$scale->code}.{$index}.{$rule}"] = 'Ответьте на вопрос '.($index + 1).'.';
                }
            }
        }

        return $messages;
    }

    /**
     * @return array<string, list<int>> код шкалы => балл на каждый вопрос по порядку
     */
    public function answers(): array
    {
        /** @var array<string, list<int|string>> $answers */
        $answers = $this->validated('answers');

        return array_map(fn (array $scores): array => array_map(intval(...), $scores), $answers);
    }

    /**
     * @return Collection<int, Scale>
     */
    private function scales(): Collection
    {
        return $this->scales ??= Scale::query()->active()->forPatients()->orderBy('position')->get();
    }
}
