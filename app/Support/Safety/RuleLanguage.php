<?php

namespace App\Support\Safety;

use LogicException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Язык условий правил — Symfony ExpressionLanguage без переменных, свойств и методов объектов:
 * только операторы, литералы и функции над данными пациента.
 *
 * - fact('gfr') — текущее значение факта: true/false, число или код варианта;
 * - age() — полных лет;
 * - diagnosis('F31') — есть действующий диагноз в рубрике или подрубрике;
 * - taking('paroxetine') — принимает препарат справочника, кроме проверяемого;
 * - taking_group('serotonergic') — принимает препарат группы, кроме проверяемого.
 *
 * Если функции не хватает данных, она бросает MissingData, и правило попадает в «Не проверено».
 */
final class RuleLanguage extends ExpressionLanguage
{
    /**
     * Функции и вид аргумента: null — без аргумента.
     */
    public const array FUNCTIONS = [
        'fact' => 'fact',
        'age' => null,
        'diagnosis' => 'icd10',
        'taking' => 'drug',
        'taking_group' => 'group',
    ];

    private ?RuleEvaluation $evaluation = null;

    public function __construct()
    {
        // Разобранные условия хранятся как есть: копирование по умолчанию в ArrayAdapter
        // возвращает их неполными (__PHP_Incomplete_Class).
        parent::__construct(new ArrayAdapter(deepClone: false));
    }

    public function evaluateFor(string $condition, RuleEvaluation $evaluation): bool
    {
        $this->evaluation = $evaluation;

        try {
            return (bool) $this->evaluate($condition);
        } finally {
            $this->evaluation = null;
        }
    }

    /**
     * Аргументы функций условия по видам: ['fact' => ['gfr'], 'group' => ['maoi']].
     * Бросает SyntaxError, если условие не разбирается или использует что-то кроме функций выше.
     *
     * @return array<string, list<string>>
     */
    public function arguments(string $condition): array
    {
        $arguments = [];
        $this->collect($this->parse($condition, [])->getNodes(), $arguments);

        return $arguments;
    }

    /**
     * Встроенные функции ExpressionLanguage (constant, enum, min, max) условиям не нужны.
     */
    protected function registerFunctions(): void
    {
        $evaluation = fn (): RuleEvaluation => $this->evaluation ?? throw new LogicException('Условие вычисляется только через evaluateFor().');

        $this->register('fact', self::notCompiled(...), fn (array $values, string $code) => $evaluation()->fact($code));
        $this->register('age', self::notCompiled(...), fn (array $values) => $evaluation()->age());
        $this->register('diagnosis', self::notCompiled(...), fn (array $values, string $code) => $evaluation()->diagnosis($code));
        $this->register('taking', self::notCompiled(...), fn (array $values, string $drug) => $evaluation()->taking($drug));
        $this->register('taking_group', self::notCompiled(...), fn (array $values, string $group) => $evaluation()->takingGroup($group));
    }

    private static function notCompiled(): string
    {
        throw new LogicException('Условия правил не компилируются в PHP.');
    }

    /**
     * @param  array<string, list<string>>  $arguments
     */
    private function collect(Node $node, array &$arguments): void
    {
        if ($node instanceof FunctionNode) {
            $name = $node->attributes['name'];
            $kind = self::FUNCTIONS[$name] ?? null;
            $args = $node->nodes['arguments']->nodes;

            if ($kind === null) {
                if ($args !== []) {
                    throw new SyntaxError("Функция {$name}() вызывается без аргументов.");
                }

                return;
            }

            if (count($args) !== 1 || ! $args[0] instanceof ConstantNode || ! is_string($args[0]->attributes['value'])) {
                throw new SyntaxError("Аргумент функции {$name}() — одна строка в кавычках.");
            }

            $arguments[$kind][] = $args[0]->attributes['value'];

            return;
        }

        // Node без подкласса — список аргументов функции.
        $allowed = $node::class === Node::class
            || $node instanceof BinaryNode
            || $node instanceof UnaryNode
            || $node instanceof ConstantNode
            || $node instanceof ConditionalNode;

        if (! $allowed) {
            throw new SyntaxError('В условии можно использовать только операторы, числа, строки и функции '.implode(', ', array_map(fn (string $name): string => $name.'()', array_keys(self::FUNCTIONS))).'.');
        }

        foreach ($node->nodes as $child) {
            $this->collect($child, $arguments);
        }
    }
}
