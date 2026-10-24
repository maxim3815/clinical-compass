<?php

namespace App\Support\Referrals;

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
 * Язык условий правил подсказки направления — тот же ExpressionLanguage без переменных, свойств
 * и методов, что у правил алертинга, плюс факты клинического профиля:
 *
 * - answer('phq9', 9) — балл ответа на 9-й вопрос PHQ-9;
 * - total('hars') — сумма баллов шкалы;
 * - fact('mania_signs') — текущее значение факта, null — записи нет.
 */
final class ReferralLanguage extends ExpressionLanguage
{
    private ?ReferralData $data = null;

    public function __construct()
    {
        // Разобранные условия хранятся как есть: копирование по умолчанию в ArrayAdapter
        // возвращает их неполными (__PHP_Incomplete_Class).
        parent::__construct(new ArrayAdapter(deepClone: false));
    }

    public function evaluateFor(string $condition, ReferralData $data): bool
    {
        $this->data = $data;

        try {
            return (bool) $this->evaluate($condition);
        } finally {
            $this->data = null;
        }
    }

    /**
     * Ответы, суммы и факты, на которые смотрит условие, по порядку появления, без повторов:
     * [['answer', 'phq9', 9], ['total', 'hars'], ['fact', 'mania_signs']]. Бросает SyntaxError,
     * если условие не разбирается или использует что-то кроме функций выше.
     *
     * @return list<array{0: 'answer', 1: string, 2: int}|array{0: 'total', 1: string}|array{0: 'fact', 1: string}>
     */
    public function references(string $condition): array
    {
        $references = [];
        $this->collect($this->parse($condition, [])->getNodes(), $references);

        return array_values(array_unique($references, SORT_REGULAR));
    }

    /**
     * Встроенные функции ExpressionLanguage (constant, enum, min, max) условиям не нужны.
     */
    protected function registerFunctions(): void
    {
        $data = fn (): ReferralData => $this->data ?? throw new LogicException('Условие вычисляется только через evaluateFor().');

        $this->register('answer', self::notCompiled(...), fn (array $values, string $scale, int $item) => $data()->answer($scale, $item));
        $this->register('total', self::notCompiled(...), fn (array $values, string $scale) => $data()->total($scale));
        $this->register('fact', self::notCompiled(...), fn (array $values, string $code) => $data()->fact($code));
    }

    private static function notCompiled(): string
    {
        throw new LogicException('Условия правил не компилируются в PHP.');
    }

    /**
     * @param  list<array{0: 'answer', 1: string, 2: int}|array{0: 'total', 1: string}|array{0: 'fact', 1: string}>  $references
     */
    private function collect(Node $node, array &$references): void
    {
        if ($node instanceof FunctionNode) {
            $name = $node->attributes['name'];
            $args = array_map(
                fn (Node $arg): mixed => $arg instanceof ConstantNode ? $arg->attributes['value'] : null,
                $node->nodes['arguments']->nodes,
            );

            if ($name === 'answer' && count($args) === 2 && is_string($args[0]) && is_int($args[1])) {
                $references[] = ['answer', $args[0], $args[1]];

                return;
            }

            if (in_array($name, ['total', 'fact'], true) && count($args) === 1 && is_string($args[0])) {
                $references[] = [$name, $args[0]];

                return;
            }

            throw new SyntaxError("Функция {$name}(): ожидается answer('код шкалы', номер вопроса), total('код шкалы') или fact('код факта').");
        }

        // Node без подкласса — список аргументов функции.
        $allowed = $node::class === Node::class
            || $node instanceof BinaryNode
            || $node instanceof UnaryNode
            || $node instanceof ConstantNode
            || $node instanceof ConditionalNode;

        if (! $allowed) {
            throw new SyntaxError('В условии можно использовать только операторы, числа, строки и функции answer(), total() и fact().');
        }

        foreach ($node->nodes as $child) {
            $this->collect($child, $references);
        }
    }
}
